<?php
/**
 * The builder-neutral page blueprint.
 *
 * One vocabulary of sections, rows, columns, and elements that every registered
 * builder knows how to render. Agents author this shape once; the site's active
 * builder decides what it becomes on disk.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blueprint {
	public const ELEMENTS = array(
		'heading',
		'text',
		'image',
		'button',
		'list',
		'quote',
		'video',
		'gallery',
		'separator',
		'spacer',
		'html',
	);

	/**
	 * JSON Schema for the neutral blueprint accepted by every builder.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'sections' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => self::section_schema(),
				),
			),
			'required'   => array( 'sections' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function section_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'label'               => array( 'type' => 'string', 'maxLength' => 100 ),
				'background_color'    => array( 'type' => 'string', 'maxLength' => 50 ),
				'background_image_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'dark'                => array( 'type' => 'boolean' ),
				'padding'             => array( 'type' => 'string', 'maxLength' => 30 ),
				'rows'                => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => self::row_schema(),
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function row_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'label'   => array( 'type' => 'string', 'maxLength' => 100 ),
				'columns' => array(
					'type'     => 'array',
					'maxItems' => 6,
					'items'    => self::column_schema(),
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function column_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'span'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Width in twelfths.' ),
				'align'    => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right' ) ),
				'elements' => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::element_schema(),
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function element_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'      => array( 'type' => 'string', 'enum' => self::ELEMENTS ),
				'text'      => array( 'type' => 'string', 'maxLength' => 2000, 'description' => 'Heading, button, or quote text.' ),
				'content'   => array( 'type' => 'string', 'maxLength' => 200000, 'description' => 'Body copy for text elements, or raw markup for html elements.' ),
				'level'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'description' => 'Heading level.' ),
				'align'     => array( 'type' => 'string', 'enum' => array( 'left', 'center', 'right', 'justify' ) ),
				'link'      => array( 'type' => 'string', 'maxLength' => 2048 ),
				'new_tab'   => array( 'type' => 'boolean' ),
				'style'     => array( 'type' => 'string', 'enum' => array( 'primary', 'secondary', 'outline' ), 'description' => 'Button emphasis.' ),
				'image_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
				'image_ids' => array( 'type' => 'array', 'maxItems' => 40, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'alt'       => array( 'type' => 'string', 'maxLength' => 300 ),
				'items'     => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'string', 'maxLength' => 1000 ) ),
				'ordered'   => array( 'type' => 'boolean' ),
				'cite'      => array( 'type' => 'string', 'maxLength' => 300 ),
				'url'       => array( 'type' => 'string', 'maxLength' => 2048, 'description' => 'Video URL.' ),
				'height'    => array( 'type' => 'string', 'maxLength' => 20, 'description' => 'Spacer height, for example 40px.' ),
			),
			'required'             => array( 'type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Resolve `auto` into the direction implied by the requested content locale.
	 */
	public static function resolve_direction( string $requested, string $content_locale = '' ): string {
		if ( in_array( $requested, array( 'ltr', 'rtl' ), true ) ) {
			return $requested;
		}
		if ( '' !== $content_locale ) {
			return in_array( substr( $content_locale, 0, 2 ), array( 'fa', 'ar', 'he', 'ur', 'ps', 'sd', 'yi', 'dv' ), true ) ? 'rtl' : 'ltr';
		}

		return is_rtl() ? 'rtl' : 'ltr';
	}

	/**
	 * Normalize a blueprint so every builder receives the same defaults.
	 *
	 * @param array<string,mixed> $blueprint
	 * @return array<string,mixed>
	 */
	public static function normalize( array $blueprint ): array {
		$sections = array();
		foreach ( (array) ( $blueprint['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$rows = array();
			foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$columns = array();
				foreach ( (array) ( $row['columns'] ?? array() ) as $column ) {
					if ( ! is_array( $column ) ) {
						continue;
					}
					$elements = array();
					foreach ( (array) ( $column['elements'] ?? array() ) as $element ) {
						if ( is_array( $element ) && in_array( (string) ( $element['type'] ?? '' ), self::ELEMENTS, true ) ) {
							$elements[] = $element;
						}
					}
					$columns[] = array(
						'span'     => max( 1, min( 12, absint( $column['span'] ?? 12 ) ) ),
						'align'    => (string) ( $column['align'] ?? '' ),
						'elements' => $elements,
					);
				}
				if ( ! $columns ) {
					$columns[] = array( 'span' => 12, 'align' => '', 'elements' => array() );
				}
				$rows[] = array( 'label' => (string) ( $row['label'] ?? '' ), 'columns' => $columns );
			}
			if ( ! $rows ) {
				$rows[] = array( 'label' => '', 'columns' => array( array( 'span' => 12, 'align' => '', 'elements' => array() ) ) );
			}
			$sections[] = array(
				'label'               => (string) ( $section['label'] ?? '' ),
				'background_color'    => (string) ( $section['background_color'] ?? '' ),
				'background_image_id' => absint( $section['background_image_id'] ?? 0 ),
				'dark'                => ! empty( $section['dark'] ),
				'padding'             => (string) ( $section['padding'] ?? '' ),
				'rows'                => $rows,
			);
		}

		return array( 'sections' => $sections );
	}
}
