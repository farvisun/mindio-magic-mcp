<?php
/**
 * Flatsome UX Builder adapter.
 *
 * Translates the neutral blueprint into the typed Flatsome element contract and
 * delegates the actual shortcode generation to the existing renderer, so native
 * component coverage and fallback reporting are unchanged.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Flatsome_Builder implements Page_Builder {
	private Flatsome_Renderer $renderer;
	private Flatsome_Component_Catalog $catalog;

	public function __construct( Flatsome_Renderer $renderer, Flatsome_Component_Catalog $catalog ) {
		$this->renderer = $renderer;
		$this->catalog  = $catalog;
	}

	public function id(): string {
		return 'flatsome';
	}

	public function label(): string {
		return __( 'Flatsome UX Builder', 'mindio-magic-mcp' );
	}

	public function is_available(): bool {
		return $this->catalog->flatsome_active();
	}

	/** @return array<int,string> */
	public function supported_elements(): array {
		return Blueprint::ELEMENTS;
	}

	public function owns_post( \WP_Post $post ): bool {
		return str_contains( $post->post_content, '[section' ) || str_contains( $post->post_content, '[row' );
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @return array{content:string,meta:array<string,mixed>,report:array<string,mixed>}|\WP_Error
	 */
	public function render( array $blueprint, string $direction ) {
		$sections = array();
		foreach ( (array) $blueprint['sections'] as $section ) {
			$sections[] = $this->map_section( $section );
		}

		$rendered = $this->renderer->render_page( $sections, $direction );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		return array(
			'content' => (string) $rendered['content'],
			'meta'    => array(),
			'report'  => (array) ( $rendered['render_report'] ?? $rendered ),
		);
	}

	/** @return array<string,mixed> */
	public function outline( \WP_Post $post ) {
		$sections = array();
		if ( preg_match_all( '/\[section\b([^\]]*)\](.*?)\[\/section\]/s', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $index => $match ) {
				$sections[] = array(
					'index'    => $index,
					'id'       => $this->attribute( $match[1], '_id' ),
					'label'    => $this->attribute( $match[1], 'label' ),
					'rows'     => $this->outline_rows( $match[2] ),
					'text'     => $this->plain_text( $match[2] ),
				);
			}
		}

		return array(
			'builder'  => $this->id(),
			'sections' => $sections,
		);
	}

	/**
	 * @param array<string,mixed> $section
	 * @return array<string,mixed>
	 */
	private function map_section( array $section ): array {
		$mapped = array( 'rows' => array() );
		foreach ( array( 'label', 'background_color', 'padding' ) as $field ) {
			if ( '' !== (string) $section[ $field ] ) {
				$mapped[ $field ] = (string) $section[ $field ];
			}
		}
		if ( $section['background_image_id'] ) {
			$mapped['background_image_id'] = (int) $section['background_image_id'];
		}
		if ( $section['dark'] ) {
			$mapped['dark'] = true;
		}

		foreach ( (array) $section['rows'] as $row ) {
			$columns = array();
			foreach ( (array) $row['columns'] as $column ) {
				$mapped_column = array( 'span' => (int) $column['span'], 'elements' => array() );
				if ( '' !== (string) $column['align'] ) {
					$mapped_column['align'] = (string) $column['align'];
				}
				foreach ( (array) $column['elements'] as $element ) {
					$mapped_element = $this->map_element( $element );
					if ( $mapped_element ) {
						$mapped_column['elements'][] = $mapped_element;
					}
				}
				$columns[] = $mapped_column;
			}
			$mapped_row = array( 'columns' => $columns );
			if ( '' !== (string) $row['label'] ) {
				$mapped_row['label'] = (string) $row['label'];
			}
			$mapped['rows'][] = $mapped_row;
		}

		return $mapped;
	}

	/**
	 * @param array<string,mixed> $element
	 * @return array<string,mixed>|null
	 */
	private function map_element( array $element ): ?array {
		$type = (string) $element['type'];

		return match ( $type ) {
			'heading' => array_filter(
				array(
					'type'  => 'title',
					'text'  => (string) ( $element['text'] ?? '' ),
					'tag'   => 'h' . max( 1, min( 4, absint( $element['level'] ?? 2 ) ) ),
					'style' => 'center' === ( $element['align'] ?? '' ) ? 'center' : 'normal',
					'link'  => (string) ( $element['link'] ?? '' ),
				),
				static fn( $value ): bool => '' !== $value
			),
			'text' => array_filter(
				array(
					'type'    => 'text',
					'content' => (string) ( $element['content'] ?? '' ),
					'align'   => (string) ( $element['align'] ?? '' ),
				),
				static fn( $value ): bool => '' !== $value
			),
			'image' => array_filter(
				array(
					'type'     => 'image',
					'image_id' => absint( $element['image_id'] ?? 0 ),
					'alt'      => (string) ( $element['alt'] ?? '' ),
					'link'     => (string) ( $element['link'] ?? '' ),
					'align'    => (string) ( $element['align'] ?? '' ),
				),
				static fn( $value ): bool => '' !== $value && 0 !== $value
			),
			'button' => array_filter(
				array(
					'type'    => 'button',
					'text'    => (string) ( $element['text'] ?? '' ),
					'link'    => (string) ( $element['link'] ?? '' ),
					'style'   => 'outline' === ( $element['style'] ?? '' ) ? 'outline' : 'default',
					'new_tab' => ! empty( $element['new_tab'] ),
				),
				static fn( $value ): bool => '' !== $value && false !== $value
			),
			'gallery' => array(
				'type'      => 'gallery',
				'image_ids' => array_map( 'absint', (array) ( $element['image_ids'] ?? array() ) ),
			),
			'video' => array( 'type' => 'video', 'url' => (string) ( $element['url'] ?? '' ) ),
			'separator' => array( 'type' => 'divider' ),
			'spacer' => array( 'type' => 'gap', 'height' => (string) ( $element['height'] ?? '30px' ) ),
			// Lists and quotes are body copy in Flatsome, so they ride inside a text component.
			'list' => array( 'type' => 'text', 'content' => $this->list_markup( $element ) ),
			'quote' => array( 'type' => 'text', 'content' => $this->quote_markup( $element ) ),
			'html' => array( 'type' => 'html', 'content' => (string) ( $element['content'] ?? '' ) ),
			default => null,
		};
	}

	/** @param array<string,mixed> $element */
	private function list_markup( array $element ): string {
		$tag   = ! empty( $element['ordered'] ) ? 'ol' : 'ul';
		$items = '';
		foreach ( (array) ( $element['items'] ?? array() ) as $item ) {
			$items .= '<li>' . esc_html( (string) $item ) . '</li>';
		}

		return '<' . $tag . '>' . $items . '</' . $tag . '>';
	}

	/** @param array<string,mixed> $element */
	private function quote_markup( array $element ): string {
		$quote = '<blockquote><p>' . esc_html( (string) ( $element['text'] ?? '' ) ) . '</p>';
		if ( ! empty( $element['cite'] ) ) {
			$quote .= '<cite>' . esc_html( (string) $element['cite'] ) . '</cite>';
		}

		return $quote . '</blockquote>';
	}

	/** @return array<int,array<string,mixed>> */
	private function outline_rows( string $section_content ): array {
		$rows = array();
		if ( preg_match_all( '/\[row\b([^\]]*)\](.*?)\[\/row\]/s', $section_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $index => $match ) {
				$columns = array();
				if ( preg_match_all( '/\[col\b([^\]]*)\](.*?)\[\/col\]/s', $match[2], $column_matches, PREG_SET_ORDER ) ) {
					foreach ( $column_matches as $column_index => $column ) {
						$columns[] = array(
							'index'    => $column_index,
							'id'       => $this->attribute( $column[1], '_id' ),
							'span'     => (int) ( $this->attribute( $column[1], 'span' ) ?: 12 ),
							'elements' => $this->outline_elements( $column[2] ),
						);
					}
				}
				$rows[] = array(
					'index'   => $index,
					'id'      => $this->attribute( $match[1], '_id' ),
					'columns' => $columns,
				);
			}
		}

		return $rows;
	}

	/** @return array<int,array<string,mixed>> */
	private function outline_elements( string $column_content ): array {
		$elements = array();
		if ( preg_match_all( '/\[([a-z_]+)\b([^\]]*)\]/', $column_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag = (string) $match[1];
				if ( in_array( $tag, array( 'row', 'col', 'section' ), true ) ) {
					continue;
				}
				$elements[] = array(
					'shortcode' => $tag,
					'type'      => $this->neutral_type( $tag ),
					'id'        => $this->attribute( $match[2], '_id' ),
				);
			}
		}

		return $elements;
	}

	private function neutral_type( string $shortcode ): string {
		return match ( $shortcode ) {
			'title'   => 'heading',
			'ux_text', 'text_box' => 'text',
			'ux_image', 'ux_banner' => 'image',
			'button'  => 'button',
			'ux_gallery' => 'gallery',
			'ux_video' => 'video',
			'divider' => 'separator',
			'gap'     => 'spacer',
			default   => 'html',
		};
	}

	private function attribute( string $attributes, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '="([^"]*)"/', $attributes, $match ) ) {
			return (string) $match[1];
		}

		return '';
	}

	private function plain_text( string $content ): string {
		$text = wp_strip_all_tags( preg_replace( '/\[[^\]]*\]/', ' ', $content ) ?? '' );

		return trim( mb_substr( preg_replace( '/\s+/u', ' ', $text ) ?? '', 0, 400 ) );
	}
}
