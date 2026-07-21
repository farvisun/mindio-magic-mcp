<?php
/**
 * OAuth discovery-alias, PKCE exchange, and standalone UI checks.
 *
 * Run with WP_PATH=/path/to/wordpress php tests/integration/oauth-flow.php.
 *
 * @package FlatsomeMCP
 */

declare(strict_types=1);

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\FlatsomeMCP\OAuth_Server' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the OAuth flow test.' );
}

/** @throws RuntimeException */
function fmp_oauth_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return mixed */
function fmp_oauth_invoke( object $object, string $method, mixed ...$arguments ): mixed {
	$reflection = new ReflectionMethod( $object, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( $object, ...$arguments );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
fmp_oauth_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0] );
switch_to_locale( 'en_US' );

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php?page=flatsome-mcp-oauth-authorize';

$auth         = new \FlatsomeMCP\Auth();
$oauth        = new \FlatsomeMCP\OAuth_Server( $auth, new \FlatsomeMCP\Rate_Limiter() );
$client_id    = 'fmc_test_' . wp_generate_password( 24, false, false );
$redirect_uri = 'http://127.0.0.1:48765/callback/flatsome-mcp';
$clients      = get_option( 'flatsome_mcp_oauth_clients', array() );
$clients      = is_array( $clients ) ? $clients : array();
$original_clients = $clients;
$access_id    = '';
$refresh_id   = '';

$clients[ $client_id ] = array(
	'client_id'     => $client_id,
	'client_name'   => 'Integration MCP Client',
	'redirect_uris' => array( $redirect_uri ),
);
update_option( 'flatsome_mcp_oauth_clients', $clients, false );

try {
	$oauth->register_authorization_page();
	fmp_oauth_assert(
		false !== has_action( 'load-admin_page_flatsome-mcp-oauth-authorize', array( $oauth, 'render_authorization_page' ) ),
		'The standalone authorization document is not attached before WordPress renders its admin chrome.'
	);

	$canonical_resource = untrailingslashit( rest_url( 'flatsome-mcp/v1/mcp' ) );
	$discovery_resource = untrailingslashit( home_url( '/.well-known/oauth-authorization-server' ) );

	$normalized = fmp_oauth_invoke( $oauth, 'validated_resource', $discovery_resource );
	fmp_oauth_assert( $canonical_resource === $normalized, 'The authorization-server discovery alias was not canonicalized to the MCP endpoint.' );

	$protected_alias = fmp_oauth_invoke( $oauth, 'validated_resource', untrailingslashit( home_url( '/.well-known/oauth-protected-resource' ) ) );
	fmp_oauth_assert( $canonical_resource === $protected_alias, 'The protected-resource discovery alias was not canonicalized to the MCP endpoint.' );

	$external = fmp_oauth_invoke( $oauth, 'validated_resource', 'https://untrusted.example/mcp' );
	fmp_oauth_assert( is_wp_error( $external ) && 'invalid_target' === $external->get_error_code(), 'An unrelated OAuth resource was accepted.' );

	$metadata = $oauth->authorization_metadata();
	fmp_oauth_assert( array( $canonical_resource ) === ( $metadata['protected_resources'] ?? null ), 'Authorization metadata does not advertise the canonical protected resource.' );

	$verifier  = str_repeat( 'A', 43 );
	$challenge = \FlatsomeMCP\Secret_Box::base64url_encode( hash( 'sha256', $verifier, true ) );
	$params    = array(
		'response_type'         => 'code',
		'client_id'             => $client_id,
		'redirect_uri'          => $redirect_uri,
		'scope'                 => 'read_only editor admin',
		'state'                 => 'oauth-integration-state',
		'code_challenge'        => $challenge,
		'code_challenge_method' => 'S256',
		'resource'              => $discovery_resource,
	);
	$validated = fmp_oauth_invoke( $oauth, 'validate_authorization_request', $params );
	fmp_oauth_assert( is_array( $validated ) && $canonical_resource === $validated['resource'], 'The complete authorization request did not accept and canonicalize the discovery resource.' );
	fmp_oauth_assert( \FlatsomeMCP\Auth::SCOPE_ADMIN === $validated['scope'], 'The requested scope hierarchy was not normalized.' );

	ob_start();
	fmp_oauth_invoke( $oauth, 'render_authorization_consent', $clients[ $client_id ], \FlatsomeMCP\Auth::SCOPE_ADMIN, $params );
	$consent_html = (string) ob_get_clean();
	fmp_oauth_assert( str_contains( $consent_html, '<!doctype html>' ), 'The authorization screen is not a standalone document.' );
	fmp_oauth_assert( str_contains( $consent_html, 'assets/css/oauth.css' ), 'The authorization stylesheet is missing.' );
	fmp_oauth_assert( str_contains( $consent_html, 'Integration MCP Client' ), 'The authorization screen does not identify the client.' );
	fmp_oauth_assert( str_contains( $consent_html, 'value="approve"' ) && str_contains( $consent_html, 'value="deny"' ), 'Approve and reject actions are missing.' );
	fmp_oauth_assert( ! str_contains( $consent_html, 'wp-admin-bar' ) && ! str_contains( $consent_html, 'wp-menu' ), 'WordPress admin chrome leaked into the standalone authorization screen.' );

	$code = fmp_oauth_invoke(
		$oauth,
		'issue_authorization_code',
		(int) $admins[0],
		$client_id,
		$redirect_uri,
		\FlatsomeMCP\Auth::SCOPE_ADMIN,
		$challenge,
		$canonical_resource
	);

	$token_request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/oauth/token' );
	$token_request->set_body_params(
		array(
			'grant_type'    => 'authorization_code',
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'code'          => $code,
			'code_verifier' => $verifier,
			'resource'      => $discovery_resource,
		)
	);
	$token_response = $oauth->token( $token_request );
	$token_data     = $token_response->get_data();
	fmp_oauth_assert( 200 === $token_response->get_status(), 'The PKCE token exchange failed when the client repeated the discovery resource alias.' );
	fmp_oauth_assert( is_array( $token_data ) && ! empty( $token_data['access_token'] ) && ! empty( $token_data['refresh_token'] ), 'The token exchange did not issue both tokens.' );

	preg_match( '/^fmo_([a-f0-9]{16})_/', (string) $token_data['access_token'], $access_match );
	preg_match( '/^fmr_([a-f0-9]{16})_/', (string) $token_data['refresh_token'], $refresh_match );
	$access_id  = (string) ( $access_match[1] ?? '' );
	$refresh_id = (string) ( $refresh_match[1] ?? '' );
	fmp_oauth_assert( '' !== $access_id && '' !== $refresh_id, 'Issued OAuth token identifiers are malformed.' );

	$stored_tokens = get_option( 'flatsome_mcp_tokens', array() );
	fmp_oauth_assert( $canonical_resource === ( $stored_tokens[ $access_id ]['resource'] ?? '' ), 'The access token was not audience-bound to the canonical MCP endpoint.' );

	$result_url = add_query_arg( array( 'code' => 'test-code', 'state' => 'test-state' ), $redirect_uri );
	ob_start();
	fmp_oauth_invoke( $oauth, 'render_authorization_result', true, $clients[ $client_id ], \FlatsomeMCP\Auth::SCOPE_ADMIN, $result_url );
	$result_html = (string) ob_get_clean();
	fmp_oauth_assert( str_contains( $result_html, 'data-fmp-oauth-result' ), 'The authorization result handoff screen is missing.' );
	fmp_oauth_assert( str_contains( $result_html, 'assets/js/oauth.js' ), 'The automatic client handoff script is missing.' );
	fmp_oauth_assert( str_contains( $result_html, 'test-code' ), 'The result screen did not preserve the client callback.' );

	unload_textdomain( 'mindio-magic-mcp' );
	$development_catalog = dirname( __DIR__, 2 ) . '/languages/mindio-magic-mcp-fa_IR.mo';
	fmp_oauth_assert( load_textdomain( 'mindio-magic-mcp', $development_catalog ), 'Persian OAuth translations could not be loaded.' );
	ob_start();
	fmp_oauth_invoke( $oauth, 'render_authorization_consent', $clients[ $client_id ], \FlatsomeMCP\Auth::SCOPE_ADMIN, $params );
	$persian_html = (string) ob_get_clean();
	fmp_oauth_assert( str_contains( $persian_html, 'تأیید و اتصال' ), 'The Persian approve action is not translated.' );
	fmp_oauth_assert( str_contains( $persian_html, 'دسترسی مدیر' ), 'The Persian permission summary is not translated.' );
} finally {
	update_option( 'flatsome_mcp_oauth_clients', $original_clients, false );

	if ( '' !== $access_id ) {
		$tokens = get_option( 'flatsome_mcp_tokens', array() );
		$tokens = is_array( $tokens ) ? $tokens : array();
		unset( $tokens[ $access_id ] );
		update_option( 'flatsome_mcp_tokens', $tokens, false );
	}
	if ( '' !== $refresh_id ) {
		$refresh_tokens = get_option( 'flatsome_mcp_refresh_tokens', array() );
		$refresh_tokens = is_array( $refresh_tokens ) ? $refresh_tokens : array();
		unset( $refresh_tokens[ $refresh_id ] );
		update_option( 'flatsome_mcp_refresh_tokens', $refresh_tokens, false );
	}
}

echo wp_json_encode(
	array(
		'ok'                 => true,
		'discovery_alias'    => true,
		'pkce_exchange'      => true,
		'canonical_audience' => true,
		'standalone_ui'      => true,
		'persian_ui'         => true,
	),
	JSON_UNESCAPED_SLASHES
) . PHP_EOL;
