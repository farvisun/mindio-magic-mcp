<?php
/**
 * Local WordPress smoke test. Run with WP_PATH=/path/to/wordpress php tests/integration/smoke.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Auth' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the smoke test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function fmp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function fmp_rpc( string $token, string $method, array $params = array(), int $id = 1 ): array {
	$request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ) ) );
	$response = rest_get_server()->dispatch( $request );
	fmp_assert( 200 === $response->get_status(), 'Unexpected HTTP status: ' . $response->get_status() );
	$data = $response->get_data();
	fmp_assert( is_array( $data ), 'RPC response is not an object.' );
	return $data;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
fmp_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0] );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( (int) $admins[0], \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Integration smoke test' );
fmp_assert( ! is_wp_error( $credential ), 'Could not create smoke-test credential.' );
$token   = (string) $credential['token'];
$page_id = 0;
$post_id = 0;
$media_id = 0;
$read_credential = null;
$oauth_client_id = '';
$oauth_token_ids = array();
$oauth_refresh_ids = array();
$original_settings = get_option( 'mindio_magic_mcp_settings', array() );
$original_disabled_tools = get_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, null );
update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, array(), false );

try {
	$secret_roundtrip = \MindioMagicMCP\Secret_Box::encrypt( 'integration-secret' );
	fmp_assert( 'integration-secret' === \MindioMagicMCP\Secret_Box::decrypt( $secret_roundtrip ), 'Webhook secret encryption round-trip failed.' );
	fmp_assert( '' === \MindioMagicMCP\Secret_Box::decrypt( 's1:broken' ), 'Malformed encrypted secret was accepted.' );
	$rate_identity = 'prefix-regression';
	$rate_bucket   = 'integration';
	$rate_limiter  = new \MindioMagicMCP\Rate_Limiter();
	$rate_limiter->consume( $rate_identity, $rate_bucket );
	$rate_slot = (int) floor( time() / 60 );
	$rate_key  = 'mindio_magic_mcp_rate_limit_' . md5( $rate_bucket . '|' . $rate_identity . '|' . $rate_slot );
	fmp_assert( 1 === (int) get_transient( $rate_key ), 'The rate-limit transient does not use the unique plugin prefix.' );
	delete_transient( $rate_key );

	$initialize = fmp_rpc(
		$token,
		'initialize',
		array( 'protocolVersion' => '2025-11-25', 'capabilities' => array(), 'clientInfo' => array( 'name' => 'smoke', 'version' => '1' ) )
	);
	fmp_assert( '2025-11-25' === $initialize['result']['protocolVersion'], 'MCP negotiation failed.' );

	$list = fmp_rpc( $token, 'tools/list' );
	$tool_names = array_column( $list['result']['tools'], 'name' );
	foreach ( array( 'create_post', 'upload_media', 'update_meta', 'register_webhook', 'list_flatsome_components', 'create_flatsome_page', 'add_element', 'summarize_content', 'bulk_actions', 'list_database_tables', 'describe_database_table', 'control_cache' ) as $required_tool ) {
		fmp_assert( in_array( $required_tool, $tool_names, true ), 'Missing tool: ' . $required_tool );
	}
	fmp_assert( ! in_array( 'run_safe_query', $tool_names, true ), 'The request-supplied SQL tool is still exposed.' );
	fmp_assert( count( $tool_names ) >= 55, 'The base tool registry is incomplete.' );

	update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, array( 'summarize_content' ), false );
	$governed_list  = fmp_rpc( $token, 'tools/list' );
	$governed_names = array_column( $governed_list['result']['tools'], 'name' );
	fmp_assert( ! in_array( 'summarize_content', $governed_names, true ) && in_array( 'create_post', $governed_names, true ), 'Tool discovery did not enforce the administrator exposure policy.' );
	$disabled_call = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'summarize_content',
			'arguments' => array( 'content' => 'This valid content must not be summarized while the tool is disabled.' ),
		)
	);
	fmp_assert( ! empty( $disabled_call['result']['isError'] ) && 'tool_disabled' === $disabled_call['result']['structuredContent']['error'], 'A disabled tool was callable directly.' );
	update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, array(), false );

	$component_list = fmp_rpc( $token, 'tools/call', array( 'name' => 'list_flatsome_components', 'arguments' => array() ) );
	$component_data = $component_list['result']['structuredContent'] ?? array();
	fmp_assert( empty( $component_list['result']['isError'] ) && ! empty( $component_data['flatsome_active'] ) && count( $component_data['components'] ?? array() ) >= 29, 'Flatsome component discovery failed.' );

	$development_catalog = dirname( __DIR__, 2 ) . '/languages/mindio-magic-mcp-fa_IR.mo';
	fmp_assert( load_textdomain( 'mindio-magic-mcp', $development_catalog ), 'Persian development catalog could not be loaded.' );
	$development_catalog_loader = static function ( string $locale ) use ( $development_catalog ): void {
		if ( 'fa_IR' === $locale ) {
			load_textdomain( 'mindio-magic-mcp', $development_catalog );
		}
	};
	add_action( 'change_locale', $development_catalog_loader );
	$invalid_fa = fmp_rpc( $token, 'tools/call', array( 'name' => 'create_post', 'arguments' => array( 'response_locale' => 'fa_IR' ) ) );
	fmp_assert( ! empty( $invalid_fa['result']['isError'] ) && str_contains( (string) $invalid_fa['result']['structuredContent']['message'], 'الزامی' ), 'Persian response localization failed.' );
	$invalid_en = fmp_rpc( $token, 'tools/call', array( 'name' => 'create_post', 'arguments' => array( 'response_locale' => 'en_US' ) ) );
	fmp_assert( ! empty( $invalid_en['result']['isError'] ) && str_contains( (string) $invalid_en['result']['structuredContent']['message'], 'is required' ), 'Per-call English response localization failed.' );
	remove_action( 'change_locale', $development_catalog_loader );

	$summary = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'summarize_content',
			'arguments' => array( 'content' => '<h2>Summary source</h2><p>One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twenty-one.</p>', 'target_words' => 20 ),
		)
	);
	fmp_assert( empty( $summary['result']['isError'] ) && 'local_extractive' === $summary['result']['structuredContent']['provider'], 'Local automation fallback failed.' );

	$media = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'upload_media',
			'arguments' => array(
				'filename'    => 'flatsome-mcp-smoke.png',
				'data_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
				'alt_text'   => 'تصویر آزمایشی',
			),
		)
	);
	fmp_assert( empty( $media['result']['isError'] ), 'Media upload failed: ' . wp_json_encode( $media, JSON_UNESCAPED_UNICODE ) );
	$media_id = (int) $media['result']['structuredContent']['media_id'];
	fmp_assert( $media_id > 0 && wp_attachment_is_image( $media_id ), 'Uploaded media is not an image attachment.' );
	$media_deleted = fmp_rpc( $token, 'tools/call', array( 'name' => 'delete_media', 'arguments' => array( 'media_id' => $media_id, 'confirm' => true ) ) );
	fmp_assert( empty( $media_deleted['result']['isError'] ), 'Media deletion failed.' );
	$media_id = 0;

	$blocked_webhook = fmp_rpc(
		$token,
		'tools/call',
		array( 'name' => 'register_webhook', 'arguments' => array( 'name' => 'Blocked local target', 'url' => 'https://127.0.0.1/hook', 'events' => array( 'post_created' ) ) )
	);
	fmp_assert( ! empty( $blocked_webhook['result']['isError'] ) && 'private_url' === $blocked_webhook['result']['structuredContent']['error'], 'Webhook SSRF guard failed.' );

	$removed_query = fmp_rpc(
		$token,
		'tools/call',
		array( 'name' => 'run_safe_query', 'arguments' => array( 'sql' => 'SELECT * FROM ' . $GLOBALS['wpdb']->users ) )
	);
	fmp_assert( -32602 === ( $removed_query['error']['code'] ?? null ) && str_contains( (string) ( $removed_query['error']['message'] ?? '' ), 'Unknown tool' ), 'Request-supplied SQL still reached a tool callback: ' . wp_json_encode( $removed_query ) );

	$database_settings                              = $original_settings;
	$database_settings['allow_database_inspection'] = true;
	update_option( 'mindio_magic_mcp_settings', $database_settings, false );
	$table_list = fmp_rpc(
		$token,
		'tools/call',
		array( 'name' => 'list_database_tables', 'arguments' => array() )
	);
	$table_names = array_column( (array) ( $table_list['result']['structuredContent']['tables'] ?? array() ), 'table' );
	fmp_assert( empty( $table_list['result']['isError'] ) && in_array( 'posts', $table_names, true ) && ! in_array( 'users', $table_names, true ), 'Prepared database inspection did not preserve the safe table inventory.' );
	$table_description = fmp_rpc(
		$token,
		'tools/call',
		array( 'name' => 'describe_database_table', 'arguments' => array( 'table' => 'posts' ) )
	);
	$column_names = array_column( (array) ( $table_description['result']['structuredContent']['columns'] ?? array() ), 'name' );
	fmp_assert( empty( $table_description['result']['isError'] ) && in_array( 'ID', $column_names, true ), 'Prepared database schema inspection no longer works.' );
	update_option( 'mindio_magic_mcp_settings', $original_settings, false );

	$created = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'create_flatsome_page',
			'arguments' => array(
				'title'          => 'Mindio Magic MCP Smoke Test',
				'status'         => 'draft',
				'direction'      => 'auto',
				'content_locale' => 'fa_IR',
				'sections'       => array(
					array(
						'label'            => 'Hero',
						'background_color' => '#f4f4f4',
						'padding'          => '60px',
						'rows'             => array(
							array(
								'horizontal_align' => 'center',
								'columns'          => array(
									array(
										'span'        => 10,
										'span_mobile' => 12,
									'elements'    => array(
										array( 'type' => 'title', 'text' => 'صفحه آزمایشی', 'tag' => 'h1', 'style' => 'center' ),
										array( 'type' => 'text', 'align' => 'center', 'content' => '<p>ساخته‌شده با <strong>Mindio Magic MCP</strong>.</p>' ),
										array(
											'type'       => 'accordion',
											'faq_schema' => true,
											'items'      => array(
												array( 'title' => 'پرسش', 'elements' => array( array( 'type' => 'text', 'content' => '<p>پاسخ آزمایشی</p>' ) ) ),
											),
										),
									),
									),
								),
							),
						),
					),
				),
			),
		)
	);
	fmp_assert( empty( $created['result']['isError'] ), 'Flatsome page creation returned an error.' );
	$page = $created['result']['structuredContent'];
	$page_id = (int) $page['post_id'];
	$column_id = (string) $page['new_node_ids']['columns'][0];
	$content = (string) get_post_field( 'post_content', $page_id );
	fmp_assert( str_contains( $content, '[section' ) && str_contains( $content, '[title' ) && str_contains( $content, '[accordion' ) && str_contains( $content, 'fmp-rtl' ), 'Generated native-first UX Builder content is invalid.' );
	fmp_assert( ! str_contains( $content, '<h1>' ) && 4 === (int) ( $page['render_report']['native_count'] ?? 0 ) && 0 === (int) ( $page['render_report']['fallback_count'] ?? -1 ), 'Native component reporting or heading rendering failed.' );
	$rendered = do_shortcode( $content );
	fmp_assert( str_contains( $rendered, '<section' ) && str_contains( $rendered, 'صفحه آزمایشی' ), 'Flatsome did not render the generated shortcodes.' );

	$added = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'add_element',
			'arguments' => array(
				'post_id'              => $page_id,
				'column_id'            => $column_id,
				'expected_modified_gmt' => (string) $page['modified_gmt'],
				'element'              => array( 'type' => 'button', 'text' => 'ادامه', 'link' => 'https://example.com', 'style' => 'outline' ),
			),
		)
	);
	fmp_assert(
		empty( $added['result']['isError'] ) && 1 === (int) ( $added['result']['structuredContent']['render_report']['native_count'] ?? 0 ) && str_contains( (string) get_post_field( 'post_content', $page_id ), '[button' ),
		'Incremental element insertion failed: ' . wp_json_encode( $added, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);

	$legacy_text = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'add_element',
			'arguments' => array( 'post_id' => $page_id, 'column_id' => $column_id, 'element' => array( 'type' => 'text', 'html' => '<p>Legacy</p>' ) ),
		)
	);
	fmp_assert( ! empty( $legacy_text['result']['isError'] ) && 'invalid_arguments' === $legacy_text['result']['structuredContent']['error'], 'The removed text.html contract was still accepted.' );

	$fallback = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'add_element',
			'arguments' => array(
				'post_id'              => $page_id,
				'column_id'            => $column_id,
				'expected_modified_gmt' => (string) $added['result']['structuredContent']['modified_gmt'],
				'element'              => array( 'type' => 'html', 'reason' => 'Integration fallback', 'html' => '<div onclick="alert(1)">Safe [button]</div><script>alert(1)</script>' ),
			),
		)
	);
	$fallback_content = (string) get_post_field( 'post_content', $page_id );
	fmp_assert( empty( $fallback['result']['isError'] ) && 1 === (int) ( $fallback['result']['structuredContent']['render_report']['fallback_count'] ?? 0 ), 'Explicit HTML fallback was not reported.' );
	fmp_assert( str_contains( $fallback_content, '[ux_html' ) && ! str_contains( $fallback_content, '<script' ) && ! str_contains( $fallback_content, 'onclick=' ) && str_contains( $fallback_content, '&#91;button&#93;' ), 'HTML fallback sanitization failed.' );

	$seo = fmp_rpc(
		$token,
		'tools/call',
		array(
			'name'      => 'update_meta',
			'arguments' => array(
				'post_id'          => $page_id,
				'meta_title'      => 'Smoke SEO',
				'meta_description' => 'Smoke SEO description',
				'canonical_url'   => home_url( '/flatsome-mcp-smoke-canonical/' ),
				'og_title'        => 'Smoke Open Graph',
				'robots'           => array( 'index' => false, 'follow' => true ),
				'schema'           => array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'Smoke SEO' ),
			),
		)
	);
	fmp_assert( empty( $seo['result']['isError'] ) && 'Smoke SEO' === $seo['result']['structuredContent']['meta']['meta_title'], 'SEO metadata round-trip failed.' );
	fmp_assert( 'generic' === $seo['result']['structuredContent']['provider'], 'The smoke fixture unexpectedly loaded another SEO provider.' );
	global $wp_query;
	$previous_query = $wp_query;
	$wp_query       = new WP_Query( array( 'page_id' => $page_id, 'post_status' => 'draft' ) );
	ob_start();
	do_action( 'wp_head' );
	$head_output = (string) ob_get_clean();
	fmp_assert( str_contains( $head_output, 'Smoke SEO description' ) && str_contains( $head_output, 'application/ld+json' ), 'Plugin-neutral frontend SEO output failed.' );
	fmp_assert( 'Smoke SEO' === apply_filters( 'pre_get_document_title', '' ), 'Plugin-neutral document title filter failed.' );
	fmp_assert( home_url( '/flatsome-mcp-smoke-canonical/' ) === apply_filters( 'get_canonical_url', get_permalink( $page_id ), get_post( $page_id ) ), 'Plugin-neutral canonical filter failed.' );
	$wp_query = $previous_query;

	$post = fmp_rpc(
		$token,
		'tools/call',
		array( 'name' => 'create_post', 'arguments' => array( 'title' => 'MCP CRUD Smoke Test', 'content' => '<p onclick="alert(1)">Test <strong>content</strong></p><script>alert(1)</script>', 'status' => 'draft' ) )
	);
	fmp_assert( empty( $post['result']['isError'] ), 'Post creation failed.' );
	$post_id = (int) $post['result']['structuredContent']['post_id'];
	$stored_post_content = (string) get_post_field( 'post_content', $post_id );
	fmp_assert( str_contains( $stored_post_content, '<strong>content</strong>' ) && ! str_contains( $stored_post_content, '<script' ) && ! str_contains( $stored_post_content, 'onclick=' ), 'Agent-supplied post HTML was not safely filtered.' );
	$deleted = fmp_rpc( $token, 'tools/call', array( 'name' => 'delete_post', 'arguments' => array( 'post_id' => $post_id, 'force' => true, 'confirm' => true ) ) );
	fmp_assert( empty( $deleted['result']['isError'] ), 'Post deletion failed.' );
	$post_id = 0;

	$read_credential = $auth->create_api_key( (int) $admins[0], \MindioMagicMCP\Auth::SCOPE_READ, 'Integration read-only test' );
	fmp_assert( ! is_wp_error( $read_credential ), 'Could not create read-only credential.' );
	$read_list = fmp_rpc( (string) $read_credential['token'], 'tools/list' );
	$read_names = array_column( $read_list['result']['tools'], 'name' );
	fmp_assert( ! in_array( 'create_post', $read_names, true ) && in_array( 'list_posts', $read_names, true ), 'Tool discovery did not enforce scope.' );
	$read_write = fmp_rpc(
		(string) $read_credential['token'],
		'tools/call',
		array( 'name' => 'create_post', 'arguments' => array( 'title' => 'Must not be created' ) )
	);
	fmp_assert( ! empty( $read_write['result']['isError'] ) && 'insufficient_scope' === $read_write['result']['structuredContent']['error'], 'Read-only credential performed a write.' );

	$origin_request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/mcp' );
	$origin_request->set_header( 'Authorization', 'Bearer ' . $token );
	$origin_request->set_header( 'Content-Type', 'application/json' );
	$origin_request->set_header( 'Origin', 'https://untrusted.example' );
	$origin_request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping' ) ) );
	$origin_response = rest_get_server()->dispatch( $origin_request );
	fmp_assert( 403 === $origin_response->get_status(), 'Untrusted browser Origin was accepted.' );

	$notification = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/mcp' );
	$notification->set_header( 'Authorization', 'Bearer ' . $token );
	$notification->set_header( 'Content-Type', 'application/json' );
	$notification->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) );
	fmp_assert( 202 === rest_get_server()->dispatch( $notification )->get_status(), 'MCP notification was not accepted.' );

	wp_set_current_user( 0 );
	$anonymous = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/mcp' );
	$anonymous->set_header( 'Content-Type', 'application/json' );
	$anonymous->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 100, 'method' => 'ping' ) ) );
	$anonymous_response = rest_get_server()->dispatch( $anonymous );
	fmp_assert( 401 === $anonymous_response->get_status() && str_contains( (string) $anonymous_response->get_headers()['WWW-Authenticate'], 'oauth-protected-resource' ), 'Unauthenticated request did not receive OAuth discovery metadata.' );

	$metadata_request = new WP_REST_Request( 'GET', '/flatsome-mcp/v1/oauth/protected-resource' );
	$metadata_response = rest_get_server()->dispatch( $metadata_request );
	fmp_assert( 200 === $metadata_response->get_status() && rest_url( 'flatsome-mcp/v1/mcp' ) === $metadata_response->get_data()['resource'], 'OAuth protected-resource metadata is invalid.' );

	$register_request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/oauth/register' );
	$register_request->set_header( 'Content-Type', 'application/json' );
	$register_request->set_body(
		wp_json_encode(
			array(
				'client_name'   => 'Mindio Magic MCP integration test',
				'redirect_uris' => array( 'http://127.0.0.1:8765/callback' ),
			)
		)
	);
	$register_response = rest_get_server()->dispatch( $register_request );
	$registered_client = $register_response->get_data();
	fmp_assert( 201 === $register_response->get_status() && str_starts_with( (string) $registered_client['client_id'], 'fmc_' ), 'OAuth dynamic client registration failed.' );
	$oauth_client_id = (string) $registered_client['client_id'];
	$resource = untrailingslashit( rest_url( 'flatsome-mcp/v1/mcp' ) );
	$issued_oauth = $auth->issue_oauth_tokens( (int) $admins[0], \MindioMagicMCP\Auth::SCOPE_READ, $oauth_client_id, $resource );
	fmp_assert( ! is_wp_error( $issued_oauth ), 'OAuth token issuance failed.' );
	preg_match( '/^fmo_([a-f0-9]{16})_/', (string) $issued_oauth['access_token'], $oauth_access_match );
	preg_match( '/^fmr_([a-f0-9]{16})_/', (string) $issued_oauth['refresh_token'], $oauth_refresh_match );
	$oauth_token_ids[]   = (string) ( $oauth_access_match[1] ?? '' );
	$oauth_refresh_ids[] = (string) ( $oauth_refresh_match[1] ?? '' );
	$oauth_ping = fmp_rpc( (string) $issued_oauth['access_token'], 'ping' );
	fmp_assert( isset( $oauth_ping['result'] ) && is_array( $oauth_ping['result'] ), 'OAuth access token was not accepted by MCP.' );

	$refresh_request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/oauth/token' );
	$refresh_request->set_body_params(
		array(
			'grant_type'    => 'refresh_token',
			'client_id'     => $oauth_client_id,
			'refresh_token' => (string) $issued_oauth['refresh_token'],
			'resource'      => $resource,
		)
	);
	$refresh_response = rest_get_server()->dispatch( $refresh_request );
	$rotated_oauth    = $refresh_response->get_data();
	fmp_assert( 200 === $refresh_response->get_status() && $issued_oauth['refresh_token'] !== $rotated_oauth['refresh_token'], 'OAuth refresh-token rotation failed.' );
	preg_match( '/^fmo_([a-f0-9]{16})_/', (string) $rotated_oauth['access_token'], $rotated_access_match );
	preg_match( '/^fmr_([a-f0-9]{16})_/', (string) $rotated_oauth['refresh_token'], $rotated_refresh_match );
	$oauth_token_ids[]   = (string) ( $rotated_access_match[1] ?? '' );
	$oauth_refresh_ids[] = (string) ( $rotated_refresh_match[1] ?? '' );
	$replay_response = rest_get_server()->dispatch( $refresh_request );
	fmp_assert( 400 === $replay_response->get_status() && 'invalid_grant' === $replay_response->get_data()['error'], 'A rotated refresh token was accepted twice.' );

	echo wp_json_encode(
		array(
			'ok'         => true,
			'tool_count' => count( $tool_names ),
			'page_id'    => $page_id,
			'rtl_render' => true,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . PHP_EOL;
} finally {
	update_option( 'mindio_magic_mcp_settings', $original_settings, false );
	if ( null === $original_disabled_tools ) {
		delete_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION );
	} else {
		update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, $original_disabled_tools, false );
	}
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $media_id ) {
		wp_delete_attachment( $media_id, true );
	}
	if ( $page_id ) {
		wp_delete_post( $page_id, true );
	}
	$auth->revoke_token( (string) $credential['id'] );
	if ( is_array( $read_credential ) ) {
		$auth->revoke_token( (string) $read_credential['id'] );
	}
	if ( '' !== $oauth_client_id ) {
		$clients = get_option( 'mindio_magic_mcp_oauth_clients', array() );
		unset( $clients[ $oauth_client_id ] );
		update_option( 'mindio_magic_mcp_oauth_clients', $clients, false );
		$auth->revoke_client_tokens( $oauth_client_id );
	}
	$oauth_tokens = get_option( 'mindio_magic_mcp_tokens', array() );
	foreach ( array_filter( $oauth_token_ids ) as $oauth_token_id ) {
		unset( $oauth_tokens[ $oauth_token_id ] );
	}
	update_option( 'mindio_magic_mcp_tokens', $oauth_tokens, false );
	$oauth_refresh_tokens = get_option( 'mindio_magic_mcp_refresh_tokens', array() );
	foreach ( array_filter( $oauth_refresh_ids ) as $oauth_refresh_id ) {
		unset( $oauth_refresh_tokens[ $oauth_refresh_id ] );
	}
	update_option( 'mindio_magic_mcp_refresh_tokens', $oauth_refresh_tokens, false );
	global $wpdb;
	$wpdb->delete( \MindioMagicMCP\Installer::audit_table(), array( 'token_id' => (string) $credential['id'] ) );
	if ( is_array( $read_credential ) ) {
		$wpdb->delete( \MindioMagicMCP\Installer::audit_table(), array( 'token_id' => (string) $read_credential['id'] ) );
	}
}
