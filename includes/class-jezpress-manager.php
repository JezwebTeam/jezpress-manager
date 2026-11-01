<?php
/**
 * JezPress Manager main class
 *
 * Provides a central dashboard for all JezPress plugins with
 * plugin listing, update actions, licence status (as reported by each
 * plugin), and support information.
 *
 * @package JezPress_Manager
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main JezPress Manager class
 */
class JezPress_Manager {

	/**
	 * Menu slug constant
	 *
	 * @var string
	 */
	const MENU_SLUG = 'jezpress-manager';

	/**
	 * Licence statuses a plugin may report through register().
	 *
	 * Anything outside this list is coerced to '' (no key). The Manager never
	 * validates a key itself; it renders what the plugin reports.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const LICENSE_STATUSES = array( 'active', 'expired', 'invalid', 'inactive', '' );

	/**
	 * Days before expiry at which an active licence is shown as expiring.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const EXPIRING_DAYS = 30;

	/**
	 * Single instance of the class
	 *
	 * @var JezPress_Manager|null
	 */
	private static $instance = null;

	/**
	 * Registered plugins array
	 *
	 * @var array
	 */
	private static $plugins = array();

	/**
	 * Get the singleton instance
	 *
	 * @return JezPress_Manager
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_dashboard' ) );
		add_action( 'wp_ajax_jezpress_manager_toggle_plugin', array( $this, 'ajax_toggle_plugin' ) );
		add_action( 'wp_ajax_jezpress_manager_check_updates', array( $this, 'ajax_check_updates' ) );
	}

	// ---- Registration API (public contract — additive changes only) ----

	/**
	 * Register a plugin with JezPress Manager
	 *
	 * Called by other JezPress plugins to register themselves.
	 *
	 * @param array $args {
	 *     Plugin registration arguments.
	 *
	 *     @type string   $slug           Unique plugin slug (also the submenu page slug).
	 *     @type string   $name           Plugin display name.
	 *     @type string   $version        Plugin version.
	 *     @type string   $menu_title     Submenu title.
	 *     @type string   $capability     Required capability. Default 'manage_options'.
	 *     @type callable $callback       Settings page callback function.
	 *     @type string   $license_status Licence status as reported by the plugin: 'active',
	 *                                    'expired', 'invalid', 'inactive', or '' (no key).
	 *     @type string   $license_expiry Licence expiry date (any strtotime()-parsable string).
	 *     @type string   $description    Short plugin description (optional).
	 *     @type string   $icon           Dashicon class. Default 'dashicons-admin-plugins'.
	 *     @type int      $position       Sort position (lower = higher). Default 10.
	 *     @type string   $plugin_file    Plugin basename, e.g. 'my-plugin/my-plugin.php'.
	 *                                    Lets the Manager find the plugin when the slug is not
	 *                                    the folder name. Default '' (derived from the slug).
	 *                                    @since 1.2.0
	 * }
	 */
	public static function register( $args ) {
		$defaults = array(
			'slug'           => '',
			'name'           => '',
			'version'        => '',
			'menu_title'     => '',
			'capability'     => 'manage_options',
			'callback'       => null,
			'license_status' => '',
			'license_expiry' => '',
			'description'    => '',
			'icon'           => 'dashicons-admin-plugins', // Plugin icon for dashboard.
			'position'       => 10, // Menu position (lower = higher in list).
			'plugin_file'    => '', // Plugin basename (since 1.2.0).
		);

		$plugin = wp_parse_args( $args, $defaults );

		// Sanitize the slug (used in URLs and as array key).
		$plugin['slug'] = sanitize_key( $plugin['slug'] );

		// Sanitize text fields.
		$plugin['name']           = sanitize_text_field( $plugin['name'] );
		$plugin['version']        = sanitize_text_field( $plugin['version'] );
		$plugin['menu_title']     = sanitize_text_field( $plugin['menu_title'] );
		$plugin['capability']     = sanitize_key( $plugin['capability'] );
		$plugin['license_expiry'] = sanitize_text_field( $plugin['license_expiry'] );
		$plugin['description']    = sanitize_text_field( $plugin['description'] );
		$plugin['icon']           = sanitize_html_class( $plugin['icon'] );
		$plugin['position']       = absint( $plugin['position'] );

		// Licence status: allowlist, anything else means "no key".
		$status                   = is_string( $plugin['license_status'] ) ? sanitize_key( $plugin['license_status'] ) : '';
		$plugin['license_status'] = in_array( $status, self::LICENSE_STATUSES, true ) ? $status : '';

		// Plugin basename: shape-checked here, existence-checked at use time.
		$plugin['plugin_file'] = self::sanitize_plugin_file( $plugin['plugin_file'] );

		if ( ! empty( $plugin['slug'] ) ) {
			self::$plugins[ $plugin['slug'] ] = $plugin;
		}
	}

	/**
	 * Deregister a plugin from JezPress Manager
	 *
	 * @param string $slug Plugin slug to remove.
	 * @return bool True if removed, false if not found.
	 */
	public static function deregister( $slug ) {
		$slug = sanitize_key( $slug );

		if ( isset( self::$plugins[ $slug ] ) ) {
			unset( self::$plugins[ $slug ] );
			return true;
		}

		return false;
	}

	/**
	 * Whether a plugin slug is registered with the Manager
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Plugin slug as passed to register().
	 * @return bool
	 */
	public static function has_plugin( $slug ) {
		return is_string( $slug ) && isset( self::$plugins[ sanitize_key( $slug ) ] );
	}

	/**
	 * Get all registered plugins
	 *
	 * @return array
	 */
	public static function get_plugins() {
		return self::$plugins;
	}

	/**
	 * Check if JezPress Manager is active
	 *
	 * Helper function for other plugins to check availability.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return true;
	}

	/**
	 * Get plugins sorted by position
	 *
	 * @return array Sorted plugins array.
	 */
	private static function get_sorted_plugins() {
		$plugins = self::$plugins;

		// Sort by position (lower number = higher in list), then alphabetically by name.
		uasort(
			$plugins,
			function ( $a, $b ) {
				if ( $a['position'] === $b['position'] ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
				return $a['position'] - $b['position'];
			}
		);

		return $plugins;
	}

	/**
	 * Sanitise a plugin basename passed through register()
	 *
	 * Accepts "folder/file.php" or "file.php" only. Rejects traversal and any
	 * character outside [A-Za-z0-9._-]. Never touches the filesystem — the
	 * value is only ever compared against the keys of get_plugins().
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $file Raw value.
	 * @return string Sanitised basename or ''.
	 */
	private static function sanitize_plugin_file( $file ) {
		if ( ! is_string( $file ) ) {
			return '';
		}
		$file = sanitize_text_field( $file );
		if ( '' === $file ) {
			return '';
		}

		$file = plugin_basename( $file );

		if ( 0 !== validate_file( $file ) || ! preg_match( '#^(?:[A-Za-z0-9._-]+/)?[A-Za-z0-9._-]+\.php$#', $file ) ) {
			return '';
		}

		return $file;
	}

	// ---- Plugin discovery ----

	/**
	 * Resolve the plugin basename for a registered plugin
	 *
	 * Order: the declared plugin_file → "slug/slug.php" → the first installed
	 * plugin whose folder equals the slug → the first *active* installed plugin
	 * whose header Name equals the registered name. Returns '' when nothing
	 * matches; the row then renders without a toggle or update action and is
	 * reported as "update status unknown".
	 *
	 * @since 1.2.0
	 *
	 * @param array $plugin    Registered plugin data.
	 * @param array $installed get_plugins() result.
	 * @return string
	 */
	private function resolve_plugin_file( $plugin, $installed ) {
		if ( '' !== $plugin['plugin_file'] && isset( $installed[ $plugin['plugin_file'] ] ) ) {
			return $plugin['plugin_file'];
		}

		$guess = $plugin['slug'] . '/' . $plugin['slug'] . '.php';
		if ( isset( $installed[ $guess ] ) ) {
			return $guess;
		}

		foreach ( array_keys( $installed ) as $basename ) {
			if ( dirname( $basename ) === $plugin['slug'] ) {
				return $basename;
			}
		}

		// Registered plugins are active by definition, so only an active plugin can be the match.
		// is_plugin_active() also covers network-activated plugins, which 'active_plugins' omits.
		foreach ( $installed as $basename => $headers ) {
			if ( '' !== $plugin['name'] && isset( $headers['Name'] ) && $headers['Name'] === $plugin['name'] && is_plugin_active( $basename ) ) {
				return $basename;
			}
		}

		return '';
	}

	/**
	 * Detect inactive JezPress plugins installed on the site
	 *
	 * Scans all installed plugins for JezPress-related plugins that are
	 * not currently active (i.e. not registered via self::$plugins).
	 * Detection uses multiple signals:
	 * - Custom header "JezPress Plugin: true"
	 * - Plugin folder name starting with "jezpress-"
	 * - Text domain containing "jezpress"
	 * - Author being "Jezweb"
	 *
	 * @param array    $all_plugins      get_plugins() result.
	 * @param string[] $registered_files Basenames already accounted for by registered plugins.
	 * @return array Array of inactive JezPress plugin data, keyed by plugin basename.
	 */
	private function detect_inactive_jezpress_plugins( $all_plugins, $registered_files = array() ) {
		$inactive = array();

		// Build a set of plugin basenames that correspond to registered (active) JezPress plugins.
		// A registered plugin's slug matches the folder/file portion of its basename.
		$registered_slugs = array_keys( self::$plugins );

		foreach ( $all_plugins as $basename => $headers ) {
			// Only inspect plugins that are NOT currently WordPress-active (site or network).
			if ( is_plugin_active( $basename ) || in_array( $basename, $registered_files, true ) ) {
				continue;
			}

			// Extract the folder name (first segment of the basename).
			$folder = strstr( $basename, '/', true );
			if ( false === $folder ) {
				// Single-file plugin in the plugins root; use filename without extension.
				$folder = basename( $basename, '.php' );
			}

			// Check whether this is a JezPress plugin via multiple signals.
			$is_jezpress = false;

			// 1. Explicit opt-in header: "JezPress Plugin: true".
			if ( ! empty( $headers['JezPress Plugin'] ) && 'true' === strtolower( trim( $headers['JezPress Plugin'] ) ) ) {
				$is_jezpress = true;
			}

			// 2. Folder name starts with "jezpress-".
			if ( ! $is_jezpress && 0 === stripos( $folder, 'jezpress-' ) ) {
				$is_jezpress = true;
			}

			// 3. Text domain contains "jezpress".
			if ( ! $is_jezpress && ! empty( $headers['TextDomain'] ) && false !== stripos( $headers['TextDomain'], 'jezpress' ) ) {
				$is_jezpress = true;
			}

			// 4. Author is "Jezweb" (case-insensitive).
			if ( ! $is_jezpress && ! empty( $headers['Author'] ) && false !== stripos( $headers['Author'], 'jezweb' ) ) {
				$is_jezpress = true;
			}

			if ( ! $is_jezpress ) {
				continue;
			}

			// Skip if this plugin's slug is already registered as active.
			$derived_slug = sanitize_key( $folder );
			if ( in_array( $derived_slug, $registered_slugs, true ) ) {
				continue;
			}

			$inactive[ $basename ] = array(
				'slug'        => $derived_slug,
				'name'        => ! empty( $headers['Name'] ) ? sanitize_text_field( $headers['Name'] ) : sanitize_text_field( $folder ),
				'version'     => ! empty( $headers['Version'] ) ? sanitize_text_field( $headers['Version'] ) : '',
				'description' => ! empty( $headers['Description'] ) ? sanitize_text_field( wp_strip_all_tags( $headers['Description'] ) ) : '',
				'plugin_file' => $basename,
				'active'      => false,
			);
		}

		return $inactive;
	}

	// ---- AJAX ----

	/**
	 * AJAX handler: activate or deactivate a JezPress plugin
	 *
	 * Expects POST fields:
	 *   - nonce       : jezpress_toggle_nonce
	 *   - plugin_file : plugin basename (e.g. jezpress-foo/jezpress-foo.php)
	 *   - action      : "activate" or "deactivate"
	 *
	 * @return void Outputs JSON and exits.
	 */
	public function ajax_toggle_plugin() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'jezpress_toggle_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-manager' ) ), 403 );
		}

		// Verify capability.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage plugins.', 'jezpress-manager' ) ), 403 );
		}

		$plugin_file   = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
		$toggle_action = isset( $_POST['toggle_action'] ) ? sanitize_key( $_POST['toggle_action'] ) : '';

		if ( empty( $plugin_file ) || ! in_array( $toggle_action, array( 'activate', 'deactivate' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request parameters.', 'jezpress-manager' ) ), 400 );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Strip any path-traversal components and confirm the value is a real
		// plugin basename before touching the filesystem. sanitize_text_field()
		// leaves "/" and "." intact, so "../../wp-config.php" would otherwise
		// survive and let file_exists() probe arbitrary paths.
		$plugin_file = plugin_basename( $plugin_file );
		if ( is_wp_error( validate_plugin( $plugin_file ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plugin file.', 'jezpress-manager' ) ), 400 );
		}

		// Validate that the plugin file actually exists in the plugins directory.
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin file not found.', 'jezpress-manager' ) ), 404 );
		}

		if ( 'activate' === $toggle_action ) {
			$result = activate_plugin( $plugin_file );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			}

			wp_send_json_success( array( 'message' => __( 'Plugin activated successfully.', 'jezpress-manager' ) ) );
		} else {
			deactivate_plugins( $plugin_file );
			wp_send_json_success( array( 'message' => __( 'Plugin deactivated successfully.', 'jezpress-manager' ) ) );
		}
	}

	/**
	 * AJAX handler: force a WordPress plugin-update check
	 *
	 * Clears the core update cache and re-runs wp_update_plugins(), the same
	 * thing core does after a plugin is installed or activated. Each JezPress
	 * plugin's own updater still answers from its own transient, so a fresh
	 * server release can take up to 12 hours to appear (see dashboard footnote).
	 *
	 * Expects POST field `nonce` (jezpress_check_updates_nonce).
	 *
	 * @since 1.2.0
	 * @return void Outputs JSON and exits.
	 */
	public function ajax_check_updates() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'jezpress_check_updates_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'jezpress-manager' ) ), 403 );
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to check for updates.', 'jezpress-manager' ) ), 403 );
		}

		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		wp_clean_plugins_cache( true );
		wp_update_plugins();

		// wp_update_plugins() saves last_checked BEFORE the HTTP request and returns without the
		// full write when api.wordpress.org is unreachable, so on failure the transient carries
		// last_checked but no checked/no_update lists, and no JezPress offer was injected either.
		// Say so rather than reporting "0 updates" as if the check succeeded.
		$fresh = get_site_transient( 'update_plugins' );
		if ( ! is_object( $fresh ) || ! isset( $fresh->checked ) || ! isset( $fresh->no_update ) ) {
			wp_send_json_error( array( 'message' => __( 'WordPress could not reach the update server. Try again later.', 'jezpress-manager' ) ), 502 );
		}

		$count = ! empty( $fresh->response ) ? count( (array) $fresh->response ) : 0;

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of plugins with an update available */
					_n( 'Check complete. %d plugin has an update available.', 'Check complete. %d plugins have updates available.', $count, 'jezpress-manager' ),
					$count
				),
				'updates' => $count,
			)
		);
	}

	// ---- Admin menu ----

	/**
	 * Add admin menu and submenus
	 */
	public function add_admin_menu() {
		// Add top-level menu with 'none' icon - we'll use CSS for the icon.
		add_menu_page(
			__( 'JezPress Manager', 'jezpress-manager' ),
			__( 'JezPress Manager', 'jezpress-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'none',
			58
		);

		// Add Dashboard submenu (replaces the default duplicate).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'jezpress-manager' ),
			__( 'Dashboard', 'jezpress-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		// Add submenu for each registered plugin (sorted by position).
		foreach ( self::get_sorted_plugins() as $slug => $plugin ) {
			if ( ! empty( $plugin['callback'] ) && is_callable( $plugin['callback'] ) ) {
				add_submenu_page(
					self::MENU_SLUG,
					$plugin['name'],
					$plugin['menu_title'],
					$plugin['capability'],
					$slug,
					$plugin['callback']
				);
			}
		}
	}

	/**
	 * Redirect to dashboard on activation
	 */
	public function maybe_redirect_to_dashboard() {
		if ( get_transient( 'jezpress_manager_activation_redirect' ) ) {
			delete_transient( 'jezpress_manager_activation_redirect' );

			if ( ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Activation redirect, no user action performed
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				exit;
			}
		}
	}

	// ---- Dashboard data ----

	/**
	 * Build everything the dashboard renders from
	 *
	 * @since 1.2.0
	 * @return array {
	 *     @type array  $rows          One entry per plugin (registered first, then detected inactive).
	 *     @type array  $stats         Counts for the stat cards.
	 *     @type array  $counts        Licence state counts.
	 *     @type array  $banner        Top-ranked licence problem, or empty array.
	 *     @type int    $last_checked  Unix time of the last core plugin-update check (0 = never).
	 *     @type bool   $auto_enabled  Whether plugin auto-updates are enabled site-wide.
	 *     @type string $lock_reason   Why auto-updates are locked, when they are.
	 * }
	 */
	private function get_dashboard_data() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed    = get_plugins();
		$update_data  = get_site_transient( 'update_plugins' );
		$responses    = ( is_object( $update_data ) && ! empty( $update_data->response ) ) ? (array) $update_data->response : array();
		$no_updates   = ( is_object( $update_data ) && ! empty( $update_data->no_update ) ) ? (array) $update_data->no_update : array();
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$auto_enabled = wp_is_auto_update_enabled_for_type( 'plugin' );
		$last_checked = ( is_object( $update_data ) && ! empty( $update_data->last_checked ) ) ? (int) $update_data->last_checked : 0;

		$lock_reason = '';
		if ( ! $auto_enabled ) {
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				$lock_reason = 'DISALLOW_FILE_MODS';
			} elseif ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
				$lock_reason = 'AUTOMATIC_UPDATER_DISABLED';
			} else {
				$lock_reason = __( 'site policy', 'jezpress-manager' );
			}
		}

		$rows             = array();
		$registered_files = array();

		foreach ( self::get_sorted_plugins() as $slug => $plugin ) {
			$file = $this->resolve_plugin_file( $plugin, $installed );
			if ( '' !== $file ) {
				$registered_files[] = $file;
			}

			$has_page = ! empty( $plugin['callback'] ) && is_callable( $plugin['callback'] );

			$rows[] = $this->build_row(
				array(
					'slug'           => $slug,
					'name'           => $plugin['name'],
					'description'    => $plugin['description'],
					'icon'           => $plugin['icon'],
					'version'        => $plugin['version'],
					'plugin_file'    => $file,
					'active'         => true,
					'registered'     => true,
					'license_status' => $plugin['license_status'],
					'license_expiry' => $plugin['license_expiry'],
					'settings_url'   => $has_page ? admin_url( 'admin.php?page=' . $slug ) : '',
				),
				$installed,
				$responses,
				$no_updates,
				$auto_updates,
				$auto_enabled
			);
		}

		foreach ( $this->detect_inactive_jezpress_plugins( $installed, $registered_files ) as $basename => $plugin ) {
			$rows[] = $this->build_row(
				array(
					'slug'           => $plugin['slug'],
					'name'           => $plugin['name'],
					'description'    => $plugin['description'],
					'icon'           => 'dashicons-admin-plugins',
					'version'        => $plugin['version'],
					'plugin_file'    => $basename,
					'active'         => false,
					'registered'     => false,
					'license_status' => '',
					'license_expiry' => '',
					'settings_url'   => '',
				),
				$installed,
				$responses,
				$no_updates,
				$auto_updates,
				$auto_enabled
			);
		}

		// Aggregate.
		$stats       = array(
			'total'      => count( $rows ),
			'active'     => 0,
			'updates'    => 0,
			'registered' => 0,
			'licensed'   => 0,
			'auto_total' => 0,
			'auto_on'    => 0,
			'attention'  => 0,
			'untracked'  => 0,
		);
		$counts      = array(
			'invalid'  => 0,
			'expired'  => 0,
			'inactive' => 0,
			'expiring' => 0,
			'none'     => 0,
		);
		$banner      = array();
		$banner_rank = 0;
		$ranks       = array(
			'invalid' => 2,
			'expired' => 1,
		);

		foreach ( $rows as $row ) {
			if ( $row['active'] ) {
				++$stats['active'];
			}
			if ( '' !== $row['new_version'] ) {
				++$stats['updates'];
			}
			if ( $row['installed'] ) {
				++$stats['auto_total'];
				if ( $row['auto_update_on'] ) {
					++$stats['auto_on'];
				}
			}
			if ( $row['needs_attention'] ) {
				++$stats['attention'];
			}
			if ( $row['registered'] ) {
				++$stats['registered'];
				if ( ! $row['installed'] ) {
					++$stats['untracked'];
				}
				$state = $row['license'];
				if ( $state['counts_active'] ) {
					++$stats['licensed'];
				}
				if ( isset( $counts[ $state['key'] ] ) ) {
					++$counts[ $state['key'] ];
				}
				if ( isset( $ranks[ $state['key'] ] ) && $ranks[ $state['key'] ] > $banner_rank ) {
					$banner_rank = $ranks[ $state['key'] ];
					$banner      = $row;
				}
			}
		}

		return array(
			'rows'         => $rows,
			'stats'        => $stats,
			'counts'       => $counts,
			'banner'       => $banner,
			'last_checked' => $last_checked,
			'auto_enabled' => $auto_enabled,
			'lock_reason'  => $lock_reason,
		);
	}

	/**
	 * Enrich one plugin row with install, update, auto-update and licence state
	 *
	 * @since 1.2.0
	 *
	 * @param array $row          Base row (see get_dashboard_data()).
	 * @param array $installed    get_plugins() result.
	 * @param array $responses    update_plugins transient responses keyed by basename.
	 * @param array $no_updates   update_plugins transient no_update offers keyed by basename.
	 * @param array $auto_updates auto_update_plugins site option.
	 * @param bool  $auto_enabled wp_is_auto_update_enabled_for_type( 'plugin' ).
	 * @return array
	 */
	private function build_row( $row, $installed, $responses, $no_updates, $auto_updates, $auto_enabled ) {
		$file             = $row['plugin_file'];
		$row['installed'] = ( '' !== $file && isset( $installed[ $file ] ) );

		$installed_version = ( $row['installed'] && ! empty( $installed[ $file ]['Version'] ) )
			? sanitize_text_field( $installed[ $file ]['Version'] )
			: $row['version'];
		if ( '' === $row['version'] ) {
			$row['version'] = $installed_version;
		}

		// Update availability from the core transient, version-compared so a stale
		// server flag can never produce an unsatisfiable "1.2.4 -> 1.2.4" prompt.
		$row['new_version']   = '';
		$row['update_slug']   = '';
		$row['changelog_url'] = '';
		if ( $row['installed'] && isset( $responses[ $file ] ) ) {
			$resp = $responses[ $file ];
			$new  = ( is_object( $resp ) && ! empty( $resp->new_version ) ) ? sanitize_text_field( (string) $resp->new_version ) : '';
			if ( '' !== $new && '' !== $installed_version && version_compare( $new, $installed_version, '>' ) ) {
				$row['new_version'] = $new;
				$row['update_slug'] = ! empty( $resp->slug ) ? sanitize_key( $resp->slug ) : sanitize_key( dirname( $file ) );
				// plugin-install.php dies without install_plugins (false under DISALLOW_FILE_MODS
				// and for non-super-admins on multisite), so only offer the link to users who can open it.
				if ( current_user_can( 'install_plugins' ) ) {
					$row['changelog_url'] = self_admin_url(
						'plugin-install.php?tab=plugin-information&plugin=' . $row['update_slug'] . '&section=changelog&TB_iframe=true&width=600&height=800'
					);
				}
			}
		}

		// Auto-updates: 'on' | 'off' | 'locked' | 'unavailable'.
		$row['auto_update']    = 'unavailable';
		$row['auto_update_on'] = false;
		if ( $row['installed'] ) {
			if ( ! $auto_enabled ) {
				$row['auto_update'] = 'locked';
			} else {
				$forced = wp_is_auto_update_forced_for_item(
					'plugin',
					null,
					$this->get_auto_update_item( $file, $installed[ $file ], isset( $responses[ $file ] ) ? $responses[ $file ] : ( isset( $no_updates[ $file ] ) ? $no_updates[ $file ] : null ) )
				);
				if ( null !== $forced ) {
					$row['auto_update']    = 'locked';
					$row['auto_update_on'] = (bool) $forced;
				} else {
					$row['auto_update']    = in_array( $file, $auto_updates, true ) ? 'on' : 'off';
					$row['auto_update_on'] = ( 'on' === $row['auto_update'] );
				}
			}
		}

		// Licence state (registered plugins only — detected-inactive ones report nothing).
		$row['license'] = $row['registered'] ? $this->get_license_state( $row['license_status'], $row['license_expiry'] ) : null;

		$row['needs_attention'] = ( '' !== $row['new_version'] ) || ( null !== $row['license'] && $row['license']['attention'] );

		return $row;
	}

	/**
	 * Build the item core's `auto_update_plugin` filter expects
	 *
	 * Mirrors WP_Plugins_List_Table::prepare_items(): plugin headers merged with the
	 * matching update offer (response or no_update) on top of core's defaults, so a
	 * policy that selects by slug or new_version sees the same payload here as it
	 * does during a real update check.
	 *
	 * @since 1.2.0
	 *
	 * @param string            $file    Plugin basename.
	 * @param array             $headers get_plugins() headers for the file.
	 * @param object|array|null $offer   The update_plugins response/no_update entry, if any.
	 * @return object
	 */
	private function get_auto_update_item( $file, $headers, $offer ) {
		$item = array_merge(
			array(
				'id'            => $file, // Core defaults the id to the basename when no offer exists.
				'slug'          => sanitize_key( dirname( $file ) ),
				'plugin'        => $file,
				'new_version'   => '',
				'url'           => '',
				'package'       => '',
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new stdClass(),
			),
			(array) $headers
		);

		if ( is_object( $offer ) || is_array( $offer ) ) {
			$item = array_merge( $item, (array) $offer );
		}

		return (object) $item;
	}

	/**
	 * Map a reported licence status (+ expiry) to a display state
	 *
	 * @since 1.2.0
	 *
	 * @param string $status Reported status (already allowlisted).
	 * @param string $expiry Reported expiry string.
	 * @return array {
	 *     @type string $key           active|expiring|expired|inactive|invalid|none
	 *                                 ("inactive" is what template-built plugins report whenever
	 *                                 there is no active licence, keyed or not — keep its copy neutral)
	 *     @type string $tone          ok|warn|danger|muted
	 *     @type string $label         Badge text.
	 *     @type string $detail        One-line detail under the badge ('' for none).
	 *     @type string $action        Action link text ('' for none).
	 *     @type bool   $attention     Counts toward "Needs attention".
	 *     @type bool   $counts_active Counts toward "N of M active".
	 * }
	 */
	private function get_license_state( $status, $expiry ) {
		$timestamp = ( '' !== $expiry ) ? strtotime( $expiry ) : false;
		$date      = $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
		// Whole days from the start of today (site timezone): plugins pass date-only strings,
		// which strtotime() reads as midnight, so measuring from time() would call a licence
		// expired for the whole of its final day.
		$today     = (int) strtotime( wp_date( 'Y-m-d' ) );
		$days_left = $timestamp ? (int) floor( ( $timestamp - $today ) / DAY_IN_SECONDS ) : null;

		// A plugin still reporting "active" past its own expiry date is expired.
		if ( 'active' === $status && null !== $days_left && $days_left < 0 ) {
			$status = 'expired';
		}

		$state = array(
			'key'           => 'none',
			'tone'          => 'muted',
			'label'         => __( 'No key', 'jezpress-manager' ),
			'detail'        => __( 'Updates paused without a key', 'jezpress-manager' ),
			'action'        => __( 'Add key in settings', 'jezpress-manager' ),
			'attention'     => true,
			'counts_active' => false,
		);

		switch ( $status ) {
			case 'active':
				if ( null !== $days_left && $days_left >= 0 && $days_left <= self::EXPIRING_DAYS ) {
					$state['key']   = 'expiring';
					$state['tone']  = 'warn';
					$state['label'] = ( 0 === $days_left )
						? __( 'Expires today', 'jezpress-manager' )
						/* translators: %d: number of days until the licence expires */
						: sprintf( _n( 'Expires in %d day', 'Expires in %d days', $days_left, 'jezpress-manager' ), $days_left );
					/* translators: %s: formatted expiry date */
					$state['detail']        = sprintf( __( 'Renew before %s to keep updates', 'jezpress-manager' ), $date );
					$state['action']        = __( 'Renew in settings', 'jezpress-manager' );
					$state['attention']     = true;
					$state['counts_active'] = true;
				} else {
					$state['key']   = 'active';
					$state['tone']  = 'ok';
					$state['label'] = __( 'Active', 'jezpress-manager' );
					/* translators: %s: formatted expiry date */
					$state['detail']        = $date ? sprintf( __( 'Expires %s', 'jezpress-manager' ), $date ) : '';
					$state['action']        = '';
					$state['attention']     = false;
					$state['counts_active'] = true;
				}
				break;

			case 'expired':
				$state['key']   = 'expired';
				$state['tone']  = 'warn';
				$state['label'] = __( 'Expired', 'jezpress-manager' );
				/* translators: %s: formatted expiry date */
				$state['detail'] = $date ? sprintf( __( 'Expired %s', 'jezpress-manager' ), $date ) : __( 'Updates paused until renewed', 'jezpress-manager' );
				$state['action'] = __( 'Renew in settings', 'jezpress-manager' );
				break;

			case 'inactive':
				$state['key']    = 'inactive';
				$state['tone']   = 'warn';
				$state['label']  = __( 'Not activated', 'jezpress-manager' );
				$state['detail'] = __( 'No active licence', 'jezpress-manager' );
				$state['action'] = __( 'Activate in settings', 'jezpress-manager' );
				break;

			case 'invalid':
				$state['key']    = 'invalid';
				$state['tone']   = 'danger';
				$state['label']  = __( 'Invalid key', 'jezpress-manager' );
				$state['detail'] = '';
				$state['action'] = __( 'Fix in settings', 'jezpress-manager' );
				break;
		}

		return $state;
	}

	/**
	 * Plain-text site details for the Copy button
	 *
	 * @since 1.2.0
	 *
	 * @param array $data get_dashboard_data() result.
	 * @return string
	 */
	private function get_site_report( $data ) {
		$lines   = array();
		$lines[] = 'JezPress Manager - site details';
		$lines[] = 'Site: ' . wp_parse_url( home_url(), PHP_URL_HOST );
		$lines[] = 'WordPress: ' . get_bloginfo( 'version' );
		$lines[] = 'PHP: ' . PHP_VERSION;
		$lines[] = 'JezPress Manager: ' . JEZPRESS_MANAGER_VERSION;
		$lines[] = 'Plugins registered: ' . $data['stats']['registered'];
		$lines[] = 'Updates available: ' . $data['stats']['updates'];
		$lines[] = '';
		$lines[] = 'Plugins:';

		foreach ( $data['rows'] as $row ) {
			$bits   = array();
			$bits[] = $row['active'] ? 'active' : 'inactive';
			if ( null !== $row['license'] ) {
				$bits[] = 'licence: ' . $row['license']['label'];
			}
			if ( '' !== $row['new_version'] ) {
				$bits[] = 'update available: ' . $row['new_version'];
			}
			$lines[] = sprintf( '- %s %s (%s)', $row['name'], $row['version'], implode( ', ', $bits ) );
		}

		return implode( "\n", $lines );
	}

	// ---- Dashboard rendering ----

	/**
	 * Render the dashboard page
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jezpress-manager' ) );
		}

		$data   = $this->get_dashboard_data();
		$rows   = $data['rows'];
		$stats  = $data['stats'];
		$counts = $data['counts'];

		$can_update   = current_user_can( 'update_plugins' );
		$can_toggle   = current_user_can( 'activate_plugins' );
		$can_auto     = $can_update && $data['auto_enabled'];
		$host         = wp_parse_url( home_url(), PHP_URL_HOST );
		$toggle_nonce = wp_create_nonce( 'jezpress_toggle_nonce' );
		?>
		<div class="wrap jezpress-manager-wrap" id="jezpress-manager">

			<div id="jezpress-notice" class="jezpress-notice" style="display:none;" role="status" aria-live="polite"></div>

			<header class="jezpress-header">
				<div class="jezpress-header-title">
					<span class="jezpress-logo" aria-hidden="true"></span>
					<div>
						<h1><?php esc_html_e( 'JezPress Manager', 'jezpress-manager' ); ?></h1>
						<p class="jezpress-header-meta">
							<span><?php echo esc_html( $host ); ?></span>
							<span class="jezpress-sep" aria-hidden="true">/</span>
							<span id="jezpress-last-checked">
								<?php
								if ( $data['last_checked'] > 0 ) {
									printf(
										/* translators: %s: human-readable time difference, e.g. "2 minutes" */
										esc_html__( 'Updates checked %s ago', 'jezpress-manager' ),
										esc_html( human_time_diff( $data['last_checked'] ) )
									);
								} else {
									esc_html_e( 'Updates not checked yet', 'jezpress-manager' );
								}
								?>
							</span>
						</p>
					</div>
				</div>
				<?php if ( $can_update ) : ?>
					<div class="jezpress-header-actions">
						<button type="button" class="button jezpress-btn jezpress-btn-secondary" id="jezpress-check-updates">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
							<?php esc_html_e( 'Check for updates', 'jezpress-manager' ); ?>
						</button>
						<?php if ( $stats['updates'] > 0 ) : ?>
							<button type="button" class="button button-primary jezpress-btn jezpress-btn-primary" id="jezpress-update-all">
								<?php
								printf(
									/* translators: %d: number of plugins with an update available */
									esc_html__( 'Update all (%d)', 'jezpress-manager' ),
									(int) $stats['updates']
								);
								?>
							</button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</header>

			<?php $this->render_banner( $data ); ?>

			<section class="jezpress-stats" aria-label="<?php esc_attr_e( 'Summary', 'jezpress-manager' ); ?>">
				<div class="jezpress-stat">
					<p class="jezpress-stat-label"><?php esc_html_e( 'Plugins active', 'jezpress-manager' ); ?></p>
					<p class="jezpress-stat-value">
						<strong><?php echo esc_html( $stats['active'] ); ?></strong>
						<?php
						/* translators: %d: total number of JezPress plugins */
						printf( esc_html__( 'of %d', 'jezpress-manager' ), (int) $stats['total'] );
						?>
					</p>
					<p class="jezpress-stat-detail">
						<?php
						if ( $stats['total'] > 0 && $stats['active'] === $stats['total'] ) {
							esc_html_e( 'All JezPress plugins running', 'jezpress-manager' );
						} elseif ( $stats['total'] > 0 ) {
							printf(
								/* translators: %d: number of inactive plugins */
								esc_html( _n( '%d plugin inactive', '%d plugins inactive', $stats['total'] - $stats['active'], 'jezpress-manager' ) ),
								(int) ( $stats['total'] - $stats['active'] )
							);
						} else {
							esc_html_e( 'No JezPress plugins found', 'jezpress-manager' );
						}
						?>
					</p>
				</div>

				<div class="jezpress-stat">
					<p class="jezpress-stat-label"><?php esc_html_e( 'Updates available', 'jezpress-manager' ); ?></p>
					<p class="jezpress-stat-value"><strong><?php echo esc_html( $stats['updates'] ); ?></strong></p>
					<p class="jezpress-stat-detail">
						<?php if ( $stats['updates'] > 0 ) : ?>
							<a href="#jezpress-plugins" class="jezpress-filter-link" data-filter="attention"><?php esc_html_e( 'Review and update', 'jezpress-manager' ); ?></a>
						<?php elseif ( $stats['untracked'] > 0 ) : ?>
							<?php
							printf(
								/* translators: %d: number of registered plugins whose install could not be matched */
								esc_html( _n( '%d plugin not tracked', '%d plugins not tracked', $stats['untracked'], 'jezpress-manager' ) ),
								(int) $stats['untracked']
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Everything is current', 'jezpress-manager' ); ?>
						<?php endif; ?>
					</p>
				</div>

				<?php
				$lic_tone = 'ok';
				if ( $counts['invalid'] > 0 || $counts['expired'] > 0 ) {
					$lic_tone = 'danger';
				} elseif ( $counts['inactive'] > 0 || $counts['none'] > 0 || $counts['expiring'] > 0 ) {
					$lic_tone = 'warn';
				}
				$lic_bits = array();
				if ( $counts['invalid'] > 0 ) {
					/* translators: %d: count */
					$lic_bits[] = sprintf( __( '%d invalid', 'jezpress-manager' ), $counts['invalid'] );
				}
				if ( $counts['expired'] > 0 ) {
					/* translators: %d: count */
					$lic_bits[] = sprintf( __( '%d expired', 'jezpress-manager' ), $counts['expired'] );
				}
				if ( $counts['expiring'] > 0 ) {
					/* translators: %d: count */
					$lic_bits[] = sprintf( __( '%d expiring soon', 'jezpress-manager' ), $counts['expiring'] );
				}
				if ( $counts['inactive'] > 0 ) {
					/* translators: %d: count */
					$lic_bits[] = sprintf( __( '%d not activated', 'jezpress-manager' ), $counts['inactive'] );
				}
				if ( $counts['none'] > 0 ) {
					/* translators: %d: count */
					$lic_bits[] = sprintf( __( '%d no key', 'jezpress-manager' ), $counts['none'] );
				}
				?>
				<div class="jezpress-stat jezpress-stat-<?php echo esc_attr( $lic_tone ); ?>">
					<p class="jezpress-stat-label"><?php esc_html_e( 'Licences', 'jezpress-manager' ); ?></p>
					<p class="jezpress-stat-value">
						<strong><?php echo esc_html( $stats['licensed'] ); ?></strong>
						<?php
						/* translators: %d: number of registered plugins */
						printf( esc_html__( 'of %d active', 'jezpress-manager' ), (int) $stats['registered'] );
						?>
					</p>
					<p class="jezpress-stat-detail">
						<?php
						if ( $lic_bits ) {
							echo esc_html( implode( ', ', $lic_bits ) );
						} elseif ( $stats['registered'] > 0 ) {
							esc_html_e( 'All licences active', 'jezpress-manager' );
						} else {
							esc_html_e( 'No plugins report a licence', 'jezpress-manager' );
						}
						?>
					</p>
				</div>

				<div class="jezpress-stat">
					<p class="jezpress-stat-label"><?php esc_html_e( 'Auto-updates', 'jezpress-manager' ); ?></p>
					<p class="jezpress-stat-value">
						<?php if ( $data['auto_enabled'] ) : ?>
							<strong><?php echo esc_html( $stats['auto_on'] ); ?></strong>
							<?php
							/* translators: %d: number of plugins that can auto-update */
							printf( esc_html__( 'of %d on', 'jezpress-manager' ), (int) $stats['auto_total'] );
							?>
						<?php else : ?>
							<strong><?php esc_html_e( 'Locked', 'jezpress-manager' ); ?></strong>
						<?php endif; ?>
					</p>
					<p class="jezpress-stat-detail">
						<?php
						if ( $data['auto_enabled'] ) {
							esc_html_e( 'Set per plugin', 'jezpress-manager' );
						} else {
							/* translators: %s: constant name or reason */
							printf( esc_html__( 'Locked by %s', 'jezpress-manager' ), '<code>' . esc_html( $data['lock_reason'] ) . '</code>' );
						}
						?>
					</p>
				</div>
			</section>

			<div class="jezpress-dashboard-grid">
				<main class="jezpress-main">
					<div class="jezpress-card jezpress-card-plugins" id="jezpress-plugins">
						<div class="jezpress-card-header jezpress-plugins-header">
							<h2>
								<?php esc_html_e( 'Installed plugins', 'jezpress-manager' ); ?>
								<span class="jezpress-count" id="jezpress-plugin-count"><?php echo esc_html( $stats['total'] ); ?></span>
							</h2>
							<?php if ( $stats['total'] > 0 ) : ?>
								<div class="jezpress-plugins-tools">
									<label class="jezpress-search">
										<span class="dashicons dashicons-search" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php esc_html_e( 'Search plugins', 'jezpress-manager' ); ?></span>
										<input type="search" id="jezpress-search" placeholder="<?php esc_attr_e( 'Search plugins', 'jezpress-manager' ); ?>" autocomplete="off">
									</label>
									<div class="jezpress-segmented" role="group" aria-label="<?php esc_attr_e( 'Filter plugins', 'jezpress-manager' ); ?>">
										<button type="button" class="is-active" data-filter="all" aria-pressed="true">
											<?php esc_html_e( 'All', 'jezpress-manager' ); ?>
										</button>
										<button type="button" data-filter="attention" aria-pressed="false">
											<?php esc_html_e( 'Needs attention', 'jezpress-manager' ); ?>
											<span class="jezpress-count jezpress-count-attention"><?php echo esc_html( $stats['attention'] ); ?></span>
										</button>
									</div>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( 0 === $stats['total'] ) : ?>
							<div class="jezpress-empty-state">
								<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
								<p><?php esc_html_e( 'No JezPress plugins found.', 'jezpress-manager' ); ?></p>
								<p class="description"><?php esc_html_e( 'Install JezPress plugins to see them here.', 'jezpress-manager' ); ?></p>
							</div>
						<?php else : ?>
							<table class="jezpress-table" id="jezpress-plugin-table">
								<thead>
									<tr>
										<th scope="col" class="column-plugin"><?php esc_html_e( 'Plugin', 'jezpress-manager' ); ?></th>
										<th scope="col" class="column-license"><?php esc_html_e( 'Licence', 'jezpress-manager' ); ?></th>
										<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'jezpress-manager' ); ?></th>
										<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'jezpress-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $rows as $row ) {
										$this->render_row( $row, $can_update, $can_toggle, $can_auto, $data['lock_reason'], $toggle_nonce );
									}
									?>
								</tbody>
							</table>
							<p class="jezpress-table-empty" id="jezpress-table-empty" hidden><?php esc_html_e( 'No plugins match.', 'jezpress-manager' ); ?></p>
							<p class="jezpress-footnote">
								<?php esc_html_e( 'Check for updates asks WordPress to re-check now. Each plugin answers from its own cache, so versions and licence status can be up to 12 hours old.', 'jezpress-manager' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</main>

				<aside class="jezpress-sidebar">
					<div class="jezpress-card">
						<div class="jezpress-card-header">
							<h2><?php esc_html_e( 'This site', 'jezpress-manager' ); ?></h2>
							<button type="button" class="button jezpress-btn jezpress-btn-small" id="jezpress-copy-site" data-copied="<?php esc_attr_e( 'Copied', 'jezpress-manager' ); ?>">
								<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
								<?php esc_html_e( 'Copy', 'jezpress-manager' ); ?>
							</button>
						</div>
						<dl class="jezpress-kv">
							<dt><?php esc_html_e( 'WordPress', 'jezpress-manager' ); ?></dt>
							<dd><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></dd>
							<dt><?php esc_html_e( 'PHP', 'jezpress-manager' ); ?></dt>
							<dd><code><?php echo esc_html( PHP_VERSION ); ?></code></dd>
							<dt><?php esc_html_e( 'Manager', 'jezpress-manager' ); ?></dt>
							<dd><code><?php echo esc_html( JEZPRESS_MANAGER_VERSION ); ?></code></dd>
							<dt><?php esc_html_e( 'Plugins registered', 'jezpress-manager' ); ?></dt>
							<dd><?php echo esc_html( $stats['registered'] ); ?></dd>
						</dl>
						<p class="description"><?php esc_html_e( 'Copy pastes the full report into a support request.', 'jezpress-manager' ); ?></p>
						<textarea id="jezpress-site-report" class="screen-reader-text" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $this->get_site_report( $data ) ); ?></textarea>
					</div>

					<div class="jezpress-card">
						<div class="jezpress-card-header">
							<h2><?php esc_html_e( 'Need a hand?', 'jezpress-manager' ); ?></h2>
						</div>
						<ul class="jezpress-support-list">
							<li>
								<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
								<a href="mailto:jez@jezweb.net">jez@jezweb.net</a>
							</li>
							<li>
								<span class="dashicons dashicons-phone" aria-hidden="true"></span>
								<a href="tel:1300024766">1300 024 766</a>
							</li>
							<li>
								<span class="dashicons dashicons-clock" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Mon to Fri, 9am to 5pm AEST', 'jezpress-manager' ); ?></span>
							</li>
						</ul>
						<a href="https://jezpress.com/contact" target="_blank" rel="noopener" class="button button-primary jezpress-btn jezpress-btn-primary jezpress-btn-block">
							<?php esc_html_e( 'Submit a request', 'jezpress-manager' ); ?>
						</a>
					</div>

					<div class="jezpress-card">
						<div class="jezpress-card-header">
							<h2><?php esc_html_e( 'Quick links', 'jezpress-manager' ); ?></h2>
						</div>
						<ul class="jezpress-chips">
							<li><a href="https://jezpress.com/plugins" target="_blank" rel="noopener"><?php esc_html_e( 'Plugins', 'jezpress-manager' ); ?></a></li>
							<li><a href="https://jezpress.com/hosting" target="_blank" rel="noopener"><?php esc_html_e( 'Hosting', 'jezpress-manager' ); ?></a></li>
							<li><a href="https://jezpress.com/about" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'jezpress-manager' ); ?></a></li>
							<li><a href="https://jezweb.com.au" target="_blank" rel="noopener">jezweb.com.au</a></li>
						</ul>
					</div>
				</aside>
			</div>

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
					<a href="https://jezpress.com" target="_blank" rel="noopener">jezpress.com</a>
				</p>
				<p>Jezweb Pty Ltd &middot; 5 Cowper St, Wallsend NSW 2287 &middot; ABN 88 127 346 730</p>
			</footer>
		</div>
		<?php
	}

	/**
	 * Render the attention banner (or the healthy strip)
	 *
	 * @since 1.2.0
	 *
	 * @param array $data get_dashboard_data() result.
	 */
	private function render_banner( $data ) {
		$banner = $data['banner'];
		$stats  = $data['stats'];

		if ( empty( $banner ) ) {
			if ( $stats['total'] > 0 && 0 === $stats['attention'] && 0 === $stats['untracked'] ) {
				?>
				<div class="jezpress-banner jezpress-banner-ok" role="status">
					<span class="dashicons dashicons-yes" aria-hidden="true"></span>
					<div>
						<strong><?php esc_html_e( 'Everything is healthy', 'jezpress-manager' ); ?></strong>
						<p><?php esc_html_e( 'All plugins licensed and up to date.', 'jezpress-manager' ); ?></p>
					</div>
				</div>
				<?php
			}
			return;
		}

		$state = $banner['license'];
		$name  = $banner['name'];

		switch ( $state['key'] ) {
			case 'invalid':
				/* translators: %s: plugin name */
				$title = sprintf( __( '%s licence is invalid', 'jezpress-manager' ), $name );
				$text  = __( 'Updates and support for this plugin are paused until a valid key is entered on its licence tab.', 'jezpress-manager' );
				/* translators: %s: plugin name */
				$cta  = sprintf( __( 'Open %s licence', 'jezpress-manager' ), $name );
				$tone = 'danger';
				break;
			case 'expired':
				/* translators: %s: plugin name */
				$title = sprintf( __( '%s licence has expired', 'jezpress-manager' ), $name );
				$text  = __( 'Updates are paused until the licence is renewed.', 'jezpress-manager' );
				$cta   = __( 'Renew in settings', 'jezpress-manager' );
				$tone  = 'warn';
				break;
			default:
				/* translators: %s: plugin name */
				$title = sprintf( __( '%s licence is not active', 'jezpress-manager' ), $name );
				$text  = __( 'Add or activate a key in its settings to keep updates.', 'jezpress-manager' );
				$cta   = __( 'Activate in settings', 'jezpress-manager' );
				$tone  = 'warn';
				break;
		}

		$others = max( 0, $stats['attention'] - 1 );
		?>
		<div class="jezpress-banner jezpress-banner-<?php echo esc_attr( $tone ); ?>" role="alert">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div class="jezpress-banner-body">
				<strong><?php echo esc_html( $title ); ?></strong>
				<p>
					<?php echo esc_html( $text ); ?>
					<?php if ( $others > 0 ) : ?>
						<a href="#jezpress-plugins" class="jezpress-filter-link" data-filter="attention">
							<?php
							printf(
								/* translators: %d: number of other plugins needing attention */
								esc_html( _n( '%d other plugin needs attention.', '%d other plugins need attention.', $others, 'jezpress-manager' ) ),
								(int) $others
							);
							?>
						</a>
					<?php endif; ?>
				</p>
			</div>
			<?php if ( '' !== $banner['settings_url'] ) : ?>
				<a href="<?php echo esc_url( $banner['settings_url'] ); ?>" class="button jezpress-btn jezpress-btn-<?php echo esc_attr( $tone ); ?>">
					<?php echo esc_html( $cta ); ?>
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one plugin row
	 *
	 * @since 1.2.0
	 *
	 * @param array  $row         Row from get_dashboard_data().
	 * @param bool   $can_update  Current user may update plugins.
	 * @param bool   $can_toggle  Current user may activate/deactivate plugins.
	 * @param bool   $can_auto    Current user may change auto-updates and they are enabled.
	 * @param string $lock_reason Site-wide auto-update lock reason ('' when not locked).
	 * @param string $toggle_nonce Nonce for the activate/deactivate AJAX action.
	 */
	private function render_row( $row, $can_update, $can_toggle, $can_auto, $lock_reason, $toggle_nonce ) {
		$state         = $row['license'];
		$has_update    = ( '' !== $row['new_version'] );
		$row_classes   = array( 'jezpress-row' );
		$row_classes[] = $row['active'] ? 'is-active' : 'is-inactive';
		if ( $row['needs_attention'] ) {
			$row_classes[] = 'needs-attention';
		}
		if ( $state && 'danger' === $state['tone'] ) {
			$row_classes[] = 'is-danger';
		}
		$search_text = strtolower( $row['name'] . ' ' . $row['description'] . ' ' . $row['slug'] );
		?>
		<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
			data-plugin-file="<?php echo esc_attr( $row['plugin_file'] ); ?>"
			data-slug="<?php echo esc_attr( $row['update_slug'] ? $row['update_slug'] : $row['slug'] ); ?>"
			data-name="<?php echo esc_attr( $row['name'] ); ?>"
			data-search="<?php echo esc_attr( $search_text ); ?>"
			data-attention="<?php echo $row['needs_attention'] ? '1' : '0'; ?>"
			data-has-update="<?php echo $has_update ? '1' : '0'; ?>">

			<td class="column-plugin" data-label="<?php esc_attr_e( 'Plugin', 'jezpress-manager' ); ?>">
				<div class="jezpress-plugin">
					<span class="jezpress-plugin-icon <?php echo $state && 'danger' === $state['tone'] ? 'is-danger' : ''; ?>">
						<span class="dashicons <?php echo esc_attr( $row['icon'] ); ?>" aria-hidden="true"></span>
					</span>
					<div class="jezpress-plugin-text">
						<div class="jezpress-plugin-title">
							<strong><?php echo esc_html( $row['name'] ); ?></strong>
							<?php if ( '' !== $row['version'] ) : ?>
								<code class="jezpress-version"><?php echo esc_html( $row['version'] ); ?></code>
							<?php endif; ?>
							<?php if ( $has_update ) : ?>
								<span class="jezpress-chip jezpress-chip-update">
									<span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span>
									<?php
									/* translators: %s: new version number */
									printf( esc_html__( '%s available', 'jezpress-manager' ), esc_html( $row['new_version'] ) );
									?>
								</span>
								<?php if ( '' !== $row['changelog_url'] ) : ?>
									<a href="<?php echo esc_url( $row['changelog_url'] ); ?>" class="thickbox jezpress-changelog-link" title="<?php echo esc_attr( $row['name'] ); ?>">
										<?php esc_html_e( 'What changed', 'jezpress-manager' ); ?>
									</a>
								<?php endif; ?>
							<?php elseif ( $row['installed'] && $row['active'] ) : ?>
								<span class="jezpress-chip jezpress-chip-ok"><?php esc_html_e( 'Up to date', 'jezpress-manager' ); ?></span>
							<?php elseif ( $row['registered'] && ! $row['installed'] ) : ?>
								<span class="jezpress-chip jezpress-chip-muted" title="<?php esc_attr_e( 'The plugin did not tell the Manager its plugin file, so updates cannot be tracked here.', 'jezpress-manager' ); ?>"><?php esc_html_e( 'Update status unknown', 'jezpress-manager' ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( '' !== $row['description'] ) : ?>
							<p class="jezpress-plugin-desc"><?php echo esc_html( $row['description'] ); ?></p>
						<?php endif; ?>
						<p class="jezpress-row-message" hidden></p>
					</div>
				</div>
			</td>

			<td class="column-license" data-label="<?php esc_attr_e( 'Licence', 'jezpress-manager' ); ?>">
				<?php if ( null === $state ) : ?>
					<span class="jezpress-na">&mdash;</span>
				<?php else : ?>
					<div class="jezpress-license jezpress-license-<?php echo esc_attr( $state['tone'] ); ?>">
						<span class="jezpress-license-label">
							<?php if ( 'ok' === $state['tone'] ) : ?>
								<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php elseif ( 'danger' === $state['tone'] ) : ?>
								<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php elseif ( 'warn' === $state['tone'] ) : ?>
								<span class="dashicons dashicons-clock" aria-hidden="true"></span>
							<?php endif; ?>
							<?php echo esc_html( $state['label'] ); ?>
						</span>
						<?php if ( '' !== $state['action'] && '' !== $row['settings_url'] ) : ?>
							<a href="<?php echo esc_url( $row['settings_url'] ); ?>" class="jezpress-license-action">
								<?php echo esc_html( $state['action'] ); ?>
								<span class="dashicons dashicons-external" aria-hidden="true"></span>
							</a>
						<?php elseif ( '' !== $state['detail'] ) : ?>
							<span class="jezpress-license-detail"><?php echo esc_html( $state['detail'] ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</td>

			<td class="column-status" data-label="<?php esc_attr_e( 'Status', 'jezpress-manager' ); ?>">
				<?php if ( $can_toggle && $row['installed'] ) : ?>
					<label class="jezpress-toggle" title="<?php echo $row['active'] ? esc_attr__( 'Deactivate plugin', 'jezpress-manager' ) : esc_attr__( 'Activate plugin', 'jezpress-manager' ); ?>">
						<input type="checkbox" class="jezpress-toggle-input"
							<?php checked( $row['active'] ); ?>
							data-plugin-file="<?php echo esc_attr( $row['plugin_file'] ); ?>"
							data-plugin-name="<?php echo esc_attr( $row['name'] ); ?>"
							data-nonce="<?php echo esc_attr( $toggle_nonce ); ?>">
						<span class="jezpress-toggle-slider" aria-hidden="true"></span>
						<span class="jezpress-toggle-text"><?php echo $row['active'] ? esc_html__( 'On', 'jezpress-manager' ) : esc_html__( 'Off', 'jezpress-manager' ); ?></span>
					</label>
				<?php else : ?>
					<span class="jezpress-status-static"><?php echo $row['active'] ? esc_html__( 'Active', 'jezpress-manager' ) : esc_html__( 'Inactive', 'jezpress-manager' ); ?></span>
				<?php endif; ?>
				<?php if ( 'unavailable' !== $row['auto_update'] ) : ?>
					<span class="jezpress-auto-state" data-auto-state="<?php echo esc_attr( $row['auto_update'] ); ?>">
						<?php
						if ( 'locked' === $row['auto_update'] ) {
							esc_html_e( 'Auto-updates locked', 'jezpress-manager' );
						} elseif ( $row['auto_update_on'] ) {
							esc_html_e( 'Auto-updates on', 'jezpress-manager' );
						} else {
							esc_html_e( 'Auto-updates off', 'jezpress-manager' );
						}
						?>
					</span>
				<?php endif; ?>
			</td>

			<td class="column-actions" data-label="<?php esc_attr_e( 'Actions', 'jezpress-manager' ); ?>">
				<div class="jezpress-actions">
					<?php if ( $has_update && $can_update ) : ?>
						<button type="button" class="button button-primary jezpress-btn jezpress-btn-primary jezpress-update-btn"
							data-plugin-file="<?php echo esc_attr( $row['plugin_file'] ); ?>"
							data-slug="<?php echo esc_attr( $row['update_slug'] ); ?>"
							data-name="<?php echo esc_attr( $row['name'] ); ?>">
							<?php esc_html_e( 'Update', 'jezpress-manager' ); ?>
						</button>
					<?php elseif ( '' !== $row['settings_url'] ) : ?>
						<a href="<?php echo esc_url( $row['settings_url'] ); ?>" class="button jezpress-btn jezpress-btn-secondary"><?php esc_html_e( 'Settings', 'jezpress-manager' ); ?></a>
					<?php endif; ?>

					<details class="jezpress-menu">
						<summary class="button jezpress-btn jezpress-btn-icon" aria-label="<?php esc_attr_e( 'More actions', 'jezpress-manager' ); ?>">
							<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
						</summary>
						<ul class="jezpress-menu-list">
							<?php if ( $has_update && $can_update && '' !== $row['settings_url'] ) : ?>
								<li><a href="<?php echo esc_url( $row['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'jezpress-manager' ); ?></a></li>
							<?php endif; ?>
							<?php if ( $has_update && '' !== $row['changelog_url'] ) : ?>
								<li>
									<a href="<?php echo esc_url( $row['changelog_url'] ); ?>" class="thickbox" title="<?php echo esc_attr( $row['name'] ); ?>">
										<?php
										/* translators: %s: new version number */
										printf( esc_html__( 'What changed in %s', 'jezpress-manager' ), esc_html( $row['new_version'] ) );
										?>
									</a>
								</li>
							<?php endif; ?>
							<?php if ( 'locked' === $row['auto_update'] ) : ?>
								<li class="jezpress-menu-note">
									<?php
									if ( '' !== $lock_reason ) {
										/* translators: %s: constant name or reason */
										printf( esc_html__( 'Auto-updates locked by %s', 'jezpress-manager' ), '<code>' . esc_html( $lock_reason ) . '</code>' );
									} else {
										esc_html_e( 'Auto-updates set by a filter', 'jezpress-manager' );
									}
									?>
								</li>
							<?php elseif ( 'unavailable' !== $row['auto_update'] && $can_auto ) : ?>
								<li>
									<button type="button" class="jezpress-menu-btn jezpress-auto-btn"
										data-plugin-file="<?php echo esc_attr( $row['plugin_file'] ); ?>"
										data-state="<?php echo $row['auto_update_on'] ? 'disable' : 'enable'; ?>"
										data-label-enable="<?php esc_attr_e( 'Enable auto-updates', 'jezpress-manager' ); ?>"
										data-label-disable="<?php esc_attr_e( 'Disable auto-updates', 'jezpress-manager' ); ?>">
										<?php echo $row['auto_update_on'] ? esc_html__( 'Disable auto-updates', 'jezpress-manager' ) : esc_html__( 'Enable auto-updates', 'jezpress-manager' ); ?>
									</button>
								</li>
							<?php endif; ?>
							<li>
								<a href="<?php echo esc_url( self_admin_url( 'plugins.php?plugin_status=all&s=' . rawurlencode( $row['name'] ) ) ); ?>">
									<?php esc_html_e( 'View on Plugins screen', 'jezpress-manager' ); ?>
								</a>
							</li>
						</ul>
					</details>
				</div>
			</td>
		</tr>
		<?php if ( $state && 'invalid' === $state['key'] ) : ?>
			<tr class="jezpress-row-note <?php echo $row['active'] ? 'is-active' : 'is-inactive'; ?> needs-attention is-danger" data-note-for="<?php echo esc_attr( $row['slug'] ); ?>" data-search="<?php echo esc_attr( $search_text ); ?>" data-attention="1">
				<td colspan="4">
					<div class="jezpress-inline-note">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: %s: plugin name */
							esc_html__( '%s reports its key as invalid. Keys are entered and verified on the plugin\'s own licence tab.', 'jezpress-manager' ),
							esc_html( $row['name'] )
						);
						?>
					</div>
				</td>
			</tr>
			<?php
		endif;
	}

	// ---- Assets ----

	/**
	 * Enqueue admin assets
	 *
	 * The menu icon style loads on every admin page (the menu is everywhere);
	 * the dashboard stylesheet and script load on the dashboard only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Menu icon styles - load on all admin pages.
		wp_register_style( 'jezpress-manager-menu', false, array(), JEZPRESS_MANAGER_VERSION );
		wp_enqueue_style( 'jezpress-manager-menu' );
		wp_add_inline_style( 'jezpress-manager-menu', $this->get_menu_icon_styles() );

		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'jezpress-manager-admin',
			JEZPRESS_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			JEZPRESS_MANAGER_VERSION
		);

		add_thickbox();

		$can_update = current_user_can( 'update_plugins' );
		$deps       = array( 'wp-util' );
		if ( $can_update ) {
			// Core's update client: supplies wp.updates.ajax() and the 'updates' nonce.
			wp_enqueue_script( 'updates' );
			$deps[] = 'updates';
		}

		wp_enqueue_script(
			'jezpress-manager-admin',
			JEZPRESS_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
			$deps,
			JEZPRESS_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'jezpress-manager-admin',
			'jezpressManager',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'checkNonce' => wp_create_nonce( 'jezpress_check_updates_nonce' ),
				'canUpdate'  => $can_update,
				'pluginsUrl' => self_admin_url( 'plugins.php?plugin_status=upgrade' ),
				'i18n'       => array(
					'activated'     => __( 'Plugin activated:', 'jezpress-manager' ),
					'deactivated'   => __( 'Plugin deactivated:', 'jezpress-manager' ),
					'on'            => __( 'On', 'jezpress-manager' ),
					'off'           => __( 'Off', 'jezpress-manager' ),
					'genericError'  => __( 'An error occurred. Please try again.', 'jezpress-manager' ),
					'networkError'  => __( 'Network error. Please try again.', 'jezpress-manager' ),
					'checking'      => __( 'Checking…', 'jezpress-manager' ),
					'checkDone'     => __( 'Check complete. Refreshing…', 'jezpress-manager' ),
					'updating'      => __( 'Updating…', 'jezpress-manager' ),
					'updateLabel'   => __( 'Update', 'jezpress-manager' ),
					/* translators: %s: plugin name */
					'updatingName'  => __( 'Updating %s…', 'jezpress-manager' ),
					/* translators: %s: plugin name */
					'updated'       => __( 'Updated %s.', 'jezpress-manager' ),
					'allDone'       => __( 'All updates finished. Refreshing…', 'jezpress-manager' ),
					/* translators: %s: error message from WordPress */
					'updateFailed'  => __( 'Update failed: %s', 'jezpress-manager' ),
					'fsCredentials' => __( 'This host needs filesystem credentials to update plugins.', 'jezpress-manager' ),
					'fsLink'        => __( 'Update from the Plugins screen', 'jezpress-manager' ),
					'autoOn'        => __( 'Auto-updates on', 'jezpress-manager' ),
					'autoOff'       => __( 'Auto-updates off', 'jezpress-manager' ),
					'autoEnabled'   => __( 'Auto-updates enabled.', 'jezpress-manager' ),
					'autoDisabled'  => __( 'Auto-updates disabled.', 'jezpress-manager' ),
					'copied'        => __( 'Site details copied to the clipboard.', 'jezpress-manager' ),
					'copyFailed'    => __( 'Could not copy. Select the text and copy it manually.', 'jezpress-manager' ),
				),
			)
		);
	}

	/**
	 * Get menu icon CSS for hover/active states
	 *
	 * @return string CSS styles.
	 */
	private function get_menu_icon_styles() {
		// Grey icon SVG (no fill, stroke only).
		$grey_svg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23a0a5aa' stroke-width='2'%3E%3Cpath d='M12 2L2 7l10 5 10-5-10-5z'/%3E%3Cpath d='M2 17l10 5 10-5'/%3E%3Cpath d='M2 12l10 5 10-5'/%3E%3C/svg%3E";

		// White icon SVG (no fill, stroke only).
		$white_svg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2'%3E%3Cpath d='M12 2L2 7l10 5 10-5-10-5z'/%3E%3Cpath d='M2 17l10 5 10-5'/%3E%3Cpath d='M2 12l10 5 10-5'/%3E%3C/svg%3E";

		return "
			#adminmenu .toplevel_page_jezpress-manager .wp-menu-image::before {
				content: '';
				display: block;
				width: 20px;
				height: 20px;
				background-image: url(\"{$grey_svg}\");
				background-repeat: no-repeat;
				background-position: center;
				background-size: 20px 20px;
				position: absolute;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
			}
			#adminmenu .toplevel_page_jezpress-manager:hover .wp-menu-image::before,
			#adminmenu .toplevel_page_jezpress-manager.wp-has-current-submenu .wp-menu-image::before,
			#adminmenu .toplevel_page_jezpress-manager.current .wp-menu-image::before {
				background-image: url(\"{$white_svg}\");
			}
			#adminmenu .toplevel_page_jezpress-manager .wp-menu-image {
				position: relative;
			}
		";
	}
}
