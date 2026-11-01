# JezPress scaffold standard — acceptance checklist (free-plugin variant)

Canonical source = the `wp-plugin-template` repo. Cross-check against it; this is a verification checklist, not a replacement.

> **Licence points omitted on purpose.** The full JezPress standard has two extra items (a `<X>_License` singleton with a hashed `jzwb_lic_*` option + daily cron, and a `license_key` URL param on the updater). `jezpress-manager` is a **free plugin with no licence validation**, so neither applies. Don't add them back here — if a licence layer is ever wanted, that's a product decision, not a checklist gap.

1. **Plugin header**: Name, URI, Description, Version, Author, Author URI, License (GPL-2.0+), License URI, Text Domain, Domain Path, Requires at least (6.0+), Requires PHP (8.1+); WC headers when applicable.
2. **PHP version gate** at top of bootstrap — admin notice + `deactivate_plugins` if below the declared minimum. Verify the declared minimum actually parses: `php .claude/tools/php-compat.php`.
3. **Constants**: `<PREFIX>_VERSION`, `_PLUGIN_FILE`, `_PLUGIN_PATH`/`_PLUGIN_DIR`, `_PLUGIN_URL` (+ `JEZPRESS_MANAGER_ACTIVE` here — other plugins branch on it).
4. **HPOS + Cart/Checkout block compat** declared on `before_woocommerce_init` (only if a WC plugin — this one is not).
5. **`index.php` silence file** in every directory.
6. **ABSPATH guard** at the top of every PHP file (`defined( 'ABSPATH' ) || exit;`).
7. **Single settings option** with one sanitiser clamping every key. Defaults centralised; enum allowlists as `const`s.
8. **AJAX**: `check_ajax_referer` FIRST → capability + ownership/identifier validation → unslash + sanitize every `$_POST`/`$_GET`. No raw superglobal reaches output. *(This is exactly where 1.1.4's path-traversal fix landed — `plugin_basename()` + `validate_plugin()` before any filesystem access. Keep that shape for any new handler.)*
9. **Updater**: hits `updates.jezpress.com` over `wp_remote_get`, checks `is_wp_error` + the response code, caches in a namespaced transient, and never fails loudly on a network error. End-to-end verify via curl — see `updater-contract.md`.
10. **Registration API stability**: `JezPress_Manager::register()` is a public contract other plugins call. New parameters get a default + a sanitiser + docs in README.md and CLAUDE.md; never rename or repurpose an existing key.
11. **Templates / markup helpers** — escape at the point of output.
12. **Output escaping** everywhere: `esc_url` / `esc_attr` / `esc_html` / `wp_kses_post` with an explicit allowlist. PHPDoc `@var` shapes.
13. **uninstall.php** removes all `<prefix>_*` options, transients (LIKE cleanup), cron events — **and any custom tables**.
14. **readme.txt** with Stable tag + `== Description ==`, `== Installation ==`, `== FAQ ==`, `== Changelog ==`, `== Upgrade Notice ==`.
15. **`.github/workflows/release.yml`** — the canonical copy; substitute placeholders only. Never write one from scratch or copy from another plugin repo. Security invariant in its header: no `${{ }}` inside a `run:` block — the version comes from the git tag, which can carry shell metacharacters.
16. **Plugin `CLAUDE.md`** at repo root: slug, prefixes, file structure, hook map, settings shape, security baseline, release process.
17. **`## Google Chat Notifications` section in CLAUDE.md** — slug-aware cardsV2 curl that **sources the webhook URL from `~/.claude/memory/` at run time**. The URL is NEVER written into any committed file.

**Verify before declaring scaffold complete:** activates/deactivates cleanly, settings persist, no PHP warnings, zero leftover `{PLACEHOLDER}` tokens, and — for this plugin specifically — the dashboard renders correctly both with zero registered plugins and with several.
