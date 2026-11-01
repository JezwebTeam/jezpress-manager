=== JezPress Manager ===
Contributors: jezpress
Tags: jezpress, dashboard, plugin management, license management
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Central dashboard for managing all JezPress plugins, licenses, and support.

== Description ==

JezPress Manager provides a unified dashboard for all your JezPress plugins. Instead of navigating to different settings pages scattered throughout the WordPress admin, access all your JezPress plugins from one convenient location.

= Features =

* **Centralized Dashboard** - View all installed JezPress plugins in one place, with search and a Needs attention filter
* **Updates From One Screen** - See which plugins have updates, update one or all, and switch per-plugin auto-updates, all through WordPress core's own update handlers
* **Licence Status At A Glance** - Each plugin reports its own licence state (active, expiring, expired, not activated, invalid, no key) with one clear next step; the Manager never stores a key
* **Support In One Click** - Copy site details for a support request, plus direct contact and quick links
* **Site Info** - Health checks (HTTPS, REST API, object cache, WP-Cron, updates, WP_DEBUG, PHP, file modifications), WordPress / server / database / constants / theme panes, and a plain-text support report to copy or download
* **Clean Interface** - Modern, responsive design that integrates with WordPress admin

= For Plugin Developers =

Other JezPress plugins can register themselves with the manager using a simple API:

`
JezPress_Manager::register( array(
    'slug'           => 'my-plugin-settings',
    'name'           => 'My Plugin Name',
    'version'        => '1.0.0',
    'menu_title'     => 'My Plugin',
    'capability'     => 'manage_options',
    'callback'       => array( 'My_Plugin_Admin', 'render_settings' ),
    'license_status' => 'active', // 'active', 'expired', 'invalid', or ''
) );
`

== Installation ==

1. Upload the `jezpress-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Access the JezPress Manager from the admin menu

== Frequently Asked Questions ==

= Do I need this plugin? =

If you use multiple JezPress plugins, this manager provides a convenient central location to access all of them. If you only use one JezPress plugin, it's optional but still provides a cleaner admin experience.

= Will my plugins still work without this? =

Yes. JezPress plugins are designed to work independently. The manager simply provides a unified interface.

== Changelog ==

= 1.3.0 =
* New: Site info page - eight health checks (HTTPS, REST API reachable, object cache, WP-Cron firing, plugin updates waiting, WP_DEBUG, PHP support status, plugin updates allowed), WordPress / Server / Database / Constants / Theme and plugins panes, and a JezPress pane (Manager version, plugins, licences reported, updates waiting, registry schema, last update check, cron heartbeat)
* New: Copy for support puts the whole plain-text report on the clipboard; Download report saves it as a .txt file. Both leave out the fields Site Health marks private, exactly like Site Health's own copy button
* New: register() accepts four optional keys - license_url (target of the licence action links), license_expires (ISO 8601, takes precedence over license_expiry), update_transient (cleared by Check for updates so the plugin re-checks at once) and license_last_checked (shown on hover of the licence badge and in the support report)
* New: Check for updates now clears the Manager's own update cache and every registered plugin's update transient before WordPress re-checks
* New: A daily heartbeat cron event records when WP-Cron last ran, backing the Site info WP-Cron check; removed on deactivation and uninstall, per site on multisite
* Changed: The dashboard uses wide screens (layout cap raised from 1400px to 1800px)
* Changed: Other plugins' admin notices are hidden on the Manager's own screens; the row badges, Licences card and Needs attention filter already report the same licence states
* Changed: The "This site" card links to the full Site info page

= 1.2.0 =
* New: Redesigned dashboard - header with Check for updates and Update all, attention banner, stat cards (plugins active, updates available, licences, auto-updates), search, a Needs attention filter and a mobile layout
* New: Update a plugin, update all, and switch per-plugin auto-updates from the dashboard; actions run through WordPress core's own update handlers and permissions
* New: Licence states now include Expired, Not activated and a derived "Expires in N days", each linking to the plugin's own settings page; the Manager still never stores or validates a key
* New: "This site" card with a one-click Copy of site details for support requests
* New: register() accepts an optional plugin_file (plugin basename) so the active/inactive toggle works for plugins whose slug differs from their folder name
* New: JezPress_Manager::has_plugin( $slug ) helper
* Changed: Dashboard CSS and JavaScript moved from inline output to enqueued files under assets/, loaded on the Manager dashboard only
* Fixed: Plugins reporting an expired or inactive licence no longer render as "Not Set"
* Fixed: Plugins whose slug differs from their folder are matched by plugin_file or by name, so their update state and toggle work
* Fixed: A licence expiring today no longer shows as Expired; days left are counted from the start of today in the site timezone
* Fixed: Check for updates reports an error when WordPress cannot reach the update server instead of claiming no updates are available
* Fixed: The "What changed" link is hidden for users who cannot open the plugin details window (no install_plugins capability)
* Fixed: Network-activated plugins are no longer listed as inactive on multisite

= 1.1.4 =
* Security: Hardened the dashboard plugin activate/deactivate AJAX handler against path traversal - the plugin_file parameter is now normalised with plugin_basename() and validated with validate_plugin() before any filesystem access.

= 1.1.3 =
* Changed: Minimum PHP requirement lowered from 8.2 to 8.1 for broader compatibility

= 1.1.2 =
* Fixed: Updater now uses correct JezPress API endpoint format (/api/v1/)
* Fixed: Proper field mapping for requires_wp and tested_wp from API response

= 1.1.1 =
* Detect inactive JezPress plugins and show them in the dashboard alongside active ones
* Add a Status column with a CSS toggle switch to activate/deactivate plugins directly from the Manager dashboard
* AJAX-powered toggle - no page reload needed
* Security audit completed - no vulnerabilities found

= 1.1.0 =
* Added JezPress updater for automatic updates from updates.jezpress.com
* Plugin now receives automatic updates through the JezPress platform

= 1.0.0 =
* Initial release
* Central dashboard for JezPress plugins
* License status display
* Support and quick links section

== Upgrade Notice ==

= 1.3.0 =
Adds the Site info page (health checks, server and WordPress details, Copy for support and Download report) and four optional register() keys. No settings change; the registration API is backward compatible.

= 1.2.0 =
Redesigned dashboard with update actions, richer licence states and a Copy site details button. No settings change; the registration API is backward compatible.

= 1.1.4 =
Security hardening for the dashboard plugin activate/deactivate control. Recommended update.

= 1.1.3 =
Now supports PHP 8.1 for broader server compatibility.

= 1.1.2 =
Critical fix for automatic updates - updater now connects to correct API endpoint.

= 1.1.1 =
Detect inactive JezPress plugins and toggle activation directly from the dashboard.

= 1.1.0 =
Added automatic updates from the JezPress platform.

= 1.0.0 =
Initial release of JezPress Manager.
