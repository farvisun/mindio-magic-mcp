<?php
/**
 * On-demand audit export and anomaly inspection.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Export_Tools {
	private Tool_Registry $registry;
	private Audit_Log $audit;
	private Audit_Shipper $shipper;
	private Audit_Anomaly_Detector $detector;

	public function __construct( Tool_Registry $registry, Audit_Log $audit, Audit_Shipper $shipper, Audit_Anomaly_Detector $detector ) {
		$this->registry = $registry;
		$this->audit    = $audit;
		$this->shipper  = $shipper;
		$this->detector = $detector;
	}

	public function register(): void {
		$this->registry->register(
			'export_audit_log',
			__( 'Export audit records as newline-delimited JSON for a log collector, optionally only those newer than a known record ID.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'after_id' => array( 'type' => 'integer', 'minimum' => 0, 'description' => __( 'Return only records newer than this ID.', 'mindio-magic-mcp' ) ),
					'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
					'tool'     => array( 'type' => 'string', 'maxLength' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'export' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);

		$this->registry->register(
			'get_audit_anomalies',
			__( 'Read anomalies detected in the audit trail: failure spikes, permission probing, destructive bursts, exhausted budgets, and credentials used from a new address.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'anomalies' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed> */
	public function export( array $args ): array {
		$rows = $this->audit->list(
			array(
				'limit'    => absint( $args['limit'] ?? 200 ),
				'after_id' => absint( $args['after_id'] ?? 0 ),
				'tool'     => (string) ( $args['tool'] ?? '' ),
			)
		);
		usort( $rows, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );

		return array(
			'format'   => 'application/x-ndjson',
			'count'    => count( $rows ),
			'last_id'  => $rows ? (int) $rows[ count( $rows ) - 1 ]['id'] : absint( $args['after_id'] ?? 0 ),
			'records'  => $this->shipper->to_ndjson( $rows ),
		);
	}

	/** @return array<string,mixed> */
	public function anomalies( array $args ): array {
		$anomalies = $this->detector->recent( absint( $args['limit'] ?? 25 ) );

		return array( 'count' => count( $anomalies ), 'anomalies' => $anomalies );
	}
}
