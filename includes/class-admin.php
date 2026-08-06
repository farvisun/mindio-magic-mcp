<?php
/**
 * Enterprise WordPress admin console for configuration and operations.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {
	private const TABS = array( 'overview', 'tools', 'credentials', 'webhooks', 'activity', 'settings' );

	private Auth $auth;
	private Audit_Log $audit;
	private Webhook_Engine $webhooks;
	private Tool_Registry $registry;

	public function __construct( Auth $auth, Audit_Log $audit, Webhook_Engine $webhooks, Tool_Registry $registry ) {
		$this->auth     = $auth;
		$this->audit    = $audit;
		$this->webhooks = $webhooks;
		$this->registry = $registry;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mindio_magic_mcp_generate_key', array( $this, 'generate_key' ) );
		add_action( 'admin_post_mindio_magic_mcp_revoke_key', array( $this, 'revoke_key' ) );
		add_action( 'admin_post_mindio_magic_mcp_remove_oauth_client', array( $this, 'remove_oauth_client' ) );
		add_action( 'admin_post_mindio_magic_mcp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_mindio_magic_mcp_save_tools', array( $this, 'save_tools' ) );
		add_action( 'admin_post_mindio_magic_mcp_add_webhook', array( $this, 'add_webhook' ) );
		add_action( 'admin_post_mindio_magic_mcp_remove_webhook', array( $this, 'remove_webhook' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MINDIO_MAGIC_MCP_FILE ), array( $this, 'action_links' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_mindio-magic-mcp' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'mindio-magic-mcp-admin', MINDIO_MAGIC_MCP_URL . 'assets/css/admin.css', array(), MINDIO_MAGIC_MCP_VERSION );
		wp_enqueue_script( 'mindio-magic-mcp-admin', MINDIO_MAGIC_MCP_URL . 'assets/js/admin.js', array(), MINDIO_MAGIC_MCP_VERSION, true );
		wp_localize_script(
			'mindio-magic-mcp-admin',
			'MindioMagicMCPAdmin',
			array(
				'copy'        => __( 'Copy', 'mindio-magic-mcp' ),
				'copied'      => __( 'Copied', 'mindio-magic-mcp' ),
				'copyFailed'  => __( 'Copy failed. Select and copy the value manually.', 'mindio-magic-mcp' ),
				'saving'      => __( 'Saving…', 'mindio-magic-mcp' ),
					'creating'    => __( 'Creating…', 'mindio-magic-mcp' ),
					'filterEmpty' => __( 'No records match your filters.', 'mindio-magic-mcp' ),
					/* translators: %d: number of visible records. */
					'recordCount' => __( '%d records', 'mindio-magic-mcp' ),
					/* translators: 1: visible tool count, 2: total tool count. */
					'toolsVisible' => __( '%1$d of %2$d tools visible', 'mindio-magic-mcp' ),
				'toolEnabled' => __( 'Exposed', 'mindio-magic-mcp' ),
				'toolDisabled' => __( 'Disabled', 'mindio-magic-mcp' ),
					'operationEnabled' => __( 'Enabled', 'mindio-magic-mcp' ),
					'operationDisabled' => __( 'Disabled', 'mindio-magic-mcp' ),
					/* translators: 1: enabled operation count, 2: total operation count. */
					'operationCount' => __( '%1$d of %2$d operations enabled', 'mindio-magic-mcp' ),
			)
		);
	}

	public function menu(): void {
		add_options_page(
			__( 'Mindio Magic MCP', 'mindio-magic-mcp' ),
			__( 'Mindio Magic MCP', 'mindio-magic-mcp' ),
			'manage_options',
			'mindio-magic-mcp',
			array( $this, 'render' )
		);
	}

	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( $this->tab_url( 'overview' ) ) . '">' . esc_html__( 'Open console', 'mindio-magic-mcp' ) . '</a>' );
		return $links;
	}

	public function generate_key(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_generate_key' );
		$user_id = absint( $_POST['user_id'] ?? get_current_user_id() );
		$scope   = sanitize_key( (string) wp_unslash( $_POST['scope'] ?? Auth::SCOPE_READ ) );
		$label   = sanitize_text_field( (string) wp_unslash( $_POST['label'] ?? '' ) );
		$result  = $this->auth->create_api_key( $user_id, $scope, $label );
		$this->flash_result( $result, 'api_key' );
	}

	public function revoke_key(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_revoke_key' );
		$id      = sanitize_key( (string) wp_unslash( $_POST['token_id'] ?? '' ) );
		$revoked = $this->auth->revoke_token( $id );
		$this->flash( $revoked ? 'success' : 'error', $revoked ? __( 'Credential revoked.', 'mindio-magic-mcp' ) : __( 'Credential not found.', 'mindio-magic-mcp' ) );
		$this->redirect();
	}

	public function remove_oauth_client(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_remove_oauth_client' );
		$client_id = sanitize_text_field( (string) wp_unslash( $_POST['client_id'] ?? '' ) );
		$clients   = get_option( 'mindio_magic_mcp_oauth_clients', array() );
		if ( ! is_array( $clients ) || ! isset( $clients[ $client_id ] ) ) {
			$this->flash( 'error', __( 'OAuth client not found.', 'mindio-magic-mcp' ) );
			$this->redirect();
		}
		unset( $clients[ $client_id ] );
		update_option( 'mindio_magic_mcp_oauth_clients', $clients, false );
		$this->auth->revoke_client_tokens( $client_id );
		$this->flash( 'success', __( 'OAuth client and its tokens revoked.', 'mindio-magic-mcp' ) );
		$this->redirect();
	}

	public function save_settings(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_save_settings' );
		$origin_input = sanitize_textarea_field( (string) wp_unslash( $_POST['allowed_origins'] ?? '' ) );
		$origins = preg_split( '/\R+/', $origin_input ) ?: array();
		$origins = array_values(
			array_filter(
				array_map(
					static fn( string $origin ): string => esc_url_raw( trim( $origin ), array( 'http', 'https' ) ),
					$origins
				)
			)
		);
		$settings = array(
			'enabled'                => ! empty( $_POST['enabled'] ),
			'rate_limit'             => max( 5, min( 1000, absint( $_POST['rate_limit'] ?? 60 ) ) ),
			'max_upload_mb'          => max( 1, min( 100, absint( $_POST['max_upload_mb'] ?? 10 ) ) ),
			'audit_retention_days'   => max( 1, min( 365, absint( $_POST['audit_retention_days'] ?? 30 ) ) ),
			'webhook_retention_days' => max( 1, min( 365, absint( $_POST['webhook_retention_days'] ?? 14 ) ) ),
			'allowed_origins'        => array_unique( $origins ),
			'delete_on_uninstall'    => ! empty( $_POST['delete_on_uninstall'] ),
			'allow_database_inspection'       => ! empty( $_POST['allow_database_inspection'] ),
			'allow_filesystem_read'  => ! empty( $_POST['allow_filesystem_read'] ),
			'allow_wp_cli'           => ! empty( $_POST['allow_wp_cli'] ),
		);
		update_option( 'mindio_magic_mcp_settings', $settings, false );
		$this->flash( 'success', __( 'Settings saved.', 'mindio-magic-mcp' ) );
		$this->redirect();
	}

	public function save_tools(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_save_tools' );
		$enabled   = array_values(
			array_filter(
				array_map(
					static fn( mixed $name ): string => is_string( $name ) ? sanitize_key( $name ) : '',
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each submitted scalar is sanitized by the callback immediately above; nested values are rejected.
					(array) wp_unslash( $_POST['enabled_tools'] ?? array() )
				)
			)
		);
		$summary   = $this->registry->update_exposure( $enabled );
		$enabled_operations   = array_values(
			array_filter(
				array_map(
					static function ( mixed $key ): string {
						if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}:[a-z][a-z0-9_]{0,63}$/', $key ) ) {
							return '';
						}
						return $key;
					},
					map_deep( (array) wp_unslash( $_POST['enabled_operations'] ?? array() ), 'sanitize_text_field' )
				)
			)
		);
		$operation_summary = $this->registry->update_operation_exposure( $enabled_operations );

		$this->flash(
			'success',
			sprintf(
				/* translators: 1: exposed tools, 2: disabled tools, 3: enabled operations, 4: disabled operations. */
				__( 'Tool policy updated: %1$s tools exposed, %2$s tools disabled; %3$s operations enabled, %4$s operations disabled.', 'mindio-magic-mcp' ),
				number_format_i18n( $summary['exposed'] ),
				number_format_i18n( $summary['disabled'] ),
				number_format_i18n( $operation_summary['exposed'] ),
				number_format_i18n( $operation_summary['disabled'] )
			)
		);
		$this->redirect();
	}

	public function add_webhook(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_add_webhook' );
		$result = $this->webhooks->register_webhook(
			sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) ),
			array_map( 'sanitize_key', (array) wp_unslash( $_POST['events'] ?? array() ) )
		);
		$this->flash_result( $result, 'webhook' );
	}

	public function remove_webhook(): void {
		$this->guard();
		check_admin_referer( 'mindio_magic_mcp_remove_webhook' );
		$id      = sanitize_key( (string) wp_unslash( $_POST['webhook_id'] ?? '' ) );
		$removed = $this->webhooks->unregister_webhook( $id );
		$this->flash(
			$removed ? 'success' : 'error',
			$removed ? __( 'Webhook removed.', 'mindio-magic-mcp' ) : __( 'Webhook not found.', 'mindio-magic-mcp' )
		);
		$this->redirect();
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = $this->current_tab();
		$settings   = $this->settings();
		$flash_key  = 'mindio_magic_mcp_admin_flash_' . get_current_user_id();
		$flash      = get_transient( $flash_key );
		delete_transient( $flash_key );
		?>
		<div class="wrap mindio-admin" data-mindio-admin>
			<div id="mindio-copy-status" class="screen-reader-text" aria-live="polite"></div>

			<header class="mindio-masthead">
				<div class="mindio-brand">
					<div class="mindio-brand__mark" aria-hidden="true">
						<span class="dashicons dashicons-admin-links"></span>
					</div>
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'WordPress agent operations', 'mindio-magic-mcp' ); ?></p>
						<h1><?php esc_html_e( 'Mindio Magic MCP', 'mindio-magic-mcp' ); ?></h1>
						<p class="mindio-masthead__summary"><?php esc_html_e( 'Securely connect AI agents, govern access, automate events, and monitor every tool call from one control plane.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<div class="mindio-masthead__meta" aria-label="<?php esc_attr_e( 'Service status', 'mindio-magic-mcp' ); ?>">
					<span class="mindio-version"><?php echo esc_html( 'v' . MINDIO_MAGIC_MCP_VERSION ); ?></span>
					<span class="mindio-service-state mindio-service-state--<?php echo ! empty( $settings['enabled'] ) ? 'online' : 'paused'; ?>">
						<span aria-hidden="true"></span>
						<?php echo ! empty( $settings['enabled'] ) ? esc_html__( 'Endpoint online', 'mindio-magic-mcp' ) : esc_html__( 'Endpoint paused', 'mindio-magic-mcp' ); ?>
					</span>
				</div>
			</header>

			<nav class="mindio-tabs" aria-label="<?php esc_attr_e( 'Mindio Magic MCP sections', 'mindio-magic-mcp' ); ?>">
				<?php foreach ( $this->tabs() as $slug => $tab ) : ?>
					<a
						class="mindio-tab <?php echo $active_tab === $slug ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"
						<?php echo $active_tab === $slug ? 'aria-current="page"' : ''; ?>
					>
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
						<span>
							<strong><?php echo esc_html( $tab['label'] ); ?></strong>
							<small><?php echo esc_html( $tab['description'] ); ?></small>
						</span>
					</a>
				<?php endforeach; ?>
			</nav>

			<main id="mindio-console-panel" class="mindio-console" tabindex="-1">
				<?php
				if ( is_array( $flash ) ) {
					$this->render_flash( $flash );
				}

				switch ( $active_tab ) {
					case 'tools':
						$this->render_tools();
						break;
					case 'credentials':
						$this->render_credentials();
						break;
					case 'webhooks':
						$this->render_webhooks();
						break;
					case 'activity':
						$this->render_activity();
						break;
					case 'settings':
						$this->render_settings( $settings );
						break;
					default:
						$this->render_overview( $settings );
				}
				?>
			</main>

			<footer class="mindio-console-footer">
				<span>
					<?php
					/* translators: %s: plugin version number. */
					echo esc_html( sprintf( __( 'Mindio Magic MCP %s', 'mindio-magic-mcp' ), MINDIO_MAGIC_MCP_VERSION ) );
					?>
				</span>
				<span><?php esc_html_e( 'Streamable HTTP · JSON-RPC 2.0 · OAuth 2.1', 'mindio-magic-mcp' ); ?></span>
			</footer>
		</div>
		<?php
	}

	/** @param array<string,mixed> $flash */
	private function render_flash( array $flash ): void {
		$type   = 'error' === ( $flash['type'] ?? '' ) ? 'error' : 'success';
		$secret = (string) ( $flash['secret'] ?? '' );
		?>
		<div class="mindio-alert mindio-alert--<?php echo esc_attr( $type ); ?>" role="alert">
			<span class="dashicons <?php echo 'error' === $type ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
			<div class="mindio-alert__content">
				<strong><?php echo 'error' === $type ? esc_html__( 'Action required', 'mindio-magic-mcp' ) : esc_html__( 'Operation completed', 'mindio-magic-mcp' ); ?></strong>
				<p><?php echo esc_html( (string) ( $flash['message'] ?? '' ) ); ?></p>
				<?php if ( '' !== $secret ) : ?>
					<div class="mindio-secret">
						<label for="mindio-one-time-secret"><?php esc_html_e( 'One-time secret', 'mindio-magic-mcp' ); ?></label>
						<div class="mindio-copy-field">
							<input id="mindio-one-time-secret" class="mindio-control mindio-control--code" type="text" readonly value="<?php echo esc_attr( $secret ); ?>" dir="ltr">
							<?php $this->render_copy_button( 'mindio-one-time-secret', __( 'one-time secret', 'mindio-magic-mcp' ) ); ?>
						</div>
						<small><?php esc_html_e( 'Store this value in a password manager now. It will not be displayed again.', 'mindio-magic-mcp' ); ?></small>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $settings */
	private function render_overview( array $settings ): void {
		$tokens        = $this->auth->list_tokens();
		$oauth_clients = $this->oauth_clients();
		$webhooks      = $this->webhooks->list_webhooks();
		$activity      = $this->audit->list( array( 'limit' => 50 ) );
		$successes     = count( array_filter( $activity, static fn( array $row ): bool => ! empty( $row['success'] ) ) );
		$success_rate  = $activity ? (string) round( ( $successes / count( $activity ) ) * 100 ) . '%' : '—';
		?>
		<section class="mindio-section-heading">
			<div>
				<p class="mindio-eyebrow"><?php esc_html_e( 'Control plane', 'mindio-magic-mcp' ); ?></p>
				<h2><?php esc_html_e( 'System overview', 'mindio-magic-mcp' ); ?></h2>
				<p><?php esc_html_e( 'A live view of connectivity, access, automation, and recent execution health.', 'mindio-magic-mcp' ); ?></p>
			</div>
			<a class="mindio-button mindio-button--primary" href="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Create credential', 'mindio-magic-mcp' ); ?>
			</a>
		</section>

		<div class="mindio-metrics">
			<?php
			$this->render_metric( __( 'API keys', 'mindio-magic-mcp' ), number_format_i18n( count( $tokens ) ), __( 'Long-lived credentials', 'mindio-magic-mcp' ), 'dashicons-admin-network', 'blue' );
			$this->render_metric( __( 'OAuth clients', 'mindio-magic-mcp' ), number_format_i18n( count( $oauth_clients ) ), __( 'Dynamically registered', 'mindio-magic-mcp' ), 'dashicons-lock', 'indigo' );
			$this->render_metric( __( 'Webhooks', 'mindio-magic-mcp' ), number_format_i18n( count( $webhooks ) ), __( 'Active destinations', 'mindio-magic-mcp' ), 'dashicons-rest-api', 'amber' );
			$this->render_metric( __( 'Request success', 'mindio-magic-mcp' ), $success_rate, __( 'Latest 50 tool calls', 'mindio-magic-mcp' ), 'dashicons-chart-line', 'green' );
			?>
		</div>

		<div class="mindio-layout mindio-layout--wide">
			<section class="mindio-card mindio-card--connection">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'Client configuration', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Connection endpoints', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Use the MCP endpoint in your client. OAuth discovery URLs are consumed automatically.', 'mindio-magic-mcp' ); ?></p>
					</div>
					<?php $this->render_status_badge( ! empty( $settings['enabled'] ) ? __( 'Available', 'mindio-magic-mcp' ) : __( 'Paused', 'mindio-magic-mcp' ), ! empty( $settings['enabled'] ) ? 'success' : 'warning' ); ?>
				</div>
				<div class="mindio-endpoints">
					<?php
					$this->render_endpoint( 'mindio-mcp-endpoint', __( 'MCP endpoint', 'mindio-magic-mcp' ), rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' ), __( 'Paste this URL into your MCP client', 'mindio-magic-mcp' ) );
					$this->render_endpoint( 'mindio-oauth-metadata', __( 'OAuth authorization server', 'mindio-magic-mcp' ), home_url( '/.well-known/oauth-authorization-server' ), __( 'Automatic discovery only — not an MCP server URL', 'mindio-magic-mcp' ) );
					$this->render_endpoint( 'mindio-protected-resource', __( 'Protected resource metadata', 'mindio-magic-mcp' ), rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/oauth/protected-resource' ), __( 'Automatic resource discovery for OAuth clients', 'mindio-magic-mcp' ) );
					?>
				</div>
			</section>

			<aside class="mindio-card">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'Environment', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'System readiness', 'mindio-magic-mcp' ); ?></h3>
					</div>
				</div>
				<div class="mindio-readiness">
					<?php
					$this->render_readiness_row( __( 'WordPress', 'mindio-magic-mcp' ), get_bloginfo( 'version' ), 'success' );
					$this->render_readiness_row( __( 'PHP runtime', 'mindio-magic-mcp' ), PHP_VERSION, version_compare( PHP_VERSION, '8.0', '>=' ) ? 'success' : 'danger' );
					$this->render_readiness_row( __( 'Flatsome theme', 'mindio-magic-mcp' ), 'flatsome' === get_template() ? __( 'Detected', 'mindio-magic-mcp' ) : __( 'Not active', 'mindio-magic-mcp' ), 'flatsome' === get_template() ? 'success' : 'neutral' );
					$this->render_readiness_row( __( 'HTTPS transport', 'mindio-magic-mcp' ), is_ssl() ? __( 'Secure', 'mindio-magic-mcp' ) : __( 'Recommended', 'mindio-magic-mcp' ), is_ssl() ? 'success' : 'warning' );
					?>
				</div>
			</aside>
		</div>

		<div class="mindio-layout mindio-layout--equal">
			<section class="mindio-card">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'Deployment path', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Connect an agent in three steps', 'mindio-magic-mcp' ); ?></h3>
					</div>
				</div>
				<ol class="mindio-steps">
					<li>
						<span>01</span>
						<div>
							<strong><?php esc_html_e( 'Issue the least-privileged credential', 'mindio-magic-mcp' ); ?></strong>
							<p><?php esc_html_e( 'Choose read-only, editor, or administrator scope for a specific WordPress user.', 'mindio-magic-mcp' ); ?></p>
							<a href="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>"><?php esc_html_e( 'Manage credentials', 'mindio-magic-mcp' ); ?></a>
						</div>
					</li>
					<li>
						<span>02</span>
						<div>
							<strong><?php esc_html_e( 'Configure the MCP client', 'mindio-magic-mcp' ); ?></strong>
							<p><?php esc_html_e( 'Add the endpoint above and authenticate with an API key or the OAuth discovery flow.', 'mindio-magic-mcp' ); ?></p>
						</div>
					</li>
					<li>
						<span>03</span>
						<div>
							<strong><?php esc_html_e( 'Verify and automate', 'mindio-magic-mcp' ); ?></strong>
							<p><?php esc_html_e( 'Run a tool call, review the audit trail, then subscribe downstream systems to signed events.', 'mindio-magic-mcp' ); ?></p>
							<a href="<?php echo esc_url( $this->tab_url( 'webhooks' ) ); ?>"><?php esc_html_e( 'Configure webhooks', 'mindio-magic-mcp' ); ?></a>
						</div>
					</li>
				</ol>
			</section>

			<section class="mindio-card">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'Observability', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Latest tool activity', 'mindio-magic-mcp' ); ?></h3>
					</div>
					<a class="mindio-inline-link" href="<?php echo esc_url( $this->tab_url( 'activity' ) ); ?>"><?php esc_html_e( 'View all', 'mindio-magic-mcp' ); ?></a>
				</div>
				<?php if ( $activity ) : ?>
					<div class="mindio-activity-feed">
						<?php foreach ( array_slice( $activity, 0, 5 ) as $row ) : ?>
							<div class="mindio-activity-feed__item">
								<span class="mindio-result-dot mindio-result-dot--<?php echo ! empty( $row['success'] ) ? 'success' : 'error'; ?>" aria-hidden="true"></span>
								<div>
									<code><?php echo esc_html( (string) $row['tool'] ); ?></code>
									<small><?php echo esc_html( $this->format_datetime( (string) $row['created_at'] ) ); ?></small>
								</div>
								<strong><?php echo esc_html( number_format_i18n( (int) $row['duration_ms'] ) ); ?> ms</strong>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<?php $this->render_empty_state( 'dashicons-chart-area', __( 'No tool activity yet', 'mindio-magic-mcp' ), __( 'Successful and failed MCP calls will appear here as agents begin using the endpoint.', 'mindio-magic-mcp' ) ); ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	private function render_credentials(): void {
		$tokens        = $this->auth->list_tokens();
		$oauth_clients = $this->oauth_clients();
		?>
		<section class="mindio-section-heading">
			<div>
				<p class="mindio-eyebrow"><?php esc_html_e( 'Identity and access', 'mindio-magic-mcp' ); ?></p>
				<h2><?php esc_html_e( 'Credentials', 'mindio-magic-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Provision scoped API keys and govern dynamically registered OAuth clients.', 'mindio-magic-mcp' ); ?></p>
			</div>
			<?php
			/* translators: %s: number of active API keys. */
			$this->render_status_badge( sprintf( _n( '%s active API key', '%s active API keys', count( $tokens ), 'mindio-magic-mcp' ), number_format_i18n( count( $tokens ) ) ), 'neutral' );
			?>
		</section>

		<div class="mindio-layout mindio-layout--credentials">
			<section class="mindio-card">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'New credential', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Generate an API key', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'The raw key is revealed once after creation.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<form class="mindio-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-loading-label="creating">
					<input type="hidden" name="action" value="mindio_magic_mcp_generate_key">
					<input type="hidden" name="redirect_tab" value="credentials">
					<?php wp_nonce_field( 'mindio_magic_mcp_generate_key' ); ?>
					<div class="mindio-field">
						<label for="mindio-key-label"><?php esc_html_e( 'Credential label', 'mindio-magic-mcp' ); ?></label>
						<input id="mindio-key-label" class="mindio-control" type="text" name="label" maxlength="200" required placeholder="<?php esc_attr_e( 'Production content agent', 'mindio-magic-mcp' ); ?>">
						<small><?php esc_html_e( 'Use a name that identifies the client and environment.', 'mindio-magic-mcp' ); ?></small>
					</div>
					<div class="mindio-form-grid">
						<div class="mindio-field">
							<label for="mindio-key-user"><?php esc_html_e( 'WordPress user', 'mindio-magic-mcp' ); ?></label>
							<?php
							echo wp_dropdown_users(
								array(
									'name'             => 'user_id',
									'id'               => 'mindio-key-user',
									'class'            => 'mindio-control',
									'selected'         => get_current_user_id(),
									'show_option_none' => false,
									'echo'             => false,
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
						<div class="mindio-field">
							<label for="mindio-key-scope"><?php esc_html_e( 'Permission scope', 'mindio-magic-mcp' ); ?></label>
							<select id="mindio-key-scope" class="mindio-control" name="scope">
								<option value="<?php echo esc_attr( Auth::SCOPE_READ ); ?>"><?php esc_html_e( 'Read only', 'mindio-magic-mcp' ); ?></option>
								<option value="<?php echo esc_attr( Auth::SCOPE_EDITOR ); ?>"><?php esc_html_e( 'Editor', 'mindio-magic-mcp' ); ?></option>
								<option value="<?php echo esc_attr( Auth::SCOPE_ADMIN ); ?>"><?php esc_html_e( 'Administrator', 'mindio-magic-mcp' ); ?></option>
							</select>
						</div>
					</div>
					<button class="mindio-button mindio-button--primary" type="submit" data-submit-label>
						<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
						<?php esc_html_e( 'Generate secure key', 'mindio-magic-mcp' ); ?>
					</button>
				</form>
			</section>

			<aside class="mindio-card mindio-card--tinted">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'Least privilege', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Scope policy', 'mindio-magic-mcp' ); ?></h3>
					</div>
				</div>
				<div class="mindio-scope-guide">
					<div><code>read_only</code><p><?php esc_html_e( 'Inspect content, settings, orders, and logs without modifying the site.', 'mindio-magic-mcp' ); ?></p></div>
					<div><code>editor</code><p><?php esc_html_e( 'Create and update content, media, SEO metadata, comments, and products.', 'mindio-magic-mcp' ); ?></p></div>
					<div><code>admin</code><p><?php esc_html_e( 'Manage users, extensions, settings, automation, and privileged tools.', 'mindio-magic-mcp' ); ?></p></div>
				</div>
				<div class="mindio-callout mindio-callout--info">
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<p><?php esc_html_e( 'A scope never exceeds the selected WordPress user’s native capabilities.', 'mindio-magic-mcp' ); ?></p>
				</div>
			</aside>
		</div>

		<section class="mindio-card mindio-card--table">
			<div class="mindio-card__header">
				<div>
					<p class="mindio-eyebrow"><?php esc_html_e( 'API access', 'mindio-magic-mcp' ); ?></p>
					<h3><?php esc_html_e( 'Active API keys', 'mindio-magic-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Only hashed credential material is stored. Raw secrets cannot be recovered.', 'mindio-magic-mcp' ); ?></p>
				</div>
			</div>
			<?php if ( $tokens ) : ?>
				<div class="mindio-table-wrap">
					<table class="mindio-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Credential', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Principal', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Scope', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last used', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mindio-magic-mcp' ); ?></span></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $tokens as $token ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( (string) $token['label'] ); ?></strong>
										<?php // The `fmp_` prefix is part of every issued API key, so it is displayed verbatim. ?>
										<code class="mindio-subvalue"><?php echo esc_html( 'fmp_' . (string) $token['id'] . '_••••' ); ?></code>
									</td>
									<td><?php echo esc_html( $this->user_name( (int) $token['user_id'] ) ); ?></td>
									<td><?php $this->render_scope_badge( (string) $token['scope'] ); ?></td>
									<td><?php echo esc_html( $this->format_datetime( (string) $token['created_at'] ) ); ?></td>
									<td><?php echo esc_html( $this->format_datetime( (string) ( $token['last_used'] ?? '' ) ) ); ?></td>
									<td class="mindio-table__action">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'Revoke this API key? The connected client will immediately lose access.', 'mindio-magic-mcp' ); ?>">
											<input type="hidden" name="action" value="mindio_magic_mcp_revoke_key">
											<input type="hidden" name="redirect_tab" value="credentials">
											<input type="hidden" name="token_id" value="<?php echo esc_attr( (string) $token['id'] ); ?>">
											<?php wp_nonce_field( 'mindio_magic_mcp_revoke_key' ); ?>
											<button class="mindio-text-button mindio-text-button--danger" type="submit"><?php esc_html_e( 'Revoke', 'mindio-magic-mcp' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<?php $this->render_empty_state( 'dashicons-admin-network', __( 'No API keys configured', 'mindio-magic-mcp' ), __( 'Generate a scoped credential above to connect your first agent.', 'mindio-magic-mcp' ) ); ?>
			<?php endif; ?>
		</section>

		<section class="mindio-card mindio-card--table">
			<div class="mindio-card__header">
				<div>
					<p class="mindio-eyebrow"><?php esc_html_e( 'OAuth 2.1', 'mindio-magic-mcp' ); ?></p>
					<h3><?php esc_html_e( 'Registered OAuth clients', 'mindio-magic-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Clients appear here after completing dynamic client registration.', 'mindio-magic-mcp' ); ?></p>
				</div>
				<?php
				/* translators: %s: number of registered OAuth clients. */
				$this->render_status_badge( sprintf( _n( '%s client', '%s clients', count( $oauth_clients ), 'mindio-magic-mcp' ), number_format_i18n( count( $oauth_clients ) ) ), 'neutral' );
				?>
			</div>
			<?php if ( $oauth_clients ) : ?>
				<div class="mindio-table-wrap">
					<table class="mindio-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Client', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Client ID', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Redirect URIs', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Registered', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mindio-magic-mcp' ); ?></span></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $oauth_clients as $client ) : ?>
								<?php
								$client_id = (string) ( $client['client_id'] ?? '' );
								$target_id = 'mindio-oauth-' . substr( md5( $client_id ), 0, 10 );
								?>
								<tr>
									<td><strong><?php echo esc_html( (string) ( $client['client_name'] ?? __( 'Unnamed client', 'mindio-magic-mcp' ) ) ); ?></strong></td>
									<td>
										<div class="mindio-inline-copy">
											<code id="<?php echo esc_attr( $target_id ); ?>"><?php echo esc_html( $client_id ); ?></code>
											<?php $this->render_copy_button( $target_id, __( 'client ID', 'mindio-magic-mcp' ), true ); ?>
										</div>
									</td>
									<td>
										<ul class="mindio-uri-list">
											<?php foreach ( (array) ( $client['redirect_uris'] ?? array() ) as $uri ) : ?>
												<li><code><?php echo esc_html( (string) $uri ); ?></code></li>
											<?php endforeach; ?>
										</ul>
									</td>
									<td><?php echo esc_html( ! empty( $client['client_id_issued_at'] ) ? $this->format_datetime( gmdate( DATE_ATOM, (int) $client['client_id_issued_at'] ) ) : '—' ); ?></td>
									<td class="mindio-table__action">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'Revoke this OAuth client and all of its access and refresh tokens?', 'mindio-magic-mcp' ); ?>">
											<input type="hidden" name="action" value="mindio_magic_mcp_remove_oauth_client">
											<input type="hidden" name="redirect_tab" value="credentials">
											<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
											<?php wp_nonce_field( 'mindio_magic_mcp_remove_oauth_client' ); ?>
											<button class="mindio-text-button mindio-text-button--danger" type="submit"><?php esc_html_e( 'Revoke', 'mindio-magic-mcp' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<?php $this->render_empty_state( 'dashicons-lock', __( 'No OAuth clients registered', 'mindio-magic-mcp' ), __( 'Compatible clients can register automatically through the OAuth discovery endpoints.', 'mindio-magic-mcp' ) ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_webhooks(): void {
		$webhooks = $this->webhooks->list_webhooks();
		?>
		<section class="mindio-section-heading">
			<div>
				<p class="mindio-eyebrow"><?php esc_html_e( 'Event automation', 'mindio-magic-mcp' ); ?></p>
				<h2><?php esc_html_e( 'Webhooks', 'mindio-magic-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Deliver signed WordPress and WooCommerce events to trusted HTTPS destinations.', 'mindio-magic-mcp' ); ?></p>
			</div>
			<?php
			/* translators: %s: number of webhook destinations. */
			$this->render_status_badge( sprintf( _n( '%s destination', '%s destinations', count( $webhooks ), 'mindio-magic-mcp' ), number_format_i18n( count( $webhooks ) ) ), 'neutral' );
			?>
		</section>

		<div class="mindio-layout mindio-layout--webhooks">
			<section class="mindio-card">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'New subscription', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Add a webhook destination', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'A signing secret is revealed once after the destination is created.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<form class="mindio-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-webhook-form data-loading-label="creating">
					<input type="hidden" name="action" value="mindio_magic_mcp_add_webhook">
					<input type="hidden" name="redirect_tab" value="webhooks">
					<?php wp_nonce_field( 'mindio_magic_mcp_add_webhook' ); ?>
					<div class="mindio-form-grid">
						<div class="mindio-field">
							<label for="mindio-webhook-name"><?php esc_html_e( 'Destination name', 'mindio-magic-mcp' ); ?></label>
							<input id="mindio-webhook-name" class="mindio-control" type="text" name="name" maxlength="200" required placeholder="<?php esc_attr_e( 'Automation platform', 'mindio-magic-mcp' ); ?>">
						</div>
						<div class="mindio-field">
							<label for="mindio-webhook-url"><?php esc_html_e( 'HTTPS endpoint URL', 'mindio-magic-mcp' ); ?></label>
							<input id="mindio-webhook-url" class="mindio-control" type="url" name="url" required inputmode="url" dir="ltr" placeholder="https://hooks.example.com/wordpress">
						</div>
					</div>
					<fieldset class="mindio-events">
						<legend><?php esc_html_e( 'Events to deliver', 'mindio-magic-mcp' ); ?></legend>
						<p><?php esc_html_e( 'Select one or more events for this destination.', 'mindio-magic-mcp' ); ?></p>
						<div class="mindio-event-grid">
							<?php foreach ( Webhook_Engine::EVENTS as $event ) : ?>
								<label class="mindio-event-option" for="<?php echo esc_attr( 'mindio-event-' . $event ); ?>">
									<input id="<?php echo esc_attr( 'mindio-event-' . $event ); ?>" type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>">
									<span class="mindio-event-option__check" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
									<span>
										<code><?php echo esc_html( $event ); ?></code>
										<small><?php echo esc_html( $this->event_description( $event ) ); ?></small>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="mindio-field-error" data-webhook-error role="alert" hidden><?php esc_html_e( 'Select at least one event before creating the webhook.', 'mindio-magic-mcp' ); ?></p>
					</fieldset>
					<button class="mindio-button mindio-button--primary" type="submit" data-submit-label>
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Create webhook', 'mindio-magic-mcp' ); ?>
					</button>
				</form>
			</section>

			<aside class="mindio-card mindio-card--dark">
				<div class="mindio-card__header">
					<div>
						<p class="mindio-eyebrow"><?php esc_html_e( 'Delivery security', 'mindio-magic-mcp' ); ?></p>
						<h3><?php esc_html_e( 'Signed by default', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Every delivery includes verification headers and follows a bounded retry schedule.', 'mindio-magic-mcp' ); ?></p>
					</div>
					<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
				</div>
				<dl class="mindio-security-spec">
					<div><dt><?php esc_html_e( 'Signature', 'mindio-magic-mcp' ); ?></dt><dd><code>X-Mindio-Magic-MCP-Signature-256</code></dd></div>
					<div><dt><?php esc_html_e( 'Algorithm', 'mindio-magic-mcp' ); ?></dt><dd>HMAC-SHA256</dd></div>
					<div><dt><?php esc_html_e( 'Retries', 'mindio-magic-mcp' ); ?></dt><dd><?php esc_html_e( 'Up to 5 attempts', 'mindio-magic-mcp' ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Network policy', 'mindio-magic-mcp' ); ?></dt><dd><?php esc_html_e( 'HTTPS with private targets blocked', 'mindio-magic-mcp' ); ?></dd></div>
				</dl>
			</aside>
		</div>

		<section class="mindio-card mindio-card--table">
			<div class="mindio-card__header">
				<div>
					<p class="mindio-eyebrow"><?php esc_html_e( 'Subscriptions', 'mindio-magic-mcp' ); ?></p>
					<h3><?php esc_html_e( 'Webhook destinations', 'mindio-magic-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Configured destinations receive only the events selected for them.', 'mindio-magic-mcp' ); ?></p>
				</div>
			</div>
			<?php if ( $webhooks ) : ?>
				<div class="mindio-table-wrap">
					<table class="mindio-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Destination', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Endpoint', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Events', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mindio-magic-mcp' ); ?></span></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $webhooks as $webhook ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( (string) $webhook['name'] ); ?></strong>
										<code class="mindio-subvalue"><?php echo esc_html( (string) $webhook['id'] ); ?></code>
									</td>
									<td><code class="mindio-url-value" title="<?php echo esc_attr( (string) $webhook['url'] ); ?>"><?php echo esc_html( (string) $webhook['url'] ); ?></code></td>
									<td><div class="mindio-pill-list"><?php foreach ( (array) $webhook['events'] as $event ) : ?><code><?php echo esc_html( (string) $event ); ?></code><?php endforeach; ?></div></td>
									<td><?php echo esc_html( $this->format_datetime( (string) ( $webhook['created_at'] ?? '' ) ) ); ?></td>
									<td><?php $this->render_status_badge( ! empty( $webhook['active'] ) ? __( 'Active', 'mindio-magic-mcp' ) : __( 'Inactive', 'mindio-magic-mcp' ), ! empty( $webhook['active'] ) ? 'success' : 'neutral' ); ?></td>
									<td class="mindio-table__action">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="<?php esc_attr_e( 'Remove this webhook destination? Future matching events will no longer be delivered.', 'mindio-magic-mcp' ); ?>">
											<input type="hidden" name="action" value="mindio_magic_mcp_remove_webhook">
											<input type="hidden" name="redirect_tab" value="webhooks">
											<input type="hidden" name="webhook_id" value="<?php echo esc_attr( (string) $webhook['id'] ); ?>">
											<?php wp_nonce_field( 'mindio_magic_mcp_remove_webhook' ); ?>
											<button class="mindio-text-button mindio-text-button--danger" type="submit"><?php esc_html_e( 'Remove', 'mindio-magic-mcp' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<?php $this->render_empty_state( 'dashicons-rest-api', __( 'No webhook destinations', 'mindio-magic-mcp' ), __( 'Create a signed subscription above to automate downstream workflows.', 'mindio-magic-mcp' ) ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_activity(): void {
		$activity        = $this->audit->list( array( 'limit' => 100 ) );
		$deliveries      = $this->webhooks->list_logs( array( 'limit' => 100 ) );
		$successes       = count( array_filter( $activity, static fn( array $row ): bool => ! empty( $row['success'] ) ) );
		$failures        = count( $activity ) - $successes;
		$average         = $activity ? (int) round( array_sum( array_column( $activity, 'duration_ms' ) ) / count( $activity ) ) : 0;
		$delivery_issues = count( array_filter( $deliveries, static fn( array $row ): bool => in_array( (string) $row['status'], array( 'failed', 'retrying' ), true ) ) );
		$webhook_names   = array();
		foreach ( $this->webhooks->list_webhooks() as $webhook ) {
			$webhook_names[ (string) $webhook['id'] ] = (string) $webhook['name'];
		}
		?>
		<section class="mindio-section-heading">
			<div>
				<p class="mindio-eyebrow"><?php esc_html_e( 'Governance and diagnostics', 'mindio-magic-mcp' ); ?></p>
				<h2><?php esc_html_e( 'Activity & logs', 'mindio-magic-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Inspect tool execution outcomes and webhook delivery health without exposing sensitive payloads.', 'mindio-magic-mcp' ); ?></p>
			</div>
			<?php $this->render_status_badge( __( 'Arguments redacted', 'mindio-magic-mcp' ), 'success' ); ?>
		</section>

		<div class="mindio-metrics">
			<?php
			$this->render_metric( __( 'Successful calls', 'mindio-magic-mcp' ), number_format_i18n( $successes ), __( 'Latest 100 records', 'mindio-magic-mcp' ), 'dashicons-yes-alt', 'green' );
			$this->render_metric( __( 'Failed calls', 'mindio-magic-mcp' ), number_format_i18n( $failures ), __( 'Review error codes below', 'mindio-magic-mcp' ), 'dashicons-warning', $failures ? 'red' : 'blue' );
			$this->render_metric( __( 'Average duration', 'mindio-magic-mcp' ), number_format_i18n( $average ) . ' ms', __( 'Across visible tool calls', 'mindio-magic-mcp' ), 'dashicons-performance', 'indigo' );
			$this->render_metric( __( 'Delivery attention', 'mindio-magic-mcp' ), number_format_i18n( $delivery_issues ), __( 'Failed or retrying webhooks', 'mindio-magic-mcp' ), 'dashicons-rest-api', $delivery_issues ? 'amber' : 'green' );
			?>
		</div>

		<section class="mindio-card mindio-card--table">
			<div class="mindio-card__header mindio-card__header--toolbar">
				<div>
					<p class="mindio-eyebrow"><?php esc_html_e( 'Audit trail', 'mindio-magic-mcp' ); ?></p>
					<h3><?php esc_html_e( 'Tool execution', 'mindio-magic-mcp' ); ?></h3>
					<p><?php esc_html_e( 'The latest calls are retained according to your data lifecycle settings.', 'mindio-magic-mcp' ); ?></p>
				</div>
				<?php if ( $activity ) : ?>
					<div class="mindio-table-toolbar" data-table-controls="mindio-activity-table">
						<label class="mindio-search">
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Search activity', 'mindio-magic-mcp' ); ?></span>
							<input type="search" data-table-search placeholder="<?php esc_attr_e( 'Search tool or user…', 'mindio-magic-mcp' ); ?>">
						</label>
						<label>
							<span class="screen-reader-text"><?php esc_html_e( 'Filter by result', 'mindio-magic-mcp' ); ?></span>
							<select data-table-status>
								<option value=""><?php esc_html_e( 'All results', 'mindio-magic-mcp' ); ?></option>
								<option value="success"><?php esc_html_e( 'Successful', 'mindio-magic-mcp' ); ?></option>
								<option value="error"><?php esc_html_e( 'Failed', 'mindio-magic-mcp' ); ?></option>
							</select>
						</label>
						<output data-table-count>
							<?php
							/* translators: %d: number of activity records. */
							echo esc_html( sprintf( __( '%d records', 'mindio-magic-mcp' ), count( $activity ) ) );
							?>
						</output>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $activity ) : ?>
				<div class="mindio-table-wrap">
					<table id="mindio-activity-table" class="mindio-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Time', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tool', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Principal', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Scope', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Duration', 'mindio-magic-mcp' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $activity as $row ) : ?>
								<?php
								$user_name = $this->user_name( (int) $row['user_id'] );
								$status    = ! empty( $row['success'] ) ? 'success' : 'error';
								$search    = implode( ' ', array( (string) $row['tool'], $user_name, (string) $row['user_id'], (string) $row['scope'], (string) $row['error_code'] ) );
								?>
								<tr data-table-row data-status="<?php echo esc_attr( $status ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
									<td><?php echo esc_html( $this->format_datetime( (string) $row['created_at'] ) ); ?></td>
									<td><code><?php echo esc_html( (string) $row['tool'] ); ?></code></td>
									<td>
										<strong><?php echo esc_html( $user_name ); ?></strong>
										<span class="mindio-subvalue"><?php echo esc_html( '#' . (string) $row['user_id'] ); ?></span>
									</td>
									<td><?php $this->render_scope_badge( (string) $row['scope'] ); ?></td>
									<td>
										<?php
										$this->render_status_badge(
											! empty( $row['success'] ) ? __( 'Success', 'mindio-magic-mcp' ) : ( (string) $row['error_code'] ?: __( 'Error', 'mindio-magic-mcp' ) ),
											! empty( $row['success'] ) ? 'success' : 'danger'
										);
										?>
									</td>
									<td><strong class="mindio-duration"><?php echo esc_html( number_format_i18n( (int) $row['duration_ms'] ) ); ?> ms</strong></td>
								</tr>
							<?php endforeach; ?>
							<tr class="mindio-filter-empty" data-table-empty hidden><td colspan="6"><?php esc_html_e( 'No records match your filters.', 'mindio-magic-mcp' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<?php $this->render_empty_state( 'dashicons-chart-area', __( 'No audit records yet', 'mindio-magic-mcp' ), __( 'Tool calls will be recorded here with duration, scope, and sanitized outcomes.', 'mindio-magic-mcp' ) ); ?>
			<?php endif; ?>
		</section>

		<section class="mindio-card mindio-card--table">
			<div class="mindio-card__header">
				<div>
					<p class="mindio-eyebrow"><?php esc_html_e( 'Outbound automation', 'mindio-magic-mcp' ); ?></p>
					<h3><?php esc_html_e( 'Webhook deliveries', 'mindio-magic-mcp' ); ?></h3>
					<p><?php esc_html_e( 'Delivery status and HTTP outcomes are visible here; event payloads remain protected.', 'mindio-magic-mcp' ); ?></p>
				</div>
			</div>
			<?php if ( $deliveries ) : ?>
				<div class="mindio-table-wrap">
					<table class="mindio-table">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Time', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Destination', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Attempts', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'HTTP', 'mindio-magic-mcp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Next attempt', 'mindio-magic-mcp' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $deliveries as $delivery ) : ?>
								<?php $delivery_status = (string) $delivery['status']; ?>
								<tr>
									<td><?php echo esc_html( $this->format_datetime( (string) $delivery['created_at'] ) ); ?></td>
									<td>
										<strong><?php echo esc_html( $webhook_names[ (string) $delivery['webhook_id'] ] ?? __( 'Removed destination', 'mindio-magic-mcp' ) ); ?></strong>
										<code class="mindio-subvalue"><?php echo esc_html( (string) $delivery['webhook_id'] ); ?></code>
									</td>
									<td><code><?php echo esc_html( (string) $delivery['event'] ); ?></code></td>
									<td><?php $this->render_status_badge( $this->delivery_label( $delivery_status ), $this->delivery_tone( $delivery_status ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $delivery['attempts'] ) ); ?></td>
									<td><?php echo (int) $delivery['response_code'] > 0 ? esc_html( (string) $delivery['response_code'] ) : '—'; ?></td>
									<td><?php echo esc_html( $this->format_datetime( (string) ( $delivery['next_attempt'] ?? '' ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<?php $this->render_empty_state( 'dashicons-rest-api', __( 'No webhook deliveries yet', 'mindio-magic-mcp' ), __( 'Delivery attempts will appear after a subscribed WordPress event occurs.', 'mindio-magic-mcp' ) ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_tools(): void {
		$catalog       = $this->registry->catalog();
		$definitions   = $this->tool_groups();
		$name_to_group = array();
		$groups        = array();

		foreach ( $definitions as $key => $definition ) {
			$groups[ $key ]          = $definition;
			$groups[ $key ]['items'] = array();
			foreach ( $definition['tools'] as $tool_name ) {
				$name_to_group[ $tool_name ] = $key;
			}
		}

		foreach ( $catalog as $tool ) {
			$key = $name_to_group[ $tool['name'] ] ?? 'other';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'label'       => __( 'Other tools', 'mindio-magic-mcp' ),
					'description' => __( 'Additional tools registered by this server.', 'mindio-magic-mcp' ),
					'icon'        => 'dashicons-admin-tools',
					'tools'       => array(),
					'items'       => array(),
				);
			}
			$groups[ $key ]['items'][] = $tool;
		}

		$groups             = array_filter( $groups, static fn( array $group ): bool => ! empty( $group['items'] ) );
		$total              = count( $catalog );
		$exposed            = count( array_filter( $catalog, static fn( array $tool ): bool => ! empty( $tool['exposed'] ) ) );
		$total_operations   = 0;
		$enabled_operations = 0;
		foreach ( $catalog as $tool ) {
			$total_operations += count( $tool['operations'] );
			$enabled_operations += count( array_filter( $tool['operations'], static fn( array $operation ): bool => ! empty( $operation['exposed'] ) ) );
		}
		$disabled_operations = $total_operations - $enabled_operations;
		?>
		<section class="mindio-section-heading mindio-section-heading--settings">
			<div>
				<p class="mindio-eyebrow"><?php esc_html_e( 'Capability governance', 'mindio-magic-mcp' ); ?></p>
				<h2><?php esc_html_e( 'MCP tool exposure', 'mindio-magic-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Choose which registered capabilities agents can discover and execute on this site.', 'mindio-magic-mcp' ); ?></p>
			</div>
			<button class="mindio-button mindio-button--primary" type="submit" form="mindio-tools-form">
				<span class="dashicons dashicons-saved" aria-hidden="true"></span>
				<span data-submit-label><?php esc_html_e( 'Save tool policy', 'mindio-magic-mcp' ); ?></span>
			</button>
		</section>

		<div class="mindio-metrics">
			<?php
			$this->render_metric( __( 'Registered tools', 'mindio-magic-mcp' ), number_format_i18n( $total ), __( 'Current integrations', 'mindio-magic-mcp' ), 'dashicons-screenoptions', 'blue' );
			$this->render_metric( __( 'Exposed tools', 'mindio-magic-mcp' ), number_format_i18n( $exposed ), __( 'Advertised in discovery', 'mindio-magic-mcp' ), 'dashicons-visibility', 'green' );
			$this->render_metric( __( 'Enabled operations', 'mindio-magic-mcp' ), number_format_i18n( $enabled_operations ), __( 'Granular integration access', 'mindio-magic-mcp' ), 'dashicons-yes-alt', 'indigo' );
			$this->render_metric( __( 'Disabled operations', 'mindio-magic-mcp' ), number_format_i18n( $disabled_operations ), __( 'Write operations start disabled', 'mindio-magic-mcp' ), 'dashicons-lock', $disabled_operations > 0 ? 'amber' : 'indigo' );
			?>
		</div>

		<form id="mindio-tools-form" class="mindio-tool-manager" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-tool-manager data-loading-label="saving">
			<input type="hidden" name="action" value="mindio_magic_mcp_save_tools">
			<input type="hidden" name="redirect_tab" value="tools">
			<?php wp_nonce_field( 'mindio_magic_mcp_save_tools' ); ?>

			<section class="mindio-card mindio-tool-controls" aria-label="<?php esc_attr_e( 'Tool filters and bulk actions', 'mindio-magic-mcp' ); ?>">
				<div class="mindio-tool-controls__filters">
					<label class="mindio-search mindio-search--tools" for="mindio-tool-search">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input id="mindio-tool-search" type="search" data-tool-search placeholder="<?php esc_attr_e( 'Search tool name or description…', 'mindio-magic-mcp' ); ?>">
					</label>
					<label class="mindio-filter-field" for="mindio-tool-group-filter">
						<span class="screen-reader-text"><?php esc_html_e( 'Filter by tool group', 'mindio-magic-mcp' ); ?></span>
						<select id="mindio-tool-group-filter" class="mindio-control" data-tool-group-filter>
							<option value=""><?php esc_html_e( 'All tool groups', 'mindio-magic-mcp' ); ?></option>
							<?php foreach ( $groups as $key => $group ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<output class="mindio-tool-count" data-tool-count aria-live="polite">
						<?php
						/* translators: 1: visible tool count, 2: total tool count. */
						echo esc_html( sprintf( __( '%1$d of %2$d tools visible', 'mindio-magic-mcp' ), $total, $total ) );
						?>
					</output>
				</div>
				<div class="mindio-tool-controls__actions">
					<button class="mindio-button mindio-button--compact" type="button" data-tool-enable-all><?php esc_html_e( 'Enable all tools', 'mindio-magic-mcp' ); ?></button>
					<button class="mindio-button mindio-button--compact" type="button" data-tool-disable-all><?php esc_html_e( 'Disable all tools', 'mindio-magic-mcp' ); ?></button>
				</div>
			</section>

			<div class="mindio-callout mindio-callout--info mindio-tool-policy-note">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<p><?php esc_html_e( 'Disabled tools are removed from discovery and rejected when called directly. Expand integration tools to govern each read or write operation independently. Credentials and permission scopes are not changed.', 'mindio-magic-mcp' ); ?></p>
			</div>

			<div class="mindio-tool-groups">
				<?php foreach ( $groups as $key => $group ) : ?>
					<?php
					$group_id         = 'mindio-tool-group-' . sanitize_html_class( $key );
					$group_total      = count( $group['items'] );
					$group_exposed    = count( array_filter( $group['items'], static fn( array $tool ): bool => ! empty( $tool['exposed'] ) ) );
					$group_all_on     = $group_total === $group_exposed;
					$group_search     = $group['label'] . ' ' . $group['description'];
					$group_operations = array_sum( array_map( static fn( array $tool ): int => count( $tool['operations'] ), $group['items'] ) );
					?>
					<section class="mindio-card mindio-tool-group" data-tool-group data-group-key="<?php echo esc_attr( $key ); ?>" data-search="<?php echo esc_attr( $group_search ); ?>" aria-labelledby="<?php echo esc_attr( $group_id ); ?>">
						<header class="mindio-tool-group__header">
							<div class="mindio-tool-group__identity">
								<span class="mindio-settings-icon"><span class="dashicons <?php echo esc_attr( sanitize_html_class( $group['icon'] ) ); ?>" aria-hidden="true"></span></span>
								<div>
									<h3 id="<?php echo esc_attr( $group_id ); ?>"><?php echo esc_html( $group['label'] ); ?></h3>
									<p><?php echo esc_html( $group['description'] ); ?></p>
								</div>
							</div>
							<div class="mindio-tool-group__policy">
								<span>
									<?php
									/* translators: %s: number of tools. */
									echo esc_html( sprintf( _n( '%s tool', '%s tools', $group_total, 'mindio-magic-mcp' ), number_format_i18n( $group_total ) ) );
									if ( $group_operations > 0 ) {
										/* translators: %s: number of integration operations. */
										echo esc_html( ' · ' . sprintf( _n( '%s operation', '%s operations', $group_operations, 'mindio-magic-mcp' ), number_format_i18n( $group_operations ) ) );
									}
									?>
								</span>
								<label class="mindio-group-toggle">
									<input type="checkbox" data-tool-group-toggle <?php checked( $group_all_on ); ?>>
									<span class="mindio-group-toggle__track" aria-hidden="true"><span></span></span>
									<strong><?php esc_html_e( 'Enable group', 'mindio-magic-mcp' ); ?></strong>
								</label>
							</div>
						</header>
						<div class="mindio-tool-list">
							<?php foreach ( $group['items'] as $tool ) : ?>
								<?php
								$tool_id             = 'mindio-tool-' . sanitize_html_class( $tool['name'] );
								$description_id      = $tool_id . '-description';
								$operation_panel_id  = $tool_id . '-operations';
								$state               = ! empty( $tool['exposed'] ) ? 'exposed' : 'disabled';
								$state_label         = ! empty( $tool['exposed'] ) ? __( 'Exposed', 'mindio-magic-mcp' ) : __( 'Disabled', 'mindio-magic-mcp' );
								$tool_operations     = (array) $tool['operations'];
								$operation_total     = count( $tool_operations );
								$operation_enabled   = count( array_filter( $tool_operations, static fn( array $operation ): bool => ! empty( $operation['exposed'] ) ) );
								$operation_search    = implode(
									' ',
									array_map(
										static fn( array $operation ): string => (string) $operation['name'] . ' ' . (string) $operation['label'] . ' ' . (string) $operation['description'],
										$tool_operations
									)
								);
								$search = $tool['name'] . ' ' . $tool['description'] . ' ' . $operation_search . ' ' . $group_search;
								?>
								<article class="mindio-tool-row is-<?php echo esc_attr( $state ); ?> <?php echo $operation_total > 0 ? 'has-operations' : ''; ?>" data-tool-row data-search="<?php echo esc_attr( $search ); ?>" data-tool-state="<?php echo esc_attr( $state ); ?>">
									<div class="mindio-tool-row__main">
									<label class="mindio-tool-toggle" for="<?php echo esc_attr( $tool_id ); ?>" aria-label="<?php
									/* translators: %s: MCP tool name. */
									echo esc_attr( sprintf( __( 'Expose %s tool', 'mindio-magic-mcp' ), $tool['name'] ) );
									?>">
											<input id="<?php echo esc_attr( $tool_id ); ?>" type="checkbox" name="enabled_tools[]" value="<?php echo esc_attr( $tool['name'] ); ?>" data-tool-toggle aria-describedby="<?php echo esc_attr( $description_id ); ?>" <?php checked( ! empty( $tool['exposed'] ) ); ?>>
											<span class="mindio-tool-toggle__track" aria-hidden="true"><span></span></span>
										</label>
										<div class="mindio-tool-row__content">
											<div class="mindio-tool-row__title">
												<code dir="ltr"><?php echo esc_html( $tool['name'] ); ?></code>
												<?php if ( $operation_total > 0 ) : ?>
											<span class="mindio-tool-annotation">
												<?php
												/* translators: %s: number of integration operations. */
												echo esc_html( sprintf( _n( '%s operation', '%s operations', $operation_total, 'mindio-magic-mcp' ), number_format_i18n( $operation_total ) ) );
												?>
											</span>
												<?php elseif ( ! empty( $tool['destructive'] ) ) : ?>
													<span class="mindio-tool-annotation mindio-tool-annotation--danger"><?php esc_html_e( 'Destructive', 'mindio-magic-mcp' ); ?></span>
												<?php elseif ( ! empty( $tool['read_only'] ) ) : ?>
													<span class="mindio-tool-annotation"><?php esc_html_e( 'Read operation', 'mindio-magic-mcp' ); ?></span>
												<?php endif; ?>
											</div>
											<small id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $tool['description'] ); ?></small>
										</div>
										<div class="mindio-tool-row__meta">
											<?php $this->render_scope_badge( $tool['scope'] ); ?>
											<span class="mindio-tool-state mindio-tool-state--<?php echo esc_attr( $state ); ?>" data-tool-status><?php echo esc_html( $state_label ); ?></span>
											<?php if ( $operation_total > 0 ) : ?>
												<button class="mindio-operation-disclosure" type="button" data-operation-disclosure aria-expanded="false" aria-controls="<?php echo esc_attr( $operation_panel_id ); ?>">
													<span><?php esc_html_e( 'Manage', 'mindio-magic-mcp' ); ?></span>
													<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
												</button>
											<?php endif; ?>
										</div>
									</div>

									<?php if ( $operation_total > 0 ) : ?>
										<div id="<?php echo esc_attr( $operation_panel_id ); ?>" class="mindio-operation-panel" data-operation-panel hidden>
											<header class="mindio-operation-panel__header">
												<div>
													<strong><?php esc_html_e( 'Operation policy', 'mindio-magic-mcp' ); ?></strong>
												<output data-operation-summary>
													<?php
													/* translators: 1: enabled operation count, 2: total operation count. */
													echo esc_html( sprintf( __( '%1$d of %2$d operations enabled', 'mindio-magic-mcp' ), $operation_enabled, $operation_total ) );
													?>
												</output>
												</div>
												<div class="mindio-operation-panel__actions">
													<button type="button" data-operation-enable-reads><?php esc_html_e( 'Enable reads', 'mindio-magic-mcp' ); ?></button>
													<button type="button" data-operation-disable-writes><?php esc_html_e( 'Disable writes', 'mindio-magic-mcp' ); ?></button>
												</div>
											</header>
											<div class="mindio-operation-list">
												<?php foreach ( $tool_operations as $operation ) : ?>
													<?php
													$operation_id          = $tool_id . '-operation-' . sanitize_html_class( $operation['name'] );
													$operation_state       = ! empty( $operation['exposed'] ) ? 'enabled' : 'disabled';
													$operation_state_label = ! empty( $operation['exposed'] ) ? __( 'Enabled', 'mindio-magic-mcp' ) : __( 'Disabled', 'mindio-magic-mcp' );
													?>
													<label class="mindio-operation-row is-<?php echo esc_attr( $operation_state ); ?>" for="<?php echo esc_attr( $operation_id ); ?>" data-operation-row data-operation-mode="<?php echo esc_attr( $operation['mode'] ); ?>">
														<input id="<?php echo esc_attr( $operation_id ); ?>" type="checkbox" name="enabled_operations[]" value="<?php echo esc_attr( $tool['name'] . ':' . $operation['name'] ); ?>" data-operation-toggle <?php checked( ! empty( $operation['exposed'] ) ); ?>>
														<span class="mindio-operation-checkbox" aria-hidden="true"><span class="dashicons dashicons-yes"></span></span>
														<span class="mindio-operation-row__content">
															<span><strong><?php echo esc_html( $operation['label'] ); ?></strong><code dir="ltr"><?php echo esc_html( $operation['name'] ); ?></code></span>
															<small><?php echo esc_html( $operation['description'] ); ?></small>
														</span>
														<span class="mindio-operation-row__meta">
															<span class="mindio-operation-mode mindio-operation-mode--<?php echo esc_attr( $operation['mode'] ); ?>"><?php echo 'read' === $operation['mode'] ? esc_html__( 'Read', 'mindio-magic-mcp' ) : esc_html__( 'Write', 'mindio-magic-mcp' ); ?></span>
															<?php if ( ! empty( $operation['destructive'] ) ) : ?><span class="mindio-tool-annotation mindio-tool-annotation--danger"><?php esc_html_e( 'Destructive', 'mindio-magic-mcp' ); ?></span><?php endif; ?>
															<?php $this->render_scope_badge( $operation['scope'] ); ?>
															<span class="mindio-operation-state mindio-operation-state--<?php echo esc_attr( $operation_state ); ?>" data-operation-status><?php echo esc_html( $operation_state_label ); ?></span>
														</span>
													</label>
												<?php endforeach; ?>
											</div>
										</div>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>

			<div class="mindio-card mindio-tool-empty" data-tool-empty hidden>
				<?php $this->render_empty_state( 'dashicons-search', __( 'No tools match these filters', 'mindio-magic-mcp' ), __( 'Try another search term or select a different tool group.', 'mindio-magic-mcp' ) ); ?>
			</div>

			<div class="mindio-save-bar">
				<div>
					<strong><?php esc_html_e( 'Tool and operation policy changes apply to new MCP requests immediately.', 'mindio-magic-mcp' ); ?></strong>
					<span><?php esc_html_e( 'A disabled operation remains unavailable even when its parent integration tool is exposed.', 'mindio-magic-mcp' ); ?></span>
				</div>
				<button class="mindio-button mindio-button--primary" type="submit">
					<span class="dashicons dashicons-saved" aria-hidden="true"></span>
					<span data-submit-label><?php esc_html_e( 'Save tool policy', 'mindio-magic-mcp' ); ?></span>
				</button>
			</div>
		</form>
		<?php
	}

	/** @param array<string,mixed> $settings */
	private function render_settings( array $settings ): void {
		?>
		<section class="mindio-section-heading mindio-section-heading--settings">
			<div>
				<p class="mindio-eyebrow"><?php esc_html_e( 'Policy and runtime', 'mindio-magic-mcp' ); ?></p>
				<h2><?php esc_html_e( 'Settings', 'mindio-magic-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Configure transport limits, browser policy, retention, and privileged developer capabilities.', 'mindio-magic-mcp' ); ?></p>
			</div>
			<button class="mindio-button mindio-button--primary" type="submit" form="mindio-settings-form" data-settings-submit>
				<span class="dashicons dashicons-saved" aria-hidden="true"></span>
				<span data-submit-label><?php esc_html_e( 'Save changes', 'mindio-magic-mcp' ); ?></span>
			</button>
		</section>

		<form id="mindio-settings-form" class="mindio-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-loading-label="saving">
			<input type="hidden" name="action" value="mindio_magic_mcp_save_settings">
			<input type="hidden" name="redirect_tab" value="settings">
			<?php wp_nonce_field( 'mindio_magic_mcp_save_settings' ); ?>

			<section class="mindio-card mindio-settings-group">
				<div class="mindio-settings-group__intro">
					<span class="mindio-settings-icon"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span></span>
					<div>
						<h3><?php esc_html_e( 'MCP service', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Control endpoint availability and bounded request resources.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<div class="mindio-settings-group__body">
					<?php $this->render_switch( 'mindio-enabled', 'enabled', ! empty( $settings['enabled'] ), __( 'Enable MCP requests', 'mindio-magic-mcp' ), __( 'Authenticated clients can discover and call tools through the MCP endpoint.', 'mindio-magic-mcp' ) ); ?>
					<div class="mindio-settings-grid">
						<div class="mindio-field">
							<label for="mindio-rate"><?php esc_html_e( 'Rate limit', 'mindio-magic-mcp' ); ?></label>
							<div class="mindio-unit-field"><input id="mindio-rate" class="mindio-control" type="number" min="5" max="1000" name="rate_limit" value="<?php echo esc_attr( (string) $settings['rate_limit'] ); ?>"><span><?php esc_html_e( 'req / minute', 'mindio-magic-mcp' ); ?></span></div>
							<small><?php esc_html_e( 'Applied independently to each credential.', 'mindio-magic-mcp' ); ?></small>
						</div>
						<div class="mindio-field">
							<label for="mindio-upload"><?php esc_html_e( 'Media upload limit', 'mindio-magic-mcp' ); ?></label>
							<div class="mindio-unit-field"><input id="mindio-upload" class="mindio-control" type="number" min="1" max="100" name="max_upload_mb" value="<?php echo esc_attr( (string) $settings['max_upload_mb'] ); ?>"><span>MB</span></div>
							<small><?php esc_html_e( 'Maximum decoded file size accepted by media tools.', 'mindio-magic-mcp' ); ?></small>
						</div>
					</div>
				</div>
			</section>

			<section class="mindio-card mindio-settings-group">
				<div class="mindio-settings-group__intro">
					<span class="mindio-settings-icon"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span></span>
					<div>
						<h3><?php esc_html_e( 'Browser security', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Restrict cross-origin browser clients to explicit trusted origins.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<div class="mindio-settings-group__body">
					<div class="mindio-field">
						<label for="mindio-origins"><?php esc_html_e( 'Allowed browser origins', 'mindio-magic-mcp' ); ?></label>
						<textarea id="mindio-origins" class="mindio-control mindio-control--code" rows="5" name="allowed_origins" dir="ltr" placeholder="https://agent.example.com"><?php echo esc_textarea( implode( "\n", (array) $settings['allowed_origins'] ) ); ?></textarea>
						<small><?php esc_html_e( 'Enter one exact HTTP or HTTPS origin per line. Native MCP clients normally send no Origin header.', 'mindio-magic-mcp' ); ?></small>
					</div>
					<div class="mindio-callout mindio-callout--info">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<p><?php esc_html_e( 'An empty list blocks browser-origin requests while preserving native desktop and server-side MCP clients.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
			</section>

			<section class="mindio-card mindio-settings-group">
				<div class="mindio-settings-group__intro">
					<span class="mindio-settings-icon"><span class="dashicons dashicons-database" aria-hidden="true"></span></span>
					<div>
						<h3><?php esc_html_e( 'Data lifecycle', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Choose how long operational records remain available for review.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<div class="mindio-settings-group__body">
					<div class="mindio-settings-grid">
						<div class="mindio-field">
							<label for="mindio-audit-retention"><?php esc_html_e( 'Audit retention', 'mindio-magic-mcp' ); ?></label>
							<div class="mindio-unit-field"><input id="mindio-audit-retention" class="mindio-control" type="number" min="1" max="365" name="audit_retention_days" value="<?php echo esc_attr( (string) $settings['audit_retention_days'] ); ?>"><span><?php esc_html_e( 'days', 'mindio-magic-mcp' ); ?></span></div>
						</div>
						<div class="mindio-field">
							<label for="mindio-webhook-retention"><?php esc_html_e( 'Webhook retention', 'mindio-magic-mcp' ); ?></label>
							<div class="mindio-unit-field"><input id="mindio-webhook-retention" class="mindio-control" type="number" min="1" max="365" name="webhook_retention_days" value="<?php echo esc_attr( (string) $settings['webhook_retention_days'] ); ?>"><span><?php esc_html_e( 'days', 'mindio-magic-mcp' ); ?></span></div>
						</div>
					</div>
				</div>
			</section>

			<section class="mindio-card mindio-settings-group">
				<div class="mindio-settings-group__intro">
					<span class="mindio-settings-icon"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span></span>
					<div>
						<h3><?php esc_html_e( 'Developer capabilities', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Privileged tools are disabled by default and always require administrator scope.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<div class="mindio-settings-group__body">
					<div class="mindio-switch-stack">
						<?php
						$this->render_switch( 'mindio-filesystem-read', 'allow_filesystem_read', ! empty( $settings['allow_filesystem_read'] ), __( 'Read-only filesystem inspection', 'mindio-magic-mcp' ), __( 'Allow bounded text-file reads, directory listings, and searches inside approved WordPress content roots.', 'mindio-magic-mcp' ) );
						$this->render_switch( 'mindio-database-inspection', 'allow_database_inspection', ! empty( $settings['allow_database_inspection'] ), __( 'Database schema inspection', 'mindio-magic-mcp' ), __( 'Allow prepared, fixed-shape queries that list non-sensitive tables and describe their schemas. Table rows are never returned.', 'mindio-magic-mcp' ) );
						$this->render_switch( 'mindio-wp-cli', 'allow_wp_cli', ! empty( $settings['allow_wp_cli'] ), __( 'Allowlisted WP-CLI commands', 'mindio-magic-mcp' ), __( 'Permit safe in-process WP-CLI commands only when WP_CLI is already loaded.', 'mindio-magic-mcp' ) );
						?>
					</div>
					<div class="mindio-callout mindio-callout--warning">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<p><?php esc_html_e( 'Enable these capabilities only for controlled administrator agents. Every invocation is written to the audit trail.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
			</section>

			<section class="mindio-card mindio-settings-group mindio-settings-group--danger">
				<div class="mindio-settings-group__intro">
					<span class="mindio-settings-icon"><span class="dashicons dashicons-trash" aria-hidden="true"></span></span>
					<div>
						<h3><?php esc_html_e( 'Uninstall behavior', 'mindio-magic-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Decide whether operational data survives plugin removal.', 'mindio-magic-mcp' ); ?></p>
					</div>
				</div>
				<div class="mindio-settings-group__body">
					<?php $this->render_switch( 'mindio-delete-data', 'delete_on_uninstall', ! empty( $settings['delete_on_uninstall'] ), __( 'Delete all plugin data on uninstall', 'mindio-magic-mcp' ), __( 'Permanently remove credentials, OAuth registrations, webhooks, configuration, and logs when the plugin is deleted.', 'mindio-magic-mcp' ), true ); ?>
				</div>
			</section>

			<div class="mindio-save-bar">
				<div>
					<strong><?php esc_html_e( 'Configuration changes are audited through WordPress administration.', 'mindio-magic-mcp' ); ?></strong>
					<span><?php esc_html_e( 'Review security-sensitive options before saving.', 'mindio-magic-mcp' ); ?></span>
				</div>
				<button class="mindio-button mindio-button--primary" type="submit">
					<span class="dashicons dashicons-saved" aria-hidden="true"></span>
					<span data-submit-label><?php esc_html_e( 'Save changes', 'mindio-magic-mcp' ); ?></span>
				</button>
			</div>
		</form>
		<?php
	}

	private function render_metric( string $label, string $value, string $detail, string $icon, string $tone ): void {
		?>
		<article class="mindio-metric mindio-metric--<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>">
			<span class="mindio-metric__icon"><span class="dashicons <?php echo esc_attr( sanitize_html_class( $icon ) ); ?>" aria-hidden="true"></span></span>
			<div>
				<span><?php echo esc_html( $label ); ?></span>
				<strong><?php echo esc_html( $value ); ?></strong>
				<small><?php echo esc_html( $detail ); ?></small>
			</div>
		</article>
		<?php
	}

	private function render_endpoint( string $id, string $label, string $value, string $description ): void {
		?>
		<div class="mindio-endpoint">
			<div class="mindio-endpoint__meta">
				<strong><?php echo esc_html( $label ); ?></strong>
				<small><?php echo esc_html( $description ); ?></small>
			</div>
			<div class="mindio-inline-copy mindio-inline-copy--endpoint">
				<code id="<?php echo esc_attr( $id ); ?>" dir="ltr"><?php echo esc_html( $value ); ?></code>
				<?php $this->render_copy_button( $id, $label ); ?>
			</div>
		</div>
		<?php
	}

	private function render_copy_button( string $target_id, string $accessible_label, bool $compact = false ): void {
		?>
		<button
			class="mindio-copy-button <?php echo $compact ? 'mindio-copy-button--compact' : ''; ?>"
			type="button"
			data-copy-target="<?php echo esc_attr( $target_id ); ?>"
			aria-label="<?php
			/* translators: %s: label of the value being copied. */
			echo esc_attr( sprintf( __( 'Copy %s', 'mindio-magic-mcp' ), $accessible_label ) );
			?>"
		>
			<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
			<span class="mindio-copy-button__label"><?php esc_html_e( 'Copy', 'mindio-magic-mcp' ); ?></span>
		</button>
		<?php
	}

	private function render_readiness_row( string $label, string $value, string $tone ): void {
		?>
		<div class="mindio-readiness__row">
			<span><?php echo esc_html( $label ); ?></span>
			<div>
				<strong><?php echo esc_html( $value ); ?></strong>
				<span class="mindio-readiness__indicator mindio-readiness__indicator--<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>" aria-hidden="true"></span>
			</div>
		</div>
		<?php
	}

	private function render_status_badge( string $label, string $tone ): void {
		$tones = array( 'success', 'danger', 'warning', 'neutral', 'info' );
		$tone  = in_array( $tone, $tones, true ) ? $tone : 'neutral';
		?>
		<span class="mindio-badge mindio-badge--<?php echo esc_attr( $tone ); ?>">
			<span aria-hidden="true"></span>
			<?php echo esc_html( $label ); ?>
		</span>
		<?php
	}

	private function render_scope_badge( string $scope ): void {
		$labels = array(
			Auth::SCOPE_READ   => __( 'Read only', 'mindio-magic-mcp' ),
			Auth::SCOPE_EDITOR => __( 'Editor', 'mindio-magic-mcp' ),
			Auth::SCOPE_ADMIN  => __( 'Administrator', 'mindio-magic-mcp' ),
		);
		$tone = match ( $scope ) {
			Auth::SCOPE_ADMIN  => 'danger',
			Auth::SCOPE_EDITOR => 'info',
			default            => 'neutral',
		};
		$this->render_status_badge( $labels[ $scope ] ?? $scope, $tone );
	}

	private function render_empty_state( string $icon, string $title, string $description ): void {
		?>
		<div class="mindio-empty">
			<span class="mindio-empty__icon"><span class="dashicons <?php echo esc_attr( sanitize_html_class( $icon ) ); ?>" aria-hidden="true"></span></span>
			<strong><?php echo esc_html( $title ); ?></strong>
			<p><?php echo esc_html( $description ); ?></p>
		</div>
		<?php
	}

	private function render_switch( string $id, string $name, bool $checked, string $title, string $description, bool $danger = false ): void {
		?>
		<label class="mindio-switch <?php echo $danger ? 'mindio-switch--danger' : ''; ?>" for="<?php echo esc_attr( $id ); ?>">
			<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
			<span class="mindio-switch__track" aria-hidden="true"><span></span></span>
			<span class="mindio-switch__content">
				<strong><?php echo esc_html( $title ); ?></strong>
				<small><?php echo esc_html( $description ); ?></small>
			</span>
		</label>
		<?php
	}

	/** @return array<string,array{label:string,description:string,icon:string,tools:array<int,string>}> */
	private function tool_groups(): array {
		return array(
			'content'     => array(
				'label'       => __( 'Content and publishing', 'mindio-magic-mcp' ),
				'description' => __( 'Posts, pages, custom post types, publishing, and scheduling.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-post',
				'tools'       => array( 'create_post', 'get_post', 'update_post', 'delete_post', 'publish_post', 'schedule_post', 'list_posts' ),
			),
			'gutenberg'   => array(
				'label'       => __( 'Gutenberg blocks', 'mindio-magic-mcp' ),
				'description' => __( 'Structured block discovery, tree editing, duplication, movement, and patterns.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-block-default',
				'tools'       => array( 'list_blocks', 'get_block_schema', 'get_post_blocks', 'list_patterns', 'add_block', 'update_block', 'remove_block', 'move_block', 'duplicate_block', 'insert_pattern' ),
			),
			'media-seo'   => array(
				'label'       => __( 'Media and SEO', 'mindio-magic-mcp' ),
				'description' => __( 'Media Library operations and normalized search metadata.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-format-image',
				'tools'       => array( 'upload_media', 'list_media', 'attach_media', 'delete_media', 'get_meta', 'update_meta', 'yoast_seo_read', 'yoast_seo_write', 'rank_math_read', 'rank_math_write' ),
			),
			'flatsome'     => array(
				'label'       => __( 'Flatsome Builder', 'mindio-magic-mcp' ),
				'description' => __( 'Native-first UX Builder discovery, page creation, and layout editing.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-layout',
				'tools'       => array( 'list_flatsome_components', 'create_flatsome_page', 'get_flatsome_page', 'add_section', 'add_row', 'add_element', 'get_flatsome_theme_settings', 'update_flatsome_theme_settings' ),
			),
			'people'       => array(
				'label'       => __( 'People and comments', 'mindio-magic-mcp' ),
				'description' => __( 'User lifecycle, password recovery, discussion, and moderation.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-groups',
				'tools'       => array( 'list_users', 'create_user', 'update_user', 'delete_user', 'send_password_reset', 'list_comments', 'approve_comment', 'delete_comment', 'reply_comment' ),
			),
			'site'         => array(
				'label'       => __( 'Site and discovery', 'mindio-magic-mcp' ),
				'description' => __( 'Curated WordPress settings and cross-content search.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-site-alt3',
				'tools'       => array( 'get_settings', 'update_settings', 'search_content' ),
			),
			'automation'   => array(
				'label'       => __( 'Content automation', 'mindio-magic-mcp' ),
				'description' => __( 'Generation-provider workflows, summaries, translations, and bounded bulk actions.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-update',
				'tools'       => array( 'generate_post_from_prompt', 'summarize_content', 'translate_content', 'bulk_actions' ),
			),
			'webhooks'     => array(
				'label'       => __( 'Webhook automation', 'mindio-magic-mcp' ),
				'description' => __( 'Signed event destinations and subscription management.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-rest-api',
				'tools'       => array( 'register_webhook', 'unregister_webhook', 'list_webhooks' ),
			),
			'extensions'   => array(
				'label'       => __( 'Plugins and themes', 'mindio-magic-mcp' ),
				'description' => __( 'Official-directory package lifecycle, generic theme controls, and child themes.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-plugins',
				'tools'       => array( 'list_plugins', 'search_plugins', 'install_plugin', 'update_plugin', 'activate_plugin', 'deactivate_plugin', 'delete_plugin', 'list_themes', 'search_themes', 'install_theme', 'update_theme', 'delete_theme', 'switch_theme', 'get_theme_context', 'get_theme_mods', 'update_theme_mods', 'create_child_theme' ),
			),
			'integrations' => array(
				'label'       => __( 'Plugin integrations', 'mindio-magic-mcp' ),
				'description' => __( 'Operation-level controls for ACF Free, BetterDocs, and Contact Form 7.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-generic',
				'tools'       => array( 'acf_read', 'acf_write', 'betterdocs_read', 'betterdocs_write', 'contact_form_7_read', 'contact_form_7_write' ),
			),
			'operations'   => array(
				'label'       => __( 'Operations and diagnostics', 'mindio-magic-mcp' ),
				'description' => __( 'Read-only filesystem and database inspection, logs, maintenance, caches, CDN, and image optimization.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-performance',
				'tools'       => array( 'run_wp_cli', 'list_database_tables', 'describe_database_table', 'read_file', 'list_directory', 'search_files', 'clear_cache', 'get_error_logs', 'purge_cdn', 'control_cache', 'trigger_image_optimization', 'get_server_status', 'get_activity_logs', 'get_webhook_logs' ),
			),
			'woocommerce'  => array(
				'label'       => __( 'WooCommerce', 'mindio-magic-mcp' ),
				'description' => __( 'Products, orders, customers, inventory, and coupon operations when WooCommerce is active.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-cart',
				'tools'       => array( 'create_product', 'update_product', 'list_orders', 'manage_customers', 'manage_inventory', 'apply_coupons', 'woocommerce_read', 'woocommerce_write' ),
			),
			'multisite'    => array(
				'label'       => __( 'Multisite', 'mindio-magic-mcp' ),
				'description' => __( 'Network site discovery and stateless site-context validation.', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-multisite',
				'tools'       => array( 'list_sites', 'switch_site_context' ),
			),
		);
	}

	/** @return array<string,array{label:string,description:string,icon:string}> */
	private function tabs(): array {
		return array(
			'overview'    => array(
				'label'       => __( 'Overview', 'mindio-magic-mcp' ),
				'description' => __( 'Health & connection', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-chart-area',
			),
			'tools'       => array(
				'label'       => __( 'Tools', 'mindio-magic-mcp' ),
				'description' => __( 'Exposure & policy', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-screenoptions',
			),
			'credentials' => array(
				'label'       => __( 'Credentials', 'mindio-magic-mcp' ),
				'description' => __( 'Keys & OAuth', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-network',
			),
			'webhooks'    => array(
				'label'       => __( 'Webhooks', 'mindio-magic-mcp' ),
				'description' => __( 'Events & delivery', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-rest-api',
			),
			'activity'    => array(
				'label'       => __( 'Activity', 'mindio-magic-mcp' ),
				'description' => __( 'Audit & diagnostics', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-list-view',
			),
			'settings'    => array(
				'label'       => __( 'Settings', 'mindio-magic-mcp' ),
				'description' => __( 'Policy & runtime', 'mindio-magic-mcp' ),
				'icon'        => 'dashicons-admin-settings',
			),
		);
	}

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Display-only input is type-checked, unslashed, and sanitized immediately below.
		$value = $_GET['tab'] ?? 'overview';
		$tab   = is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : 'overview';
		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return wp_parse_args(
			get_option( 'mindio_magic_mcp_settings', array() ),
			array(
				'enabled'                => true,
				'rate_limit'             => 60,
				'max_upload_mb'          => 10,
				'audit_retention_days'   => 30,
				'webhook_retention_days' => 14,
				'allowed_origins'        => array(),
				'delete_on_uninstall'    => false,
				'allow_database_inspection'       => false,
				'allow_filesystem_read'  => false,
				'allow_wp_cli'           => false,
			)
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function oauth_clients(): array {
		$clients = get_option( 'mindio_magic_mcp_oauth_clients', array() );
		return is_array( $clients ) ? $clients : array();
	}

	private function user_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( $user ) {
			return (string) ( $user->display_name ?: $user->user_login );
		}
		if ( 0 === $user_id ) {
			return __( 'System', 'mindio-magic-mcp' );
		}
		return sprintf(
			/* translators: %d: WordPress user ID. */
			__( 'User #%d', 'mindio-magic-mcp' ),
			$user_id
		);
	}

	private function format_datetime( string $value ): string {
		if ( '' === trim( $value ) || '0000-00-00 00:00:00' === $value ) {
			return '—';
		}
		if ( ! preg_match( '/(?:Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			$value .= ' UTC';
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), $timestamp );
	}

	private function event_description( string $event ): string {
		return match ( $event ) {
			'post_created'  => __( 'A post, page, or custom post is created.', 'mindio-magic-mcp' ),
			'post_updated'  => __( 'Existing content is updated.', 'mindio-magic-mcp' ),
			'comment_added' => __( 'A new comment is submitted.', 'mindio-magic-mcp' ),
			'order_created' => __( 'WooCommerce creates a new order.', 'mindio-magic-mcp' ),
			default         => '',
		};
	}

	private function delivery_tone( string $status ): string {
		return match ( $status ) {
			'delivered'           => 'success',
			'failed'              => 'danger',
			'retrying', 'queued'  => 'warning',
			default               => 'neutral',
		};
	}

	private function delivery_label( string $status ): string {
		return match ( $status ) {
			'delivered' => __( 'Delivered', 'mindio-magic-mcp' ),
			'failed'    => __( 'Failed', 'mindio-magic-mcp' ),
			'retrying'  => __( 'Retrying', 'mindio-magic-mcp' ),
			'queued'    => __( 'Queued', 'mindio-magic-mcp' ),
			default     => ucfirst( $status ),
		};
	}

	private function tab_url( string $tab ): string {
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'overview';
		return add_query_arg(
			array(
				'page' => 'mindio-magic-mcp',
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage Mindio Magic MCP.', 'mindio-magic-mcp' ), '', array( 'response' => 403 ) );
		}
	}

	/** @param array<string,mixed>|\WP_Error $result */
	private function flash_result( array|\WP_Error $result, string $kind ): void {
		if ( is_wp_error( $result ) ) {
			$this->flash( 'error', $result->get_error_message() );
		} else {
			$secret  = 'api_key' === $kind ? (string) $result['token'] : (string) $result['secret'];
			$message = 'api_key' === $kind ? __( 'API key created. Copy it now; it cannot be recovered.', 'mindio-magic-mcp' ) : __( 'Webhook created. Copy its signing secret now.', 'mindio-magic-mcp' );
			$this->flash( 'success', $message, $secret );
		}
		$this->redirect();
	}

	private function flash( string $type, string $message, string $secret = '' ): void {
		set_transient( 'mindio_magic_mcp_admin_flash_' . get_current_user_id(), compact( 'type', 'message', 'secret' ), 5 * MINUTE_IN_SECONDS );
	}

	private function redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Every private caller verifies its nonce; input is type-checked, unslashed, and sanitized below.
		$value = $_POST['redirect_tab'] ?? 'overview';
		$tab   = is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : 'overview';
		wp_safe_redirect( $this->tab_url( $tab ) );
		exit;
	}
}
