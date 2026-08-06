<?php
/**
 * Scoped API-key and OAuth token authentication.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Auth {
	public const SCOPE_READ   = 'read_only';
	public const SCOPE_EDITOR = 'editor';
	public const SCOPE_ADMIN  = 'admin';

	private string $current_scope = '';
	private string $current_token_id = '';
	private string $current_token_type = '';

	/** @return true|\WP_Error */
	public function authenticate_request( \WP_REST_Request $request ): bool|\WP_Error {
		$this->current_scope      = '';
		$this->current_token_id   = '';
		$this->current_token_type = '';

		$header     = trim( (string) $request->get_header( 'authorization' ) );
		$api_header = '';

		// First match wins: canonical header, then the deprecated header names
		// still emitted by clients configured before the plugin was renamed.
		foreach ( array( 'x-mindio-magic-mcp-key', 'x-magicmcp-key', 'x-flatsome-mcp-key' ) as $api_key_header ) {
			$api_header = trim( (string) $request->get_header( $api_key_header ) );
			if ( '' !== $api_header ) {
				break;
			}
		}
		$bearer_token = '';
		if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			$bearer_token = trim( $matches[1] );
		}

		$token = $api_header ?: $bearer_token;
		if ( '' !== $token && preg_match( '/^fm[po]_/', $token ) ) {
			$verified = $this->verify_access_token( $token );
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			wp_set_current_user( (int) $verified['user_id'] );
			$this->current_scope      = (string) $verified['scope'];
			$this->current_token_id   = (string) $verified['id'];
			$this->current_token_type = (string) $verified['type'];
			return true;
		}

		// WordPress Application Passwords, cookie auth, or an OAuth provider may
		// already have authenticated the request before the REST callback runs.
		if ( get_current_user_id() > 0 ) {
			$this->current_scope      = $this->highest_scope_for_user( get_current_user_id() );
			$this->current_token_id   = 'wordpress:' . get_current_user_id();
			$this->current_token_type = '' !== $bearer_token ? 'external_oauth' : 'wordpress';
			return true;
		}

		return new \WP_Error(
			'unauthorized',
			__( 'A valid API key, OAuth token, or WordPress Application Password is required.', 'mindio-magic-mcp' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Generate a long-lived API key. The raw credential is returned once.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create_api_key( int $user_id, string $scope, string $label ): array|\WP_Error {
		$scope = $this->normalize_scope( $scope );
		if ( ! $scope || ! get_user_by( 'id', $user_id ) ) {
			return new \WP_Error( 'invalid_key_parameters', __( 'The API-key user or scope is invalid.', 'mindio-magic-mcp' ) );
		}
		if ( ! $this->user_allows_scope( $user_id, $scope ) ) {
			return new \WP_Error( 'scope_exceeds_user', __( 'The selected user cannot be granted that scope.', 'mindio-magic-mcp' ) );
		}

		return $this->issue_access_token( $user_id, $scope, 'api_key', sanitize_text_field( $label ), 0, '' );
	}

	/**
	 * Issue a short-lived OAuth access token and rotating refresh token.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function issue_oauth_tokens( int $user_id, string $scope, string $client_id, string $resource ): array|\WP_Error {
		$scope = $this->normalize_scope( $scope );
		if ( ! $scope || ! $this->user_allows_scope( $user_id, $scope ) ) {
			return new \WP_Error( 'invalid_scope', __( 'The requested OAuth scope is not available to this user.', 'mindio-magic-mcp' ) );
		}

		$access = $this->issue_access_token( $user_id, $scope, 'oauth', 'OAuth: ' . $client_id, HOUR_IN_SECONDS, $client_id, $resource );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$refresh = $this->issue_refresh_token( $user_id, $scope, $client_id, $resource );
		return array(
			'access_token'  => $access['token'],
			'token_type'    => 'Bearer',
			'expires_in'    => HOUR_IN_SECONDS,
			'refresh_token' => $refresh,
			'scope'         => $scope,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rotate_refresh_token( string $token, string $client_id, string $requested_scope = '', string $resource = '' ): array|\WP_Error {
		$record = $this->verify_refresh_token( $token );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		if ( ! hash_equals( (string) $record['client_id'], $client_id ) ) {
			return new \WP_Error( 'invalid_grant', __( 'The refresh token does not belong to this client.', 'mindio-magic-mcp' ) );
		}
		$bound_resource = (string) ( $record['resource'] ?? '' );
		if (
			'' === $bound_resource || '' === $resource ||
			(
				! hash_equals( $bound_resource, $resource ) &&
				! hash_equals( self::canonicalize_resource_namespace( $bound_resource ), $resource )
			)
		) {
			return new \WP_Error( 'invalid_target', __( 'The refresh token resource does not match this MCP endpoint.', 'mindio-magic-mcp' ) );
		}

		// Rotation is also the migration point: tokens bound to the pre-rename
		// namespace are reissued against the canonical one.
		$bound_resource = $resource;

		$scope = $record['scope'];
		if ( '' !== $requested_scope ) {
			$requested_scope = $this->normalize_scope( $requested_scope );
			if ( ! $requested_scope || ! $this->scope_allows( (string) $record['scope'], $requested_scope ) ) {
				return new \WP_Error( 'invalid_scope', __( 'A refresh request cannot increase its original scope.', 'mindio-magic-mcp' ) );
			}
			$scope = $requested_scope;
		}

		$this->revoke_refresh_token( (string) $record['id'] );
		return $this->issue_oauth_tokens( (int) $record['user_id'], (string) $scope, $client_id, $bound_resource );
	}

	public function revoke_token( string $id ): bool {
		$tokens = $this->tokens();
		if ( ! isset( $tokens[ $id ] ) ) {
			return false;
		}
		unset( $tokens[ $id ] );
		$this->save_tokens( $tokens );
		return true;
	}

	/** @return array{access_tokens:int,refresh_tokens:int} */
	public function revoke_client_tokens( string $client_id ): array {
		$access_tokens = $this->tokens();
		$access_count  = 0;
		foreach ( $access_tokens as $id => $record ) {
			if ( hash_equals( $client_id, (string) ( $record['client_id'] ?? '' ) ) ) {
				unset( $access_tokens[ $id ] );
				++$access_count;
			}
		}
		$this->save_tokens( $access_tokens );

		$refresh_tokens = get_option( 'mindio_magic_mcp_refresh_tokens', array() );
		$refresh_tokens = is_array( $refresh_tokens ) ? $refresh_tokens : array();
		$refresh_count  = 0;
		foreach ( $refresh_tokens as $id => $record ) {
			if ( hash_equals( $client_id, (string) ( $record['client_id'] ?? '' ) ) ) {
				unset( $refresh_tokens[ $id ] );
				++$refresh_count;
			}
		}
		update_option( 'mindio_magic_mcp_refresh_tokens', $refresh_tokens, false );
		return array( 'access_tokens' => $access_count, 'refresh_tokens' => $refresh_count );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_tokens(): array {
		$output = array();
		foreach ( $this->tokens() as $record ) {
			if ( 'api_key' !== ( $record['type'] ?? '' ) ) {
				continue;
			}
			unset( $record['hash'] );
			$output[] = $record;
		}
		usort( $output, static fn( array $a, array $b ): int => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );
		return $output;
	}

	public function current_scope(): string {
		return $this->current_scope ?: self::SCOPE_READ;
	}

	public function current_token_id(): string {
		return $this->current_token_id;
	}

	public function current_token_type(): string {
		return $this->current_token_type;
	}

	public function current_identity(): string {
		if ( '' !== $this->current_token_id ) {
			return $this->current_token_id;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'ip:' . $ip;
	}

	public function scope_allows( string $granted, string $required ): bool {
		$rank = array(
			self::SCOPE_READ   => 10,
			self::SCOPE_EDITOR => 20,
			self::SCOPE_ADMIN  => 30,
		);
		return isset( $rank[ $granted ], $rank[ $required ] ) && $rank[ $granted ] >= $rank[ $required ];
	}

	public function normalize_scope( string $scope ): string {
		$scope = sanitize_key( trim( $scope ) );
		return in_array( $scope, array( self::SCOPE_READ, self::SCOPE_EDITOR, self::SCOPE_ADMIN ), true ) ? $scope : '';
	}

	public function highest_scope_for_user( int $user_id ): string {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return self::SCOPE_READ;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return self::SCOPE_ADMIN;
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return self::SCOPE_EDITOR;
		}
		return self::SCOPE_READ;
	}

	public function user_allows_scope( int $user_id, string $scope ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		return match ( $scope ) {
			self::SCOPE_ADMIN  => user_can( $user, 'manage_options' ),
			self::SCOPE_EDITOR => user_can( $user, 'edit_posts' ),
			self::SCOPE_READ   => user_can( $user, 'read' ),
			default            => false,
		};
	}

	/** @return array<string,mixed>|\WP_Error */
	private function issue_access_token( int $user_id, string $scope, string $type, string $label, int $ttl, string $client_id, string $resource = '' ): array|\WP_Error {
		$id     = bin2hex( random_bytes( 8 ) );
		$secret = Secret_Box::base64url_encode( random_bytes( 32 ) );
		$prefix = 'oauth' === $type ? 'fmo' : 'fmp';
		$raw    = $prefix . '_' . $id . '_' . $secret;
		$now    = time();
		$record = array(
			'id'         => $id,
			'type'       => $type,
			'label'      => $label ?: __( 'Unnamed key', 'mindio-magic-mcp' ),
			'user_id'    => $user_id,
			'scope'      => $scope,
			'hash'       => $this->token_hash( $secret ),
			'created_at' => gmdate( DATE_ATOM, $now ),
			'last_used'  => '',
			'expires_at' => $ttl > 0 ? $now + $ttl : 0,
			'client_id'  => $client_id,
			'resource'   => $resource,
		);

		$tokens        = $this->tokens();
		$tokens[ $id ] = $record;
		$this->save_tokens( $tokens );

		$public          = $record;
		$public['token'] = $raw;
		unset( $public['hash'] );
		return $public;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function verify_access_token( string $raw ): array|\WP_Error {
		if ( ! preg_match( '/^(fmp|fmo)_([a-f0-9]{16})_([A-Za-z0-9_-]{43})$/', $raw, $matches ) ) {
			return new \WP_Error( 'invalid_token', __( 'The access token is malformed.', 'mindio-magic-mcp' ), array( 'status' => 401 ) );
		}

		$tokens = $this->tokens();
		$id     = $matches[2];
		if ( ! isset( $tokens[ $id ] ) || ! hash_equals( (string) $tokens[ $id ]['hash'], $this->token_hash( $matches[3] ) ) ) {
			return new \WP_Error( 'invalid_token', __( 'The access token is invalid or revoked.', 'mindio-magic-mcp' ), array( 'status' => 401 ) );
		}

		$record = $tokens[ $id ];
		if ( ! empty( $record['expires_at'] ) && time() >= (int) $record['expires_at'] ) {
			unset( $tokens[ $id ] );
			$this->save_tokens( $tokens );
			return new \WP_Error( 'expired_token', __( 'The access token has expired.', 'mindio-magic-mcp' ), array( 'status' => 401 ) );
		}
		if ( ! get_user_by( 'id', (int) $record['user_id'] ) || ! $this->user_allows_scope( (int) $record['user_id'], (string) $record['scope'] ) ) {
			return new \WP_Error( 'invalid_token_user', __( 'The token user no longer has the required access.', 'mindio-magic-mcp' ), array( 'status' => 401 ) );
		}

		$last_used = ! empty( $record['last_used'] ) ? strtotime( (string) $record['last_used'] ) : 0;
		if ( ! $last_used || time() - $last_used > HOUR_IN_SECONDS ) {
			$record['last_used'] = gmdate( DATE_ATOM );
			$tokens[ $id ]       = $record;
			$this->save_tokens( $tokens );
		}

		return $record;
	}

	private function issue_refresh_token( int $user_id, string $scope, string $client_id, string $resource ): string {
		$id     = bin2hex( random_bytes( 8 ) );
		$secret = Secret_Box::base64url_encode( random_bytes( 32 ) );
		$raw    = 'fmr_' . $id . '_' . $secret;
		$tokens = get_option( 'mindio_magic_mcp_refresh_tokens', array() );
		$tokens = is_array( $tokens ) ? $tokens : array();
		$tokens[ $id ] = array(
			'id'         => $id,
			'hash'       => $this->token_hash( $secret ),
			'user_id'    => $user_id,
			'scope'      => $scope,
			'client_id'  => $client_id,
			'resource'   => $resource,
			'created_at' => time(),
			'expires_at' => time() + ( 30 * DAY_IN_SECONDS ),
		);
		update_option( 'mindio_magic_mcp_refresh_tokens', $tokens, false );
		return $raw;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function verify_refresh_token( string $raw ): array|\WP_Error {
		if ( ! preg_match( '/^fmr_([a-f0-9]{16})_([A-Za-z0-9_-]{43})$/', $raw, $matches ) ) {
			return new \WP_Error( 'invalid_grant', __( 'The refresh token is malformed.', 'mindio-magic-mcp' ) );
		}
		$tokens = get_option( 'mindio_magic_mcp_refresh_tokens', array() );
		$tokens = is_array( $tokens ) ? $tokens : array();
		$record = $tokens[ $matches[1] ] ?? null;
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['hash'], $this->token_hash( $matches[2] ) ) || time() >= (int) $record['expires_at'] ) {
			return new \WP_Error( 'invalid_grant', __( 'The refresh token is invalid, expired, or already used.', 'mindio-magic-mcp' ) );
		}
		return $record;
	}

	private function revoke_refresh_token( string $id ): void {
		$tokens = get_option( 'mindio_magic_mcp_refresh_tokens', array() );
		$tokens = is_array( $tokens ) ? $tokens : array();
		unset( $tokens[ $id ] );
		update_option( 'mindio_magic_mcp_refresh_tokens', $tokens, false );
	}

	/** @return array<string,array<string,mixed>> */
	private function tokens(): array {
		$tokens = get_option( 'mindio_magic_mcp_tokens', array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/** @param array<string,array<string,mixed>> $tokens */
	private function save_tokens( array $tokens ): void {
		update_option( 'mindio_magic_mcp_tokens', $tokens, false );
	}

	/**
	 * Rewrites the deprecated REST namespace in a stored resource URI to the canonical one.
	 */
	private static function canonicalize_resource_namespace( string $resource ): string {
		return str_replace(
			'/' . MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE . '/',
			'/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/',
			$resource
		);
	}

	/**
	 * The HMAC context string is frozen at its pre-rename value on purpose:
	 * changing it would invalidate every access token already issued.
	 */
	private function token_hash( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) . '|flatsome-mcp-token' );
	}
}
