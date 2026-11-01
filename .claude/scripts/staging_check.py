#!/usr/bin/env python3
"""Staging compatibility check for a JezPress plugin - `/release` step 1d.

Runs AFTER the Playground smoke test passes. Playground proves the plugin works
on a clean WordPress at its PHP floor; this proves it works next to the real
plugins, theme, object cache and WooCommerce settings on the shared staging
site. WHICH site is machine-local, in ~/.claude/jezpress-staging.json
({"site_url": ..., "rocket_site_id": ...}; `staging_check.py config` prints
it) - switching staging means editing that file and running the one-time
setup in .claude/audit-guide.md, never editing plugin repos. Abner installs
the build by hand; this never installs, deactivates, uninstalls, submits a
form or places an order. The only Rocket MCP step is fetching the single-use
login link (`rocketnet_wordpress get_login_url` for rocket_site_id) into a
file before `check`.

  build   Build the test ZIP from the working tree with release.yml's rsync
          excludes - the same file list the real release ships - plus a
          jezsmoke-build.txt marker holding a random token.
            python3 .claude/scripts/staging_check.py build --out <scratch>/<slug>-staging.zip

  check   Prove staging runs THIS build (the marker must be served with the
          same token), log in with the Rocket link, write a start sentinel to
          the debug log, load wp-admin, plugins.php, every admin menu page, the
          REST index and the front end (WooCommerce shop / product / cart /
          checkout / my-account for a WooCommerce add-on), write an end
          sentinel, then ask the helper mu-plugin for the log entries between
          the two sentinels that belong to this plugin, plus every fatal. Both
          sentinels must be present or the log is not proven to cover the run.
          Read-only GETs; action / nonce / delete / logout links are never
          followed.
            python3 .claude/scripts/staging_check.py check \
                --login-url-file <f> --zip <scratch>/<slug>-staging.zip --out <scratch>/staging.md

Requires on staging (one-time setup, done 2026-10-05): WP_DEBUG true,
WP_DEBUG_DISPLAY false, WP_DEBUG_LOG -> /home/<user>/jezsmoke-<random>.log (OUTSIDE the web root), and
the mu-plugin .claude/scripts/staging-sentinel.php installed as
wp-content/mu-plugins/jezsmoke-sentinel.php.

Exit codes: 0 PASS (warnings allowed), 1 FAIL, 2 usage error,
            3 environment problem (staging not running this build, login
              failed, log not proven) - never a pass.
"""

from __future__ import annotations

import argparse
import fnmatch
import html
import importlib.util
import json
import os
import re
import secrets
import subprocess
import time
import sys
import urllib.parse
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
# Which staging site to use lives in ONE machine-local file, so switching
# staging never means editing the ~75 plugin repos that carry this script.
STAGING_CONFIG = Path(os.environ.get("JEZSMOKE_STAGING_CONFIG", str(Path.home() / ".claude" / "jezpress-staging.json")))


def staging_config(slug: str = "") -> dict:
    """The staging site for `slug`: the file's top-level default, overridden by
    its "plugins": {"<slug>": {...}} entry when a plugin has its own staging
    (e.g. a client's staging with the plugin's real data). {} when unset.
    Kept machine-local so client staging URLs never land in a plugin repo."""
    try:
        cfg = json.loads(STAGING_CONFIG.read_text())
    except (OSError, ValueError):
        return {}
    if not isinstance(cfg, dict):
        return {}
    plugins = cfg.get("plugins") if isinstance(cfg.get("plugins"), dict) else {}
    out = {k: v for k, v in cfg.items() if k != "plugins"}
    override = plugins.get(slug) if slug else None
    if isinstance(override, dict):
        out.update(override)
        out["source"] = f"plugins.{slug} override"
    else:
        out["source"] = "default"
    return out
MAX_PAGES = 250            # exceeding it is a WARN; front-end pages are never the ones cut
PAGE_TIMEOUT = 60          # seconds per page; a timeout is reported as a failed page
RUN_BUDGET = 20 * 60       # seconds for the whole crawl; exceeding it is a WARN, never silent
SLOW_PAGE = 15             # seconds; slower pages are listed as WARN
BUILD_MARKER = "jezsmoke-build.txt"


def token_file(zip_path: Path) -> Path:
    return zip_path.with_name(zip_path.name + ".token")
SKIP_LINK_RE = re.compile(r"(action=|_wpnonce|delete|trash|logout|deactivate|activate=|uninstall|export|download)", re.I)
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) jezpress-staging-check"


def load(name: str):
    spec = importlib.util.spec_from_file_location(name, HERE / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


smoke = load("smoke_test")


def repo_root() -> Path:
    out = subprocess.run(["git", "rev-parse", "--show-toplevel"], capture_output=True, text=True)
    if out.returncode != 0:
        print("staging_check: run inside the plugin repo", file=sys.stderr)
        sys.exit(2)
    return Path(out.stdout.strip())


# ------------------------------------------------------------------- build

def release_excludes(repo: Path) -> list[str]:
    wf = repo / ".github" / "workflows" / "release.yml"
    if not wf.is_file():
        print("staging_check: no .github/workflows/release.yml - cannot match the release file list", file=sys.stderr)
        sys.exit(2)
    pats = re.findall(r"--exclude=['\"]?([^'\"\s]+)", wf.read_text(errors="replace"))
    if not pats:
        print("staging_check: release.yml has no rsync --exclude lines", file=sys.stderr)
        sys.exit(2)
    return pats


def excluded(path: str, pats: list[str]) -> bool:
    # Same rule as verify_release.rsync_excluded, so the test ZIP and the
    # release ZIP cannot drift.
    parts = path.split("/")
    for pat in pats:
        pat = pat.rstrip("/")
        if "/" in pat:
            if fnmatch.fnmatch(path, pat.lstrip("/")) or fnmatch.fnmatch(path, pat.lstrip("/") + "/*"):
                return True
        elif any(fnmatch.fnmatch(part, pat) for part in parts):
            return True
    return False


def cmd_build(args) -> int:
    repo = repo_root()
    slug = repo.name
    pats = release_excludes(repo)
    # Tracked files plus untracked-but-not-ignored ones: the build under test
    # is the working tree, before the version bump and commit.
    out = subprocess.run(["git", "ls-files", "--cached", "--others", "--exclude-standard"],
                         cwd=repo, capture_output=True, text=True, check=True)
    files = sorted({f for f in out.stdout.splitlines() if f and (repo / f).is_file() and not excluded(f, pats)})
    if not any(f == f"{slug}.php" for f in files):
        print(f"staging_check: {slug}.php missing from the build", file=sys.stderr)
        return 2
    args.out.parent.mkdir(parents=True, exist_ok=True)
    # A random build token, shipped as a plain .txt file the web server serves,
    # lets `check` prove staging runs THIS build without reading PHP files.
    token = secrets.token_hex(12)
    with zipfile.ZipFile(args.out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in files:
            z.write(repo / f, f"{slug}/{f}")
        z.writestr(f"{slug}/{BUILD_MARKER}", f"{token}\n")
    token_file(args.out).write_text(token + "\n")
    print(f"Built {args.out} ({len(files)} files + {BUILD_MARKER}, release.yml excludes applied). Build token: {token}")
    print(f"Install it on staging: Plugins > Add New > Upload Plugin > Replace current with uploaded.")
    return 0


# ------------------------------------------------------------------- crawl

def admin_links(base: str, page: str) -> list[str]:
    m = re.search(r"""<ul id=["']adminmenu["'].*?</ul>\s*</div>""", page, re.S)
    if not m:
        return []  # caller treats "no menu found" as an environment problem
    menu = m.group(0)
    links = []
    # WordPress prints admin-menu links with single quotes (href='index.php').
    for href in re.findall(r"""href=["']([^"']+)["']""", menu):
        href = html.unescape(href)
        url = urllib.parse.urljoin(f"{base}/wp-admin/", href)
        if not url.startswith(f"{base}/wp-admin/") or SKIP_LINK_RE.search(url):
            continue
        if url not in links:
            links.append(url)
    return links


def guard_redirects(site, base: str, blocked: list) -> None:
    """Every redirect target passes the same safety filter as a menu link, or
    an innocent page could bounce the admin session into ?action=deactivate."""
    import urllib.request

    class SafeRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            target = urllib.parse.urljoin(req.full_url, newurl)
            if not target.startswith(f"{base}/") or SKIP_LINK_RE.search(urllib.parse.unquote(target)):
                blocked.append(f"{req.full_url.replace(base, '') or '/'} -> {target}")
                return None  # not followed; the 3xx comes back as the response
            return super().redirect_request(req, fp, code, msg, headers, newurl)

    site.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(site.jar), SafeRedirect())


WOO_REQUIRED = ("shop", "cart", "checkout", "my-account")


def woo_front(site, base: str) -> tuple[list[str], list[str]]:
    """WooCommerce display URLs, and which required pages could NOT be found -
    a discovery failure must reduce the verdict, never silently the coverage."""
    found: dict[str, str] = {}
    code, _, body = site.get(f"{base}/wp-json/wp/v2/pages?per_page=100&_fields=slug,link")
    if code == 200:
        try:
            for p in json.loads(body):
                if p.get("slug") in WOO_REQUIRED:
                    found[p["slug"]] = p["link"]
        except (ValueError, TypeError, KeyError, AttributeError):
            pass
    urls = list(found.values())
    code, _, body = site.get(f"{base}/wp-json/wp/v2/product?per_page=1&status=publish&_fields=id,link")
    if code == 200:
        try:
            items = json.loads(body)
            if items:
                # Display URL only: ?add-to-cart= mutates a cart, and this check
                # is read-only. Cart/checkout with items is Playground's job.
                urls.append(items[0]["link"])
        except (ValueError, TypeError, KeyError, AttributeError):
            pass
    return urls, [s for s in WOO_REQUIRED if s not in found]


def cmd_crawl(args) -> int:
    repo = repo_root()
    slug = repo.name
    base = (args.site or staging_config(slug).get("site_url") or "").rstrip("/")
    if not base.startswith("https://"):
        print(f"staging_check: no staging site - pass --site https://... or set site_url in {STAGING_CONFIG}", file=sys.stderr)
        return 2
    res = {"slug": slug, "site": base, "fails": [], "env": [], "notes": [], "pages": 0, "token": ""}

    main_file = smoke.find_main_file(repo)
    if not main_file:
        print("staging_check: no main plugin file", file=sys.stderr)
        return 2
    header = smoke.read_header(main_file)
    res["domain"] = header.get("Text Domain")

    site = smoke.Site(base, ua=UA, timeout=PAGE_TIMEOUT)
    blocked: list[str] = []
    guard_redirects(site, base, blocked)

    if args.no_build_check:
        res["warn_build"] = "build identity NOT verified (--no-build-check: harness testing only, never for a release)"
    else:
        tf = token_file(args.zip)
        if not tf.is_file():
            print(f"staging_check: no build token next to {args.zip} - run `build` first", file=sys.stderr)
            return 2
        want = tf.read_text().strip()
        marker = f"{base}/wp-content/plugins/{slug}/{BUILD_MARKER}?nocache={secrets.token_hex(4)}"
        code, _, body = site.get(marker)
        if code != 200 or body.strip() != want:
            res["env"].append("staging is not running this build: its build marker "
                              + (f"returned HTTP {code}" if code != 200 else "has a different token")
                              + " (install the test ZIP with 'Replace current with uploaded', then re-run)")
            return finish(res, args)
        res["notes"].append(f"build identity verified (marker token matches {args.zip.name})")
    login_url = args.login_url_file.read_text().strip()
    # Capture the sign-on cookies WITHOUT following the redirect: the 302 sets
    # clearing cookies before the real ones (rocket-sso memory).
    import urllib.request
    no_redirect = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(site.jar),
        type("NoRedirect", (urllib.request.HTTPRedirectHandler,), {"redirect_request": lambda *a, **k: None})())
    try:
        no_redirect.open(urllib.request.Request(login_url, headers={"User-Agent": UA}), timeout=60)
    except urllib.error.HTTPError as e:
        if e.code not in (301, 302, 303):
            res["env"].append(f"Rocket sign-on returned HTTP {e.code}")
    if not any(c.name.startswith("wordpress_logged_in") for c in site.jar):
        res["env"].append("Rocket sign-on did not set a logged-in cookie (the link is single-use: fetch a fresh one)")
        return finish(res, args)

    # Installed files prove nothing if WordPress never activated the plugin.
    def plugin_active() -> tuple[bool, str]:
        code, _, body = site.get(f"{base}/wp-admin/index.php?jezsmoke_active={slug}")
        try:
            st = json.loads(body)
        except ValueError:
            return False, f"helper returned HTTP {code}, not JSON - is the jezsmoke-sentinel mu-plugin installed?"
        if not st.get("installed"):
            return False, "the plugin is not installed on staging"
        return bool(st.get("active")), "" if st.get("active") else "the plugin is installed but NOT active on staging"

    ok, why = plugin_active()
    if not ok:
        res["env"].append(f"{why} - activate it and re-run")
        return finish(res, args)

    token = "r" + secrets.token_hex(6)
    res["token"] = token
    site.get(f"{base}/wp-admin/index.php?jezsmoke_sentinel={token}start")

    code, final, body = site.get(f"{base}/wp-admin/")
    if smoke.page_problem(code, final, body, f"{base}/wp-admin/"):
        res["fails"].append(f"/wp-admin/ -> {smoke.page_problem(code, final, body, f'{base}/wp-admin/')}")
    menu_links = admin_links(base, body)
    if not menu_links:
        # Silence here would shrink the crawl to three pages and still PASS.
        res["env"].append("could not find the admin menu on /wp-admin/ - the crawl would cover almost nothing")
        return finish(res, args)
    # Front end and WooCommerce pages FIRST: they are what a customer sees, and
    # the cap must only ever trim the long tail of other plugins' admin pages
    # (on Birch staging the old order cut /shop/ and the product page).
    urls = [f"{base}/"]
    addon = smoke.detect_addons(repo, slug, header)
    if addon["woocommerce"]:
        woo_urls, missing = woo_front(site, base)
        if missing:
            res["env"].append(f"WooCommerce add-on, but these WooCommerce pages could not be discovered on staging: "
                              f"{', '.join(missing)} - the check would not cover them")
            return finish(res, args)
        urls += [u for u in woo_urls if u not in urls]
        res["notes"].append(f"WooCommerce pages included: {', '.join(WOO_REQUIRED)} ({addon['woocommerce']})")
    urls += [u for u in (f"{base}/wp-admin/plugins.php", f"{base}/wp-json/") if u not in urls]
    urls += [u for u in menu_links if u not in urls]
    if len(urls) > MAX_PAGES:
        res["capped"] = f"{len(urls)} pages found, first {MAX_PAGES} loaded - the last {len(urls) - MAX_PAGES} admin pages were not checked"
        urls = urls[:MAX_PAGES]
    started = time.monotonic()
    timings = []
    for i, u in enumerate(urls, 1):
        if time.monotonic() - started > RUN_BUDGET:
            res["notes"].append(f"time budget ({RUN_BUDGET // 60} min) reached: {len(urls) - i + 1} pages not loaded")
            res["budget_hit"] = True
            break
        t0 = time.monotonic()
        code, final, body = site.get(u)
        if code == 0:
            # Staging pages that make outbound calls (update checks for ~16
            # plugins, the Gravity Forms add-on catalogue, wc-admin analytics)
            # time out intermittently. Retry once before judging.
            code, final, body = site.get(u)
        took = time.monotonic() - t0
        timings.append((took, u.replace(base, "") or "/"))
        print(f"  [{i}/{len(urls)}] {took:5.1f}s {code} {u.replace(base, '') or '/'}", file=sys.stderr, flush=True)
        res["pages"] += 1
        problem = smoke.page_problem(code, final, body, u)
        path = u.replace(base, "") or "/"
        if problem and code == 0:
            # No baseline on staging, so a timeout cannot be pinned on this
            # plugin. A plugin that really hangs a page is a hard FAIL in the
            # Playground layer (fast pages, inactive baseline).
            res.setdefault("timeouts", []).append(f"{path} timed out twice ({problem.replace('request failed: ', '')})")
        elif problem:
            res["fails"].append(f"{path} -> {problem}")

    if blocked:
        res["blocked"] = blocked[:20]
    slow = sorted((t for t in timings if t[0] >= SLOW_PAGE), reverse=True)
    if slow:
        res["slow"] = [f"{p} took {t:.0f}s" for t, p in slow[:10]]

    site.get(f"{base}/wp-admin/index.php?jezsmoke_sentinel={token}end")
    ok, why = plugin_active()
    if not ok:
        res["fails"].append(f"plugin was not active after the crawl ({why}) - something deactivated it")

    # The helper mu-plugin filters the log on the server: a run on this noisy
    # site writes ~1 MB, and only this plugin's entries plus fatals matter.
    q = urllib.parse.urlencode({"jezsmoke_log": token, "slug": slug, "domain": (res.get("domain") or "").lower()})
    site.timeout = 180  # it scans up to 16 MB of log server-side
    code, _, body = site.get(f"{base}/wp-admin/index.php?{q}")
    try:
        res["log"] = json.loads(body)
    except ValueError:
        res["log"] = {"error": f"helper returned HTTP {code}, not JSON - is wp-content/mu-plugins/jezsmoke-sentinel.php installed?"}
    return finish(res, args)


# ------------------------------------------------------------------- report

def finish(res: dict, args) -> int:
    """Decide and print the report. Every exit path of `check` goes through here."""
    if getattr(args, "cmd", "") == "check" and args.json:
        args.json.write_text(json.dumps(res))
    slug = res["slug"]
    warns, fails, env = [], list(res.get("fails", [])), list(res.get("env", []))
    log = res.get("log")
    if not env:
        if not isinstance(log, dict) or log.get("error"):
            env.append(f"debug log not read: {(log or {}).get('error', 'no helper response')}")
        elif not (log.get("start") and log.get("end")):
            env.append("the debug log does not contain both run sentinels: it was not proven to cover this run "
                       "(WP_DEBUG_LOG off, log rotated, or the sentinel mu-plugin is missing)")
        else:
            groups: dict[tuple[str, str, bool], int] = {}
            for level, line, mine in smoke.parse_log("\n".join(log.get("entries", [])), slug, res.get("domain")):
                key = (level, re.sub(r"^\[[^\]]+\]\s*", "", line), mine)
                groups[key] = groups.get(key, 0) + 1
            res["other_log"] = log.get("other", 0)
            counts = log.get("counts")
            if not isinstance(counts, dict):
                env.append("the staging helper is out of date (no failure counts) - reinstall .claude/scripts/staging-sentinel.php")
            if log.get("truncated"):
                warns.append("more than 300 relevant log entries; details capped (failures listed first, counts complete)")
            for (level, msg, mine), n in groups.items():
                t = msg + (f" (x{n})" if n > 1 else "")
                fatal = level in ("Fatal error", "Parse error", "Recoverable fatal error")
                if mine and (fatal or level == "Warning"):
                    fails.insert(0, f"log: {t}")
                elif mine:
                    warns.append(f"log: {t}")
                elif fatal:
                    fails.insert(0, f"log (outside the plugin path - an interaction on staging?): {t}")
            # The server-side counts are complete even when details were capped:
            # a failure that did not make it into `entries` still fails the run.
            if isinstance(counts, dict) and (counts.get("mine_fail") or counts.get("foreign_fatal")) \
                    and not any(f.startswith("log") for f in fails):
                fails.insert(0, f"log: {counts.get('mine_fail', 0)} plugin warning/fatal and "
                                f"{counts.get('foreign_fatal', 0)} other fatal entries (details were capped)")

    if res.get("warn_build"):
        warns.insert(0, res["warn_build"])
    if res.get("capped"):
        warns.insert(0, f"coverage capped: {res['capped']}")
    if res.get("budget_hit"):
        warns.insert(0, "crawl stopped at the time budget - coverage incomplete (see the note above)")
    warns += [f"redirect NOT followed (unsafe or off-site target): {b}" for b in res.get("blocked", [])]
    warns += [f"staging slow, not judged - check by hand if it is this plugin's page: {t}" for t in res.get("timeouts", [])]
    timed_out = {t.split(" timed out")[0] for t in res.get("timeouts", [])}
    warns += [f"slow page: {s}" for s in res.get("slow", []) if s.split(" took")[0] not in timed_out]
    if res.get("pages") and len(res.get("timeouts", [])) > res["pages"] // 4:
        env.append(f"{len(res['timeouts'])} of {res['pages']} pages timed out - staging is too slow to judge right now; re-run later")
    status = "ENVIRONMENT PROBLEM" if env else ("FAIL" if fails else ("PASS (warnings)" if warns else "PASS"))
    lines = [f"# Staging compatibility check: {slug}", "",
             f"Site: {res['site']}. Pages loaded: {res.get('pages', 0)}. "
             f"Log entries from other plugins on staging, not fatal: {res.get('other_log', 0)}.", ""]
    lines += [f"> {n}" for n in res.get("notes", [])] + [""]
    lines.append(f"## {status}")
    for label, items in (("Environment", env), ("FAIL", fails), ("WARN", warns)):
        lines += [f"- **{label}** {i}" for i in items[:40]]
    code = 3 if env else (1 if fails else 0)
    lines += ["", {0: "STAGING: PASS", 1: "STAGING: FAIL", 3: "STAGING: ENVIRONMENT PROBLEM (not a verdict on the plugin)"}[code]]
    report = "\n".join(lines)
    print(report)
    if args.out:
        args.out.write_text(report + "\n")
    return code


def cmd_report(args) -> int:
    """Re-print the verdict for a saved run (`check --json`)."""
    return finish(json.loads(args.json.read_text()), args)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = ap.add_subparsers(dest="cmd", required=True)
    b = sub.add_parser("build")
    b.add_argument("--out", type=Path, required=True)
    c = sub.add_parser("check")
    c.add_argument("--site", help=f"staging URL (default: site_url in {STAGING_CONFIG})")
    sub.add_parser("config", help="print the staging site this machine uses")
    c.add_argument("--login-url-file", type=Path, required=True)
    c.add_argument("--zip", type=Path, help="the test ZIP from `build` (its .token file proves the deployed build)")
    c.add_argument("--no-build-check", action="store_true", help="skip build identity - harness testing only")
    c.add_argument("--json", type=Path, help="also save the raw run (re-print later with `report`)")
    c.add_argument("--out", type=Path, help="also write the report to this file")
    r = sub.add_parser("report")
    r.add_argument("--json", type=Path, required=True)
    r.add_argument("--out", type=Path)
    args = ap.parse_args()
    if args.cmd == "check" and not args.zip and not args.no_build_check:
        ap.error("check needs --zip <test zip> (or --no-build-check for harness testing)")
    return {"build": cmd_build, "check": cmd_crawl, "report": cmd_report, "config": cmd_config}[args.cmd](args)


def cmd_config(args) -> int:
    top = subprocess.run(["git", "rev-parse", "--show-toplevel"], capture_output=True, text=True)
    slug = Path(top.stdout.strip()).name if top.returncode == 0 else ""
    cfg = staging_config(slug)
    if slug:
        cfg = {"plugin": slug, **cfg}
    if not cfg.get("site_url") or not cfg.get("rocket_site_id"):
        print(f"No staging site configured: create {STAGING_CONFIG} with "
              '{"site_url": "https://...", "rocket_site_id": 123456}', file=sys.stderr)
        return 2
    print(json.dumps({"config": str(STAGING_CONFIG), **cfg}, indent=2))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - a harness crash is never a plugin FAIL
        print(f"STAGING: HARNESS ERROR ({type(exc).__name__}: {exc}) - not a verdict on the plugin", file=sys.stderr)
        sys.exit(3)
