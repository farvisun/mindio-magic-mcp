<?php
/**
 * Durable MCP audit logging.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Log {
	public function write( string $tool, array $arguments, bool $success, string $error_code, int $duration_ms, Auth $auth ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only write to the plugin-owned audit table.
		$wpdb->insert(
			Installer::audit_table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'user_id'     => get_current_user_id(),
				'token_id'    => $auth->current_token_id(),
				'scope'       => $auth->current_scope(),
				'tool'        => sanitize_key( $tool ),
				'arguments'   => wp_json_encode( $this->redact( $arguments ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'success'     => $success ? 1 : 0,
				'error_code'  => sanitize_key( $error_code ),
				'duration_ms' => max( 0, $duration_ms ),
				'ip'          => $this->request_ip(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function list( array $args = array() ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, absint( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, absint( $args['offset'] ?? 0 ) );
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['tool'] ) ) {
			$where[]  = 'tool = %s';
			$params[] = sanitize_key( $args['tool'] );
		}
		if ( isset( $args['success'] ) ) {
			$where[]  = 'success = %d';
			$params[] = $args['success'] ? 1 : 0;
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		$sql      = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions are fixed fragments selected above.
		array_unshift( $params, Installer::audit_table() );
		$params[] = $limit;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL contains only fixed condition fragments and every value/identifier is passed through wpdb::prepare; audit results must be current.

		return array_map(
			static function ( array $row ): array {
				$row['id']          = (int) $row['id'];
				$row['user_id']     = (int) $row['user_id'];
				$row['success']     = (bool) $row['success'];
				$row['duration_ms'] = (int) $row['duration_ms'];
				$row['arguments']   = json_decode( (string) $row['arguments'], true ) ?: array();
				return $row;
			},
			(array) $rows
		);
	}

	private function redact( mixed $value, string $key = '' ): mixed {
		if ( preg_match( '/(?:password|secret|token|authorization|api[_-]?key|data_base64|content|prompt|query|search|sql|html|description|excerpt|email|phone)$/i', $key ) ) {
			return '[REDACTED]';
		}
		if ( is_array( $value ) ) {
			$output = array();
			foreach ( $value as $child_key => $child ) {
				$output[ $child_key ] = $this->redact( $child, (string) $child_key );
			}
			return $output;
		}
		if ( is_string( $value ) && preg_match( '/(?:^|_)url$/i', $key ) ) {
			return preg_replace( '/([?#]).*$/', '$1[REDACTED]', $value ) ?? $value;
		}
		if ( is_string( $value ) && strlen( $value ) > 500 ) {
			return substr( $value, 0, 500 ) . '…';
		}
		return $value;
	}

	private function request_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
