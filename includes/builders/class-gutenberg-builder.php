<?php
/**
 * Core block adapter.
 *
 * Always available, because every WordPress installation ships the block
 * editor. This is the fallback builder on sites without a page builder.
 *
 * @package MindioMagicMCP;
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gutenberg_Builder implements Page_Builder {
	public function id(): string {
		return 'gutenberg';
	}

	public function label(): string {
		return __( 'Core blocks', 'mindio-magic-mcp' );
	}

	public function is_available(): bool {
		return true;
	}

	/** @return array<int,string> */
	public function supported_elements(): array {
		return Blueprint::ELEMENTS;
	}

	public function owns_post( \WP_Post $post ): bool {
		return str_contains( $post->post_content, '<!-- wp:' );
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @return array{content:string,meta:array<string,mixed>,report:array<string,mixed>}
	 */
	public function render( array $blueprint, string $direction ) {
		$content  = '';
		$counts   = array();
		$fallbacks = array();

		foreach ( (array) $blueprint['sections'] as $section ) {
			$inner = '';
			foreach ( (array) $section['rows'] as $row ) {
				$columns = (array) $row['columns'];
				if ( 1 === count( $columns ) ) {
					$inner .= $this->render_elements( $columns[0], $direction, $counts, $fallbacks );
					continue;
				}
				$column_markup = '';
				foreach ( $columns as $column ) {
					$width          = round( ( (int) $column['span'] / 12 ) * 100, 2 );
					$column_markup .= '<!-- wp:column {"width":"' . $width . '%"} --><div class="wp-block-column" style="flex-basis:' . $width . '%">';
					$column_markup .= $this->render_elements( $column, $direction, $counts, $fallbacks );
					$column_markup .= '</div><!-- /wp:column -->';
				}
				$inner .= '<!-- wp:columns --><div class="wp-block-columns">' . $column_markup . '</div><!-- /wp:columns -->';
			}
			$content .= $this->wrap_section( $section, $inner, $direction );
		}

		return array(
			'content' => $content,
			'meta'    => array(),
			'report'  => array(
				'builder'        => $this->id(),
				'native_count'   => array_sum( $counts ),
				'fallback_count' => count( $fallbacks ),
				'components'     => $counts,
				'fallbacks'      => $fallbacks,
			),
		);
	}

	/** @return array<string,mixed> */
	public function outline( \WP_Post $post ) {
		$blocks   = parse_blocks( $post->post_content );
		$sections = array();

		foreach ( $blocks as $index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$sections[] = array(
				'index'    => $index,
				'id'       => (string) ( $block['attrs']['metadata']['name'] ?? '' ),
				'label'    => (string) ( $block['attrs']['metadata']['name'] ?? '' ),
				'rows'     => $this->outline_rows( $block ),
				'text'     => $this->plain_text( render_block( $block ) ),
			);
		}

		return array( 'builder' => $this->id(), 'sections' => $sections );
	}

	/**
	 * @param array<string,mixed> $section
	 */
	private function wrap_section( array $section, string $inner, string $direction ): string {
		$style   = array();
		$classes = array( 'mindio-section' );
		if ( '' !== (string) $section['background_color'] ) {
			$style[] = 'background-color:' . sanitize_hex_color( (string) $section['background_color'] ) ?: '';
		}
		if ( $section['background_image_id'] ) {
			$url = wp_get_attachment_image_url( (int) $section['background_image_id'], 'full' );
			if ( $url ) {
				$style[] = 'background-image:url(' . esc_url( $url ) . ');background-size:cover';
			}
		}
		if ( '' !== (string) $section['padding'] ) {
			$style[] = 'padding:' . preg_replace( '/[^0-9a-z%\s.]/i', '', (string) $section['padding'] );
		}
		if ( $section['dark'] ) {
			$classes[] = 'has-white-color has-text-color';
		}
		if ( 'rtl' === $direction ) {
			$classes[] = 'fmp-rtl';
		}

		$attributes = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		if ( $style ) {
			$attributes .= ' style="' . esc_attr( implode( ';', array_filter( $style ) ) ) . '"';
		}

		return '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><div' . $attributes . '>'
			. $inner
			. '</div></div><!-- /wp:group -->';
	}

	/**
	 * @param array<string,mixed>   $column
	 * @param array<string,int>     $counts
	 * @param array<int,array<string,mixed>> $fallbacks
	 */
	private function render_elements( array $column, string $direction, array &$counts, array &$fallbacks ): string {
		$markup = '';
		$align  = (string) $column['align'];

		foreach ( (array) $column['elements'] as $element ) {
			$type = (string) $element['type'];
			$rendered = $this->render_element( $element, $align, $direction );
			if ( '' === $rendered ) {
				$fallbacks[] = array( 'type' => $type, 'reason' => 'unsupported_element' );
				continue;
			}
			$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
			$markup         .= $rendered;
		}

		return $markup;
	}

	/** @param array<string,mixed> $element */
	private function render_element( array $element, string $column_align, string $direction ): string {
		$align = (string) ( $element['align'] ?? $column_align );
		$attrs = '' !== $align ? array( 'textAlign' => $align ) : array();
		$class = '' !== $align ? ' has-text-align-' . $align : '';
		if ( 'rtl' === $direction ) {
			$class .= ' fmp-rtl';
		}

		switch ( (string) $element['type'] ) {
			case 'heading':
				$level = max( 1, min( 4, absint( $element['level'] ?? 2 ) ) );
				$text  = esc_html( (string) ( $element['text'] ?? '' ) );
				if ( ! empty( $element['link'] ) ) {
					$text = '<a href="' . esc_url( (string) $element['link'] ) . '">' . $text . '</a>';
				}
				$attrs['level'] = $level;
				return '<!-- wp:heading ' . $this->attrs( $attrs ) . ' --><h' . $level . ' class="wp-block-heading' . $class . '">' . $text . '</h' . $level . '><!-- /wp:heading -->';

			case 'text':
				return '<!-- wp:paragraph ' . $this->attrs( $attrs ) . ' --><p class="' . trim( $class ) . '">' . wp_kses_post( (string) ( $element['content'] ?? '' ) ) . '</p><!-- /wp:paragraph -->';

			case 'image':
				$id  = absint( $element['image_id'] ?? 0 );
				$url = $id ? wp_get_attachment_image_url( $id, 'large' ) : '';
				if ( ! $url ) {
					return '';
				}
				$image = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( (string) ( $element['alt'] ?? '' ) ) . '" class="wp-image-' . $id . '"/>';
				if ( ! empty( $element['link'] ) ) {
					$image = '<a href="' . esc_url( (string) $element['link'] ) . '">' . $image . '</a>';
				}
				return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large">' . $image . '</figure><!-- /wp:image -->';

			case 'button':
				$style_class = 'outline' === ( $element['style'] ?? '' ) ? ' is-style-outline' : '';
				$target      = ! empty( $element['new_tab'] ) ? ' target="_blank" rel="noreferrer noopener"' : '';
				return '<!-- wp:buttons --><div class="wp-block-buttons">'
					. '<!-- wp:button' . ( $style_class ? ' {"className":"is-style-outline"}' : '' ) . ' -->'
					. '<div class="wp-block-button' . $style_class . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url( (string) ( $element['link'] ?? '' ) ) . '"' . $target . '>'
					. esc_html( (string) ( $element['text'] ?? '' ) )
					. '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

			case 'list':
				$tag   = ! empty( $element['ordered'] ) ? 'ol' : 'ul';
				$items = '';
				foreach ( (array) ( $element['items'] ?? array() ) as $item ) {
					$items .= '<!-- wp:list-item --><li>' . esc_html( (string) $item ) . '</li><!-- /wp:list-item -->';
				}
				$ordered = ! empty( $element['ordered'] ) ? ' {"ordered":true}' : '';
				return '<!-- wp:list' . $ordered . ' --><' . $tag . ' class="wp-block-list">' . $items . '</' . $tag . '><!-- /wp:list -->';

			case 'quote':
				$cite = ! empty( $element['cite'] ) ? '<cite>' . esc_html( (string) $element['cite'] ) . '</cite>' : '';
				return '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>'
					. esc_html( (string) ( $element['text'] ?? '' ) )
					. '</p><!-- /wp:paragraph -->' . $cite . '</blockquote><!-- /wp:quote -->';

			case 'video':
				$url = esc_url( (string) ( $element['url'] ?? '' ) );
				if ( '' === $url ) {
					return '';
				}
				return '<!-- wp:embed {"url":"' . $url . '"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . $url . '</div></figure><!-- /wp:embed -->';

			case 'gallery':
				$ids = array_map( 'absint', (array) ( $element['image_ids'] ?? array() ) );
				$ids = array_values( array_filter( $ids ) );
				if ( ! $ids ) {
					return '';
				}
				$images = '';
				foreach ( $ids as $id ) {
					$url = wp_get_attachment_image_url( $id, 'large' );
					if ( $url ) {
						$images .= '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . esc_url( $url ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
					}
				}
				return '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images">' . $images . '</figure><!-- /wp:gallery -->';

			case 'separator':
				return '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';

			case 'spacer':
				$height = preg_replace( '/[^0-9a-z%.]/i', '', (string) ( $element['height'] ?? '40px' ) ) ?: '40px';
				return '<!-- wp:spacer {"height":"' . esc_attr( $height ) . '"} --><div style="height:' . esc_attr( $height ) . '" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->';

			case 'html':
				return '<!-- wp:html -->' . wp_kses_post( (string) ( $element['content'] ?? '' ) ) . '<!-- /wp:html -->';
		}

		return '';
	}

	/** @param array<string,mixed> $attrs */
	private function attrs( array $attrs ): string {
		return $attrs ? (string) wp_json_encode( $attrs ) : '';
	}

	/**
	 * @param array<string,mixed> $block
	 * @return array<int,array<string,mixed>>
	 */
	private function outline_rows( array $block ): array {
		$rows = array();
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $index => $inner ) {
			$columns = array();
			if ( 'core/columns' === ( $inner['blockName'] ?? '' ) ) {
				foreach ( (array) $inner['innerBlocks'] as $column_index => $column ) {
					$columns[] = array(
						'index'    => $column_index,
						'span'     => (int) round( ( (float) str_replace( '%', '', (string) ( $column['attrs']['width'] ?? '100' ) ) / 100 ) * 12 ),
						'elements' => $this->outline_elements( (array) $column['innerBlocks'] ),
					);
				}
			} else {
				$columns[] = array( 'index' => 0, 'span' => 12, 'elements' => $this->outline_elements( array( $inner ) ) );
			}
			$rows[] = array( 'index' => $index, 'columns' => $columns );
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @return array<int,array<string,mixed>>
	 */
	private function outline_elements( array $blocks ): array {
		$elements = array();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$elements[] = array(
				'block' => $name,
				'type'  => $this->neutral_type( $name ),
				'text'  => $this->plain_text( render_block( $block ) ),
			);
		}

		return $elements;
	}

	private function neutral_type( string $block_name ): string {
		return match ( $block_name ) {
			'core/heading'   => 'heading',
			'core/paragraph' => 'text',
			'core/image'     => 'image',
			'core/buttons', 'core/button' => 'button',
			'core/list'      => 'list',
			'core/quote'     => 'quote',
			'core/embed', 'core/video' => 'video',
			'core/gallery'   => 'gallery',
			'core/separator' => 'separator',
			'core/spacer'    => 'spacer',
			default          => 'html',
		};
	}

	private function plain_text( string $html ): string {
		$text = wp_strip_all_tags( $html );

		return trim( mb_substr( preg_replace( '/\s+/u', ' ', $text ) ?? '', 0, 400 ) );
	}
}
