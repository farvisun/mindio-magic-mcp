<?php
/**
 * MCP webhook configuration tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhook_Tools {
	private Tool_Registry $registry;
	private Webhook_Engine $webhooks;

	public function __construct( Tool_Registry $registry, Webhook_Engine $webhooks ) {
		$this->registry = $registry;
		$this->webhooks = $webhooks;
	}

	public function register(): void {
		$this->registry->register(
			'register_webhook',
			__( 'Register a signed HTTPS webhook for WordPress post, comment, or WooCommerce order events. The signing secret is returned once.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'name'   => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
					'url'    => array( 'type' => 'string', 'format' => 'uri', 'maxLength' => 2048 ),
					'events' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 4, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'enum' => Webhook_Engine::EVENTS ) ),
				),
				'required'             => array( 'name', 'url', 'events' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			fn( array $args ): array|\WP_Error => $this->webhooks->register_webhook( (string) $args['name'], (string) $args['url'], (array) $args['events'] ),
			Auth::SCOPE_ADMIN,
			'manage_options'
		);
		$this->registry->register(
			'unregister_webhook',
			__( 'Unregister a webhook by ID.', 'mindio-magic-mcp' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'webhook_id' => array( 'type' => 'string', 'pattern' => '^wh_[a-z0-9]{20}$' ) ),
				'required'             => array( 'webhook_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			function ( array $args ): array|\WP_Error {
				return $this->webhooks->unregister_webhook( sanitize_key( (string) $args['webhook_id'] ) )
					? array( 'webhook_id' => $args['webhook_id'], 'unregistered' => true )
					: new \WP_Error( 'webhook_not_found', __( 'Webhook not found.', 'mindio-magic-mcp' ) );
			},
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'list_webhooks',
			__( 'List configured webhooks without revealing signing secrets.', 'mindio-magic-mcp' ),
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			array( 'type' => 'object' ),
			fn(): array => array( 'webhooks' => $this->webhooks->list_webhooks() ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}
}

