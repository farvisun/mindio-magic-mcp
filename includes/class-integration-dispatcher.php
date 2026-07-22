<?php
/**
 * Shared compact dispatcher for integration operation families.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Integration_Dispatcher {
	/** @var array<string,array<string,mixed>>|null */
	private static ?array $installed_plugins = null;
	protected Tool_Registry $registry;
	private Schema_Validator $validator;
	private string $integration;
	private string $label;

	public function __construct( Tool_Registry $registry, string $integration, string $label ) {
		$this->registry    = $registry;
		$this->validator   = new Schema_Validator();
		$this->integration = sanitize_key( $integration );
		$this->label       = $label;
	}

	/**
	 * @param array<string,array<string,mixed>> $operations Operation definitions.
	 */
	protected function register_operations(
		array $operations,
		string $read_scope = Auth::SCOPE_READ,
		string $write_scope = Auth::SCOPE_EDITOR
	): void {
		if ( ! $this->dependency_installed() ) {
			return;
		}

		foreach ( array( 'read' => $read_scope, 'write' => $write_scope ) as $mode => $scope ) {
			$selected = array_filter(
				$operations,
				static fn( array $operation ): bool => $mode === ( $operation['mode'] ?? 'read' )
			);
			if ( ! $selected ) {
				continue;
			}
			$tool_name = $this->integration . '_' . $mode;
			$metadata  = array();
			foreach ( $selected as $name => $operation ) {
				$metadata[ $name ] = array(
					'label'           => (string) ( $operation['label'] ?? $name ),
					'description'     => (string) ( $operation['description'] ?? '' ),
					'mode'            => $mode,
					'destructive'     => ! empty( $operation['destructive'] ),
					'default_exposed' => array_key_exists( 'default_exposed', $operation ) ? (bool) $operation['default_exposed'] : 'read' === $mode,
					'scope'           => (string) ( $operation['scope'] ?? ( 'read' === $mode ? $read_scope : $write_scope ) ),
				);
			}
			$description = 'read' === $mode
				? sprintf(
					/* translators: %s: integration name, for example WooCommerce. */
					__( 'Run an enabled read operation for the %s integration, or omit operation to inspect its available read capabilities.', 'mindio-magic-mcp' ),
					$this->label
				)
				: sprintf(
					/* translators: %s: integration name, for example WooCommerce. */
					__( 'Run an administrator-enabled write operation for the %s integration. Write operations are disabled by default.', 'mindio-magic-mcp' ),
					$this->label
				);
			$this->registry->register(
				$tool_name,
				$description,
				array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array(
							'type'        => 'string',
							'enum'        => array_keys( $selected ),
							'description' => __( 'Enabled integration operation to execute. Omit it to return the operation catalog.', 'mindio-magic-mcp' ),
						),
						'arguments' => array(
							'type'        => 'object',
							'description' => __( 'Arguments validated against the selected operation schema.', 'mindio-magic-mcp' ),
						),
					),
					'additionalProperties' => false,
				),
				array( 'type' => 'object' ),
				function ( array $args ) use ( $tool_name, $selected ): array|\WP_Error {
					return $this->dispatch( $tool_name, $selected, $args );
				},
				$scope,
				'read',
				array(
					'readOnlyHint'    => 'read' === $mode,
					'destructiveHint' => false,
					'idempotentHint'  => 'read' === $mode,
				),
				array( 'operations' => $metadata )
			);
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $operations
	 * @return array<string,mixed>|\WP_Error
	 */
	private function dispatch( string $tool_name, array $operations, array $args ): array|\WP_Error {
		if ( ! $this->dependency_available() ) {
				return new \WP_Error(
					'integration_unavailable',
					sprintf(
						/* translators: %s: required WordPress plugin name. */
						__( '%s is not installed and active.', 'mindio-magic-mcp' ),
						$this->dependency_label()
					)
				);
		}
		$operation_name = sanitize_key( (string) ( $args['operation'] ?? '' ) );
		if ( '' === $operation_name ) {
			return array(
				'integration' => $this->integration,
				'available'   => true,
				'operations'  => $this->registry->operation_catalog( $tool_name, true ),
			);
		}
		if ( ! isset( $operations[ $operation_name ] ) ) {
			return new \WP_Error( 'unknown_operation', __( 'Unknown integration operation.', 'mindio-magic-mcp' ) );
		}
		$operation = $operations[ $operation_name ];
		$arguments = (array) ( $args['arguments'] ?? array() );
		$required_scope = (string) ( $operation['scope'] ?? ( 'read' === ( $operation['mode'] ?? 'read' ) ? Auth::SCOPE_READ : Auth::SCOPE_EDITOR ) );
		if ( ! $this->registry->current_scope_allows( $required_scope ) ) {
			return new \WP_Error( 'insufficient_scope', __( 'The access token does not grant this integration operation scope.', 'mindio-magic-mcp' ) );
		}
		$validation = $this->validator->validate( $arguments, (array) ( $operation['schema'] ?? $this->empty_schema() ) );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$capability = $operation['capability'] ?? ( 'read' === ( $operation['mode'] ?? 'read' ) ? 'read' : 'edit_posts' );
		$allowed    = is_callable( $capability ) ? (bool) call_user_func( $capability, $arguments ) : current_user_can( (string) $capability );
		if ( ! $allowed ) {
			return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot perform this integration operation.', 'mindio-magic-mcp' ) );
		}
		$callback = $operation['callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return new \WP_Error( 'operation_unavailable', __( 'The integration operation is not callable.', 'mindio-magic-mcp' ) );
		}
		$result = call_user_func( $callback, $arguments );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'integration' => $this->integration,
			'operation'   => $operation_name,
			'result'      => is_array( $result ) ? $result : array( 'value' => $result ),
		);
	}

	protected function object_schema( array $properties = array(), array $required = array() ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	protected function empty_schema(): array {
		return $this->object_schema();
	}

	/**
	 * Detect an installed plugin even when it is inactive or its directory was renamed.
	 *
	 * @param array<int,string> $plugin_files Official plugin entry files.
	 * @param array<int,string> $text_domains Accepted plugin header text domains.
	 */
	protected function plugin_is_installed( array $plugin_files, array $text_domains ): bool {
		$plugin_files = array_values(
			array_filter(
				array_map(
					static fn( mixed $file ): string => is_string( $file ) ? trim( wp_normalize_path( $file ), '/' ) : '',
					$plugin_files
				)
			)
		);
		foreach ( $plugin_files as $plugin_file ) {
			if ( is_file( wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file ) ) ) {
				return true;
			}
		}

		if ( null === self::$installed_plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$installed_plugins = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
			if ( function_exists( 'get_mu_plugins' ) ) {
				self::$installed_plugins = array_merge( self::$installed_plugins, (array) get_mu_plugins() );
			}
		}

		$text_domains = array_values(
			array_filter(
				array_map(
					static fn( mixed $domain ): string => is_string( $domain ) ? sanitize_key( $domain ) : '',
					$text_domains
				)
			)
		);
		foreach ( self::$installed_plugins as $plugin_file => $headers ) {
			if ( in_array( wp_normalize_path( (string) $plugin_file ), $plugin_files, true ) ) {
				return true;
			}
			$text_domain = is_array( $headers ) ? sanitize_key( (string) ( $headers['TextDomain'] ?? '' ) ) : '';
			if ( '' !== $text_domain && in_array( $text_domain, $text_domains, true ) ) {
				return true;
			}
		}

		return false;
	}

	abstract protected function dependency_installed(): bool;

	abstract protected function dependency_available(): bool;

	abstract protected function dependency_label(): string;
}
