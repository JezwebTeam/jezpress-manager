<?php
/**
 * JezPress Manager Site info page
 *
 * Health strip, six info panes and the plain-text support report. Everything
 * here reads data WordPress already holds (WP_Debug_Data, the update
 * transient, the Manager registry). Nothing is stored except the heartbeat
 * timestamp the daily cron event writes (see JezPress_Manager::heartbeat()).
 *
 * @package JezPress_Manager
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site info page
 */
class JezPress_Manager_Site_Info {

	/**
	 * Submenu page slug
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'jezpress-manager-site-info';

	/**
	 * Name of the admin_post action (and nonce action) for the report download
	 *
	 * @var string
	 */
	const DOWNLOAD_ACTION = 'jezpress_manager_site_report';

	/**
	 * Seconds after which the last heartbeat counts as stale (36 hours for a daily event)
	 *
	 * @var int
	 */
	const HEARTBEAT_MAX_AGE = 129600;

	/**
	 * Number of checks in the health strip
	 *
	 * @var int
	 */
	const CHECK_COUNT = 8;

	/**
	 * Core debug sections included in the support report, in order
	 *
	 * Skipped: wp-paths-sizes (its values are "Loading…" placeholders outside
	 * Site Health's AJAX), wp-media and wp-filesystem (not support-relevant).
	 *
	 * @var string[]
	 */
	const REPORT_SECTIONS = array(
		'wp-core',
		'wp-active-theme',
		'wp-parent-theme',
		'wp-mu-plugins',
		'wp-plugins-active',
		'wp-plugins-inactive',
		'wp-dropins',
		'wp-server',
		'wp-database',
		'wp-constants',
	);

	/**
	 * Owning Manager instance
	 *
	 * @var JezPress_Manager
	 */
	private $manager;

	/**
	 * Page hook returned by add_submenu_page(), '' until the menu is built
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Constructor
	 *
	 * @param JezPress_Manager $manager Manager instance.
	 */
	public function __construct( JezPress_Manager $manager ) {
		$this->manager = $manager;
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
	}

	/**
	 * Add the Site info submenu (called from JezPress_Manager::add_admin_menu())
	 *
	 * @return string Page hook ('' when the current user cannot see the page).
	 */
	public function add_menu() {
		$hook = add_submenu_page(
			JezPress_Manager::MENU_SLUG,
			__( 'Site info', 'jezpress-manager' ),
			__( 'Site info', 'jezpress-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		$this->hook = is_string( $hook ) ? $hook : '';

		return $this->hook;
	}

	/**
	 * Page hook for enqueue / screen checks
	 *
	 * @return string
	 */
	public function get_hook() {
		return $this->hook;
	}

	/**
	 * Page URL
	 *
	 * @return string
	 */
	public static function get_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	// ---- Data ----

	/**
	 * Whether the current user may see core's debug data (the five WordPress panes + report)
	 *
	 * Mirrors Site Health: view_site_health_checks maps to install_plugins, so on
	 * multisite only super admins see server paths and database details.
	 *
	 * @return bool
	 */
	private function can_view_debug() {
		return current_user_can( 'view_site_health_checks' );
	}

	/**
	 * Core's debug data (WP_Debug_Data::debug_data())
	 *
	 * Computed live, like Site Health's Info tab. Includes core's wordpress.org
	 * reachability probe (10 s timeout), so only call it when rendering or downloading.
	 *
	 * @return array
	 */
	private function get_debug_data() {
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php'; // debug_data() uses it.
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'got_url_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$info = WP_Debug_Data::debug_data();

		return is_array( $info ) ? $info : array();
	}

	/**
	 * One field from the debug data, or a fallback
	 *
	 * @param array  $info     debug_data() result.
	 * @param string $section  Section key.
	 * @param string $field    Field key.
	 * @param string $fallback Value when the field is missing.
	 * @return string
	 */
	private function field( $info, $section, $field, $fallback = '' ) {
		if ( isset( $info[ $section ]['fields'][ $field ]['value'] ) ) {
			$value = $info[ $section ]['fields'][ $field ]['value'];
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return $fallback;
	}

	/**
	 * The eight health checks
	 *
	 * Each: key, label, status (ok | info | warn | bad), detail, link (url + text, or null).
	 * ok and info count as passed; warn and bad need attention.
	 *
	 * @param array $dash JezPress_Manager::get_dashboard_data() result.
	 * @return array
	 */
	public function get_health_checks( $dash ) {
		$checks = array();

		// 1. HTTPS.
		$https    = wp_is_using_https();
		$checks[] = array(
			'key'    => 'https',
			'label'  => $https ? __( 'HTTPS in use', 'jezpress-manager' ) : __( 'Site is not using HTTPS', 'jezpress-manager' ),
			'status' => $https ? 'ok' : 'bad',
			'detail' => $https
				? __( 'Site and home URLs use https', 'jezpress-manager' )
				: __( 'Set both the WordPress Address and Site Address to https', 'jezpress-manager' ),
			'link'   => null,
		);

		// 2. REST API (loopback), via Site Health's own test.
		$checks[] = $this->check_rest();

		// 3. Persistent object cache.
		$ext      = wp_using_ext_object_cache();
		$checks[] = array(
			'key'    => 'object_cache',
			'label'  => $ext ? __( 'Object cache running', 'jezpress-manager' ) : __( 'Object cache not in use', 'jezpress-manager' ),
			'status' => $ext ? 'ok' : 'info',
			'detail' => $ext
				? __( 'A persistent object cache drop-in is active', 'jezpress-manager' )
				: __( 'No persistent object cache; fine for most sites', 'jezpress-manager' ),
			'link'   => null,
		);

		// 4. WP-Cron, measured by the Manager's own daily heartbeat.
		$checks[] = $this->check_cron();

		// 5. Plugin updates waiting (JezPress plugins; other plugins in the detail).
		// "Current" is only claimed when core has completed a check and every registered
		// plugin could be matched to an installed one; otherwise the status is unknown.
		$jez_updates = isset( $dash['stats']['updates'] ) ? (int) $dash['stats']['updates'] : 0;
		$untracked   = isset( $dash['stats']['untracked'] ) ? (int) $dash['stats']['untracked'] : 0;
		$checked     = isset( $dash['last_checked'] ) ? (int) $dash['last_checked'] : 0;
		$all_updates = $this->count_site_updates();
		$others      = max( 0, $all_updates - $jez_updates );
		$view_link   = array(
			'url'  => admin_url( 'admin.php?page=' . JezPress_Manager::MENU_SLUG . '#jezpress-plugins' ),
			'text' => __( 'View', 'jezpress-manager' ),
		);

		if ( $jez_updates > 0 ) {
			$status = 'warn';
			/* translators: %d: number of JezPress plugins with an update */
			$label  = sprintf( _n( '%d plugin update waiting', '%d plugin updates waiting', $jez_updates, 'jezpress-manager' ), $jez_updates );
			$detail = $others > 0
				/* translators: %d: number of non-JezPress plugins with an update */
				? sprintf( _n( '%d other plugin also has an update', '%d other plugins also have updates', $others, 'jezpress-manager' ), $others )
				: __( 'Update from the dashboard', 'jezpress-manager' );
			$link = $view_link;
		} elseif ( 0 === $checked ) {
			$status = 'info';
			$label  = __( 'Update status unknown', 'jezpress-manager' );
			$detail = __( 'WordPress has not checked for updates yet; use Check for updates on the dashboard', 'jezpress-manager' );
			$link   = $view_link;
		} elseif ( $untracked > 0 ) {
			$status = 'warn';
			$label  = __( 'Update status incomplete', 'jezpress-manager' );
			/* translators: %d: number of registered plugins whose install could not be matched */
			$detail = sprintf( _n( '%d registered plugin could not be matched to an installed plugin', '%d registered plugins could not be matched to installed plugins', $untracked, 'jezpress-manager' ), $untracked );
			$link   = $view_link;
		} else {
			$status = 'ok';
			$label  = __( 'No plugin updates waiting', 'jezpress-manager' );
			$detail = $others > 0
				/* translators: %d: number of non-JezPress plugins with an update */
				? sprintf( _n( '%d other plugin has an update', '%d other plugins have updates', $others, 'jezpress-manager' ), $others )
				: __( 'JezPress plugins are current', 'jezpress-manager' );
			$link = null;
		}

		$checks[] = array(
			'key'    => 'updates',
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'link'   => $link,
		);

		// 6. WP_DEBUG.
		$debug    = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display  = defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true;
		$checks[] = array(
			'key'    => 'wp_debug',
			'label'  => $debug ? __( 'WP_DEBUG is on', 'jezpress-manager' ) : __( 'WP_DEBUG is off', 'jezpress-manager' ),
			'status' => $debug ? 'warn' : 'ok',
			'detail' => $debug
				? ( $display
					? __( 'Errors may show to visitors; set WP_DEBUG_DISPLAY to false', 'jezpress-manager' )
					: __( 'Errors go to the log only', 'jezpress-manager' ) )
				: __( 'Debug output is disabled', 'jezpress-manager' ),
			'link'   => null,
		);

		// 7. PHP support, from wordpress.org's PHP support table (core caches it for a week).
		$checks[] = $this->check_php();

		// 8. Plugin updates allowed (file modifications).
		$allowed  = wp_is_file_mod_allowed( 'capability_update_core' );
		$checks[] = array(
			'key'    => 'file_mods',
			'label'  => $allowed ? __( 'Plugin updates allowed', 'jezpress-manager' ) : __( 'Plugin updates blocked', 'jezpress-manager' ),
			'status' => $allowed ? 'ok' : 'warn',
			'detail' => $allowed
				? ( ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED )
					? __( 'Manual updates only: AUTOMATIC_UPDATER_DISABLED is set', 'jezpress-manager' )
					: __( 'WordPress can install updates on this site', 'jezpress-manager' ) )
				: ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS )
					? __( 'DISALLOW_FILE_MODS is set; updates must be deployed by the host', 'jezpress-manager' )
					: __( 'A plugin or theme blocks file changes (file_mod_allowed filter)', 'jezpress-manager' ) ),
			'link'   => null,
		);

		return $checks;
	}

	/**
	 * REST API reachability (Site Health's test, same loopback request and 10 s timeout)
	 *
	 * @return array
	 */
	private function check_rest() {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$status = 'info';
		$detail = __( 'Could not run the REST API check', 'jezpress-manager' );

		$health = class_exists( 'WP_Site_Health' ) ? WP_Site_Health::get_instance() : null;
		if ( $health && method_exists( $health, 'get_test_rest_availability' ) ) {
			$result = $health->get_test_rest_availability();
			$core   = isset( $result['status'] ) ? $result['status'] : '';
			$status = 'good' === $core ? 'ok' : ( 'critical' === $core ? 'bad' : 'warn' );
			$detail = isset( $result['label'] ) ? wp_strip_all_tags( (string) $result['label'] ) : $detail;
		}

		return array(
			'key'    => 'rest',
			'label'  => 'ok' === $status ? __( 'REST API reachable', 'jezpress-manager' ) : __( 'REST API not reachable', 'jezpress-manager' ),
			'status' => $status,
			'detail' => $detail,
			'link'   => null,
		);
	}

	/**
	 * WP-Cron check from the Manager heartbeat
	 *
	 * @return array
	 */
	private function check_cron() {
		$last     = (int) get_option( JezPress_Manager::HEARTBEAT_OPTION, 0 );
		$next     = wp_next_scheduled( JezPress_Manager::HEARTBEAT_HOOK );
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$now      = time();

		$hint = $disabled
			? __( 'DISABLE_WP_CRON is set, so the host must call wp-cron.php on a schedule', 'jezpress-manager' )
			: '';

		if ( $last > 0 && ( $now - $last ) <= self::HEARTBEAT_MAX_AGE ) {
			$status = 'ok';
			$label  = __( 'WP-Cron firing', 'jezpress-manager' );
			/* translators: %s: human-readable time difference */
			$detail = sprintf( __( 'Heartbeat ran %s ago', 'jezpress-manager' ), human_time_diff( $last, $now ) );
		} elseif ( $last > 0 && $next && $next > $now ) {
			// Stale timestamp but the next run is still ahead (e.g. just reactivated): not a verdict yet.
			$status = 'info';
			$label  = __( 'WP-Cron not measured yet', 'jezpress-manager' );
			/* translators: %s: human-readable time difference */
			$detail = sprintf( __( 'Next heartbeat due in %s', 'jezpress-manager' ), human_time_diff( $now, $next ) );
		} elseif ( $last > 0 ) {
			$status = 'bad';
			$label  = __( 'WP-Cron not firing', 'jezpress-manager' );
			/* translators: %s: human-readable time difference */
			$detail = sprintf( __( 'Daily heartbeat last ran %s ago', 'jezpress-manager' ), human_time_diff( $last, $now ) );
		} elseif ( $next && $next < $now - HOUR_IN_SECONDS ) {
			$status = 'bad';
			$label  = __( 'WP-Cron not firing', 'jezpress-manager' );
			/* translators: %s: human-readable time difference */
			$detail = sprintf( __( 'Heartbeat was due %s ago and has not run', 'jezpress-manager' ), human_time_diff( $next, $now ) );
		} else {
			$status = 'info';
			$label  = __( 'WP-Cron not measured yet', 'jezpress-manager' );
			$detail = $next
				/* translators: %s: human-readable time difference */
				? sprintf( __( 'First heartbeat due in %s', 'jezpress-manager' ), human_time_diff( $now, $next ) )
				: __( 'Heartbeat is scheduled on the next admin page load', 'jezpress-manager' );
		}

		if ( '' !== $hint ) {
			$detail .= '. ' . $hint;
		}

		return array(
			'key'    => 'cron',
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'link'   => null,
		);
	}

	/**
	 * PHP support check (wp_check_php_version(): wordpress.org's table, cached a week by core)
	 *
	 * @return array
	 */
	private function check_php() {
		$response = function_exists( 'wp_check_php_version' ) ? wp_check_php_version() : false;

		if ( ! is_array( $response ) ) {
			$status = 'info';
			/* translators: %s: PHP version */
			$label  = sprintf( __( 'PHP %s (support not verified)', 'jezpress-manager' ), PHP_VERSION );
			$detail = __( 'WordPress.org could not be reached to confirm the PHP support status', 'jezpress-manager' );
		} elseif ( ! empty( $response['is_supported'] ) ) {
			$status = 'ok';
			/* translators: %s: PHP version */
			$label  = sprintf( __( 'PHP %s supported', 'jezpress-manager' ), PHP_VERSION );
			$detail = __( 'Actively supported by the PHP project', 'jezpress-manager' );
		} elseif ( ! empty( $response['is_secure'] ) ) {
			$status = 'warn';
			/* translators: %s: PHP version */
			$label  = sprintf( __( 'PHP %s is ageing', 'jezpress-manager' ), PHP_VERSION );
			$detail = __( 'Receives security fixes only; plan an upgrade', 'jezpress-manager' );
		} else {
			$status = 'bad';
			/* translators: %s: PHP version */
			$label  = sprintf( __( 'PHP %s unsupported', 'jezpress-manager' ), PHP_VERSION );
			$detail = __( 'No longer receives security fixes; upgrade PHP with the host', 'jezpress-manager' );
		}

		return array(
			'key'    => 'php',
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'link'   => null,
		);
	}

	/**
	 * Number of plugins (any author) with an update in core's transient
	 *
	 * @return int
	 */
	private function count_site_updates() {
		$data = get_site_transient( 'update_plugins' );
		return ( is_object( $data ) && ! empty( $data->response ) ) ? count( (array) $data->response ) : 0;
	}

	/**
	 * Summary of the checks
	 *
	 * @param array $checks get_health_checks() result.
	 * @return array { @type int $passed @type int $attention @type string $tone ok|warn|bad }
	 */
	public function summarise_checks( $checks ) {
		$passed = 0;
		$tone   = 'ok';
		foreach ( $checks as $check ) {
			if ( in_array( $check['status'], array( 'ok', 'info' ), true ) ) {
				++$passed;
			} elseif ( 'bad' === $check['status'] ) {
				$tone = 'bad';
			} elseif ( 'ok' === $tone ) {
				$tone = 'warn';
			}
		}

		return array(
			'passed'    => $passed,
			'attention' => count( $checks ) - $passed,
			'tone'      => $tone,
		);
	}

	/**
	 * The six panes
	 *
	 * Each pane: label + rows of array( label, value, code ). The JezPress pane
	 * needs no debug data; the other five fall back to direct lookups when a
	 * core field is missing (older WordPress, disabled ini_get).
	 *
	 * @param array $info debug_data() result (empty array when not permitted).
	 * @param array $dash get_dashboard_data() result.
	 * @param bool  $core Whether to include the five core panes.
	 * @return array
	 */
	public function get_panes( $info, $dash, $core ) {
		global $wpdb;

		$panes = array();
		$theme = wp_get_theme();
		$yes   = __( 'Yes', 'jezpress-manager' );
		$no    = __( 'No', 'jezpress-manager' );

		if ( $core ) {
			$panes['wordpress'] = array(
				'label' => __( 'WordPress', 'jezpress-manager' ),
				'rows'  => array(
					array( __( 'Version', 'jezpress-manager' ), $this->field( $info, 'wp-core', 'version', get_bloginfo( 'version' ) ), true ),
					array( __( 'Site URL', 'jezpress-manager' ), home_url(), false ),
					array( __( 'Multisite', 'jezpress-manager' ), is_multisite() ? $yes : $no, false ),
					array( __( 'Language', 'jezpress-manager' ), $this->field( $info, 'wp-core', 'site_language', get_locale() ), true ),
					array( __( 'Timezone', 'jezpress-manager' ), $this->field( $info, 'wp-core', 'timezone', wp_timezone_string() ), true ),
					array( __( 'Environment', 'jezpress-manager' ), $this->field( $info, 'wp-core', 'environment_type', wp_get_environment_type() ), true ),
				),
			);

			$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
			$upload_max      = size_format( wp_max_upload_size() );
			$curl            = function_exists( 'curl_version' ) ? (string) ( curl_version()['version'] ?? '' ) : '';

			$panes['server'] = array(
				'label' => __( 'Server', 'jezpress-manager' ),
				'rows'  => array(
					array( __( 'PHP', 'jezpress-manager' ), PHP_VERSION, true ),
					array( __( 'Web server', 'jezpress-manager' ), $this->field( $info, 'wp-server', 'httpd_software', '' !== $server_software ? $server_software : __( 'Unknown', 'jezpress-manager' ) ), false ),
					array( __( 'Memory limit', 'jezpress-manager' ), $this->field( $info, 'wp-server', 'memory_limit', (string) ini_get( 'memory_limit' ) ), true ),
					array( __( 'Max execution', 'jezpress-manager' ), $this->seconds_label( $this->field( $info, 'wp-server', 'time_limit', (string) ini_get( 'max_execution_time' ) ) ), true ),
					array( __( 'Upload max', 'jezpress-manager' ), $this->field( $info, 'wp-server', 'upload_max_filesize', $upload_max ), true ),
					array( __( 'cURL', 'jezpress-manager' ), $this->field( $info, 'wp-server', 'curl_version', '' !== $curl ? $curl : __( 'Not available', 'jezpress-manager' ) ), true ),
				),
			);

			$server_version = $this->field( $info, 'wp-database', 'server_version', (string) $wpdb->db_server_info() );
			$engine         = ( false !== stripos( $server_version, 'mariadb' ) ) ? 'MariaDB' : 'MySQL';
			$db_version     = (string) $wpdb->db_version();

			$panes['database'] = array(
				'label' => __( 'Database', 'jezpress-manager' ),
				'rows'  => array(
					array( __( 'Server', 'jezpress-manager' ), $engine, false ),
					array( __( 'Version', 'jezpress-manager' ), '' !== $db_version ? $db_version : $server_version, true ),
					array( __( 'Table prefix', 'jezpress-manager' ), (string) $wpdb->prefix, true ),
					array( __( 'Charset', 'jezpress-manager' ), $this->field( $info, 'wp-database', 'database_charset', (string) $wpdb->charset ), true ),
					array( __( 'Max allowed packet', 'jezpress-manager' ), $this->field( $info, 'wp-database', 'max_allowed_packet', __( 'Unknown', 'jezpress-manager' ) ), true ),
					array( __( 'Client version', 'jezpress-manager' ), $this->field( $info, 'wp-database', 'client_version', __( 'Unknown', 'jezpress-manager' ) ), true ),
				),
			);

			$panes['constants'] = array(
				'label' => __( 'Constants', 'jezpress-manager' ),
				'rows'  => array(
					array( 'WP_DEBUG', $this->constant_value( 'WP_DEBUG' ), true ),
					array( 'WP_DEBUG_LOG', $this->constant_value( 'WP_DEBUG_LOG' ), true ),
					array( 'WP_DEBUG_DISPLAY', $this->constant_value( 'WP_DEBUG_DISPLAY' ), true ),
					array( 'DISALLOW_FILE_EDIT', $this->constant_value( 'DISALLOW_FILE_EDIT' ), true ),
					array( 'DISALLOW_FILE_MODS', $this->constant_value( 'DISALLOW_FILE_MODS' ), true ),
					array( 'AUTOMATIC_UPDATER_DISABLED', $this->constant_value( 'AUTOMATIC_UPDATER_DISABLED' ), true ),
					array( 'WP_CACHE', $this->constant_value( 'WP_CACHE' ), true ),
					array( __( 'Debug log size', 'jezpress-manager' ), $this->debug_log_size(), false ),
				),
			);

			$counts = $this->count_plugins();
			$parent = $theme->parent();

			$panes['theme'] = array(
				'label' => __( 'Theme and plugins', 'jezpress-manager' ),
				'rows'  => array(
					array( __( 'Active theme', 'jezpress-manager' ), trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ), false ),
					array( __( 'Parent theme', 'jezpress-manager' ), $parent ? trim( $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) ) : __( 'None', 'jezpress-manager' ), false ),
					array( __( 'Plugins active', 'jezpress-manager' ), (string) $counts['active'], false ),
					array( __( 'Plugins inactive', 'jezpress-manager' ), (string) $counts['inactive'], false ),
					array( __( 'Must-use plugins', 'jezpress-manager' ), (string) $counts['mu'], false ),
					array( __( 'Updates waiting', 'jezpress-manager' ), (string) $this->count_site_updates(), false ),
				),
			);
		}

		$stats     = $dash['stats'];
		$heartbeat = (int) get_option( JezPress_Manager::HEARTBEAT_OPTION, 0 );
		$schema    = $this->registry_schema_summary();

		$panes['jezpress'] = array(
			'label' => __( 'JezPress', 'jezpress-manager' ),
			'rows'  => array(
				array( __( 'Manager', 'jezpress-manager' ), JEZPRESS_MANAGER_VERSION, true ),
				array(
					__( 'Plugins installed', 'jezpress-manager' ),
					/* translators: 1: total JezPress plugins, 2: active count */
					sprintf( __( '%1$d (%2$d active)', 'jezpress-manager' ), (int) $stats['total'], (int) $stats['active'] ),
					false,
				),
				array(
					__( 'Licences reported', 'jezpress-manager' ),
					/* translators: 1: licences active, 2: registered plugins */
					sprintf( __( '%1$d active of %2$d', 'jezpress-manager' ), (int) $stats['licensed'], (int) $stats['registered'] ),
					false,
				),
				array( __( 'Updates waiting', 'jezpress-manager' ), (string) (int) $stats['updates'], false ),
				array( __( 'Registry schema', 'jezpress-manager' ), $schema, false ),
				array(
					__( 'Last update check', 'jezpress-manager' ),
					$dash['last_checked'] > 0
						/* translators: %s: human-readable time difference */
						? sprintf( __( '%s ago', 'jezpress-manager' ), human_time_diff( $dash['last_checked'] ) )
						: __( 'Never', 'jezpress-manager' ),
					false,
				),
				array(
					__( 'Cron heartbeat', 'jezpress-manager' ),
					$heartbeat > 0
						/* translators: %s: human-readable time difference */
						? sprintf( __( '%s ago', 'jezpress-manager' ), human_time_diff( $heartbeat ) )
						: __( 'Not run yet', 'jezpress-manager' ),
					false,
				),
			),
		);

		return $panes;
	}

	/**
	 * "v2 · 3 of 5 plugins" — how many registered plugins pass any 1.3.0 field
	 *
	 * @return string
	 */
	private function registry_schema_summary() {
		$plugins = JezPress_Manager::get_plugins();
		$total   = count( $plugins );
		$current = 0;
		foreach ( $plugins as $plugin ) {
			if ( ! empty( $plugin['schema'] ) && (int) $plugin['schema'] >= JezPress_Manager::REGISTRY_SCHEMA ) {
				++$current;
			}
		}

		return sprintf(
			/* translators: 1: schema version, 2: plugins on it, 3: registered plugins */
			__( 'v%1$d (%2$d of %3$d plugins)', 'jezpress-manager' ),
			JezPress_Manager::REGISTRY_SCHEMA,
			$current,
			$total
		);
	}

	/**
	 * "30s", or "Unlimited" for 0 / -1 (how PHP reports no execution limit)
	 *
	 * @param string $value Raw ini value.
	 * @return string
	 */
	private function seconds_label( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return __( 'Unknown', 'jezpress-manager' );
		}
		if ( is_numeric( $value ) && (int) $value <= 0 ) {
			return __( 'Unlimited', 'jezpress-manager' );
		}
		/* translators: %s: number of seconds */
		return is_numeric( $value ) ? sprintf( __( '%ss', 'jezpress-manager' ), $value ) : $value;
	}

	/**
	 * Display value of a boolean-ish constant
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private function constant_value( $name ) {
		if ( ! defined( $name ) ) {
			return __( 'undefined', 'jezpress-manager' );
		}
		$value = constant( $name );
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_string( $value ) ) {
			return '' === $value ? "''" : $value;
		}
		return (string) $value;
	}

	/**
	 * Size of the debug log, when one exists
	 *
	 * WP_DEBUG_LOG may be true (wp-content/debug.log) or a custom path.
	 *
	 * @return string
	 */
	private function debug_log_size() {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return __( 'Logging off', 'jezpress-manager' );
		}
		$path = is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
		if ( ! @file_exists( $path ) || ! @is_file( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Path may be outside open_basedir.
			return __( 'No log file yet', 'jezpress-manager' );
		}
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- As above.
		return false === $size ? __( 'Unknown', 'jezpress-manager' ) : size_format( $size );
	}

	/**
	 * Active / inactive / must-use plugin counts
	 *
	 * @return array
	 */
	private function count_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active   = 0;
		$inactive = 0;
		foreach ( array_keys( get_plugins() ) as $basename ) {
			if ( is_plugin_active( $basename ) ) {
				++$active;
			} else {
				++$inactive;
			}
		}

		return array(
			'active'   => $active,
			'inactive' => $inactive,
			'mu'       => count( get_mu_plugins() ),
		);
	}

	// ---- Report ----

	/**
	 * Plain-text support report
	 *
	 * Health checks and the JezPress registry first, then the core debug
	 * sections through WP_Debug_Data::format(), which skips the fields core
	 * marks private (database credentials, ABSPATH, site URLs) exactly as Site
	 * Health's copy button does. Everything else matches Site Health's Info tab.
	 *
	 * @param array $checks get_health_checks() result.
	 * @param array $dash   get_dashboard_data() result.
	 * @param array $info   debug_data() result (empty array to omit core sections).
	 * @return string
	 */
	public function build_report( $checks, $dash, $info ) {
		$sections = array();

		$fields = array();
		foreach ( $checks as $check ) {
			$fields[ $check['key'] ] = array(
				'label' => $check['label'],
				'value' => strtoupper( $check['status'] ) . ' - ' . $check['detail'],
			);
		}
		$sections['jezpress-health'] = array(
			'label'  => __( 'JezPress health checks', 'jezpress-manager' ),
			'fields' => $fields,
		);

		$stats  = $dash['stats'];
		$fields = array(
			'manager'      => array(
				'label' => __( 'Manager version', 'jezpress-manager' ),
				'value' => JEZPRESS_MANAGER_VERSION,
			),
			'registry'     => array(
				'label' => __( 'Registry schema', 'jezpress-manager' ),
				'value' => $this->registry_schema_summary(),
			),
			'plugins'      => array(
				'label' => __( 'Plugins installed', 'jezpress-manager' ),
				'value' => sprintf( '%d (%d active, %d registered)', (int) $stats['total'], (int) $stats['active'], (int) $stats['registered'] ),
			),
			'licences'     => array(
				'label' => __( 'Licences reported active', 'jezpress-manager' ),
				'value' => sprintf( '%d of %d', (int) $stats['licensed'], (int) $stats['registered'] ),
			),
			'updates'      => array(
				'label' => __( 'Updates waiting', 'jezpress-manager' ),
				'value' => (string) (int) $stats['updates'],
			),
			'last_checked' => array(
				'label' => __( 'Last update check', 'jezpress-manager' ),
				'value' => $dash['last_checked'] > 0 ? gmdate( 'Y-m-d H:i:s', $dash['last_checked'] ) . ' UTC' : 'never',
			),
			'heartbeat'    => array(
				'label' => __( 'Cron heartbeat', 'jezpress-manager' ),
				'value' => (int) get_option( JezPress_Manager::HEARTBEAT_OPTION, 0 ) > 0
					? gmdate( 'Y-m-d H:i:s', (int) get_option( JezPress_Manager::HEARTBEAT_OPTION, 0 ) ) . ' UTC'
					: 'never',
			),
		);

		foreach ( $dash['rows'] as $row ) {
			$bits   = array();
			$bits[] = $row['version'];
			$bits[] = $row['active'] ? 'active' : 'inactive';
			if ( null !== $row['license'] ) {
				$bit = 'licence: ' . $row['license']['label'];
				if ( ! empty( $row['license_checked'] ) ) {
					$bit .= ' (checked ' . gmdate( 'Y-m-d H:i', (int) $row['license_checked'] ) . ' UTC)';
				}
				$bits[] = $bit;
			}
			if ( '' !== $row['new_version'] ) {
				$bits[] = 'update available: ' . $row['new_version'];
			}
			if ( ! empty( $row['supplied'] ) ) {
				$bits[] = 'fields: ' . implode( ' ', $row['supplied'] );
			}
			// WP_Debug_Data::format() prints the key, not the label, so the name goes in the value.
			$fields[ 'plugin_' . sanitize_key( $row['slug'] ) ] = array(
				'label' => $row['name'],
				'value' => $row['name'] . ' - ' . implode( ', ', $bits ),
			);
		}

		$sections['jezpress'] = array(
			'label'  => __( 'JezPress plugins', 'jezpress-manager' ),
			'fields' => $fields,
		);

		foreach ( self::REPORT_SECTIONS as $key ) {
			if ( isset( $info[ $key ] ) && is_array( $info[ $key ] ) ) {
				$sections[ $key ] = $info[ $key ];
			}
		}

		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}

		$header  = __( 'JezPress Manager site report', 'jezpress-manager' ) . "\n";
		$header .= 'Site: ' . home_url() . "\n";
		$header .= 'Generated: ' . wp_date( 'Y-m-d H:i:s T' ) . "\n\n";

		return $header . WP_Debug_Data::format( $sections, 'debug' );
	}

	/**
	 * Download the report as a .txt (admin_post handler)
	 */
	public function handle_download() {
		check_admin_referer( self::DOWNLOAD_ACTION );

		if ( ! current_user_can( 'manage_options' ) || ! $this->can_view_debug() ) {
			wp_die( esc_html__( 'You do not have permission to download the site report.', 'jezpress-manager' ), 403 );
		}

		$dash   = $this->manager->get_dashboard_data();
		$report = $this->build_report( $this->get_health_checks( $dash ), $dash, $this->get_debug_data() );
		$host   = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$name   = 'jezpress-site-info-' . ( '' !== $host ? $host . '-' : '' ) . wp_date( 'Ymd-Hi' ) . '.txt';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $report; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text download, Content-Type text/plain + nosniff.
		exit;
	}

	// ---- Rendering ----

	/**
	 * Render the page
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jezpress-manager' ) );
		}

		// Same gate as Site Health: without view_site_health_checks (install_plugins; super admins
		// on multisite) the page shows only the JezPress pane - no checks, panes, loopbacks or report.
		$can_debug = $this->can_view_debug();
		$dash      = $this->manager->get_dashboard_data();
		$checks    = $can_debug ? $this->get_health_checks( $dash ) : array();
		$summary   = $this->summarise_checks( $checks );
		$info      = $can_debug ? $this->get_debug_data() : array();
		$panes     = $this->get_panes( $info, $dash, $can_debug );
		$report    = $can_debug ? $this->build_report( $checks, $dash, $info ) : '';
		$generated = sprintf(
			/* translators: 1: date and time, 2: timezone */
			__( 'Generated %1$s %2$s', 'jezpress-manager' ),
			wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ),
			wp_timezone_string()
		);
		$download = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION ), self::DOWNLOAD_ACTION );
		?>
		<div class="wrap jezpress-manager-wrap jezpress-site-info" id="jezpress-manager">

			<div id="jezpress-notice" class="jezpress-notice" style="display:none;" role="status" aria-live="polite"></div>

			<header class="jezpress-header">
				<div class="jezpress-header-title">
					<div>
						<h1><?php esc_html_e( 'Site info', 'jezpress-manager' ); ?></h1>
						<p class="jezpress-header-meta">
							<?php esc_html_e( 'Everything Jezweb support asks for, in one place.', 'jezpress-manager' ); ?>
							<?php echo esc_html( $generated ); ?>
						</p>
					</div>
				</div>
				<?php if ( $can_debug ) : ?>
					<div class="jezpress-header-actions">
						<a href="<?php echo esc_url( $download ); ?>" class="button jezpress-btn jezpress-btn-secondary">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'Download report', 'jezpress-manager' ); ?>
						</a>
						<button type="button" class="button button-primary jezpress-btn jezpress-btn-primary" id="jezpress-copy-site" data-copied="<?php esc_attr_e( 'Copied', 'jezpress-manager' ); ?>">
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<?php esc_html_e( 'Copy for support', 'jezpress-manager' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</header>

			<?php if ( $can_debug ) : ?>
			<section class="jezpress-health jezpress-health-<?php echo esc_attr( $summary['tone'] ); ?>" aria-label="<?php esc_attr_e( 'Health checks', 'jezpress-manager' ); ?>">
				<div class="jezpress-health-summary">
					<?php if ( 0 === $summary['attention'] ) : ?>
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'All checks passed', 'jezpress-manager' ); ?></strong>
					<?php else : ?>
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<strong>
							<?php
							printf(
								/* translators: %d: number of checks needing attention */
								esc_html( _n( '%d check needs attention', '%d checks need attention', $summary['attention'], 'jezpress-manager' ) ),
								(int) $summary['attention']
							);
							?>
						</strong>
					<?php endif; ?>
					<span class="jezpress-health-count">
						<?php
						printf(
							/* translators: 1: checks passed, 2: total checks */
							esc_html__( '%1$d of %2$d passed', 'jezpress-manager' ),
							(int) $summary['passed'],
							count( $checks )
						);
						?>
					</span>
				</div>
				<ul class="jezpress-health-grid">
					<?php foreach ( $checks as $check ) : ?>
						<li class="jezpress-check jezpress-check-<?php echo esc_attr( $check['status'] ); ?>" title="<?php echo esc_attr( $check['detail'] ); ?>">
							<?php if ( 'ok' === $check['status'] ) : ?>
								<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php elseif ( 'info' === $check['status'] ) : ?>
								<span class="dashicons dashicons-minus" aria-hidden="true"></span>
							<?php elseif ( 'bad' === $check['status'] ) : ?>
								<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php else : ?>
								<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="jezpress-check-text">
								<span class="jezpress-check-label"><?php echo esc_html( $check['label'] ); ?></span>
								<span class="jezpress-check-detail"><?php echo esc_html( $check['detail'] ); ?></span>
							</span>
							<?php if ( ! empty( $check['link'] ) ) : ?>
								<a href="<?php echo esc_url( $check['link']['url'] ); ?>" class="jezpress-check-link"><?php echo esc_html( $check['link']['text'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php else : ?>
				<div class="jezpress-banner jezpress-banner-warn">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<div class="jezpress-banner-body">
						<?php if ( is_multisite() ) : ?>
							<strong><?php esc_html_e( 'Health checks, server, database and WordPress details are limited to network administrators.', 'jezpress-manager' ); ?></strong>
							<p><?php esc_html_e( 'The same rule as Site Health: ask a super admin to download the report.', 'jezpress-manager' ); ?></p>
						<?php else : ?>
							<strong><?php esc_html_e( 'Health checks, server, database and WordPress details need the install_plugins capability.', 'jezpress-manager' ); ?></strong>
							<p><?php esc_html_e( 'The same rule as Site Health: ask an administrator to download the report.', 'jezpress-manager' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="jezpress-panes">
				<?php foreach ( $panes as $key => $pane ) : ?>
					<section class="jezpress-card jezpress-pane jezpress-pane-<?php echo esc_attr( $key ); ?>">
						<div class="jezpress-card-header">
							<h2><?php echo esc_html( $pane['label'] ); ?></h2>
						</div>
						<dl class="jezpress-kv">
							<?php foreach ( $pane['rows'] as $row ) : ?>
								<dt><?php echo esc_html( $row[0] ); ?></dt>
								<dd>
									<?php if ( $row[2] ) : ?>
										<code><?php echo esc_html( $row[1] ); ?></code>
									<?php else : ?>
										<?php echo esc_html( $row[1] ); ?>
									<?php endif; ?>
								</dd>
							<?php endforeach; ?>
						</dl>
					</section>
				<?php endforeach; ?>
			</div>

			<div class="jezpress-card jezpress-site-footer">
				<p>
					<?php
					if ( $can_debug ) {
						esc_html_e( 'Copy for support puts this whole report on your clipboard as plain text, ready to paste into a ticket or an email to Jezweb. Database credentials and the other fields Site Health marks private are left out.', 'jezpress-manager' );
					} else {
						esc_html_e( 'Jezweb support can work from a screenshot of the JezPress pane above.', 'jezpress-manager' );
					}
					?>
				</p>
				<a href="https://jezpress.com/contact" target="_blank" rel="noopener" class="button jezpress-btn jezpress-btn-secondary">
					<?php esc_html_e( 'Submit a request', 'jezpress-manager' ); ?>
				</a>
			</div>

			<?php if ( $can_debug ) : ?>
				<textarea id="jezpress-site-report" class="screen-reader-text" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $report ); ?></textarea>
			<?php endif; ?>

			<footer class="jezpress-footer">
				<p>
					<?php
					printf(
						/* translators: %s: JezPress Manager version */
						esc_html__( 'JezPress Manager %s', 'jezpress-manager' ),
						esc_html( JEZPRESS_MANAGER_VERSION )
					);
					?>
					&middot;
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . JezPress_Manager::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Back to the dashboard', 'jezpress-manager' ); ?></a>
				</p>
				<p>Jezweb Pty Ltd &middot; 5 Cowper St, Wallsend NSW 2287 &middot; ABN 88 127 346 730</p>
			</footer>
		</div>
		<?php
	}
}
