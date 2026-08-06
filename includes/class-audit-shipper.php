<?php
/**
 * Ships audit records off the site.
 *
 * Records are batched by cron rather than sent inline, so shipping never adds
 * latency to a tool call. A cursor records the last exported row, so a batch is
 * neither lost nor sent twice.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Shipper {
	public const CURSOR_OPTION = 'mindio_magic_mcp_audit_export_cursor';
	public const CRON_HOOK     = 'mindio_magic_mcp_ship_audit';
	public const SCHEDULE      = 'mindio_magic_mcp_five_minutes';

	private const MAX_BATCH = 200;

	private Audit_Log $audit;
	private Audit_Anomaly_Detector $detector;

	public function __construct( Audit_Log $audit, ?Audit_Anomaly_Detector $detector = null ) {
		$this->audit    = $audit;
		$this->detector = $detector ?? new Audit_Anomaly_Detector();
	}

	public function register_hooks(): void {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'ship' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ), 20 );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public function register_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Mindio Magic MCP audit export)', 'mindio-magic-mcp' ),
		);

		return $schedules;
	}

	public function maybe_schedule(): void {
		$enabled = ! empty( get_option( 'mindio_magic_mcp_settings', array() )['audit_export_enabled'] );

		if ( $enabled && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_HOOK );
			return;
		}
		if ( ! $enabled && wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Export everything recorded since the cursor.
	 *
	 * @return array<string,mixed>
	 */
	public function ship(): array {
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		if ( empty( $settings['audit_export_enabled'] ) ) {
			return array( 'shipped' => 0, 'skipped' => 'disabled' );
		}

		$rows = $this->pending();
		if ( ! $rows ) {
			return array( 'shipped' => 0, 'anomalies' => 0 );
		}

		$anomalies = $this->detector->evaluate( $rows );
		$targets   = (string) ( $settings['audit_export_target'] ?? 'webhook' );
		$delivered = array();

		if ( in_array( $targets, array( 'webhook', 'both' ), true ) ) {
			$delivered['webhook'] = $this->to_webhook( $rows, $anomalies, $settings );
		}
		if ( in_array( $targets, array( 'syslog', 'both' ), true ) ) {
			$delivered['syslog'] = $this->to_syslog( $rows, $anomalies );
		}

		$last = (int) $rows[ count( $rows ) - 1 ]['id'];
		update_option( self::CURSOR_OPTION, $last, false );

		return array(
			'shipped'   => count( $rows ),
			'anomalies' => count( $anomalies ),
			'cursor'    => $last,
			'targets'   => $delivered,
		);
	}

	/**
	 * Audit rows newer than the cursor, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pending(): array {
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$rows   = $this->audit->list( array( 'limit' => self::MAX_BATCH, 'after_id' => $cursor ) );

		usort( $rows, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );

		return $rows;
	}

	/**
	 * Render one batch as newline-delimited JSON, the shape log collectors expect.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,array<string,mixed>> $anomalies
	 */
	public function to_ndjson( array $rows, array $anomalies = array() ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = (string) wp_json_encode(
				array(
					'kind'        => 'audit',
					'site'        => home_url( '/' ),
					'id'          => (int) $row['id'],
					'created_at'  => (string) $row['created_at'],
					'tool'        => (string) $row['tool'],
					'success'     => (bool) $row['success'],
					'error_code'  => (string) $row['error_code'],
					'duration_ms' => (int) $row['duration_ms'],
					'user_id'     => (int) $row['user_id'],
					'token_id'    => (string) $row['token_id'],
					'scope'       => (string) $row['scope'],
					'ip'          => (string) $row['ip'],
					'arguments'   => $row['arguments'],
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}
		foreach ( $anomalies as $anomaly ) {
			$lines[] = (string) wp_json_encode(
				array_merge( array( 'kind' => 'anomaly' ), $anomaly ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,array<string,mixed>> $anomalies
	 * @param array<string,mixed>            $settings
	 * @return array<string,mixed>
	 */
	private function to_webhook( array $rows, array $anomalies, array $settings ): array {
		$url = esc_url_raw( (string) ( $settings['audit_export_url'] ?? '' ), array( 'https', 'http' ) );
		if ( '' === $url ) {
			return array( 'ok' => false, 'reason' => 'no_url' );
		}

		$guard = URL_Guard::validate( $url );
		if ( is_wp_error( $guard ) ) {
			return array( 'ok' => false, 'reason' => $guard->get_error_code() );
		}

		$body      = $this->to_ndjson( $rows, $anomalies );
		$timestamp = (string) time();
		$secret    = (string) ( $settings['audit_export_secret'] ?? '' );
		$headers   = array(
			'Content-Type'                     => 'application/x-ndjson',
			'User-Agent'                       => 'MindioMagicMCP/' . MINDIO_MAGIC_MCP_VERSION,
			'X-Mindio-Magic-MCP-Export'        => 'audit',
			'X-Mindio-Magic-MCP-Records'       => (string) count( $rows ),
			'X-Mindio-Magic-MCP-Anomalies'     => (string) count( $anomalies ),
			'X-Mindio-Magic-MCP-Timestamp'     => $timestamp,
		);
		if ( '' !== $secret ) {
			$headers['X-Mindio-Magic-MCP-Signature-256'] = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		}

		$response = wp_safe_remote_post(
			$url,
			array( 'timeout' => 10, 'redirection' => 0, 'headers' => $headers, 'body' => $body )
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'reason' => $response->get_error_code() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array( 'ok' => $code >= 200 && $code < 300, 'status' => $code );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,array<string,mixed>> $anomalies
	 * @return array<string,mixed>
	 */
	private function to_syslog( array $rows, array $anomalies ): array {
		if ( ! function_exists( 'openlog' ) || ! function_exists( 'syslog' ) ) {
			return array( 'ok' => false, 'reason' => 'syslog_unavailable' );
		}

		openlog( 'mindio-magic-mcp', LOG_ODELAY | LOG_PID, LOG_USER );
		foreach ( explode( "\n", $this->to_ndjson( $rows, $anomalies ) ) as $line ) {
			if ( '' !== $line ) {
				syslog( LOG_INFO, $line );
			}
		}
		foreach ( $anomalies as $anomaly ) {
			syslog(
				'critical' === ( $anomaly['severity'] ?? '' ) ? LOG_CRIT : LOG_WARNING,
				(string) wp_json_encode( array_merge( array( 'kind' => 'anomaly' ), $anomaly ), JSON_UNESCAPED_SLASHES )
			);
		}
		closelog();

		return array( 'ok' => true, 'lines' => count( $rows ) + count( $anomalies ) );
	}
}
