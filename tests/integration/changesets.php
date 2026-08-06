<?php
/**
 * Changeset journal and revert coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/changesets.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Changeset' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the changeset test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_cs_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_cs_call( string $token, string $tool, array $arguments = array() ): array {
	$request = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => $tool, 'arguments' => $arguments ),
			)
		)
	);
	$response = rest_get_server()->dispatch( $request );
	mindio_cs_assert( 200 === $response->get_status(), $tool . ' returned HTTP ' . $response->get_status() );
	$result = (array) ( $response->get_data()['result'] ?? array() );
	mindio_cs_assert( empty( $result['isError'] ), $tool . ' failed: ' . wp_json_encode( $result['structuredContent'] ?? array() ) );

	return (array) $result['structuredContent'];
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_cs_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Changeset coverage' );
mindio_cs_assert( ! is_wp_error( $credential ), 'Could not create the changeset credential.' );
$token = (string) $credential['token'];

$post_id = 0;

try {
	$post_id = wp_insert_post(
		array(
			'post_title'   => 'Changeset fixture',
			'post_content' => 'Original body.',
			'post_excerpt' => 'Original excerpt.',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		),
		true
	);
	mindio_cs_assert( ! is_wp_error( $post_id ), 'Could not create the changeset fixture post.' );
	$post_id = (int) $post_id;
	wp_set_object_terms( $post_id, array( 'uncategorized' ), 'category', false );

	$changeset = mindio_cs_call( $token, 'begin_changeset', array( 'label' => 'Rewrite fixture copy' ) );
	$changeset_id = (string) $changeset['changeset_id'];
	mindio_cs_assert( str_starts_with( $changeset_id, 'cs_' ), 'begin_changeset returned an unexpected ID.' );
	mindio_cs_assert( 'open' === $changeset['status'], 'A new changeset is not open.' );

	mindio_cs_call(
		$token,
		'update_post',
		array( 'post_id' => $post_id, 'title' => 'Journalled title', 'content' => 'Journalled body.', 'changeset' => $changeset_id )
	);
	mindio_cs_call(
		$token,
		'update_post',
		array( 'post_id' => $post_id, 'excerpt' => 'Journalled excerpt.', 'changeset' => $changeset_id )
	);

	clean_post_cache( $post_id );
	mindio_cs_assert( 'Journalled title' === get_post( $post_id )->post_title, 'The journalled write did not apply.' );

	$detail = mindio_cs_call( $token, 'get_changeset', array( 'changeset_id' => $changeset_id ) );
	mindio_cs_assert( count( (array) $detail['entries'] ) >= 2, 'The changeset recorded fewer entries than expected.' );
	$first = (array) $detail['entries'][0];
	mindio_cs_assert( 'post' === $first['kind'], 'The first journal entry is not a post change.' );
	mindio_cs_assert(
		'Changeset fixture' === $first['before_state']['post_title'],
		'The journal recorded the wrong previous title.'
	);

	$listed = mindio_cs_call( $token, 'list_changesets', array( 'status' => 'open' ) );
	$ids    = wp_list_pluck( (array) $listed['changesets'], 'changeset_id' );
	mindio_cs_assert( in_array( $changeset_id, $ids, true ), 'The open changeset is missing from the list.' );

	mindio_cs_call( $token, 'close_changeset', array( 'changeset_id' => $changeset_id ) );

	$rejected = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$rejected->set_header( 'Authorization', 'Bearer ' . $token );
	$rejected->set_header( 'Content-Type', 'application/json' );
	$rejected->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'update_post',
					'arguments' => array( 'post_id' => $post_id, 'title' => 'Should not apply', 'changeset' => $changeset_id ),
				),
			)
		)
	);
	$rejected_result = (array) rest_get_server()->dispatch( $rejected )->get_data()['result'];
	mindio_cs_assert( ! empty( $rejected_result['isError'] ), 'A closed changeset still accepted writes.' );

	$reverted = mindio_cs_call( $token, 'revert_changeset', array( 'changeset_id' => $changeset_id, 'confirm' => true ) );
	mindio_cs_assert( 'reverted' === $reverted['status'], 'The changeset was not marked reverted.' );
	mindio_cs_assert( $reverted['reverted'] > 0, 'The revert restored nothing.' );

	clean_post_cache( $post_id );
	$restored = get_post( $post_id );
	mindio_cs_assert( 'Changeset fixture' === $restored->post_title, 'The revert did not restore the title.' );
	mindio_cs_assert( 'Original body.' === $restored->post_content, 'The revert did not restore the content.' );
	mindio_cs_assert( 'Original excerpt.' === $restored->post_excerpt, 'The revert did not restore the excerpt.' );

	$twice = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$twice->set_header( 'Authorization', 'Bearer ' . $token );
	$twice->set_header( 'Content-Type', 'application/json' );
	$twice->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'revert_changeset', 'arguments' => array( 'changeset_id' => $changeset_id, 'confirm' => true ) ),
			)
		)
	);
	$twice_result = (array) rest_get_server()->dispatch( $twice )->get_data()['result'];
	mindio_cs_assert( ! empty( $twice_result['isError'] ), 'Reverting the same changeset twice was allowed.' );

	echo wp_json_encode(
		array(
			'ok'              => true,
			'entries'         => count( (array) $detail['entries'] ),
			'reverted'        => (int) $reverted['reverted'],
			'closed_enforced' => true,
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$auth->revoke_token( (string) $credential['id'] );
}
