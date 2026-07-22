<?php
/**
 * Flatsome UX Builder page-generation tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Flatsome_Tools {
	private const MAX_LAYOUT_NODES = 2000;
	private const MAX_LAYOUT_BYTES = 4 * MB_IN_BYTES;

	private Tool_Registry $registry;
	private Flatsome_Renderer $renderer;
	private Flatsome_Component_Catalog $catalog;

	public function __construct( Tool_Registry $registry, Flatsome_Renderer $renderer, Flatsome_Component_Catalog $catalog ) {
		$this->registry = $registry;
		$this->renderer = $renderer;
		$this->catalog  = $catalog;
	}

	public function register(): void {
		$this->registry->register(
			'list_flatsome_components',
			__( 'List native-first Flatsome component types, shortcode availability, dependencies, container rules, and the active Flatsome version.', 'mindio-magic-mcp' ),
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			array( 'type' => 'object' ),
			array( $this, 'list_components' ),
			Auth::SCOPE_READ,
			static fn( array $args ): bool => current_user_can( 'read' ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'create_flatsome_page',
			__( 'Create a production-ready Flatsome UX Builder page from declarative sections, responsive rows, columns, and native-first typed components.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'title'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
					'slug'      => array( 'type' => 'string', 'maxLength' => 200 ),
					'status'    => array( 'type' => 'string', 'enum' => array( 'draft', 'private', 'publish' ) ),
					'template'  => array( 'type' => 'string', 'enum' => array( 'default', 'page-blank.php', 'page-blank-landingpage.php', 'page-transparent-header.php', 'page-transparent-header-light.php' ) ),
					'direction' => array( 'type' => 'string', 'enum' => array( 'auto', 'ltr', 'rtl' ) ),
					'content_locale' => array( 'type' => 'string', 'maxLength' => 20 ),
					'sections'  => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $this->section_schema() ),
				),
				'required'             => array( 'title', 'sections' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'create_page' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_create_page' )
		);
		$this->registry->register(
			'get_flatsome_page',
			__( 'Read a Flatsome page and its stable section, row, and column IDs before making incremental edits.', 'mindio-magic-mcp' ),
			$this->post_id_schema(),
			array( 'type' => 'object' ),
			array( $this, 'get_page' ),
			Auth::SCOPE_READ,
			fn( array $args ): bool => current_user_can( 'read_post', absint( $args['post_id'] ?? 0 ) ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'add_section',
			__( 'Append one safe UX Builder section to an existing Flatsome page and return all new stable node IDs.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'              => array( 'type' => 'integer', 'minimum' => 1 ),
					'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
					'section'              => $this->section_schema(),
				),
				'required'             => array( 'post_id', 'section' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'add_section' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) )
		);
		$this->registry->register(
			'add_row',
			__( 'Insert one responsive UX Builder row into a section selected by its stable section_id.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'              => array( 'type' => 'integer', 'minimum' => 1 ),
					'section_id'           => array( 'type' => 'string', 'pattern' => '^fmp-section-[a-z0-9-]{6,64}$' ),
					'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
					'row'                  => $this->row_schema(),
				),
				'required'             => array( 'post_id', 'section_id', 'row' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'add_row' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) )
		);
		$this->registry->register(
			'add_element',
			__( 'Insert one native-first typed Flatsome component into a UX Builder column selected by stable column_id.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'              => array( 'type' => 'integer', 'minimum' => 1 ),
					'column_id'            => array( 'type' => 'string', 'pattern' => '^fmp-col-[a-z0-9-]{6,64}$' ),
					'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
					'element'              => $this->element_schema(),
				),
				'required'             => array( 'post_id', 'column_id', 'element' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'add_element' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) )
		);
	}

	/** @return array<string,mixed> */
	public function list_components( array $args = array() ): array {
		return $this->catalog->discovery();
	}

	public function can_create_page( array $args ): bool {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return false;
		}
		return ! in_array( $args['status'] ?? 'draft', array( 'publish', 'private' ), true ) || current_user_can( 'publish_pages' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_page( array $args ): array|\WP_Error {
		$active = $this->ensure_flatsome();
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		$complexity = $this->validate_layout_complexity( (array) $args['sections'] );
		if ( is_wp_error( $complexity ) ) {
			return $complexity;
		}
		$media = $this->validate_media( $args['sections'] );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$direction = $this->direction( (string) ( $args['direction'] ?? 'auto' ), (string) ( $args['content_locale'] ?? determine_locale() ) );
		$rendered  = $this->renderer->render_page( (array) $args['sections'], $direction );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}
		if ( $this->has_duplicate_ids( $rendered['node_ids'] ) ) {
			return new \WP_Error( 'duplicate_node_id', __( 'Every custom UX Builder node id must be unique within the page.', 'mindio-magic-mcp' ) );
		}
		if ( strlen( $rendered['content'] ) > self::MAX_LAYOUT_BYTES ) {
			return new \WP_Error( 'layout_too_large', __( 'The generated UX Builder layout exceeds the 4 MB content limit.', 'mindio-magic-mcp' ) );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_title'   => sanitize_text_field( (string) $args['title'] ),
					'post_name'    => sanitize_title( (string) ( $args['slug'] ?? '' ) ),
					'post_status'  => sanitize_key( (string) ( $args['status'] ?? 'draft' ) ),
					'post_content' => $rendered['content'],
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_wp_page_template', sanitize_file_name( (string) ( $args['template'] ?? 'page-blank.php' ) ) );
		update_post_meta( $post_id, '_mindio_magic_mcp_managed', MINDIO_MAGIC_MCP_VERSION );
		update_post_meta( $post_id, '_mindio_magic_mcp_direction', $direction );
		update_post_meta( $post_id, '_mindio_magic_mcp_content_locale', sanitize_locale_name( (string) ( $args['content_locale'] ?? determine_locale() ) ) );

		return $this->page_result( (int) $post_id, $rendered['node_ids'], $rendered['render_report'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_page( array $args ): array|\WP_Error {
		$post = $this->page( absint( $args['post_id'] ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return array_merge(
			$this->page_result( $post->ID, $this->extract_node_ids( $post->post_content ) ),
			array(
				'title'     => get_the_title( $post ),
				'status'    => $post->post_status,
				'direction' => get_post_meta( $post->ID, '_mindio_magic_mcp_direction', true ) ?: 'ltr',
				'content'   => $post->post_content,
			)
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function add_section( array $args ): array|\WP_Error {
		$post = $this->page_for_update( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$media = $this->validate_media( $args['section'] );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$complexity = $this->validate_layout_complexity( array( (array) $args['section'] ) );
		if ( is_wp_error( $complexity ) ) {
			return $complexity;
		}
		$rendered = $this->renderer->render_section_fragment( (array) $args['section'], $this->post_direction( $post->ID ) );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}
		$duplicate = $this->ensure_new_ids( $post->post_content, $rendered['node_ids'] );
		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}
		$content = rtrim( $post->post_content ) . "\n\n" . $rendered['content'];
		return $this->save_layout( $post, $content, $rendered['node_ids'], $rendered['render_report'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function add_row( array $args ): array|\WP_Error {
		$post = $this->page_for_update( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$media = $this->validate_media( $args['row'] );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$rendered = $this->renderer->render_row_fragment( (array) $args['row'], $this->post_direction( $post->ID ) );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}
		$duplicate = $this->ensure_new_ids( $post->post_content, $rendered['node_ids'] );
		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}
		$content = $this->renderer->insert_into_node( $post->post_content, 'section', sanitize_html_class( (string) $args['section_id'] ), $rendered['content'] );
		return is_wp_error( $content ) ? $content : $this->save_layout( $post, $content, $rendered['node_ids'], $rendered['render_report'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function add_element( array $args ): array|\WP_Error {
		$post = $this->page_for_update( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$media = $this->validate_media( $args['element'] );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$rendered = $this->renderer->render_element_fragment( (array) $args['element'], $this->post_direction( $post->ID ) );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}
		$duplicate = $this->ensure_new_ids( $post->post_content, $rendered['node_ids'] );
		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}
		$content = $this->renderer->insert_into_node( $post->post_content, 'col', sanitize_html_class( (string) $args['column_id'] ), $rendered['content'] );
		return is_wp_error( $content ) ? $content : $this->save_layout( $post, $content, $rendered['node_ids'], $rendered['render_report'] );
	}

	private function section_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'                  => array( 'type' => 'string', 'maxLength' => 80 ),
				'label'               => array( 'type' => 'string', 'maxLength' => 100 ),
				'class'               => array( 'type' => 'string', 'maxLength' => 200 ),
				'background_image_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'background_color'    => array( 'type' => 'string', 'maxLength' => 50 ),
				'background_overlay'  => array( 'type' => 'string', 'maxLength' => 50 ),
				'background_position' => array( 'type' => 'string', 'maxLength' => 30 ),
				'background_size'     => array( 'type' => 'string', 'enum' => array( 'thumbnail', 'medium', 'large', 'full' ) ),
				'dark'                => array( 'type' => 'boolean' ),
				'padding'             => array( 'type' => 'string', 'maxLength' => 100 ),
				'padding_mobile'      => array( 'type' => 'string', 'maxLength' => 100 ),
				'height'              => array( 'type' => 'string', 'maxLength' => 30 ),
				'height_mobile'       => array( 'type' => 'string', 'maxLength' => 30 ),
				'margin_bottom'       => array( 'type' => 'string', 'maxLength' => 30 ),
				'parallax'            => array( 'type' => 'number', 'minimum' => -10, 'maximum' => 10 ),
				'rows'                => array( 'type' => 'array', 'maxItems' => 50, 'items' => $this->row_schema() ),
			),
			'additionalProperties' => false,
		);
	}

	private function row_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'               => array( 'type' => 'string', 'maxLength' => 80 ),
				'label'            => array( 'type' => 'string', 'maxLength' => 100 ),
				'class'            => array( 'type' => 'string', 'maxLength' => 200 ),
				'style'            => array( 'type' => 'string', 'enum' => array( 'small', 'large', 'collapse' ) ),
				'width'            => array( 'type' => 'string', 'enum' => array( 'full-width', 'custom' ) ),
				'vertical_align'   => array( 'type' => 'string', 'enum' => array( 'top', 'middle', 'bottom', 'equal' ) ),
				'horizontal_align' => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
				'column_style'     => array( 'type' => 'string', 'enum' => array( 'divided', 'dashed', 'solid' ) ),
				'padding'          => array( 'type' => 'string', 'maxLength' => 100 ),
				'depth'            => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ),
				'depth_hover'      => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ),
				'columns'          => array( 'type' => 'array', 'maxItems' => 12, 'items' => $this->column_schema() ),
			),
			'additionalProperties' => false,
		);
	}

	private function column_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'               => array( 'type' => 'string', 'maxLength' => 80 ),
				'label'            => array( 'type' => 'string', 'maxLength' => 100 ),
				'class'            => array( 'type' => 'string', 'maxLength' => 200 ),
				'span'             => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ),
				'span_tablet'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ),
				'span_mobile'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ),
				'align'            => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
				'light_text'       => array( 'type' => 'boolean' ),
				'padding'          => array( 'type' => 'string', 'maxLength' => 100 ),
				'padding_mobile'   => array( 'type' => 'string', 'maxLength' => 100 ),
				'margin'           => array( 'type' => 'string', 'maxLength' => 100 ),
				'max_width'        => array( 'type' => 'string', 'maxLength' => 30 ),
				'background_color' => array( 'type' => 'string', 'maxLength' => 50 ),
				'radius'           => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 500 ),
				'depth'            => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ),
				'depth_hover'      => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ),
				'animate'          => array( 'type' => 'string', 'enum' => array( 'fadeInLeft', 'fadeInRight', 'fadeInUp', 'fadeInDown', 'flipInY', 'bounceIn', 'none' ) ),
				'elements'         => array( 'type' => 'array', 'maxItems' => 100, 'items' => $this->element_schema() ),
			),
			'additionalProperties' => false,
		);
	}

	private function element_schema( ?array $types = null ): array {
		$types    = $types ?? $this->catalog->types();
		$variants = array();
		foreach ( $types as $type ) {
			$variants[] = $this->component_variant_schema( (string) $type );
		}
		return array( 'oneOf' => $variants );
	}

	private function component_variant_schema( string $type ): array {
		$properties = array();
		$required   = array();
		switch ( $type ) {
			case 'title':
				$properties = array(
					'text'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
					'tag'           => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4' ) ),
					'style'         => array( 'type' => 'string', 'enum' => array( 'normal', 'center', 'bold', 'bold-center' ) ),
					'color'         => array( 'type' => 'string', 'maxLength' => 50 ),
					'size'          => array( 'type' => 'number', 'minimum' => 20, 'maximum' => 300 ),
					'link'          => array( 'type' => 'string', 'maxLength' => 2048 ),
					'new_tab'       => array( 'type' => 'boolean' ),
					'margin_top'    => array( 'type' => 'string', 'maxLength' => 30 ),
					'margin_bottom' => array( 'type' => 'string', 'maxLength' => 30 ),
				);
				$required = array( 'text' );
				break;
			case 'text':
				$properties = array(
					'content'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200000, 'description' => 'Body copy only: paragraphs, lists, links, emphasis, quotes, and inline text. Use typed components for headings, media, layouts, or shortcodes.' ),
					'align'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
					'font_size'   => array( 'type' => 'number', 'minimum' => 0.5, 'maximum' => 8 ),
					'line_height' => array( 'type' => 'number', 'minimum' => 0.5, 'maximum' => 4 ),
					'text_color'  => array( 'type' => 'string', 'maxLength' => 50 ),
				);
				$required = array( 'content' );
				break;
			case 'image':
				$properties = array_merge( $this->image_properties(), array( 'width' => array( 'type' => 'number', 'minimum' => 1, 'maximum' => 100 ), 'height' => array( 'type' => 'string', 'maxLength' => 30 ), 'depth' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ) ) );
				$required   = array( 'media_id' );
				break;
			case 'button':
				$properties = $this->button_properties();
				$required   = array( 'text' );
				break;
			case 'banner':
				$properties = array_merge(
					$this->image_properties(),
					array(
						'heading'              => array( 'type' => 'string', 'maxLength' => 1000 ),
						'heading_tag'          => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4' ) ),
						'text'                 => array( 'type' => 'string', 'maxLength' => 10000 ),
						'height'               => array( 'type' => 'string', 'maxLength' => 30 ),
						'overlay'              => array( 'type' => 'string', 'maxLength' => 50 ),
						'background_position'  => array( 'type' => 'string', 'maxLength' => 30 ),
						'content_width'        => array( 'type' => 'number', 'minimum' => 10, 'maximum' => 100 ),
						'content_width_mobile' => array( 'type' => 'number', 'minimum' => 10, 'maximum' => 100 ),
						'position_x'           => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 100 ),
						'position_y'           => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 100 ),
						'align'                => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
						'dark_text'            => array( 'type' => 'boolean' ),
						'button_text'          => array( 'type' => 'string', 'maxLength' => 500 ),
						'button_link'          => array( 'type' => 'string', 'maxLength' => 2048 ),
						'button_new_tab'       => array( 'type' => 'boolean' ),
						'button_style'         => array( 'type' => 'string', 'enum' => array( 'outline', 'link', 'underline', 'shade' ) ),
						'button_color'         => array( 'type' => 'string', 'enum' => array( 'primary', 'secondary', 'alert', 'success', 'white' ) ),
					)
				);
				$required = array( 'media_id' );
				break;
			case 'featured_box':
				$properties = array(
					'title'       => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ),
					'subtitle'    => array( 'type' => 'string', 'maxLength' => 1000 ),
					'content'     => array( 'type' => 'string', 'maxLength' => 20000 ),
					'media_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'image_width' => array( 'type' => 'number', 'minimum' => 20, 'maximum' => 600 ),
					'position'    => array( 'type' => 'string', 'enum' => array( 'top', 'center', 'left', 'right' ) ),
					'font_size'   => array( 'type' => 'string', 'enum' => array( 'xsmall', 'small', 'medium', 'large', 'xlarge' ) ),
					'icon_color'  => array( 'type' => 'string', 'maxLength' => 50 ),
					'link'        => array( 'type' => 'string', 'maxLength' => 2048 ),
					'new_tab'     => array( 'type' => 'boolean' ),
				);
				$required = array( 'title' );
				break;
			case 'image_box':
				$properties = array_merge(
					$this->image_properties(),
					array(
						'title'       => array( 'type' => 'string', 'maxLength' => 1000 ),
						'content'     => array( 'type' => 'string', 'maxLength' => 20000 ),
						'style'       => array( 'type' => 'string', 'enum' => array( 'normal', 'overlay', 'shade', 'badge', 'push', 'push-rounded' ) ),
						'align'       => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
						'depth'       => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ),
						'depth_hover' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ),
					)
				);
				$required = array( 'media_id' );
				break;
			case 'message_box':
				$properties = array(
					'background_media_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'background_color'    => array( 'type' => 'string', 'maxLength' => 50 ),
					'text_color'          => array( 'type' => 'string', 'enum' => array( 'dark', 'light' ) ),
					'padding'             => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 200 ),
					'elements'            => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => $this->element_schema( $this->leaf_component_types() ) ),
				);
				$required = array( 'elements' );
				break;
			case 'slider':
				$properties = array(
					'slides'         => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => $this->element_schema( array( 'banner', 'image', 'image_box', 'testimonial', 'html' ) ) ),
					'nav_style'      => array( 'type' => 'string', 'enum' => array( 'circle', 'simple', 'reveal' ) ),
					'nav_color'      => array( 'type' => 'string', 'enum' => array( 'dark', 'light' ) ),
					'nav_position'   => array( 'type' => 'string', 'enum' => array( 'inside', 'outside' ) ),
					'arrows'         => array( 'type' => 'boolean' ),
					'bullets'        => array( 'type' => 'boolean' ),
					'auto_slide'     => array( 'type' => 'integer', 'minimum' => 1000, 'maximum' => 30000 ),
					'pause_on_hover' => array( 'type' => 'boolean' ),
					'free_scroll'    => array( 'type' => 'boolean' ),
				);
				$required = array( 'slides' );
				break;
			case 'banner_grid':
				$properties = array(
					'height'        => array( 'type' => 'string', 'maxLength' => 30 ),
					'height_mobile' => array( 'type' => 'string', 'maxLength' => 30 ),
					'items'         => array(
						'type' => 'array', 'minItems' => 1, 'maxItems' => 20,
						'items' => array(
							'type' => 'object',
							'properties' => array( 'span' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ), 'span_tablet' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ), 'span_mobile' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ), 'height' => array( 'type' => 'number', 'minimum' => 10, 'maximum' => 100 ), 'banner' => $this->element_schema( array( 'banner' ) ) ),
							'required' => array( 'banner' ), 'additionalProperties' => false,
						),
					),
				);
				$required = array( 'items' );
				break;
			case 'accordion':
				$properties = array( 'title' => array( 'type' => 'string', 'maxLength' => 1000 ), 'open_item' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 30 ), 'auto_open' => array( 'type' => 'boolean' ), 'faq_schema' => array( 'type' => 'boolean' ), 'items' => $this->panel_items_schema() );
				$required   = array( 'items' );
				break;
			case 'tabs':
				$properties = array( 'title' => array( 'type' => 'string', 'maxLength' => 1000 ), 'style' => array( 'type' => 'string', 'enum' => array( 'line', 'tabs', 'pills' ) ), 'align' => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ), 'orientation' => array( 'type' => 'string', 'enum' => array( 'horizontal', 'vertical' ) ), 'items' => $this->panel_items_schema() );
				$required   = array( 'items' );
				break;
			case 'gallery':
				$properties = array( 'media_ids' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'columns' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ), 'columns_tablet' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ), 'columns_mobile' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 4 ), 'lightbox' => array( 'type' => 'boolean' ), 'image_size' => $this->image_size_schema() );
				$required   = array( 'media_ids' );
				break;
			case 'video':
				$properties = array( 'url' => array( 'type' => 'string', 'format' => 'uri', 'minLength' => 1, 'maxLength' => 2048 ), 'height' => array( 'type' => 'string', 'maxLength' => 30 ), 'depth' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ), 'depth_hover' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ) );
				$required   = array( 'url' );
				break;
			case 'countdown':
				$properties = array( 'deadline' => array( 'type' => 'string', 'format' => 'date-time' ), 'style' => array( 'type' => 'string', 'enum' => array( 'clock', 'text' ) ), 'size' => array( 'type' => 'number', 'minimum' => 20, 'maximum' => 400 ), 'color' => array( 'type' => 'string', 'enum' => array( 'dark', 'light' ) ), 'background_color' => array( 'type' => 'string', 'maxLength' => 50 ) );
				$required   = array( 'deadline' );
				break;
			case 'testimonial':
				$properties = array( 'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20000 ), 'media_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'image_width' => array( 'type' => 'number', 'minimum' => 20, 'maximum' => 300 ), 'position' => array( 'type' => 'string', 'enum' => array( 'top', 'center', 'left', 'right' ) ), 'name' => array( 'type' => 'string', 'maxLength' => 500 ), 'company' => array( 'type' => 'string', 'maxLength' => 500 ), 'stars' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ), 'font_size' => array( 'type' => 'string', 'enum' => array( 'small', 'medium', 'large' ) ) );
				$required   = array( 'content' );
				break;
			case 'team_member':
				$properties = array( 'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ), 'title' => array( 'type' => 'string', 'maxLength' => 500 ), 'content' => array( 'type' => 'string', 'maxLength' => 20000 ), 'media_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'style' => array( 'type' => 'string', 'enum' => array( 'normal', 'overlay', 'shade', 'badge', 'push', 'push-rounded' ) ), 'depth' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ), 'depth_hover' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ), 'social' => $this->social_links_schema() );
				$required   = array( 'name' );
				break;
			case 'price_table':
				$properties = array( 'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ), 'price' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ), 'description' => array( 'type' => 'string', 'maxLength' => 1000 ), 'featured' => array( 'type' => 'boolean' ), 'light_text' => array( 'type' => 'boolean' ), 'background_color' => array( 'type' => 'string', 'maxLength' => 50 ), 'radius' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 30 ), 'depth' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ), 'depth_hover' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5 ), 'bullets' => array( 'type' => 'array', 'maxItems' => 50, 'items' => array( 'type' => 'object', 'properties' => array( 'text' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ), 'tooltip' => array( 'type' => 'string', 'maxLength' => 1000 ), 'enabled' => array( 'type' => 'boolean' ) ), 'required' => array( 'text' ), 'additionalProperties' => false ) ), 'button' => $this->element_schema( array( 'button' ) ) );
				$required   = array( 'title', 'price' );
				break;
			case 'logo':
				$properties = array_merge( $this->image_properties(), array( 'image_size' => $this->image_size_schema( true ), 'title' => array( 'type' => 'string', 'maxLength' => 500 ), 'height' => array( 'type' => 'integer', 'minimum' => 10, 'maximum' => 300 ), 'padding' => array( 'type' => 'string', 'maxLength' => 30 ) ) );
				$required   = array( 'media_id' );
				break;
			case 'divider':
				$properties = array( 'align' => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ), 'width' => array( 'type' => 'string', 'maxLength' => 30 ), 'height' => array( 'type' => 'number', 'minimum' => 1, 'maximum' => 20 ), 'color' => array( 'type' => 'string', 'maxLength' => 50 ), 'margin' => array( 'type' => 'string', 'maxLength' => 50 ) );
				break;
			case 'gap':
				$properties = array( 'height' => array( 'type' => 'string', 'maxLength' => 30 ), 'height_mobile' => array( 'type' => 'string', 'maxLength' => 30 ) );
				break;
			case 'blog_posts':
				$properties = array_merge( $this->query_properties(), array( 'post_ids' => $this->id_array_schema(), 'category_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'show_excerpt' => array( 'type' => 'boolean' ), 'excerpt_length' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ), 'show_date' => array( 'type' => 'boolean' ) ) );
				break;
			case 'products':
				$properties = array_merge( $this->query_properties(), array( 'product_ids' => $this->id_array_schema(), 'category' => array( 'type' => 'string', 'maxLength' => 200 ), 'tags' => array( 'type' => 'array', 'maxItems' => 50, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'maxLength' => 200 ) ) ) );
				break;
			case 'product_categories':
				$properties = array_merge( $this->query_properties(), array( 'category_ids' => $this->id_array_schema(), 'category' => array( 'type' => 'string', 'maxLength' => 200 ), 'orderby' => array( 'type' => 'string', 'enum' => array( 'menu_order', 'name', 'slug', 'count', 'id' ) ), 'hide_empty' => array( 'type' => 'boolean' ), 'show_count' => array( 'type' => 'boolean' ) ) );
				break;
			case 'follow':
				$properties = array_merge( $this->social_display_properties(), array( 'social' => $this->social_links_schema() ) );
				$required   = array( 'social' );
				break;
			case 'share':
				$properties = $this->social_display_properties();
				break;
			case 'map':
				$properties = array( 'latitude' => array( 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ), 'longitude' => array( 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ), 'height' => array( 'type' => 'string', 'maxLength' => 30 ), 'height_mobile' => array( 'type' => 'string', 'maxLength' => 30 ), 'zoom' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 22 ), 'controls' => array( 'type' => 'boolean' ), 'content' => array( 'type' => 'string', 'maxLength' => 20000 ), 'content_width' => array( 'type' => 'number', 'minimum' => 10, 'maximum' => 100 ) );
				$required   = array( 'latitude', 'longitude' );
				break;
			case 'search':
				$properties = array( 'size' => array( 'type' => 'string', 'enum' => array( 'small', 'normal', 'large' ) ), 'style' => array( 'type' => 'string', 'enum' => array( 'flat', 'minimal' ) ) );
				break;
			case 'html':
				$properties = array( 'html' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200000, 'description' => 'Sanitized last-resort HTML for a design that no supported native component can represent.' ), 'reason' => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'Explain why no native component can represent this content.' ) );
				$required   = array( 'html' );
				break;
			default:
				return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
		}

		$common = array(
			'type'  => array( 'type' => 'string', 'enum' => array( $type ) ),
			'id'    => array( 'type' => 'string', 'maxLength' => 80 ),
			'label' => array( 'type' => 'string', 'maxLength' => 100 ),
			'class' => array( 'type' => 'string', 'maxLength' => 200 ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array_merge( $common, $properties ),
			'required'             => array_merge( array( 'type' ), $required ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function image_properties(): array {
		return array(
			'media_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
			'image_size' => $this->image_size_schema(),
			'link'       => array( 'type' => 'string', 'maxLength' => 2048 ),
			'new_tab'    => array( 'type' => 'boolean' ),
			'hover'      => array( 'type' => 'string', 'enum' => array( 'zoom', 'zoom-fade', 'blur', 'fade-in', 'fade-out', 'color', 'grayscale', 'glow' ) ),
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function button_properties(): array {
		return array(
			'text'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
			'link'          => array( 'type' => 'string', 'maxLength' => 2048 ),
			'new_tab'       => array( 'type' => 'boolean' ),
			'color'         => array( 'type' => 'string', 'enum' => array( 'primary', 'secondary', 'alert', 'success', 'white' ) ),
			'style'         => array( 'type' => 'string', 'enum' => array( 'outline', 'link', 'underline', 'shade' ) ),
			'size'          => array( 'type' => 'string', 'enum' => array( 'smaller', 'small', 'medium', 'large', 'larger', 'xlarge' ) ),
			'radius'        => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 99 ),
			'icon'          => array( 'type' => 'string', 'pattern' => '^icon-[a-z0-9-]{1,40}$' ),
			'icon_position' => array( 'type' => 'string', 'enum' => array( 'left', 'right' ) ),
		);
	}

	private function image_size_schema( bool $allow_original = false ): array {
		$sizes = array( 'thumbnail', 'medium', 'large', 'full' );
		if ( $allow_original ) {
			$sizes[] = 'original';
		}
		return array( 'type' => 'string', 'enum' => $sizes );
	}

	private function id_array_schema(): array {
		return array( 'type' => 'array', 'maxItems' => 100, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) );
	}

	/** @return array<string,array<string,mixed>> */
	private function query_properties(): array {
		return array(
			'limit'          => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
			'columns'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ),
			'columns_tablet' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 8 ),
			'columns_mobile' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 4 ),
			'layout'         => array( 'type' => 'string', 'enum' => array( 'slider', 'row', 'masonry', 'grid' ) ),
			'style'          => array( 'type' => 'string', 'enum' => array( 'normal', 'overlay', 'shade', 'badge', 'bounce', 'push' ) ),
			'orderby'        => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'menu_order', 'rand', 'id', 'price', 'sales', 'rating', 'name', 'slug', 'count' ) ),
			'order'          => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
		);
	}

	private function panel_items_schema(): array {
		return array(
			'type' => 'array', 'minItems' => 1, 'maxItems' => 30,
			'items' => array(
				'type' => 'object',
				'properties' => array( 'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000 ), 'anchor' => array( 'type' => 'string', 'maxLength' => 200 ), 'elements' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => $this->element_schema( $this->leaf_component_types() ) ) ),
				'required' => array( 'title', 'elements' ), 'additionalProperties' => false,
			),
		);
	}

	/** @return string[] */
	private function leaf_component_types(): array {
		return array( 'title', 'text', 'image', 'button', 'featured_box', 'image_box', 'video', 'testimonial', 'divider', 'gap', 'html' );
	}

	private function social_links_schema(): array {
		$properties = array();
		foreach ( array( 'facebook', 'instagram', 'tiktok', 'snapchat', 'x', 'twitter', 'threads', 'linkedin', 'email', 'phone', 'pinterest', 'rss', 'youtube', 'flickr', 'vkontakte', 'px500', 'telegram', 'discord', 'twitch' ) as $network ) {
			$properties[ $network ] = array( 'type' => 'string', 'maxLength' => 2048 );
		}
		return array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
	}

	/** @return array<string,array<string,mixed>> */
	private function social_display_properties(): array {
		return array( 'title' => array( 'type' => 'string', 'maxLength' => 500 ), 'style' => array( 'type' => 'string', 'enum' => array( 'outline', 'fill', 'small' ) ), 'align' => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ), 'scale' => array( 'type' => 'number', 'minimum' => 50, 'maximum' => 300 ), 'tooltips' => array( 'type' => 'boolean' ) );
	}

	private function post_id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return true|\WP_Error */
	private function ensure_flatsome(): bool|\WP_Error {
		return 'flatsome' === get_template()
			? true
			: new \WP_Error( 'flatsome_inactive', __( 'The Flatsome theme (or a Flatsome child theme) must be active for UX Builder tools.', 'mindio-magic-mcp' ) );
	}

	/** @return \WP_Post|\WP_Error */
	private function page( int $post_id ): \WP_Post|\WP_Error {
		$post = get_post( $post_id );
		return $post && 'page' === $post->post_type ? $post : new \WP_Error( 'page_not_found', __( 'The Flatsome page was not found.', 'mindio-magic-mcp' ) );
	}

	/** @return \WP_Post|\WP_Error */
	private function page_for_update( array $args ): \WP_Post|\WP_Error {
		$active = $this->ensure_flatsome();
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		$post = $this->page( absint( $args['post_id'] ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! empty( $args['expected_modified_gmt'] ) ) {
			$actual = $this->post_modified_gmt( $post );
			if ( ! hash_equals( $actual, (string) $args['expected_modified_gmt'] ) ) {
				return new \WP_Error( 'edit_conflict', __( 'The page changed since it was read. Fetch it again before editing.', 'mindio-magic-mcp' ) );
			}
		}
		return $post;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function save_layout( \WP_Post $post, string $content, array $new_nodes, array $render_report ): array|\WP_Error {
		if ( strlen( $content ) > self::MAX_LAYOUT_BYTES ) {
			return new \WP_Error( 'layout_too_large', __( 'The UX Builder page exceeds the 4 MB content limit.', 'mindio-magic-mcp' ) );
		}
		$managed_nodes = $this->extract_node_ids( $content );
		if ( count( array_merge( ...array_values( $managed_nodes ) ) ) > self::MAX_LAYOUT_NODES ) {
			return new \WP_Error( 'layout_too_complex', __( 'A Flatsome layout may contain at most 2,000 managed nodes.', 'mindio-magic-mcp' ) );
		}
		wp_save_post_revision( $post->ID );
		$result = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $content ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		do_action( 'mindio_magic_mcp_layout_updated', $post->ID, $new_nodes );
		return $this->page_result( $post->ID, $new_nodes, $render_report );
	}

	/** @return array<string,mixed> */
	private function page_result( int $post_id, array $nodes, ?array $render_report = null ): array {
		$post = get_post( $post_id );
		$result = array(
			'post_id'         => $post_id,
			'url'             => get_permalink( $post_id ) ?: '',
			'edit_url'        => get_edit_post_link( $post_id, 'raw' ) ?: '',
			'ux_builder_url'  => function_exists( 'ux_builder_edit_url' ) ? ux_builder_edit_url( $post_id ) : get_edit_post_link( $post_id, 'raw' ),
			'modified_gmt'    => $post ? $this->post_modified_gmt( $post ) : '',
			'new_node_ids'    => $nodes,
			'flatsome_active' => 'flatsome' === get_template(),
		);
		if ( null !== $render_report ) {
			$result['render_report'] = $render_report;
		}
		return $result;
	}

	private function direction( string $requested, string $locale ): string {
		if ( in_array( $requested, array( 'ltr', 'rtl' ), true ) ) {
			return $requested;
		}
		$language = strtolower( strtok( str_replace( '-', '_', $locale ), '_' ) ?: '' );
		return in_array( $language, array( 'ar', 'ckb', 'dv', 'fa', 'he', 'ku', 'ps', 'ur', 'yi' ), true ) ? 'rtl' : 'ltr';
	}

	private function post_direction( int $post_id ): string {
		$direction = get_post_meta( $post_id, '_mindio_magic_mcp_direction', true );
		return in_array( $direction, array( 'ltr', 'rtl' ), true ) ? $direction : ( is_rtl() ? 'rtl' : 'ltr' );
	}

	private function post_modified_gmt( \WP_Post $post ): string {
		$date = get_post_datetime( $post, 'modified' );
		return $date ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : '';
	}

	/** @return bool|\WP_Error */
	private function validate_layout_complexity( array $sections ): bool|\WP_Error {
		$count = count( $sections );
		foreach ( $sections as $section ) {
			$rows   = (array) ( $section['rows'] ?? array() );
			$count += count( $rows );
			foreach ( $rows as $row ) {
				$columns = (array) ( $row['columns'] ?? array() );
				$count  += count( $columns );
				foreach ( $columns as $column ) {
					foreach ( (array) ( $column['elements'] ?? array() ) as $element ) {
						$result = $this->count_component_nodes( (array) $element, 0, $count );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}
					if ( $count > self::MAX_LAYOUT_NODES ) {
						return new \WP_Error( 'layout_too_complex', __( 'A Flatsome layout may contain at most 2,000 sections, rows, columns, and elements combined.', 'mindio-magic-mcp' ) );
					}
				}
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function count_component_nodes( array $component, int $depth, int &$count ): bool|\WP_Error {
		if ( $depth > 10 ) {
			return new \WP_Error( 'component_nesting_too_deep', __( 'Flatsome components may be nested at most 10 levels deep.', 'mindio-magic-mcp' ) );
		}
		++$count;
		if ( $count > self::MAX_LAYOUT_NODES ) {
			return new \WP_Error( 'layout_too_complex', __( 'A Flatsome layout may contain at most 2,000 sections, rows, columns, and components combined.', 'mindio-magic-mcp' ) );
		}

		foreach ( array( 'elements', 'slides' ) as $key ) {
			foreach ( (array) ( $component[ $key ] ?? array() ) as $child ) {
				$result = $this->count_component_nodes( (array) $child, $depth + 1, $count );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		foreach ( (array) ( $component['items'] ?? array() ) as $item ) {
			if ( ! empty( $item['banner'] ) ) {
				$result = $this->count_component_nodes( (array) $item['banner'], $depth + 1, $count );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			foreach ( (array) ( $item['elements'] ?? array() ) as $child ) {
				$result = $this->count_component_nodes( (array) $child, $depth + 1, $count );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function validate_media( mixed $value ): bool|\WP_Error {
		if ( ! is_array( $value ) ) {
			return true;
		}
		foreach ( $value as $key => $child ) {
			if ( in_array( (string) $key, array( 'media_id', 'background_image_id', 'background_media_id' ), true ) && absint( $child ) > 0 && ! wp_attachment_is_image( absint( $child ) ) ) {
					return new \WP_Error(
						'invalid_media_id',
						sprintf(
							/* translators: %d: WordPress media attachment ID. */
							__( 'Media ID %d is not an image attachment.', 'mindio-magic-mcp' ),
							absint( $child )
						)
					);
			}
			if ( 'media_ids' === (string) $key && is_array( $child ) ) {
				foreach ( $child as $media_id ) {
					if ( absint( $media_id ) > 0 && ! wp_attachment_is_image( absint( $media_id ) ) ) {
							return new \WP_Error(
								'invalid_media_id',
								sprintf(
									/* translators: %d: WordPress media attachment ID. */
									__( 'Media ID %d is not an image attachment.', 'mindio-magic-mcp' ),
									absint( $media_id )
								)
							);
					}
				}
			}
			$result = $this->validate_media( $child );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	private function has_duplicate_ids( array $nodes ): bool {
		$flat = array_merge( ...array_values( $nodes ) );
		return count( $flat ) !== count( array_unique( $flat ) );
	}

	/** @return true|\WP_Error */
	private function ensure_new_ids( string $content, array $nodes ): bool|\WP_Error {
		if ( $this->has_duplicate_ids( $nodes ) ) {
			return new \WP_Error( 'duplicate_node_id', __( 'The new fragment contains duplicate node IDs.', 'mindio-magic-mcp' ) );
		}
		foreach ( array_merge( ...array_values( $nodes ) ) as $id ) {
			if ( str_contains( $content, '_id="' . $id . '"' ) || str_contains( $content, 'fmp-node-' . $id ) ) {
				return new \WP_Error(
					'duplicate_node_id',
					sprintf(
						/* translators: %s: Flatsome UX Builder node ID. */
						__( 'Node ID %s already exists on the page.', 'mindio-magic-mcp' ),
						$id
					)
				);
			}
		}
		return true;
	}

	/** @return array<string,string[]> */
	private function extract_node_ids( string $content ): array {
		$nodes = array( 'sections' => array(), 'rows' => array(), 'columns' => array(), 'elements' => array() );
		foreach ( array( 'section' => 'sections', 'row' => 'rows', 'col' => 'columns' ) as $tag => $group ) {
			preg_match_all( '/\[' . $tag . '\b[^\]]*\b_id="(fmp-[^"]+)"/i', $content, $matches );
			$nodes[ $group ] = array_values( array_unique( $matches[1] ?? array() ) );
		}
		preg_match_all( '/fmp-node-(fmp-[a-z]+-[a-z0-9-]{6,64})/', $content, $matches );
		$nodes['elements'] = array_values( array_unique( $matches[1] ?? array() ) );
		return $nodes;
	}
}
