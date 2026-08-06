<?php
/**
 * Audit export and anomaly detection coverage.
 * Run with WP_PATH=/path/to/wordpress php tests/integration/audit-export.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Audit_Shipper' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the audit export test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_ax_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_ax_call( string $token, string $tool, array $arguments = array() ): array {
	$request = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $tool, 'arguments' => $arguments ),
			)
		)
	);

	return (array) ( rest_get_server()->dispatch( $request )->get_data()['result'] ?? array() );
}

/**
 * Build synthetic audit rows in the shape Audit_Log::list returns.
 *
 * @return array<int,array<string,mixed>>
 */
function mindio_ax_rows( int $count, array $overrides = array() ): array {
	$rows = array();
	for ( $i = 1; $i <= $count; $i++ ) {
		$rows[] = array_merge(
			array(
				'id'          => $i,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'tool'        => 'get_post',
				'success'     => true,
				'error_code'  => '',
				'duration_ms' => 5,
				'user_id'     => 1,
				'token_id'    => 'tok-fixture',
				'scope'       => 'admin',
				'ip'          => '203.0.113.5',
				'arguments'   => array(),
			),
			$overrides
		);
	}

	return $rows;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_ax_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Audit export coverage' );
mindio_ax_assert( ! is_wp_error( $credential ), 'Could not create the audit export credential.' );
$token = (string) $credential['token'];

$original_settings = (array) get_option( 'mindio_magic_mcp_settings', array() );
$original_known    = get_option( \MindioMagicMCP\Audit_Anomaly_Detector::KNOWN_IPS_OPTION, array() );

try {
	$detector = new \MindioMagicMCP\Audit_Anomaly_Detector();
	$detector->clear();
	update_option( \MindioMagicMCP\Audit_Anomaly_Detector::KNOWN_IPS_OPTION, array(), false );

	$shipper = new \MindioMagicMCP\Audit_Shipper( new \MindioMagicMCP\Audit_Log(), $detector );

	$ndjson = $shipper->to_ndjson( mindio_ax_rows( 3 ) );
	$lines  = explode( "\n", $ndjson );
	mindio_ax_assert( 3 === count( $lines ), 'to_ndjson produced ' . count( $lines ) . ' lines instead of three.' );
	$first = json_decode( $lines[0], true );
	mindio_ax_assert( is_array( $first ) && 'audit' === $first['kind'], 'An exported line is not an audit record.' );
	mindio_ax_assert( isset( $first['tool'], $first['token_id'], $first['ip'] ), 'An exported record is missing fields.' );

	$spike = $detector->evaluate( mindio_ax_rows( 8, array( 'success' => false, 'error_code' => 'tool_exception' ) ) );
	$types = wp_list_pluck( $spike, 'type' );
	mindio_ax_assert( in_array( 'failure_spike', $types, true ), 'A failure spike was not detected.' );

	$detector->clear();
	$probing = $detector->evaluate( mindio_ax_rows( 6, array( 'success' => false, 'error_code' => 'insufficient_scope' ) ) );
	mindio_ax_assert(
		in_array( 'authorization_probing', wp_list_pluck( $probing, 'type' ), true ),
		'Permission probing was not detected.'
	);

	$detector->clear();
	$destructive = $detector->evaluate( mindio_ax_rows( 12, array( 'tool' => 'delete_post' ) ) );
	mindio_ax_assert(
		in_array( 'destructive_burst', wp_list_pluck( $destructive, 'type' ), true ),
		'A destructive burst was not detected.'
	);

	$detector->clear();
	$budget = $detector->evaluate( mindio_ax_rows( 2, array( 'success' => false, 'error_code' => 'budget_exhausted' ) ) );
	mindio_ax_assert(
		in_array( 'budget_exhausted', wp_list_pluck( $budget, 'type' ), true ),
		'Budget exhaustion was not detected.'
	);

	// The first address seen for a credential is a baseline, the second is an anomaly.
	$detector->clear();
	update_option( \MindioMagicMCP\Audit_Anomaly_Detector::KNOWN_IPS_OPTION, array(), false );
	$baseline = $detector->evaluate( mindio_ax_rows( 1, array( 'ip' => '198.51.100.1' ) ) );
	mindio_ax_assert(
		! in_array( 'new_credential_address', wp_list_pluck( $baseline, 'type' ), true ),
		'The first address for a credential was reported as an anomaly.'
	);
	$moved = $detector->evaluate( mindio_ax_rows( 1, array( 'ip' => '198.51.100.99' ) ) );
	mindio_ax_assert(
		in_array( 'new_credential_address', wp_list_pluck( $moved, 'type' ), true ),
		'A credential used from a new address was not flagged.'
	);

	$recent = $detector->recent( 10 );
	mindio_ax_assert( ! empty( $recent ), 'Detected anomalies were not retained.' );

	update_option(
		'mindio_magic_mcp_settings',
		array_merge( $original_settings, array( 'audit_export_enabled' => false ) ),
		false
	);
	$disabled = $shipper->ship();
	mindio_ax_assert( 'disabled' === ( $disabled['skipped'] ?? '' ), 'Shipping ran while export was disabled.' );

	$exported = mindio_ax_call( $token, 'export_audit_log', array( 'limit' => 5 ) );
	mindio_ax_assert( empty( $exported['isError'] ), 'export_audit_log failed.' );
	$payload = (array) $exported['structuredContent'];
	mindio_ax_assert( 'application/x-ndjson' === $payload['format'], 'export_audit_log reported the wrong format.' );
	mindio_ax_assert( $payload['count'] > 0, 'export_audit_log returned no records.' );

	$since = mindio_ax_call( $token, 'export_audit_log', array( 'after_id' => (int) $payload['last_id'], 'limit' => 5 ) );
	$since_payload = (array) $since['structuredContent'];
	mindio_ax_assert(
		(int) $since_payload['count'] <= (int) $payload['count'],
		'The cursor did not narrow the export.'
	);

	$anomaly_tool = mindio_ax_call( $token, 'get_audit_anomalies', array( 'limit' => 10 ) );
	mindio_ax_assert( empty( $anomaly_tool['isError'] ), 'get_audit_anomalies failed.' );
	mindio_ax_assert(
		(int) $anomaly_tool['structuredContent']['count'] > 0,
		'get_audit_anomalies returned nothing after anomalies were recorded.'
	);

	$reader = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_READ, 'Audit export reader' );
	$denied = mindio_ax_call( (string) $reader['token'], 'export_audit_log', array() );
	mindio_ax_assert( ! empty( $denied['isError'] ), 'A read-only credential exported the audit log.' );
	$auth->revoke_token( (string) $reader['id'] );

	echo wp_json_encode(
		array(
			'ok'                => true,
			'rules_fired'       => 5,
			'exported_records'  => (int) $payload['count'],
			'retained'          => count( $recent ),
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	update_option( 'mindio_magic_mcp_settings', $original_settings, false );
	update_option( \MindioMagicMCP\Audit_Anomaly_Detector::KNOWN_IPS_OPTION, $original_known, false );
	( new \MindioMagicMCP\Audit_Anomaly_Detector() )->clear();
	$auth->revoke_token( (string) $credential['id'] );
}
