<?php
/**
 * explain_page coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/explain-page.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Page_Analysis_Tools' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the explain_page test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_explain_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_explain_call( string $token, string $tool, array $arguments = array() ): array {
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
	mindio_explain_assert( 200 === $response->get_status(), $tool . ' returned HTTP ' . $response->get_status() );
	$result = (array) ( $response->get_data()['result'] ?? array() );
	mindio_explain_assert( empty( $result['isError'] ), $tool . ' failed: ' . wp_json_encode( $result['structuredContent'] ?? array() ) );

	return (array) $result['structuredContent'];
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_explain_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'explain_page coverage' );
mindio_explain_assert( ! is_wp_error( $credential ), 'Could not create the explain_page credential.' );
$token = (string) $credential['token'];

$block_page = 0;
$builder_page = 0;

try {
	$block_page = wp_insert_post(
		array(
			'post_title'   => 'Explain fixture',
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_content' => implode(
				'',
				array(
					'<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Primary heading</h1><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Body copy with an <a href="https://external.example/docs">external link</a> and an internal one to <a href="' . esc_url( home_url( '/about/' ) ) . '">about</a>.</p><!-- /wp:paragraph -->',
					// A skipped level (h1 straight to h3) and an image with no alt text.
					'<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Skipped level</h3><!-- /wp:heading -->',
					'<!-- wp:html --><img src="https://example.com/a.png" alt=""/><!-- /wp:html -->',
				)
			),
		),
		true
	);
	mindio_explain_assert( ! is_wp_error( $block_page ), 'Could not create the block fixture page.' );
	$block_page = (int) $block_page;

	$explained = mindio_explain_call( $token, 'explain_page', array( 'post_id' => $block_page ) );

	mindio_explain_assert( (int) $explained['post_id'] === $block_page, 'explain_page returned the wrong post.' );
	mindio_explain_assert( 'gutenberg' === $explained['builder']['id'], 'explain_page misidentified the builder.' );
	mindio_explain_assert( $explained['summary']['sections'] > 0, 'explain_page found no sections.' );
	mindio_explain_assert( $explained['summary']['word_count'] > 0, 'explain_page reported no words.' );

	mindio_explain_assert( 1 === (int) $explained['headings']['h1_count'], 'explain_page miscounted H1 headings.' );
	mindio_explain_assert( true === $explained['headings']['skipped_levels'], 'explain_page did not detect the skipped heading level.' );
	mindio_explain_assert(
		'Primary heading' === (string) $explained['headings']['outline'][0]['text'],
		'explain_page reported the wrong first heading.'
	);

	mindio_explain_assert( (int) $explained['media']['image_count'] >= 1, 'explain_page found no images.' );
	mindio_explain_assert( (int) $explained['media']['missing_alt_count'] >= 1, 'explain_page did not flag the empty alt text.' );

	mindio_explain_assert( (int) $explained['links']['external_count'] >= 1, 'explain_page found no external link.' );
	mindio_explain_assert( (int) $explained['links']['internal_count'] >= 1, 'explain_page found no internal link.' );

	mindio_explain_assert( ! empty( $explained['editing'] ), 'explain_page returned no editing hints.' );

	$without_text = mindio_explain_call( $token, 'explain_page', array( 'post_id' => $block_page, 'include_text' => false ) );
	$has_text     = false;
	foreach ( (array) $without_text['sections'] as $section ) {
		if ( isset( $section['text'] ) ) {
			$has_text = true;
		}
	}
	mindio_explain_assert( ! $has_text, 'include_text=false still returned excerpts.' );

	$created = mindio_explain_call(
		$token,
		'create_builder_page',
		array(
			'title'     => 'Explain builder fixture',
			'blueprint' => array(
				'sections' => array(
					array(
						'label' => 'Intro',
						'rows'  => array(
							array(
								'columns' => array(
									array(
										'span'     => 12,
										'elements' => array(
											array( 'type' => 'heading', 'text' => 'Built heading', 'level' => 2 ),
											array( 'type' => 'text', 'content' => '<p>Built body copy.</p>' ),
										),
									),
								),
							),
						),
					),
				),
			),
		)
	);
	$builder_page = (int) $created['post_id'];

	$built_outline = mindio_explain_call( $token, 'explain_page', array( 'post_id' => $builder_page ) );
	mindio_explain_assert(
		(string) $built_outline['builder']['id'] === (string) $created['builder'],
		'explain_page did not detect the builder that created the page.'
	);
	mindio_explain_assert( (int) $built_outline['summary']['elements'] > 0, 'explain_page found no elements on a built page.' );

	$missing = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$missing->set_header( 'Authorization', 'Bearer ' . $token );
	$missing->set_header( 'Content-Type', 'application/json' );
	$missing->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'explain_page', 'arguments' => array( 'post_id' => 999999999 ) ),
			)
		)
	);
	$missing_result = (array) rest_get_server()->dispatch( $missing )->get_data()['result'];
	mindio_explain_assert( ! empty( $missing_result['isError'] ), 'explain_page accepted a missing post.' );

	echo wp_json_encode(
		array(
			'ok'              => true,
			'builder'         => (string) $explained['builder']['id'],
			'headings'        => count( (array) $explained['headings']['outline'] ),
			'accessibility'   => (int) $explained['media']['missing_alt_count'],
			'links'           => (int) $explained['links']['internal_count'] + (int) $explained['links']['external_count'],
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	foreach ( array( $block_page, $builder_page ) as $post_id ) {
		if ( $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	$auth->revoke_token( (string) $credential['id'] );
}
