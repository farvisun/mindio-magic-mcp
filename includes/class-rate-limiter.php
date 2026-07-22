<?php
/**
 * Fixed-window request rate limiter.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rate_Limiter {
	/**
	 * @return array{allowed:bool,limit:int,remaining:int,retry_after:int}
	 */
	public function consume( string $identity, string $bucket = 'mcp' ): array {
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		$limit    = max( 5, min( 1000, absint( $settings['rate_limit'] ?? 60 ) ) );
		$window   = 60;
		$slot     = (int) floor( time() / $window );
		$key      = 'mindio_magic_mcp_rate_limit_' . md5( $bucket . '|' . $identity . '|' . $slot );
		$count    = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, $window + 5 );

		return array(
			'allowed'     => $count <= $limit,
			'limit'       => $limit,
			'remaining'   => max( 0, $limit - $count ),
			'retry_after' => max( 1, $window - ( time() % $window ) ),
		);
	}
}
