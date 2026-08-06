<?php
/**
 * Approval queue coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/approvals.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Approval_Queue' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the approval test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_ap_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_ap_call( string $token, string $tool, array $arguments = array() ): array {
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
	$response = rest_get_server()->dispatch( $request );
	mindio_ap_assert( 200 === $response->get_status(), $tool . ' returned HTTP ' . $response->get_status() );

	return (array) ( $response->get_data()['result'] ?? array() );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_ap_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Approval coverage' );
mindio_ap_assert( ! is_wp_error( $credential ), 'Could not create the approval credential.' );
$token = (string) $credential['token'];

$original_settings = (array) get_option( 'mindio_magic_mcp_settings', array() );
$post_id           = 0;
$second_post       = 0;

try {
	update_option(
		'mindio_magic_mcp_settings',
		array_merge(
			$original_settings,
			array( 'approvals_enabled' => true, 'approval_tools' => array( 'delete_*' ), 'approval_ttl_hours' => 72 )
		),
		false
	);

	$post_id = (int) wp_insert_post(
		array( 'post_title' => 'Approval fixture', 'post_status' => 'draft', 'post_type' => 'post' ),
		true
	);
	mindio_ap_assert( $post_id > 0, 'Could not create the approval fixture post.' );

	$gated = mindio_ap_call( $token, 'delete_post', array( 'post_id' => $post_id, 'confirm' => true ) );
	mindio_ap_assert( ! empty( $gated['isError'] ), 'A gated call executed without approval.' );
	mindio_ap_assert(
		'approval_required' === $gated['structuredContent']['error'],
		'A gated call returned ' . (string) $gated['structuredContent']['error']
	);
	mindio_ap_assert( get_post( $post_id ) instanceof WP_Post, 'The gated call deleted the post anyway.' );

	$approvals = new \MindioMagicMCP\Approval_Queue();
	$pending   = $approvals->list_requests( 'pending', 10 );
	mindio_ap_assert( ! empty( $pending ), 'No pending request was recorded.' );
	$request_id = (string) $pending[0]['request_id'];
	mindio_ap_assert( 'delete_post' === (string) $pending[0]['tool'], 'The pending request names the wrong tool.' );

	$listed = mindio_ap_call( $token, 'list_approvals', array( 'status' => 'pending' ) );
	mindio_ap_assert( empty( $listed['isError'] ), 'list_approvals failed.' );
	mindio_ap_assert( (int) $listed['structuredContent']['pending'] > 0, 'list_approvals reported nothing pending.' );

	$too_early = mindio_ap_call( $token, 'delete_post', array( 'post_id' => $post_id, 'confirm' => true, 'approval' => $request_id ) );
	mindio_ap_assert(
		! empty( $too_early['isError'] ) && 'approval_pending' === $too_early['structuredContent']['error'],
		'A still-pending approval was accepted.'
	);

	mindio_ap_assert( $approvals->approve( $request_id ), 'Approving the request failed.' );

	$second_post = (int) wp_insert_post(
		array( 'post_title' => 'Approval mismatch fixture', 'post_status' => 'draft', 'post_type' => 'post' ),
		true
	);
	$mismatched = mindio_ap_call( $token, 'delete_post', array( 'post_id' => $second_post, 'confirm' => true, 'approval' => $request_id ) );
	mindio_ap_assert(
		! empty( $mismatched['isError'] ) && 'approval_mismatch' === $mismatched['structuredContent']['error'],
		'An approval was reused for different arguments.'
	);
	mindio_ap_assert( get_post( $second_post ) instanceof WP_Post, 'The mismatched call still deleted a post.' );

	$approved = mindio_ap_call( $token, 'delete_post', array( 'post_id' => $post_id, 'confirm' => true, 'approval' => $request_id ) );
	mindio_ap_assert( empty( $approved['isError'] ), 'The approved call failed: ' . wp_json_encode( $approved['structuredContent'] ?? array() ) );

	$spent = mindio_ap_call( $token, 'delete_post', array( 'post_id' => $post_id, 'confirm' => true, 'approval' => $request_id ) );
	mindio_ap_assert(
		! empty( $spent['isError'] ) && 'approval_spent' === $spent['structuredContent']['error'],
		'A spent approval was reused.'
	);

	$preview = mindio_ap_call( $token, 'delete_post', array( 'post_id' => $second_post, 'confirm' => true, 'dry_run' => true ) );
	mindio_ap_assert( empty( $preview['isError'] ), 'A dry run of a gated tool was blocked: ' . wp_json_encode( $preview['structuredContent'] ?? array() ) );
	mindio_ap_assert( get_post( $second_post ) instanceof WP_Post, 'The gated dry run deleted the post.' );

	$ungated = mindio_ap_call( $token, 'update_post', array( 'post_id' => $second_post, 'title' => 'Ungated write' ) );
	mindio_ap_assert( empty( $ungated['isError'] ), 'An ungated write was blocked by the approval queue.' );

	echo wp_json_encode(
		array(
			'ok'                => true,
			'gated'             => true,
			'mismatch_blocked'  => true,
			'replay_blocked'    => true,
			'dry_run_exempt'    => true,
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	update_option( 'mindio_magic_mcp_settings', $original_settings, false );
	foreach ( array( $post_id, $second_post ) as $id ) {
		if ( $id && get_post( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	$auth->revoke_token( (string) $credential['id'] );
}
