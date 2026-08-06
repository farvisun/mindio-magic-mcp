<?php
/**
 * OAuth 2.1 authorization-code server with PKCE for remote MCP clients.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuth_Server {
	private const AUTHORIZATION_PAGE   = 'mindio-magic-mcp-oauth-authorize';
	private const AUTHORIZATION_ACTION = 'mindio_magic_mcp_oauth_authorize';

	private Auth $auth;
	private Rate_Limiter $rate_limiter;

	public function __construct( Auth $auth, Rate_Limiter $rate_limiter ) {
		$this->auth         = $auth;
		$this->rate_limiter = $rate_limiter;
	}

	public function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_well_known_document' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_authorization_page' ) );
	}

	public static function register_rewrite_rules(): void {
		add_rewrite_rule( '^\.well-known/oauth-protected-resource/?$', 'index.php?mindio_magic_mcp_well_known=resource', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/?$', 'index.php?mindio_magic_mcp_well_known=authorization_server', 'top' );
	}

	public function query_vars( array $vars ): array {
		$vars[] = 'mindio_magic_mcp_well_known';
		return $vars;
	}

	public function serve_well_known_document(): void {
		$type = (string) get_query_var( 'mindio_magic_mcp_well_known' );
		if ( ! in_array( $type, array( 'resource', 'authorization_server' ), true ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		echo wp_json_encode( 'resource' === $type ? $this->resource_metadata() : $this->authorization_metadata(), JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function register_routes(): void {
		$routes = array(
			'/oauth/protected-resource'   => array(
				'methods'             => 'GET',
				'callback'            => fn(): \WP_REST_Response => new \WP_REST_Response( $this->resource_metadata() ),
				'permission_callback' => '__return_true',
			),
			'/oauth/authorization-server' => array(
				'methods'             => 'GET',
				'callback'            => fn(): \WP_REST_Response => new \WP_REST_Response( $this->authorization_metadata() ),
				'permission_callback' => '__return_true',
			),
			'/oauth/register'             => array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_client' ),
				'permission_callback' => '__return_true',
			),
			'/oauth/token'                => array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			),
		);

		// The legacy namespace is the deprecated pre-rename one, kept so issued grants keep working.
		foreach ( array( MINDIO_MAGIC_MCP_REST_NAMESPACE, MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE ) as $namespace ) {
			foreach ( $routes as $route => $args ) {
				register_rest_route( $namespace, $route, $args );
			}
		}
	}

	public function register_authorization_page(): void {
		$hook_suffix = add_submenu_page(
			null,
			__( 'Authorize MCP Client', 'mindio-magic-mcp' ),
			__( 'Authorize MCP Client', 'mindio-magic-mcp' ),
			'read',
			self::AUTHORIZATION_PAGE,
			'__return_null'
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'render_authorization_page' ) );
		}
	}

	/** @return array<string,mixed> */
	public function resource_metadata(): array {
		return array(
			'resource'                              => $this->canonical_resource(),
			'authorization_servers'                 => array( untrailingslashit( home_url( '/' ) ) ),
			'bearer_methods_supported'              => array( 'header' ),
			'scopes_supported'                      => array( Auth::SCOPE_READ, Auth::SCOPE_EDITOR, Auth::SCOPE_ADMIN ),
			'resource_name'                         => get_bloginfo( 'name' ) . ' Mindio Magic MCP',
			'resource_documentation'                => plugins_url( 'README.md', MINDIO_MAGIC_MCP_FILE ),
		);
	}

	/** @return array<string,mixed> */
	public function authorization_metadata(): array {
		return array(
			'issuer'                                => untrailingslashit( home_url( '/' ) ),
			'authorization_endpoint'                => admin_url( 'admin.php?page=' . self::AUTHORIZATION_PAGE ),
			'token_endpoint'                        => rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/oauth/token' ),
			'registration_endpoint'                 => rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/oauth/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( Auth::SCOPE_READ, Auth::SCOPE_EDITOR, Auth::SCOPE_ADMIN ),
			'protected_resources'                   => array( $this->canonical_resource() ),
			'service_documentation'                 => plugins_url( 'README.md', MINDIO_MAGIC_MCP_FILE ),
		);
	}

	public function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		$rate = $this->rate_limiter->consume( $this->request_ip(), 'oauth_register' );
		if ( ! $rate['allowed'] ) {
			return $this->oauth_error( 'temporarily_unavailable', __( 'Too many client registrations.', 'mindio-magic-mcp' ), 429, $rate['retry_after'] );
		}

		$body          = $request->get_json_params();
		$body          = is_array( $body ) ? $body : $request->get_params();
		$redirect_uris = array_values( array_unique( array_map( 'strval', (array) ( $body['redirect_uris'] ?? array() ) ) ) );
		if ( empty( $redirect_uris ) || count( $redirect_uris ) > 10 ) {
			return $this->oauth_error( 'invalid_redirect_uri', __( 'One to ten redirect URIs are required.', 'mindio-magic-mcp' ), 400 );
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! $this->valid_redirect_uri( $uri ) ) {
				return $this->oauth_error( 'invalid_redirect_uri', __( 'A redirect URI is invalid. Use HTTPS or a loopback HTTP URI.', 'mindio-magic-mcp' ), 400 );
			}
		}

		$clients = $this->clients();
		if ( count( $clients ) >= 250 ) {
			return $this->oauth_error( 'temporarily_unavailable', __( 'The OAuth client registry is full. Revoke an unused client before registering another.', 'mindio-magic-mcp' ), 429 );
		}

		$client_id = 'fmc_' . Secret_Box::base64url_encode( random_bytes( 24 ) );
		$record    = array(
			'client_id'                  => $client_id,
			'client_name'                => sanitize_text_field( (string) ( $body['client_name'] ?? __( 'MCP Client', 'mindio-magic-mcp' ) ) ),
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
			'client_id_issued_at'        => time(),
		);
		$clients[ $client_id ] = $record;
		update_option( 'mindio_magic_mcp_oauth_clients', $clients, false );

		$response = new \WP_REST_Response( $record, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		$rate = $this->rate_limiter->consume( $this->request_ip(), 'oauth_token' );
		if ( ! $rate['allowed'] ) {
			return $this->oauth_error( 'temporarily_unavailable', __( 'Too many token requests.', 'mindio-magic-mcp' ), 429, $rate['retry_after'] );
		}

		$params     = $request->get_params();
		$grant_type = sanitize_text_field( (string) ( $params['grant_type'] ?? '' ) );
		$client_id  = sanitize_text_field( (string) ( $params['client_id'] ?? '' ) );
		if ( ! $this->get_client( $client_id ) ) {
			return $this->oauth_error( 'invalid_client', __( 'The OAuth client is unknown.', 'mindio-magic-mcp' ), 401 );
		}

		if ( 'refresh_token' === $grant_type ) {
			$resource = $this->validated_resource( (string) ( $params['resource'] ?? '' ) );
			if ( is_wp_error( $resource ) ) {
				return $this->oauth_error( 'invalid_target', $resource->get_error_message(), 400 );
			}
			$result = $this->auth->rotate_refresh_token(
				(string) ( $params['refresh_token'] ?? '' ),
				$client_id,
				(string) ( $params['scope'] ?? '' ),
				$resource
			);
			return $this->token_result( $result );
		}
		if ( 'authorization_code' !== $grant_type ) {
			return $this->oauth_error( 'unsupported_grant_type', __( 'Only authorization_code and refresh_token are supported.', 'mindio-magic-mcp' ), 400 );
		}

		$code = $this->consume_authorization_code( (string) ( $params['code'] ?? '' ) );
		if ( is_wp_error( $code ) ) {
			return $this->oauth_error( 'invalid_grant', $code->get_error_message(), 400 );
		}
		if ( ! hash_equals( (string) $code['client_id'], $client_id ) || ! hash_equals( (string) $code['redirect_uri'], (string) ( $params['redirect_uri'] ?? '' ) ) ) {
			return $this->oauth_error( 'invalid_grant', __( 'The authorization code context does not match.', 'mindio-magic-mcp' ), 400 );
		}
		$resource = $this->validated_resource( (string) ( $params['resource'] ?? '' ) );
		if ( is_wp_error( $resource ) || ! hash_equals( (string) $code['resource'], is_wp_error( $resource ) ? '' : $resource ) ) {
			return $this->oauth_error( 'invalid_target', is_wp_error( $resource ) ? $resource->get_error_message() : __( 'The authorization code resource does not match.', 'mindio-magic-mcp' ), 400 );
		}

		$verifier = (string) ( $params['code_verifier'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return $this->oauth_error( 'invalid_grant', __( 'A valid PKCE code_verifier is required.', 'mindio-magic-mcp' ), 400 );
		}
		$challenge = Secret_Box::base64url_encode( hash( 'sha256', $verifier, true ) );
		if ( ! hash_equals( (string) $code['code_challenge'], $challenge ) ) {
			return $this->oauth_error( 'invalid_grant', __( 'PKCE verification failed.', 'mindio-magic-mcp' ), 400 );
		}

		return $this->token_result( $this->auth->issue_oauth_tokens( (int) $code['user_id'], (string) $code['scope'], $client_id, $resource ) );
	}

	public function render_authorization_page(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$params         = wp_unslash( 'POST' === $request_method ? $_POST : $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated before any authorization is issued.
		$params         = is_array( $params ) ? $params : array();
		$check          = $this->validate_authorization_request( $params );
		if ( is_wp_error( $check ) ) {
			$this->render_authorization_error( $check );
			exit;
		}
		$client   = $check['client'];
		$scope    = $check['scope'];
		$resource = $check['resource'];

		if ( 'POST' === $request_method ) {
			$nonce = sanitize_text_field( (string) ( $params['_wpnonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, self::AUTHORIZATION_ACTION ) ) {
				$this->render_authorization_error(
					new \WP_Error(
						'expired_request',
						__( 'This authorization request expired. Start the connection again from your MCP client.', 'mindio-magic-mcp' ),
						array( 'status' => 403 )
					)
				);
				exit;
			}

			$decision = sanitize_key( (string) ( $params['decision'] ?? '' ) );
			if ( 'approve' === $decision ) {
				$code = $this->issue_authorization_code(
					get_current_user_id(),
					(string) $params['client_id'],
					(string) $params['redirect_uri'],
					$scope,
					(string) $params['code_challenge'],
					(string) $resource
				);
				$redirect_url = $this->authorization_redirect_url(
					(string) $params['redirect_uri'],
					array(
						'code'  => $code,
						'state' => (string) ( $params['state'] ?? '' ),
					)
				);
				$this->render_authorization_result( true, $client, $scope, $redirect_url );
				exit;
			}

			$redirect_url = $this->authorization_redirect_url(
				(string) $params['redirect_uri'],
				array(
					'error' => 'access_denied',
					'state' => (string) ( $params['state'] ?? '' ),
				)
			);
			$this->render_authorization_result( false, $client, $scope, $redirect_url );
			exit;
		}

		$this->render_authorization_consent( $client, $scope, $params );
		exit;
	}

	/**
	 * Render the standalone OAuth consent document.
	 *
	 * @param array<string,mixed> $client Registered OAuth client.
	 * @param array<string,mixed> $params Validated authorization parameters.
	 */
	private function render_authorization_consent( array $client, string $scope, array $params ): void {
		$client_name = (string) ( $client['client_name'] ?? __( 'MCP Client', 'mindio-magic-mcp' ) );
		$user        = wp_get_current_user();
		$this->authorization_page_start( __( 'Authorize MCP Client', 'mindio-magic-mcp' ), 'fmp-oauth-page--consent' );
		?>
		<main class="fmp-oauth-card" aria-labelledby="fmp-oauth-title">
			<div class="fmp-oauth-card__heading">
				<p class="fmp-oauth-eyebrow"><?php esc_html_e( 'Authorization request', 'mindio-magic-mcp' ); ?></p>
				<h1 id="fmp-oauth-title">
					<?php
					printf(
						/* translators: %s: OAuth client name. */
						esc_html__( 'Allow %s to connect?', 'mindio-magic-mcp' ),
						esc_html( $client_name )
					);
					?>
				</h1>
				<p><?php esc_html_e( 'Review the requested access before connecting this client to your WordPress site.', 'mindio-magic-mcp' ); ?></p>
			</div>

			<div class="fmp-oauth-identity" aria-label="<?php esc_attr_e( 'Signed-in account', 'mindio-magic-mcp' ); ?>">
				<span class="fmp-oauth-identity__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" focusable="false"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0H5Z"/></svg>
				</span>
				<span>
					<small><?php esc_html_e( 'Signed in as', 'mindio-magic-mcp' ); ?></small>
					<strong><?php echo esc_html( $user->display_name ); ?></strong>
				</span>
			</div>

			<dl class="fmp-oauth-details">
				<div>
					<dt><?php esc_html_e( 'Client', 'mindio-magic-mcp' ); ?></dt>
					<dd><?php echo esc_html( $client_name ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'WordPress site', 'mindio-magic-mcp' ); ?></dt>
					<dd><?php echo esc_html( get_bloginfo( 'name' ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Returns to', 'mindio-magic-mcp' ); ?></dt>
					<dd><code><?php echo esc_html( $this->redirect_destination( (string) $params['redirect_uri'] ) ); ?></code></dd>
				</div>
			</dl>

			<section class="fmp-oauth-permission" aria-labelledby="fmp-oauth-permission-title">
				<div class="fmp-oauth-permission__heading">
					<div>
						<p class="fmp-oauth-eyebrow"><?php esc_html_e( 'Requested access', 'mindio-magic-mcp' ); ?></p>
						<h2 id="fmp-oauth-permission-title"><?php echo esc_html( $this->scope_label( $scope ) ); ?></h2>
					</div>
					<code><?php echo esc_html( $scope ); ?></code>
				</div>
				<ul>
					<?php foreach ( $this->scope_permissions( $scope ) as $permission ) : ?>
						<li>
							<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m7.7 14.3-4-4 1.4-1.4 2.6 2.6 7.2-7.2 1.4 1.4-8.6 8.6Z"/></svg>
							<span><?php echo esc_html( $permission ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<div class="fmp-oauth-warning" role="note">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2 2 21h20L12 2Zm1 15h-2v-2h2v2Zm0-4h-2V8h2v5Z"/></svg>
				<p><?php esc_html_e( 'Only approve clients you trust. This client will act with the granted permissions as your current WordPress user.', 'mindio-magic-mcp' ); ?></p>
			</div>

			<form class="fmp-oauth-actions" method="post">
				<?php wp_nonce_field( self::AUTHORIZATION_ACTION ); ?>
				<?php foreach ( $this->authorization_fields() as $field ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) ( $params[ $field ] ?? '' ) ); ?>">
				<?php endforeach; ?>
				<button class="fmp-oauth-button fmp-oauth-button--primary" type="submit" name="decision" value="approve">
					<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m7.7 14.3-4-4 1.4-1.4 2.6 2.6 7.2-7.2 1.4 1.4-8.6 8.6Z"/></svg>
					<?php esc_html_e( 'Approve and connect', 'mindio-magic-mcp' ); ?>
				</button>
				<button class="fmp-oauth-button fmp-oauth-button--secondary" type="submit" name="decision" value="deny"><?php esc_html_e( 'Reject request', 'mindio-magic-mcp' ); ?></button>
			</form>
		</main>
		<?php
		$this->authorization_page_end();
	}

	/**
	 * Render a brief result before handing control back to the OAuth client.
	 *
	 * @param array<string,mixed> $client Registered OAuth client.
	 */
	private function render_authorization_result( bool $approved, array $client, string $scope, string $redirect_url ): void {
		$client_name = (string) ( $client['client_name'] ?? __( 'MCP Client', 'mindio-magic-mcp' ) );
		$title       = $approved ? __( 'Authorization approved', 'mindio-magic-mcp' ) : __( 'Access request rejected', 'mindio-magic-mcp' );
		$this->authorization_page_start( $title, $approved ? 'fmp-oauth-page--success' : 'fmp-oauth-page--denied' );
		?>
		<main
			class="fmp-oauth-card fmp-oauth-card--result"
			data-fmp-oauth-result
			data-redirect-target="<?php echo esc_url( $redirect_url ); ?>"
			data-redirect-delay="1800"
			aria-labelledby="fmp-oauth-result-title"
		>
			<div class="fmp-oauth-result-icon" aria-hidden="true">
				<?php if ( $approved ) : ?>
					<svg viewBox="0 0 28 28" focusable="false"><path d="m10.7 20.1-6-6 2-2 4 4 10.6-10.6 2 2-12.6 12.6Z"/></svg>
				<?php else : ?>
					<svg viewBox="0 0 28 28" focusable="false"><path d="m7.8 5.8 6.2 6.2 6.2-6.2 2 2-6.2 6.2 6.2 6.2-2 2-6.2-6.2-6.2 6.2-2-2 6.2-6.2-6.2-6.2 2-2Z"/></svg>
				<?php endif; ?>
			</div>
			<p class="fmp-oauth-eyebrow"><?php echo esc_html( $approved ? __( 'Authorization complete', 'mindio-magic-mcp' ) : __( 'Request closed', 'mindio-magic-mcp' ) ); ?></p>
			<h1 id="fmp-oauth-result-title"><?php echo esc_html( $title ); ?></h1>
			<p class="fmp-oauth-result-copy">
				<?php
				echo esc_html(
					$approved
						? __( 'The client can now finish authentication with the access you granted.', 'mindio-magic-mcp' )
						: __( 'No access was granted. The client will receive your decision.', 'mindio-magic-mcp' )
				);
				?>
			</p>

			<dl class="fmp-oauth-result-details">
				<div><dt><?php esc_html_e( 'Client', 'mindio-magic-mcp' ); ?></dt><dd><?php echo esc_html( $client_name ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Access', 'mindio-magic-mcp' ); ?></dt><dd><?php echo esc_html( $approved ? $this->scope_label( $scope ) : __( 'Not granted', 'mindio-magic-mcp' ) ); ?></dd></div>
			</dl>

			<div class="fmp-oauth-handoff" aria-live="polite">
				<span class="fmp-oauth-handoff__indicator" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Returning securely to the MCP client…', 'mindio-magic-mcp' ); ?></span>
			</div>
			<a class="fmp-oauth-button fmp-oauth-button--primary fmp-oauth-button--full" href="<?php echo esc_url( $redirect_url ); ?>">
				<?php esc_html_e( 'Continue to client', 'mindio-magic-mcp' ); ?>
			</a>
			<noscript><p class="fmp-oauth-noscript"><?php esc_html_e( 'Automatic return requires JavaScript. Select Continue to finish the authorization flow.', 'mindio-magic-mcp' ); ?></p></noscript>
		</main>
		<?php
		$this->authorization_page_end( true );
	}

	private function render_authorization_error( \WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$this->authorization_page_start( __( 'Invalid OAuth request', 'mindio-magic-mcp' ), 'fmp-oauth-page--error', $status );
		?>
		<main class="fmp-oauth-card fmp-oauth-card--result" aria-labelledby="fmp-oauth-error-title">
			<div class="fmp-oauth-result-icon" aria-hidden="true">
				<svg viewBox="0 0 28 28" focusable="false"><path d="M14 3 2.5 24h23L14 3Zm1.4 16.4h-2.8v-2.8h2.8v2.8Zm0-5h-2.8V9h2.8v5.4Z"/></svg>
			</div>
			<p class="fmp-oauth-eyebrow"><?php esc_html_e( 'Unable to continue', 'mindio-magic-mcp' ); ?></p>
			<h1 id="fmp-oauth-error-title"><?php esc_html_e( 'Invalid authorization request', 'mindio-magic-mcp' ); ?></h1>
			<p class="fmp-oauth-result-copy"><?php echo esc_html( $error->get_error_message() ); ?></p>
			<div class="fmp-oauth-error-code">
				<span><?php esc_html_e( 'Error code', 'mindio-magic-mcp' ); ?></span>
				<code><?php echo esc_html( $error->get_error_code() ); ?></code>
			</div>
			<p class="fmp-oauth-guidance"><?php esc_html_e( 'Start the connection again from your MCP client. If the problem continues, verify the client URL and OAuth configuration.', 'mindio-magic-mcp' ); ?></p>
			<a class="fmp-oauth-button fmp-oauth-button--secondary fmp-oauth-button--full" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Return to WordPress', 'mindio-magic-mcp' ); ?></a>
		</main>
		<?php
		$this->authorization_page_end();
	}

	private function authorization_page_start( string $title, string $body_class, int $status = 200 ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
		header( "Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'" );
		wp_enqueue_style( 'mindio-magic-mcp-oauth', MINDIO_MAGIC_MCP_URL . 'assets/css/oauth.css', array(), MINDIO_MAGIC_MCP_VERSION );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php echo esc_attr( get_option( 'blog_charset', 'UTF-8' ) ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex,nofollow,noarchive">
			<title><?php echo esc_html( $title ); ?> · Mindio Magic MCP</title>
			<?php wp_print_styles( 'mindio-magic-mcp-oauth' ); ?>
		</head>
		<body class="fmp-oauth-page <?php echo esc_attr( $body_class ); ?>">
			<div class="fmp-oauth-shell">
				<header class="fmp-oauth-header">
					<div class="fmp-oauth-brand">
						<span class="fmp-oauth-brand__mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false"><path d="M9.6 15.8 8.2 14.4l6.2-6.2a2.8 2.8 0 0 1 4 4l-3 3-1.4-1.4 3-3a.8.8 0 1 0-1.2-1.2l-6.2 6.2Zm4.8-7.6 1.4 1.4-6.2 6.2a2.8 2.8 0 1 1-4-4l3-3L10 9.9l-3 3A.8.8 0 1 0 8.2 14l6.2-6.2Z"/></svg>
						</span>
						<span>
							<strong>Mindio Magic MCP</strong>
							<small><?php esc_html_e( 'Secure WordPress authorization', 'mindio-magic-mcp' ); ?></small>
						</span>
					</div>
					<span class="fmp-oauth-protocol"><i aria-hidden="true"></i> OAuth 2.1 + PKCE</span>
				</header>
		<?php
	}

	private function authorization_page_end( bool $load_script = false ): void {
		?>
				<footer class="fmp-oauth-footer">
					<span><?php esc_html_e( 'Protected by WordPress permissions', 'mindio-magic-mcp' ); ?></span>
					<span><?php esc_html_e( 'Tokens are bound to this MCP endpoint', 'mindio-magic-mcp' ); ?></span>
				</footer>
			</div>
			<?php
			if ( $load_script ) {
				wp_enqueue_script( 'mindio-magic-mcp-oauth', MINDIO_MAGIC_MCP_URL . 'assets/js/oauth.js', array(), MINDIO_MAGIC_MCP_VERSION, true );
				wp_print_footer_scripts();
			}
			?>
		</body>
		</html>
		<?php
	}

	private function scope_label( string $scope ): string {
		return match ( $scope ) {
			Auth::SCOPE_ADMIN  => __( 'Administrator access', 'mindio-magic-mcp' ),
			Auth::SCOPE_EDITOR => __( 'Editor access', 'mindio-magic-mcp' ),
			default            => __( 'Read-only access', 'mindio-magic-mcp' ),
		};
	}

	/** @return string[] */
	private function scope_permissions( string $scope ): array {
		$permissions = array(
			__( 'View posts, pages, media, settings, and operational activity.', 'mindio-magic-mcp' ),
		);

		if ( in_array( $scope, array( Auth::SCOPE_EDITOR, Auth::SCOPE_ADMIN ), true ) ) {
			$permissions[] = __( 'Create and update content, media, comments, SEO metadata, and store data.', 'mindio-magic-mcp' );
		}
		if ( Auth::SCOPE_ADMIN === $scope ) {
			$permissions[] = __( 'Manage users, plugins, themes, site settings, webhooks, and privileged operations.', 'mindio-magic-mcp' );
		}

		return $permissions;
	}

	private function redirect_destination( string $redirect_uri ): string {
		$parts = wp_parse_url( $redirect_uri );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $redirect_uri;
		}
		$host = (string) $parts['host'];
		if ( str_contains( $host, ':' ) ) {
			$host = '[' . trim( $host, '[]' ) . ']';
		}
		return $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validate_authorization_request( array $params ): array|\WP_Error {
		if ( 'code' !== ( $params['response_type'] ?? '' ) ) {
			return new \WP_Error( 'unsupported_response_type', __( 'Only the code response type is supported.', 'mindio-magic-mcp' ) );
		}
		$client = $this->get_client( (string) ( $params['client_id'] ?? '' ) );
		if ( ! $client ) {
			return new \WP_Error( 'invalid_client', __( 'The OAuth client is unknown.', 'mindio-magic-mcp' ) );
		}
		$redirect_uri = (string) ( $params['redirect_uri'] ?? '' );
		if ( ! in_array( $redirect_uri, (array) $client['redirect_uris'], true ) ) {
			return new \WP_Error( 'invalid_redirect_uri', __( 'The redirect URI is not registered for this client.', 'mindio-magic-mcp' ) );
		}
		if ( 'S256' !== ( $params['code_challenge_method'] ?? '' ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', (string) ( $params['code_challenge'] ?? '' ) ) ) {
			return new \WP_Error( 'invalid_request', __( 'OAuth authorization requires PKCE with S256.', 'mindio-magic-mcp' ) );
		}
		$resource = $this->validated_resource( (string) ( $params['resource'] ?? '' ) );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}

		$scope = $this->requested_scope( (string) ( $params['scope'] ?? Auth::SCOPE_READ ) );
		if ( ! $scope || ! $this->auth->user_allows_scope( get_current_user_id(), $scope ) ) {
			return new \WP_Error( 'invalid_scope', __( 'Your user cannot grant the requested scope.', 'mindio-magic-mcp' ) );
		}

		return array( 'client' => $client, 'scope' => $scope, 'resource' => $resource );
	}

	private function requested_scope( string $scopes ): string {
		$highest = '';
		foreach ( preg_split( '/\s+/', trim( $scopes ) ) ?: array() as $scope ) {
			$scope = $this->auth->normalize_scope( $scope );
			if ( ! $scope ) {
				return '';
			}
			if ( ! $highest || $this->auth->scope_allows( $scope, $highest ) ) {
				$highest = $scope;
			}
		}
		return $highest ?: Auth::SCOPE_READ;
	}

	/** @return string|\WP_Error */
	private function validated_resource( string $resource ): string|\WP_Error {
		$expected = $this->canonical_resource();
		$resource = $this->normalize_resource_uri( $resource );
		if ( '' === $resource ) {
			return new \WP_Error( 'invalid_target', __( 'The OAuth resource must exactly identify this MCP endpoint.', 'mindio-magic-mcp' ) );
		}

		$accepted_resources = array(
			$expected,
			$this->normalize_resource_uri( home_url( '/.well-known/oauth-protected-resource' ) ),
			$this->normalize_resource_uri( home_url( '/.well-known/oauth-authorization-server' ) ),
			$this->normalize_resource_uri( rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/oauth/protected-resource' ) ),
			$this->normalize_resource_uri( rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/oauth/authorization-server' ) ),
			$this->normalize_resource_uri( rest_url( MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE . '/mcp' ) ),
			$this->normalize_resource_uri( rest_url( MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE . '/oauth/protected-resource' ) ),
			$this->normalize_resource_uri( rest_url( MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE . '/oauth/authorization-server' ) ),
		);

		foreach ( array_unique( array_filter( $accepted_resources ) ) as $accepted_resource ) {
			if ( hash_equals( $accepted_resource, $resource ) ) {
				// Discovery URLs are compatibility aliases only. Codes and tokens
				// remain audience-bound to the canonical MCP endpoint.
				return $expected;
			}
		}

		return new \WP_Error( 'invalid_target', __( 'The OAuth resource must exactly identify this MCP endpoint.', 'mindio-magic-mcp' ) );
	}

	private function canonical_resource(): string {
		return $this->normalize_resource_uri( rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' ) );
	}

	private function normalize_resource_uri( string $resource ): string {
		$resource = trim( esc_url_raw( $resource, array( 'http', 'https' ) ) );
		$parts    = wp_parse_url( $resource );
		if (
			'' === $resource ||
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] ) ||
			isset( $parts['fragment'], $parts['user'], $parts['pass'] )
		) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( str_contains( $host, ':' ) ) {
			$host = '[' . $host . ']';
		}

		$authority = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$path      = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';
		$query     = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . (string) $parts['query'] : '';
		return $authority . $path . $query;
	}

	private function issue_authorization_code( int $user_id, string $client_id, string $redirect_uri, string $scope, string $challenge, string $resource ): string {
		$id     = bin2hex( random_bytes( 8 ) );
		$secret = Secret_Box::base64url_encode( random_bytes( 32 ) );
		$raw    = 'fmc_code_' . $id . '_' . $secret;
		set_transient(
			'mindio_magic_mcp_oauth_code_' . $id,
			array(
				'id'             => $id,
				'hash'           => hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) . '|mindio-magic-mcp-code' ),
				'user_id'        => $user_id,
				'client_id'      => $client_id,
				'redirect_uri'   => $redirect_uri,
				'scope'          => $scope,
				'code_challenge' => $challenge,
				'resource'       => $resource,
			),
			5 * MINUTE_IN_SECONDS
		);
		return $raw;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function consume_authorization_code( string $raw ): array|\WP_Error {
		if ( ! preg_match( '/^fmc_code_([a-f0-9]{16})_([A-Za-z0-9_-]{43})$/', $raw, $matches ) ) {
			return new \WP_Error( 'invalid_code', __( 'The authorization code is malformed.', 'mindio-magic-mcp' ) );
		}
		$key    = 'mindio_magic_mcp_oauth_code_' . $matches[1];
		$record = get_transient( $key );
		delete_transient( $key );
		$hash = hash_hmac( 'sha256', $matches[2], wp_salt( 'auth' ) . '|mindio-magic-mcp-code' );
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['hash'], $hash ) ) {
			return new \WP_Error( 'invalid_code', __( 'The authorization code is invalid, expired, or already used.', 'mindio-magic-mcp' ) );
		}
		return $record;
	}

	/** @return array<string,mixed>|null */
	private function get_client( string $client_id ): ?array {
		$clients = $this->clients();
		if ( isset( $clients[ $client_id ] ) ) {
			return $clients[ $client_id ];
		}

		// MCP also permits HTTPS Client ID Metadata Documents. Fetch them with
		// the same SSRF guard used for webhooks and cache only validated metadata.
		if ( ! str_starts_with( $client_id, 'https://' ) ) {
			return null;
		}
		$client_parts = wp_parse_url( $client_id );
		if ( ! is_array( $client_parts ) || isset( $client_parts['fragment'] ) ) {
			return null;
		}
		$cached = get_transient( 'mindio_magic_mcp_client_doc_' . md5( $client_id ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( is_wp_error( URL_Guard::validate( $client_id, true ) ) ) {
			return null;
		}
		$response = wp_safe_remote_get( $client_id, array( 'timeout' => 5, 'redirection' => 2, 'limit_response_size' => 65536 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$document = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $document ) || ! hash_equals( $client_id, (string) ( $document['client_id'] ?? '' ) ) ) {
			return null;
		}
		$redirects = array_values( array_map( 'strval', (array) ( $document['redirect_uris'] ?? array() ) ) );
		if ( empty( $redirects ) || count( $redirects ) > 10 ) {
			return null;
		}
		foreach ( $redirects as $redirect ) {
			if ( ! $this->valid_redirect_uri( $redirect ) ) {
				return null;
			}
		}
		$client = array(
			'client_id'     => $client_id,
			'client_name'   => sanitize_text_field( (string) ( $document['client_name'] ?? $client_id ) ),
			'redirect_uris' => $redirects,
		);
		set_transient( 'mindio_magic_mcp_client_doc_' . md5( $client_id ), $client, HOUR_IN_SECONDS );
		return $client;
	}

	private function valid_redirect_uri( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['fragment'], $parts['user'], $parts['pass'] ) ) {
			return false;
		}
		if ( 'https' === strtolower( (string) $parts['scheme'] ) ) {
			return true;
		}
		$host = trim( strtolower( (string) $parts['host'] ), '[]' );
		return 'http' === strtolower( (string) $parts['scheme'] ) && in_array( $host, array( '127.0.0.1', '::1' ), true );
	}

	/** @return array<string,array<string,mixed>> */
	private function clients(): array {
		$clients = get_option( 'mindio_magic_mcp_oauth_clients', array() );
		return is_array( $clients ) ? $clients : array();
	}

	/** @param array<string,mixed>|\WP_Error $result */
	private function token_result( array|\WP_Error $result ): \WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			return $this->oauth_error( $result->get_error_code(), $result->get_error_message(), 400 );
		}
		$response = new \WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	private function oauth_error( string $error, string $description, int $status, int $retry_after = 0 ): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'error' => sanitize_key( $error ), 'error_description' => $description ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		if ( $retry_after > 0 ) {
			$response->header( 'Retry-After', (string) $retry_after );
		}
		return $response;
	}

	/** @param array<string,string> $params */
	private function authorization_redirect_url( string $redirect_uri, array $params ): string {
		$params = array_filter( $params, static fn( string $value ): bool => '' !== $value );
		return add_query_arg( $params, $redirect_uri );
	}

	/** @return string[] */
	private function authorization_fields(): array {
		return array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' );
	}

	private function request_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}
}
