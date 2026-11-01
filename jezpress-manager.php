<?php
/**
 * Plugin Name: JezPress Manager
 * Plugin URI: https://jezpress.com
 * Description: Central dashboard for managing all JezPress plugins, licenses, and support.
 * Version: 1.3.0
 * Author: JezPress
 * Author URI: https://jezpress.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jezpress-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package JezPress_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants
 */
define( 'JEZPRESS_MANAGER_VERSION', '1.3.0' );
define( 'JEZPRESS_MANAGER_PLUGIN_FILE', __FILE__ );
define( 'JEZPRESS_MANAGER_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'JEZPRESS_MANAGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JEZPRESS_MANAGER_ACTIVE', true ); // Easy check for other plugins

/**
 * Load the main class
 */
require_once JEZPRESS_MANAGER_PLUGIN_PATH . 'includes/class-jezpress-manager.php';

/**
 * Load the Site info page class
 */
require_once JEZPRESS_MANAGER_PLUGIN_PATH . 'includes/class-jezpress-manager-site-info.php';

/**
 * Load the updater class
 */
require_once JEZPRESS_MANAGER_PLUGIN_PATH . 'includes/class-jezpress-manager-updater.php';

/**
 * Initialize the plugin
 *
 * @return JezPress_Manager
 */
function jezpress_manager_init() {
	// Initialize updater.
	new JezPress_Manager_Updater( JEZPRESS_MANAGER_PLUGIN_FILE );

	return JezPress_Manager::instance();
}
add_action( 'plugins_loaded', 'jezpress_manager_init', 5 );

/**
 * Activation hook
 */
function jezpress_manager_activate() {
	// Set default options if needed.
	if ( false === get_option( 'jezpress_manager_version' ) ) {
		add_option( 'jezpress_manager_version', JEZPRESS_MANAGER_VERSION );
	}

	// Set transient for activation redirect.
	set_transient( 'jezpress_manager_activation_redirect', true, 30 );

	// Daily heartbeat (Site info WP-Cron check). Also re-scheduled on admin_init if missing.
	if ( ! wp_next_scheduled( JezPress_Manager::HEARTBEAT_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', JezPress_Manager::HEARTBEAT_HOOK );
	}
}
register_activation_hook( __FILE__, 'jezpress_manager_activate' );

/**
 * Deactivation hook
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 */
function jezpress_manager_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		// Every subsite that scheduled a heartbeat (admin_init) owns its own cron option.
		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				wp_clear_scheduled_hook( JezPress_Manager::HEARTBEAT_HOOK );
				restore_current_blog();
			}
			$offset += 100;
			$found   = count( $site_ids );
		} while ( 100 === $found );
		return;
	}

	wp_clear_scheduled_hook( JezPress_Manager::HEARTBEAT_HOOK );
}
register_deactivation_hook( __FILE__, 'jezpress_manager_deactivate' );
