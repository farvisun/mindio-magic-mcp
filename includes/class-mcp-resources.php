<?php
/**
 * Site content and configuration exposed as MCP resources.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MCP_Resources {
	private const MAX_LIST_ITEMS   = 50;
	private const MAX_CONTENT_CHARS = 60000;

	private Resource_Registry $resources;
	private ?Flatsome_Component_Catalog $catalog;

	public function __construct( Resource_Registry $resources, ?Flatsome_Component_Catalog $catalog = null ) {
		$this->resources = $resources;
		$this->catalog   = $catalog;
	}

	public function register(): void {
		$this->resources->register(
			'mindio://site/profile',
			'site-profile',
			__( 'Site profile', 'mindio-magic-mcp' ),
			__( 'Identity, locale, active theme, editing surface, and brand voice for this WordPress site.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'site_profile' )
		);

		$this->resources->register(
			'mindio://site/post-types',
			'site-post-types',
			__( 'Post types', 'mindio-magic-mcp' ),
			__( 'Every public post type with its labels, supported features, and taxonomies.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'post_types' )
		);

		$this->resources->register(
			'mindio://site/taxonomies',
			'site-taxonomies',
			__( 'Taxonomies', 'mindio-magic-mcp' ),
			__( 'Every public taxonomy with its labels, hierarchy, and attached post types.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'taxonomies' )
		);

		$this->resources->register(
			'mindio://site/templates',
			'site-templates',
			__( 'Page templates', 'mindio-magic-mcp' ),
			__( 'Page templates published by the active theme, usable as the template argument on page writes.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'templates' )
		);

		$this->resources->register(
			'mindio://site/menus',
			'site-menus',
			__( 'Navigation menus', 'mindio-magic-mcp' ),
			__( 'Registered menu locations and the menus assigned to them.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'menus' )
		);

		$this->resources->register(
			'mindio://flatsome/components',
			'flatsome-components',
			__( 'Flatsome component catalog', 'mindio-magic-mcp' ),
			__( 'Typed UX Builder component catalog with availability and dependency reporting.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'flatsome_components' )
		);

		$this->resources->register_template(
			'mindio://post/{id}',
			'post',
			__( 'Post or page', 'mindio-magic-mcp' ),
			__( 'One post, page, or custom post type entry with its content, taxonomies, and plugin-visible meta.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'post' )
		);

		$this->resources->register_template(
			'mindio://media/{id}',
			'media',
			__( 'Media item', 'mindio-magic-mcp' ),
			__( 'One attachment with its MIME type, dimensions, alternative text, and generated sizes.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'media' )
		);

		$this->resources->register_template(
			'mindio://posts/{post_type}',
			'post-collection',
			__( 'Recent entries by post type', 'mindio-magic-mcp' ),
			__( 'The most recently modified entries for one post type, newest first.', 'mindio-magic-mcp' ),
			'application/json',
			array( $this, 'post_collection' )
		);
	}

	/** @return array<string,mixed> */
	public function site_profile(): array {
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		$theme    = wp_get_theme();

		return array(
			'name'             => get_bloginfo( 'name' ),
			'tagline'          => get_bloginfo( 'description' ),
			'url'              => home_url( '/' ),
			'locale'           => determine_locale(),
			'is_rtl'           => is_rtl(),
			'timezone'         => wp_timezone_string(),
			'date_format'      => (string) get_option( 'date_format' ),
			'page_on_front'    => (int) get_option( 'page_on_front' ),
			'posts_per_page'   => (int) get_option( 'posts_per_page' ),
			'theme'            => array(
				'name'     => $theme->get( 'Name' ),
				'version'  => $theme->get( 'Version' ),
				'template' => $theme->get_template(),
				'is_block' => wp_is_block_theme(),
			),
			'builder'          => $this->builder_summary(),
			'brand_voice'      => (string) ( $settings['brand_voice'] ?? '' ),
			'woocommerce'      => class_exists( 'WooCommerce' ),
			'public_post_types' => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function post_types(): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$types[] = array(
				'name'         => $type->name,
				'label'        => $type->labels->name ?? $type->name,
				'singular'     => $type->labels->singular_name ?? $type->name,
				'hierarchical' => (bool) $type->hierarchical,
				'supports'     => array_keys( get_all_post_type_supports( $type->name ) ),
				'taxonomies'   => array_values( get_object_taxonomies( $type->name ) ),
				'rest_base'    => $type->rest_base ?: $type->name,
				'count'        => (int) ( wp_count_posts( $type->name )->publish ?? 0 ),
			);
		}

		return $types;
	}

	/** @return array<int,array<string,mixed>> */
	public function taxonomies(): array {
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$taxonomies[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->labels->name ?? $taxonomy->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'post_types'   => array_values( (array) $taxonomy->object_type ),
				'terms'        => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) ),
			);
		}

		return $taxonomies;
	}

	/** @return array<string,mixed> */
	public function templates(): array {
		$theme = wp_get_theme();

		return array(
			'page'      => $theme->get_page_templates( null, 'page' ),
			'post'      => $theme->get_page_templates( null, 'post' ),
			'is_block_theme' => wp_is_block_theme(),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function menus(): array {
		$locations = get_nav_menu_locations();
		$registered = get_registered_nav_menus();
		$menus     = array();

		foreach ( $registered as $location => $label ) {
			$menu_id = (int) ( $locations[ $location ] ?? 0 );
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
			$menus[] = array(
				'location' => $location,
				'label'    => $label,
				'menu_id'  => $menu_id,
				'menu'     => $menu ? $menu->name : '',
				'items'    => $menu ? (int) $menu->count : 0,
			);
		}

		return $menus;
	}

	/** @return array<string,mixed> */
	public function flatsome_components(): array {
		if ( ! $this->catalog instanceof Flatsome_Component_Catalog ) {
			return array( 'available' => false, 'types' => array() );
		}

		$types = array();
		foreach ( $this->catalog->types() as $type ) {
			$definition = $this->catalog->get( $type );
			$types[]    = array(
				'type'        => $type,
				'available'   => $this->catalog->available( $type ),
				'description' => (string) ( $definition['description'] ?? '' ),
				'missing_shortcodes' => $this->catalog->missing_shortcodes( $type ),
			);
		}

		return array(
			'available'        => $this->catalog->flatsome_active(),
			'flatsome_version' => $this->catalog->flatsome_version(),
			'types'            => $types,
		);
	}

	/**
	 * @param array<string,string> $variables
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post( array $variables ) {
		$post = get_post( absint( $variables['id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'unknown_resource', __( 'The requested post does not exist.', 'mindio-magic-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot read this post.', 'mindio-magic-mcp' ) );
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$assigned = get_the_terms( $post, $taxonomy );
			if ( is_array( $assigned ) ) {
				$terms[ $taxonomy ] = wp_list_pluck( $assigned, 'name' );
			}
		}

		return array(
			'id'            => $post->ID,
			'type'          => $post->post_type,
			'status'        => $post->post_status,
			'title'         => $post->post_title,
			'slug'          => $post->post_name,
			'permalink'     => (string) get_permalink( $post ),
			'excerpt'       => $post->post_excerpt,
			'content'       => mb_substr( $post->post_content, 0, self::MAX_CONTENT_CHARS ),
			'truncated'     => mb_strlen( $post->post_content ) > self::MAX_CONTENT_CHARS,
			'author'        => (int) $post->post_author,
			'parent'        => (int) $post->post_parent,
			'template'      => (string) get_page_template_slug( $post ),
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			'modified_gmt'  => $post->post_modified_gmt,
			'editing_surface' => $this->editing_surface( $post ),
			'terms'         => $terms,
		);
	}

	/**
	 * @param array<string,string> $variables
	 * @return array<string,mixed>|\WP_Error
	 */
	public function media( array $variables ) {
		$id = absint( $variables['id'] ?? 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'unknown_resource', __( 'The requested media item does not exist.', 'mindio-magic-mcp' ) );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot read this media item.', 'mindio-magic-mcp' ) );
		}

		$metadata = (array) wp_get_attachment_metadata( $id );

		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'url'       => (string) wp_get_attachment_url( $id ),
			'mime_type' => (string) get_post_mime_type( $id ),
			'alt_text'  => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'   => (string) wp_get_attachment_caption( $id ),
			'width'     => (int) ( $metadata['width'] ?? 0 ),
			'height'    => (int) ( $metadata['height'] ?? 0 ),
			'filesize'  => (int) ( $metadata['filesize'] ?? 0 ),
			'sizes'     => array_keys( (array) ( $metadata['sizes'] ?? array() ) ),
		);
	}

	/**
	 * @param array<string,string> $variables
	 * @return array<string,mixed>|\WP_Error
	 */
	public function post_collection( array $variables ) {
		$post_type = sanitize_key( $variables['post_type'] ?? '' );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'unknown_resource', __( 'The requested post type does not exist.', 'mindio-magic-mcp' ) );
		}
		$type_object = get_post_type_object( $post_type );
		if ( ! $type_object || empty( $type_object->public ) ) {
			return new \WP_Error( 'unknown_resource', __( 'The requested post type is not public.', 'mindio-magic-mcp' ) );
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => self::MAX_LIST_ITEMS,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'           => $post->ID,
				'uri'          => 'mindio://post/' . $post->ID,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'permalink'    => (string) get_permalink( $post ),
				'modified_gmt' => $post->post_modified_gmt,
			);
		}

		return array(
			'post_type' => $post_type,
			'limit'     => self::MAX_LIST_ITEMS,
			'count'     => count( $items ),
			'items'     => $items,
		);
	}

	/** @return array<string,mixed> */
	private function builder_summary(): array {
		$flatsome = $this->catalog instanceof Flatsome_Component_Catalog && $this->catalog->flatsome_active();

		return array(
			'flatsome'  => $flatsome,
			'gutenberg' => true,
			'preferred' => $flatsome ? 'flatsome' : 'gutenberg',
		);
	}

	private function editing_surface( \WP_Post $post ): string {
		if ( str_contains( $post->post_content, '[section' ) || str_contains( $post->post_content, '[row' ) ) {
			return 'flatsome';
		}
		if ( str_contains( $post->post_content, '<!-- wp:' ) ) {
			return 'gutenberg';
		}

		return 'classic';
	}
}
