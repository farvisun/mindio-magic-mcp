<?php
/**
 * Signed, retrying webhook delivery engine.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhook_Engine {
	public const EVENTS = array( 'post_created', 'post_updated', 'comment_added', 'order_created' );

	public function register_hooks(): void {
		add_action( 'wp_after_insert_post', array( $this, 'post_event' ), 10, 4 );
		add_action( 'comment_post', array( $this, 'comment_event' ), 10, 3 );
		add_action( 'woocommerce_new_order', array( $this, 'order_event' ), 10, 2 );
		add_action( 'mindio_magic_mcp_deliver_webhook', array( $this, 'deliver' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function register_webhook( string $name, string $url, array $events ): array|\WP_Error {
		$check = URL_Guard::validate( $url, true );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$events = array_values( array_unique( array_map( 'sanitize_key', $events ) ) );
		if ( empty( $events ) || array_diff( $events, self::EVENTS ) ) {
			return new \WP_Error( 'invalid_webhook_events', __( 'Select at least one supported webhook event.', 'mindio-magic-mcp' ) );
		}
		$webhooks = $this->records();
		if ( count( $webhooks ) >= 100 ) {
			return new \WP_Error( 'webhook_limit_reached', __( 'This site already has the maximum of 100 MCP webhooks.', 'mindio-magic-mcp' ) );
		}

		$id     = 'wh_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
		$secret = 'whsec_' . Secret_Box::base64url_encode( random_bytes( 32 ) );
		try {
			$encrypted_secret = Secret_Box::encrypt( $secret );
		} catch ( \Throwable ) {
			return new \WP_Error( 'webhook_encryption_unavailable', __( 'The webhook secret could not be encrypted on this server.', 'mindio-magic-mcp' ) );
		}
		$record = array(
			'id'         => $id,
			'name'       => sanitize_text_field( $name ) ?: __( 'Webhook', 'mindio-magic-mcp' ),
			'url'        => esc_url_raw( $url ),
			'events'     => $events,
			'secret'     => $encrypted_secret,
			'active'     => true,
			'created_at' => gmdate( DATE_ATOM ),
			'user_id'    => get_current_user_id(),
		);
		$webhooks[ $id ] = $record;
		update_option( 'mindio_magic_mcp_webhooks', $webhooks, false );

		$public           = $this->public_record( $record );
		$public['secret'] = $secret;
		$public['secret_notice'] = __( 'Store this signing secret now; it will not be shown again.', 'mindio-magic-mcp' );
		return $public;
	}

	public function unregister_webhook( string $id ): bool {
		$webhooks = $this->records();
		if ( ! isset( $webhooks[ $id ] ) ) {
			return false;
		}
		unset( $webhooks[ $id ] );
		update_option( 'mindio_magic_mcp_webhooks', $webhooks, false );
		return true;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_webhooks(): array {
		return array_values( array_map( array( $this, 'public_record' ), $this->records() ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_logs( array $args = array() ): array {
		global $wpdb;

		$limit  = max( 1, min( 200, absint( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, absint( $args['offset'] ?? 0 ) );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['webhook_id'] ) ) {
			$where[]  = 'webhook_id = %s';
			$params[] = sanitize_key( $args['webhook_id'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		$sql      = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions are fixed fragments selected above.
		array_unshift( $params, Installer::webhook_log_table() );
		$params[] = $limit;
		$params[] = $offset;
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL contains only fixed condition fragments and every value/identifier is passed through wpdb::prepare; diagnostics must be current.
		$include_payload = ! empty( $args['include_payload'] );
		return array_map(
			static function ( array $row ) use ( $include_payload ): array {
				$row['id']            = (int) $row['id'];
				$row['attempts']      = (int) $row['attempts'];
				$row['response_code'] = (int) $row['response_code'];
				if ( $include_payload ) {
					$row['payload'] = json_decode( (string) $row['payload'], true ) ?: array();
				} else {
					unset( $row['payload'] );
				}
				return $row;
			},
			$rows
		);
	}

	public function post_event( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		unset( $post_before );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'attachment' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		$this->queue(
			$update ? 'post_updated' : 'post_created',
			array(
				'post_id'      => $post_id,
				'post_type'    => $post->post_type,
				'status'       => $post->post_status,
				'title'        => get_the_title( $post ),
				'url'          => get_permalink( $post ) ?: '',
				'modified_gmt' => ( $modified = get_post_datetime( $post, 'modified' ) ) ? $modified->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : '',
			)
		);
	}

	public function comment_event( int $comment_id, int|string $approved, array $commentdata ): void {
		$this->queue(
			'comment_added',
			array(
				'comment_id'   => $comment_id,
				'post_id'      => absint( $commentdata['comment_post_ID'] ?? 0 ),
				'author'       => sanitize_text_field( (string) ( $commentdata['comment_author'] ?? '' ) ),
				'approved'     => (string) $approved,
				'created_gmt'  => gmdate( DATE_ATOM ),
			)
		);
	}

	public function order_event( int $order_id, mixed $order = null ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = $order ?: wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$this->queue(
			'order_created',
			array(
				'order_id'   => $order_id,
				'status'     => $order->get_status(),
				'total'      => $order->get_total(),
				'currency'   => $order->get_currency(),
				'customer_id' => $order->get_customer_id(),
				'created_gmt' => gmdate( DATE_ATOM ),
			)
		);
	}

	public function deliver( int $delivery_id ): void {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::webhook_log_table(), $delivery_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue workers must read the latest delivery state.
		if ( ! $row || 'delivered' === $row['status'] || 'failed' === $row['status'] ) {
			return;
		}
		$webhook = $this->records()[ $row['webhook_id'] ] ?? null;
		if ( ! is_array( $webhook ) || empty( $webhook['active'] ) ) {
			$this->update_delivery( $delivery_id, array( 'status' => 'failed', 'response_body' => 'Webhook no longer exists or is inactive.' ) );
			return;
		}
		$url_check = URL_Guard::validate( (string) $webhook['url'], true );
		if ( is_wp_error( $url_check ) ) {
			$this->update_delivery( $delivery_id, array( 'status' => 'failed', 'response_body' => $url_check->get_error_message() ) );
			return;
		}
		$secret = Secret_Box::decrypt( (string) $webhook['secret'] );
		if ( '' === $secret ) {
			$this->update_delivery( $delivery_id, array( 'status' => 'failed', 'response_body' => 'Webhook secret could not be decrypted.' ) );
			return;
		}

		$attempt   = (int) $row['attempts'] + 1;
		$timestamp = (string) time();
		$payload   = (string) $row['payload'];
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		$response  = wp_safe_remote_post(
			(string) $webhook['url'],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'                      => 'application/json',
					'User-Agent'                        => 'MindioMagicMCP/' . MINDIO_MAGIC_MCP_VERSION,
					'X-Mindio-Magic-MCP-Event'          => $row['event'],
					'X-Mindio-Magic-MCP-Delivery'       => (string) $delivery_id,
					'X-Mindio-Magic-MCP-Timestamp'      => $timestamp,
					'X-Mindio-Magic-MCP-Signature-256'  => 'sha256=' . $signature,
					// Deprecated aliases for receivers wired up before the rename.
					'X-MagicMCP-Event'                  => $row['event'],
					'X-MagicMCP-Delivery'               => (string) $delivery_id,
					'X-MagicMCP-Timestamp'              => $timestamp,
					'X-MagicMCP-Signature-256'          => 'sha256=' . $signature,
				),
				'body'        => $payload,
			),
		);
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
		$body = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 2000 ) : substr( $body, 0, 2000 );
		if ( $code >= 200 && $code < 300 ) {
			$this->update_delivery( $delivery_id, array( 'status' => 'delivered', 'attempts' => $attempt, 'response_code' => $code, 'response_body' => $body, 'next_attempt' => null ) );
			return;
		}

		$delays = array( 60, 300, 900, 3600 );
		if ( $attempt >= 5 ) {
			$this->update_delivery( $delivery_id, array( 'status' => 'failed', 'attempts' => $attempt, 'response_code' => $code, 'response_body' => $body, 'next_attempt' => null ) );
			return;
		}
		$next = time() + $delays[ $attempt - 1 ];
		$this->update_delivery( $delivery_id, array( 'status' => 'retrying', 'attempts' => $attempt, 'response_code' => $code, 'response_body' => $body, 'next_attempt' => gmdate( 'Y-m-d H:i:s', $next ) ) );
		wp_schedule_single_event( $next, 'mindio_magic_mcp_deliver_webhook', array( $delivery_id ) );
	}

	private function queue( string $event, array $data ): void {
		global $wpdb;

		foreach ( $this->records() as $webhook ) {
			if ( empty( $webhook['active'] ) || ! in_array( $event, (array) $webhook['events'], true ) ) {
				continue;
			}
			$payload = array(
				'id'          => wp_generate_uuid4(),
				'event'       => $event,
				'occurred_at' => gmdate( DATE_ATOM ),
				'site'        => array( 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
				'data'        => $data,
			);
			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only write to the plugin-owned webhook queue table.
			$wpdb->insert(
				Installer::webhook_log_table(),
				array(
					'webhook_id' => $webhook['id'],
					'event'      => $event,
					'payload'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					'status'     => 'queued',
					'attempts'   => 0,
					'created_at' => $now,
					'updated_at' => $now,
					'next_attempt' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			if ( $wpdb->insert_id ) {
				wp_schedule_single_event( time(), 'mindio_magic_mcp_deliver_webhook', array( (int) $wpdb->insert_id ) );
			}
		}
	}

	private function update_delivery( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Installer::webhook_log_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updates plugin-owned queue state that must be immediately visible.
	}

	/** @return array<string,array<string,mixed>> */
	private function records(): array {
		$records = get_option( 'mindio_magic_mcp_webhooks', array() );
		return is_array( $records ) ? $records : array();
	}

	/** @return array<string,mixed> */
	private function public_record( array $record ): array {
		unset( $record['secret'] );
		return $record;
	}
}
