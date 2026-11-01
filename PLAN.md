# PLAN.md — JezPress Manager

**Slug:** `jezpress-manager` · **Class prefix:** `JezPress_Manager` · **Const prefix:** `JEZPRESS_MANAGER_` · **Text domain:** `jezpress-manager`
**Status:** 1.2.0 Phase 3 built and audited (2026-11-01); next step is `/release 1.2.0` (pre-release audit runs inside it). Updated 2026-11-01.

> Dev-only file. Excluded from the release ZIP (`release.yml` excludes `PLAN.md`). Mirrors `.claude/build-playbook.md`; tick items as each phase passes its audit gate.
>
> **No licence layer.** This plugin is free — no licence class, option, cron or key. The licence rows of the standard template are deliberately absent; don't reinstate them. The Manager renders the licence status each plugin reports and links to that plugin's own licence tab. It never holds a key itself.

## The single thing it does
One admin screen that shows every JezPress plugin on the site — version, update available, licence status as reported by the plugin, active toggle, auto-update state — with one clear next step per state, plus a Site info page that puts everything Jezweb support asks for on the clipboard in one click. It is a dashboard over data WordPress and the registered plugins already hold. It does not store licence keys, talk to the licence server, or own any plugin's settings.

## Source design
`JezPress Manager dashboard UX 2.pdf` (third revision, reviewed 2026-11-01). Pages 1–4 are in scope. Page 5 (Licences page) is marked **DEFERRED — DO NOT BUILD IN 1.2.0** in the artifact itself.

## Confirmed decisions (locked)
1. **Two releases, not one.** 1.2.0 = dashboard rebuild on data already available, zero contract change. 1.3.0 = Site info page + additive registration fields. Keeps the first drop fleet-independent and each audit reviewable.
2. **Update actions go through WordPress core.** Update / Update all / auto-update toggle call core's `wp.updates` AJAX actions (`update-plugin`, `toggle-auto-updates`). The Manager adds no server-side handler that modifies files; its one new AJAX action only forces core's re-check (`wp_clean_plugins_cache( true )` + `wp_update_plugins()`) behind a nonce + `update_plugins`. Core enforces the nonce and `update_plugins` on the update actions themselves. Buttons render only for users holding `update_plugins`; hidden entirely under `DISALLOW_FILE_MODS`. **JS calls `wp.updates.ajax( 'update-plugin' )` directly with its own callbacks, and POSTs `toggle-auto-updates` itself with the same nonce** (core's handler returns an empty success payload, which `wp.updates.ajaxAlways()` dereferences — caught in the Playground run) — verified against core's `updates.js` (2026-11-01): `wp.updates.updatePlugin()` / `updatePluginSuccess` / `updatePluginError` dereference Plugins-screen DOM and throw on any other `pagenow`. For the same reason core's filesystem-credentials modal is **not** embedded (its resume path re-enters `updatePlugin()`); on `unable_to_connect_to_filesystem` the row shows the error inline with a link to the Plugins screen and releases core's `ajaxLocked`.
3. **"Security releases only" is dropped.** WordPress has no security-only channel for plugins. Auto-updates card reads "N of N on · Set per plugin".
4. **Licence state allowlist** (`register()` `license_status`): `active`, `expired`, `invalid`, `inactive`, `''` (no key). `expiring` is **derived** (active + parsable expiry within 30 days); when the expiry string does not parse (e.g. `lifetime`) the row stays plain Active. `inactive` is new to the allowlist — today it falls to the default badge. Each state's action is a link to the plugin's own settings/licence page; the Manager never shows a key field.
5. **Additive contract only.** New `register()` keys get a default + sanitiser + README/CLAUDE.md docs. Nothing existing is renamed or repurposed (scaffold-standard #10). Two additive items ship in **1.2.0** because they fix live defects: `plugin_file` (toggle currently builds `slug/slug.php`, which is wrong for Australia Post `jpwap-settings` and Turnstile `jezcft-settings`) and a public `has_plugin( $slug )` (JezPress SEO already calls it behind `method_exists`, so today it always draws its own top-level menu).
6. **Assets move out of inline.** CSS and JS become enqueued files under `assets/`, versioned with `JEZPRESS_MANAGER_VERSION`, nonces passed via `wp_localize_script`. Classes stay `jezpress-` prefixed. **System font stack**, no Google Fonts (privacy; wp-admin must not call out to third parties).
7. **Host name is not shown.** Rocket.net is not reliably detectable from inside WordPress; dropped from header and Site info.
8. **"Check for updates" footnote is honest.** Copy: *"Check for updates asks WordPress to re-check now. Each plugin answers from its own cache, so versions and licence status can be up to 12 hours old."* Becomes fully live only when 1.3.0's `update_transient` field lets the Manager clear each plugin's cache.
9. **Expiry wording is "Expires", not "Renews".** Plugins only pass an expiry date.
10. **Site info reuses core.** `WP_Debug_Data::debug_data()` supplies WordPress / Server / Database / Constants / Theme panes and the plain-text support report. Health checks mirror Site Health's own tests. No hosting pane, no backups check, no activity log (none observable from inside WP without new storage).
11. **Licences page is gated** on the shared licensing library (local `wp-plugin-template` commits, unapproved as of 2026-11-01) being approved and shipped fleet-wide. Agency key maps to the server's all-plugins (`*`) key. Not started before then — see *Deferred*.
12. **Line endings are LF.** `.gitattributes` added 2026-11-01 after an editor converted the working tree to CRLF and made every tracked file appear modified.

## Build phases (playbook-aligned)

### Release 1.2.0 — Dashboard rebuild
- [x] **Phase 0 — Plan & scope.** Design reviewed ×3, fleet impact checked (94 `register()` callers, 150 `JEZPRESS_MANAGER_ACTIVE` checks, menu-slug page-hook users, SEO's `has_plugin`). PLAN.md written. *(no audit)*
- [x] **Phase 3 — Core feature (attack surface).** *(full audit — see Audit-gate note)*
  - [x] Header: site host, "Updates checked N ago" (core `update_plugins->last_checked`), Check for updates (triggers `wp_update_plugins()` via core), Update all (N).
  - [x] Attention banner from the worst reported state; disappears when everything is healthy.
  - [x] Four stat cards: plugins active, updates available, licences (counts per state), auto-updates N of N.
  - [x] Table: icon, name, description, version chip, update chip ("X.Y.Z available" + "What changed" → core plugin-information thickbox), licence badge + action link, toggle (existing handler), auto-update state, Settings, kebab.
  - [x] Search + All / Needs attention filter (client-side).
  - [x] Inline info note under a row with `invalid` status.
  - [x] This site card (WP, PHP, Manager, plugins registered) + Copy (clipboard, client-side) + "See all site info" (links to Site info in 1.3.0; hidden until then).
  - [x] Need a hand + Quick links + footer (existing content, new layout).
  - [x] `register()`: add `plugin_file` (sanitised with `plugin_basename()`, validated with `validate_plugin()` at use time, fallback to `slug/slug.php`); add `inactive` to the status allowlist; add `has_plugin()`.
  - [x] Assets: `assets/css/admin.css`, `assets/js/admin.js`; enqueue core `updates` + thickbox on the Manager hook only (no credentials modal — see decision 2). Column widths live on `th` only so the <782px block layout works.
  - [x] Mobile layout (page 2 of the design).
  - [x] Docs: README.md + CLAUDE.md registration table (`plugin_file`, `inactive`), file structure (assets), hook map.
- [ ] **Phase 6 — Release 1.2.0.** `/release 1.2.0`. Pre-release audit (mandatory) → bump header / const / Stable tag / CLAUDE.md → tag → workflow → per-version changelog → dashboard Description + Changelog tabs → info-endpoint curl → LEDGER row → Google Chat card. *(pre-release audit)*

### Release 1.3.0 — Site info + contract fields
- [ ] **Phase 3 — Core feature.** *(full audit)*
  - [ ] Submenu "Site info" (`manage_options`).
  - [ ] Health strip (8 checks): HTTPS, REST reachable (loopback), object cache, WP-Cron firing (Manager heartbeat), plugin update waiting, `WP_DEBUG`, PHP supported, plugin updates allowed.
  - [ ] Six panes from `WP_Debug_Data` + JezPress pane (Manager, plugins, licences reported, updates waiting, registry schema, last update check).
  - [ ] Copy for support (plain text) + Download report (nonced `admin_post_` handler, `manage_options`, `.txt`).
  - [ ] Contract fields (all optional): `license_url`, `license_expires` (ISO 8601), `update_transient`, `license_last_checked`. "Check for updates" deletes each registered `update_transient` before `wp_update_plugins()`.
  - [ ] Heartbeat: one daily cron event writing a timestamp option; both in `uninstall.php` and the Data footprint table.
  - [ ] Docs + fleet adoption note (each plugin adds the fields in its next ordinary release; no forced fleet release).
- [ ] **Phase 6 — Release 1.3.0.** `/release 1.3.0`. *(pre-release audit)*

### Deferred — Licences page (design page 5)
Blocked until the shared licensing library is approved and shipped fleet-wide. Reasons, so nobody re-litigates them:
- **Agency key / Activate all** — one key per site cannot be written into N separately encrypted, signed `jzwb_lic_*` options; ~68 of 88 fleet classes delete an unsigned row on `admin_init`. Only each plugin's own activate path (or the library) can do it.
- **Masked keys** — the Manager never sees a key.
- **Sites used / Expires** — depend on the `/license/validate` response, which the fleet currently misreads (`license.status` nested). Needs one live check with a real key from Abner.
- **History** — needs a Manager-side event log that does not exist.
- Revisit trigger: [[jezpress-licensing-model-2026-10]] Phase 0 ships + Abner approves the template's licensing library.

## Data footprint
| Kind | Name | Created by | Cleaned by `uninstall.php` |
|---|---|---|---|
| option | `jezpress_manager_version` | activation hook | ✅ explicit delete |
| transient | `jezpress_manager_update_info` | updater cache (12 h) | ✅ explicit delete |
| transient | `jezpress_manager_activation_redirect` | activation hook (30 s) | ✅ explicit delete |
| **1.2.0** | *nothing new* | — | — |
| option (1.3.0) | `jezpress_manager_cron_heartbeat` | heartbeat cron | ⬜ add to `uninstall.php` when created |
| cron (1.3.0) | `jezpress_manager_heartbeat` (daily) | `admin_init` schedule-if-missing | ⬜ `wp_clear_scheduled_hook` in `uninstall.php` + deactivation |
| table | *none* | — | — |

## Acceptance criteria — 1.2.0
| # | Criterion | Verified by |
|---|---|---|
| 1 | Dashboard renders with zero registered plugins and with several (incl. one inactive-detected) with no PHP notices | staging: deactivate all JezPress plugins, reload; `WP_DEBUG_LOG` empty |
| 2 | A plugin passing `expired`, `inactive`, `invalid`, `active`, `''` shows the matching badge and action link; unknown values fall to No key | mu-plugin registering five fake entries |
| 3 | Row toggle deactivates/reactivates Australia Post and Turnstile (slug ≠ folder) once they pass `plugin_file`; still works for slug = folder plugins without it | staging click-through |
| 4 | Toggle rejects a forged `plugin_file` (nonce + `validate_plugin()`), no filesystem access | crafted admin-ajax POST returns 400/403 |
| 5 | Update button installs the pending version via core; failure shows core's error text inline; page reload shows new version | staging with one stale JezPress plugin |
| 6 | Update buttons absent for a user without `update_plugins` and under `DISALLOW_FILE_MODS`; nothing else on the page changes | staging: editor role; define constant |
| 7 | No request leaves wp-admin for fonts or scripts other than the site itself | browser network tab on the Manager page |
| 8 | No fleet plugin changes behaviour: menu slug, submenu URLs, `JEZPRESS_MANAGER_ACTIVE`, `register()` defaults unchanged | `git diff` on the constants/`register()` defaults + staging check of two plugin settings links |
| 9 | Updater still offers the release: `/api/v1/info` reports 1.2.0 and WP shows the banner after transient delete | post-release curl + `wp transient delete jezpress_manager_update_info` |

## Open items / to confirm
- "What changed" relies on each sibling plugin's own updater implementing `plugins_api` for its slug. Confirm on staging which fleet plugins do; rows without it hide the link.
- The exact 30-day threshold for the derived "Expires in N days" state — adjust if Jez wants a different window.
- Live `/license/validate` field check (for the deferred page) needs a real key + exact activated `site_url` from Abner. Not needed for 1.2.0 or 1.3.0.
- Fleet-wide follow-up (separate from this plugin): any plugin registering without a callable `callback` is missing from the Manager menu ([[jezpress-manager-register-needs-callback]]).

## Audit-gate note
**1.2.0 Phase 3 (2026-11-01) — both layers ran, verdict PASS after fixes.**
- Layer 2, `jezpress-code-reviewer` on Opus 5.5 (one round, per the round budget): **PASS WITH FIXES** — H1 `inactive` copy claimed a key exists when ~70 fleet `get_status()` implementations return `inactive` for "no active licence" (fixed: neutral "Not activated" copy, state no longer drives the banner); M1 slug≠folder plugins dropped out of update tracking (fixed: active-plugin Name fallback + "Update status unknown" chip + `untracked` stat suppressing healthy claims); M2 "Up to date" shown for detected-inactive rows (fixed); L1–L6 all applied.
- Layer 1, Codex gpt-6.1-sol round 1: **PASS WITH FIXES** — H-1 core's plugin-information iframe posts `update-plugin` to the parent and core routes it to `wp.updates.updatePlugin()`, which throws on this page (fixed: capture-phase `message` interception → `updateRow()`); M-1 forced-policy item lacked offer fields (fixed: `get_auto_update_item()` mirrors core's list-table payload). Round 2 on the fix diff: **PASS**, one residual Medium (default `id` must be the basename) fixed in the same session.
- Runtime: WordPress Playground (PHP 8.1, WP latest) with an mu-plugin fixture covering every licence state, a bogus status, a traversal `plugin_file`, a detected-inactive plugin and a faked update offer. Toggle on/off, search, Needs-attention filter, auto-update toggle, Update (core error path shown inline, lock released), iframe-message route, Copy, kebab menus, desktop + 390px layouts all verified; console clean; only external request is core's Gravatar. Found and fixed during the run: routing `toggle-auto-updates` through `wp.updates.ajax()` throws inside core's `ajaxAlways()` (empty success payload) and leaves `ajaxLocked` stuck.
- Pre-release audit (Fable) runs inside `/release 1.2.0`, before the version bump.
