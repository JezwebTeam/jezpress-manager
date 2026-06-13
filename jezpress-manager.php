<?php
/**
 * Plugin Name: JezPress Manager
 * Plugin URI: https://jezpress.com
 * Description: Central dashboard for managing all JezPress plugins, licenses, and support.
 * Version: 1.1.4
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
define( 'JEZPRESS_MANAGER_VERSION', '1.1.4' );
define( 'JEZPRESS_MANAGER_PLUGIN_FILE', __FILE__ );
define( 'JEZPRESS_MANAGER_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'JEZPRESS_MANAGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JEZPRESS_MANAGER_ACTIVE', true ); // Easy check for other plugins

/**
 * Load the main class
 */
require_once JEZPRESS_MANAGER_PLUGIN_PATH . 'includes/class-jezpress-manager.php';

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
}
register_activation_hook( __FILE__, 'jezpress_manager_activate' );

/**
 * Deactivation hook
 */
function jezpress_manager_deactivate() {
	// Cleanup if needed.
}
register_deactivation_hook( __FILE__, 'jezpress_manager_deactivate' );
