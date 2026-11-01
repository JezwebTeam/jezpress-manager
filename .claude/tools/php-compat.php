<?php
/**
 * Lint every shipped file against the plugin's DECLARED minimum PHP version.
 *
 * This tool exists because of a critical defect found in another JezPress
 * plugin (jezpress-smtp 1.0.5), and the same trap applies here.
 *
 * That plugin's header said `Requires PHP: 8.1`, but fourteen files used the
 * `true` return type - `check_connection(): true|WP_Error` and friends - which
 * PHP only accepted from 8.2. On 8.1 every one of those files is a parse error
 * ("Cannot use 'true' as class name as it is reserved"), so loading fatals and
 * the site white-screens. The bootstrap's own version gate lets 8.1 through,
 * which makes it worse: the check that exists to prevent exactly this said yes.
 *
 * jezpress-manager declares `Requires PHP: 8.1` too, and is loaded on every
 * admin request of every site that has it - so a parse error here takes the
 * whole dashboard down.
 *
 * Local development ran PHP 8.5 and the verification site ran 8.2+, so nothing in
 * the pipeline ever executed the declared minimum. `php -l` on the development
 * version is not a compatibility check - it only proves a file parses on the one
 * version you happen to have installed.
 *
 * Usage:
 *   php .claude/tools/php-compat.php
 *   JEZPRESS_MANAGER_PHP_BIN=/path/to/php81 php .claude/tools/php-compat.php
 *
 * Exit codes: 0 pass, 1 a file does not parse, 2 the minimum's binary was not
 * found - which is NOT a pass, because an unavailable check must never read as
 * one.
 */

$root = dirname( __DIR__, 2 );

$header = (string) file_get_contents( $root . '/jezpress-manager.php' );

if ( ! preg_match( '/^\s*\*\s*Requires PHP:\s*([0-9]+\.[0-9]+)/m', $header, $match ) ) {
	fwrite( STDERR, "Could not read 'Requires PHP' from the plugin header." . PHP_EOL );
	exit( 1 );
}

$minimum = $match[1];

echo 'Declared minimum: PHP ' . $minimum . PHP_EOL;

/**
 * Find a PHP binary for the given version.
 *
 * @param string $version Version like '8.1'.
 * @return string|null
 */
function find_php( string $version ): ?string {
	$explicit = getenv( 'JEZPRESS_MANAGER_PHP_BIN' );

	if ( is_string( $explicit ) && '' !== $explicit && is_file( $explicit ) ) {
		return $explicit;
	}

	// php-multi keeps one directory per version; glob so a patch bump does not
	// need this tool edited.
	foreach ( array( 'C:/tools/php-multi', '/usr/local/php', '/opt/php' ) as $base ) {
		foreach ( (array) glob( $base . '/php-' . $version . '.*', GLOB_ONLYDIR ) as $dir ) {
			foreach ( array( '/php.exe', '/bin/php', '/php' ) as $suffix ) {
				if ( is_file( $dir . $suffix ) ) {
					return $dir . $suffix;
				}
			}
		}
	}

	foreach ( array( 'php' . $version, 'php' . str_replace( '.', '', $version ) ) as $name ) {
		$found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

		if ( '' !== $found ) {
			return $found;
		}
	}

	return null;
}

$binary = find_php( $minimum );

if ( null === $binary ) {
	echo PHP_EOL . 'No PHP ' . $minimum . ' binary found, so COMPATIBILITY WAS NOT CHECKED.' . PHP_EOL . PHP_EOL;
	echo 'Install it, or point JEZPRESS_MANAGER_PHP_BIN at one:' . PHP_EOL;
	echo '  JEZPRESS_MANAGER_PHP_BIN=/path/to/php' . $minimum . ' php .claude/tools/php-compat.php' . PHP_EOL . PHP_EOL;
	echo 'Not treating this as a pass - shipping 8.2-only syntax under an 8.1' . PHP_EOL;
	echo 'declaration white-screens every site on the declared minimum.' . PHP_EOL;

	exit( 2 );
}

$actual = trim( (string) shell_exec( escapeshellarg( $binary ) . ' -r "echo PHP_VERSION;" 2>&1' ) );

echo 'Linting with: ' . $binary . ' (' . $actual . ')' . PHP_EOL . PHP_EOL;

// Only what ships. .claude/ and the ZIP's other exclusions are dev-only and
// never run on a customer's PHP.
$skip  = array( '/.git/', '/.github/', '/.claude/', '/node_modules/' );
$files = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $entry ) {
	$path = strtr( $entry->getPathname(), DIRECTORY_SEPARATOR, '/' );

	if ( '.php' !== substr( $path, -4 ) ) {
		continue;
	}

	foreach ( $skip as $fragment ) {
		if ( false !== strpos( $path, $fragment ) ) {
			continue 2;
		}
	}

	$files[] = $path;
}

sort( $files );

$failures = array();
$base     = strtr( $root, DIRECTORY_SEPARATOR, '/' );

foreach ( $files as $path ) {
	$output = (string) shell_exec( escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1' );

	if ( false === strpos( $output, 'No syntax errors' ) ) {
		$relative = ltrim( str_replace( $base, '', $path ), '/' );

		$failures[ $relative ] = trim( (string) preg_replace( '/\s+/', ' ', $output ) );
	}
}

echo 'Checked ' . count( $files ) . ' shipped file(s).' . PHP_EOL . PHP_EOL;

if ( array() === $failures ) {
	echo 'PHP ' . $minimum . ' COMPATIBILITY OK' . PHP_EOL;
	exit( 0 );
}

echo count( $failures ) . ' FILE(S) DO NOT PARSE ON THE DECLARED MINIMUM:' . PHP_EOL;

foreach ( $failures as $relative => $error ) {
	echo ' - ' . $relative . PHP_EOL . '   ' . $error . PHP_EOL;
}

echo PHP_EOL . "Either fix the syntax or raise 'Requires PHP' - but raising it excludes" . PHP_EOL;
echo 'sites, so fixing the syntax is almost always right.' . PHP_EOL;

exit( 1 );
