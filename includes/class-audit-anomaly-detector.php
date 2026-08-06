<?php
/**
 * Pattern detection over recent audit records.
 *
 * The rules here look for the shapes that matter when an agent credential is
 * misbehaving or has been taken: bursts of failures, permission probing,
 * unusual volumes of destructive work, and calls from a new address.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Anomaly_Detector {
	public const KNOWN_IPS_OPTION = 'mindio_magic_mcp_known_credential_ips';
	public const ANOMALY_OPTION   = 'mindio_magic_mcp_audit_anomalies';

	private const MAX_STORED       = 100;
	private const MAX_KNOWN_IPS    = 20;
	private const FAILURE_FLOOR    = 5;
	private const PROBE_FLOOR      = 5;
	private const DESTRUCTIVE_FLOOR = 10;

	/**
	 * Error codes that mean the caller asked for something it may not have.
	 *
	 * @var array<int,string>
	 */
	private const PROBE_CODES = array( 'forbidden', 'insufficient_scope', 'tool_not_in_policy', 'tool_disabled', 'operation_disabled' );

	/**
	 * Evaluate one batch of audit rows.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>> Detected anomalies.
	 */
	public function evaluate( array $rows ): array {
		if ( ! $rows ) {
			return array();
		}

		$anomalies = array_merge(
			$this->failure_spike( $rows ),
			$this->authorization_probing( $rows ),
			$this->destructive_burst( $rows ),
			$this->budget_exhaustion( $rows ),
			$this->new_addresses( $rows )
		);

		if ( $anomalies ) {
			$this->remember( $anomalies );
		}

		return $anomalies;
	}

	/**
	 * Most recent anomalies, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 25 ): array {
		$stored = get_option( self::ANOMALY_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_slice( array_reverse( $stored ), 0, max( 1, min( self::MAX_STORED, $limit ) ) );
	}

	public function clear(): void {
		update_option( self::ANOMALY_OPTION, array(), false );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function failure_spike( array $rows ): array {
		$failures = array_filter( $rows, static fn( array $row ): bool => empty( $row['success'] ) );
		$count    = count( $failures );
		if ( $count < self::FAILURE_FLOOR || $count < ( count( $rows ) / 2 ) ) {
			return array();
		}

		return array(
			$this->anomaly(
				'failure_spike',
				'warning',
				sprintf(
					/* translators: 1: failed call count, 2: total call count. */
					__( '%1$d of %2$d recent tool calls failed.', 'mindio-magic-mcp' ),
					$count,
					count( $rows )
				),
				array(
					'failed' => $count,
					'total'  => count( $rows ),
					'tools'  => array_values( array_unique( wp_list_pluck( $failures, 'tool' ) ) ),
				)
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function authorization_probing( array $rows ): array {
		$probes = array_filter(
			$rows,
			static fn( array $row ): bool => in_array( (string) $row['error_code'], self::PROBE_CODES, true )
		);
		if ( count( $probes ) < self::PROBE_FLOOR ) {
			return array();
		}

		$by_token = array();
		foreach ( $probes as $probe ) {
			$token              = (string) $probe['token_id'];
			$by_token[ $token ] = ( $by_token[ $token ] ?? 0 ) + 1;
		}

		return array(
			$this->anomaly(
				'authorization_probing',
				'critical',
				sprintf(
					/* translators: %d: number of denied calls. */
					__( '%d calls were denied for missing scope, capability, or policy.', 'mindio-magic-mcp' ),
					count( $probes )
				),
				array(
					'denied'          => count( $probes ),
					'per_credential'  => $by_token,
					'tools'           => array_values( array_unique( wp_list_pluck( $probes, 'tool' ) ) ),
				)
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function destructive_burst( array $rows ): array {
		$destructive = array_filter(
			$rows,
			static function ( array $row ): bool {
				$tool = (string) $row['tool'];

				return ! empty( $row['success'] ) && (
					str_starts_with( $tool, 'delete_' )
					|| in_array( $tool, array( 'revert_changeset', 'run_wp_cli', 'switch_theme' ), true )
				);
			}
		);
		if ( count( $destructive ) < self::DESTRUCTIVE_FLOOR ) {
			return array();
		}

		return array(
			$this->anomaly(
				'destructive_burst',
				'critical',
				sprintf(
					/* translators: %d: number of destructive calls. */
					__( '%d destructive calls succeeded in a short window.', 'mindio-magic-mcp' ),
					count( $destructive )
				),
				array(
					'count' => count( $destructive ),
					'tools' => array_count_values( wp_list_pluck( $destructive, 'tool' ) ),
				)
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function budget_exhaustion( array $rows ): array {
		$exhausted = array_filter( $rows, static fn( array $row ): bool => 'budget_exhausted' === (string) $row['error_code'] );
		if ( ! $exhausted ) {
			return array();
		}

		return array(
			$this->anomaly(
				'budget_exhausted',
				'notice',
				sprintf(
					/* translators: %d: number of rejected calls. */
					__( '%d calls were rejected after a credential used its daily budget.', 'mindio-magic-mcp' ),
					count( $exhausted )
				),
				array( 'credentials' => array_values( array_unique( wp_list_pluck( $exhausted, 'token_id' ) ) ) )
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function new_addresses( array $rows ): array {
		$known = get_option( self::KNOWN_IPS_OPTION, array() );
		$known = is_array( $known ) ? $known : array();

		$anomalies = array();
		$changed   = false;

		foreach ( $rows as $row ) {
			$token = (string) $row['token_id'];
			$ip    = (string) $row['ip'];
			if ( '' === $token || '' === $ip ) {
				continue;
			}

			$seen = (array) ( $known[ $token ] ?? array() );
			if ( in_array( $ip, $seen, true ) ) {
				continue;
			}

			// A credential's first observed address is its baseline, not an anomaly.
			if ( $seen ) {
				$anomalies[] = $this->anomaly(
					'new_credential_address',
					'warning',
					sprintf(
						/* translators: 1: credential id, 2: IP address. */
						__( 'Credential %1$s was used from a new address (%2$s).', 'mindio-magic-mcp' ),
						$token,
						$ip
					),
					array( 'token_id' => $token, 'ip' => $ip, 'known' => $seen )
				);
			}

			$seen[]          = $ip;
			$known[ $token ] = array_slice( array_values( array_unique( $seen ) ), -self::MAX_KNOWN_IPS );
			$changed         = true;
		}

		if ( $changed ) {
			update_option( self::KNOWN_IPS_OPTION, $known, false );
		}

		return $anomalies;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function anomaly( string $type, string $severity, string $message, array $context ): array {
		return array(
			'type'       => $type,
			'severity'   => $severity,
			'message'    => $message,
			'context'    => $context,
			'detected_at' => gmdate( DATE_ATOM ),
			'site'       => home_url( '/' ),
		);
	}

	/** @param array<int,array<string,mixed>> $anomalies */
	private function remember( array $anomalies ): void {
		$stored = get_option( self::ANOMALY_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$stored = array_slice( array_merge( $stored, $anomalies ), -self::MAX_STORED );

		update_option( self::ANOMALY_OPTION, $stored, false );

		foreach ( $anomalies as $anomaly ) {
			do_action( 'mindio_magic_mcp_audit_anomaly', $anomaly );
		}
	}
}
