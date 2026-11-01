<?php
/**
 * Uninstall script for JezPress Manager
 *
 * Fired when the plugin is uninstalled.
 *
 * @package JezPress_Manager
 * @since   1.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything the plugin stored on the current site
 *
 * @since 1.3.0 (extracted so multisite can run it per site)
 */
function jezpress_manager_uninstall_site() {
	// Delete plugin version option.
	delete_option( 'jezpress_manager_version' );

	// Delete activation redirect transient (in case it exists during uninstall).
	delete_transient( 'jezpress_manager_activation_redirect' );

	// Delete update cache transient.
	delete_transient( 'jezpress_manager_update_info' );

	// Heartbeat (since 1.3.0): the cron event and the timestamp it writes.
	wp_clear_scheduled_hook( 'jezpress_manager_heartbeat' );
	delete_option( 'jezpress_manager_cron_heartbeat' );
}

if ( is_multisite() ) {
	// Options and cron are per site; uninstall.php runs once, so visit each site (in pages).
	$jezpress_manager_offset = 0;
	do {
		$jezpress_manager_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $jezpress_manager_offset,
			)
		);
		foreach ( $jezpress_manager_site_ids as $jezpress_manager_site_id ) {
			switch_to_blog( $jezpress_manager_site_id );
			jezpress_manager_uninstall_site();
			restore_current_blog();
		}
		$jezpress_manager_offset += 100;
		$jezpress_manager_found   = count( $jezpress_manager_site_ids );
	} while ( 100 === $jezpress_manager_found );
} else {
	jezpress_manager_uninstall_site();
}

// Note: Registered plugins are stored in static memory only, no cleanup needed.
