<?php
/**
 * Expanded WordPress, Gutenberg, free-plugin, filesystem, and database checks.
 *
 * Run with WP_PATH=/path/to/wordpress php tests/integration/expanded-tools.php.
 * ACF Free, Contact Form 7, and WooCommerce Free are exercised when active.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\\MindioMagicMCP\\Auth' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the expanded-tools test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function fmp_exp_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function fmp_exp_rpc( string $token, string $method, array $params = array(), int $id = 1 ): array {
	$request = new WP_REST_Request( 'POST', '/flatsome-mcp/v1/mcp' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
	$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ) ) );
	$response = rest_get_server()->dispatch( $request );
	fmp_exp_assert( 200 === $response->get_status(), 'Unexpected MCP HTTP status: ' . $response->get_status() );
	$data = $response->get_data();
	fmp_exp_assert( is_array( $data ), 'MCP response is not an object.' );
	return $data;
}

/** @return array<string,mixed> */
function fmp_exp_call( string $token, string $tool, array $arguments = array(), bool $allow_error = false ): array {
	$response = fmp_exp_rpc( $token, 'tools/call', array( 'name' => $tool, 'arguments' => $arguments ) );
	$result   = (array) ( $response['result'] ?? array() );
	if ( ! $allow_error ) {
		fmp_exp_assert( empty( $result['isError'] ), $tool . ' failed: ' . wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
	return $result;
}

/** @return array<string,mixed> */
function fmp_exp_integration_result( array $call ): array {
	return (array) ( $call['structuredContent']['result'] ?? array() );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
fmp_exp_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0] );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( (int) $admins[0], \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Expanded tools integration test' );
fmp_exp_assert( ! is_wp_error( $credential ), 'Could not create an expanded-tools credential.' );
$token = (string) $credential['token'];

$original_settings         = get_option( 'mindio_magic_mcp_settings', array() );
$original_disabled_tools   = get_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, null );
$original_operation_policy = get_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION, null );
$post_ids                  = array();
$acf_group_key             = '';
$acf_field_key             = '';
$form_ids                  = array();
$product_id                = 0;
$tested_integrations       = array();

update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, array(), false );
update_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION, array(), false );

try {
	$tools_response = fmp_exp_rpc( $token, 'tools/list' );
	$tools          = array_column( (array) ( $tools_response['result']['tools'] ?? array() ), null, 'name' );
	foreach ( array( 'list_blocks', 'get_block_schema', 'get_post_blocks', 'add_block', 'update_block', 'remove_block', 'move_block', 'duplicate_block', 'insert_pattern', 'search_plugins', 'update_plugin', 'search_themes', 'install_theme', 'update_theme', 'delete_theme', 'get_theme_context', 'get_theme_mods', 'update_theme_mods', 'create_child_theme', 'read_file', 'list_directory', 'search_files', 'list_database_tables', 'describe_database_table' ) as $required ) {
		fmp_exp_assert( isset( $tools[ $required ] ), 'Expanded tool is missing from discovery: ' . $required );
	}
	fmp_exp_assert( ! isset( $tools['acf_write'], $tools['contact_form_7_write'], $tools['woocommerce_write'] ), 'Default-denied integration write tools leaked into discovery.' );

	$blocked_write = fmp_exp_call(
		$token,
		'acf_write',
		array( 'operation' => 'save_field_group', 'arguments' => array( 'group' => array( 'title' => 'Blocked' ), 'confirm' => true ) ),
		true
	);
	fmp_exp_assert( ! empty( $blocked_write['isError'] ) && 'operation_disabled' === ( $blocked_write['structuredContent']['error'] ?? '' ), 'A default-disabled integration write operation was callable.' );

	$gutenberg_post = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Expanded Gutenberg integration test',
			'post_content' => '<!-- wp:paragraph --><p>First block</p><!-- /wp:paragraph -->',
		),
		true
	);
	fmp_exp_assert( ! is_wp_error( $gutenberg_post ), 'Could not create the Gutenberg fixture post.' );
	$post_ids[] = (int) $gutenberg_post;
	$block_tree = fmp_exp_call( $token, 'get_post_blocks', array( 'post_id' => (int) $gutenberg_post ) );
	fmp_exp_assert( 1 === (int) ( $block_tree['structuredContent']['block_count'] ?? 0 ), 'The initial Gutenberg tree was not parsed.' );
	$added = fmp_exp_call(
		$token,
		'add_block',
		array(
			'post_id' => (int) $gutenberg_post,
			'markup'  => '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Second block</h2><!-- /wp:heading -->',
		)
	);
	fmp_exp_assert( 2 === (int) ( $added['structuredContent']['block_count'] ?? 0 ), 'Gutenberg block insertion failed.' );
	$duplicated = fmp_exp_call( $token, 'duplicate_block', array( 'post_id' => (int) $gutenberg_post, 'path' => array( 1 ) ) );
	fmp_exp_assert( 3 === (int) ( $duplicated['structuredContent']['block_count'] ?? 0 ), 'Gutenberg block duplication failed.' );
	$removed = fmp_exp_call( $token, 'remove_block', array( 'post_id' => (int) $gutenberg_post, 'path' => array( 0 ), 'confirm' => true ) );
	fmp_exp_assert( 2 === (int) ( $removed['structuredContent']['block_count'] ?? 0 ) && ! str_contains( (string) get_post_field( 'post_content', (int) $gutenberg_post ), 'First block' ), 'Gutenberg block removal failed.' );
	$unsafe_block = fmp_exp_call(
		$token,
		'add_block',
		array(
			'post_id' => (int) $gutenberg_post,
			'markup'  => '<!-- wp:html --><div onclick="alert(1)"><strong>Filtered block</strong></div><script>alert(1)</script><!-- /wp:html -->',
		)
	);
	$filtered_block_content = (string) get_post_field( 'post_content', (int) $gutenberg_post );
	fmp_exp_assert( 3 === (int) ( $unsafe_block['structuredContent']['block_count'] ?? 0 ) && str_contains( $filtered_block_content, '<strong>Filtered block</strong>' ) && ! str_contains( $filtered_block_content, '<script' ) && ! str_contains( $filtered_block_content, 'onclick=' ), 'Agent-supplied Gutenberg markup was not safely filtered.' );

	$filesystem_disabled = fmp_exp_call( $token, 'read_file', array( 'root' => 'parent_theme', 'path' => 'style.css' ), true );
	fmp_exp_assert( ! empty( $filesystem_disabled['isError'] ) && 'filesystem_read_disabled' === ( $filesystem_disabled['structuredContent']['error'] ?? '' ), 'Filesystem reads were not opt-in.' );
	$settings                          = $original_settings;
	$settings['allow_filesystem_read'] = true;
	$settings['allow_database_inspection']      = true;
	update_option( 'mindio_magic_mcp_settings', $settings, false );
	$file = fmp_exp_call( $token, 'read_file', array( 'root' => 'parent_theme', 'path' => 'style.css', 'end_line' => 40 ) );
	fmp_exp_assert( str_contains( (string) ( $file['structuredContent']['content'] ?? '' ), 'Text Domain:          flatsome' ), 'The allowlisted theme file could not be read.' );
	$traversal = fmp_exp_call( $token, 'read_file', array( 'root' => 'plugins', 'path' => '../wp-config.php' ), true );
	fmp_exp_assert( ! empty( $traversal['isError'] ) && 'invalid_file_path' === ( $traversal['structuredContent']['error'] ?? '' ), 'Filesystem traversal was not rejected.' );
	$directory = fmp_exp_call( $token, 'list_directory', array( 'root' => 'parent_theme', 'path' => 'inc', 'depth' => 0, 'max_entries' => 100 ) );
	fmp_exp_assert( (int) ( $directory['structuredContent']['count'] ?? 0 ) > 5, 'Bounded directory listing returned no theme files.' );
	$search = fmp_exp_call( $token, 'search_files', array( 'root' => 'parent_theme', 'path' => 'inc', 'query' => 'Flatsome', 'extensions' => array( 'php' ), 'max_files' => 100, 'max_matches' => 20 ) );
	fmp_exp_assert( (int) ( $search['structuredContent']['match_count'] ?? 0 ) > 0, 'Bounded filesystem search found no known source reference.' );

	$database = fmp_exp_call( $token, 'list_database_tables' );
	$table_names = array_column( (array) ( $database['structuredContent']['tables'] ?? array() ), 'table' );
	fmp_exp_assert( in_array( 'posts', $table_names, true ) && ! in_array( 'users', $table_names, true ) && ! in_array( 'options', $table_names, true ), 'Database table classification exposed a sensitive table or hid posts.' );
	$description = fmp_exp_call( $token, 'describe_database_table', array( 'table' => 'posts' ) );
	fmp_exp_assert( in_array( 'ID', array_column( (array) ( $description['structuredContent']['columns'] ?? array() ), 'name' ), true ), 'Database table description is incomplete.' );
	$blocked_table = fmp_exp_call( $token, 'describe_database_table', array( 'table' => 'users' ), true );
	fmp_exp_assert( ! empty( $blocked_table['isError'] ) && 'database_table_forbidden' === ( $blocked_table['structuredContent']['error'] ?? '' ), 'Sensitive database structure was exposed.' );

	$theme = fmp_exp_call( $token, 'get_theme_context' );
	fmp_exp_assert( ! empty( $theme['structuredContent']['stylesheet'] ) && array_key_exists( 'theme_supports', (array) $theme['structuredContent'] ), 'Generic theme context is incomplete.' );
	$theme_mods = fmp_exp_call( $token, 'get_theme_mods', array( 'keys' => array( 'custom_logo', 'background_color' ) ) );
	fmp_exp_assert( ! empty( $theme_mods['structuredContent']['redacted'] ) && in_array( 'custom_logo', (array) ( $theme_mods['structuredContent']['writable_keys'] ?? array() ), true ), 'Generic theme-mod discovery is incomplete.' );

	$enabled_write_operations = array(
		'acf_write:save_field_group'        => true,
		'acf_write:delete_field_group'      => true,
		'acf_write:save_field'              => true,
		'acf_write:delete_field'            => true,
		'acf_write:update_field_value'      => true,
		'contact_form_7_write:create_form'  => true,
		'contact_form_7_write:update_form'  => true,
		'contact_form_7_write:duplicate_form'=> true,
		'contact_form_7_write:delete_form'  => true,
		'woocommerce_write:create_product'  => true,
		'woocommerce_write:delete_product'  => true,
	);
	update_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION, $enabled_write_operations, false );
	$tools_response = fmp_exp_rpc( $token, 'tools/list' );
	$tools          = array_column( (array) ( $tools_response['result']['tools'] ?? array() ), null, 'name' );
	foreach ( array( 'acf_write', 'contact_form_7_write', 'woocommerce_write' ) as $write_tool ) {
		fmp_exp_assert( isset( $tools[ $write_tool ] ), 'An administrator-enabled write dispatcher is missing: ' . $write_tool );
	}
	$woo_write_enum = (array) ( $tools['woocommerce_write']['inputSchema']['properties']['operation']['enum'] ?? array() );
	sort( $woo_write_enum );
	fmp_exp_assert( array( 'create_product', 'delete_product' ) === $woo_write_enum, 'Write operation discovery did not apply granular policy.' );

	if ( function_exists( 'acf_get_field_groups' ) ) {
		$tested_integrations[] = 'acf_free';
		$group_call = fmp_exp_call(
			$token,
			'acf_write',
			array(
				'operation' => 'save_field_group',
				'arguments' => array(
					'confirm' => true,
					'group'   => array(
						'title'        => 'Mindio Magic MCP integration fixture',
						'active'       => true,
						'show_in_rest' => true,
						'location'     => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
					),
				),
			)
		);
		$group_result  = fmp_exp_integration_result( $group_call );
		$acf_group_key = (string) ( $group_result['group']['key'] ?? '' );
		fmp_exp_assert( str_starts_with( $acf_group_key, 'group_' ), 'ACF field group was not created.' );
		$field_call = fmp_exp_call(
			$token,
			'acf_write',
			array(
				'operation' => 'save_field',
				'arguments' => array(
					'confirm' => true,
					'field'   => array( 'parent' => $acf_group_key, 'label' => 'MCP fixture value', 'name' => 'mcp_fixture_value', 'type' => 'wysiwyg' ),
				),
			)
		);
		$field_result  = fmp_exp_integration_result( $field_call );
		$acf_field_key = (string) ( $field_result['field']['key'] ?? '' );
		fmp_exp_assert( str_starts_with( $acf_field_key, 'field_' ), 'ACF field was not created.' );
		$value_post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'ACF MCP fixture' ), true );
		fmp_exp_assert( ! is_wp_error( $value_post ), 'Could not create the ACF value fixture post.' );
		$post_ids[] = (int) $value_post;
		fmp_exp_call( $token, 'acf_write', array( 'operation' => 'update_field_value', 'arguments' => array( 'post_id' => (int) $value_post, 'field' => $acf_field_key, 'value' => '<p onclick="alert(1)">Stored <strong>through MCP</strong></p><script>alert(1)</script>' ) ) );
		$value = fmp_exp_call( $token, 'acf_read', array( 'operation' => 'get_field_value', 'arguments' => array( 'post_id' => (int) $value_post, 'field' => $acf_field_key, 'format_value' => false ) ) );
		$stored_acf_value = (string) ( fmp_exp_integration_result( $value )['value'] ?? '' );
		fmp_exp_assert( str_contains( $stored_acf_value, '<strong>through MCP</strong>' ) && ! str_contains( $stored_acf_value, '<script' ) && ! str_contains( $stored_acf_value, 'onclick=' ), 'Agent-supplied ACF HTML was not safely filtered.' );
	}

	if ( class_exists( '\\WPCF7_ContactForm' ) ) {
		$tested_integrations[] = 'contact_form_7';
		$created_form = fmp_exp_call( $token, 'contact_form_7_write', array( 'operation' => 'create_form', 'arguments' => array( 'title' => 'Mindio Magic MCP form fixture', 'locale' => 'en_US', 'confirm' => true ) ) );
		$form_id      = (int) ( fmp_exp_integration_result( $created_form )['form']['id'] ?? 0 );
		fmp_exp_assert( $form_id > 0, 'Contact Form 7 form was not created.' );
		$form_ids[] = $form_id;
		$form = fmp_exp_call( $token, 'contact_form_7_read', array( 'operation' => 'get_form', 'arguments' => array( 'form_id' => $form_id ) ) );
		fmp_exp_assert( 'Mindio Magic MCP form fixture' === ( fmp_exp_integration_result( $form )['form']['title'] ?? '' ), 'Contact Form 7 form read failed.' );
		$duplicate = fmp_exp_call( $token, 'contact_form_7_write', array( 'operation' => 'duplicate_form', 'arguments' => array( 'form_id' => $form_id, 'title' => 'Mindio Magic MCP copied form', 'confirm' => true ) ) );
		$copy_id   = (int) ( fmp_exp_integration_result( $duplicate )['form']['id'] ?? 0 );
		fmp_exp_assert( $copy_id > 0 && $copy_id !== $form_id, 'Contact Form 7 duplication failed.' );
		$form_ids[] = $copy_id;
	}

	if ( class_exists( '\\WooCommerce' ) && function_exists( 'WC' ) ) {
		$tested_integrations[] = 'woocommerce_free';
		$product = fmp_exp_call(
			$token,
			'woocommerce_write',
			array(
				'operation' => 'create_product',
				'arguments' => array( 'data' => array( 'name' => 'Mindio Magic MCP product fixture', 'type' => 'simple', 'status' => 'draft', 'regular_price' => '19.95', 'description' => '<p onclick="alert(1)">Filtered <strong>product</strong></p><script>alert(1)</script>' ) ),
			)
		);
		$product_id = (int) ( fmp_exp_integration_result( $product )['data']['id'] ?? 0 );
		fmp_exp_assert( $product_id > 0, 'WooCommerce product was not created.' );
		$product_read = fmp_exp_call( $token, 'woocommerce_read', array( 'operation' => 'get_product', 'arguments' => array( 'product_id' => $product_id ) ) );
		fmp_exp_assert( 'Mindio Magic MCP product fixture' === ( fmp_exp_integration_result( $product_read )['data']['name'] ?? '' ), 'WooCommerce product read failed.' );
		$product_description = (string) ( fmp_exp_integration_result( $product_read )['data']['description'] ?? '' );
		fmp_exp_assert( str_contains( $product_description, '<strong>product</strong>' ) && ! str_contains( $product_description, '<script' ) && ! str_contains( $product_description, 'onclick=' ), 'Agent-supplied product HTML was not safely filtered.' );
		$deleted_product = fmp_exp_call( $token, 'woocommerce_write', array( 'operation' => 'delete_product', 'arguments' => array( 'product_id' => $product_id, 'force' => true, 'confirm' => true ) ) );
		fmp_exp_assert( 200 === (int) ( fmp_exp_integration_result( $deleted_product )['status'] ?? 0 ), 'WooCommerce product deletion failed.' );
		$product_id = 0;
	}

	echo wp_json_encode(
		array(
			'ok'                  => true,
			'gutenberg'           => true,
			'filesystem_database' => true,
			'integrations'        => $tested_integrations,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} finally {
	if ( $product_id > 0 ) {
		wp_delete_post( $product_id, true );
	}
	foreach ( array_reverse( $form_ids ) as $form_id ) {
		if ( class_exists( '\\WPCF7_ContactForm' ) ) {
			$form = \WPCF7_ContactForm::get_instance( $form_id );
			if ( $form ) {
				$form->delete();
			}
		}
	}
	if ( $acf_field_key && function_exists( 'acf_delete_field' ) ) {
		acf_delete_field( $acf_field_key );
	}
	if ( $acf_group_key && function_exists( 'acf_delete_field_group' ) ) {
		acf_delete_field_group( $acf_group_key );
	}
	foreach ( array_reverse( $post_ids ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	update_option( 'mindio_magic_mcp_settings', $original_settings, false );
	if ( null === $original_disabled_tools ) {
		delete_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION );
	} else {
		update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, $original_disabled_tools, false );
	}
	if ( null === $original_operation_policy ) {
		delete_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION );
	} else {
		update_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION, $original_operation_policy, false );
	}
	$auth->revoke_token( (string) $credential['id'] );
	global $wpdb;
	$wpdb->delete( \MindioMagicMCP\Installer::audit_table(), array( 'token_id' => (string) $credential['id'] ) );
}
