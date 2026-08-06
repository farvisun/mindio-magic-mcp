<?php
/**
 * Builder abstraction coverage. Run with WP_PATH=/path/to/wordpress php tests/integration/builders.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Page_Builder_Registry' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the builder test.' );
}
if ( ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init', rest_get_server() );
}

/** @throws RuntimeException */
function mindio_build_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string,mixed> */
function mindio_build_call( string $token, string $tool, array $arguments = array() ): array {
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
	mindio_build_assert( 200 === $response->get_status(), $tool . ' returned HTTP ' . $response->get_status() );
	$result = (array) ( $response->get_data()['result'] ?? array() );
	mindio_build_assert( empty( $result['isError'] ), $tool . ' failed: ' . wp_json_encode( $result['structuredContent'] ?? array() ) );

	return (array) $result['structuredContent'];
}

/** @return array<string,mixed> */
function mindio_build_blueprint(): array {
	return array(
		'sections' => array(
			array(
				'label'            => 'Hero',
				'background_color' => '#101820',
				'dark'             => true,
				'rows'             => array(
					array(
						'columns' => array(
							array(
								'span'     => 12,
								'align'    => 'center',
								'elements' => array(
									array( 'type' => 'heading', 'text' => 'Neutral hero heading', 'level' => 1 ),
									array( 'type' => 'text', 'content' => '<p>Written once, rendered by whichever builder the site runs.</p>' ),
									array( 'type' => 'button', 'text' => 'Get started', 'link' => 'https://example.com/start', 'style' => 'primary' ),
								),
							),
						),
					),
				),
			),
			array(
				'label' => 'Detail',
				'rows'  => array(
					array(
						'columns' => array(
							array(
								'span'     => 6,
								'elements' => array(
									array( 'type' => 'list', 'items' => array( 'First point', 'Second point' ), 'ordered' => false ),
									array( 'type' => 'separator' ),
								),
							),
							array(
								'span'     => 6,
								'elements' => array(
									array( 'type' => 'quote', 'text' => 'One contract, three renderers.', 'cite' => 'Release notes' ),
									array( 'type' => 'spacer', 'height' => '40px' ),
								),
							),
						),
					),
				),
			),
		),
	);
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
mindio_build_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );

$auth       = new \MindioMagicMCP\Auth();
$credential = $auth->create_api_key( $user_id, \MindioMagicMCP\Auth::SCOPE_ADMIN, 'Builder coverage' );
mindio_build_assert( ! is_wp_error( $credential ), 'Could not create the builder credential.' );
$token = (string) $credential['token'];

$created = array();

try {
	$catalog = mindio_build_call( $token, 'list_page_builders' );
	$ids     = wp_list_pluck( (array) $catalog['builders'], 'id' );
	mindio_build_assert( in_array( 'flatsome', $ids, true ), 'The Flatsome builder is not registered.' );
	mindio_build_assert( in_array( 'gutenberg', $ids, true ), 'The core block builder is not registered.' );
	mindio_build_assert( in_array( 'elementor', $ids, true ), 'The Elementor builder is not registered.' );
	mindio_build_assert( '' !== (string) $catalog['preferred'], 'No preferred builder was reported.' );

	$available = array();
	foreach ( (array) $catalog['builders'] as $builder ) {
		if ( ! empty( $builder['available'] ) ) {
			$available[] = (string) $builder['id'];
		}
	}
	mindio_build_assert( in_array( 'gutenberg', $available, true ), 'Core blocks should always be available.' );

	$auto = mindio_build_call(
		$token,
		'create_builder_page',
		array( 'title' => 'Neutral blueprint (auto)', 'blueprint' => mindio_build_blueprint() )
	);
	$created[] = (int) $auto['post_id'];
	mindio_build_assert( in_array( (string) $auto['builder'], $available, true ), 'Auto selected an unavailable builder.' );
	mindio_build_assert( (int) $auto['render_report']['native_count'] > 0, 'The auto render produced no native elements.' );

	foreach ( $available as $builder_id ) {
		$page = mindio_build_call(
			$token,
			'create_builder_page',
			array(
				'title'     => 'Neutral blueprint (' . $builder_id . ')',
				'builder'   => $builder_id,
				'direction' => 'rtl',
				'blueprint' => mindio_build_blueprint(),
			)
		);
		$created[] = (int) $page['post_id'];
		mindio_build_assert( $builder_id === (string) $page['builder'], 'The page was rendered by the wrong builder.' );

		$post = get_post( (int) $page['post_id'] );
		mindio_build_assert( $post instanceof WP_Post, 'The created page is missing.' );

		if ( 'flatsome' === $builder_id ) {
			mindio_build_assert( str_contains( $post->post_content, '[section' ), 'The Flatsome render has no section shortcode.' );
		}
		if ( 'gutenberg' === $builder_id ) {
			mindio_build_assert( str_contains( $post->post_content, '<!-- wp:heading' ), 'The block render has no heading block.' );
			mindio_build_assert( str_contains( $post->post_content, '<!-- wp:columns' ), 'The block render has no columns block.' );
			mindio_build_assert( str_contains( $post->post_content, 'Neutral hero heading' ), 'The block render lost the heading text.' );
		}
		if ( 'elementor' === $builder_id ) {
			$document = json_decode( (string) get_post_meta( (int) $page['post_id'], '_elementor_data', true ), true );
			mindio_build_assert( is_array( $document ) && $document, 'The Elementor render stored no document.' );
		}
	}

	$rebuilt = mindio_build_call(
		$token,
		'update_builder_page',
		array( 'post_id' => $created[0], 'blueprint' => mindio_build_blueprint() )
	);
	mindio_build_assert(
		(string) $rebuilt['builder'] === (string) $auto['builder'],
		'Updating without a builder argument switched builders.'
	);

	$unknown = new WP_REST_Request( 'POST', '/' . MINDIO_MAGIC_MCP_REST_NAMESPACE . '/mcp' );
	$unknown->set_header( 'Authorization', 'Bearer ' . $token );
	$unknown->set_header( 'Content-Type', 'application/json' );
	$unknown->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'create_builder_page',
					'arguments' => array( 'title' => 'Nope', 'builder' => 'divi', 'blueprint' => mindio_build_blueprint() ),
				),
			)
		)
	);
	$unknown_result = (array) rest_get_server()->dispatch( $unknown )->get_data()['result'];
	mindio_build_assert( ! empty( $unknown_result['isError'] ), 'An unknown builder was accepted.' );

	echo wp_json_encode(
		array(
			'ok'         => true,
			'registered' => count( $ids ),
			'available'  => $available,
			'preferred'  => (string) $catalog['preferred'],
		),
		JSON_PRETTY_PRINT
	) . "\n";
} finally {
	foreach ( $created as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$auth->revoke_token( (string) $credential['id'] );
}
