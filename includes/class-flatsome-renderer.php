<?php
/**
 * Safe native-first renderer for Flatsome UX Builder shortcodes.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Flatsome_Renderer {
	private const MAX_NESTING_DEPTH = 10;

	private Flatsome_Component_Catalog $catalog;

	public function __construct( Flatsome_Component_Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	/** @return array{content:string,node_ids:array<string,string[]>,render_report:array<string,mixed>}|\WP_Error */
	public function render_page( array $sections, string $direction = 'ltr' ): array|\WP_Error {
		$nodes   = $this->empty_nodes();
		$report  = $this->empty_report();
		$content = array();
		foreach ( $sections as $section ) {
			$rendered = $this->render_section( (array) $section, $direction, $nodes, $report );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$content[] = $rendered;
		}
		return $this->result( implode( "\n\n", $content ), $nodes, $report );
	}

	/** @return array{content:string,node_ids:array<string,string[]>,render_report:array<string,mixed>}|\WP_Error */
	public function render_section_fragment( array $section, string $direction = 'ltr' ): array|\WP_Error {
		$nodes    = $this->empty_nodes();
		$report   = $this->empty_report();
		$rendered = $this->render_section( $section, $direction, $nodes, $report );
		return is_wp_error( $rendered ) ? $rendered : $this->result( $rendered, $nodes, $report );
	}

	/** @return array{content:string,node_ids:array<string,string[]>,render_report:array<string,mixed>}|\WP_Error */
	public function render_row_fragment( array $row, string $direction = 'ltr' ): array|\WP_Error {
		$nodes    = $this->empty_nodes();
		$report   = $this->empty_report();
		$rendered = $this->render_row( $row, $direction, $nodes, $report );
		return is_wp_error( $rendered ) ? $rendered : $this->result( $rendered, $nodes, $report );
	}

	/** @return array{content:string,node_ids:array<string,string[]>,render_report:array<string,mixed>}|\WP_Error */
	public function render_element_fragment( array $element, string $direction = 'ltr' ): array|\WP_Error {
		$nodes    = $this->empty_nodes();
		$report   = $this->empty_report();
		$rendered = $this->render_element( $element, $direction, $nodes, $report );
		return is_wp_error( $rendered ) ? $rendered : $this->result( $rendered, $nodes, $report );
	}

	/** @return string|\WP_Error */
	public function insert_into_node( string $content, string $tag, string $node_id, string $fragment ): string|\WP_Error {
		if ( ! in_array( $tag, array( 'section', 'row', 'col' ), true ) || ! preg_match( '/^fmp-[a-z]+-[a-z0-9-]{6,64}$/', $node_id ) ) {
			return new \WP_Error( 'invalid_node', __( 'The target node is invalid.', 'mindio-magic-mcp' ) );
		}

		$open_pattern = '/\[' . preg_quote( $tag, '/' ) . '\b[^\]]*\b_id=(?:"|\')' . preg_quote( $node_id, '/' ) . '(?:"|\')[^\]]*\]/i';
		if ( 1 !== preg_match( $open_pattern, $content, $opening, PREG_OFFSET_CAPTURE ) ) {
			return new \WP_Error( 'node_not_found', __( 'The target UX Builder node was not found. Refresh the page structure and retry.', 'mindio-magic-mcp' ) );
		}

		$start   = (int) $opening[0][1];
		$pattern = '/\[(\/?)' . preg_quote( $tag, '/' ) . '\b[^\]]*\]/i';
		if ( false === preg_match_all( $pattern, $content, $tokens, PREG_OFFSET_CAPTURE, $start ) ) {
			return new \WP_Error( 'malformed_layout', __( 'The UX Builder layout could not be parsed.', 'mindio-magic-mcp' ) );
		}
		$depth = 0;
		foreach ( $tokens[0] as $index => $token ) {
			$is_close = '/' === $tokens[1][ $index ][0];
			$depth    += $is_close ? -1 : 1;
			if ( $is_close && 0 === $depth ) {
				$position = (int) $token[1];
				return substr( $content, 0, $position ) . "\n" . trim( $fragment ) . "\n" . substr( $content, $position );
			}
		}

		return new \WP_Error( 'malformed_layout', __( 'The target UX Builder node has no matching closing shortcode.', 'mindio-magic-mcp' ) );
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_section( array $section, string $direction, array &$nodes, array &$report ): string|\WP_Error {
		$id                  = $this->node_id( 'section', (string) ( $section['id'] ?? '' ) );
		$nodes['sections'][] = $id;
		$classes             = $this->classes( (string) ( $section['class'] ?? '' ) );
		if ( 'rtl' === $direction ) {
			$classes[] = 'fmp-rtl';
		}
		$attrs = array(
			'_id'         => $id,
			'label'       => $this->text_attr( (string) ( $section['label'] ?? '' ) ),
			'class'       => implode( ' ', array_unique( $classes ) ),
			'bg'          => $this->media_id( $section['background_image_id'] ?? 0 ),
			'bg_color'    => $this->color( (string) ( $section['background_color'] ?? '' ) ),
			'bg_overlay'  => $this->color( (string) ( $section['background_overlay'] ?? '' ) ),
			'bg_pos'      => $this->position( (string) ( $section['background_position'] ?? '' ) ),
			'bg_size'     => $this->allowed( (string) ( $section['background_size'] ?? '' ), array( 'thumbnail', 'medium', 'large', 'full' ) ),
			'dark'        => ! empty( $section['dark'] ) ? 'true' : '',
			'padding'     => $this->dimension( (string) ( $section['padding'] ?? '' ) ),
			'padding__sm' => $this->dimension( (string) ( $section['padding_mobile'] ?? '' ) ),
			'height'      => $this->dimension( (string) ( $section['height'] ?? '' ) ),
			'height__sm'  => $this->dimension( (string) ( $section['height_mobile'] ?? '' ) ),
			'margin'      => $this->dimension( (string) ( $section['margin_bottom'] ?? '' ) ),
			'parallax'    => $this->number_range( $section['parallax'] ?? '', -10, 10 ),
		);

		$rows = array();
		foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
			$rendered = $this->render_row( (array) $row, $direction, $nodes, $report );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$rows[] = $rendered;
		}
		return $this->open( 'section', $attrs ) . "\n" . implode( "\n", $rows ) . "\n[/section]";
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_row( array $row, string $direction, array &$nodes, array &$report ): string|\WP_Error {
		$id              = $this->node_id( 'row', (string) ( $row['id'] ?? '' ) );
		$nodes['rows'][] = $id;
		$attrs           = array(
			'_id'         => $id,
			'label'       => $this->text_attr( (string) ( $row['label'] ?? '' ) ),
			'class'       => implode( ' ', $this->classes( (string) ( $row['class'] ?? '' ) ) ),
			'style'       => $this->allowed( (string) ( $row['style'] ?? '' ), array( 'small', 'large', 'collapse' ) ),
			'width'       => $this->allowed( (string) ( $row['width'] ?? '' ), array( 'full-width', 'custom' ) ),
			'v_align'     => $this->allowed( (string) ( $row['vertical_align'] ?? '' ), array( 'top', 'middle', 'bottom', 'equal' ) ),
			'h_align'     => $this->allowed( (string) ( $row['horizontal_align'] ?? '' ), array( 'left', 'center', 'right' ) ),
			'col_style'   => $this->allowed( (string) ( $row['column_style'] ?? '' ), array( 'divided', 'dashed', 'solid' ) ),
			'padding'     => $this->dimension( (string) ( $row['padding'] ?? '' ) ),
			'depth'       => $this->number_range( $row['depth'] ?? '', 0, 5 ),
			'depth_hover' => $this->number_range( $row['depth_hover'] ?? '', 0, 5 ),
		);

		$columns = (array) ( $row['columns'] ?? array() );
		if ( empty( $columns ) ) {
			$columns[] = array( 'span' => 12, 'span_mobile' => 12, 'elements' => array() );
		}
		$rendered_columns = array();
		foreach ( $columns as $column ) {
			$rendered = $this->render_column( (array) $column, $direction, $nodes, $report );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$rendered_columns[] = $rendered;
		}
		return $this->open( 'row', $attrs ) . "\n" . implode( "\n", $rendered_columns ) . "\n[/row]";
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_column( array $column, string $direction, array &$nodes, array &$report ): string|\WP_Error {
		$id                 = $this->node_id( 'col', (string) ( $column['id'] ?? '' ) );
		$nodes['columns'][] = $id;
		$attrs              = array(
			'_id'         => $id,
			'label'       => $this->text_attr( (string) ( $column['label'] ?? '' ) ),
			'class'       => implode( ' ', $this->classes( (string) ( $column['class'] ?? '' ) ) ),
			'span'        => $this->integer_range( $column['span'] ?? 12, 1, 12 ),
			'span__md'    => $this->integer_range( $column['span_tablet'] ?? '', 1, 12 ),
			'span__sm'    => $this->integer_range( $column['span_mobile'] ?? 12, 1, 12 ),
			'align'       => $this->allowed( (string) ( $column['align'] ?? ( 'rtl' === $direction ? 'right' : '' ) ), array( 'left', 'center', 'right', 'justify' ) ),
			'color'       => ! empty( $column['light_text'] ) ? 'light' : '',
			'padding'     => $this->dimension( (string) ( $column['padding'] ?? '' ) ),
			'padding__sm' => $this->dimension( (string) ( $column['padding_mobile'] ?? '' ) ),
			'margin'      => $this->dimension( (string) ( $column['margin'] ?? '' ) ),
			'max_width'   => $this->dimension( (string) ( $column['max_width'] ?? '' ) ),
			'bg_color'    => $this->color( (string) ( $column['background_color'] ?? '' ) ),
			'bg_radius'   => $this->number_range( $column['radius'] ?? '', 0, 500 ),
			'depth'       => $this->number_range( $column['depth'] ?? '', 0, 5 ),
			'depth_hover' => $this->number_range( $column['depth_hover'] ?? '', 0, 5 ),
			'animate'     => $this->allowed( (string) ( $column['animate'] ?? '' ), array( 'fadeInLeft', 'fadeInRight', 'fadeInUp', 'fadeInDown', 'flipInY', 'bounceIn', 'none' ) ),
		);

		$elements = $this->render_children( (array) ( $column['elements'] ?? array() ), $direction, $nodes, $report, 0 );
		if ( is_wp_error( $elements ) ) {
			return $elements;
		}
		return $this->open( 'col', $attrs ) . "\n" . $elements . "\n[/col]";
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_element( array $element, string $direction, array &$nodes, array &$report, int $depth = 0 ): string|\WP_Error {
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			return new \WP_Error( 'component_nesting_too_deep', __( 'Flatsome components may be nested at most 10 levels deep.', 'mindio-magic-mcp' ) );
		}
		$type       = sanitize_key( (string) ( $element['type'] ?? '' ) );
		$definition = $this->catalog->get( $type );
		if ( ! $definition ) {
			return new \WP_Error( 'unsupported_element', __( 'The requested Flatsome component type is not supported.', 'mindio-magic-mcp' ) );
		}
		if ( ! $this->catalog->dependency_available( $type ) ) {
			return new \WP_Error(
				'component_dependency_missing',
				sprintf(
					/* translators: 1: component type, 2: dependency name. */
					__( 'The %1$s component requires the active %2$s dependency.', 'mindio-magic-mcp' ),
					$type,
					$definition['dependency']
				)
			);
		}

		$id                  = $this->node_id( $type, (string) ( $element['id'] ?? '' ) );
		$nodes['elements'][] = $id;
		$missing             = $this->catalog->missing_shortcodes( $type );
		if ( 'html' === $type ) {
			$this->note_fallback( $report, $id, $type, 'explicit_html' );
			return $this->render_html_fallback( (string) ( $element['html'] ?? '' ), $element, $id, $direction );
		}
		if ( ! empty( $missing ) ) {
			$this->note_fallback( $report, $id, $type, 'shortcode_unavailable' );
			return $this->render_semantic_fallback( $element, $type, $id, $direction, $nodes, $report, $depth );
		}

		$rendered = $this->render_native_element( $element, $type, $id, $direction, $nodes, $report, $depth );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}
		$this->note_native( $report, $type, $definition['shortcodes'][0] );
		return $rendered;
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_native_element( array $element, string $type, string $id, string $direction, array &$nodes, array &$report, int $depth ): string|\WP_Error {
		$marker = $this->marker_classes( $element, $id, $direction );
		if ( 'title' === $type ) {
			$text = $this->text_attr( (string) ( $element['text'] ?? '' ) );
			if ( '' === $text ) {
				return new \WP_Error( 'title_text_required', __( 'A title component requires text.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'title', array( '_id' => $id, 'class' => $marker, 'text' => $text, 'tag_name' => $this->allowed( (string) ( $element['tag'] ?? 'h2' ), array( 'h1', 'h2', 'h3', 'h4' ) ) ?: 'h2', 'style' => $this->allowed( (string) ( $element['style'] ?? 'normal' ), array( 'normal', 'center', 'bold', 'bold-center' ) ) ?: 'normal', 'color' => $this->color( (string) ( $element['color'] ?? '' ) ), 'size' => $this->number_range( $element['size'] ?? 100, 20, 300 ), 'link' => $this->url( (string) ( $element['link'] ?? '' ) ), 'target' => ! empty( $element['new_tab'] ) ? '_blank' : '', 'margin_top' => $this->dimension( (string) ( $element['margin_top'] ?? '' ) ), 'margin_bottom' => $this->dimension( (string) ( $element['margin_bottom'] ?? '' ) ) ) );
		}
		if ( 'text' === $type ) {
			$raw_content = (string) ( $element['content'] ?? '' );
			if ( preg_match( '/<(?:h[1-6]|img|div|section|article|header|footer|main|aside|table|form|iframe|script|style|video|audio|canvas|svg)\b/i', $raw_content ) || preg_match( '/\[(?:\/)?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', $raw_content ) ) {
				return new \WP_Error( 'text_markup_forbidden', __( 'UX text content may contain body-copy markup only. Use typed components for headings, media, layouts, and shortcodes.', 'mindio-magic-mcp' ) );
			}
			$content = $this->safe_body_html( $raw_content );
			if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
				return new \WP_Error( 'text_required', __( 'A text component requires content.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'ux_text', array( '_id' => $id, 'class' => $marker, 'text_align' => $this->allowed( (string) ( $element['align'] ?? ( 'rtl' === $direction ? 'right' : '' ) ), array( 'left', 'center', 'right', 'justify' ) ), 'font_size' => $this->number_range( $element['font_size'] ?? '', 0.5, 8 ), 'line_height' => $this->number_range( $element['line_height'] ?? '', 0.5, 4 ), 'text_color' => $this->color( (string) ( $element['text_color'] ?? '' ) ) ) ) . "\n" . $content . "\n[/ux_text]";
		}
		if ( 'image' === $type ) {
			$media_id = $this->media_id( $element['media_id'] ?? 0 );
			if ( ! $media_id ) {
				return new \WP_Error( 'image_required', __( 'An image component requires a Media Library media_id.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'ux_image', array( '_id' => $id, 'class' => $marker, 'id' => $media_id, 'image_size' => $this->allowed( (string) ( $element['image_size'] ?? 'large' ), array( 'thumbnail', 'medium', 'large', 'full' ) ) ?: 'large', 'width' => $this->number_range( $element['width'] ?? 100, 1, 100 ), 'height' => $this->dimension( (string) ( $element['height'] ?? '' ) ), 'link' => $this->url( (string) ( $element['link'] ?? '' ) ), 'target' => ! empty( $element['new_tab'] ) ? '_blank' : '', 'image_hover' => $this->allowed( (string) ( $element['hover'] ?? '' ), array( 'zoom', 'zoom-fade', 'blur', 'fade-in', 'fade-out', 'color', 'grayscale' ) ), 'depth' => $this->number_range( $element['depth'] ?? '', 0, 5 ) ) ) . '[/ux_image]';
		}
		if ( 'button' === $type ) {
			return $this->render_button( $element, $id, $marker );
		}
		if ( 'banner' === $type ) {
			return $this->render_banner( $element, $id, $marker, $direction );
		}
		if ( 'featured_box' === $type ) {
			$title = $this->text_attr( (string) ( $element['title'] ?? '' ) );
			if ( '' === $title ) {
				return new \WP_Error( 'featured_box_title_required', __( 'A featured box requires a title.', 'mindio-magic-mcp' ) );
			}
			$body = $this->body_shortcode( (string) ( $element['content'] ?? '' ), $direction );
			return $this->open( 'featured_box', array( '_id' => $id, 'class' => $marker, 'img' => $this->media_id( $element['media_id'] ?? 0 ), 'img_width' => $this->number_range( $element['image_width'] ?? 60, 20, 600 ), 'pos' => $this->allowed( (string) ( $element['position'] ?? 'top' ), array( 'top', 'center', 'left', 'right' ) ) ?: 'top', 'title' => $title, 'title_small' => $this->text_attr( (string) ( $element['subtitle'] ?? '' ) ), 'font_size' => $this->allowed( (string) ( $element['font_size'] ?? 'medium' ), array( 'xsmall', 'small', 'medium', 'large', 'xlarge' ) ) ?: 'medium', 'link' => $this->url( (string) ( $element['link'] ?? '' ) ), 'target' => ! empty( $element['new_tab'] ) ? '_blank' : '', 'icon_color' => $this->color( (string) ( $element['icon_color'] ?? '' ) ) ) ) . $body . '[/featured_box]';
		}
		if ( 'image_box' === $type ) {
			$media_id = $this->media_id( $element['media_id'] ?? 0 );
			if ( ! $media_id ) {
				return new \WP_Error( 'image_box_image_required', __( 'An image box requires a Media Library media_id.', 'mindio-magic-mcp' ) );
			}
			$inner = $this->inline_title_and_body( $element, $direction, 'h3' );
			return $this->open( 'ux_image_box', array( '_id' => $id, 'class' => $marker, 'img' => $media_id, 'style' => $this->allowed( (string) ( $element['style'] ?? 'normal' ), array( 'normal', 'overlay', 'shade', 'badge', 'push', 'push-rounded' ) ) ?: 'normal', 'depth' => $this->number_range( $element['depth'] ?? '', 0, 5 ), 'depth_hover' => $this->number_range( $element['depth_hover'] ?? '', 0, 5 ), 'text_align' => $this->allowed( (string) ( $element['align'] ?? ( 'rtl' === $direction ? 'right' : 'center' ) ), array( 'left', 'center', 'right' ) ), 'image_hover' => $this->allowed( (string) ( $element['hover'] ?? '' ), array( 'zoom', 'zoom-fade', 'blur', 'fade-in', 'fade-out', 'color', 'grayscale' ) ), 'link' => $this->url( (string) ( $element['link'] ?? '' ) ), 'target' => ! empty( $element['new_tab'] ) ? '_blank' : '' ) ) . $inner . '[/ux_image_box]';
		}
		if ( 'message_box' === $type ) {
			$children = $this->render_children( (array) ( $element['elements'] ?? array() ), $direction, $nodes, $report, $depth + 1 );
			if ( is_wp_error( $children ) ) {
				return $children;
			}
			return $this->open( 'message_box', array( '_id' => $id, 'class' => $marker, 'bg' => $this->media_id( $element['background_media_id'] ?? 0 ), 'bg_color' => $this->color( (string) ( $element['background_color'] ?? '' ) ), 'text_color' => $this->allowed( (string) ( $element['text_color'] ?? 'dark' ), array( 'dark', 'light' ) ) ?: 'dark', 'padding' => $this->number_range( $element['padding'] ?? 15, 0, 200 ) ) ) . "\n" . $children . "\n[/message_box]";
		}
		if ( 'slider' === $type ) {
			$children = $this->render_children( (array) ( $element['slides'] ?? array() ), $direction, $nodes, $report, $depth + 1 );
			if ( is_wp_error( $children ) ) {
				return $children;
			}
			return $this->open( 'ux_slider', array( '_id' => $id, 'class' => $marker, 'nav_style' => $this->allowed( (string) ( $element['nav_style'] ?? 'circle' ), array( 'circle', 'simple', 'reveal' ) ) ?: 'circle', 'nav_color' => $this->allowed( (string) ( $element['nav_color'] ?? 'light' ), array( 'dark', 'light' ) ) ?: 'light', 'nav_pos' => $this->allowed( (string) ( $element['nav_position'] ?? '' ), array( 'inside', 'outside' ) ), 'arrows' => $this->boolean_attr( $element['arrows'] ?? true ), 'bullets' => $this->boolean_attr( $element['bullets'] ?? true ), 'auto_slide' => $this->integer_range( $element['auto_slide'] ?? '', 1000, 30000 ), 'pause_hover' => $this->boolean_attr( $element['pause_on_hover'] ?? true ), 'freescroll' => $this->boolean_attr( $element['free_scroll'] ?? false ) ) ) . "\n" . $children . "\n[/ux_slider]";
		}
		if ( 'banner_grid' === $type ) {
			$items = array();
			foreach ( (array) ( $element['items'] ?? array() ) as $item ) {
				$banner = $this->render_element( (array) ( $item['banner'] ?? array() ), $direction, $nodes, $report, $depth + 1 );
				if ( is_wp_error( $banner ) ) {
					return $banner;
				}
				$items[] = $this->open( 'col_grid', array( 'span' => $this->integer_range( $item['span'] ?? 6, 1, 12 ), 'span__md' => $this->integer_range( $item['span_tablet'] ?? '', 1, 12 ), 'span__sm' => $this->integer_range( $item['span_mobile'] ?? 12, 1, 12 ), 'height' => $this->number_range( $item['height'] ?? 50, 10, 100 ) ) ) . $banner . '[/col_grid]';
			}
			return $this->open( 'ux_banner_grid', array( '_id' => $id, 'class' => $marker, 'height' => $this->dimension( (string) ( $element['height'] ?? '600px' ) ) ?: '600px', 'height__sm' => $this->dimension( (string) ( $element['height_mobile'] ?? '' ) ) ) ) . "\n" . implode( "\n", $items ) . "\n[/ux_banner_grid]";
		}
		if ( 'accordion' === $type || 'tabs' === $type ) {
			return $this->render_panel_group( $element, $type, $id, $marker, $direction, $nodes, $report, $depth );
		}
		if ( 'gallery' === $type ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) ( $element['media_ids'] ?? array() ) ) ) );
			if ( empty( $ids ) ) {
				return new \WP_Error( 'gallery_images_required', __( 'A gallery requires at least one Media Library image.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'ux_gallery', array( '_id' => $id, 'class' => $marker, 'ids' => implode( ',', $ids ), 'columns' => $this->integer_range( $element['columns'] ?? 4, 1, 8 ), 'columns__md' => $this->integer_range( $element['columns_tablet'] ?? '', 1, 8 ), 'columns__sm' => $this->integer_range( $element['columns_mobile'] ?? 2, 1, 4 ), 'lightbox' => $this->boolean_attr( $element['lightbox'] ?? true ), 'image_size' => $this->allowed( (string) ( $element['image_size'] ?? 'medium' ), array( 'thumbnail', 'medium', 'large', 'full' ) ) ?: 'medium' ) );
		}
		if ( 'video' === $type ) {
			$url = $this->url( (string) ( $element['url'] ?? '' ) );
			if ( '' === $url ) {
				return new \WP_Error( 'video_url_required', __( 'A video component requires a valid URL.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'ux_video', array( '_id' => $id, 'class' => $marker, 'url' => $url, 'height' => $this->dimension( (string) ( $element['height'] ?? '56.25%' ) ) ?: '56.25%', 'depth' => $this->number_range( $element['depth'] ?? '', 0, 5 ), 'depth_hover' => $this->number_range( $element['depth_hover'] ?? '', 0, 5 ) ) ) . '[/ux_video]';
		}
		if ( 'countdown' === $type ) {
			return $this->render_countdown( $element, $id, $marker );
		}
		if ( 'testimonial' === $type ) {
			$content = $this->body_shortcode( (string) ( $element['content'] ?? '' ), $direction );
			if ( '' === $content ) {
				return new \WP_Error( 'testimonial_content_required', __( 'A testimonial requires content.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'testimonial', array( '_id' => $id, 'class' => $marker, 'image' => $this->media_id( $element['media_id'] ?? 0 ), 'image_width' => $this->number_range( $element['image_width'] ?? 80, 20, 300 ), 'pos' => $this->allowed( (string) ( $element['position'] ?? 'left' ), array( 'top', 'center', 'left', 'right' ) ) ?: 'left', 'name' => $this->text_attr( (string) ( $element['name'] ?? '' ) ), 'company' => $this->text_attr( (string) ( $element['company'] ?? '' ) ), 'stars' => $this->integer_range( $element['stars'] ?? 5, 0, 5 ), 'font_size' => $this->allowed( (string) ( $element['font_size'] ?? 'medium' ), array( 'small', 'medium', 'large' ) ) ?: 'medium' ) ) . $content . '[/testimonial]';
		}
		if ( 'team_member' === $type ) {
			$name = $this->text_attr( (string) ( $element['name'] ?? '' ) );
			if ( '' === $name ) {
				return new \WP_Error( 'team_member_name_required', __( 'A team member requires a name.', 'mindio-magic-mcp' ) );
			}
			$attrs = array( '_id' => $id, 'class' => $marker, 'img' => $this->media_id( $element['media_id'] ?? 0 ), 'name' => $name, 'title' => $this->text_attr( (string) ( $element['title'] ?? '' ) ), 'style' => $this->allowed( (string) ( $element['style'] ?? 'normal' ), array( 'normal', 'overlay', 'shade', 'badge', 'push', 'push-rounded' ) ) ?: 'normal', 'depth' => $this->number_range( $element['depth'] ?? '', 0, 5 ), 'depth_hover' => $this->number_range( $element['depth_hover'] ?? '', 0, 5 ) );
			foreach ( (array) ( $element['social'] ?? array() ) as $network => $url ) {
				if ( in_array( $network, $this->social_networks(), true ) ) {
					$attrs[ $network ] = $this->url( (string) $url );
				}
			}
			return $this->open( 'team_member', $attrs ) . $this->body_shortcode( (string) ( $element['content'] ?? '' ), $direction ) . '[/team_member]';
		}
		if ( 'price_table' === $type ) {
			return $this->render_price_table( $element, $id, $marker );
		}
		if ( 'logo' === $type ) {
			$media_id = $this->media_id( $element['media_id'] ?? 0 );
			if ( ! $media_id ) {
				return new \WP_Error( 'logo_image_required', __( 'A logo component requires a Media Library media_id.', 'mindio-magic-mcp' ) );
			}
			return $this->open( 'logo', array( '_id' => $id, 'class' => $marker, 'img' => $media_id, 'image_size' => $this->allowed( (string) ( $element['image_size'] ?? 'full' ), array( 'thumbnail', 'medium', 'large', 'full', 'original' ) ) ?: 'full', 'title' => $this->text_attr( (string) ( $element['title'] ?? '' ) ), 'height' => $this->integer_range( $element['height'] ?? 50, 10, 300 ), 'padding' => $this->dimension( (string) ( $element['padding'] ?? '15px' ) ) ?: '15px', 'hover' => $this->allowed( (string) ( $element['hover'] ?? '' ), array( 'zoom', 'fade-in', 'glow', 'color', 'grayscale' ) ), 'link' => $this->url( (string) ( $element['link'] ?? '' ) ), 'target' => ! empty( $element['new_tab'] ) ? '_blank' : '' ) );
		}
		if ( 'divider' === $type ) {
			return $this->open( 'divider', array( '_id' => $id, 'class' => $marker, 'align' => $this->allowed( (string) ( $element['align'] ?? '' ), array( 'left', 'center', 'right' ) ), 'width' => $this->dimension( (string) ( $element['width'] ?? '30px' ) ) ?: '30px', 'height' => $this->number_range( $element['height'] ?? 1, 1, 20 ), 'color' => $this->color( (string) ( $element['color'] ?? '' ) ), 'margin' => $this->dimension( (string) ( $element['margin'] ?? '' ) ) ) );
		}
		if ( 'gap' === $type ) {
			return $this->open( 'gap', array( '_id' => $id, 'class' => $marker, 'height' => $this->dimension( (string) ( $element['height'] ?? '30px' ) ) ?: '30px', 'height__sm' => $this->dimension( (string) ( $element['height_mobile'] ?? '' ) ) ) );
		}
		if ( 'blog_posts' === $type ) {
			return $this->open( 'blog_posts', $this->query_attrs( $element, $id, $marker, false ) );
		}
		if ( 'products' === $type ) {
			return $this->open( 'ux_products', $this->query_attrs( $element, $id, $marker, true ) );
		}
		if ( 'product_categories' === $type ) {
			return $this->open( 'ux_product_categories', array( '_id' => $id, 'class' => $marker, 'number' => $this->integer_range( $element['limit'] ?? 12, 1, 50 ), 'ids' => $this->id_list( (array) ( $element['category_ids'] ?? array() ) ), 'cat' => sanitize_title( (string) ( $element['category'] ?? '' ) ), 'columns' => $this->integer_range( $element['columns'] ?? 4, 1, 8 ), 'columns__md' => $this->integer_range( $element['columns_tablet'] ?? '', 1, 8 ), 'columns__sm' => $this->integer_range( $element['columns_mobile'] ?? 2, 1, 4 ), 'type' => $this->allowed( (string) ( $element['layout'] ?? 'slider' ), array( 'slider', 'row', 'masonry', 'grid' ) ) ?: 'slider', 'style' => $this->allowed( (string) ( $element['style'] ?? 'badge' ), array( 'badge', 'overlay', 'shade', 'normal' ) ) ?: 'badge', 'orderby' => $this->allowed( (string) ( $element['orderby'] ?? 'menu_order' ), array( 'menu_order', 'name', 'slug', 'count', 'id' ) ) ?: 'menu_order', 'order' => strtoupper( $this->allowed( strtolower( (string) ( $element['order'] ?? 'asc' ) ), array( 'asc', 'desc' ) ) ?: 'asc' ), 'hide_empty' => $this->boolean_attr( $element['hide_empty'] ?? true ), 'show_count' => $this->boolean_attr( $element['show_count'] ?? true ) ) );
		}
		if ( 'follow' === $type ) {
			$attrs = $this->social_attrs( $element, $id, $marker );
			foreach ( (array) ( $element['social'] ?? array() ) as $network => $url ) {
				if ( in_array( $network, $this->social_networks(), true ) ) {
					$attrs[ $network ] = $this->url( (string) $url );
				}
			}
			return $this->open( 'follow', $attrs );
		}
		if ( 'share' === $type ) {
			return $this->open( 'share', $this->social_attrs( $element, $id, $marker ) );
		}
		if ( 'map' === $type ) {
			return $this->open( 'map', array( '_id' => $id, 'class' => $marker, 'lat' => $this->number_range( $element['latitude'] ?? 0, -90, 90 ), 'long' => $this->number_range( $element['longitude'] ?? 0, -180, 180 ), 'height' => $this->dimension( (string) ( $element['height'] ?? '400px' ) ) ?: '400px', 'height__sm' => $this->dimension( (string) ( $element['height_mobile'] ?? '' ) ), 'zoom' => $this->integer_range( $element['zoom'] ?? 16, 1, 22 ), 'controls' => $this->boolean_attr( $element['controls'] ?? false ), 'content_enable' => ! empty( $element['content'] ) ? 'true' : 'false', 'content_width' => $this->number_range( $element['content_width'] ?? 30, 10, 100 ) ) ) . $this->safe_body_html( (string) ( $element['content'] ?? '' ) ) . '[/map]';
		}
		if ( 'search' === $type ) {
			return $this->open( 'search', array( '_id' => $id, 'class' => $marker, 'size' => $this->allowed( (string) ( $element['size'] ?? 'normal' ), array( 'small', 'normal', 'large' ) ) ?: 'normal', 'style' => $this->allowed( (string) ( $element['style'] ?? '' ), array( 'flat', 'minimal' ) ) ) );
		}

		return new \WP_Error( 'unsupported_element', __( 'The requested Flatsome component type is not supported.', 'mindio-magic-mcp' ) );
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_children( array $elements, string $direction, array &$nodes, array &$report, int $depth ): string|\WP_Error {
		$content = array();
		foreach ( $elements as $element ) {
			$rendered = $this->render_element( (array) $element, $direction, $nodes, $report, $depth );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$content[] = $rendered;
		}
		return implode( "\n", $content );
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_panel_group( array $element, string $type, string $id, string $marker, string $direction, array &$nodes, array &$report, int $depth ): string|\WP_Error {
		$items = array();
		foreach ( (array) ( $element['items'] ?? array() ) as $item ) {
			$children = $this->render_children( (array) ( $item['elements'] ?? array() ), $direction, $nodes, $report, $depth + 1 );
			if ( is_wp_error( $children ) ) {
				return $children;
			}
			$tag     = 'accordion' === $type ? 'accordion-item' : 'tab';
			$items[] = $this->open( $tag, array( 'title' => $this->text_attr( (string) ( $item['title'] ?? '' ) ), 'anchor' => sanitize_title( (string) ( $item['anchor'] ?? '' ) ) ) ) . "\n" . $children . "\n[/" . $tag . ']';
		}
		if ( 'accordion' === $type ) {
			return $this->open( 'accordion', array( '_id' => $id, 'class' => $marker, 'title' => $this->text_attr( (string) ( $element['title'] ?? '' ) ), 'open' => $this->integer_range( $element['open_item'] ?? '', 1, max( 1, count( $items ) ) ), 'auto_open' => $this->boolean_attr( $element['auto_open'] ?? false ), 'faq_schema' => $this->boolean_attr( $element['faq_schema'] ?? false ) ) ) . "\n" . implode( "\n", $items ) . "\n[/accordion]";
		}
		return $this->open( 'tabgroup', array( '_id' => $id, 'class' => $marker, 'title' => $this->text_attr( (string) ( $element['title'] ?? '' ) ), 'style' => $this->allowed( (string) ( $element['style'] ?? 'line' ), array( 'line', 'tabs', 'pills' ) ) ?: 'line', 'align' => $this->allowed( (string) ( $element['align'] ?? ( 'rtl' === $direction ? 'right' : 'left' ) ), array( 'left', 'center', 'right' ) ), 'type' => $this->allowed( (string) ( $element['orientation'] ?? 'horizontal' ), array( 'horizontal', 'vertical' ) ) ) ) . "\n" . implode( "\n", $items ) . "\n[/tabgroup]";
	}

	private function render_button( array $element, string $id = '', string $class = '' ): string|\WP_Error {
		$text = $this->text_attr( (string) ( $element['text'] ?? '' ) );
		if ( '' === $text ) {
			return new \WP_Error( 'button_text_required', __( 'A button component requires text.', 'mindio-magic-mcp' ) );
		}
		return $this->open( 'button', array( '_id' => $id, 'class' => $class, 'text' => $text, 'link' => $this->url( (string) ( $element['link'] ?? '' ) ), 'target' => ! empty( $element['new_tab'] ) ? '_blank' : '', 'color' => $this->allowed( (string) ( $element['color'] ?? '' ), array( 'primary', 'secondary', 'alert', 'success', 'white' ) ), 'style' => $this->allowed( (string) ( $element['style'] ?? '' ), array( 'outline', 'link', 'underline', 'shade' ) ), 'size' => $this->allowed( (string) ( $element['size'] ?? '' ), array( 'smaller', 'small', 'medium', 'large', 'larger', 'xlarge' ) ), 'radius' => $this->number_range( $element['radius'] ?? '', 0, 99 ), 'icon' => $this->allowed_icon( (string) ( $element['icon'] ?? '' ) ), 'icon_pos' => $this->allowed( (string) ( $element['icon_position'] ?? '' ), array( 'left', 'right' ) ) ) );
	}

	private function render_banner( array $element, string $id, string $marker, string $direction ): string|\WP_Error {
		$media_id = $this->media_id( $element['media_id'] ?? 0 );
		if ( ! $media_id ) {
			return new \WP_Error( 'banner_image_required', __( 'A banner component requires a Media Library media_id.', 'mindio-magic-mcp' ) );
		}
		$inner = '';
		if ( ! empty( $element['heading'] ) ) {
			$inner .= $this->open( 'title', array( 'text' => $this->text_attr( (string) $element['heading'] ), 'tag_name' => $this->allowed( (string) ( $element['heading_tag'] ?? 'h2' ), array( 'h1', 'h2', 'h3', 'h4' ) ) ?: 'h2', 'style' => 'normal' ) ) . "\n";
		}
		if ( ! empty( $element['text'] ) ) {
			$inner .= $this->body_shortcode( (string) $element['text'], $direction ) . "\n";
		}
		if ( ! empty( $element['button_text'] ) ) {
			$button = $this->render_button( array( 'text' => $element['button_text'], 'link' => $element['button_link'] ?? '', 'new_tab' => $element['button_new_tab'] ?? false, 'style' => $element['button_style'] ?? 'outline', 'color' => $element['button_color'] ?? 'white' ) );
			if ( is_wp_error( $button ) ) {
				return $button;
			}
			$inner .= $button;
		}
		$banner_attrs = array( '_id' => $id, 'class' => $marker, 'bg' => $media_id, 'height' => $this->dimension( (string) ( $element['height'] ?? '500px' ) ) ?: '500px', 'bg_overlay' => $this->color( (string) ( $element['overlay'] ?? 'rgba(0,0,0,0.35)' ) ), 'bg_pos' => $this->position( (string) ( $element['background_position'] ?? '50% 50%' ) ), 'link' => $this->url( (string) ( $element['link'] ?? '' ) ) );
		$box_attrs    = array( 'width' => $this->number_range( $element['content_width'] ?? 60, 10, 100 ), 'width__sm' => $this->number_range( $element['content_width_mobile'] ?? 90, 10, 100 ), 'position_x' => $this->number_range( $element['position_x'] ?? 50, 0, 100 ), 'position_y' => $this->number_range( $element['position_y'] ?? 50, 0, 100 ), 'text_align' => $this->allowed( (string) ( $element['align'] ?? ( 'rtl' === $direction ? 'right' : 'center' ) ), array( 'left', 'center', 'right' ) ), 'text_color' => ! empty( $element['dark_text'] ) ? 'dark' : 'light' );
		return $this->open( 'ux_banner', $banner_attrs ) . "\n" . $this->open( 'text_box', $box_attrs ) . "\n" . trim( $inner ) . "\n[/text_box]\n[/ux_banner]";
	}

	private function render_countdown( array $element, string $id, string $marker ): string|\WP_Error {
		try {
			$date = new \DateTimeImmutable( (string) ( $element['deadline'] ?? '' ) );
		} catch ( \Throwable ) {
			return new \WP_Error( 'countdown_deadline_invalid', __( 'A countdown requires a valid deadline.', 'mindio-magic-mcp' ) );
		}
		return $this->open( 'ux_countdown', array( '_id' => $id, 'class' => $marker, 'year' => $date->format( 'Y' ), 'month' => $date->format( 'm' ), 'day' => $date->format( 'd' ), 'time' => $date->format( 'H:i' ), 'style' => $this->allowed( (string) ( $element['style'] ?? 'clock' ), array( 'clock', 'text' ) ) ?: 'clock', 'size' => $this->number_range( $element['size'] ?? 300, 20, 400 ), 'color' => $this->allowed( (string) ( $element['color'] ?? 'dark' ), array( 'dark', 'light' ) ) ?: 'dark', 'bg_color' => $this->color( (string) ( $element['background_color'] ?? '' ) ) ) );
	}

	private function render_price_table( array $element, string $id, string $marker ): string|\WP_Error {
		$title = $this->text_attr( (string) ( $element['title'] ?? '' ) );
		$price = $this->text_attr( (string) ( $element['price'] ?? '' ) );
		if ( '' === $title || '' === $price ) {
			return new \WP_Error( 'price_table_fields_required', __( 'A price table requires a title and price.', 'mindio-magic-mcp' ) );
		}
		$inner = array();
		foreach ( (array) ( $element['bullets'] ?? array() ) as $bullet ) {
			$inner[] = $this->open( 'bullet_item', array( 'text' => $this->text_attr( (string) ( $bullet['text'] ?? '' ) ), 'tooltip' => $this->text_attr( (string) ( $bullet['tooltip'] ?? '' ) ), 'enabled' => $this->boolean_attr( $bullet['enabled'] ?? true ) ) );
		}
		if ( ! empty( $element['button'] ) ) {
			$button = $this->render_button( (array) $element['button'] );
			if ( is_wp_error( $button ) ) {
				return $button;
			}
			$inner[] = $button;
		}
		return $this->open( 'ux_price_table', array( '_id' => $id, 'class' => $marker, 'title' => $title, 'price' => $price, 'description' => $this->text_attr( (string) ( $element['description'] ?? '' ) ), 'featured' => $this->boolean_attr( $element['featured'] ?? false ), 'color' => ! empty( $element['light_text'] ) ? 'dark' : '', 'bg_color' => $this->color( (string) ( $element['background_color'] ?? '' ) ), 'radius' => $this->number_range( $element['radius'] ?? 0, 0, 30 ), 'depth' => $this->number_range( $element['depth'] ?? '', 0, 5 ), 'depth_hover' => $this->number_range( $element['depth_hover'] ?? '', 0, 5 ) ) ) . "\n" . implode( "\n", $inner ) . "\n[/ux_price_table]";
	}

	/** @return array<string,mixed> */
	private function query_attrs( array $element, string $id, string $marker, bool $products ): array {
		$attrs = array( '_id' => $id, 'class' => $marker, 'columns' => $this->integer_range( $element['columns'] ?? 4, 1, 8 ), 'columns__md' => $this->integer_range( $element['columns_tablet'] ?? '', 1, 8 ), 'columns__sm' => $this->integer_range( $element['columns_mobile'] ?? 2, 1, 4 ), 'type' => $this->allowed( (string) ( $element['layout'] ?? 'slider' ), array( 'slider', 'row', 'masonry', 'grid' ) ) ?: 'slider', 'style' => $this->allowed( (string) ( $element['style'] ?? 'normal' ), array( 'normal', 'overlay', 'shade', 'badge', 'bounce', 'push' ) ) ?: 'normal', 'orderby' => $this->allowed( (string) ( $element['orderby'] ?? 'date' ), array( 'date', 'modified', 'title', 'menu_order', 'rand', 'id', 'price', 'sales', 'rating' ) ) ?: 'date', 'order' => strtoupper( $this->allowed( strtolower( (string) ( $element['order'] ?? 'desc' ) ), array( 'asc', 'desc' ) ) ?: 'desc' ) );
		if ( $products ) {
			$attrs['products'] = $this->integer_range( $element['limit'] ?? 8, 1, 50 );
			$attrs['ids']      = $this->id_list( (array) ( $element['product_ids'] ?? array() ) );
			$attrs['cat']      = sanitize_title( (string) ( $element['category'] ?? '' ) );
			$attrs['tags']     = $this->slug_list( (array) ( $element['tags'] ?? array() ) );
		} else {
			$attrs['posts']          = $this->integer_range( $element['limit'] ?? 8, 1, 50 );
			$attrs['ids']            = $this->id_list( (array) ( $element['post_ids'] ?? array() ) );
			$attrs['cat']            = $this->integer_range( $element['category_id'] ?? '', 1, PHP_INT_MAX );
			$attrs['excerpt']        = ! empty( $element['show_excerpt'] ) ? 'visible' : 'false';
			$attrs['excerpt_length'] = $this->integer_range( $element['excerpt_length'] ?? 15, 1, 100 );
			$attrs['show_date']      = ! empty( $element['show_date'] ) ? 'badge' : 'false';
		}
		return $attrs;
	}

	/** @return array<string,mixed> */
	private function social_attrs( array $element, string $id, string $marker ): array {
		return array( '_id' => $id, 'class' => $marker, 'title' => $this->text_attr( (string) ( $element['title'] ?? '' ) ), 'style' => $this->allowed( (string) ( $element['style'] ?? 'outline' ), array( 'outline', 'fill', 'small' ) ) ?: 'outline', 'align' => $this->allowed( (string) ( $element['align'] ?? '' ), array( 'left', 'center', 'right' ) ), 'scale' => $this->number_range( $element['scale'] ?? '', 50, 300 ), 'tooltip' => $this->boolean_attr( $element['tooltips'] ?? true ) );
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function render_semantic_fallback( array $element, string $type, string $id, string $direction, array &$nodes, array &$report, int $depth ): string|\WP_Error {
		$content = $this->semantic_fallback_content( $element, $type, $direction, $nodes, $report, $depth );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$classes = $this->marker_classes( $element, $id, $direction ) . ' fmp-html-fallback';
		if ( shortcode_exists( 'ux_html' ) ) {
			return $this->open( 'ux_html', array( '_id' => $id, 'class' => $classes, 'label' => 'MCP ' . $type . ' fallback' ) ) . "\n" . $content . "\n[/ux_html]";
		}
		return '<div class="' . esc_attr( $classes ) . '">' . $content . '</div>';
	}

	/** @param array<string,string[]> $nodes @param array<string,mixed> $report */
	private function semantic_fallback_content( array $element, string $type, string $direction, array &$nodes, array &$report, int $depth ): string|\WP_Error {
		if ( 'title' === $type ) {
			$tag = $this->allowed( (string) ( $element['tag'] ?? 'h2' ), array( 'h1', 'h2', 'h3', 'h4' ) ) ?: 'h2';
			return '<' . $tag . '>' . esc_html( (string) ( $element['text'] ?? '' ) ) . '</' . $tag . '>';
		}
		if ( 'text' === $type ) {
			return $this->safe_body_html( (string) ( $element['content'] ?? '' ) );
		}
		if ( 'image' === $type || 'logo' === $type ) {
			return $this->attachment_html( absint( $element['media_id'] ?? 0 ), (string) ( $element['image_size'] ?? 'large' ) );
		}
		if ( 'button' === $type ) {
			$url = $this->url( (string) ( $element['link'] ?? '' ) );
			return '' !== $url ? '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( (string) ( $element['text'] ?? '' ) ) . '</a>' : '<span class="button">' . esc_html( (string) ( $element['text'] ?? '' ) ) . '</span>';
		}
		if ( 'banner' === $type || 'featured_box' === $type || 'image_box' === $type || 'testimonial' === $type || 'team_member' === $type ) {
			$image = $this->attachment_html( absint( $element['media_id'] ?? 0 ), 'large' );
			$title = (string) ( $element['heading'] ?? $element['title'] ?? $element['name'] ?? '' );
			$body  = (string) ( $element['content'] ?? $element['text'] ?? '' );
			return '<article>' . $image . ( '' !== $title ? '<h3>' . esc_html( $title ) . '</h3>' : '' ) . $this->safe_body_html( $body ) . '</article>';
		}
		if ( 'video' === $type ) {
			$url = $this->url( (string) ( $element['url'] ?? '' ) );
			return '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Watch video', 'mindio-magic-mcp' ) . '</a></p>';
		}
		if ( 'gallery' === $type ) {
			$output = '<div class="gallery">';
			foreach ( (array) ( $element['media_ids'] ?? array() ) as $media_id ) {
				$output .= $this->attachment_html( absint( $media_id ), (string) ( $element['image_size'] ?? 'medium' ) );
			}
			return $output . '</div>';
		}
		if ( 'countdown' === $type ) {
			$deadline = (string) ( $element['deadline'] ?? '' );
			return '<time datetime="' . esc_attr( $deadline ) . '">' . esc_html( $deadline ) . '</time>';
		}
		if ( in_array( $type, array( 'slider', 'message_box' ), true ) ) {
			$key      = 'slider' === $type ? 'slides' : 'elements';
			$children = $this->render_children( (array) ( $element[ $key ] ?? array() ), $direction, $nodes, $report, $depth + 1 );
			return is_wp_error( $children ) ? $children : '<div>' . $children . '</div>';
		}
		if ( 'banner_grid' === $type ) {
			$banners = array_map( static fn( array $item ): array => (array) ( $item['banner'] ?? array() ), (array) ( $element['items'] ?? array() ) );
			$children = $this->render_children( $banners, $direction, $nodes, $report, $depth + 1 );
			return is_wp_error( $children ) ? $children : '<div>' . $children . '</div>';
		}
		if ( 'accordion' === $type || 'tabs' === $type ) {
			$output = '';
			foreach ( (array) ( $element['items'] ?? array() ) as $item ) {
				$children = $this->render_children( (array) ( $item['elements'] ?? array() ), $direction, $nodes, $report, $depth + 1 );
				if ( is_wp_error( $children ) ) {
					return $children;
				}
				$output .= '<section><h3>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</h3>' . $children . '</section>';
			}
			return $output;
		}
		if ( 'price_table' === $type ) {
			$output = '<section><h3>' . esc_html( (string) ( $element['title'] ?? '' ) ) . '</h3><p>' . esc_html( (string) ( $element['price'] ?? '' ) ) . '</p><ul>';
			foreach ( (array) ( $element['bullets'] ?? array() ) as $bullet ) {
				$output .= '<li>' . esc_html( (string) ( $bullet['text'] ?? '' ) ) . '</li>';
			}
			return $output . '</ul></section>';
		}
		if ( 'divider' === $type ) {
			return '<hr />';
		}
		if ( 'gap' === $type ) {
			return '<div aria-hidden="true"></div>';
		}
		if ( in_array( $type, array( 'blog_posts', 'products' ), true ) ) {
			return $this->query_fallback( $element, 'products' === $type ? 'product' : 'post' );
		}
		if ( 'product_categories' === $type ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => ! empty( $element['hide_empty'] ), 'number' => min( 50, absint( $element['limit'] ?? 12 ) ) ) );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$output = '<ul>';
			foreach ( $terms as $term ) {
				$output .= '<li><a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></li>';
			}
			return $output . '</ul>';
		}
		if ( 'follow' === $type ) {
			$output = '<nav aria-label="' . esc_attr__( 'Social profiles', 'mindio-magic-mcp' ) . '"><ul>';
			foreach ( (array) ( $element['social'] ?? array() ) as $network => $url ) {
				$output .= '<li><a href="' . esc_url( $this->url( (string) $url ) ) . '">' . esc_html( ucfirst( (string) $network ) ) . '</a></li>';
			}
			return $output . '</ul></nav>';
		}
		if ( 'share' === $type ) {
			return '<p><a href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Share this page', 'mindio-magic-mcp' ) . '</a></p>';
		}
		if ( 'map' === $type ) {
			$url = add_query_arg( array( 'q' => (string) ( $element['latitude'] ?? 0 ) . ',' . (string) ( $element['longitude'] ?? 0 ) ), 'https://www.google.com/maps' );
			return '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'View location on map', 'mindio-magic-mcp' ) . '</a></p>';
		}
		if ( 'search' === $type ) {
			return get_search_form( false );
		}
		return '';
	}

	private function render_html_fallback( string $html, array $element, string $id, string $direction ): string|\WP_Error {
		$html = $this->safe_fallback_html( $html );
		if ( '' === trim( $html ) ) {
			return new \WP_Error( 'html_required', __( 'An HTML fallback component requires html.', 'mindio-magic-mcp' ) );
		}
		$classes = $this->marker_classes( $element, $id, $direction ) . ' fmp-html-fallback';
		return shortcode_exists( 'ux_html' )
			? $this->open( 'ux_html', array( '_id' => $id, 'class' => $classes, 'label' => 'MCP HTML fallback' ) ) . "\n" . $html . "\n[/ux_html]"
			: '<div class="' . esc_attr( $classes ) . '">' . $html . '</div>';
	}

	private function query_fallback( array $element, string $post_type ): string {
		$query = new \WP_Query( array( 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => min( 50, max( 1, absint( $element['limit'] ?? 8 ) ) ), 'post__in' => array_values( array_filter( array_map( 'absint', (array) ( $element[ 'product' === $post_type ? 'product_ids' : 'post_ids' ] ?? array() ) ) ) ), 'orderby' => sanitize_key( (string) ( $element['orderby'] ?? 'date' ) ), 'order' => 'asc' === strtolower( (string) ( $element['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC' ) );
		$output = '<div class="fmp-query-fallback">';
		foreach ( $query->posts as $post ) {
			$output .= '<article><h3><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3></article>';
		}
		wp_reset_postdata();
		return $output . '</div>';
	}

	private function inline_title_and_body( array $element, string $direction, string $tag ): string {
		$inner = '';
		if ( ! empty( $element['title'] ) ) {
			$inner .= $this->open( 'title', array( 'text' => $this->text_attr( (string) $element['title'] ), 'tag_name' => $tag, 'style' => 'normal' ) ) . "\n";
		}
		$inner .= $this->body_shortcode( (string) ( $element['content'] ?? '' ), $direction );
		return $inner;
	}

	private function body_shortcode( string $content, string $direction ): string {
		if ( '' === trim( $content ) ) {
			return '';
		}
		if ( ! str_contains( $content, '<' ) ) {
			$content = '<p>' . esc_html( $content ) . '</p>';
		}
		return $this->open( 'ux_text', array( 'class' => 'rtl' === $direction ? 'fmp-rtl' : '' ) ) . $this->safe_body_html( $content ) . '[/ux_text]';
	}

	private function attachment_html( int $media_id, string $size ): string {
		if ( $media_id < 1 ) {
			return '';
		}
		$size = $this->allowed( $size, array( 'thumbnail', 'medium', 'large', 'full' ) ) ?: 'large';
		return (string) wp_get_attachment_image( $media_id, $size, false, array( 'loading' => 'lazy' ) );
	}

	/** @return array{content:string,node_ids:array<string,string[]>,render_report:array<string,mixed>} */
	private function result( string $content, array $nodes, array $report ): array {
		ksort( $report['components'] );
		$report['components'] = array_values( $report['components'] );
		return array( 'content' => $content, 'node_ids' => $nodes, 'render_report' => $report );
	}

	/** @return array<string,string[]> */
	private function empty_nodes(): array {
		return array( 'sections' => array(), 'rows' => array(), 'columns' => array(), 'elements' => array() );
	}

	/** @return array<string,mixed> */
	private function empty_report(): array {
		return array( 'native_count' => 0, 'fallback_count' => 0, 'components' => array(), 'fallbacks' => array() );
	}

	/** @param array<string,mixed> $report */
	private function note_native( array &$report, string $type, string $shortcode ): void {
		++$report['native_count'];
		if ( ! isset( $report['components'][ $type ] ) ) {
			$report['components'][ $type ] = array( 'type' => $type, 'shortcode' => $shortcode, 'count' => 0 );
		}
		++$report['components'][ $type ]['count'];
	}

	/** @param array<string,mixed> $report */
	private function note_fallback( array &$report, string $id, string $type, string $reason ): void {
		++$report['fallback_count'];
		$report['fallbacks'][] = array( 'node_id' => $id, 'type' => $type, 'reason' => $reason );
	}

	private function node_id( string $type, string $requested ): string {
		$type      = sanitize_title( str_replace( '_', '-', $type ) );
		$requested = sanitize_html_class( strtolower( $requested ) );
		// The `fmp-` node prefix is frozen: it is written into generated page content
		// and is the handle agents use to address nodes in already-published pages.
		if ( preg_match( '/^fmp-' . preg_quote( $type, '/' ) . '-[a-z0-9-]{6,64}$/', $requested ) ) {
			return $requested;
		}
		return 'fmp-' . $type . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
	}

	private function open( string $tag, array $attrs ): string {
		$parts = array();
		foreach ( $attrs as $key => $value ) {
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			$parts[] = sanitize_key( (string) $key ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return '[' . $tag . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . ']';
	}

	private function marker_classes( array $element, string $id, string $direction ): string {
		$classes   = array_merge( array( 'fmp-node-' . $id ), $this->classes( (string) ( $element['class'] ?? '' ) ) );
		$classes[] = 'rtl' === $direction ? 'fmp-rtl' : '';
		return implode( ' ', array_filter( array_unique( $classes ) ) );
	}

	/** @return string[] */
	private function classes( string $classes ): array {
		$output = array();
		foreach ( preg_split( '/\s+/', $classes ) ?: array() as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$output[] = $class;
			}
		}
		return array_slice( array_unique( $output ), 0, 10 );
	}

	private function text_attr( string $value ): string {
		$value = sanitize_text_field( $value );
		return str_replace( array( '[', ']', '"' ), array( '', '', "'" ), $value );
	}

	private function safe_body_html( string $html ): string {
		$allowed = array(
			'p' => array( 'class' => true, 'dir' => true ), 'ul' => array( 'class' => true ), 'ol' => array( 'class' => true, 'start' => true ), 'li' => array( 'class' => true ),
			'a' => array( 'href' => true, 'target' => true, 'rel' => true, 'title' => true ), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
			'br' => array(), 'blockquote' => array( 'cite' => true, 'class' => true ), 'span' => array( 'class' => true, 'dir' => true ), 'code' => array(), 'small' => array(), 'sup' => array(), 'sub' => array(),
		);
		$html = wp_kses( $html, $allowed );
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html );
	}

	private function safe_fallback_html( string $html ): string {
		$html = wp_kses_post( $html );
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $html );
	}

	private function color( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) || preg_match( '/^rgba?\(\s*[0-9.%\s,]+\)$/i', $value ) ? $value : '';
	}

	private function dimension( string $value ): string {
		$value = trim( $value );
		$part  = '-?(?:\d+(?:\.\d+)?)(?:px|%|vh|vw|rem|em)?';
		return preg_match( '/^' . $part . '(?:\s+' . $part . '){0,3}$/i', $value ) ? $value : '';
	}

	private function position( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^(?:left|right|top|bottom|center|\d{1,3}%)(?:\s+(?:left|right|top|bottom|center|\d{1,3}%))?$/i', $value ) ? $value : '';
	}

	private function url( string $value ): string {
		return '' === $value ? '' : esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	private function allowed( string $value, array $allowed ): string {
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private function allowed_icon( string $value ): string {
		return preg_match( '/^icon-[a-z0-9-]{1,40}$/', $value ) ? $value : '';
	}

	private function media_id( mixed $value ): int|string {
		$value = absint( $value );
		return $value > 0 ? $value : '';
	}

	private function integer_range( mixed $value, int $min, int $max ): int|string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		return max( $min, min( $max, (int) $value ) );
	}

	private function number_range( mixed $value, float $min, float $max ): float|int|string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$value = max( $min, min( $max, (float) $value ) );
		return floor( $value ) === $value ? (int) $value : $value;
	}

	private function boolean_attr( mixed $value ): string {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'true' : 'false';
	}

	private function id_list( array $values ): string {
		return implode( ',', array_values( array_filter( array_map( 'absint', $values ) ) ) );
	}

	private function slug_list( array $values ): string {
		return implode( ',', array_values( array_filter( array_map( 'sanitize_title', $values ) ) ) );
	}

	/** @return string[] */
	private function social_networks(): array {
		return array( 'facebook', 'instagram', 'tiktok', 'snapchat', 'x', 'twitter', 'threads', 'linkedin', 'email', 'phone', 'pinterest', 'rss', 'youtube', 'flickr', 'vkontakte', 'px500', 'telegram', 'discord', 'twitch' );
	}
}
