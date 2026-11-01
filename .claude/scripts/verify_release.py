#!/usr/bin/env python3
"""Read-only post-upload verification for a JezPress plugin release.

Run by the jezpress-release-verifier agent at the verify step of /release,
after the upload and the metadata PATCHes. It never writes to the server, the
repo or the ZIP. Every check here exists because the thing it checks has
failed silently on a real release - the server answers 200 to writes it does
not keep, and the CLI reports success for changes it never made.

    python3 .claude/scripts/verify_release.py --version 1.2.8 --prev 1.2.7 \
        --zip ../jezweb-cloudflare-turnstile.zip

Licensed plugins (requires_license: true) need a key for the update-endpoint
checks. Pass it as a FILE (--license-key-file), never on the command line:
approved commands are recorded verbatim in .claude/settings.local.json.

Exit status: 0 = no FAIL, 1 = at least one FAIL, 2 = could not run.
Python 3.9 compatible (the macOS system python).
"""

import argparse
import fnmatch
import io
import json
import os
import re
import subprocess
import sys
import tarfile
import time
import zipfile
from urllib.parse import urlencode

API = "https://updates.jezpress.com"
TOKEN_PATH = os.path.expanduser("~/.jezpress-token")

# How the expected ZIP file list is derived, matching however the repo builds it:
# - a .github/workflows/release.yml with rsync --exclude lines (wp-plugin-template
#   plugins, ZIP built in CI): the tracked files minus those excludes, with rsync
#   semantics - a slash-free pattern matches that name at ANY depth;
# - otherwise (ZIP built locally with git archive, e.g. jezweb-cloudflare-turnstile):
#   git archive of the tag (which honours export-ignore) minus the skill's drop list.
ZIP_DROP = ("CLAUDE.md", ".gitignore", ".gitattributes", ".claude/", ".github/")

# Anything matching these inside the ZIP is dev material or a secret.
ZIP_FORBIDDEN = [
    (r"(^|/)CLAUDE\.md$", "CLAUDE.md"),
    (r"(^|/)\.git(ignore|attributes|/)", ".git material"),
    (r"(^|/)\.claude/", ".claude/"),
    (r"(^|/)\.github/", ".github/"),
    (r"(^|/)\._", "macOS AppleDouble"),
    (r"(^|/)\.DS_Store$", ".DS_Store"),
    # Root vendor/ carrying dev tooling. A root vendor/ with RUNTIME libraries can
    # be legitimate (see ZIP_WARN), and assets/*/vendor/ front-end libraries are.
    (r"^[^/]+/vendor/(squizlabs|wp-coding-standards|phpcompatibility|phpcsstandards|dealerdirect"
     r"|phpunit|phpstan|sebastian|yoast/phpunit-polyfills)/", "vendor/ dev tooling (PHPCS/PHPUnit)"),
    (r"(^|/)node_modules/", "node_modules/"),
    (r"settings\.local\.json$", "settings.local.json"),
    (r"(^|/)\.env", ".env file"),
    (r"jezpress-token", "JezPress token"),
    (r"\.zip$", "nested ZIP"),
]
ZIP_WARN = [
    (r"^[^/]+/composer\.(json|lock)$", "composer file (dev-only, known gap)"),
    (r"^[^/]+/vendor/", "root vendor/ ships: confirm it is runtime-only and the bootstrap autoloader resolves it"),
]

MOJIBAKE = ("\u00e2\u20ac", "\u00c3", "\ufffd")

results = []  # (status, name, detail)


def record(status, name, detail=""):
    results.append((status, name, detail))


def vtuple(v):
    return tuple(int(x) for x in re.findall(r"\d+", v or ""))


def run(cmd, cwd=None):
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)


def curl_json(url, token=None):
    """curl, not urllib: Cloudflare blocks urllib's default User-Agent here."""
    cmd = ["curl", "-s", "--max-time", "30", url]
    if token:
        cmd[1:1] = ["-H", "Authorization: Bearer " + token]
    out = run(cmd)
    if out.returncode != 0:
        return None, "curl exit %d" % out.returncode
    try:
        return json.loads(out.stdout), None
    except ValueError:
        return None, "non-JSON reply (%d bytes)" % len(out.stdout)


def text_issue(s):
    """Describe mojibake / non-ASCII in a stored text field, or ''."""
    s = s or ""
    if any(m in s for m in MOJIBAKE):
        return "mojibake"
    if not s.isascii():
        return "non-ASCII"
    return ""


# --------------------------------------------------------------------- local


def read_header(path):
    with io.open(path, encoding="utf-8", errors="replace") as fh:
        head = fh.read(8192)

    def field(name):
        # Anchored so "WC requires at least" / "WC tested up to" never match.
        m = re.search(r"^[ \t/*#@]*" + re.escape(name) + r":[ \t]*(.+?)\s*$", head, re.M | re.I)
        return m.group(1) if m else None

    const = re.search(r"define\(\s*'([A-Z0-9_]+_VERSION)'\s*,\s*'([^']+)'", head)
    return {
        "version": field("Version"),
        "requires_php": field("Requires PHP"),
        "requires_wp": field("Requires at least"),
        "tested_wp": field("Tested up to"),
        "wc_tested": field("WC tested up to"),
        "const": const.groups() if const else None,
    }


def read_readme(path):
    if not os.path.exists(path):
        return {}, ""
    with io.open(path, encoding="utf-8", errors="replace") as fh:
        body = fh.read()

    def field(name):
        m = re.search(r"^" + re.escape(name) + r":[ \t]*(.+?)\s*$", body, re.M | re.I)
        return m.group(1) if m else None

    return {"stable": field("Stable tag"), "tested_wp": field("Tested up to")}, body


def readme_section(body, heading):
    m = re.search(r"^== " + re.escape(heading) + r" ==\s*$(.*?)(?=^== |\Z)", body, re.M | re.S)
    return m.group(1) if m else ""


def check_repo(repo, slug, ver, header, readme, body):
    if header["version"] == ver:
        record("PASS", "header Version", ver)
    else:
        record("FAIL", "header Version", "%s, expected %s" % (header["version"], ver))

    if not header["const"]:
        record("FAIL", "version constant", "no define('<PREFIX>_VERSION', ...) in main file")
    elif header["const"][1] == ver:
        record("PASS", "version constant", "%s = %s" % header["const"])
    else:
        record("FAIL", "version constant", "%s = %s, expected %s" % (header["const"] + (ver,)))

    if readme.get("stable") == ver:
        record("PASS", "readme Stable tag", ver)
    else:
        record("FAIL", "readme Stable tag", "%s, expected %s" % (readme.get("stable"), ver))

    block = "= %s =" % ver
    for heading in ("Changelog", "Upgrade Notice"):
        section = readme_section(body, heading)
        if block in section:
            record("PASS", "readme %s entry" % heading, block)
        else:
            record("FAIL", "readme %s entry" % heading, "no '%s' under == %s ==" % (block, heading))

    tag = "v" + ver
    local = run(["git", "rev-parse", "-q", "--verify", "refs/tags/%s^{commit}" % tag], cwd=repo)
    if local.returncode != 0:
        record("WARN", "git tag", "%s does not exist locally" % tag)
        return None
    remote = run(["git", "ls-remote", "--tags", "origin", "refs/tags/%s" % tag], cwd=repo)
    if remote.returncode == 0 and remote.stdout.strip():
        record("PASS", "git tag", "%s exists and is on origin" % tag)
    else:
        record("WARN", "git tag", "%s exists locally but not on origin" % tag)
    return tag


# ----------------------------------------------------------------------- zip


def check_zip(zip_path, repo, slug, ver, ref):
    if not zip_path or not os.path.exists(zip_path):
        record("FAIL", "ZIP present", "%s not found (verify BEFORE deleting it)" % zip_path)
        return
    try:
        zf = zipfile.ZipFile(zip_path)
    except zipfile.BadZipFile:
        record("FAIL", "ZIP readable", "not a valid ZIP")
        return
    names = [n for n in zf.namelist() if not n.endswith("/")]

    outside = [n for n in names if not n.startswith(slug + "/")]
    if outside:
        record("FAIL", "ZIP folder", "%d entries outside %s/, e.g. %s" % (len(outside), slug, outside[0]))
    else:
        record("PASS", "ZIP folder", "%s/ (%d files)" % (slug, len(names)))

    main = "%s/%s.php" % (slug, slug)
    if main in names:
        m = re.search(r"^[ \t/*#@]*Version:[ \t]*(.+?)\s*$", zf.read(main).decode("utf-8", "replace")[:8192], re.M)
        zver = m.group(1) if m else None
        if zver == ver:
            record("PASS", "ZIP main file version", "%s -> %s" % (main, ver))
        else:
            record("FAIL", "ZIP main file version", "%s, expected %s (stale ZIP?)" % (zver, ver))
    else:
        record("FAIL", "ZIP main file", "%s missing" % main)

    bad = sorted({label for n in names for rx, label in ZIP_FORBIDDEN if re.search(rx, n)})
    if bad:
        record("FAIL", "ZIP dev material", ", ".join(bad))
    else:
        record("PASS", "ZIP dev material", "none")
    for rx, label in ZIP_WARN:
        if any(re.search(rx, n) for n in names):
            record("WARN", "ZIP contents", label)

    # Exact comparison with the file list the repo's own build should produce.
    # Catches a ZIP built from the working tree (untracked files in) and files
    # lost out. See ZIP_DROP for how the expected list is derived.
    expected, rule = expected_zip_files(repo, slug, ref)
    if expected is None:
        record("WARN", "ZIP vs expected", rule)
        return
    extra = sorted(set(names) - expected)
    missing = sorted(expected - set(names))
    if not extra and not missing:
        record("PASS", "ZIP vs expected", "identical file list (%s, %s)" % (rule, ref))
    else:
        parts = []
        if extra:
            parts.append("%d not expected (e.g. %s)" % (len(extra), extra[0]))
        if missing:
            parts.append("%d missing (e.g. %s)" % (len(missing), missing[0]))
        record("FAIL", "ZIP vs expected", "; ".join(parts) + " [%s, %s]" % (rule, ref))


def rsync_excludes(repo):
    wf = os.path.join(repo, ".github", "workflows", "release.yml")
    if not os.path.exists(wf):
        return None
    with io.open(wf, encoding="utf-8", errors="replace") as fh:
        pats = re.findall(r"--exclude=['\"]?([^'\"\s]+)", fh.read())
    return pats or None


def rsync_excluded(path, pats):
    parts = path.split("/")
    for pat in pats:
        pat = pat.rstrip("/")
        if "/" in pat:
            if fnmatch.fnmatch(path, pat.lstrip("/")) or fnmatch.fnmatch(path, pat.lstrip("/") + "/*"):
                return True
        elif any(fnmatch.fnmatch(part, pat) for part in parts):
            return True
    return False


def expected_zip_files(repo, slug, ref):
    pats = rsync_excludes(repo)
    if pats:
        out = run(["git", "ls-tree", "-r", "--name-only", ref], cwd=repo)
        if out.returncode != 0:
            return None, "git ls-tree %s failed" % ref
        files = [f for f in out.stdout.splitlines() if f and not rsync_excluded(f, pats)]
        return {"%s/%s" % (slug, f) for f in files}, "release.yml rsync excludes"
    arch = subprocess.run(["git", "archive", "--format=tar", ref], cwd=repo, capture_output=True)
    if arch.returncode != 0:
        return None, "git archive %s failed" % ref
    with tarfile.open(fileobj=io.BytesIO(arch.stdout)) as tar:
        files = {
            "%s/%s" % (slug, m.name)
            for m in tar.getmembers()
            if m.isfile() and not m.name.startswith(ZIP_DROP)
        }
    return files, "git archive + drop list"


# -------------------------------------------------------------------- server


def poll_record(slug, token, polls, delay):
    snaps = []
    for i in range(polls):
        if i:
            time.sleep(delay)
        data, err = curl_json("%s/api/dev/plugins/%s" % (API, slug), token)
        if data and data.get("plugin"):
            data["plugin"]["_available"] = data.get("available_versions")
            snaps.append(data["plugin"])
    return snaps


def any_snapshot(snaps, name, fn, fail_status="FAIL"):
    """Edge caches alternate stale and fresh reads, so a check passes when ANY
    snapshot satisfies it. One read proves nothing in either direction."""
    detail = ""
    for p in snaps:
        ok, detail = fn(p)
        if ok:
            record("PASS", name, detail)
            return
    record(fail_status, name, detail + (" (%d reads)" % len(snaps) if len(snaps) > 1 else ""))


def check_server(snaps, slug, ver, header, readme):
    def eq(field, want):
        def fn(p):
            if p.get(field) == want:
                return True, "%s" % want
            return False, "%s=%r, expected %r" % (field, p.get(field), want)
        return fn

    any_snapshot(snaps, "current_version", eq("current_version", ver))
    any_snapshot(snaps, "stable channel",
                 lambda p: ((p.get("channels") or {}).get("stable") == ver,
                            "channels.stable=%r" % (p.get("channels") or {}).get("stable")))
    any_snapshot(snaps, "owner_email",
                 lambda p: (True, p["owner_email"]) if p.get("owner_email") else
                           (False, "null: CLI + raw API are locked out (admin reassignment needed)"))
    any_snapshot(snaps, "requires_php (top)", eq("requires_php", header["requires_php"]))
    any_snapshot(snaps, "requires_wp (top)", eq("requires_wp", header["requires_wp"]))

    tw = snaps[-1].get("tested_wp")
    want_tw = header["tested_wp"] or readme.get("tested_wp")
    if not tw:
        record("FAIL", "tested_wp", "not set")
    elif header["wc_tested"] and tw == header["wc_tested"] and tw != want_tw:
        record("FAIL", "tested_wp", "%s = the 'WC tested up to' value; --sync-metadata shadowing" % tw)
    elif vtuple(tw) and vtuple(tw)[0] >= 10:
        record("FAIL", "tested_wp", "%s is not a real WordPress version" % tw)
    elif want_tw and tw != want_tw:
        record("WARN", "tested_wp", "server %s, readme/header says %s" % (tw, want_tw))
    else:
        record("PASS", "tested_wp", tw)

    record("PASS" if snaps[-1].get("requires_license") is not None else "WARN",
           "requires_license", repr(snaps[-1].get("requires_license")))

    any_snapshot(snaps, "available_versions",
                 lambda p: (True, "%s listed" % ver) if ver in (p.get("_available") or p.get("versions") or {})
                           else (False, "%s not listed" % ver))

    def new_version(p):
        v = (p.get("versions") or {}).get(ver)
        if not v:
            return False, "versions[%s] missing" % ver
        probs = []
        if not v.get("changelog"):
            probs.append("changelog empty")
        elif text_issue(v["changelog"]):
            probs.append("changelog " + text_issue(v["changelog"]))
        if v.get("requires_php") != header["requires_php"]:
            probs.append("requires_php=%r" % v.get("requires_php"))
        if v.get("requires_wp") != header["requires_wp"]:
            probs.append("requires_wp=%r" % v.get("requires_wp"))
        if probs:
            return False, "; ".join(probs)
        return True, "changelog %d chars, php %s, wp %s" % (len(v["changelog"]), v["requires_php"], v["requires_wp"])

    any_snapshot(snaps, "version %s record" % ver, new_version)

    # Every historical version: an older entry's changelog can vanish unnoticed.
    all_versions = set()
    for p in snaps:
        all_versions.update((p.get("versions") or {}).keys())
    for k in sorted(all_versions - {ver}, key=vtuple, reverse=True):
        def old_version(p, k=k):
            v = (p.get("versions") or {}).get(k)
            if not v:
                return False, "missing"
            if not v.get("changelog"):
                return False, "changelog empty"
            if text_issue(v["changelog"]):
                return False, "changelog " + text_issue(v["changelog"])
            if not v.get("requires_php"):
                return False, "requires_php empty"
            return True, "%d chars" % len(v["changelog"])
        any_snapshot(snaps, "version %s record" % k, old_version, fail_status="WARN")

    def section(name, must_contain=None):
        def fn(p):
            s = (p.get("sections") or {}).get(name) or ""
            if not s:
                return False, "empty"
            if must_contain and must_contain not in s:
                return False, "%d chars but no %r (stale read or not patched)" % (len(s), must_contain)
            issue = text_issue(s)
            if issue == "mojibake":
                return False, "%d chars, mojibake" % len(s)
            return True, "%d chars%s" % (len(s), ", " + issue if issue else "")
        return fn

    any_snapshot(snaps, "sections.description", section("description"))
    any_snapshot(snaps, "sections.changelog", section("changelog", "Version " + ver))


def check_update_endpoint(slug, ver, prev, header, requires_license, site_url, key):
    if requires_license and not key:
        record("SKIP", "update endpoint",
               "requires_license=true: ask Abner for the licence key + exact activated site_url")
        return

    def query(version, with_key=True, tries=3):
        params = {"plugin": slug, "version": version, "site_url": site_url}
        if key and with_key:
            params["license_key"] = key  # URL query param, never a Bearer header
        data, err = None, None
        for i in range(tries):
            if i:
                time.sleep(3)
            data, err = curl_json("%s/api/v1/update?%s" % (API, urlencode(params)))
            # Licence activation is eventually consistent too: retry these.
            if data and data.get("error") not in ("domain_not_registered",):
                break
        return data, err

    data, err = query(prev)
    if not data:
        record("FAIL", "update from %s" % prev, err)
    elif not data.get("success"):
        record("FAIL", "update from %s" % prev, "error=%r" % data.get("error"))
    else:
        probs = []
        if data.get("update_available") is not True:
            probs.append("update_available=%r" % data.get("update_available"))
        if data.get("version") != ver:
            probs.append("version=%r" % data.get("version"))
        if data.get("requires_php") != header["requires_php"]:
            probs.append("requires_php=%r" % data.get("requires_php"))
        if not data.get("download_url"):
            probs.append("no download_url")  # never print it: it embeds the licence key
        issue = text_issue(data.get("changelog"))
        if issue == "mojibake":
            probs.append("changelog mojibake (customers' View details popup)")
        if probs:
            record("FAIL", "update from %s" % prev, "; ".join(probs))
        else:
            record("PASS", "update from %s" % prev, "offers %s, php %s" % (ver, data.get("requires_php")))

    data, err = query(ver)
    if not data:
        record("FAIL", "no self-update at %s" % ver, err)
    elif not data.get("success"):
        record("FAIL", "no self-update at %s" % ver, "error=%r" % data.get("error"))
    elif data.get("update_available") is not True:
        record("PASS", "no self-update at %s" % ver, "update_available=%r" % data.get("update_available"))
    else:
        record("FAIL", "no self-update at %s" % ver,
               "update_available=%r: the unsatisfiable '%s -> %s' prompt (#20)" % (data.get("update_available"), ver, ver))

    if requires_license:
        data, err = query(prev, with_key=False, tries=1)
        refused = data and not data.get("success") and data.get("error") == "license_required"
        record("PASS" if refused else "FAIL", "refuses without licence",
               "license_required" if refused else "did not refuse: %r" % (data or err))


# ---------------------------------------------------------------------- main


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--version", required=True, help="the version just released")
    ap.add_argument("--prev", required=True, help="the previous released version")
    ap.add_argument("--zip", help="the release ZIP that was uploaded")
    ap.add_argument("--slug", help="default: the repo folder name")
    ap.add_argument("--repo", help="default: git rev-parse --show-toplevel")
    ap.add_argument("--site-url", default="https://example.com")
    ap.add_argument("--license-key-file", help="file holding the licence key (licensed plugins only)")
    ap.add_argument("--polls", type=int, default=4, help="reads of the plugin record (edge caches alternate)")
    ap.add_argument("--delay", type=float, default=4.0)
    a = ap.parse_args()

    repo = a.repo or run(["git", "rev-parse", "--show-toplevel"]).stdout.strip()
    if not repo:
        print("ERROR: not inside a git repo; pass --repo", file=sys.stderr)
        return 2
    slug = a.slug or os.path.basename(repo)
    main_file = os.path.join(repo, slug + ".php")
    if not os.path.exists(main_file):
        print("ERROR: %s not found (folder name must equal the slug)" % main_file, file=sys.stderr)
        return 2
    if not os.path.exists(TOKEN_PATH):
        print("ERROR: %s missing; run 'jezpress login'" % TOKEN_PATH, file=sys.stderr)
        return 2
    with io.open(TOKEN_PATH) as fh:
        token = fh.read().strip()
    key = None
    if a.license_key_file:
        with io.open(a.license_key_file) as fh:
            key = fh.read().strip() or None

    header = read_header(main_file)
    readme, body = read_readme(os.path.join(repo, "readme.txt"))

    tag = check_repo(repo, slug, a.version, header, readme, body)
    check_zip(a.zip, repo, slug, a.version, tag or "HEAD")

    snaps = poll_record(slug, token, max(1, a.polls), a.delay)
    if not snaps:
        record("FAIL", "plugin record", "GET /api/dev/plugins/%s failed (token expired? run 'jezpress login')" % slug)
    else:
        check_server(snaps, slug, a.version, header, readme)
        check_update_endpoint(slug, a.version, a.prev, header,
                              bool(snaps[-1].get("requires_license")), a.site_url, key)

    width = max(len(n) for _, n, _ in results)
    for status, name, detail in results:
        print("%-4s  %-*s  %s" % (status, width, name, detail))
    counts = {s: sum(1 for r in results if r[0] == s) for s in ("PASS", "WARN", "FAIL", "SKIP")}
    verdict = "FAIL" if counts["FAIL"] else "PASS"
    print("\nRESULT: %s  (%d pass, %d warn, %d fail, %d skip)" % (
        verdict, counts["PASS"], counts["WARN"], counts["FAIL"], counts["SKIP"]))
    return 1 if counts["FAIL"] else 0


if __name__ == "__main__":
    sys.exit(main())
