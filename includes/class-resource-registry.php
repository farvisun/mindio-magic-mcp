<?php
/**
 * MCP resource registry for direct and templated URIs.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Resource_Registry {
	public const URI_SCHEME = 'mindio';

	/** @var array<string,array<string,mixed>> */
	private array $resources = array();

	/** @var array<string,array<string,mixed>> */
	private array $templates = array();

	private Auth $auth;

	public function __construct( Auth $auth ) {
		$this->auth = $auth;
	}

	/**
	 * Register a resource addressable by one fixed URI.
	 *
	 * @param callable $callback Returns a string, or an array encoded as JSON.
	 */
	public function register(
		string $uri,
		string $name,
		string $title,
		string $description,
		string $mime_type,
		callable $callback,
		string $scope = Auth::SCOPE_READ,
		string $capability = 'read'
	): void {
		$this->assert_uri( $uri );
		if ( isset( $this->resources[ $uri ] ) ) {
			throw new \LogicException( esc_html( 'Duplicate MCP resource URI: ' . $uri ) );
		}

		$this->resources[ $uri ] = array(
			'uri'         => $uri,
			'name'        => $name,
			'title'       => $title,
			'description' => $description,
			'mimeType'    => $mime_type,
			'callback'    => $callback,
			'scope'       => $scope,
			'capability'  => $capability,
		);
	}

	/**
	 * Register a family of resources addressable by an RFC 6570 level-1 URI template.
	 *
	 * @param string   $uri_template Template such as mindio://post/{id}.
	 * @param callable $callback     Receives the extracted variables keyed by name.
	 */
	public function register_template(
		string $uri_template,
		string $name,
		string $title,
		string $description,
		string $mime_type,
		callable $callback,
		string $scope = Auth::SCOPE_READ,
		string $capability = 'read'
	): void {
		$this->assert_uri( $uri_template );
		if ( isset( $this->templates[ $uri_template ] ) ) {
			throw new \LogicException( esc_html( 'Duplicate MCP resource template: ' . $uri_template ) );
		}

		$this->templates[ $uri_template ] = array(
			'uriTemplate' => $uri_template,
			'name'        => $name,
			'title'       => $title,
			'description' => $description,
			'mimeType'    => $mime_type,
			'pattern'     => $this->template_pattern( $uri_template ),
			'variables'   => $this->template_variables( $uri_template ),
			'callback'    => $callback,
			'scope'       => $scope,
			'capability'  => $capability,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function list_resources(): array {
		$visible = array();
		foreach ( $this->resources as $resource ) {
			if ( ! $this->is_permitted( $resource ) ) {
				continue;
			}
			$visible[] = array(
				'uri'         => $resource['uri'],
				'name'        => $resource['name'],
				'title'       => $resource['title'],
				'description' => $resource['description'],
				'mimeType'    => $resource['mimeType'],
			);
		}

		return $visible;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_templates(): array {
		$visible = array();
		foreach ( $this->templates as $template ) {
			if ( ! $this->is_permitted( $template ) ) {
				continue;
			}
			$visible[] = array(
				'uriTemplate' => $template['uriTemplate'],
				'name'        => $template['name'],
				'title'       => $template['title'],
				'description' => $template['description'],
				'mimeType'    => $template['mimeType'],
			);
		}

		return $visible;
	}

	/**
	 * Resolve one resource URI to MCP contents.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read( string $uri ) {
		$uri = trim( $uri );
		if ( '' === $uri || strlen( $uri ) > 2000 ) {
			return new \WP_Error( 'invalid_resource_uri', __( 'The resource URI is missing or too long.', 'mindio-magic-mcp' ) );
		}

		$match = $this->resolve( $uri );
		if ( is_wp_error( $match ) ) {
			return $match;
		}

		$definition = $match['definition'];
		if ( ! $this->auth->scope_allows( $this->auth->current_scope(), $definition['scope'] ) ) {
			return new \WP_Error( 'insufficient_scope', __( 'The access token does not grant this resource scope.', 'mindio-magic-mcp' ) );
		}
		if ( ! current_user_can( $definition['capability'] ) ) {
			return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot read this resource.', 'mindio-magic-mcp' ) );
		}

		try {
			$payload = call_user_func( $definition['callback'], $match['variables'] );
		} catch ( \Throwable $throwable ) {
			do_action( 'mindio_magic_mcp_resource_exception', $throwable, $uri );
			return new \WP_Error( 'resource_exception', __( 'The resource failed unexpectedly.', 'mindio-magic-mcp' ) );
		}

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$text = is_string( $payload )
			? $payload
			: (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'name'     => $definition['name'],
					'title'    => $definition['title'],
					'mimeType' => $definition['mimeType'],
					'text'     => $text,
				),
			),
		);
	}

	public function has( string $uri ): bool {
		return ! is_wp_error( $this->resolve( $uri ) );
	}

	public function count(): int {
		return count( $this->resources ) + count( $this->templates );
	}

	/** @return array{definition:array<string,mixed>,variables:array<string,string>}|\WP_Error */
	private function resolve( string $uri ) {
		if ( isset( $this->resources[ $uri ] ) ) {
			return array( 'definition' => $this->resources[ $uri ], 'variables' => array() );
		}

		foreach ( $this->templates as $template ) {
			if ( preg_match( $template['pattern'], $uri, $matches ) ) {
				$variables = array();
				foreach ( $template['variables'] as $variable ) {
					$variables[ $variable ] = isset( $matches[ $variable ] ) ? rawurldecode( (string) $matches[ $variable ] ) : '';
				}
				return array( 'definition' => $template, 'variables' => $variables );
			}
		}

		return new \WP_Error( 'unknown_resource', __( 'Unknown resource URI.', 'mindio-magic-mcp' ) );
	}

	/** @param array<string,mixed> $definition */
	private function is_permitted( array $definition ): bool {
		return $this->auth->scope_allows( $this->auth->current_scope(), (string) $definition['scope'] )
			&& current_user_can( (string) $definition['capability'] );
	}

	private function assert_uri( string $uri ): void {
		if ( ! str_starts_with( $uri, self::URI_SCHEME . '://' ) ) {
			throw new \InvalidArgumentException( esc_html( 'MCP resource URIs must use the ' . self::URI_SCHEME . ':// scheme: ' . $uri ) );
		}
	}

	/**
	 * Compile a level-1 URI template into a named-group regular expression.
	 */
	private function template_pattern( string $uri_template ): string {
		$pattern = '';
		$offset  = 0;
		while ( preg_match( '/\{([a-z][a-z0-9_]*)\}/', $uri_template, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$literal  = substr( $uri_template, $offset, $matches[0][1] - $offset );
			$pattern .= preg_quote( $literal, '#' );
			$pattern .= '(?P<' . $matches[1][0] . '>[^/]+)';
			$offset   = $matches[0][1] + strlen( $matches[0][0] );
		}
		$pattern .= preg_quote( substr( $uri_template, $offset ), '#' );

		return '#^' . $pattern . '$#';
	}

	/** @return array<int,string> */
	private function template_variables( string $uri_template ): array {
		preg_match_all( '/\{([a-z][a-z0-9_]*)\}/', $uri_template, $matches );

		return array_values( (array) $matches[1] );
	}
}
