<?php
/**
 * Plugin Name: JezPress staging-check helper
 * Description: For the /release staging compatibility check (wp-plugin-template .claude/scripts/staging_check.py). Administrators only. ?jezsmoke_sentinel=<token> writes one marker line to the debug log; ?jezsmoke_log=<token>&slug=<slug>&domain=<text-domain> returns, as JSON, only the debug-log entries between that run's start and end markers that belong to the plugin under test, plus every fatal error. Read-only otherwise. Safe to delete.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_GET['jezsmoke_sentinel'] ) ) {
		error_log( 'jezsmoke-sentinel ' . sanitize_key( wp_unslash( $_GET['jezsmoke_sentinel'] ) ) . ' ' . gmdate( 'c' ) );
		return;
	}

	// Is the plugin under test actually ACTIVE? Installed files alone prove nothing.
	if ( isset( $_GET['jezsmoke_active'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$slug   = sanitize_key( wp_unslash( $_GET['jezsmoke_active'] ) );
		$active = false;
		$file   = '';
		foreach ( array_keys( get_plugins() ) as $candidate ) {
			if ( 0 === strpos( $candidate, $slug . '/' ) ) {
				$file   = $candidate;
				$active = is_plugin_active( $candidate );
				break;
			}
		}
		wp_send_json( array( 'installed' => '' !== $file, 'active' => $active, 'file' => $file ) );
	}

	if ( ! isset( $_GET['jezsmoke_log'] ) ) {
		return;
	}
	$token  = sanitize_key( wp_unslash( $_GET['jezsmoke_log'] ) );
	$slug   = sanitize_key( wp_unslash( $_GET['slug'] ?? '' ) );
	$domain = sanitize_key( wp_unslash( $_GET['domain'] ?? '' ) );
	$path   = ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
	// `counts` are complete even when `entries` is capped, so a failure past
	// the cap still fails the run.
	$out    = array(
		'start'     => false,
		'end'       => false,
		'entries'   => array(),
		'other'     => 0,
		'truncated' => false,
		'counts'    => array( 'mine_fail' => 0, 'mine_other' => 0, 'foreign_fatal' => 0 ),
	);

	if ( '' === $token || '' === $slug || ! is_readable( $path ) ) {
		$out['error'] = '' === $slug ? 'slug required' : 'debug log not readable';
		wp_send_json( $out );
	}

	// Read the tail only: a run on this noisy site writes ~1 MB.
	$size = (int) filesize( $path );
	$fh   = fopen( $path, 'rb' );
	fseek( $fh, max( 0, $size - 16 * MB_IN_BYTES ) );
	$data = (string) stream_get_contents( $fh );
	fclose( $fh );

	$start = strpos( $data, "jezsmoke-sentinel {$token}start" );
	$end   = strpos( $data, "jezsmoke-sentinel {$token}end" );
	$out['start'] = false !== $start;
	$out['end']   = false !== $end && false !== $start && $end > $start;
	if ( ! $out['end'] ) {
		wp_send_json( $out );
	}

	$slice    = substr( $data, $start, $end - $start );
	$failures = array();
	$others   = array();
	foreach ( preg_split( '/\n(?=\[)/', $slice ) as $entry ) {
		if ( '' === trim( $entry ) || false !== strpos( $entry, 'jezsmoke-sentinel' ) ) {
			continue;
		}
		$mine  = false !== strpos( $entry, "/plugins/{$slug}/" )
			|| ( '' !== $domain && false !== strpos( $entry, "the <code>{$domain}</code> domain" ) );
		$fatal = (bool) preg_match( '/PHP (Fatal error|Parse error|Recoverable fatal error)/', $entry );
		$warn  = (bool) preg_match( '/PHP Warning/', $entry );
		if ( $mine && ( $fatal || $warn ) ) {
			++$out['counts']['mine_fail'];
			$failures[] = $entry;
		} elseif ( $fatal ) {
			++$out['counts']['foreign_fatal'];
			$failures[] = $entry;
		} elseif ( $mine ) {
			++$out['counts']['mine_other'];
			$others[] = $entry;
		} else {
			++$out['other'];
		}
	}
	// Failures first, so the cap can only ever drop notices.
	foreach ( array_merge( $failures, $others ) as $entry ) {
		if ( count( $out['entries'] ) >= 300 ) {
			$out['truncated'] = true;
			break;
		}
		$out['entries'][] = substr( $entry, 0, 2000 );
	}
	wp_send_json( $out );
} );
