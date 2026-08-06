<?php
/**
 * Monitoring and server-introspection tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class System_Tools {
	private Tool_Registry $registry;
	private Audit_Log $audit;
	private Webhook_Engine $webhooks;

	public function __construct( Tool_Registry $registry, Audit_Log $audit, Webhook_Engine $webhooks ) {
		$this->registry = $registry;
		$this->audit    = $audit;
		$this->webhooks = $webhooks;
	}

	public function register(): void {
		$this->registry->register(
			'get_server_status',
			__( 'Get the MCP endpoint, WordPress/Flatsome versions, locale, and capabilities available to the current credential.', 'mindio-magic-mcp' ),
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			array( 'type' => 'object' ),
			array( $this, 'status' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'get_activity_logs',
			__( 'Read the durable MCP audit trail with redacted sensitive inputs.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'tool'    => array( 'type' => 'string', 'maxLength' => 64 ),
					'success' => array( 'type' => 'boolean' ),
					'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
					'offset'  => array( 'type' => 'integer', 'minimum' => 0 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			fn( array $args ): array => array( 'items' => $this->audit->list( $args ) ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'get_webhook_logs',
			__( 'Read webhook delivery attempts, response codes, retry state, and optionally their event payloads.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'webhook_id'     => array( 'type' => 'string', 'maxLength' => 64 ),
					'status'         => array( 'type' => 'string', 'enum' => array( 'queued', 'retrying', 'delivered', 'failed' ) ),
					'include_payload' => array( 'type' => 'boolean' ),
					'limit'          => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
					'offset'         => array( 'type' => 'integer', 'minimum' => 0 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			fn( array $args ): array => array( 'items' => $this->webhooks->list_logs( $args ) ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed> */
	public function status( array $args = array() ): array {
		unset( $args );
		$theme = wp_get_theme();
		return array(
			'plugin_version'  => MINDIO_MAGIC_MCP_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'     => PHP_VERSION,
			'mcp_endpoint'    => rest_url( MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' ),
			'oauth_metadata'  => home_url( '/.well-known/oauth-authorization-server' ),
			'locale'          => determine_locale(),
			'is_rtl'          => is_rtl(),
			'theme'           => array(
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'template'         => get_template(),
				'flatsome_active'  => 'flatsome' === get_template(),
			),
			'available_tools' => count( $this->registry->list_tools() ),
		);
	}
}

