<?php
/**
 * MCP resources and prompts coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/resources-prompts.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Resource_Registry' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the resource test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_res_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_res_rpc( string $token, string $method, array $params = array() ): array {
	$request = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ) );
	$response = rest_get_server()->dispatch( $request );
	mindio_res_assert( 200 === $response->get_status(), $method . ' returned HTTP ' . $response->get_status() );
	$data = $response->get_data();
	mindio_res_assert( is_array( $data ), $method . ' returned a non-object body.' );
	mindio_res_assert( empty( $data['error'] ), $method . ' failed: ' . wp_json_encode( $data['error'] ?? array() ) );

	return (array) ( $data['result'] ?? array() );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_res_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Resource coverage' );
mindio_res_assert( ! is_wp_error( $credential ), 'Could not create the resource test credential.' );
$token = (string) $credential['token'];

try {
	$initialize = mindio_res_rpc( $token, 'initialize', array( 'protocolVersion' => '2025-06-18' ) );
	mindio_res_assert( isset( $initialize['capabilities']['resources'] ), 'initialize does not advertise the resources capability.' );
	mindio_res_assert( isset( $initialize['capabilities']['prompts'] ), 'initialize does not advertise the prompts capability.' );

	$resources = mindio_res_rpc( $token, 'resources/list' );
	$uris      = wp_list_pluck( (array) $resources['resources'], 'uri' );
	mindio_res_assert( in_array( 'mindio://site/profile', $uris, true ), 'The site profile resource is missing.' );
	mindio_res_assert( in_array( 'mindio://site/post-types', $uris, true ), 'The post types resource is missing.' );
	mindio_res_assert( in_array( 'mindio://flatsome/components', $uris, true ), 'The Flatsome component resource is missing.' );

	$templates = mindio_res_rpc( $token, 'resources/templates/list' );
	$patterns  = wp_list_pluck( (array) $templates['resourceTemplates'], 'uriTemplate' );
	mindio_res_assert( in_array( 'mindio://post/{id}', $patterns, true ), 'The post resource template is missing.' );

	$profile = mindio_res_rpc( $token, 'resources/read', array( 'uri' => 'mindio://site/profile' ) );
	$decoded = json_decode( (string) $profile['contents'][0]['text'], true );
	mindio_res_assert( is_array( $decoded ) && isset( $decoded['locale'], $decoded['theme'] ), 'The site profile payload is invalid.' );

	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Resource fixture',
			'post_content' => '<!-- wp:paragraph --><p>Fixture body.</p><!-- /wp:paragraph -->',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		),
		true
	);
	mindio_res_assert( ! is_wp_error( $post_id ), 'Could not create the resource fixture post.' );

	$read = mindio_res_rpc( $token, 'resources/read', array( 'uri' => 'mindio://post/' . $post_id ) );
	$post_payload = json_decode( (string) $read['contents'][0]['text'], true );
	mindio_res_assert( is_array( $post_payload ) && (int) $post_payload['id'] === (int) $post_id, 'The post resource returned the wrong entry.' );
	mindio_res_assert( 'gutenberg' === $post_payload['editing_surface'], 'The post resource misreported the editing surface.' );

	$collection = mindio_res_rpc( $token, 'resources/read', array( 'uri' => 'mindio://posts/post' ) );
	$collection_payload = json_decode( (string) $collection['contents'][0]['text'], true );
	mindio_res_assert( is_array( $collection_payload ) && $collection_payload['count'] > 0, 'The post collection resource is empty.' );

	$missing = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$missing->set_header( 'Authorization', 'Bearer ' . $token );
	$missing->set_header( 'Content-Type', 'application/json' );
	$missing->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => array( 'uri' => 'mindio://post/0' ) ) ) );
	$missing_data = rest_get_server()->dispatch( $missing )->get_data();
	mindio_res_assert( ! empty( $missing_data['error'] ), 'Reading an unknown resource did not fail.' );

	$prompts = mindio_res_rpc( $token, 'prompts/list' );
	$names   = wp_list_pluck( (array) $prompts['prompts'], 'name' );
	mindio_res_assert( in_array( 'write_product_description', $names, true ), 'The product description prompt is missing.' );
	mindio_res_assert( in_array( 'build_landing_page', $names, true ), 'The landing page prompt is missing.' );

	update_option(
		'mindio_magic_mcp_settings',
		array_merge(
			(array) get_option( 'mindio_magic_mcp_settings', array() ),
			array( 'brand_voice' => 'Calm and precise. No exclamation marks.' )
		),
		false
	);

	$rendered = mindio_res_rpc( $token, 'prompts/get', array( 'name' => 'write_product_description', 'arguments' => array( 'product' => 'Test kettle' ) ) );
	mindio_res_assert( ! empty( $rendered['messages'] ), 'The prompt returned no messages.' );
	$joined = '';
	foreach ( $rendered['messages'] as $message ) {
		$joined .= (string) $message['content']['text'];
	}
	mindio_res_assert( str_contains( $joined, 'Calm and precise' ), 'The prompt did not carry the configured brand voice.' );
	mindio_res_assert( str_contains( $joined, 'Test kettle' ), 'The prompt did not carry its argument.' );

	$invalid = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$invalid->set_header( 'Authorization', 'Bearer ' . $token );
	$invalid->set_header( 'Content-Type', 'application/json' );
	$invalid->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/get', 'params' => array( 'name' => 'write_product_description', 'arguments' => array() ) ) ) );
	$invalid_data = rest_get_server()->dispatch( $invalid )->get_data();
	mindio_res_assert( ! empty( $invalid_data['error'] ), 'A prompt missing a required argument did not fail.' );

	wp_delete_post( (int) $post_id, true );

	echo wp_json_encode(
		array(
			'ok'        => true,
			'resources' => count( (array) $resources['resources'] ),
			'templates' => count( (array) $templates['resourceTemplates'] ),
			'prompts'   => count( (array) $prompts['prompts'] ),
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	$auth->revoke_token( (string) $credential['id'] );
}
