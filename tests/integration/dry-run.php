<?php
/**
 * Dry run coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/dry-run.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Dry_Run' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the dry run test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_dry_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_dry_call( string $token, string $tool, array $arguments = array() ): array {
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
	mindio_dry_assert( 200 === $response->get_status(), $tool . ' returned HTTP ' . $response->get_status() );
	$data = $response->get_data();

	return (array) ( $data['result'] ?? array() );
}

if ( ! \MindioMagicMCP\Dry_Run::is_supported() ) {
	echo wp_json_encode( array( 'ok' => true, 'skipped' => 'The database does not use a transactional storage engine.' ) ) . "\n";
	return;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_dry_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Dry run coverage' );
mindio_dry_assert( ! is_wp_error( $credential ), 'Could not create the dry run credential.' );
$token = (string) $credential['token'];

$post_id = 0;

try {
	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Dry run fixture',
			'post_content' => 'Original body.',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		),
		true
	);
	mindio_dry_assert( ! is_wp_error( $post_id ), 'Could not create the dry run fixture post.' );
	$post_id = (int) $post_id;

	$listed = mindio_dry_call( $token, 'list_tools' );
	unset( $listed );

	$tools_request = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$tools_request->set_header( 'Authorization', 'Bearer ' . $token );
	$tools_request->set_header( 'Content-Type', 'application/json' );
	$tools_request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list' ) ) );
	$tools = (array) rest_get_server()->dispatch( $tools_request )->get_data()['result']['tools'];

	$by_name = array();
	foreach ( $tools as $tool ) {
		$by_name[ $tool['name'] ] = $tool;
	}
	mindio_dry_assert(
		isset( $by_name['update_post']['inputSchema']['properties']['dry_run'] ),
		'Write tools do not advertise dry_run.'
	);
	mindio_dry_assert(
		! isset( $by_name['get_post']['inputSchema']['properties']['dry_run'] ),
		'A read-only tool advertises dry_run.'
	);
	mindio_dry_assert(
		! isset( $by_name['upload_media']['inputSchema']['properties']['dry_run'] ),
		'A filesystem-writing tool advertises dry_run.'
	);

	$preview = mindio_dry_call(
		$token,
		'update_post',
		array(
			'post_id' => $post_id,
			'title'   => 'Previewed title',
			'content' => 'Previewed body.',
			'dry_run' => true,
		)
	);
	mindio_dry_assert( empty( $preview['isError'] ), 'The dry run returned an error: ' . wp_json_encode( $preview ) );

	$structured = (array) $preview['structuredContent'];
	mindio_dry_assert( true === $structured['dry_run'], 'The result is not flagged as a dry run.' );
	mindio_dry_assert( false === $structured['applied'], 'The result is not flagged as unapplied.' );

	$posts = (array) $structured['changes']['posts'];
	mindio_dry_assert( 1 === count( $posts ), 'The dry run reported ' . count( $posts ) . ' post changes instead of one.' );
	mindio_dry_assert( (int) $posts[0]['id'] === $post_id, 'The dry run reported the wrong post.' );
	mindio_dry_assert( 'update' === $posts[0]['operation'], 'The dry run misreported the operation.' );
	mindio_dry_assert(
		'Dry run fixture' === $posts[0]['fields']['post_title']['before'],
		'The dry run reported the wrong previous title.'
	);
	mindio_dry_assert(
		'Previewed title' === $posts[0]['fields']['post_title']['after'],
		'The dry run reported the wrong proposed title.'
	);

	clean_post_cache( $post_id );
	$after = get_post( $post_id );
	mindio_dry_assert( 'Dry run fixture' === $after->post_title, 'The dry run committed the title change.' );
	mindio_dry_assert( 'Original body.' === $after->post_content, 'The dry run committed the content change.' );

	$applied = mindio_dry_call(
		$token,
		'update_post',
		array( 'post_id' => $post_id, 'title' => 'Applied title' )
	);
	mindio_dry_assert( empty( $applied['isError'] ), 'The real update failed after a dry run.' );
	clean_post_cache( $post_id );
	mindio_dry_assert( 'Applied title' === get_post( $post_id )->post_title, 'The real update did not persist.' );

	$blocked = mindio_dry_call( $token, 'upload_media', array( 'dry_run' => true ) );
	mindio_dry_assert( ! empty( $blocked['isError'] ), 'A filesystem-writing tool accepted dry_run.' );

	$deletion = mindio_dry_call( $token, 'delete_post', array( 'post_id' => $post_id, 'confirm' => true, 'force' => true, 'dry_run' => true ) );
	mindio_dry_assert( empty( $deletion['isError'] ), 'The delete dry run failed: ' . wp_json_encode( $deletion ) );
	clean_post_cache( $post_id );
	mindio_dry_assert( get_post( $post_id ) instanceof WP_Post, 'The delete dry run actually deleted the post.' );

	echo wp_json_encode(
		array(
			'ok'              => true,
			'preview_changes' => (int) $structured['changes']['total'],
			'rolled_back'     => true,
			'opt_out_enforced' => true,
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$auth->revoke_token( (string) $credential['id'] );
}
