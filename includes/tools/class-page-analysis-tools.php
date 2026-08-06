<?php
/**
 * Structural read of a built page.
 *
 * Lets an agent understand what a page already contains — its sections, their
 * node IDs, and the shape of the copy — so it can edit one part surgically
 * instead of regenerating the whole document.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Page_Analysis_Tools {
	private Tool_Registry $registry;
	private Page_Builder_Registry $builders;

	public function __construct( Tool_Registry $registry, Page_Builder_Registry $builders ) {
		$this->registry = $registry;
		$this->builders = $builders;
	}

	public function register(): void {
		$this->registry->register(
			'explain_page',
			__( 'Read a built page as a structured outline: which builder made it, its sections, rows, columns, and elements with their stable node IDs, plus heading structure, word counts, link and image inventory, and accessibility gaps. Use this before editing so changes can target one node instead of replacing the page.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
					'include_text' => array(
						'type'        => 'boolean',
						'description' => __( 'Include a short text excerpt for every element. On by default.', 'mindio-magic-mcp' ),
					),
				),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'explain' ),
			Auth::SCOPE_READ,
			fn( array $args ): bool => current_user_can( 'read_post', absint( $args['post_id'] ?? 0 ) ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function explain( array $args ) {
		$post = get_post( absint( $args['post_id'] ) );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'unknown_post', __( 'The requested page does not exist.', 'mindio-magic-mcp' ) );
		}

		$include_text = ! array_key_exists( 'include_text', $args ) || ! empty( $args['include_text'] );
		$builder      = $this->builders->detect( $post );
		$outline      = $builder->outline( $post );
		if ( is_wp_error( $outline ) ) {
			return $outline;
		}

		$sections = $this->shape_sections( (array) ( $outline['sections'] ?? array() ), $include_text );
		$rendered = $this->rendered_html( $post );

		return array(
			'post_id'      => $post->ID,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'permalink'    => (string) get_permalink( $post ),
			'modified_gmt' => $post->post_modified_gmt,
			'builder'      => array(
				'id'    => $builder->id(),
				'label' => $builder->label(),
			),
			'summary'      => array(
				'sections'      => count( $sections ),
				'elements'      => $this->count_elements( $sections ),
				'element_types' => $this->count_types( $sections ),
				'word_count'    => $this->word_count( $rendered ),
			),
			'headings'     => $this->headings( $rendered ),
			'media'        => $this->media( $rendered ),
			'links'        => $this->links( $rendered, $post ),
			'sections'     => $sections,
			'editing'      => $this->editing_hints( $builder ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 * @return array<int,array<string,mixed>>
	 */
	private function shape_sections( array $sections, bool $include_text ): array {
		$shaped = array();
		foreach ( $sections as $section ) {
			$rows = array();
			foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
				$columns = array();
				foreach ( (array) ( $row['columns'] ?? array() ) as $column ) {
					$elements = array();
					foreach ( (array) ( $column['elements'] ?? array() ) as $element ) {
						$shaped_element = array(
							'type' => (string) ( $element['type'] ?? 'html' ),
							'id'   => (string) ( $element['id'] ?? '' ),
						);
						foreach ( array( 'shortcode', 'block', 'widget' ) as $native ) {
							if ( ! empty( $element[ $native ] ) ) {
								$shaped_element['native'] = (string) $element[ $native ];
							}
						}
						if ( $include_text && ! empty( $element['text'] ) ) {
							$shaped_element['text'] = (string) $element['text'];
						}
						$elements[] = $shaped_element;
					}
					$columns[] = array(
						'index'    => (int) ( $column['index'] ?? 0 ),
						'id'       => (string) ( $column['id'] ?? '' ),
						'span'     => (int) ( $column['span'] ?? 12 ),
						'elements' => $elements,
					);
				}
				$rows[] = array(
					'index'   => (int) ( $row['index'] ?? 0 ),
					'id'      => (string) ( $row['id'] ?? '' ),
					'columns' => $columns,
				);
			}

			$shaped_section = array(
				'index' => (int) ( $section['index'] ?? 0 ),
				'id'    => (string) ( $section['id'] ?? '' ),
				'label' => (string) ( $section['label'] ?? '' ),
				'rows'  => $rows,
			);
			if ( $include_text && ! empty( $section['text'] ) ) {
				$shaped_section['text'] = (string) $section['text'];
			}
			$shaped[] = $shaped_section;
		}

		return $shaped;
	}

	/** @param array<int,array<string,mixed>> $sections */
	private function count_elements( array $sections ): int {
		$count = 0;
		foreach ( $sections as $section ) {
			foreach ( $section['rows'] as $row ) {
				foreach ( $row['columns'] as $column ) {
					$count += count( $column['elements'] );
				}
			}
		}

		return $count;
	}

	/**
	 * @param array<int,array<string,mixed>> $sections
	 * @return array<string,int>
	 */
	private function count_types( array $sections ): array {
		$counts = array();
		foreach ( $sections as $section ) {
			foreach ( $section['rows'] as $row ) {
				foreach ( $row['columns'] as $column ) {
					foreach ( $column['elements'] as $element ) {
						$type            = (string) $element['type'];
						$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
					}
				}
			}
		}
		arsort( $counts );

		return $counts;
	}

	/**
	 * Render the page the way a visitor sees it, so shortcodes and blocks both resolve.
	 */
	private function rendered_html( \WP_Post $post ): string {
		$content = (string) apply_filters( 'the_content', $post->post_content );

		return is_string( $content ) ? $content : '';
	}

	private function word_count( string $html ): int {
		$text = wp_strip_all_tags( $html );

		return str_word_count( $text ) ?: (int) ( mb_strlen( trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' ) ) / 5 );
	}

	/**
	 * Heading outline plus the two problems agents most often need to fix.
	 *
	 * @return array<string,mixed>
	 */
	private function headings( string $html ): array {
		$outline = array();
		if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$outline[] = array(
					'level' => (int) $match[1],
					'text'  => trim( wp_strip_all_tags( $match[2] ) ),
				);
			}
		}

		$levels    = wp_list_pluck( $outline, 'level' );
		$skipped   = false;
		$previous  = 0;
		foreach ( $levels as $level ) {
			if ( $previous && $level > $previous + 1 ) {
				$skipped = true;
				break;
			}
			$previous = $level;
		}

		return array(
			'outline'        => $outline,
			'h1_count'       => count( array_filter( $levels, static fn( int $level ): bool => 1 === $level ) ),
			'skipped_levels' => $skipped,
		);
	}

	/** @return array<string,mixed> */
	private function media( string $html ): array {
		$images     = array();
		$missing_alt = 0;
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				$alt = '';
				if ( preg_match( '/\balt="([^"]*)"/i', $tag, $alt_match ) ) {
					$alt = trim( (string) $alt_match[1] );
				}
				$source = '';
				if ( preg_match( '/\bsrc="([^"]*)"/i', $tag, $src_match ) ) {
					$source = (string) $src_match[1];
				}
				if ( '' === $alt ) {
					++$missing_alt;
				}
				$images[] = array( 'src' => $source, 'alt' => $alt );
			}
		}

		return array(
			'image_count'        => count( $images ),
			'missing_alt_count'  => $missing_alt,
			'images'             => array_slice( $images, 0, 50 ),
		);
	}

	/** @return array<string,mixed> */
	private function links( string $html, \WP_Post $post ): array {
		$home     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$internal = 0;
		$external = 0;
		$links    = array();

		if ( preg_match_all( '/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$url  = (string) $match[1];
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( ! $host || $host === $home ) {
					++$internal;
				} else {
					++$external;
				}
				$links[] = array(
					'url'      => $url,
					'text'     => trim( wp_strip_all_tags( $match[2] ) ),
					'external' => (bool) ( $host && $host !== $home ),
				);
			}
		}
		unset( $post );

		return array(
			'internal_count' => $internal,
			'external_count' => $external,
			'links'          => array_slice( $links, 0, 100 ),
		);
	}

	/** @return array<string,mixed> */
	private function editing_hints( Page_Builder $builder ): array {
		$hints = array(
			'flatsome'  => array(
				'append_section' => 'add_section',
				'append_row'     => 'add_row',
				'append_element' => 'add_element',
				'note'           => __( 'Section, row, and column IDs above address existing nodes for incremental edits.', 'mindio-magic-mcp' ),
			),
			'gutenberg' => array(
				'insert' => 'insert_block',
				'replace' => 'replace_block',
				'note'   => __( 'Use the Gutenberg block tools, which operate on the parsed block tree.', 'mindio-magic-mcp' ),
			),
			'elementor' => array(
				'rebuild' => 'update_builder_page',
				'note'    => __( 'Elementor documents are replaced as a whole; re-send the full blueprint.', 'mindio-magic-mcp' ),
			),
		);

		return $hints[ $builder->id() ] ?? array( 'rebuild' => 'update_builder_page' );
	}
}
