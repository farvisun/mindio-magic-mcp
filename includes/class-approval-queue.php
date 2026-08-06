<?php
/**
 * Human approval gate for high-risk tool calls.
 *
 * When a tool matches the configured approval patterns, the call is parked as a
 * pending request instead of executing. A human approves or rejects it in the
 * admin console, and the agent replays the call with the returned approval ID.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Approval_Queue {
	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_EXECUTED = 'executed';

	/**
	 * Tools gated by default when approvals are switched on but no patterns are set.
	 *
	 * @return array<int,string>
	 */
	public static function default_patterns(): array {
		return array(
			'delete_*',
			'install_*',
			'update_settings',
			'update_plugin',
			'update_theme',
			'switch_theme',
			'run_wp_cli',
			'revert_changeset',
		);
	}

	/**
	 * Whether this tool call needs a human decision before it runs.
	 */
	public function requires_approval( string $tool ): bool {
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		if ( empty( $settings['approvals_enabled'] ) ) {
			return false;
		}

		$patterns = (array) ( $settings['approval_tools'] ?? array() );
		if ( ! $patterns ) {
			$patterns = self::default_patterns();
		}

		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( $pattern === $tool ) {
				return true;
			}
			if ( str_ends_with( $pattern, '*' ) && str_starts_with( $tool, substr( $pattern, 0, -1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Park a call for review and return the pending record.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	public function request( string $tool, array $arguments, Auth $auth ): array {
		global $wpdb;

		$settings   = get_option( 'mindio_magic_mcp_settings', array() );
		$ttl_hours  = max( 1, min( 720, absint( $settings['approval_ttl_hours'] ?? 72 ) ) );
		$request_id = 'ap_' . bin2hex( random_bytes( 8 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned approval table.
		$wpdb->insert(
			Installer::approval_table(),
			array(
				'request_id'      => $request_id,
				'tool'            => sanitize_key( $tool ),
				'arguments'       => (string) wp_json_encode( $arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'arguments_hash'  => self::hash_arguments( $arguments ),
				'status'          => self::STATUS_PENDING,
				'requested_by'    => get_current_user_id(),
				'token_id'        => $auth->current_token_id(),
				'created_at'      => current_time( 'mysql', true ),
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + ( $ttl_hours * HOUR_IN_SECONDS ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return (array) $this->get( $request_id );
	}

	/**
	 * Validate an approval token supplied on a replayed call.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>|\WP_Error
	 */
	public function claim( string $request_id, string $tool, array $arguments ) {
		$record = $this->get( $request_id );
		if ( ! $record ) {
			return new \WP_Error( 'unknown_approval', __( 'Unknown approval request.', 'mindio-magic-mcp' ) );
		}
		if ( $record['tool'] !== $tool ) {
			return new \WP_Error( 'approval_mismatch', __( 'This approval was issued for a different tool.', 'mindio-magic-mcp' ) );
		}
		if ( self::STATUS_EXECUTED === $record['status'] ) {
			return new \WP_Error( 'approval_spent', __( 'This approval has already been used.', 'mindio-magic-mcp' ) );
		}
		if ( self::STATUS_REJECTED === $record['status'] ) {
			return new \WP_Error( 'approval_rejected', __( 'This request was rejected by an administrator.', 'mindio-magic-mcp' ) );
		}
		if ( self::STATUS_PENDING === $record['status'] ) {
			return new \WP_Error( 'approval_pending', __( 'This request is still awaiting a human decision.', 'mindio-magic-mcp' ) );
		}
		if ( $this->is_expired( $record ) ) {
			return new \WP_Error( 'approval_expired', __( 'This approval expired before it was used.', 'mindio-magic-mcp' ) );
		}
		if ( ! hash_equals( (string) $record['arguments_hash'], self::hash_arguments( $arguments ) ) ) {
			return new \WP_Error( 'approval_mismatch', __( 'The call arguments differ from the ones that were approved.', 'mindio-magic-mcp' ) );
		}

		return $record;
	}

	public function mark_executed( string $request_id ): void {
		$this->set_status( $request_id, self::STATUS_EXECUTED );
	}

	public function approve( string $request_id ): bool {
		return $this->decide( $request_id, self::STATUS_APPROVED );
	}

	public function reject( string $request_id ): bool {
		return $this->decide( $request_id, self::STATUS_REJECTED );
	}

	/** @return array<string,mixed>|null */
	public function get( string $request_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Approval state must be read live.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %s', Installer::approval_table(), $request_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_requests( string $status = '', int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );
		$valid = array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_EXECUTED );

		if ( in_array( $status, $valid, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Approval state must be read live.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', Installer::approval_table(), $status, $limit ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Approval state must be read live.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Installer::approval_table(), $limit ),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'normalize' ), (array) $rows );
	}

	public function pending_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Badge count must be live.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', Installer::approval_table(), self::STATUS_PENDING )
		);
	}

	/** @param array<string,mixed> $arguments */
	public static function hash_arguments( array $arguments ): string {
		unset( $arguments['approval'], $arguments['dry_run'] );
		ksort( $arguments );

		return hash( 'sha256', (string) wp_json_encode( $arguments ) );
	}

	/** @param array<string,mixed> $record */
	private function is_expired( array $record ): bool {
		$expires = (string) ( $record['expires_at'] ?? '' );

		return '' !== $expires && strtotime( $expires . ' UTC' ) < time();
	}

	private function decide( string $request_id, string $status ): bool {
		$record = $this->get( $request_id );
		if ( ! $record || self::STATUS_PENDING !== $record['status'] ) {
			return false;
		}

		return $this->set_status( $request_id, $status );
	}

	private function set_status( string $request_id, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned approval table.
		return (bool) $wpdb->update(
			Installer::approval_table(),
			array(
				'status'     => $status,
				'decided_at' => current_time( 'mysql', true ),
				'decided_by' => get_current_user_id(),
			),
			array( 'request_id' => $request_id ),
			array( '%s', '%s', '%d' ),
			array( '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['requested_by'] = (int) $row['requested_by'];
		$row['decided_by']   = (int) $row['decided_by'];
		$row['arguments']    = json_decode( (string) $row['arguments'], true ) ?: array();
		$row['expired']      = $this->is_expired( $row );

		return $row;
	}
}
