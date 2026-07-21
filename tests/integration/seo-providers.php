<?php
/**
 * Yoast SEO Free and Rank Math SEO Free provider integration checks.
 *
 * Activate one or both free providers before running this test.
 * Run with WP_PATH=/path/to/wordpress php tests/integration/seo-providers.php.
 *
 * @package FlatsomeMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\\FlatsomeMCP\\Auth' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the SEO provider test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function fmp_seo_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function fmp_seo_rpc( string $token, string $method, array $params = array() ): array {
	$request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ) );
	$response = rest_get_server()->dispatch( $request );
	fmp_seo_assert( 200 === $response->get_status(), 'Unexpected MCP HTTP status: ' . $response->get_status() );
	$data = $response->get_data();
	fmp_seo_assert( is_array( $data ), 'MCP response is not an object.' );
	return $data;
}

/** @return array<string,mixed> */
function fmp_seo_call( string $token, string $tool, array $arguments = array() ): array {
	$response = fmp_seo_rpc( $token, 'tools/call', array( 'name' => $tool, 'arguments' => $arguments ) );
	$result   = (array) ( $response['result'] ?? array() );
	fmp_seo_assert( empty( $result['isError'] ), $tool . ' failed: ' . wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	return (array) ( $result['structuredContent']['result'] ?? array() );
}

$providers = array();
if ( defined( 'WPSEO_VERSION' ) && class_exists( '\\WPSEO_Options' ) ) {
	$providers['yoast_seo'] = array(
		'label'            => 'yoast',
		'title_meta'       => '_yoast_wpseo_title',
		'description_meta' => '_yoast_wpseo_metadesc',
		'focus_meta'       => '_yoast_wpseo_focuskw',
	);
}
if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' ) ) {
	$providers['rank_math'] = array(
		'label'            => 'rank_math',
		'title_meta'       => 'rank_math_title',
		'description_meta' => 'rank_math_description',
		'focus_meta'       => 'rank_math_focus_keyword',
	);
}
fmp_seo_assert( ! empty( $providers ), 'Activate Yoast SEO Free or Rank Math SEO Free before running this test.' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
fmp_seo_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0] );

$auth       = new \FlatsomeMCP\Auth();
$credential = $auth->create_api_key( (int) $admins[0], \FlatsomeMCP\Auth::SCOPE_ADMIN, 'SEO provider integration test' );
fmp_seo_assert( ! is_wp_error( $credential ), 'Could not create the SEO provider credential.' );
$token = (string) $credential['token'];

$original_policy   = get_option( \FlatsomeMCP\Tool_Registry::OPERATION_POLICY_OPTION, null );
$original_exposure = get_option( \FlatsomeMCP\Tool_Registry::EXPOSURE_OPTION, null );
$post_id           = 0;

try {
	update_option( \FlatsomeMCP\Tool_Registry::EXPOSURE_OPTION, array(), false );
	$policy = array();
	foreach ( array_keys( $providers ) as $tool_prefix ) {
		$policy[ $tool_prefix . '_write:update_post_seo' ] = true;
	}
	update_option( \FlatsomeMCP\Tool_Registry::OPERATION_POLICY_OPTION, $policy, false );

	$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'SEO provider fixture', 'post_content' => 'Provider integration content.' ), true );
	fmp_seo_assert( ! is_wp_error( $post_id ), 'Could not create the SEO fixture post.' );
	$post_id = (int) $post_id;

	$listed = fmp_seo_rpc( $token, 'tools/list' );
	$tools  = array_column( (array) ( $listed['result']['tools'] ?? array() ), null, 'name' );

	foreach ( $providers as $tool_prefix => $provider ) {
		$read_tool  = $tool_prefix . '_read';
		$write_tool = $tool_prefix . '_write';
		fmp_seo_assert( isset( $tools[ $read_tool ], $tools[ $write_tool ] ), 'SEO provider tools are missing for ' . $provider['label'] );
		fmp_seo_assert( array( 'update_post_seo' ) === (array) ( $tools[ $write_tool ]['inputSchema']['properties']['operation']['enum'] ?? array() ), 'SEO write discovery ignored operation policy for ' . $provider['label'] );

		$read = fmp_seo_call( $token, $read_tool, array( 'operation' => 'get_post_seo', 'arguments' => array( 'post_id' => $post_id ) ) );
		fmp_seo_assert( $post_id === (int) ( $read['post_id'] ?? 0 ), 'SEO provider could not read the fixture post.' );

		$title       = strtoupper( (string) $provider['label'] ) . ' MCP title';
		$description = 'Description stored through the provider-specific MCP adapter.';
		$focus       = 'wordpress mcp';
		$updated     = fmp_seo_call(
			$token,
			$write_tool,
			array(
				'operation' => 'update_post_seo',
				'arguments' => array(
					'post_id'       => $post_id,
					'title'         => $title,
					'description'   => $description,
					'focus_keyword' => $focus,
					'canonical_url' => 'https://example.com/provider-seo-fixture',
					'robots'       => array( 'noindex', 'nofollow' ),
				),
			)
		);
		$seo = (array) ( $updated['seo'] ?? array() );
		fmp_seo_assert( $title === ( $seo['title'] ?? null ) && $description === ( $seo['description'] ?? null ) && $focus === ( $seo['focus_keyword'] ?? null ), 'SEO provider round-trip failed for ' . $provider['label'] );
		fmp_seo_assert( $title === get_post_meta( $post_id, (string) $provider['title_meta'], true ), 'SEO title was not stored in the provider meta key.' );
		fmp_seo_assert( $description === get_post_meta( $post_id, (string) $provider['description_meta'], true ), 'SEO description was not stored in the provider meta key.' );
		fmp_seo_assert( $focus === get_post_meta( $post_id, (string) $provider['focus_meta'], true ), 'SEO focus keyword was not stored in the provider meta key.' );
		fmp_seo_assert( in_array( 'noindex', (array) ( $seo['robots'] ?? array() ), true ) && in_array( 'nofollow', (array) ( $seo['robots'] ?? array() ), true ), 'SEO robots directives did not round-trip.' );
	}

	echo wp_json_encode( array( 'ok' => true, 'providers' => array_column( $providers, 'label' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} finally {
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( null === $original_policy ) {
		delete_option( \FlatsomeMCP\Tool_Registry::OPERATION_POLICY_OPTION );
	} else {
		update_option( \FlatsomeMCP\Tool_Registry::OPERATION_POLICY_OPTION, $original_policy, false );
	}
	if ( null === $original_exposure ) {
		delete_option( \FlatsomeMCP\Tool_Registry::EXPOSURE_OPTION );
	} else {
		update_option( \FlatsomeMCP\Tool_Registry::EXPOSURE_OPTION, $original_exposure, false );
	}
	$auth->revoke_token( (string) $credential['id'] );
	global $wpdb;
	$wpdb->delete( \FlatsomeMCP\Installer::audit_table(), array( 'token_id' => (string) $credential['id'] ) );
}
