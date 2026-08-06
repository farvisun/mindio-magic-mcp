<?php
/**
 * Translation catalog completeness checks.
 *
 * Run with php tests/integration/localization.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

/** @throws RuntimeException */
function mindio_localization_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Parse the small PO surface needed by this integration check.
 *
 * @return array<int, array{fields: array<string, string>, fuzzy: bool}>
 * @throws RuntimeException When a quoted PO value cannot be decoded.
 */
function mindio_parse_po( string $path ): array {
	$lines   = file( $path, FILE_IGNORE_NEW_LINES );
	$entries = array();
	$fields  = array();
	$current = null;
	$fuzzy   = false;
	$obsolete = false;

	mindio_localization_assert( false !== $lines, 'Translation catalog could not be read: ' . $path );

	$flush = static function () use ( &$entries, &$fields, &$current, &$fuzzy, &$obsolete ): void {
		if ( ! $obsolete && array_key_exists( 'msgid', $fields ) ) {
			$entries[] = array(
				'fields' => $fields,
				'fuzzy'  => $fuzzy,
			);
		}

		$fields   = array();
		$current  = null;
		$fuzzy    = false;
		$obsolete = false;
	};

	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			$flush();
			continue;
		}

		if ( str_starts_with( $line, '#~' ) ) {
			$obsolete = true;
			continue;
		}

		if ( str_starts_with( $line, '#,' ) && str_contains( $line, 'fuzzy' ) ) {
			$fuzzy = true;
			continue;
		}

		if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+(".*")$/', $line, $matches ) ) {
			$current = $matches[1];
			$value   = json_decode( $matches[2], true );
			mindio_localization_assert( is_string( $value ), 'Invalid PO value in ' . $path . ': ' . $line );
			$fields[ $current ] = $value;
			continue;
		}

		if ( null !== $current && preg_match( '/^(".*")$/', $line, $matches ) ) {
			$value = json_decode( $matches[1], true );
			mindio_localization_assert( is_string( $value ), 'Invalid PO continuation in ' . $path . ': ' . $line );
			$fields[ $current ] .= $value;
		}
	}

	$flush();

	return $entries;
}

$plugin_root = dirname( __DIR__, 2 );
$pot_entries = mindio_parse_po( $plugin_root . '/languages/mindio-magic-mcp.pot' );
$po_entries  = mindio_parse_po( $plugin_root . '/languages/mindio-magic-mcp-fa_IR.po' );

$source_keys = array();
foreach ( $pot_entries as $entry ) {
	$msgid = $entry['fields']['msgid'];
	if ( '' !== $msgid ) {
		$source_keys[] = ( $entry['fields']['msgctxt'] ?? '' ) . "\x04" . $msgid;
	}
}

$translated_keys = array();
foreach ( $po_entries as $entry ) {
	$msgid = $entry['fields']['msgid'];
	if ( '' === $msgid ) {
		continue;
	}

	mindio_localization_assert( ! $entry['fuzzy'], 'Fuzzy Persian translation remains: ' . $msgid );

	$translations = array_filter(
		$entry['fields'],
		static fn ( string $key ): bool => str_starts_with( $key, 'msgstr' ),
		ARRAY_FILTER_USE_KEY
	);

	mindio_localization_assert( ! empty( $translations ), 'Persian translation is missing: ' . $msgid );
	foreach ( $translations as $translation ) {
		mindio_localization_assert( '' !== trim( $translation ), 'Persian translation is empty: ' . $msgid );
	}

	$translated_keys[] = ( $entry['fields']['msgctxt'] ?? '' ) . "\x04" . $msgid;
}

sort( $source_keys );
sort( $translated_keys );
mindio_localization_assert( $source_keys === $translated_keys, 'The Persian catalog does not exactly match the source catalog.' );

echo json_encode(
	array(
		'ok'                  => true,
		'translated_messages' => count( $translated_keys ),
		'fuzzy_messages'      => 0,
	),
	JSON_UNESCAPED_SLASHES
) . PHP_EOL;
