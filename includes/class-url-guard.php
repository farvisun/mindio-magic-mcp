<?php
/**
 * Outbound URL validation for media and webhook requests.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class URL_Guard {
	/** @return true|\WP_Error */
	public static function validate( string $url, bool $require_https = true ): bool|\WP_Error {
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( ! $url || ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'invalid_url', __( 'The URL is invalid.', 'mindio-magic-mcp' ) );
		}
		if ( $require_https && 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new \WP_Error( 'https_required', __( 'Only HTTPS URLs are allowed.', 'mindio-magic-mcp' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'url_credentials_forbidden', __( 'URLs containing credentials are not allowed.', 'mindio-magic-mcp' ) );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return new \WP_Error( 'private_url', __( 'Local and private network URLs are not allowed.', 'mindio-magic-mcp' ) );
		}

		$ips = array();
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} elseif ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS warnings are converted to a validation failure.
			foreach ( (array) $records as $record ) {
				if ( isset( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				}
				if ( isset( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		if ( empty( $ips ) ) {
			return new \WP_Error( 'unresolvable_url', __( 'The URL host could not be resolved.', 'mindio-magic-mcp' ) );
		}
		foreach ( array_unique( $ips ) as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new \WP_Error( 'private_url', __( 'Local and private network URLs are not allowed.', 'mindio-magic-mcp' ) );
			}
		}

		return true;
	}
}
