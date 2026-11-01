# JezPress Manager

Central dashboard for managing all JezPress WordPress plugins, licenses, and support.

## Description

JezPress Manager provides a unified admin dashboard for all JezPress plugins. Instead of navigating to different settings pages scattered throughout the WordPress admin, access all your JezPress plugins from one convenient location.

### Features

- **Centralized Dashboard** - View all installed JezPress plugins in one place
- **License Management** - Monitor license status for all your plugins at a glance
- **Quick Support Access** - Easy access to documentation, support, and your account
- **Clean Interface** - Modern, responsive design that integrates with WordPress admin

## Requirements

- WordPress 6.0 or higher
- PHP 8.2 or higher

## Installation

1. Download the plugin zip file or clone this repository
2. Upload to `/wp-content/plugins/jezpress-manager/`
3. Activate the plugin through the WordPress Plugins menu
4. Access JezPress Manager from the admin sidebar

## Plugin Integration

Other JezPress plugins can register themselves with the manager using a simple API.

### Basic Registration

Add this code to your JezPress plugin:

```php
add_action( 'plugins_loaded', 'my_plugin_register_with_manager', 15 );

function my_plugin_register_with_manager() {
    // Only register if JezPress Manager is active
    if ( ! class_exists( 'JezPress_Manager' ) ) {
        return;
    }

    JezPress_Manager::register( array(
        'slug'       => 'my-plugin-settings',
        'name'       => 'My Plugin Name',
        'version'    => '1.0.0',
        'menu_title' => 'My Plugin',
        'capability' => 'manage_options',
        'callback'   => array( 'My_Plugin_Admin', 'render_settings_page' ),
    ) );
}
```

### Registration with License Status

```php
add_action( 'plugins_loaded', 'my_plugin_register_with_manager', 15 );

function my_plugin_register_with_manager() {
    if ( ! class_exists( 'JezPress_Manager' ) ) {
        return;
    }

    // Get license status from your license handler
    $license_status = '';
    $license_expiry = '';

    if ( class_exists( 'My_Plugin_License' ) ) {
        $license = My_Plugin_License::instance();
        $license_status = $license->is_valid() ? 'active' : 'invalid';
        $license_expiry = $license->get_expiry_date(); // e.g., '2025-12-31'
    }

    JezPress_Manager::register( array(
        'slug'           => 'my-plugin-settings',
        'name'           => 'My Plugin Name',
        'version'        => MY_PLUGIN_VERSION,
        'menu_title'     => 'My Plugin',
        'capability'     => 'manage_options',
        'callback'       => array( 'My_Plugin_Admin', 'render_settings_page' ),
        'license_status' => $license_status,
        'license_expiry' => $license_expiry,
        'description'    => 'Short description of what the plugin does.',
    ) );
}
```

### Registration Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | string | Yes | Unique identifier for the plugin (used in menu URL) |
| `name` | string | Yes | Full plugin name displayed in dashboard |
| `version` | string | Yes | Plugin version number |
| `menu_title` | string | Yes | Short title for the submenu |
| `capability` | string | No | Required capability (default: `manage_options`) |
| `callback` | callable | No | Settings page render callback |
| `license_status` | string | No | `active`, `expired`, `invalid`, `inactive`, or empty (no key). Anything else is treated as empty. |
| `license_expiry` | string | No | Expiry date, any `strtotime()`-parsable string (e.g. `2026-12-31`). Drives the "Expires in N days" state. |
| `description` | string | No | Short plugin description |
| `icon` | string | No | Dashicon class (default: `dashicons-admin-plugins`) |
| `position` | int | No | Menu order - lower number appears first (default: `10`) |
| `plugin_file` | string | No | Plugin basename, e.g. `my-plugin/my-plugin.php`. Lets the Manager find the plugin when the slug is not the folder name (since 1.2.0) |
| `license_url` | string | No | Absolute URL of the plugin's own licence page or tab, e.g. `admin_url( 'admin.php?page=my-plugin&tab=license' )`. Licence action links ("Renew in settings", "Activate in settings") go here instead of the settings page (since 1.3.0) |
| `license_expires` | string | No | Expiry as ISO 8601: `2026-12-31` or `2026-12-31T23:59:59+10:00`. Wins over `license_expiry` when valid; anything else is ignored (since 1.3.0) |
| `update_transient` | string | No | Name of the transient your updater caches the update-server reply in (the one you pass to `get_transient()`). "Check for updates" deletes it before WordPress re-checks, so the row goes live instead of waiting up to 12 hours (since 1.3.0) |
| `license_last_checked` | int\|string | No | When the plugin last validated its licence: Unix timestamp or `strtotime()`-parsable string. Shown on hover of the licence badge and in the support report (since 1.3.0) |

All four 1.3.0 keys are optional and additive. A plugin passing any of them is recorded as registry schema 2; the Site info page reports how many registered plugins are on it. Add them in the plugin's next ordinary release; no fleet-wide release is needed.

### License Status Values

The Manager renders the status each plugin reports and links to that plugin's own settings page. It never stores or validates a key itself.

| Value | Display | Next step shown |
|-------|---------|-----------------|
| `active` | Green "Active" + expiry date (a past expiry date renders as Expired) | None |
| `active` with expiry within 30 days | Amber "Expires in N days" | Renew in settings |
| `expired` | Amber "Expired" | Renew in settings |
| `inactive` | Amber "Not activated" (no active licence; template-built plugins report this whether or not a key was entered) | Activate in settings |
| `invalid` | Red "Invalid key" + inline note | Fix in settings |
| (empty) | "No key" | Add key in settings |

## Site Info

**JezPress Manager → Site info** (`manage_options`) is the page Jezweb support asks for:

- **Health strip** (8 checks): HTTPS, REST API reachable (Site Health's loopback test), persistent object cache, WP-Cron firing (measured by the Manager's own daily heartbeat event), plugin updates waiting, `WP_DEBUG`, PHP support status (wordpress.org's table, cached by core), plugin updates allowed (`DISALLOW_FILE_MODS` / `file_mod_allowed`).
- **Six panes**: WordPress, Server, Database, Constants, Theme and plugins (from `WP_Debug_Data`), plus a JezPress pane (Manager version, plugins, licences, updates waiting, registry schema, last update check, heartbeat).
- **Copy for support** puts the whole plain-text report on the clipboard; **Download report** saves it as `.txt` through a nonced `admin_post` handler. Both use `WP_Debug_Data::format()`, which leaves out the fields core marks private (database credentials, server paths), exactly like Site Health's copy button.

The health strip, the WordPress/Server/Database/Constants/Theme panes and the report need `view_site_health_checks` (`install_plugins`; super admins on multisite), the same rule as Site Health. Without it the page shows the JezPress pane only.

## Menu Structure

Once activated, you'll see:

```
JezPress Manager
├── Dashboard       ← Shows all plugins + licenses + support info
├── Plugin A        ← First registered plugin's settings
├── Plugin B        ← Second registered plugin's settings
└── ...
```

## Helper Functions

### Check if Manager is Active

```php
// Method 1: Using constant (fastest)
if ( defined( 'JEZPRESS_MANAGER_ACTIVE' ) && JEZPRESS_MANAGER_ACTIVE ) {
    // Manager is available
}

// Method 2: Using class check
if ( class_exists( 'JezPress_Manager' ) ) {
    // Manager is available
}
```

### Get All Registered Plugins

```php
$plugins = JezPress_Manager::get_plugins();
```

### Check if a Slug is Registered

```php
if ( JezPress_Manager::has_plugin( 'my-plugin-slug' ) ) {
    // The Manager owns this plugin's menu entry.
}
```

### Deregister a Plugin

```php
JezPress_Manager::deregister( 'my-plugin-slug' );
```

## Hooks

### Actions

| Hook | Purpose |
|------|---------|
| `jezpress_manager_heartbeat` | Daily WP-Cron event. Writes the `jezpress_manager_cron_heartbeat` option (Unix time) so Site info can tell whether WP-Cron fires. Cleared on deactivation and uninstall. |
| `admin_post_jezpress_manager_site_report` | Site info "Download report" (nonce `jezpress_manager_site_report`, `manage_options` + `view_site_health_checks`). |

### Filters

None currently.

## Changelog

### 1.3.0
- Site info page: 8-check health strip, six info panes, Copy for support and Download report
- `register()` accepts `license_url`, `license_expires`, `update_transient` and `license_last_checked` (all optional)
- Check for updates clears each registered plugin's update cache before WordPress re-checks
- Daily heartbeat cron event backing the WP-Cron check
- Dashboard uses wide screens (1800px) and hides other plugins' admin notices on the Manager's own screens

### 1.2.0
- Redesigned dashboard: header with Check for updates and Update all, attention banner, stat cards, search, Needs attention filter, mobile layout
- Update a plugin, update all, and toggle per-plugin auto-updates from the dashboard (via WordPress core's own update handlers and permissions)
- Licence states: Expired, Inactive and a derived "Expires in N days", each linking to the plugin's own settings page
- "This site" card with one-click Copy of site details for support requests
- `register()` accepts an optional `plugin_file`; new `has_plugin()` helper
- Dashboard CSS/JS moved to enqueued files under `assets/`

### 1.1.0
- Added JezPress updater for automatic updates from updates.jezpress.com
- Plugin now receives automatic updates through the JezPress platform

### 1.0.0
- Initial release
- Central dashboard for JezPress plugins
- License status display
- Support and quick links section
- Plugin registration API

## License

GPL-2.0+ - See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

## Support

- **Email:** jez@jezpress.com
