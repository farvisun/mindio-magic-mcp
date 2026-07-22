<?php
/**
 * Static regressions for WordPress.org review findings.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$root  = dirname( __DIR__ );
$files = array( $root . '/flatsome-mcp.php', $root . '/uninstall.php' );
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}

$forbidden = array(
	'/FlatsomeMCP|FLATSOME_MCP|flatsome_mcp/' => 'legacy generic global prefix',
	'/[\'\"]fmp_rl_/'                       => 'short rate-limit transient prefix',
	'/[\'\"]magicmcp[\'\"]/'              => 'generic plugin identifier',
	'/rank-math-options-titles/'              => 'direct Rank Math option access',
	'/run_safe_query/'                        => 'request-supplied SQL tool',
	'/\$wpdb->get_results\(\s*\$sql\b/'      => 'unprepared dynamic SQL execution',
);

foreach ( $files as $file ) {
	$contents = file_get_contents( $file );
	if ( false === $contents ) {
		throw new RuntimeException( 'Could not read ' . $file );
	}
	foreach ( $forbidden as $pattern => $label ) {
		if ( preg_match( $pattern, $contents ) ) {
			throw new RuntimeException( $label . ' found in ' . str_replace( $root . '/', '', $file ) );
		}
	}
}

echo "WordPress.org review regressions: OK\n";
