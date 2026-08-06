<?php
/**
 * SSE transport and progress notification coverage.
 * Run with WP_PATH=/path/to/wordpress php tests/integration/streaming.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\SSE_Stream' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the streaming test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_sse_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Parse raw SSE frames into decoded JSON-RPC messages.
 *
 * @return array<int,array<string,mixed>>
 */
function mindio_sse_parse( string $raw ): array {
	$messages = array();
	foreach ( explode( "\n\n", trim( $raw ) ) as $frame ) {
		if ( ! preg_match( '/^data: (.*)$/m', $frame, $match ) ) {
			continue;
		}
		$decoded = json_decode( (string) $match[1], true );
		if ( is_array( $decoded ) ) {
			$messages[] = $decoded;
		}
	}

	return $messages;
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_sse_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Streaming coverage' );
mindio_sse_assert( ! is_wp_error( $credential ), 'Could not create the streaming credential.' );

$created = array();

try {
	// The streamed path is driven directly, so authenticate the credential first
	// the way handle_post() would before dispatching a tool call.
	$authenticating = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$authenticating->set_header( 'Authorization', 'Bearer ' . (string) $credential['token'] );
	mindio_sse_assert( true === $auth->authenticate_request( $authenticating ), 'Could not authenticate the streaming credential.' );

	$registry = new \MindioMagicMCP\Tool_Registry( $auth );
	$builders = new \MindioMagicMCP\Page_Builder_Registry();
	$catalog  = new \MindioMagicMCP\Flatsome_Component_Catalog();
	$builders->add( new \MindioMagicMCP\Flatsome_Builder( new \MindioMagicMCP\Flatsome_Renderer( $catalog ), $catalog ) );
	$builders->add( new \MindioMagicMCP\Gutenberg_Builder() );
	( new \MindioMagicMCP\Builder_Tools( $registry, $builders ) )->register();

	$server = new \MindioMagicMCP\MCP_Server(
		$registry,
		$auth,
		new \MindioMagicMCP\Rate_Limiter(),
		new \MindioMagicMCP\Audit_Log(),
		new \MindioMagicMCP\Resource_Registry( $auth ),
		new \MindioMagicMCP\Prompt_Registry( $auth )
	);

	$captured = '';
	$stream   = new \MindioMagicMCP\SSE_Stream(
		static function ( string $frame ) use ( &$captured ): void {
			$captured .= $frame;
		}
	);

	$blueprint = array(
		'sections' => array(
			array(
				'label' => 'One',
				'rows'  => array( array( 'columns' => array( array( 'span' => 12, 'elements' => array( array( 'type' => 'heading', 'text' => 'Streamed', 'level' => 2 ) ) ) ) ) ),
			),
			array(
				'label' => 'Two',
				'rows'  => array( array( 'columns' => array( array( 'span' => 12, 'elements' => array( array( 'type' => 'text', 'content' => '<p>Body.</p>' ) ) ) ) ) ),
			),
		),
	);

	$server->stream_tool_call(
		77,
		array(
			'name'      => 'create_builder_page',
			'arguments' => array( 'title' => 'Streamed blueprint', 'blueprint' => $blueprint ),
			'_meta'     => array( 'progressToken' => 'tok-1' ),
		),
		$stream
	);

	$messages = mindio_sse_parse( $captured );
	mindio_sse_assert( count( $messages ) >= 2, 'The stream produced fewer than two events.' );

	$progress = array_values(
		array_filter( $messages, static fn( array $m ): bool => 'notifications/progress' === ( $m['method'] ?? '' ) )
	);
	mindio_sse_assert( ! empty( $progress ), 'The stream emitted no progress notifications.' );
	mindio_sse_assert( 'tok-1' === $progress[0]['params']['progressToken'], 'A progress notification carried the wrong token.' );
	mindio_sse_assert( isset( $progress[0]['params']['total'] ), 'A progress notification omitted the total.' );

	$final = end( $messages );
	mindio_sse_assert( 77 === ( $final['id'] ?? null ), 'The final event is not the JSON-RPC response.' );
	mindio_sse_assert( empty( $final['result']['isError'] ), 'The streamed call reported an error.' );

	$structured = (array) $final['result']['structuredContent'];
	$created[]  = (int) $structured['post_id'];
	mindio_sse_assert( get_post( (int) $structured['post_id'] ) instanceof WP_Post, 'The streamed call did not create the page.' );

	mindio_sse_assert(
		str_contains( $captured, 'event: message' ),
		'The stream frames are missing the SSE event name.'
	);
	mindio_sse_assert( str_contains( $captured, 'id: 1' ), 'The stream frames are missing event IDs.' );

	// Without a progress token the call still succeeds and emits only the response.
	$quiet_capture = '';
	$quiet_stream  = new \MindioMagicMCP\SSE_Stream(
		static function ( string $frame ) use ( &$quiet_capture ): void {
			$quiet_capture .= $frame;
		}
	);
	$server->stream_tool_call(
		78,
		array( 'name' => 'create_builder_page', 'arguments' => array( 'title' => 'Unstreamed blueprint', 'blueprint' => $blueprint ) ),
		$quiet_stream
	);
	$quiet_messages = mindio_sse_parse( $quiet_capture );
	mindio_sse_assert( 1 === count( $quiet_messages ), 'A call without a progress token still emitted notifications.' );
	$created[] = (int) $quiet_messages[0]['result']['structuredContent']['post_id'];

	mindio_sse_assert(
		! \MindioMagicMCP\Progress_Reporter::is_active(),
		'The progress listener was not detached after the stream finished.'
	);

	$plain = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$plain->set_header( 'Authorization', 'Bearer ' . (string) $credential['token'] );
	$plain->set_header( 'Content-Type', 'application/json' );
	$plain->set_header( 'Accept', 'application/json' );
	$plain->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18' ) ) ) );
	$initialize = rest_get_server()->dispatch( $plain )->get_data();
	mindio_sse_assert( isset( $initialize['result']['capabilities']['logging'] ), 'initialize does not advertise the logging capability.' );

	$get = new WP_REST_Request( 'GET', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$get->set_header( 'Authorization', 'Bearer ' . (string) $credential['token'] );
	$get->set_header( 'Accept', 'application/json' );
	$get_response = rest_get_server()->dispatch( $get );
	mindio_sse_assert( 405 === $get_response->get_status(), 'A non-streaming GET did not return 405.' );
	mindio_sse_assert(
		str_contains( (string) $get_response->get_headers()['Allow'], 'GET' ),
		'The 405 response does not advertise GET for streaming.'
	);

	echo wp_json_encode(
		array(
			'ok'                 => true,
			'events'             => count( $messages ),
			'progress_events'    => count( $progress ),
			'quiet_events'       => count( $quiet_messages ),
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	foreach ( $created as $post_id ) {
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
	$auth->revoke_token( (string) $credential['id'] );
}
