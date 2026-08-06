<?php
/**
 * Per-credential policy and budget coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/credential-policy.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Credential_Policy' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the credential policy test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_pol_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_pol_rpc( string $token, string $method, array $params = array() ): array {
	$request = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ) );

	return (array) rest_get_server()->dispatch( $request )->get_data();
}

/** @return array<string,mixed> */
function mindio_pol_call( string $token, string $tool, array $arguments = array() ): array {
	$data = mindio_pol_rpc( $token, 'tools/call', array( 'name' => $tool, 'arguments' => $arguments ) );

	return (array) ( $data['result'] ?? array() );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_pol_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth = new \MindioMagicMCP\Auth();

$restricted = $auth->create_api_key(
	$user_id,
	\MindioMagicMCP\Auth::SCOPE_ADMIN,
	'Policy: read-only surface',
	array( 'allow' => array( 'get_*', 'list_*' ), 'deny' => array( 'list_users' ), 'daily_budget' => 0 )
);
mindio_pol_assert( ! is_wp_error( $restricted ), 'Could not create the restricted credential.' );

$budgeted = $auth->create_api_key(
	$user_id,
	\MindioMagicMCP\Auth::SCOPE_READ,
	'Policy: tiny budget',
	array( 'daily_budget' => 2 )
);
mindio_pol_assert( ! is_wp_error( $budgeted ), 'Could not create the budgeted credential.' );

$unrestricted = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Policy: unrestricted' );
mindio_pol_assert( ! is_wp_error( $unrestricted ), 'Could not create the unrestricted credential.' );

try {
	$listed = mindio_pol_rpc( (string) $restricted['token'], 'tools/list' );
	$names  = wp_list_pluck( (array) $listed['result']['tools'], 'name' );
	mindio_pol_assert( in_array( 'get_server_status', $names, true ), 'An allowed tool is missing from tools/list.' );
	mindio_pol_assert( ! in_array( 'update_post', $names, true ), 'A tool outside the allow list is still listed.' );
	mindio_pol_assert( ! in_array( 'list_users', $names, true ), 'A denied tool is still listed.' );

	$allowed = mindio_pol_call( (string) $restricted['token'], 'get_server_status' );
	mindio_pol_assert( empty( $allowed['isError'] ), 'An allowed tool call failed.' );

	$outside = mindio_pol_call( (string) $restricted['token'], 'update_post', array( 'post_id' => 1, 'title' => 'Nope' ) );
	mindio_pol_assert( ! empty( $outside['isError'] ), 'A tool outside the allow list was callable.' );
	mindio_pol_assert(
		'tool_not_in_policy' === $outside['structuredContent']['error'],
		'Calling outside the allow list returned ' . (string) $outside['structuredContent']['error']
	);

	$denied = mindio_pol_call( (string) $restricted['token'], 'list_users' );
	mindio_pol_assert(
		! empty( $denied['isError'] ) && 'tool_not_in_policy' === $denied['structuredContent']['error'],
		'Deny did not win over allow.'
	);

	$unrestricted_list = mindio_pol_rpc( (string) $unrestricted['token'], 'tools/list' );
	$unrestricted_names = wp_list_pluck( (array) $unrestricted_list['result']['tools'], 'name' );
	mindio_pol_assert( in_array( 'update_post', $unrestricted_names, true ), 'An empty policy restricted a credential.' );

	$introspected = mindio_pol_call( (string) $restricted['token'], 'get_credential_policy' );
	mindio_pol_assert( empty( $introspected['isError'] ), 'get_credential_policy failed.' );
	$reported = (array) $introspected['structuredContent'];
	mindio_pol_assert( false === $reported['unrestricted'], 'A restricted credential reports itself unrestricted.' );
	mindio_pol_assert(
		in_array( 'list_users', (array) $reported['policy']['deny'], true ),
		'get_credential_policy did not report the deny list.'
	);

	$first  = mindio_pol_call( (string) $budgeted['token'], 'get_server_status' );
	$second = mindio_pol_call( (string) $budgeted['token'], 'get_server_status' );
	mindio_pol_assert( empty( $first['isError'] ) && empty( $second['isError'] ), 'Calls inside the budget were rejected.' );

	$third = mindio_pol_call( (string) $budgeted['token'], 'get_server_status' );
	mindio_pol_assert( ! empty( $third['isError'] ), 'The call over budget was allowed.' );
	mindio_pol_assert(
		'budget_exhausted' === $third['structuredContent']['error'],
		'Exceeding the budget returned ' . (string) $third['structuredContent']['error']
	);

	$updated = $auth->set_token_policy( (string) $restricted['id'], array( 'allow' => array(), 'deny' => array(), 'daily_budget' => 0 ) );
	mindio_pol_assert( $updated, 'set_token_policy did not update the credential.' );
	$relisted = mindio_pol_rpc( (string) $restricted['token'], 'tools/list' );
	$relisted_names = wp_list_pluck( (array) $relisted['result']['tools'], 'name' );
	mindio_pol_assert( in_array( 'update_post', $relisted_names, true ), 'Clearing the policy did not widen the surface.' );

	echo wp_json_encode(
		array(
			'ok'               => true,
			'allow_enforced'   => true,
			'deny_wins'        => true,
			'budget_enforced'  => true,
			'listing_filtered' => true,
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	foreach ( array( $restricted, $budgeted, $unrestricted ) as $credential ) {
		if ( ! is_wp_error( $credential ) ) {
			$auth->revoke_token( (string) $credential['id'] );
		}
	}
}
