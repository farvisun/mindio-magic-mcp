<?php
/**
 * Regression for the Subscriber+ sensitive data exposure report: password-protected
 * post bodies, other users' approval requests, and other users' changesets must not
 * be readable by low-privilege MCP callers.
 *
 * Run with WP_PATH=/path/to/wordpress php tests/integration/protected-content.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Post_Access' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running this test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_pc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Dispatch one JSON-RPC call. An empty token sends no credential, so the request
 * relies on the WordPress identity already set (the Application Password path).
 *
 * @return array<string,mixed>
 */
function mindio_pc_rpc( string $token, string $method, array $params = array() ): array {
	$request = new WP_REST_Request( 'POST', '/mindio-magic-mcp/v1/mcp' );
	if ( '' !== $token ) {
		$request->set_header( 'Authorization', 'Bearer ' . $token );
	}
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Accept', 'application/json' );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ) );
	$response = rest_get_server()->dispatch( $request );
	mindio_pc_assert( 200 === $response->get_status(), 'Unexpected HTTP status: ' . $response->get_status() );
	return (array) $response->get_data();
}

/** @return array<string,mixed> */
function mindio_pc_tool( string $token, string $tool, array $arguments = array() ): array {
	return mindio_pc_rpc( $token, 'tools/call', array( 'name' => $tool, 'arguments' => $arguments ) );
}

function mindio_pc_denied( array $response ): bool {
	return ! empty( $response['error'] ) || ! empty( $response['result']['isError'] );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
mindio_pc_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$admin_id = (int) $admins[0];
wp_set_current_user( $admin_id );

$marker        = 'MINDIO-PROTECTED-' . wp_generate_password( 12, false );
$auth          = new \MindioMagicMCP\Auth();
$subscriber_id = 0;
$contributor_id = 0;
$post_ids      = array();
$token_ids     = array();
$approval_id   = '';
$changeset_id  = '';
$original_disabled_tools = get_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, null );
update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, array(), false );

try {
	$subscriber_id = (int) wp_insert_user(
		array(
			'user_login' => 'mindio_pc_sub_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'mindio-pc-sub-' . wp_generate_password( 6, false ) . '@example.invalid',
			'role'       => 'subscriber',
		)
	);
	$contributor_id = (int) wp_insert_user(
		array(
			'user_login' => 'mindio_pc_con_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'mindio-pc-con-' . wp_generate_password( 6, false ) . '@example.invalid',
			'role'       => 'contributor',
		)
	);
	mindio_pc_assert( $subscriber_id > 0 && $contributor_id > 0, 'Could not create fixture users.' );

	$protected_id = (int) wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => 'Protected fixture',
			'post_content'  => '<!-- wp:paragraph --><p>' . $marker . '</p><!-- /wp:paragraph -->',
			'post_excerpt'  => $marker . ' excerpt',
			'post_password' => 'lab-password',
		)
	);
	$public_id = (int) wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Public fixture',
			'post_content' => 'Public body ' . wp_generate_password( 8, false ),
		)
	);
	$post_ids = array( $protected_id, $public_id );
	mindio_pc_assert( $protected_id > 0 && $public_id > 0, 'Could not create fixture posts.' );

	// A second save leaves a revision that carries the protected body but no password.
	wp_update_post( array( 'ID' => $protected_id, 'post_content' => '<p>' . $marker . ' v2</p>' ) );
	$revision_ids = array_values( wp_get_post_revisions( $protected_id, array( 'fields' => 'ids' ) ) );
	mindio_pc_assert( ! empty( $revision_ids ), 'The protected fixture has no revision.' );

	// Published record of a non-public post type (like a form configuration or coupon).
	register_post_type( 'mindio_pc_hidden', array( 'public' => false, 'show_ui' => false ) );
	$hidden_id  = (int) wp_insert_post(
		array(
			'post_type'    => 'mindio_pc_hidden',
			'post_status'  => 'publish',
			'post_title'   => 'Hidden fixture',
			'post_content' => $marker . ' hidden',
		)
	);
	$post_ids[] = $hidden_id;

	// Another author's draft, which a Contributor must not detect through search totals.
	$draft_id   = (int) wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_author'  => $admin_id,
			'post_title'   => 'Admin draft',
			'post_content' => $marker . ' draft',
		)
	);
	$post_ids[] = $draft_id;

	// Raw meta that is not an ACF field must not be readable through the ACF reader.
	update_post_meta( $public_id, '_mindio_pc_secret', $marker );
	update_post_meta( $public_id, 'mindio_pc_plain', $marker );

	// Admin-owned approval request and changeset that must stay private.
	$admin_key = $auth->create_api_key( $admin_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Protected content admin' );
	mindio_pc_assert( ! is_wp_error( $admin_key ), 'Could not create admin credential.' );
	$token_ids[] = (string) $admin_key['id'];
	$approval    = ( new \MindioMagicMCP\Approval_Queue() )->request( 'create_user', array( 'password' => $marker ), $auth );
	$approval_id = (string) $approval['request_id'];
	$changeset    = ( new \MindioMagicMCP\Changeset() )->begin( 'Admin changeset', $auth );
	$changeset_id = (string) $changeset['changeset_id'];

	// Administrators keep full access.
	$admin_read = mindio_pc_tool( (string) $admin_key['token'], 'get_post', array( 'post_id' => $protected_id ) );
	mindio_pc_assert( ! mindio_pc_denied( $admin_read ) && str_contains( (string) $admin_read['result']['structuredContent']['content'], $marker ), 'An administrator lost access to a protected post.' );

	$sub_key = $auth->create_api_key( $subscriber_id, \MindioMagicMCP\Auth::SCOPE_READ, 'Protected content subscriber' );
	mindio_pc_assert( ! is_wp_error( $sub_key ), 'Could not create subscriber credential.' );
	$token_ids[] = (string) $sub_key['id'];

	// Exercise both the plugin token path and the pre-authenticated WordPress identity
	// path that Application Passwords use.
	foreach ( array( 'token' => (string) $sub_key['token'], 'wordpress' => '' ) as $label => $token ) {
		wp_set_current_user( $subscriber_id );

		$listed = mindio_pc_tool( $token, 'list_posts', array( 'post_type' => 'post', 'status' => 'publish', 'per_page' => 100 ) );
		$ids    = array_column( (array) ( $listed['result']['structuredContent']['items'] ?? array() ), 'post_id' );
		mindio_pc_assert( ! mindio_pc_denied( $listed ) && in_array( $public_id, $ids, true ), "[$label] list_posts lost public posts." );
		mindio_pc_assert( ! in_array( $protected_id, $ids, true ), "[$label] list_posts enumerated a password-protected post." );

		foreach ( array( 'protected' => $protected_id, 'revision' => $revision_ids[0], 'hidden type' => $hidden_id ) as $kind => $target_id ) {
			$blocked = array(
				'get_post'          => array( 'post_id' => $target_id ),
				'get_post_blocks'   => array( 'post_id' => $target_id ),
				'explain_page'      => array( 'post_id' => $target_id ),
				'get_flatsome_page' => array( 'post_id' => $target_id ),
				'summarize_content' => array( 'post_id' => $target_id ),
				'get_meta'          => array( 'post_id' => $target_id ),
			);
			foreach ( $blocked as $tool => $arguments ) {
				$response = mindio_pc_tool( $token, $tool, $arguments );
				mindio_pc_assert( mindio_pc_denied( $response ), "[$label] $tool returned a $kind post." );
				mindio_pc_assert( ! str_contains( (string) wp_json_encode( $response ), $marker ), "[$label] $tool leaked $kind content." );
			}
			$resource = mindio_pc_rpc( $token, 'resources/read', array( 'uri' => 'mindio://post/' . $target_id ) );
			mindio_pc_assert( mindio_pc_denied( $resource ) && ! str_contains( (string) wp_json_encode( $resource ), $marker ), "[$label] resources/read leaked $kind content." );
		}

		if ( function_exists( 'get_field' ) ) {
			foreach ( array( '_mindio_pc_secret', 'mindio_pc_plain' ) as $meta_key ) {
				$acf_response = mindio_pc_tool( $token, 'acf_read', array( 'operation' => 'get_field_value', 'arguments' => array( 'post_id' => $public_id, 'field' => $meta_key ) ) );
				mindio_pc_assert( ! str_contains( (string) wp_json_encode( $acf_response ), $marker ), "[$label] acf_read exposed raw post meta $meta_key." );
			}
		}

		$server_status = mindio_pc_tool( $token, 'get_server_status' );
		$status_data = (array) ( $server_status['result']['structuredContent'] ?? array() );
		mindio_pc_assert( array_key_exists( 'php_version', $status_data ) && null === $status_data['php_version'], "[$label] get_server_status exposed the PHP version." );

		$public_read = mindio_pc_tool( $token, 'get_post', array( 'post_id' => $public_id ) );
		mindio_pc_assert( ! mindio_pc_denied( $public_read ), "[$label] get_post denied a public post." );

		$search = mindio_pc_tool( $token, 'search_content', array( 'query' => $marker ) );
		mindio_pc_assert( ! str_contains( (string) wp_json_encode( $search ), $marker ), "[$label] search_content leaked protected content." );

		$resource = mindio_pc_rpc( $token, 'resources/read', array( 'uri' => 'mindio://post/' . $protected_id ) );
		mindio_pc_assert( mindio_pc_denied( $resource ) && ! str_contains( (string) wp_json_encode( $resource ), $marker ), "[$label] resources/read leaked protected content." );

		$collection = mindio_pc_rpc( $token, 'resources/read', array( 'uri' => 'mindio://posts/post' ) );
		mindio_pc_assert( ! str_contains( (string) wp_json_encode( $collection ), '"id":' . $protected_id . ',' ), "[$label] post collection enumerated a protected post." );

		$approvals = mindio_pc_tool( $token, 'list_approvals' );
		mindio_pc_assert( ! str_contains( (string) wp_json_encode( $approvals ), $approval_id ), "[$label] list_approvals exposed another user's request." );
		$approval_read = mindio_pc_tool( $token, 'get_approval', array( 'approval_id' => $approval_id ) );
		mindio_pc_assert( mindio_pc_denied( $approval_read ) && ! str_contains( (string) wp_json_encode( $approval_read ), $marker ), "[$label] get_approval exposed another user's arguments." );
	}

	// Credential allow lists also govern resources and prompts.
	wp_set_current_user( $subscriber_id );
	$limited_key = $auth->create_api_key( $subscriber_id, \MindioMagicMCP\Auth::SCOPE_READ, 'Protected content limited', array( 'allow' => array( 'get_post' ), 'deny' => array(), 'daily_budget' => 0 ) );
	mindio_pc_assert( ! is_wp_error( $limited_key ), 'Could not create limited credential.' );
	$token_ids[] = (string) $limited_key['id'];
	$limited_read = mindio_pc_rpc( (string) $limited_key['token'], 'resources/read', array( 'uri' => 'mindio://post/' . $public_id ) );
	mindio_pc_assert( mindio_pc_denied( $limited_read ), 'resources/read bypassed the credential allow list.' );

	wp_set_current_user( $contributor_id );
	$con_key =$auth->create_api_key( $contributor_id, \MindioMagicMCP\Auth::SCOPE_EDITOR, 'Protected content contributor' );
	mindio_pc_assert( ! is_wp_error( $con_key ), 'Could not create contributor credential.' );
	$token_ids[] = (string) $con_key['id'];
	$con_token   = (string) $con_key['token'];

	foreach ( array( 'draft', 'any' ) as $list_status ) {
		$drafts = mindio_pc_tool( $con_token, 'list_posts', array( 'post_type' => 'post', 'status' => $list_status, 'search' => $marker ) );
		mindio_pc_assert(
			! mindio_pc_denied( $drafts ) && 0 === (int) ( $drafts['result']['structuredContent']['total'] ?? -1 ),
			"list_posts status=$list_status revealed matches in another author's draft."
		);
	}

	$changesets = mindio_pc_tool( $con_token, 'list_changesets' );
	mindio_pc_assert( ! str_contains( (string) wp_json_encode( $changesets ), $changeset_id ), "list_changesets exposed another user's changeset." );
	foreach ( array( 'get_changeset', 'close_changeset' ) as $tool ) {
		mindio_pc_assert( mindio_pc_denied( mindio_pc_tool( $con_token, $tool, array( 'changeset_id' => $changeset_id ) ) ), "$tool reached another user's changeset." );
	}
	mindio_pc_assert(
		mindio_pc_denied( mindio_pc_tool( $con_token, 'revert_changeset', array( 'changeset_id' => $changeset_id, 'confirm' => true ) ) ),
		"revert_changeset reached another user's changeset."
	);

	echo "Protected content exposure regression: OK\n";
} finally {
	wp_set_current_user( $admin_id );
	foreach ( $post_ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $token_ids as $id ) {
		$auth->revoke_token( $id );
	}
	global $wpdb;
	if ( '' !== $approval_id ) {
		$wpdb->delete( \MindioMagicMCP\Installer::approval_table(), array( 'request_id' => $approval_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	if ( '' !== $changeset_id ) {
		$wpdb->delete( \MindioMagicMCP\Installer::changeset_table(), array( 'changeset_id' => $changeset_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( $subscriber_id, $contributor_id ) as $id ) {
		if ( $id > 0 ) {
			wp_delete_user( $id );
		}
	}
	if ( null === $original_disabled_tools ) {
		delete_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION );
	} else {
		update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, $original_disabled_tools, false );
	}
}
