<?php
/**
 * Elementor adapter.
 *
 * Elementor stores its document tree as JSON in `_elementor_data` and renders
 * from that rather than from post_content, so this builder returns the JSON as
 * meta and leaves post_content holding a readable plain-text mirror.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Builder implements Page_Builder {
	public function id(): string {
		return 'elementor';
	}

	public function label(): string {
		return __( 'Elementor', 'mindio-magic-mcp' );
	}

	public function is_available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' );
	}

	/**
	 * Elementor has no first-class gallery widget in the free tier that maps
	 * cleanly from the neutral vocabulary, so galleries degrade to images.
	 *
	 * @return array<int,string>
	 */
	public function supported_elements(): array {
		return array( 'heading', 'text', 'image', 'button', 'list', 'quote', 'video', 'separator', 'spacer', 'html' );
	}

	public function owns_post( \WP_Post $post ): bool {
		return '' !== (string) get_post_meta( $post->ID, '_elementor_data', true );
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @return array{content:string,meta:array<string,mixed>,report:array<string,mixed>}
	 */
	public function render( array $blueprint, string $direction ) {
		$document  = array();
		$counts    = array();
		$fallbacks = array();
		$mirror    = '';

		foreach ( (array) $blueprint['sections'] as $section ) {
			$section_columns = array();
			foreach ( (array) $section['rows'] as $row ) {
				foreach ( (array) $row['columns'] as $column ) {
					$widgets = array();
					foreach ( (array) $column['elements'] as $element ) {
						$widget = $this->widget( $element, $direction );
						if ( null === $widget ) {
							$fallbacks[] = array( 'type' => (string) $element['type'], 'reason' => 'unsupported_element' );
							continue;
						}
						$type            = (string) $element['type'];
						$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
						$widgets[]       = $widget;
						$mirror         .= $this->mirror_text( $element );
					}
					$section_columns[] = array(
						'id'       => $this->element_id(),
						'elType'   => 'column',
						'settings' => array( '_column_size' => (int) round( ( (int) $column['span'] / 12 ) * 100 ) ),
						'elements' => $widgets,
					);
				}
			}

			$document[] = array(
				'id'       => $this->element_id(),
				'elType'   => 'section',
				'settings' => $this->section_settings( $section, $direction ),
				'elements' => $section_columns,
			);
		}

		return array(
			'content' => $mirror,
			'meta'    => array(
				'_elementor_data'          => (string) wp_json_encode( $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'_elementor_edit_mode'     => 'builder',
				'_elementor_template_type' => 'wp-page',
			),
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
		$document = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
		if ( ! is_array( $document ) ) {
			return array( 'builder' => $this->id(), 'sections' => array() );
		}

		$sections = array();
		foreach ( $document as $index => $section ) {
			$rows = array();
			$columns = array();
			foreach ( (array) ( $section['elements'] ?? array() ) as $column_index => $column ) {
				$elements = array();
				foreach ( (array) ( $column['elements'] ?? array() ) as $widget ) {
					$elements[] = array(
						'widget' => (string) ( $widget['widgetType'] ?? '' ),
						'type'   => $this->neutral_type( (string) ( $widget['widgetType'] ?? '' ) ),
						'id'     => (string) ( $widget['id'] ?? '' ),
						'text'   => $this->widget_text( (array) ( $widget['settings'] ?? array() ) ),
					);
				}
				$columns[] = array(
					'index'    => $column_index,
					'id'       => (string) ( $column['id'] ?? '' ),
					'span'     => (int) round( ( (int) ( $column['settings']['_column_size'] ?? 100 ) / 100 ) * 12 ),
					'elements' => $elements,
				);
			}
			$rows[]     = array( 'index' => 0, 'columns' => $columns );
			$sections[] = array(
				'index' => $index,
				'id'    => (string) ( $section['id'] ?? '' ),
				'label' => '',
				'rows'  => $rows,
				'text'  => '',
			);
		}

		return array( 'builder' => $this->id(), 'sections' => $sections );
	}

	/**
	 * @param array<string,mixed> $section
	 * @return array<string,mixed>
	 */
	private function section_settings( array $section, string $direction ): array {
		$settings = array( 'layout' => 'boxed' );
		if ( '' !== (string) $section['background_color'] ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = (string) $section['background_color'];
		}
		if ( $section['background_image_id'] ) {
			$settings['background_background'] = 'classic';
			$settings['background_image']      = array(
				'id'  => (int) $section['background_image_id'],
				'url' => (string) wp_get_attachment_image_url( (int) $section['background_image_id'], 'full' ),
			);
		}
		if ( 'rtl' === $direction ) {
			$settings['_css_classes'] = 'fmp-rtl';
		}

		return $settings;
	}

	/**
	 * @param array<string,mixed> $element
	 * @return array<string,mixed>|null
	 */
	private function widget( array $element, string $direction ): ?array {
		$align = (string) ( $element['align'] ?? '' );

		$widget = match ( (string) $element['type'] ) {
			'heading' => array(
				'widgetType' => 'heading',
				'settings'   => array(
					'title'       => (string) ( $element['text'] ?? '' ),
					'header_size' => 'h' . max( 1, min( 4, absint( $element['level'] ?? 2 ) ) ),
					'link'        => ! empty( $element['link'] ) ? array( 'url' => (string) $element['link'] ) : array(),
				),
			),
			'text' => array(
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => (string) ( $element['content'] ?? '' ) ),
			),
			'image' => array(
				'widgetType' => 'image',
				'settings'   => array(
					'image' => array(
						'id'  => absint( $element['image_id'] ?? 0 ),
						'url' => (string) wp_get_attachment_image_url( absint( $element['image_id'] ?? 0 ), 'large' ),
					),
					'link'  => ! empty( $element['link'] ) ? array( 'url' => (string) $element['link'] ) : array(),
				),
			),
			'button' => array(
				'widgetType' => 'button',
				'settings'   => array(
					'text' => (string) ( $element['text'] ?? '' ),
					'link' => array(
						'url'         => (string) ( $element['link'] ?? '' ),
						'is_external' => ! empty( $element['new_tab'] ),
					),
					'button_type' => 'outline' === ( $element['style'] ?? '' ) ? 'info' : 'success',
				),
			),
			'list' => array(
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => $this->list_markup( $element ) ),
			),
			'quote' => array(
				'widgetType' => 'blockquote',
				'settings'   => array(
					'blockquote_content' => (string) ( $element['text'] ?? '' ),
					'author_name'        => (string) ( $element['cite'] ?? '' ),
				),
			),
			'video' => array(
				'widgetType' => 'video',
				'settings'   => array( 'youtube_url' => (string) ( $element['url'] ?? '' ) ),
			),
			'separator' => array( 'widgetType' => 'divider', 'settings' => array() ),
			'spacer' => array(
				'widgetType' => 'spacer',
				'settings'   => array( 'space' => array( 'unit' => 'px', 'size' => (int) preg_replace( '/\D/', '', (string) ( $element['height'] ?? '40' ) ) ) ),
			),
			'html' => array(
				'widgetType' => 'html',
				'settings'   => array( 'html' => (string) ( $element['content'] ?? '' ) ),
			),
			default => null,
		};

		if ( null === $widget ) {
			return null;
		}

		if ( '' !== $align ) {
			$widget['settings']['align'] = $align;
		}
		if ( 'rtl' === $direction ) {
			$widget['settings']['_css_classes'] = 'fmp-rtl';
		}

		return array(
			'id'         => $this->element_id(),
			'elType'     => 'widget',
			'widgetType' => $widget['widgetType'],
			'settings'   => $widget['settings'],
			'elements'   => array(),
		);
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
	private function mirror_text( array $element ): string {
		$text = (string) ( $element['text'] ?? '' );
		$body = (string) ( $element['content'] ?? '' );
		if ( '' !== $text ) {
			return '<p>' . esc_html( $text ) . '</p>';
		}
		if ( '' !== $body ) {
			return wp_kses_post( $body );
		}

		return '';
	}

	/** @param array<string,mixed> $settings */
	private function widget_text( array $settings ): string {
		foreach ( array( 'title', 'editor', 'text', 'blockquote_content', 'html' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				return trim( mb_substr( wp_strip_all_tags( $settings[ $key ] ), 0, 400 ) );
			}
		}

		return '';
	}

	private function neutral_type( string $widget ): string {
		return match ( $widget ) {
			'heading'     => 'heading',
			'text-editor' => 'text',
			'image'       => 'image',
			'button'      => 'button',
			'blockquote'  => 'quote',
			'video'       => 'video',
			'divider'     => 'separator',
			'spacer'      => 'spacer',
			default       => 'html',
		};
	}

	private function element_id(): string {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}
}
