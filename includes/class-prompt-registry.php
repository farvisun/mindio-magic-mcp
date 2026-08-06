<?php
/**
 * MCP prompt registry for site-specific templates.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Prompt_Registry {
	/** @var array<string,array<string,mixed>> */
	private array $prompts = array();

	private Auth $auth;

	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * @param array<int,array{name:string,description:string,required?:bool}> $arguments
	 * @param callable                                                        $callback Receives sanitized arguments and returns messages.
	 */
	public function register(
		string $name,
		string $title,
		string $description,
		array $arguments,
		callable $callback,
		string $scope = Auth::SCOPE_READ,
		string $capability = 'read'
	): void {
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
			throw new \InvalidArgumentException( esc_html( 'Invalid MCP prompt name: ' . $name ) );
		}
		if ( isset( $this->prompts[ $name ] ) ) {
			throw new \LogicException( esc_html( 'Duplicate MCP prompt name: ' . $name ) );
		}

		$normalized = array();
		foreach ( $arguments as $argument ) {
			if ( ! is_array( $argument ) || empty( $argument['name'] ) || ! is_string( $argument['name'] ) ) {
				throw new \InvalidArgumentException( 'Invalid MCP prompt argument metadata.' );
			}
			$normalized[] = array(
				'name'        => $argument['name'],
				'description' => (string) ( $argument['description'] ?? '' ),
				'required'    => ! empty( $argument['required'] ),
			);
		}

		$this->prompts[ $name ] = array(
			'name'        => $name,
			'title'       => $title,
			'description' => $description,
			'arguments'   => $normalized,
			'callback'    => $callback,
			'scope'       => $scope,
			'capability'  => $capability,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function list_prompts(): array {
		$visible = array();
		foreach ( $this->prompts as $prompt ) {
			if ( ! $this->auth->scope_allows( $this->auth->current_scope(), (string) $prompt['scope'] ) ) {
				continue;
			}
			if ( ! current_user_can( (string) $prompt['capability'] ) ) {
				continue;
			}
			$visible[] = array(
				'name'        => $prompt['name'],
				'title'       => $prompt['title'],
				'description' => $prompt['description'],
				'arguments'   => $prompt['arguments'],
			);
		}

		return $visible;
	}

	/**
	 * Render one prompt into MCP messages.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get( string $name, array $arguments ) {
		$name = sanitize_key( $name );
		if ( ! isset( $this->prompts[ $name ] ) ) {
			return new \WP_Error( 'unknown_prompt', __( 'Unknown prompt.', 'mindio-magic-mcp' ) );
		}

		$prompt = $this->prompts[ $name ];
		if ( ! $this->auth->scope_allows( $this->auth->current_scope(), (string) $prompt['scope'] ) ) {
			return new \WP_Error( 'insufficient_scope', __( 'The access token does not grant this prompt scope.', 'mindio-magic-mcp' ) );
		}
		if ( ! current_user_can( (string) $prompt['capability'] ) ) {
			return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot use this prompt.', 'mindio-magic-mcp' ) );
		}

		$values = array();
		foreach ( $prompt['arguments'] as $argument ) {
			$key   = $argument['name'];
			$value = $arguments[ $key ] ?? '';
			if ( ! is_scalar( $value ) ) {
				return new \WP_Error( 'invalid_arguments', __( 'Prompt arguments must be scalar values.', 'mindio-magic-mcp' ) );
			}
			$value = sanitize_text_field( (string) $value );
			if ( '' === $value && $argument['required'] ) {
				return new \WP_Error(
					'invalid_arguments',
					sprintf(
						/* translators: %s: prompt argument name. */
						__( 'The prompt argument "%s" is required.', 'mindio-magic-mcp' ),
						$key
					)
				);
			}
			$values[ $key ] = mb_substr( $value, 0, 2000 );
		}

		try {
			$messages = call_user_func( $prompt['callback'], $values );
		} catch ( \Throwable $throwable ) {
			do_action( 'mindio_magic_mcp_prompt_exception', $throwable, $name, $values );
			return new \WP_Error( 'prompt_exception', __( 'The prompt failed unexpectedly.', 'mindio-magic-mcp' ) );
		}

		if ( is_wp_error( $messages ) ) {
			return $messages;
		}

		return array(
			'description' => $prompt['description'],
			'messages'    => $this->normalize_messages( (array) $messages ),
		);
	}

	public function has( string $name ): bool {
		return isset( $this->prompts[ sanitize_key( $name ) ] );
	}

	public function count(): int {
		return count( $this->prompts );
	}

	/**
	 * Accept either plain strings or full message arrays from prompt callbacks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_messages( array $messages ): array {
		$normalized = array();
		foreach ( $messages as $message ) {
			if ( is_string( $message ) ) {
				$message = array( 'role' => 'user', 'text' => $message );
			}
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role = in_array( (string) ( $message['role'] ?? 'user' ), array( 'user', 'assistant' ), true )
				? (string) $message['role']
				: 'user';
			$normalized[] = array(
				'role'    => $role,
				'content' => array(
					'type' => 'text',
					'text' => (string) ( $message['text'] ?? '' ),
				),
			);
		}

		return $normalized;
	}
}
