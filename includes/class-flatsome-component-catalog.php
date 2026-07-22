<?php
/**
 * Native Flatsome component metadata and runtime capability discovery.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Flatsome_Component_Catalog {
	/** @var array<string,array{category:string,shortcodes:string[],container:bool,allowed_children:string[],dependency:string}> */
	private const DEFINITIONS = array(
		'title'              => array( 'category' => 'content', 'shortcodes' => array( 'title' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'text'               => array( 'category' => 'content', 'shortcodes' => array( 'ux_text' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'image'              => array( 'category' => 'content', 'shortcodes' => array( 'ux_image' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'button'             => array( 'category' => 'content', 'shortcodes' => array( 'button' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'banner'             => array( 'category' => 'content', 'shortcodes' => array( 'ux_banner', 'text_box', 'title', 'ux_text', 'button' ), 'container' => true, 'allowed_children' => array( 'title', 'text', 'button' ), 'dependency' => '' ),
		'featured_box'       => array( 'category' => 'content', 'shortcodes' => array( 'featured_box', 'ux_text' ), 'container' => true, 'allowed_children' => array( 'text' ), 'dependency' => '' ),
		'image_box'          => array( 'category' => 'content', 'shortcodes' => array( 'ux_image_box', 'title', 'ux_text' ), 'container' => true, 'allowed_children' => array( 'title', 'text' ), 'dependency' => '' ),
		'message_box'        => array( 'category' => 'content', 'shortcodes' => array( 'message_box' ), 'container' => true, 'allowed_children' => array( 'title', 'text', 'image', 'button', 'featured_box', 'image_box', 'video', 'testimonial', 'divider', 'gap', 'html' ), 'dependency' => '' ),
		'slider'             => array( 'category' => 'interactive', 'shortcodes' => array( 'ux_slider' ), 'container' => true, 'allowed_children' => array( 'banner', 'image', 'image_box', 'testimonial', 'html' ), 'dependency' => '' ),
		'banner_grid'        => array( 'category' => 'interactive', 'shortcodes' => array( 'ux_banner_grid', 'col_grid', 'ux_banner', 'text_box' ), 'container' => true, 'allowed_children' => array( 'banner' ), 'dependency' => '' ),
		'accordion'          => array( 'category' => 'interactive', 'shortcodes' => array( 'accordion', 'accordion-item' ), 'container' => true, 'allowed_children' => array( 'title', 'text', 'image', 'button', 'featured_box', 'image_box', 'video', 'testimonial', 'divider', 'gap', 'html' ), 'dependency' => '' ),
		'tabs'               => array( 'category' => 'interactive', 'shortcodes' => array( 'tabgroup', 'tab' ), 'container' => true, 'allowed_children' => array( 'title', 'text', 'image', 'button', 'featured_box', 'image_box', 'video', 'testimonial', 'divider', 'gap', 'html' ), 'dependency' => '' ),
		'gallery'            => array( 'category' => 'media', 'shortcodes' => array( 'ux_gallery' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'video'              => array( 'category' => 'media', 'shortcodes' => array( 'ux_video' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'countdown'          => array( 'category' => 'interactive', 'shortcodes' => array( 'ux_countdown' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'testimonial'        => array( 'category' => 'business', 'shortcodes' => array( 'testimonial', 'ux_text' ), 'container' => true, 'allowed_children' => array( 'text' ), 'dependency' => '' ),
		'team_member'        => array( 'category' => 'business', 'shortcodes' => array( 'team_member', 'ux_text' ), 'container' => true, 'allowed_children' => array( 'text' ), 'dependency' => '' ),
		'price_table'        => array( 'category' => 'business', 'shortcodes' => array( 'ux_price_table', 'bullet_item', 'button' ), 'container' => true, 'allowed_children' => array( 'button' ), 'dependency' => '' ),
		'logo'               => array( 'category' => 'business', 'shortcodes' => array( 'logo' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'divider'            => array( 'category' => 'layout', 'shortcodes' => array( 'divider' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'gap'                => array( 'category' => 'layout', 'shortcodes' => array( 'gap' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'blog_posts'         => array( 'category' => 'dynamic', 'shortcodes' => array( 'blog_posts' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'products'           => array( 'category' => 'dynamic', 'shortcodes' => array( 'ux_products' ), 'container' => false, 'allowed_children' => array(), 'dependency' => 'woocommerce' ),
		'product_categories' => array( 'category' => 'dynamic', 'shortcodes' => array( 'ux_product_categories' ), 'container' => false, 'allowed_children' => array(), 'dependency' => 'woocommerce' ),
		'follow'             => array( 'category' => 'dynamic', 'shortcodes' => array( 'follow' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'share'              => array( 'category' => 'dynamic', 'shortcodes' => array( 'share' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'map'                => array( 'category' => 'dynamic', 'shortcodes' => array( 'map' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'search'             => array( 'category' => 'dynamic', 'shortcodes' => array( 'search' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
		'html'               => array( 'category' => 'fallback', 'shortcodes' => array( 'ux_html' ), 'container' => false, 'allowed_children' => array(), 'dependency' => '' ),
	);

	/** @return string[] */
	public function types(): array {
		return array_keys( self::DEFINITIONS );
	}

	/** @return array{category:string,shortcodes:string[],container:bool,allowed_children:string[],dependency:string}|null */
	public function get( string $type ): ?array {
		return self::DEFINITIONS[ $type ] ?? null;
	}

	public function flatsome_active(): bool {
		return 'flatsome' === get_template();
	}

	public function flatsome_version(): string {
		if ( ! $this->flatsome_active() ) {
			return '';
		}
		$theme = wp_get_theme( get_template() );
		return (string) $theme->get( 'Version' );
	}

	public function dependency_available( string $type ): bool {
		$definition = $this->get( $type );
		if ( ! $definition || '' === $definition['dependency'] ) {
			return true;
		}
		return 'woocommerce' === $definition['dependency'] && class_exists( 'WooCommerce' );
	}

	/** @return string[] */
	public function missing_shortcodes( string $type ): array {
		$definition = $this->get( $type );
		if ( ! $definition ) {
			return array();
		}
		return array_values(
			array_filter(
				$definition['shortcodes'],
				static fn( string $shortcode ): bool => ! shortcode_exists( $shortcode )
			)
		);
	}

	public function available( string $type ): bool {
		return $this->flatsome_active() && $this->dependency_available( $type ) && empty( $this->missing_shortcodes( $type ) );
	}

	/** @return array<string,mixed> */
	public function discovery(): array {
		$components = array();
		foreach ( self::DEFINITIONS as $type => $definition ) {
			$components[] = array(
				'type'             => $type,
				'category'         => $definition['category'],
				'shortcodes'       => $definition['shortcodes'],
				'available'        => $this->available( $type ),
				'dependency'       => $definition['dependency'],
				'container'        => $definition['container'],
				'allowed_children' => $definition['allowed_children'],
				'missing_shortcodes' => $this->missing_shortcodes( $type ),
			);
		}
		return array(
			'flatsome_active'  => $this->flatsome_active(),
			'flatsome_version' => $this->flatsome_version(),
			'components'       => $components,
			'fallback_type'    => 'html',
		);
	}
}
