<?php
/**
 * Generate languages/jezpress-manager.pot.
 *
 * Uses token_get_all() and walks each gettext call's argument list. A
 * regex/line-based extractor must NOT be used here: it skips any call whose
 * text domain sits on a different line (so every multi-line `_n()` produces no
 * entry), and it copies string literals into msgid raw, so a translatable
 * containing a double quote lands unescaped and makes the whole file fail
 * `msgfmt --check` - at which point nothing in the plugin is translatable. Both
 * failures are silent; the extractor still prints a healthy string count.
 *
 * Usage: php .claude/tools/make-pot.php
 */

$root   = dirname( __DIR__, 2 );
$text_domain = 'jezpress-manager';
$name   = 'JezPress Manager';

/*
 * Read from the plugin header rather than hardcoded.
 *
 * release.yml gates on the .pot's Project-Id-Version matching the tag, and a
 * hardcoded version here silently regenerates the .pot with the OLD number on
 * every release - failing the gate, or worse, passing it while shipping a stale
 * .pot. The header is the single source of truth.
 */
if ( ! preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', (string) file_get_contents( $root . '/' . $text_domain . '.php' ), $version_match ) ) {
	fwrite( STDERR, "Could not read Version from the plugin header.\n" );
	exit( 1 );
}

$version = $version_match[1];

// function name => [ index of singular, index of plural or null, index of the
// text domain ]. Anything not listed is not extracted.
$functions = array(
	'__'      => array( 0, null, 1 ),
	'_e'      => array( 0, null, 1 ),
	'esc_html__' => array( 0, null, 1 ),
	'esc_html_e' => array( 0, null, 1 ),
	'esc_attr__' => array( 0, null, 1 ),
	'esc_attr_e' => array( 0, null, 1 ),
	'_x'      => array( 0, null, 2 ),
	'esc_html_x' => array( 0, null, 2 ),
	'esc_attr_x' => array( 0, null, 2 ),
	'_n'      => array( 0, 1, 3 ),
	'_nx'     => array( 0, 1, 4 ),
);

$files = array();

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

foreach ( $iterator as $entry ) {
	$path = str_replace( '\\', '/', $entry->getPathname() );

	if ( substr( $path, -4 ) !== '.php' ) {
		continue;
	}

	foreach ( array( '/.git/', '/.github/', '/.claude/', '/node_modules/' ) as $skip ) {
		if ( strpos( $path, $skip ) !== false ) {
			continue 2;
		}
	}

	$files[] = $path;
}

sort( $files );

$entries = array();

foreach ( $files as $path ) {
	$tokens   = token_get_all( file_get_contents( $path ) );
	$relative = ltrim( str_replace( str_replace( '\\', '/', $root ), '', $path ), '/' );
	$count    = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
			continue;
		}

		// A method call or a property named the same thing is not a gettext
		// call. Look back past whitespace for -> or :: or function.
		$back = $i - 1;

		while ( $back >= 0 && is_array( $tokens[ $back ] ) && T_WHITESPACE === $tokens[ $back ][0] ) {
			--$back;
		}

		if ( $back >= 0 && is_array( $tokens[ $back ] )
			&& in_array( $tokens[ $back ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true )
		) {
			continue;
		}

		// Next non-whitespace token must be the opening parenthesis.
		$j = $i + 1;

		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}

		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}

		$function = $token[1];
		$line     = $token[2];

		// Walk the argument list at depth 1, collecting each argument's
		// literal string value when it is a single unconcatenated literal.
		$args      = array();
		$current   = null;
		$is_simple = true;
		$depth     = 0;

		for ( $k = $j; $k < $count; $k++ ) {
			$t = $tokens[ $k ];

			if ( '(' === $t ) {
				++$depth;

				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $t ) {
				--$depth;

				if ( 0 === $depth ) {
					$args[] = $is_simple ? $current : null;
					break;
				}
			}

			if ( 1 === $depth && ',' === $t ) {
				$args[]    = $is_simple ? $current : null;
				$current   = null;
				$is_simple = true;
				continue;
			}

			if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
				continue;
			}

			if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				if ( null === $current ) {
					// Resolve the PHP literal to its actual value, so an escaped
					// quote inside a single-quoted string becomes a real quote
					// rather than a backslash sequence in the msgid.
					$current = stripcslashes_literal( $t[1] );
				} else {
					// Concatenation of two literals: still resolvable.
					$current .= stripcslashes_literal( $t[1] );
				}

				continue;
			}

			if ( is_array( $t ) && T_COMMENT === $t[0] ) {
				continue;
			}

			// Anything else in this argument (a variable, a function call, a
			// constant) means the value is not statically known.
			if ( '.' !== $t ) {
				$is_simple = false;
			}
		}

		list( $singular_index, $plural_index, $domain_index ) = $functions[ $function ];

		$found_domain = $args[ $domain_index ] ?? null;

		if ( $found_domain !== $text_domain ) {
			continue;
		}

		$singular = $args[ $singular_index ] ?? null;

		if ( null === $singular || '' === $singular ) {
			continue;
		}

		$plural  = null === $plural_index ? null : ( $args[ $plural_index ] ?? null );
		$context = null;

		if ( '_x' === $function || 'esc_html_x' === $function || 'esc_attr_x' === $function ) {
			$context = $args[1] ?? null;
		} elseif ( '_nx' === $function ) {
			$context = $args[3] ?? null;
		}

		// Look back for an immediately preceding translators comment.
		$comment = '';
		$c       = $i - 1;

		while ( $c >= 0 ) {
			$t = $tokens[ $c ];

			if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
				--$c;
				continue;
			}

			if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true )
				&& stripos( $t[1], 'translators:' ) !== false
			) {
				$comment = trim( preg_replace( '#^/\*+|\*+/$|^//#', '', $t[1] ) ?? '' );
			}

			break;
		}

		$key = ( null === $context ? '' : $context . "\x04" ) . $singular . "\x00" . (string) $plural;

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'singular'  => $singular,
				'plural'    => $plural,
				'context'   => $context,
				'comment'   => $comment,
				'locations' => array(),
			);
		}

		if ( '' !== $comment && '' === $entries[ $key ]['comment'] ) {
			$entries[ $key ]['comment'] = $comment;
		}

		$entries[ $key ]['locations'][] = $relative . ':' . $line;
	}
}

/**
 * Resolve a PHP string literal token to its actual value.
 *
 * @param string $literal The raw token text, including its quotes.
 * @return string
 */
function stripcslashes_literal( string $literal ): string {
	$quote = $literal[0];
	$inner = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
	}

	return stripcslashes( $inner );
}

/**
 * Escape a value for a .pot msgid.
 *
 * @param string $value Value to escape.
 * @return string
 */
function pot_escape( string $value ): string {
	return str_replace(
		array( '\\', '"', "\t", "\r", "\n" ),
		array( '\\\\', '\"', '\t', '\r', '\n' ),
		$value
	);
}

ksort( $entries );

$out  = "# Copyright (C) " . gmdate( 'Y' ) . " Jezweb\n";
$out .= "# This file is distributed under the GPL-2.0+ license.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: {$name} {$version}\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://jezpress.com/support\\n\"\n";
$out .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i:sO' ) . "\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
// Without this header, plural entries are undefined behaviour in every consumer.
$out .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
$out .= "\"X-Domain: {$text_domain}\\n\"\n\n";

$plurals = 0;

foreach ( $entries as $entry ) {
	if ( '' !== $entry['comment'] ) {
		$out .= '#. ' . trim( $entry['comment'] ) . "\n";
	}

	foreach ( array_unique( $entry['locations'] ) as $location ) {
		$out .= '#: ' . $location . "\n";
	}

	if ( null !== $entry['context'] ) {
		$out .= 'msgctxt "' . pot_escape( $entry['context'] ) . "\"\n";
	}

	$out .= 'msgid "' . pot_escape( $entry['singular'] ) . "\"\n";

	if ( null !== $entry['plural'] ) {
		++$plurals;
		$out .= 'msgid_plural "' . pot_escape( $entry['plural'] ) . "\"\n";
		$out .= "msgstr[0] \"\"\n";
		$out .= "msgstr[1] \"\"\n\n";
		continue;
	}

	$out .= "msgstr \"\"\n\n";
}

if ( ! is_dir( $root . '/languages' ) ) {
	mkdir( $root . '/languages', 0755, true );
}

file_put_contents( $root . '/languages/' . $text_domain . '.pot', $out );

echo 'Wrote languages/' . $text_domain . ".pot\n";
echo 'Entries: ' . count( $entries ) . ' (' . $plurals . " plural)\n";
echo 'Files scanned: ' . count( $files ) . "\n";

// Self-check: every msgid line must have balanced, escaped quotes. This is the
// cheap stand-in for `msgfmt --check` where gettext is not installed.
$bad = 0;

foreach ( explode( "\n", $out ) as $number => $line ) {
	if ( ! preg_match( '/^(msgid|msgid_plural|msgctxt|msgstr(\[\d\])?) "(.*)"$/', $line, $m ) ) {
		if ( preg_match( '/^(msgid|msgid_plural|msgctxt|msgstr)/', $line ) ) {
			echo 'MALFORMED line ' . ( $number + 1 ) . ': ' . $line . "\n";
			++$bad;
		}

		continue;
	}

	// An unescaped double quote inside the value would have terminated it early.
	if ( preg_match( '/(?<!\\\\)"/', $m[3] ) ) {
		echo 'UNESCAPED QUOTE line ' . ( $number + 1 ) . ': ' . $line . "\n";
		++$bad;
	}
}

if ( $bad > 0 ) {
	echo "\n{$bad} MALFORMED ENTRIES - the .pot will not compile.\n";
	exit( 1 );
}

echo "Validation passed: all entries well-formed.\n";
