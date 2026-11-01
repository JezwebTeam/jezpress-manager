#!/usr/bin/env python3
"""Runtime smoke test for a JezPress plugin, in a throwaway WordPress Playground.

Static review (php -l, PHPCS, the two review layers) proves code parses and reads
right. It cannot see hook-timing bugs, a strict_types filter callback fataling
when another plugin passes `false`, or a WooCommerce screen that renders blank.
Those only show up when WordPress actually runs the plugin. This does that:

  1. Copies the plugin (working tree, or a release ZIP with --zip) to a temp dir,
     so nothing the plugin does at runtime can touch the repo.
  2. Boots WordPress Playground (PHP-WASM, no Docker, SQLite) at each PHP version
     under test, with WP_DEBUG logging to a mounted file. Loads WooCommerce
     only for a WooCommerce add-on and Gravity Forms (local copy) only for a
     Gravity Forms add-on - see detect_addons(); other `Requires Plugins`
     entries come from wordpress.org.
  3. Logs in and crawls the pages below with the plugin INACTIVE, as a baseline:
     a page already broken there is the environment's fault, not the plugin's.
     Then activates the plugin through a helper mu-plugin, so an activation
     fatal is reported instead of killing the boot.
  4. Loads, with the plugin active: wp-admin, plugins.php, every admin menu page, the REST
     index, the front page, a post, and for WooCommerce shop / product / cart /
     checkout (with an item in the cart) / my-account.
  5. Deactivates, reactivates, then runs uninstall.php (on the copy).
  6. Fails on any HTTP 4xx/5xx, WordPress "critical error" page, or PHP fatal /
     warning attributable to the plugin. Notices and deprecations from the
     plugin are warnings.

Usage (from the plugin repo root):
  python3 .claude/scripts/smoke_test.py                 # declared Requires PHP + 8.3
  python3 .claude/scripts/smoke_test.py --php 8.1       # one version
  python3 .claude/scripts/smoke_test.py --zip /tmp/rel/slug-1.2.0.zip
  python3 .claude/scripts/smoke_test.py --extra-plugin ~/plugins/gravityforms
  python3 .claude/scripts/smoke_test.py --self-test     # prove the harness catches errors

Exit codes: 0 PASS (warnings allowed), 1 FAIL, 2 usage error,
            3 environment problem or harness error (Playground missing, boot failed, dependency
              not installable) - not a verdict on the plugin.
"""

from __future__ import annotations

import argparse
import http.client
import http.cookiejar
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path

PLAYGROUND_PHP = ["7.4", "8.0", "8.1", "8.2", "8.3", "8.4", "8.5"]
DEFAULT_TOP_PHP = "8.3"
BOOT_TIMEOUT = 420  # seconds; first boot downloads WordPress and dependencies
REQUEST_TIMEOUT = 90
MAX_PAGES = 250  # safety cap; exceeding it is reported as a WARN, never silent
COPY_EXCLUDES = {".git", ".github", ".claude", "node_modules", "tests", ".idea", ".vscode"}
CRITICAL_MARKERS = ("There has been a critical error", "critical error on this website")
BODY_ERROR_RE = re.compile(r"<b>(Fatal error|Parse error|Warning)</b>:|^(PHP )?(Fatal error|Parse error):", re.M)
LOG_ENTRY_RE = re.compile(r"^\[[^\]]+\] PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)?:?\s*(.*)$")


def find_playground() -> str | None:
    for d in (Path.home() / ".local/bin", Path("/opt/homebrew/bin"), Path("/usr/local/bin")):
        if d.is_dir():
            os.environ["PATH"] = f"{d}{os.pathsep}{os.environ.get('PATH', '')}"
    return shutil.which("wp-playground-cli")


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def read_header(main_file: Path) -> dict[str, str]:
    head = main_file.read_text(encoding="utf-8", errors="replace")[:8192]
    out = {}
    for key in ("Plugin Name", "Requires PHP", "Requires Plugins", "WC requires at least", "Text Domain"):
        m = re.search(rf"^[ \t/*#@]*{re.escape(key)}:\s*(.+)$", head, re.M | re.I)
        if m:
            out[key] = m.group(1).strip()
    return out


def find_main_file(plugin_dir: Path) -> Path | None:
    preferred = plugin_dir / f"{plugin_dir.name}.php"
    if preferred.is_file() and "Plugin Name" in read_header(preferred):
        return preferred
    for f in sorted(plugin_dir.glob("*.php")):
        if "Plugin Name" in read_header(f):
            return f
    return None


DEFAULT_GF_DIR = Path.home() / "Projects/JEZWEB/gravityforms"
# PHP keywords and class names are case-insensitive, so the matches are too.
GF_ADDON_RE = re.compile(r"\bextends\s+\\?GF(Feed|Payment)?AddOn\b", re.I)
WC_CLASS_RE = re.compile(r"\bextends\s+\\?WC_[A-Za-z0-9_]+\b", re.I)
# Comments and string literals are not code: a commented-out example must not
# count as proof of an add-on. Strings first so `"//"` inside one is safe.
PHP_NOISE_RE = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*", re.S)
WOO_NAME_RE = re.compile(r"(^|[-_\s])(woo|woocommerce|wc)([-_\s]|$)", re.I)
SCAN_SKIP = {"vendor", "node_modules", "tests", ".git", ".github", ".claude"}
# Code under these folders is an optional integration, not proof of an add-on
# (jezpress-lms keeps its WooCommerce module in includes/modules/woocommerce/).
INTEGRATION_DIRS = {"integrations", "integration", "modules", "compat", "compatibility",
                    "woocommerce", "gravityforms", "gravity-forms"}


def detect_addons(src: Path, slug: str, header: dict[str, str]) -> dict[str, str]:
    """Why (if at all) the plugin is a WooCommerce / Gravity Forms add-on.

    An add-on REQUIRES the host plugin. `WC requires at least` alone is not
    enough: optional integrations (turnstile, lms) declare it for compatibility.
    """
    declared = {d.strip().lower() for d in header.get("Requires Plugins", "").split(",") if d.strip()}
    out = {"woocommerce": "", "gravityforms": ""}
    if "woocommerce" in declared:
        out["woocommerce"] = "header declares Requires Plugins: woocommerce"
    elif WOO_NAME_RE.search(slug) or WOO_NAME_RE.search(header.get("Plugin Name", "")):
        out["woocommerce"] = "WooCommerce/WC in the plugin slug or name"
    if "gravityforms" in declared:
        out["gravityforms"] = "header declares Requires Plugins: gravityforms"
    if out["woocommerce"] and out["gravityforms"]:
        return out
    for path in src.rglob("*.php"):
        dirs = {p.lower() for p in path.relative_to(src).parts[:-1]}
        if SCAN_SKIP.intersection(dirs) or INTEGRATION_DIRS.intersection(dirs):
            continue
        text = PHP_NOISE_RE.sub(" ", path.read_text(encoding="utf-8", errors="replace"))
        rel = path.relative_to(src)
        if not out["gravityforms"] and GF_ADDON_RE.search(text):
            out["gravityforms"] = f"{rel} extends a Gravity Forms add-on class"
        if not out["woocommerce"]:
            m = WC_CLASS_RE.search(text)
            if m:
                out["woocommerce"] = f"{rel} {m.group(0)}"
        if out["woocommerce"] and out["gravityforms"]:
            break
    return out


def php_versions(declared: str | None, requested: list[str] | None) -> tuple[list[str], list[str]]:
    notes = []
    if requested:
        bad = [v for v in requested if v not in PLAYGROUND_PHP]
        if bad:
            print(f"smoke_test: unsupported --php {bad}; Playground offers {PLAYGROUND_PHP}", file=sys.stderr)
            sys.exit(2)
        return requested, notes
    floor = "7.4"
    if declared:
        m = re.match(r"(\d+)\.(\d+)", declared)
        if m:
            want = f"{m.group(1)}.{m.group(2)}"
            if want in PLAYGROUND_PHP:
                floor = want
            else:
                notes.append(f"Requires PHP {declared} is below Playground's lowest (7.4); tested at 7.4 instead.")
    else:
        notes.append("No `Requires PHP` header; tested at 7.4 as the floor.")
    def key(v: str) -> tuple[int, ...]:
        return tuple(int(x) for x in v.split("."))
    # Add the top version only when it is above the floor: testing 8.3 for a
    # plugin that requires 8.4 would false-FAIL on its own PHP gate.
    versions = [floor, DEFAULT_TOP_PHP] if key(DEFAULT_TOP_PHP) > key(floor) else [floor]
    return versions, notes


MU_PLUGIN = r"""<?php
/**
 * Plugin Name: JezPress smoke-test helper (throwaway, local Playground only)
 */
if ( empty( $_GET['jezsmoke'] ) ) {
	return;
}

function jezsmoke_json( $data ) {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $data );
	exit;
}

// PHP_INT_MIN: run before any plugin callback that redirects on admin_init.
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		jezsmoke_json( array( 'error' => 'not logged in as admin' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugin = '__PLUGIN_FILE__';
	$action = sanitize_key( wp_unslash( $_GET['jezsmoke'] ) );

	if ( 'activate' === $action ) {
		$r = activate_plugin( $plugin, '', false, false );
		jezsmoke_json( array( 'ok' => ! is_wp_error( $r ), 'error' => is_wp_error( $r ) ? $r->get_error_message() : '', 'code' => is_wp_error( $r ) ? $r->get_error_code() : '', 'active' => is_plugin_active( $plugin ) ) );
	}
	if ( 'status' === $action ) {
		jezsmoke_json( array( 'ok' => true, 'active' => is_plugin_active( $plugin ) ) );
	}
	if ( 'deactivate' === $action ) {
		deactivate_plugins( $plugin, false, false );
		jezsmoke_json( array( 'ok' => ! is_plugin_active( $plugin ) ) );
	}
	if ( 'uninstall' === $action ) {
		// WordPress only uninstalls an inactive plugin, in a request where its
		// bootstrap never loaded. Refuse otherwise, or uninstall.php could
		// lean on classes the real deletion flow never loads.
		if ( is_plugin_active( $plugin ) ) {
			jezsmoke_json( array( 'ok' => false, 'error' => 'plugin still active; uninstall must run on an inactive plugin' ) );
		}
		$has = is_uninstallable_plugin( $plugin );
		if ( $has ) {
			uninstall_plugin( $plugin ); // Runs uninstall.php; never deletes files.
		}
		jezsmoke_json( array( 'ok' => true, 'uninstallable' => $has ) );
	}
	if ( 'sentinel' === $action ) {
		// Proves WP_DEBUG_LOG reaches the mounted file; without it a silent
		// log would read as "no warnings".
		error_log( 'jezsmoke-sentinel' );
		jezsmoke_json( array( 'ok' => true ) );
	}
	if ( 'urls' === $action ) {
		global $menu, $submenu;
		$admin = array();
		$add   = function ( $slug, $parent ) use ( &$admin ) {
			if ( ! $slug || false !== strpos( $slug, '://' ) ) {
				return;
			}
			// A registered page callback wins over a file-looking slug
			// (`demo/settings.php` is a page, not a file under wp-admin/).
			if ( get_plugin_page_hook( $slug, $parent ? $parent : $slug ) ) {
				if ( $parent && false !== strpos( $parent, '.php' ) ) {
					$admin[] = admin_url( add_query_arg( 'page', $slug, $parent ) );
				} else {
					$admin[] = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
				}
			} elseif ( false !== strpos( $slug, '.php' ) ) {
				$admin[] = admin_url( $slug );
			} elseif ( $parent && false !== strpos( $parent, '.php' ) ) {
				$admin[] = admin_url( add_query_arg( 'page', $slug, $parent ) );
			} else {
				$admin[] = admin_url( 'admin.php?page=' . $slug );
			}
		};
		foreach ( (array) $menu as $item ) {
			if ( false !== strpos( (string) ( $item[4] ?? '' ), 'wp-menu-separator' ) ) {
				continue; // A divider, not a page.
			}
			$add( $item[2] ?? '', '' );
		}
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( $items as $item ) {
				$add( $item[2] ?? '', $parent );
			}
		}

		$front = array( 'home' => home_url( '/' ) );
		$posts = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish' ) );
		if ( $posts ) {
			$front['post'] = get_permalink( $posts[0] );
		}
		$woo = function_exists( 'WC' ) && function_exists( 'wc_get_page_permalink' );
		if ( $woo ) {
			$ids = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ) );
			if ( ! $ids ) {
				$p = new WC_Product_Simple();
				$p->set_name( 'Smoke Test Product' );
				$p->set_regular_price( '10' );
				$p->set_status( 'publish' );
				$ids = array( $p->save() );
			}
			$front['shop']        = wc_get_page_permalink( 'shop' );
			$front['product']     = get_permalink( $ids[0] );
			$front['add_to_cart'] = add_query_arg( 'add-to-cart', $ids[0], home_url( '/' ) );
			$front['cart']        = wc_get_page_permalink( 'cart' );
			$front['checkout']    = wc_get_page_permalink( 'checkout' );
			$front['myaccount']   = wc_get_page_permalink( 'myaccount' );
		}
		jezsmoke_json( array( 'admin' => array_values( array_unique( $admin ) ), 'front' => $front, 'woo' => $woo ) );
	}
	jezsmoke_json( array( 'error' => 'unknown action' ) );
}, PHP_INT_MIN );
"""

CANARY = """<?php
/**
 * Plugin Name: Smoke Canary
 * Requires PHP: 8.1
 */
add_action( 'init', function () {
	trigger_error( 'smoke-canary warning on init', E_USER_WARNING );
} );
add_action( 'template_redirect', function () {
	smoke_canary_undefined_function();
} );
"""


class Site:
    def __init__(self, base: str, ua: str = "jezpress-smoke-test", timeout: int = REQUEST_TIMEOUT):
        self.base = base
        self.ua = ua
        self.timeout = timeout
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def get(self, url: str, data: dict | None = None) -> tuple[int, str, str]:
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(url, data=body, headers={"User-Agent": self.ua})
        # `timeout` bounds each socket operation, not the request: a server that
        # trickles bytes can hold a read open forever (Gravity Forms' System
        # Status page on staging hung a crawl for 20+ minutes). Read in chunks
        # against a hard deadline.
        deadline = time.monotonic() + self.timeout
        try:
            with self.opener.open(req, timeout=self.timeout) as r:
                body = self._read_bounded(r, deadline)
                if body is None:
                    return 0, url, f"request failed: no complete response within {self.timeout}s"
                return r.status, r.geturl(), body
        except urllib.error.HTTPError as e:
            # Error bodies get the same deadline, but the status is already
            # known: keep it, so a slow 5xx stays a FAIL and is never relabelled
            # as a "timeout". Read errors are caught here too - this handler
            # is not covered by the sibling except below.
            try:
                body = self._read_bounded(e, deadline)
            except (OSError, http.client.HTTPException) as exc:
                body = f"(error body unreadable: {exc})"
            return e.code, url, body if body is not None else f"(error body not received within {self.timeout}s)"
        # OSError covers URLError, ConnectionError and socket.timeout (which is
        # NOT a TimeoutError before Python 3.10 - the Mac's python3 is 3.9);
        # HTTPException covers IncompleteRead and other protocol breaks.
        except (OSError, http.client.HTTPException) as e:
            return 0, url, f"request failed: {e}"

    @staticmethod
    def _read_bounded(resp, deadline: float) -> str | None:
        """Read the body by deadline, or return None. Each blocking recv gets
        only the time remaining (best effort: the socket is reached through
        the response's file object)."""
        raw = getattr(getattr(resp, "fp", None), "raw", None)
        sock = getattr(raw, "_sock", None)
        chunks = []
        while True:
            left = deadline - time.monotonic()
            if left <= 0:
                return None
            if sock is not None:
                try:
                    sock.settimeout(max(0.5, left))
                except OSError:
                    pass
            read = getattr(resp, "read1", None) or resp.read
            chunk = read(65536)  # read1 returns after one recv; read() would wait to fill 64 KB
            if not chunk:
                break
            chunks.append(chunk)
        return b"".join(chunks).decode("utf-8", errors="replace")

    def login(self) -> bool:
        self.get(f"{self.base}/wp-login.php")
        _, final, _ = self.get(f"{self.base}/wp-login.php", {
            "log": "admin", "pwd": "password", "wp-submit": "Log In",
            "testcookie": "1", "redirect_to": f"{self.base}/wp-admin/",
        })
        return "wp-login.php" not in final

    def helper(self, action: str) -> tuple[int, dict | None, str]:
        code, _, body = self.get(f"{self.base}/wp-admin/index.php?jezsmoke={action}")
        try:
            return code, json.loads(body), body
        except ValueError:
            return code, None, body


def page_problem(code: int, final: str, body: str, requested: str = "") -> str | None:
    if code == 0:
        return body
    if code >= 400:
        return f"HTTP {code}"
    if any(m in body for m in CRITICAL_MARKERS):
        return "WordPress critical-error page"
    m = BODY_ERROR_RE.search(body)
    if m:
        return f"PHP error printed in page: {m.group(0)}"
    if (urllib.parse.urlparse(final).path.endswith("/wp-login.php")
            and urllib.parse.urlparse(requested).path.startswith("/wp-admin")):
        return "redirected to login (session lost)"
    return None


def parse_log(text: str, slug: str, domain: str | None) -> list[tuple[str, str, bool]]:
    """Return (level, first line, mine) per log entry."""
    entries = []
    for chunk in re.split(r"\n(?=\[)", text.strip()):
        if not chunk.strip():
            continue
        first = chunk.splitlines()[0]
        m = LOG_ENTRY_RE.match(first)
        level = (m.group(1) or "Other") if m else "Other"
        mine = f"/plugins/{slug}/" in chunk or (domain is not None and f"the <code>{domain}</code> domain" in chunk)
        entries.append((level, first[:300], mine))
    return entries


def wait_ready(proc: subprocess.Popen, log_path: Path, base: str, timeout: int) -> str | None:
    deadline = time.time() + timeout
    while time.time() < deadline:
        if proc.poll() is not None:
            return f"Playground exited early (code {proc.returncode})"
        if "Ready!" in log_path.read_text(errors="replace"):
            return None
        time.sleep(2)
    return f"Playground did not become ready in {timeout}s"


def stop(proc: subprocess.Popen) -> None:
    if proc.poll() is None and not hasattr(os, "killpg"):  # non-POSIX
        proc.kill()
        return
    if proc.poll() is None:
        try:
            os.killpg(proc.pid, signal.SIGTERM)
            proc.wait(timeout=15)
        except (ProcessLookupError, subprocess.TimeoutExpired):
            try:
                os.killpg(proc.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass


def crawl_list(base: str, data: dict) -> list[str]:
    urls = [f"{base}/wp-admin/", f"{base}/wp-admin/plugins.php", f"{base}/wp-json/"]
    urls += [u for u in data.get("admin", []) if u not in urls]
    front = data.get("front", {})
    for key in ("home", "post", "shop", "product", "add_to_cart", "cart", "checkout", "myaccount"):
        if front.get(key):
            urls.append(front[key])
    return urls


def drive(site: Site, base: str, res: dict, log: Path) -> None:
    """Baseline crawl, activate, crawl, cycle. Returns early on a blocking problem;
    the caller always reads the debug log afterwards."""
    if not site.login():
        res["env"].append("could not log in as admin/password")
        return

    # Prove the log channel is live before trusting a quiet log.
    site.helper("sentinel")
    if not log.exists() or "jezsmoke-sentinel" not in log.read_text(errors="replace"):
        res["env"].append("debug.log is not being written (WP_DEBUG_LOG mount broken?) - log checks would be blind")
        return

    # Baseline: crawl with the plugin INACTIVE. A page that already breaks the
    # SAME way here (WooCommerce's wc-admin pages 500 under Playground) is the
    # environment's problem; a page that breaks differently after activation
    # is still the plugin's.
    baseline: dict[str, str] = {}
    code, data, body = site.helper("urls")
    if not data or "admin" not in data:
        res["env"].append(f"helper could not list pages before activation: {page_problem(code, '', body) or body[:200]}")
        return
    for u in crawl_list(base, data):
        c, final, b = site.get(u)
        problem = page_problem(c, final, b, u)
        if problem:
            baseline[u.replace(base, "")] = problem
    # Log entries before this point come from the environment, not the plugin.
    res["log_offset"] = log.stat().st_size

    code, data, body = site.helper("activate")
    if not data or not data.get("ok") or not data.get("active"):
        reason = (data or {}).get("error") or page_problem(code, "", body) or body[:200]
        if data and data.get("code") == "plugin_missing_dependencies":
            res["env"].append(f"activation blocked by a missing dependency: {reason}")
        else:
            res["fails"].append(f"activation failed: {reason}")
        return

    code, data, body = site.helper("urls")
    if not data or "admin" not in data:
        res["fails"].append(f"helper could not list pages after activation: {page_problem(code, '', body) or body[:200]}")
        return
    urls = crawl_list(base, data)
    if len(urls) > MAX_PAGES:
        res["warns"].append(f"coverage incomplete: {len(urls)} pages found, only the first {MAX_PAGES} loaded")
        urls = urls[:MAX_PAGES]
    for u in urls:
        code, final, body = site.get(u)
        res["pages"] += 1
        problem = page_problem(code, final, body, u)
        path = u.replace(base, "")
        if problem and baseline.get(path) == problem:
            res["baseline"].append(f"{path} -> {problem} (identical with the plugin inactive; not covered)")
        elif problem and path in baseline:
            res["fails"].append(f"{path} -> {problem} (was '{baseline[path]}' with the plugin inactive)")
        elif problem:
            res["fails"].append(f"{path} -> {problem}")

    # A bootstrap guard that silently deactivates the plugin would empty the
    # crawl of its pages; prove it was still active throughout.
    code, data, body = site.helper("status")
    if not data or not data.get("active"):
        res["fails"].append("plugin was no longer active after the crawl (something deactivated it)")

    # Deactivate, reactivate, deactivate again, then uninstall in its own
    # request - each state re-read on a fresh request.
    for action, label, want_active in (("deactivate", "deactivate", False), ("activate", "reactivate", True),
                                       ("deactivate", "deactivate before uninstall", False),
                                       ("uninstall", "uninstall", None)):
        code, data, body = site.helper(action)
        if not data or not data.get("ok"):
            res["fails"].append(f"{label} failed: {(data or {}).get('error') or page_problem(code, '', body) or body[:200]}")
            continue
        if action == "uninstall" and not data.get("uninstallable"):
            res["warns"].append("no uninstall.php or uninstall hook (scaffold standard point 14 requires one)")
        if want_active is not None:
            code, st, body = site.helper("status")
            if not st or st.get("active") != want_active:
                res["fails"].append(f"{label} reported ok but the plugin is {'inactive' if want_active else 'still active'}")


def run_version(php: str, plugin_copy: Path, slug: str, main_rel: str, deps: list[str],
                extra: list[Path], wp: str, work: Path, domain: str | None) -> dict:
    res = {"php": php, "fails": [], "warns": [], "env": [], "baseline": [], "pages": 0, "other_log": 0}
    vdir = work / f"php{php}"
    logs = vdir / "logs"
    mu = vdir / "mu"
    logs.mkdir(parents=True, exist_ok=True)
    mu.mkdir(exist_ok=True)
    (mu / "jezsmoke.php").write_text(MU_PLUGIN.replace("__PLUGIN_FILE__", main_rel))

    steps = [{"step": "setSiteOptions", "options": {"permalink_structure": "/%postname%/"}}]
    for d in deps:
        steps.append({"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": d},
                      "options": {"activate": True}})
    for e in extra:
        steps.append({"step": "activatePlugin", "pluginPath": f"/wordpress/wp-content/plugins/{e.name}"})
    (vdir / "blueprint.json").write_text(json.dumps({"steps": steps}))

    port = free_port()
    base = f"http://127.0.0.1:{port}"
    cmd = ["wp-playground-cli", "server", f"--php={php}", f"--wp={wp}", f"--port={port}",
           "--mount-dir", str(plugin_copy), f"/wordpress/wp-content/plugins/{slug}",
           "--mount-dir", str(logs), "/smoke-logs",
           "--mount-dir", str(mu), "/wordpress/wp-content/mu-plugins",
           "--define-bool", "WP_DEBUG", "true",
           "--define", "WP_DEBUG_LOG", "/smoke-logs/debug.log",
           "--define-bool", "WP_DEBUG_DISPLAY", "false",
           "--blueprint", str(vdir / "blueprint.json")]
    for e in extra:
        cmd += ["--mount-dir", str(e), f"/wordpress/wp-content/plugins/{e.name}"]
    server_log = vdir / "server.log"
    with server_log.open("w") as fh:
        proc = subprocess.Popen(cmd, cwd=vdir, stdout=fh, stderr=subprocess.STDOUT, start_new_session=True)
    try:
        err = wait_ready(proc, server_log, base, BOOT_TIMEOUT)
        if err:
            tail = server_log.read_text(errors="replace").strip().splitlines()[-12:]
            res["env"].append(err + "\n    " + "\n    ".join(tail))
        else:
            drive(Site(base), base, res, logs / "debug.log")
    finally:
        stop(proc)

    log = logs / "debug.log"
    if log.exists() and "log_offset" in res:
        # Only entries written after the baseline crawl count. The same entry
        # repeats once per request: group by message (timestamp stripped) and
        # list fatals first so they are never buried.
        text = log.read_bytes()[res["log_offset"]:].decode("utf-8", errors="replace")
        groups: dict[tuple[str, str, bool], int] = {}
        for level, line, mine in parse_log(text, slug, domain):
            key = (level, re.sub(r"^\[[^\]]+\]\s*", "", line), mine)
            groups[key] = groups.get(key, 0) + 1
        rank = {"Fatal error": 0, "Parse error": 0, "Recoverable fatal error": 0, "Warning": 1}
        log_fails = []
        for (level, msg, mine), n in sorted(groups.items(), key=lambda kv: rank.get(kv[0][0], 2)):
            text = msg + (f" (x{n})" if n > 1 else "")
            fatal = level in ("Fatal error", "Parse error", "Recoverable fatal error")
            if mine and (fatal or level == "Warning"):
                log_fails.append(f"log: {text}")
            elif mine:
                res["warns"].append(f"log: {text}")
            elif fatal:
                log_fails.append(f"log (outside the plugin path - check for an interaction): {text}")
            elif level == "Warning":
                # e.g. a filter returning null makes core warn in class-wp-hook.php:
                # not the plugin's path, but only seen after it activated.
                res["warns"].append(f"log (outside the plugin path, after activation): {text}")
            else:
                res["other_log"] += n
        res["fails"] = log_fails + res["fails"]
    return res


def copy_plugin(src: Path, dest: Path) -> None:
    shutil.copytree(src, dest, ignore=lambda d, names: [n for n in names if n in COPY_EXCLUDES and Path(d) == src])


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--plugin-dir", type=Path, help="plugin folder (default: repo root)")
    ap.add_argument("--zip", type=Path, help="test this release ZIP instead of the working tree")
    ap.add_argument("--php", action="append", help="PHP version to test (repeatable); default: Requires PHP + 8.3")
    ap.add_argument("--wp", default="latest", help="WordPress version (default: latest)")
    ap.add_argument("--extra-plugin", type=Path, action="append", default=[],
                    help="local plugin folder to install and activate first (e.g. a commercial dependency)")
    ap.add_argument("--out", type=Path, help="also write the report to this file")
    ap.add_argument("--keep", action="store_true", help="keep the temp dir (logs, server output)")
    ap.add_argument("--self-test", action="store_true", help="run against a deliberately broken plugin; must FAIL")
    ap.add_argument("--woo", choices=("auto", "yes", "no"), default="auto",
                    help="load WooCommerce: auto = only for a WooCommerce add-on (default)")
    ap.add_argument("--gravityforms", choices=("auto", "yes", "no"), default="auto",
                    help="load Gravity Forms from JEZSMOKE_GRAVITYFORMS_DIR (default ~/Projects/JEZWEB/gravityforms): "
                         "auto = only for a Gravity Forms add-on (default)")
    args = ap.parse_args()

    # A stopped background task gets SIGTERM: turn it into SystemExit so every
    # finally runs (Playground killed, temp dir removed).
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(3))

    if args.out and not args.out.resolve().parent.is_dir():
        print(f"smoke_test: --out directory does not exist: {args.out.parent}", file=sys.stderr)
        return 2

    if not find_playground():
        print("SMOKE UNAVAILABLE: wp-playground-cli not installed (npm install -g @wp-playground/cli)", file=sys.stderr)
        return 3

    work = Path(tempfile.mkdtemp(prefix="jezsmoke-"))
    try:
        if args.self_test:
            src = work / "src" / "smoke-canary"
            src.mkdir(parents=True)
            (src / "smoke-canary.php").write_text(CANARY)
        elif args.zip:
            if not args.zip.is_file():
                print(f"smoke_test: ZIP not found: {args.zip}", file=sys.stderr)
                return 2
            try:
                with zipfile.ZipFile(args.zip) as z:
                    z.extractall(work / "src")  # extractall drops absolute and ../ member paths
            except zipfile.BadZipFile:
                print(f"smoke_test: not a valid ZIP: {args.zip}", file=sys.stderr)
                return 2
            tops = [p for p in (work / "src").iterdir() if p.is_dir()]
            if len(tops) != 1:
                print("smoke_test: ZIP must contain exactly one top-level plugin folder", file=sys.stderr)
                return 2
            src = tops[0]
        else:
            if args.plugin_dir:
                src = args.plugin_dir.resolve()
            else:
                top = subprocess.run(["git", "rev-parse", "--show-toplevel"], capture_output=True, text=True)
                if top.returncode != 0:
                    print("smoke_test: not in a git repo; pass --plugin-dir", file=sys.stderr)
                    return 2
                src = Path(top.stdout.strip())
        for e in args.extra_plugin:
            if not e.is_dir():
                print(f"smoke_test: --extra-plugin is not a folder: {e}", file=sys.stderr)
                return 2

        main_file = find_main_file(src)
        if not main_file:
            print(f"smoke_test: no file with a 'Plugin Name:' header in {src}", file=sys.stderr)
            return 2
        slug = src.name
        header = read_header(main_file)
        if "{" in slug or "{PLUGIN" in header.get("Plugin Name", ""):
            print("smoke_test: this is the unscaffolded template; run it in a plugin made from it", file=sys.stderr)
            return 2
        main_rel = f"{slug}/{main_file.name}"
        domain = header.get("Text Domain")
        # Load WooCommerce / Gravity Forms ONLY for a real add-on of that
        # plugin. Optional integrations (turnstile, spam-filter) are tested
        # without them unless forced with --woo yes / --gravityforms yes.
        addon = detect_addons(src, slug, header)
        dep_notes = []
        declared = [d.strip().lower() for d in header.get("Requires Plugins", "").split(",") if d.strip()]
        # Other declared hard dependencies (e.g. elementor) come from wordpress.org.
        deps = [d for d in declared if d not in ("woocommerce", "gravityforms")]
        want_woo = {"yes": True, "no": False}.get(args.woo, bool(addon["woocommerce"]))
        want_gf = {"yes": True, "no": False}.get(args.gravityforms, bool(addon["gravityforms"]))
        if want_woo:
            deps.insert(0, "woocommerce")
            dep_notes.append("WooCommerce: " + (addon["woocommerce"] if args.woo == "auto" else "forced with --woo yes"))
        extra_dirs = [e.resolve() for e in args.extra_plugin]
        if want_gf and not any(e.name == "gravityforms" for e in extra_dirs):
            gf = Path(os.environ.get("JEZSMOKE_GRAVITYFORMS_DIR", str(DEFAULT_GF_DIR))).expanduser()
            if not (gf / "gravityforms.php").is_file():
                print(f"SMOKE: ENVIRONMENT PROBLEM - this is a Gravity Forms add-on but no Gravity Forms copy was found at {gf}. "
                      "Set JEZSMOKE_GRAVITYFORMS_DIR or pass --extra-plugin <gravityforms folder>.", file=sys.stderr)
                return 3
            extra_dirs.append(gf.resolve())
        if want_gf:
            dep_notes.append("Gravity Forms (local copy): " + (addon["gravityforms"] if args.gravityforms == "auto" else "forced with --gravityforms yes"))
        extra_names = {e.name for e in extra_dirs}
        deps = [d for d in deps if d not in extra_names]
        versions, notes = php_versions(header.get("Requires PHP"), args.php)

        print(f"smoke_test: {slug} on PHP {', '.join(versions)}, WordPress {args.wp}"
              + (f", deps: {', '.join(deps + sorted(extra_names))}" if deps or extra_names else "") + " ...",
              file=sys.stderr, flush=True)
        results = []
        for v in versions:
            print(f"  PHP {v}: booting ...", file=sys.stderr, flush=True)
            # Fresh copies per version: activation or uninstall on one PHP
            # version must not change what the next one tests.
            plugin_copy = work / f"php{v}" / "plugin" / slug
            copy_plugin(src, plugin_copy)
            extra = []
            for e in extra_dirs:
                dest = work / f"php{v}" / "extra" / e.name
                copy_plugin(e, dest)
                extra.append(dest)
            results.append(run_version(v, plugin_copy, slug, main_rel, deps, extra, args.wp, work, domain))

        lines = [f"# Runtime smoke test: {slug}", "",
                 f"Source: {'self-test canary' if args.self_test else (args.zip or src)}. "
                 f"WordPress {args.wp}. Dependencies installed: {', '.join(deps + sorted(extra_names)) or 'none'}.", ""]
        lines += [f"> Loaded because it is an add-on of {n}" for n in dep_notes]
        if not want_woo and not want_gf:
            lines.append("> Not a WooCommerce or Gravity Forms add-on: tested without either.")
        lines += [f"> {n}" for n in notes] + [""]
        any_fail = any(r["fails"] for r in results)
        any_env = any(r["env"] for r in results)
        for r in results:
            status = "ENV PROBLEM" if r["env"] else ("FAIL" if r["fails"] else ("PASS (warnings)" if r["warns"] else "PASS"))
            lines.append(f"## PHP {r['php']}: {status}")
            lines.append(f"Pages loaded: {r['pages']}. Log entries from other code (WordPress, dependencies), not fatal: {r['other_log']}.")
            for label, key in (("Environment", "env"), ("FAIL", "fails"), ("WARN", "warns"), ("Ignored", "baseline")):
                for item in r[key][:25]:
                    lines.append(f"- **{label}** {item}")
                if len(r[key]) > 25:
                    lines.append(f"- ... {len(r[key]) - 25} more {label} lines")
            lines.append("")
        if args.self_test:
            # Both channels must fire: the HTTP check (front-end 500s) AND the
            # log check (the init warning only the log can see).
            fails = [f for r in results for f in r["fails"]]
            http_ok = any(" -> HTTP 5" in f for f in fails)
            log_ok = any(f.startswith("log:") and "smoke-canary warning on init" in f for f in fails)
            if any_env:
                verdict, code = "SELF-TEST INCONCLUSIVE: environment problem (see above)", 3
            elif http_ok and log_ok:
                verdict, code = "SELF-TEST PASS: the harness caught the canary's errors on both the HTTP and log channels", 0
            else:
                missed = ", ".join(n for n, ok in (("HTTP", http_ok), ("log", log_ok)) if not ok)
                verdict, code = f"SELF-TEST FAIL: the {missed} channel missed the canary's errors - do not trust this harness", 1
        elif any_fail:
            verdict, code = "SMOKE: FAIL", 1
        elif any_env:
            verdict, code = "SMOKE: ENVIRONMENT PROBLEM (not a verdict on the plugin)", 3
        else:
            verdict, code = "SMOKE: PASS", 0
        lines.append(verdict)
        if args.keep:
            lines.append(f"\nTemp dir kept: {work}")
        report = "\n".join(lines)
        print(report)
        if args.out:
            args.out.write_text(report + "\n")
        return code
    finally:
        if not args.keep:
            shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # noqa: BLE001 - a harness crash must never read as a plugin FAIL (1)
        print(f"SMOKE: HARNESS ERROR ({type(exc).__name__}: {exc}) - not a verdict on the plugin", file=sys.stderr)
        sys.exit(3)
