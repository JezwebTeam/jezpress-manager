=== JezPress Manager ===
Contributors: jezpress
Tags: jezpress, dashboard, plugin management, license management
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Central dashboard for managing all JezPress plugins, licenses, and support.

== Description ==

JezPress Manager provides a unified dashboard for all your JezPress plugins. Instead of navigating to different settings pages scattered throughout the WordPress admin, access all your JezPress plugins from one convenient location.

= Features =

* **Centralized Dashboard** - View all installed JezPress plugins in one place
* **License Management** - Monitor license status for all your plugins at a glance
* **Quick Support Access** - Easy access to documentation, support, and your account
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
* AJAX-powered toggle — no page reload needed
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
