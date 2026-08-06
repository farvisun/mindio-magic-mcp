<?php
/**
 * Encryption for webhook signing secrets at rest.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Secret_Box {
	public static function encrypt( string $plaintext ): string {
		$key = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return 's1:' . self::base64url_encode( $nonce . $cipher );
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'No supported authenticated-encryption extension is available.' );
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'Unable to encrypt a webhook secret.' );
		}
		return 'o1:' . self::base64url_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( string $encoded ): string {
		if ( str_starts_with( $encoded, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw   = self::base64url_decode( substr( $encoded, 3 ) );
			if ( strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
			return false === $plain ? '' : $plain;
		}
		if ( str_starts_with( $encoded, 'o1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw   = self::base64url_decode( substr( $encoded, 3 ) );
			if ( strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv    = substr( $raw, 0, 12 );
			$tag   = substr( $raw, 12, 16 );
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	public static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	public static function base64url_decode( string $value ): string {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}

	private static function key(): string {
		// Frozen at its pre-rename value on purpose: changing this context string
		// would make every already-stored encrypted secret undecryptable.
		return hash( 'sha256', wp_salt( 'auth' ) . '|flatsome-mcp-secret-box', true );
	}
}
