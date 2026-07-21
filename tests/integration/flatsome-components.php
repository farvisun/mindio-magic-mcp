<?php
/**
 * Native Flatsome component renderer matrix.
 *
 * Run with php tests/integration/flatsome-components.php.
 *
 * @package FlatsomeMCP
 */

declare(strict_types=1);

$_SERVER['SERVER_NAME'] ??= 'localhost';

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

/** @throws RuntimeException */
function fmp_component_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
fmp_component_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0] );

$upload = wp_upload_bits(
	'flatsome-mcp-components.png',
	null,
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true )
);
fmp_component_assert( empty( $upload['error'] ), 'Could not create the component-test image.' );
$media_id = wp_insert_attachment(
	array( 'post_mime_type' => 'image/png', 'post_title' => 'Mindio Magic MCP component fixture', 'post_status' => 'inherit' ),
	(string) $upload['file']
);
fmp_component_assert( $media_id > 0, 'Could not register the component-test image.' );

$catalog  = new \FlatsomeMCP\Flatsome_Component_Catalog();
$renderer = new \FlatsomeMCP\Flatsome_Renderer( $catalog );
$expected_shortcodes = array(
	'title'              => 'title',
	'text'               => 'ux_text',
	'image'              => 'ux_image',
	'button'             => 'button',
	'banner'             => 'ux_banner',
	'featured_box'       => 'featured_box',
	'image_box'          => 'ux_image_box',
	'message_box'        => 'message_box',
	'slider'             => 'ux_slider',
	'banner_grid'        => 'ux_banner_grid',
	'accordion'          => 'accordion',
	'tabs'               => 'tabgroup',
	'gallery'            => 'ux_gallery',
	'video'              => 'ux_video',
	'countdown'          => 'ux_countdown',
	'testimonial'        => 'testimonial',
	'team_member'        => 'team_member',
	'price_table'        => 'ux_price_table',
	'logo'               => 'logo',
	'divider'            => 'divider',
	'gap'                => 'gap',
	'blog_posts'         => 'blog_posts',
	'follow'             => 'follow',
	'share'              => 'share',
	'map'                => 'map',
	'search'             => 'search',
);

$fixtures = array(
	'title'        => array( 'type' => 'title', 'text' => 'Native title', 'tag' => 'h2' ),
	'text'         => array( 'type' => 'text', 'content' => '<p>Native <strong>body</strong></p>' ),
	'image'        => array( 'type' => 'image', 'media_id' => $media_id ),
	'button'       => array( 'type' => 'button', 'text' => 'Action', 'link' => 'https://example.com' ),
	'banner'       => array( 'type' => 'banner', 'media_id' => $media_id, 'heading' => 'Banner heading', 'text' => 'Banner body', 'button_text' => 'Open' ),
	'featured_box' => array( 'type' => 'featured_box', 'media_id' => $media_id, 'title' => 'Feature', 'content' => '<p>Feature body</p>' ),
	'image_box'    => array( 'type' => 'image_box', 'media_id' => $media_id, 'title' => 'Image box', 'content' => '<p>Image body</p>' ),
	'message_box'  => array( 'type' => 'message_box', 'elements' => array( array( 'type' => 'text', 'content' => '<p>Message</p>' ), array( 'type' => 'button', 'text' => 'Read' ) ) ),
	'slider'       => array( 'type' => 'slider', 'slides' => array( array( 'type' => 'image', 'media_id' => $media_id ), array( 'type' => 'testimonial', 'content' => '<p>Slide quote</p>', 'name' => 'Customer' ) ) ),
	'banner_grid'  => array( 'type' => 'banner_grid', 'items' => array( array( 'span' => 12, 'banner' => array( 'type' => 'banner', 'media_id' => $media_id, 'heading' => 'Grid banner' ) ) ) ),
	'accordion'    => array( 'type' => 'accordion', 'faq_schema' => true, 'items' => array( array( 'title' => 'Question', 'elements' => array( array( 'type' => 'text', 'content' => '<p>Answer</p>' ) ) ) ) ),
	'tabs'         => array( 'type' => 'tabs', 'items' => array( array( 'title' => 'Tab one', 'elements' => array( array( 'type' => 'text', 'content' => '<p>Panel</p>' ) ) ) ) ),
	'gallery'      => array( 'type' => 'gallery', 'media_ids' => array( $media_id ) ),
	'video'        => array( 'type' => 'video', 'url' => 'https://www.youtube.com/watch?v=AoPiLg8DZ3A' ),
	'countdown'    => array( 'type' => 'countdown', 'deadline' => '2030-12-31T18:00:00+00:00' ),
	'testimonial'  => array( 'type' => 'testimonial', 'content' => '<p>Excellent service.</p>', 'name' => 'Customer', 'media_id' => $media_id ),
	'team_member'  => array( 'type' => 'team_member', 'name' => 'Team member', 'title' => 'Director', 'content' => '<p>Biography</p>', 'media_id' => $media_id ),
	'price_table'  => array( 'type' => 'price_table', 'title' => 'Business', 'price' => '$99', 'bullets' => array( array( 'text' => 'Support' ) ), 'button' => array( 'type' => 'button', 'text' => 'Choose' ) ),
	'logo'         => array( 'type' => 'logo', 'media_id' => $media_id, 'title' => 'Partner' ),
	'divider'      => array( 'type' => 'divider', 'width' => '40px' ),
	'gap'          => array( 'type' => 'gap', 'height' => '24px' ),
	'blog_posts'   => array( 'type' => 'blog_posts', 'limit' => 2, 'layout' => 'row' ),
	'follow'       => array( 'type' => 'follow', 'social' => array( 'telegram' => 'https://example.com/telegram' ) ),
	'share'        => array( 'type' => 'share', 'align' => 'center' ),
	'map'          => array( 'type' => 'map', 'latitude' => 35.6892, 'longitude' => 51.3890, 'content' => '<p>Tehran</p>' ),
	'search'       => array( 'type' => 'search', 'size' => 'normal' ),
);

try {
	$discovery = $catalog->discovery();
	fmp_component_assert( '3.20.7' === $discovery['flatsome_version'], 'Unexpected Flatsome test version.' );
	fmp_component_assert( 29 === count( $discovery['components'] ), 'The component catalog is incomplete.' );
	$tool_set      = new \FlatsomeMCP\Flatsome_Tools( new \FlatsomeMCP\Tool_Registry( new \FlatsomeMCP\Auth() ), $renderer, $catalog );
	$schema_method = new ReflectionMethod( $tool_set, 'element_schema' );
	$schema         = $schema_method->invoke( $tool_set );
	$validator      = new \FlatsomeMCP\Schema_Validator();

	foreach ( $fixtures as $type => $fixture ) {
		fmp_component_assert( true === $validator->validate( $fixture, $schema ), 'The strict schema rejected ' . $type . '.' );
		$result = $renderer->render_element_fragment( $fixture, 'rtl' );
		fmp_component_assert( ! is_wp_error( $result ), 'Rendering failed for ' . $type . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		fmp_component_assert( str_contains( $result['content'], '[' . $expected_shortcodes[ $type ] ), 'The native shortcode is missing for ' . $type . '.' );
		fmp_component_assert( $result['render_report']['native_count'] >= 1 && 0 === $result['render_report']['fallback_count'], 'The native report is invalid for ' . $type . '.' );
		$rendered_html = do_shortcode( $result['content'] );
		fmp_component_assert( is_string( $rendered_html ), 'Flatsome did not render ' . $type . '.' );
	}

	$text_result = $renderer->render_element_fragment( $fixtures['text'] );
	fmp_component_assert( ! is_wp_error( $text_result ) && str_contains( $text_result['content'], '<strong>body</strong>' ), 'Valid UX text did not render.' );
	$heading_text = $renderer->render_element_fragment( array( 'type' => 'text', 'content' => '<h2>Wrong component</h2><p>Body</p>' ) );
	$shortcode_text = $renderer->render_element_fragment( array( 'type' => 'text', 'content' => '<p>Body [button]</p>' ) );
	fmp_component_assert( is_wp_error( $heading_text ) && 'text_markup_forbidden' === $heading_text->get_error_code(), 'UX text accepted heading markup.' );
	fmp_component_assert( is_wp_error( $shortcode_text ) && 'text_markup_forbidden' === $shortcode_text->get_error_code(), 'UX text accepted shortcode markup.' );
	fmp_component_assert( is_wp_error( $validator->validate( array( 'type' => 'text', 'html' => '<p>Legacy</p>' ), $schema ) ), 'The strict schema accepted legacy text.html.' );

	$html_result = $renderer->render_element_fragment( array( 'type' => 'html', 'reason' => 'No native equivalent', 'html' => '<div onclick="bad()">Fallback [title]</div><script>bad()</script>' ) );
	fmp_component_assert( ! is_wp_error( $html_result ) && 1 === $html_result['render_report']['fallback_count'], 'Explicit HTML fallback was not reported.' );
	fmp_component_assert( ! str_contains( $html_result['content'], '<script' ) && ! str_contains( $html_result['content'], 'onclick=' ) && str_contains( $html_result['content'], '&#91;title&#93;' ), 'Explicit HTML fallback was not sanitized.' );

	global $shortcode_tags;
	$title_callback = $shortcode_tags['title'];
	remove_shortcode( 'title' );
	$missing_result = $renderer->render_element_fragment( array( 'type' => 'title', 'text' => 'Semantic fallback', 'tag' => 'h2' ) );
	add_shortcode( 'title', $title_callback );
	fmp_component_assert( ! is_wp_error( $missing_result ) && 1 === $missing_result['render_report']['fallback_count'] && str_contains( $missing_result['content'], '[ux_html' ) && str_contains( $missing_result['content'], '<h2>' ), 'Unavailable shortcode fallback failed.' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		$products = $renderer->render_element_fragment( array( 'type' => 'products', 'limit' => 4 ) );
		fmp_component_assert( is_wp_error( $products ) && 'component_dependency_missing' === $products->get_error_code(), 'Missing WooCommerce dependency did not fail clearly.' );
	}

	echo wp_json_encode(
		array( 'ok' => true, 'catalog_types' => count( $discovery['components'] ), 'native_matrix' => count( $fixtures ), 'fallbacks' => 2 ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} finally {
	wp_delete_attachment( $media_id, true );
}
