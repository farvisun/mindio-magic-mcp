<?php
/**
 * MCP Streamable HTTP transport over the WordPress REST API.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MCP_Server {
	private const PROTOCOLS = array( '2025-11-25', '2025-06-18', '2025-03-26' );
	private const MAX_REQUEST_BYTES = 25 * MB_IN_BYTES;

	private Tool_Registry $registry;
	private Auth $auth;
	private Rate_Limiter $rate_limiter;
	private Audit_Log $audit;
	private Resource_Registry $resources;
	private Prompt_Registry $prompts;

	public function __construct(
		Tool_Registry $registry,
		Auth $auth,
		Rate_Limiter $rate_limiter,
		Audit_Log $audit,
		Resource_Registry $resources,
		Prompt_Registry $prompts
	) {
		$this->registry     = $registry;
		$this->auth         = $auth;
		$this->rate_limiter = $rate_limiter;
		$this->audit        = $audit;
		$this->resources    = $resources;
		$this->prompts      = $prompts;
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		$routes = array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => array( 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_unsupported_transport_method' ),
				'permission_callback' => '__return_true',
			),
		);

		register_rest_route( MINDIO_MAGIC_MCP_REST_NAMESPACE, '/mcp', $routes );

		// Deprecated pre-rename namespace, kept so already-configured MCP clients keep working.
		register_rest_route( MINDIO_MAGIC_MCP_LEGACY_REST_NAMESPACE, '/mcp', $routes );
	}

	public static function protocol_version(): string {
		return self::PROTOCOLS[0];
	}

	public function handle_unsupported_transport_method( \WP_REST_Request $request ): \WP_REST_Response {
		$origin = $this->validate_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $this->transport_error( $origin->get_error_message(), 403, -32003 );
		}
		$auth = $this->auth->authenticate_request( $request );
		if ( is_wp_error( $auth ) ) {
			return $this->unauthorized( $auth );
		}

		if ( 'GET' === $request->get_method() && $this->wants_stream( $request ) ) {
			$this->stream_keepalive();
			return new \WP_REST_Response( null, 200 );
		}

		$response = $this->transport_error( __( 'This stateless server has no server-initiated messages. Open a stream by sending Accept: text/event-stream on a POST instead.', 'mindio-magic-mcp' ), 405, -32005 );
		$response->header( 'Allow', 'POST, GET' );
		return $response;
	}

	public function handle_post( \WP_REST_Request $request ): \WP_REST_Response {
		if ( strlen( $request->get_body() ) > self::MAX_REQUEST_BYTES ) {
			return $this->transport_error( __( 'The MCP request body exceeds the 25 MB transport limit.', 'mindio-magic-mcp' ), 413, -32013 );
		}
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		if ( isset( $settings['enabled'] ) && ! $settings['enabled'] ) {
			return $this->transport_error( __( 'The MCP endpoint is disabled by an administrator.', 'mindio-magic-mcp' ), 503, -32004 );
		}

		$origin = $this->validate_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $this->transport_error( $origin->get_error_message(), 403, -32003 );
		}

		$auth = $this->auth->authenticate_request( $request );
		if ( is_wp_error( $auth ) ) {
			return $this->unauthorized( $auth );
		}

		$rate = $this->rate_limiter->consume( $this->auth->current_identity(), 'mcp' );
		if ( ! $rate['allowed'] ) {
			$response = $this->transport_error( __( 'Rate limit exceeded.', 'mindio-magic-mcp' ), 429, -32029 );
			$response->header( 'Retry-After', (string) $rate['retry_after'] );
			$response->header( 'X-RateLimit-Limit', (string) $rate['limit'] );
			$response->header( 'X-RateLimit-Remaining', '0' );
			return $response;
		}

		$protocol_header = trim( (string) $request->get_header( 'mcp-protocol-version' ) );
		if ( '' !== $protocol_header && ! in_array( $protocol_header, self::PROTOCOLS, true ) ) {
			return $this->transport_error( __( 'Unsupported MCP-Protocol-Version header.', 'mindio-magic-mcp' ), 400, -32602 );
		}

		$message = $request->get_json_params();
		if ( ! is_array( $message ) || $this->is_list( $message ) ) {
			return $this->transport_error( __( 'The request body must be one JSON-RPC object.', 'mindio-magic-mcp' ), 400, -32700 );
		}
		if ( '2.0' !== ( $message['jsonrpc'] ?? null ) || empty( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return $this->transport_error( __( 'Invalid JSON-RPC 2.0 request.', 'mindio-magic-mcp' ), 400, -32600 );
		}

		$is_notification = ! array_key_exists( 'id', $message );
		if ( $is_notification ) {
			if ( in_array( $message['method'], array( 'notifications/initialized', 'notifications/cancelled', 'notifications/progress' ), true ) ) {
				return $this->notification_accepted();
			}
			return $this->notification_accepted();
		}

		$id     = $message['id'];
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		if ( 'tools/call' === $message['method'] && $this->wants_stream( $request ) ) {
			$this->stream_tool_call( $id, $params, new SSE_Stream(), true );
			return new \WP_REST_Response( null, 200 );
		}

		$result = match ( $message['method'] ) {
			'initialize'              => $this->initialize( $params ),
			'ping'                    => array(),
			'tools/list'              => array( 'tools' => $this->registry->list_tools() ),
			'tools/call'              => $this->call_tool( $params ),
			'resources/list'          => array( 'resources' => $this->resources->list_resources() ),
			'resources/templates/list' => array( 'resourceTemplates' => $this->resources->list_templates() ),
			'resources/read'          => $this->read_resource( $params ),
			'prompts/list'            => array( 'prompts' => $this->prompts->list_prompts() ),
			'prompts/get'             => $this->get_prompt( $params ),
			default                   => new \WP_Error( 'method_not_found', __( 'JSON-RPC method not found.', 'mindio-magic-mcp' ) ),
		};

		if ( is_wp_error( $result ) ) {
			$error = array( 'code' => $this->error_code( $result ), 'message' => $result->get_error_message() );
			$data  = $result->get_error_data();
			if ( is_array( $data ) && $data ) {
				$error['data'] = $data;
			}
			return $this->json_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => $error ) );
		}

		return $this->json_response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function initialize( array $params ): array|\WP_Error {
		$requested = (string) ( $params['protocolVersion'] ?? '' );
		if ( '' === $requested ) {
			return new \WP_Error( 'invalid_arguments', __( 'initialize requires protocolVersion.', 'mindio-magic-mcp' ) );
		}
		$negotiated = in_array( $requested, self::PROTOCOLS, true ) ? $requested : self::PROTOCOLS[0];
		$locale     = determine_locale();

		return array(
			'protocolVersion' => $negotiated,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false, 'subscribe' => false ),
				'prompts'   => array( 'listChanged' => false ),
				'logging'   => new \stdClass(),
			),
			'serverInfo'      => array(
				'name'        => 'mindio-magic-mcp',
				'title'       => 'Mindio Magic MCP',
				'version'     => MINDIO_MAGIC_MCP_VERSION,
				'description' => __( 'Secure WordPress and Flatsome UX Builder automation.', 'mindio-magic-mcp' ),
				'websiteUrl'  => home_url( '/' ),
			),
			'instructions'    => sprintf(
				/* translators: 1: site URL, 2: locale. */
				__( 'Operate %1$s carefully. Prefer drafts, use list/read tools before writes, and honor the site locale (%2$s). Destructive calls require explicit confirmation.', 'mindio-magic-mcp' ),
				home_url( '/' ),
				$locale
			),
		);
	}

	/**
	 * Whether the client asked for a server-sent event stream.
	 */
	private function wants_stream( \WP_REST_Request $request ): bool {
		return str_contains( strtolower( (string) $request->get_header( 'accept' ) ), 'text/event-stream' );
	}

	/**
	 * Run one tool call, emitting progress notifications as they are reported and
	 * the JSON-RPC response as the final event.
	 *
	 * Exposed for tests through an injectable stream; `$terminate` is false there
	 * so the process is not taken over.
	 *
	 * @param array<string,mixed> $params
	 */
	public function stream_tool_call( mixed $id, array $params, SSE_Stream $stream, bool $terminate = false ): void {
		if ( $terminate ) {
			SSE_Stream::disable_buffering();
			SSE_Stream::send_headers();
		}

		$token = (string) ( $params['_meta']['progressToken'] ?? '' );
		if ( '' !== $token ) {
			Progress_Reporter::listen(
				$token,
				static function ( float $progress, ?float $total, string $message ) use ( $stream, $token ): void {
					$payload = array( 'progressToken' => $token, 'progress' => $progress );
					if ( null !== $total ) {
						$payload['total'] = $total;
					}
					if ( '' !== $message ) {
						$payload['message'] = $message;
					}
					$stream->send(
						array(
							'jsonrpc' => '2.0',
							'method'  => 'notifications/progress',
							'params'  => $payload,
						)
					);
				}
			);
		}

		try {
			$result = $this->call_tool( $params );
		} finally {
			Progress_Reporter::stop();
		}

		if ( is_wp_error( $result ) ) {
			$stream->send(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array( 'code' => $this->error_code( $result ), 'message' => $result->get_error_message() ),
				)
			);
		} else {
			$stream->send( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ) );
		}

		if ( $terminate ) {
			exit;
		}
	}

	/**
	 * Hold a GET stream open briefly so clients that expect one can connect.
	 *
	 * This server is stateless and never initiates messages, so the stream only
	 * carries keep-alive comments and then closes.
	 */
	private function stream_keepalive(): void {
		SSE_Stream::disable_buffering();
		SSE_Stream::send_headers();

		$stream = new SSE_Stream();
		$stream->comment( 'mindio-magic-mcp: no server-initiated messages' );

		exit;
	}

	private function error_code( \WP_Error $error ): int {
		return match ( $error->get_error_code() ) {
			'method_not_found' => -32601,
			'unknown_tool', 'invalid_arguments', 'invalid_resource_uri' => -32602,
			'unknown_resource', 'unknown_prompt' => -32002,
			'forbidden', 'insufficient_scope' => -32003,
			default => -32603,
		};
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_resource( array $params ): array|\WP_Error {
		$uri = isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
		if ( '' === $uri ) {
			return new \WP_Error( 'invalid_arguments', __( 'resources/read requires a uri.', 'mindio-magic-mcp' ) );
		}

		$start  = hrtime( true );
		$result = $this->resources->read( $uri );
		$ms     = (int) round( ( hrtime( true ) - $start ) / 1000000 );
		$this->audit->write(
			'resources_read',
			array( 'uri' => $uri ),
			! is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() : '',
			$ms,
			$this->auth
		);

		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function get_prompt( array $params ): array|\WP_Error {
		$name      = isset( $params['name'] ) ? sanitize_key( (string) $params['name'] ) : '';
		$arguments = $params['arguments'] ?? array();
		if ( '' === $name || ! is_array( $arguments ) ) {
			return new \WP_Error( 'invalid_arguments', __( 'prompts/get requires a prompt name and object arguments.', 'mindio-magic-mcp' ) );
		}

		$start  = hrtime( true );
		$result = $this->prompts->get( $name, $arguments );
		$ms     = (int) round( ( hrtime( true ) - $start ) / 1000000 );
		$this->audit->write(
			'prompts_get',
			array( 'name' => $name ),
			! is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() : '',
			$ms,
			$this->auth
		);

		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function call_tool( array $params ): array|\WP_Error {
		$name      = isset( $params['name'] ) ? sanitize_key( (string) $params['name'] ) : '';
		$arguments = $params['arguments'] ?? array();
		if ( '' === $name || ! is_array( $arguments ) ) {
			return new \WP_Error( 'invalid_arguments', __( 'tools/call requires a tool name and object arguments.', 'mindio-magic-mcp' ) );
		}
		if ( ! $this->registry->has( $name ) ) {
			return new \WP_Error(
				'unknown_tool',
				sprintf(
					/* translators: %s: MCP tool name. */
					__( 'Unknown tool: %s.', 'mindio-magic-mcp' ),
					$name
				)
			);
		}

		$start  = hrtime( true );
		$result = $this->registry->call( $name, $arguments );
		$ms     = (int) round( ( hrtime( true ) - $start ) / 1000000 );
		$this->audit->write( $name, $arguments, ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() : '', $ms, $this->auth );

		if ( is_wp_error( $result ) ) {
			$structured = array(
				'error'   => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
			return array(
				'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
				'structuredContent' => $structured,
				'isError'           => true,
			);
		}

		return array(
			'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
			'structuredContent' => $result,
			'isError'           => false,
		);
	}

	/** @return true|\WP_Error */
	private function validate_origin( \WP_REST_Request $request ): bool|\WP_Error {
		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' === $origin ) {
			return true;
		}

		$allowed  = array( $this->origin_of( home_url( '/' ) ), $this->origin_of( rest_url() ) );
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		foreach ( (array) ( $settings['allowed_origins'] ?? array() ) as $configured ) {
			$normalized = $this->origin_of( (string) $configured );
			if ( '' !== $normalized ) {
				$allowed[] = $normalized;
			}
		}
		if ( ! in_array( $this->origin_of( $origin ), array_unique( $allowed ), true ) ) {
			return new \WP_Error( 'invalid_origin', __( 'The Origin header is not allowed for this MCP server.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	private function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function unauthorized( \WP_Error $error ): \WP_REST_Response {
		$response = $this->transport_error( $error->get_error_message(), 401, -32001 );
		$response->header(
			'WWW-Authenticate',
			'Bearer resource_metadata="' . esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) . '", scope="read_only"'
		);
		return $response;
	}

	private function notification_accepted(): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 202 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private function transport_error( string $message, int $status, int $code ): \WP_REST_Response {
		return $this->json_response( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => $code, 'message' => $message ) ), $status );
	}

	private function json_response( array $body, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'MCP-Protocol-Version', self::PROTOCOLS[0] );
		return $response;
	}
}
