# CLAUDE.md - JezPress Manager

## Reusable standards (auto-loaded)

@.claude/build-playbook.md
@.claude/scaffold-standard.md

> Phase-specific docs load on demand: `.claude/updater-contract.md` (updater work), `.claude/audit-guide.md` (every audit gate). The release pipeline is the **`/release` skill** (`.claude/skills/release/`). A committed `jezpress-code-reviewer` subagent lives in `.claude/agents/`, `.claude/LEDGER.md` is the per-version release + audit log, and the repo-root `PLAN.md` carries the current build plan (1.2.0 dashboard rebuild, 1.3.0 Site info + contract fields, Licences page deferred).
>
> **These are the free-plugin variants.** JezPress Manager has no licence layer — no licence class, option, cron or key, and no `license_key` on any request. The `license_status` / `license_expiry` fields in `JezPress_Manager::register()` are display-only values other plugins pass in. Don't reinstate licence code from another plugin's bundle.

## Project Overview

**Plugin Name:** JezPress Manager
**Slug:** `jezpress-manager`
**Version:** 1.3.0
**Type:** WordPress Admin Dashboard Plugin
**Purpose:** Central dashboard for managing all JezPress plugins, licenses, and support
**License Required:** No (free plugin, no license validation)

## JezPress Platform Integration

This plugin uses JezPress for automatic updates from updates.jezpress.com

### Quick Reference

| Service | URL |
|---------|-----|
| Update Server | https://updates.jezpress.com |
| MCP Server | https://mcp.jezpress.com/mcp |
| CLI | npm install -g @jezweb/jezpress-cli |

### Commands

```bash
jezpress login                    # Authenticate with Google
jezpress whoami                   # Check current user
jezpress plugins list --mine      # List your plugins
jezpress plugins get <slug>       # Plugin details
jezpress plugins preflight <slug> <zip>  # Validate before upload
jezpress plugins upload <slug> <zip>     # Upload new version
```

### Version Workflow

1. Update Version in plugin header AND constant
2. Create ZIP: `zip -r jezpress-manager.zip jezpress-manager -x "*.git*" -x "*CLAUDE.md" -x "*PLAN.md"`
3. Validate: `jezpress plugins preflight jezpress-manager ./jezpress-manager.zip`
4. Upload: `jezpress plugins upload jezpress-manager ./jezpress-manager.zip`

## Architecture

### File Structure

```
jezpress-manager/
├── jezpress-manager.php                    # Main plugin bootstrap
├── includes/
│   ├── class-jezpress-manager.php          # Core singleton class (registry, AJAX, dashboard, heartbeat, notice suppression)
│   ├── class-jezpress-manager-site-info.php # Site info page (health checks, panes, support report, download)
│   ├── class-jezpress-manager-updater.php  # JezPress updater class
│   └── index.php
├── assets/
│   ├── css/admin.css                       # Dashboard styles (dashboard hook only)
│   └── js/admin.js                         # Dashboard behaviour (toggles, updates, filter, copy)
├── readme.txt                              # WordPress.org readme
├── README.md                               # GitHub documentation
└── CLAUDE.md                               # This file
```

### Design Pattern

- **Singleton Pattern** - Single instance via `JezPress_Manager::instance()`
- **Static Registry** - Plugins register via `JezPress_Manager::register()`
- **Hook-based Initialization** - Loads on `plugins_loaded` priority 5

### Key Constants

```php
JEZPRESS_MANAGER_VERSION      // Plugin version
JEZPRESS_MANAGER_PLUGIN_FILE  // Main plugin file path
JEZPRESS_MANAGER_PLUGIN_PATH  // Plugin directory path
JEZPRESS_MANAGER_PLUGIN_URL   // Plugin URL
JEZPRESS_MANAGER_ACTIVE       // Boolean true - use for quick availability check
```

### Menu Structure

```
JezPress Manager (menu slug: jezpress-manager)
├── Dashboard (default submenu)
├── Site info (jezpress-manager-site-info, 1.3.0)
└── [Registered plugin submenus]
```

## Code Standards

### WordPress Coding Standards

- PHP 8.2+ required
- WordPress 6.0+ required
- Follow WordPress PHP Coding Standards
- Use proper escaping: `esc_html()`, `esc_url()`, `esc_attr()`
- Use proper sanitization: `sanitize_key()`, `sanitize_text_field()`
- Text domain: `jezpress-manager`

### Security Requirements

- All files must have `defined( 'ABSPATH' ) || exit;`
- Sanitize all input in `register()` method
- Escape all output in templates
- Capability checks on all admin pages (`manage_options`)

## Plugin Registration API

### How Other Plugins Register

```php
add_action( 'plugins_loaded', 'my_plugin_register', 15 );

function my_plugin_register() {
    // Quick check using constant
    if ( ! defined( 'JEZPRESS_MANAGER_ACTIVE' ) ) {
        return;
    }

    JezPress_Manager::register( array(
        'slug'           => 'my-plugin-settings',           // Required
        'name'           => 'My Plugin',                    // Required
        'version'        => '1.0.0',                        // Required
        'menu_title'     => 'My Plugin',                    // Required
        'capability'     => 'manage_options',               // Optional (default: manage_options)
        'callback'       => 'render_function',              // Optional
        'license_status' => 'active',                       // Optional: active|expired|invalid|inactive|''
        'license_expiry' => '2025-12-31',                   // Optional: strtotime()-parsable
        'plugin_file'    => 'my-plugin/my-plugin.php',      // Optional (1.2.0): basename when slug != folder
        'license_url'    => admin_url( 'admin.php?page=my-plugin&tab=license' ), // Optional (1.3.0): licence action link target
        'license_expires' => '2026-12-31',                  // Optional (1.3.0): ISO 8601; wins over license_expiry
        'update_transient' => 'my_plugin_update_info',      // Optional (1.3.0): deleted by "Check for updates"
        'license_last_checked' => 1767139200,               // Optional (1.3.0): Unix time or strtotime() string
        'description'    => 'Plugin description',           // Optional
        'icon'           => 'dashicons-printer',            // Optional (default: dashicons-admin-plugins)
        'position'       => 10,                             // Optional (default: 10, lower = higher in menu)
    ) );
}
```

### Deregister a Plugin

```php
JezPress_Manager::deregister( 'my-plugin-slug' );
```

### Registration Priority

- JezPress Manager loads at priority **5**
- Other plugins should register at priority **15** or later
- This ensures the manager class is available

### License Status Values

Allowlisted in `JezPress_Manager::LICENSE_STATUSES`; anything else is coerced to `''`. The Manager renders what the plugin reports and links to the plugin's own settings page. It never stores or validates a key.

| Value | Display | Next step link |
|-------|---------|----------------|
| `active` | Green "Active" + expiry (past expiry → Expired) | none |
| `active`, expiry within `EXPIRING_DAYS` (30) | Amber "Expires in N days" | Renew in settings |
| `expired` | Amber "Expired" | Renew in settings |
| `inactive` | Amber "Not activated" (fleet licence classes report this whenever there is no active licence, keyed or not; never drives the banner) | Activate in settings |
| `invalid` | Red "Invalid key" + inline note; drives the attention banner | Fix in settings |
| `''` | "No key" | Add key in settings |

`JezPress_Manager::has_plugin( $slug )` (1.2.0) returns whether a slug is registered.

### Schema 2 fields (1.3.0)

`license_url` (absolute URL, `esc_url_raw`), `license_expires` (ISO 8601, regex + `strtotime`), `update_transient` (`[A-Za-z0-9_-]{1,172}`), `license_last_checked` (int or `strtotime()` string, rejected if before 2000 or over a day in the future). The Manager adds `supplied` (which optional keys were passed) and `schema` (1 or `REGISTRY_SCHEMA` = 2) to each registry entry. `license_url` is a plugin-supplied href; `esc_url_raw` on input, `esc_url` on output.

## Site info page (1.3.0)

`JezPress_Manager_Site_Info` (`includes/class-jezpress-manager-site-info.php`), submenu `jezpress-manager-site-info`, `manage_options`. The health strip, the five WordPress panes and the report additionally need `view_site_health_checks` (Site Health's rule: `install_plugins`, so super admins on multisite); without it only the JezPress pane renders and no loopback runs.

- **Health checks** (`get_health_checks()`): HTTPS (`wp_is_using_https`), REST (`WP_Site_Health::get_test_rest_availability()`, a 10 s loopback), object cache (`wp_using_ext_object_cache`), WP-Cron (the heartbeat option, stale after 36 h), updates waiting (dashboard stats + core transient), `WP_DEBUG`, PHP (`wp_check_php_version()`, cached a week by core), file mods (`wp_is_file_mod_allowed( 'capability_update_core' )`). Statuses `ok | info | warn | bad`; `ok` + `info` count as passed.
- **Panes** (`get_panes()`): values come from `WP_Debug_Data::debug_data()` with direct fallbacks. `debug_data()` is computed live like Site Health's Info tab (it includes core's wordpress.org probe), so it is only called on this page and in the download handler.
- **Report** (`build_report()`): JezPress sections first, then the core sections in `REPORT_SECTIONS`, all through `WP_Debug_Data::format( $sections, 'debug' )`, which skips `private` fields. Copy uses the dashboard's `#jezpress-copy-site` / `#jezpress-site-report` JS; Download is `admin_post_jezpress_manager_site_report` (`check_admin_referer`, `text/plain`, `nosniff`).
- **Heartbeat**: `jezpress_manager_heartbeat` daily event scheduled on activation and on `admin_init` if missing; the callback writes `jezpress_manager_cron_heartbeat` (autoload off). Cleared on deactivation and in `uninstall.php`.
- **Notice suppression**: `suppress_foreign_notices()` on `in_admin_header`, Manager screens only. Reflection resolves each `admin_notices` / `all_admin_notices` / `network_admin_notices` / `user_admin_notices` callback to its file; callbacks from `wp-admin/`, `wp-includes/` and this plugin stay, every other plugin's are removed with `remove_action()`. Internal functions (no file) are left alone.

## Development Guidelines

### Adding New Features

1. Keep the plugin lightweight - it's a dashboard, not a framework
2. New features should benefit all JezPress plugins
3. Maintain backward compatibility with existing registrations

### Modifying the Dashboard

- Data: `get_dashboard_data()` → `build_row()` / `get_license_state()`. Update availability comes from core's `update_plugins` transient, version-compared; auto-update state from the `auto_update_plugins` site option.
- Markup: `render_dashboard()`, `render_banner()`, `render_row()`. Escape at output.
- Styles: `assets/css/admin.css` (all selectors `.jezpress-` prefixed, system font stack, no external requests). Menu icon CSS stays inline via `get_menu_icon_styles()` because it loads on every admin page.
- Behaviour: `assets/js/admin.js`. Update / Update all call core's `wp.updates.ajax( 'update-plugin' )` with the Manager's own callbacks — never `wp.updates.updatePlugin()`, which assumes the Plugins-screen DOM and throws on any other `pagenow`. The auto-update toggle POSTs core's `toggle-auto-updates` action directly with the same `updates` nonce (as core's Plugins screen does): that handler returns an empty success payload and `wp.updates.ajaxAlways()` dereferences it, so sending it through `wp.updates.ajax()` throws and leaves `ajaxLocked` stuck. The server side for both is core's own handlers (`updates` nonce, `update_plugins` capability).
- Capabilities: page = `manage_options`; update buttons render only for `update_plugins` (false under `DISALLOW_FILE_MODS`); toggle = `activate_plugins`.

### Adding New Registration Parameters

1. Add default value in `register()` method defaults array
2. Add sanitization for the new parameter
3. Update documentation in README.md and CLAUDE.md
4. Update PHPDoc in the `register()` method

## Testing Checklist

### Before Release

- [ ] Plugin activates without errors
- [ ] Dashboard displays correctly with no plugins registered
- [ ] Dashboard displays correctly with plugins registered
- [ ] All external links work and open in new tab
- [ ] License badges display correctly for all statuses
- [ ] Settings buttons link to correct submenu pages
- [ ] Responsive layout works on smaller screens
- [ ] No PHP warnings or notices
- [ ] `expired`, `inactive`, `invalid`, `active`, `''` each render the right badge + link; unknown values fall to No key
- [ ] Toggle works for a plugin whose slug ≠ folder once it passes `plugin_file`
- [ ] Update button installs a pending version via core; failure text shows inline
- [ ] Update buttons absent for a user without `update_plugins` and under `DISALLOW_FILE_MODS`
- [ ] No request leaves wp-admin for fonts or scripts (browser network tab)

### Registration Testing

- [ ] Plugins can register with minimal parameters
- [ ] Plugins can register with all parameters
- [ ] Invalid/empty slugs are rejected
- [ ] Sanitization works correctly on all fields
- [ ] Callbacks are validated before adding submenu

## Common Tasks

### Change Support Email

Edit `render_dashboard()` in `class-jezpress-manager.php`:
```php
<a href="mailto:jez@jezweb.net">jez@jezweb.net</a>
```

### Change Menu Icon

Edit `add_admin_menu()` in `class-jezpress-manager.php`:
```php
'dashicons-superhero-alt', // Change to any dashicon
```

### Change Menu Position

Edit `add_admin_menu()` in `class-jezpress-manager.php`:
```php
58 // Menu position (after Appearance=60)
```

### Add New Quick Link

Add to the quick links section in `render_dashboard()`:
```php
<li>
    <a href="https://example.com" target="_blank" rel="noopener">
        <span class="dashicons dashicons-icon-name"></span>
        <?php esc_html_e( 'Link Text', 'jezpress-manager' ); ?>
    </a>
</li>
```

## Dependencies

- None (standalone plugin)
- Optional: Other JezPress plugins that register with it

## ZIP Structure (Critical)

```
jezpress-manager.zip
└── jezpress-manager/                       ← Folder MUST match slug
    ├── jezpress-manager.php                ← Main file MUST be slug.php
    ├── includes/
    │   ├── class-jezpress-manager.php
    │   └── class-jezpress-manager-updater.php
    ├── readme.txt
    ├── README.md
    └── uninstall.php
```

## Google Chat Notifications

When asked to "send release to Google Chat" or "notify Google Chat about release", use the **Jezweb Releases** webhook. The URL (with API key + space token) is a secret — **never commit it to this or any repo file**. Read it at run-time from the git-ignored memory file:

```bash
WEBHOOK_URL="$(grep -oP 'https://chat\.googleapis\.com/\S+' ~/.claude/memory/jezpress-google-chat-webhook.md | head -n1)"
```

**Per-release approval required:** even with the URL in hand, do NOT post a release card without explicit per-release approval from Abner.

### How to Send Release Notification

1. Extract changelog for the version from `readme.txt`
2. Load `WEBHOOK_URL` using the one-liner above
3. Send a POST request with a formatted card:

```bash
curl -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -d '{
    "cardsV2": [{
      "cardId": "release-VERSION",
      "card": {
        "header": {
          "title": "JezPress Manager",
          "subtitle": "Version VERSION Released"
        },
        "sections": [{
          "header": "What'\''s New",
          "widgets": [{
            "textParagraph": {
              "text": "CHANGELOG_CONTENT"
            }
          }]
        }, {
          "widgets": [{
            "buttonList": {
              "buttons": [{
                "text": "View Plugin",
                "onClick": {
                  "openLink": {
                    "url": "https://admin.jezpress.com/dashboard/plugins/jezpress-manager"
                  }
                }
              }, {
                "text": "Download Latest",
                "onClick": {
                  "openLink": {
                    "url": "https://admin.jezpress.com/api/update-server/plugins/jezpress-manager/download?version=VERSION"
                  }
                }
              }]
            }
          }]
        }]
      }
    }]
  }'
```

## Hooks Reference

### Actions Used

| Hook | Priority | Purpose |
|------|----------|---------|
| `plugins_loaded` | 5 | Initialize plugin |
| `admin_menu` | 9 | Register admin menus |
| `admin_enqueue_scripts` | 10 | Menu icon CSS everywhere; dashboard CSS/JS + core `updates` + thickbox on `toplevel_page_jezpress-manager` only |
| `admin_init` | 10 | Handle activation redirect |
| `wp_ajax_jezpress_manager_toggle_plugin` | 10 | Activate/deactivate (nonce `jezpress_toggle_nonce`, `activate_plugins`, `plugin_basename()` + `validate_plugin()`) |
| `wp_ajax_jezpress_manager_check_updates` | 10 | Deletes the Manager's own update transient and every registered `update_transient`, then forces core's re-check: `wp_clean_plugins_cache( true )` + `wp_update_plugins()` (nonce `jezpress_check_updates_nonce`, `update_plugins`). Modifies no files. |
| `admin_init` | 10 | Schedule the daily heartbeat if missing (1.3.0) |
| `jezpress_manager_heartbeat` | 10 | Write the heartbeat timestamp option (1.3.0) |
| `in_admin_header` | 10 | Drop other plugins' admin notices on the Manager's screens (1.3.0) |
| `admin_post_jezpress_manager_site_report` | 10 | Site info report download (1.3.0) |

### Activation/Deactivation

- `register_activation_hook` - Sets version option and redirect transient
- `register_deactivation_hook` - Clears the heartbeat cron event

## Troubleshooting

### Plugin Not Appearing in Menu

1. Check if `JezPress_Manager` class exists before registering
2. Verify registration happens after priority 5
3. Ensure slug is not empty after sanitization
4. Check capability requirements

### Styles Not Loading

1. Verify the page hook is exactly `toplevel_page_jezpress-manager` or the Site info hook (`jezpress-manager_page_jezpress-manager-site-info`); the dashboard CSS/JS load on those two only, the menu icon CSS loads everywhere
2. Check browser console for CSS errors
3. Clear any caching plugins

### Activation Redirect Not Working

1. Transient may have expired (30 second window)
2. Multi-plugin activation bypasses redirect
3. Check for redirect conflicts with other plugins
