<?php
/**
 * MCP tool registry and authorization dispatcher.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Tool_Registry {
	public const EXPOSURE_OPTION = 'mindio_magic_mcp_disabled_tools';
	public const OPERATION_POLICY_OPTION = 'mindio_magic_mcp_operation_policy';

	/** @var array<string,array<string,mixed>> */
	private array $tools = array();
	private Auth $auth;
	private Schema_Validator $validator;

	public function __construct( Auth $auth, ?Schema_Validator $validator = null ) {
		$this->auth      = $auth;
		$this->validator = $validator ?? new Schema_Validator();
	}

	/**
	 * @param callable $callback Receives validated arguments and returns an array or WP_Error.
	 * @param string|callable $capability WordPress capability or callback receiving arguments.
	 */
	public function register(
		string $name,
		string $description,
		array $input_schema,
		array $output_schema,
		callable $callback,
		string $scope = Auth::SCOPE_READ,
		string|callable $capability = 'read',
		array $annotations = array(),
		array $metadata = array()
	): void {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid MCP tool name: ' . $name ) );
		}
		if ( isset( $this->tools[ $name ] ) ) {
			throw new \LogicException( esc_html( 'Duplicate MCP tool name: ' . $name ) );
		}

		$supports_dry_run = array_key_exists( 'dry_run', $metadata )
			? (bool) $metadata['dry_run']
			: ( empty( $annotations['readOnlyHint'] ) && ! in_array( $name, self::unpreviewable_tools(), true ) );

		if ( 'object' === ( $input_schema['type'] ?? null ) ) {
			$input_schema['properties'] = (array) ( $input_schema['properties'] ?? array() );
			if ( $supports_dry_run ) {
				$input_schema['properties']['dry_run'] = array(
					'type'        => 'boolean',
					'description' => __( 'Preview this call without committing it. Returns the exact post, meta, term, option, comment, and user changes it would make.', 'mindio-magic-mcp' ),
				);
			}
			$input_schema['properties']['response_locale'] = array(
				'type'        => 'string',
				'maxLength'   => 20,
				'description' => __( 'Optional installed WordPress locale for localized response messages, for example fa_IR or en_US.', 'mindio-magic-mcp' ),
			);
			$input_schema['properties']['site_id'] = array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'On multisite, optionally execute this one call in the selected site context.', 'mindio-magic-mcp' ),
			);
		}

		$operations = $this->normalize_operations( (array) ( $metadata['operations'] ?? array() ) );
		$this->tools[ $name ] = array(
			'name'         => $name,
			'description'  => $description,
			'inputSchema'  => $input_schema,
			'outputSchema' => $output_schema,
			'callback'     => $callback,
			'scope'        => $scope,
			'capability'   => $capability,
			'annotations'  => wp_parse_args(
				$annotations,
				array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
					'openWorldHint'   => false,
				)
			),
			'operations'   => $operations,
			'dry_run'      => $supports_dry_run,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function list_tools(): array {
		$visible = array();
		$disabled = array_fill_keys( $this->disabled_tools(), true );
		foreach ( $this->tools as $tool ) {
			if ( isset( $disabled[ $tool['name'] ] ) ) {
				continue;
			}
			if ( ! $this->auth->scope_allows( $this->auth->current_scope(), $tool['scope'] ) ) {
				continue;
			}
			if ( is_string( $tool['capability'] ) && ! current_user_can( $tool['capability'] ) ) {
				continue;
			}
			$input_schema = $tool['inputSchema'];
			if ( $tool['operations'] ) {
				$operation_names = array();
				foreach ( $tool['operations'] as $operation => $operation_data ) {
					if ( $this->is_operation_exposed( $tool['name'], $operation ) ) {
						$operation_names[] = $operation;
					}
				}
				if ( ! $operation_names ) {
					continue;
				}
				if ( isset( $input_schema['properties']['operation'] ) ) {
					$input_schema['properties']['operation']['enum'] = $operation_names;
				}
			}
			$visible[] = array(
				'name'         => $tool['name'],
				'description'  => $tool['description'],
				'inputSchema'  => $input_schema,
				'outputSchema' => $tool['outputSchema'],
				'annotations'  => $tool['annotations'],
			);
		}

		return $visible;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function call( string $name, array $arguments ) {
		if ( ! isset( $this->tools[ $name ] ) ) {
			return new \WP_Error( 'unknown_tool', __( 'Unknown tool.', 'mindio-magic-mcp' ) );
		}

		$tool = $this->tools[ $name ];
		$blog_switched   = false;
		$locale_switched = false;
		if ( isset( $arguments['response_locale'] ) && is_string( $arguments['response_locale'] ) && '' !== $arguments['response_locale'] && strlen( $arguments['response_locale'] ) <= 20 ) {
			$locale    = sanitize_locale_name( $arguments['response_locale'] );
			$available = array_merge( array( 'en_US', determine_locale(), get_locale() ), get_available_languages() );
			if ( ! in_array( $locale, array_unique( $available ), true ) ) {
				return new \WP_Error( 'invalid_locale', __( 'The requested response locale is not installed.', 'mindio-magic-mcp' ) );
			}
			$locale_switched = switch_to_locale( $locale );
		}

		try {
			if ( ! $this->auth->scope_allows( $this->auth->current_scope(), $tool['scope'] ) ) {
				return new \WP_Error( 'insufficient_scope', __( 'The access token does not grant this tool scope.', 'mindio-magic-mcp' ) );
			}

			$validation = $this->validator->validate( $arguments, $tool['inputSchema'] );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$site_id = absint( $arguments['site_id'] ?? 0 );
			if ( $site_id && $site_id !== get_current_blog_id() ) {
				if ( ! is_multisite() || ! get_site( $site_id ) ) {
					return new \WP_Error( 'site_not_found', __( 'The requested multisite site does not exist.', 'mindio-magic-mcp' ) );
				}
				$user_id = get_current_user_id();
				if ( ! current_user_can( 'manage_sites' ) && ! current_user_can( 'manage_network' ) && ! is_user_member_of_blog( $user_id, $site_id ) ) {
					return new \WP_Error( 'site_forbidden', __( 'Your user cannot access the requested site.', 'mindio-magic-mcp' ) );
				}
				$blog_switched = switch_to_blog( $site_id );
			}

			if ( ! $this->is_exposed( $name ) ) {
				return new \WP_Error( 'tool_disabled', __( 'This tool is disabled by the site administrator.', 'mindio-magic-mcp' ) );
			}
			if ( $tool['operations'] && isset( $arguments['operation'] ) && is_string( $arguments['operation'] ) ) {
				$operation = sanitize_key( $arguments['operation'] );
				if ( ! isset( $tool['operations'][ $operation ] ) ) {
					return new \WP_Error( 'unknown_operation', __( 'Unknown integration operation.', 'mindio-magic-mcp' ) );
				}
				if ( ! $this->is_operation_exposed( $name, $operation ) ) {
					return new \WP_Error( 'operation_disabled', __( 'This integration operation is disabled by the site administrator.', 'mindio-magic-mcp' ) );
				}
			}

			$allowed = is_callable( $tool['capability'] )
				? (bool) call_user_func( $tool['capability'], $arguments )
				: current_user_can( $tool['capability'] );
			if ( ! $allowed ) {
				return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot perform this action.', 'mindio-magic-mcp' ) );
			}

			if ( ! empty( $arguments['dry_run'] ) ) {
				if ( empty( $tool['dry_run'] ) ) {
					return new \WP_Error(
						'dry_run_unsupported',
						__( 'This tool changes files or external state that cannot be rolled back, so it cannot be previewed.', 'mindio-magic-mcp' )
					);
				}
				$result = ( new Dry_Run() )->run(
					static fn() => call_user_func( $tool['callback'], $arguments )
				);
			} else {
				$result = call_user_func( $tool['callback'], $arguments );
			}
		} catch ( \Throwable $throwable ) {
			do_action( 'mindio_magic_mcp_tool_exception', $throwable, $name, $arguments );
			return new \WP_Error( 'tool_exception', __( 'The tool failed unexpectedly.', 'mindio-magic-mcp' ) );
		} finally {
			if ( $locale_switched ) {
				restore_previous_locale();
			}
			if ( $blog_switched ) {
				restore_current_blog();
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_array( $result ) ? $result : array( 'result' => $result );
	}

	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	public function current_scope_allows( string $required_scope ): bool {
		return $this->auth->scope_allows( $this->auth->current_scope(), $required_scope );
	}

	/**
	 * Return administrator-safe metadata for every registered tool.
	 *
	 * This catalog intentionally ignores the current credential scope and user
	 * capabilities so site administrators can govern the complete server surface.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function catalog(): array {
		$disabled = array_fill_keys( $this->disabled_tools(), true );
		$catalog  = array();

		foreach ( $this->tools as $tool ) {
			$operations = array();
			foreach ( $tool['operations'] as $operation => $operation_data ) {
				$operations[] = array_merge(
					$operation_data,
					array(
						'name'    => $operation,
						'exposed' => $this->is_operation_exposed( $tool['name'], $operation ),
					)
				);
			}
			$catalog[] = array(
				'name'        => (string) $tool['name'],
				'description' => (string) $tool['description'],
				'scope'       => (string) $tool['scope'],
				'exposed'     => ! isset( $disabled[ $tool['name'] ] ),
				'read_only'   => ! empty( $tool['annotations']['readOnlyHint'] ),
				'destructive' => ! empty( $tool['annotations']['destructiveHint'] ),
				'dry_run'     => ! empty( $tool['dry_run'] ),
				'operations'  => $operations,
			);
		}

		return $catalog;
	}

	public function is_exposed( string $name ): bool {
		return isset( $this->tools[ $name ] ) && ! in_array( $name, $this->disabled_tools(), true );
	}

	public function is_operation_exposed( string $tool_name, string $operation ): bool {
		if ( ! isset( $this->tools[ $tool_name ]['operations'][ $operation ] ) ) {
			return false;
		}
		$key    = $tool_name . ':' . $operation;
		$policy = $this->operation_policy();
		if ( array_key_exists( $key, $policy ) ) {
			return $policy[ $key ];
		}
		return ! empty( $this->tools[ $tool_name ]['operations'][ $operation ]['default_exposed'] );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function operation_catalog( string $tool_name, bool $only_exposed = false ): array {
		if ( ! isset( $this->tools[ $tool_name ] ) ) {
			return array();
		}
		$catalog = array();
		foreach ( $this->tools[ $tool_name ]['operations'] as $operation => $data ) {
			$exposed = $this->is_operation_exposed( $tool_name, $operation );
			if ( $only_exposed && ! $exposed ) {
				continue;
			}
			$catalog[] = array_merge( $data, array( 'name' => $operation, 'exposed' => $exposed ) );
		}
		return $catalog;
	}

	/**
	 * Persist the complete enabled set for currently registered tools.
	 *
	 * Disabled entries for temporarily unavailable conditional integrations are
	 * retained, while tools introduced by a future version default to exposed.
	 *
	 * @param array<int,mixed> $enabled_names Tool names submitted by an administrator.
	 * @return array{registered:int,exposed:int,disabled:int}
	 */
	public function update_exposure( array $enabled_names ): array {
		$registered = array_fill_keys( array_keys( $this->tools ), true );
		$enabled    = array();

		foreach ( $enabled_names as $name ) {
			if ( is_string( $name ) ) {
				$name = sanitize_key( $name );
				if ( isset( $registered[ $name ] ) ) {
					$enabled[ $name ] = true;
				}
			}
		}

		$disabled = array_values( array_diff( array_keys( $registered ), array_keys( $enabled ) ) );
		foreach ( $this->disabled_tools() as $name ) {
			if ( ! isset( $registered[ $name ] ) ) {
				$disabled[] = $name;
			}
		}
		$disabled = array_values( array_unique( $disabled ) );
		sort( $disabled, SORT_STRING );
		update_option( self::EXPOSURE_OPTION, $disabled, false );

		return array(
			'registered' => count( $registered ),
			'exposed'    => count( $enabled ),
			'disabled'   => count( $registered ) - count( $enabled ),
		);
	}

	/**
	 * Persist enabled operation keys in the form tool_name:operation_name.
	 *
	 * Policies for temporarily unavailable integrations are retained.
	 *
	 * @param array<int,mixed> $enabled_keys Submitted operation keys.
	 * @return array{registered:int,exposed:int,disabled:int}
	 */
	public function update_operation_exposure( array $enabled_keys ): array {
		$registered = array();
		foreach ( $this->tools as $tool_name => $tool ) {
			foreach ( array_keys( $tool['operations'] ) as $operation ) {
				$registered[ $tool_name . ':' . $operation ] = true;
			}
		}
		$enabled = array();
		foreach ( $enabled_keys as $key ) {
			if ( is_string( $key ) && isset( $registered[ $key ] ) ) {
				$enabled[ $key ] = true;
			}
		}
		$policy = $this->operation_policy();
		foreach ( array_keys( $registered ) as $key ) {
			$policy[ $key ] = isset( $enabled[ $key ] );
		}
		ksort( $policy, SORT_STRING );
		update_option( self::OPERATION_POLICY_OPTION, $policy, false );

		return array(
			'registered' => count( $registered ),
			'exposed'    => count( $enabled ),
			'disabled'   => count( $registered ) - count( $enabled ),
		);
	}

	/**
	 * Tools whose effects reach the filesystem, a package installer, or a remote
	 * service, and therefore survive a rolled-back database transaction.
	 *
	 * @return array<int,string>
	 */
	public static function unpreviewable_tools(): array {
		return (array) apply_filters(
			'mindio_magic_mcp_unpreviewable_tools',
			array(
				'upload_media',
				'delete_media',
				'install_plugin',
				'update_plugin',
				'delete_plugin',
				'install_theme',
				'update_theme',
				'delete_theme',
				'run_wp_cli',
				'clear_cache',
				'purge_cdn',
				'control_cache',
				'trigger_image_optimization',
			)
		);
	}

	/** @return array<int,string> */
	private function disabled_tools(): array {
		$value = get_option( self::EXPOSURE_OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$disabled = array();
		foreach ( $value as $name ) {
			if ( is_string( $name ) && preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
				$disabled[] = $name;
			}
		}

		return array_values( array_unique( $disabled ) );
	}

	/** @return array<string,array<string,mixed>> */
	private function normalize_operations( array $operations ): array {
		$normalized = array();
		foreach ( $operations as $name => $data ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) || ! is_array( $data ) ) {
				throw new \InvalidArgumentException( 'Invalid MCP operation metadata.' );
			}
			$mode = in_array( (string) ( $data['mode'] ?? 'read' ), array( 'read', 'write' ), true ) ? (string) ( $data['mode'] ?? 'read' ) : 'read';
			$normalized[ $name ] = array(
				'label'           => sanitize_text_field( (string) ( $data['label'] ?? $name ) ),
				'description'     => sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
				'mode'            => $mode,
				'destructive'     => ! empty( $data['destructive'] ),
				'default_exposed' => array_key_exists( 'default_exposed', $data ) ? (bool) $data['default_exposed'] : 'read' === $mode,
				'scope'           => in_array( (string) ( $data['scope'] ?? '' ), array( Auth::SCOPE_READ, Auth::SCOPE_EDITOR, Auth::SCOPE_ADMIN ), true )
					? (string) $data['scope']
					: ( 'read' === $mode ? Auth::SCOPE_READ : Auth::SCOPE_EDITOR ),
			);
		}
		return $normalized;
	}

	/** @return array<string,bool> */
	private function operation_policy(): array {
		$value = get_option( self::OPERATION_POLICY_OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$policy = array();
		foreach ( $value as $key => $enabled ) {
			if ( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_]{0,63}:[a-z][a-z0-9_]{0,63}$/', $key ) ) {
				$policy[ $key ] = (bool) $enabled;
			}
		}
		return $policy;
	}
}
