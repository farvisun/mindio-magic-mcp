<?php
/**
 * Builder-neutral page tools.
 *
 * One contract for authoring pages, whichever builder the site actually runs.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Builder_Tools {
	private Tool_Registry $registry;
	private Page_Builder_Registry $builders;

	public function __construct( Tool_Registry $registry, Page_Builder_Registry $builders ) {
		$this->registry = $registry;
		$this->builders = $builders;
	}

	public function register(): void {
		$this->registry->register(
			'list_page_builders',
			__( 'List the page builders this site can author with, which one is preferred, and the neutral element types each supports.', 'mindio-magic-mcp' ),
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			array( 'type' => 'object' ),
			array( $this, 'list_builders' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);

		$this->registry->register(
			'create_builder_page',
			__( 'Create a page from one builder-neutral blueprint. The site\'s active builder decides whether it becomes Flatsome shortcodes, core blocks, or Elementor widgets.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'title'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
					'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ),
					'builder'        => $this->builder_argument(),
					'direction'      => array( 'type' => 'string', 'enum' => array( 'auto', 'ltr', 'rtl' ) ),
					'content_locale' => array( 'type' => 'string', 'maxLength' => 20 ),
					'blueprint'      => Blueprint::schema(),
				),
				'required'   => array( 'title', 'blueprint' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'create_page' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_create_page' )
		);

		$this->registry->register(
			'update_builder_page',
			__( 'Replace the body of an existing page with a builder-neutral blueprint, keeping the builder the page already uses unless another is named.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
					'builder'               => $this->builder_argument(),
					'direction'             => array( 'type' => 'string', 'enum' => array( 'auto', 'ltr', 'rtl' ) ),
					'content_locale'        => array( 'type' => 'string', 'maxLength' => 20 ),
					'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
					'blueprint'             => Blueprint::schema(),
				),
				'required'   => array( 'post_id', 'blueprint' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'update_page' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) )
		);
	}

	/** @return array<string,mixed> */
	private function builder_argument(): array {
		return array(
			'type'        => 'string',
			'enum'        => array_merge( array( 'auto' ), $this->builders->ids() ),
			'description' => __( 'Which builder to render with. Defaults to auto, which picks the site\'s active builder.', 'mindio-magic-mcp' ),
		);
	}

	/** @return array<string,mixed> */
	public function list_builders(): array {
		return array(
			'preferred' => $this->builders->preferred()->id(),
			'builders'  => $this->builders->catalog(),
			'elements'  => Blueprint::ELEMENTS,
		);
	}

	/** @param array<string,mixed> $args */
	public function can_create_page( array $args ): bool {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return false;
		}

		return ! in_array( $args['status'] ?? 'draft', array( 'publish', 'private' ), true ) || current_user_can( 'publish_pages' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_page( array $args ) {
		$builder = $this->builders->resolve( (string) ( $args['builder'] ?? 'auto' ) );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$rendered = $this->render( $builder, $args );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( (string) $args['title'] ),
				'post_content' => $rendered['content'],
				'post_status'  => (string) ( $args['status'] ?? 'draft' ),
				'post_type'    => 'page',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		foreach ( (array) $rendered['meta'] as $meta_key => $meta_value ) {
			update_post_meta( (int) $post_id, (string) $meta_key, $meta_value );
		}

		return array(
			'post_id'       => (int) $post_id,
			'builder'       => $builder->id(),
			'permalink'     => (string) get_permalink( (int) $post_id ),
			'edit_link'     => (string) get_edit_post_link( (int) $post_id, 'raw' ),
			'modified_gmt'  => (string) get_post_field( 'post_modified_gmt', (int) $post_id ),
			'render_report' => $rendered['report'],
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_page( array $args ) {
		$post = get_post( absint( $args['post_id'] ) );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'unknown_post', __( 'The requested page does not exist.', 'mindio-magic-mcp' ) );
		}

		$expected = (string) ( $args['expected_modified_gmt'] ?? '' );
		if ( '' !== $expected && $expected !== $post->post_modified_gmt ) {
			return new \WP_Error( 'conflict', __( 'The page changed since it was read. Re-read it before writing.', 'mindio-magic-mcp' ) );
		}

		$requested = (string) ( $args['builder'] ?? 'auto' );
		$builder   = 'auto' === $requested || '' === $requested
			? $this->builders->detect( $post )
			: $this->builders->resolve( $requested );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$rendered = $this->render( $builder, $args );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		$updated = wp_update_post(
			array( 'ID' => $post->ID, 'post_content' => $rendered['content'] ),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		foreach ( (array) $rendered['meta'] as $meta_key => $meta_value ) {
			update_post_meta( $post->ID, (string) $meta_key, $meta_value );
		}

		return array(
			'post_id'       => $post->ID,
			'builder'       => $builder->id(),
			'permalink'     => (string) get_permalink( $post ),
			'modified_gmt'  => (string) get_post_field( 'post_modified_gmt', $post->ID ),
			'render_report' => $rendered['report'],
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array{content:string,meta:array<string,mixed>,report:array<string,mixed>}|\WP_Error
	 */
	private function render( Page_Builder $builder, array $args ) {
		$direction = Blueprint::resolve_direction(
			(string) ( $args['direction'] ?? 'auto' ),
			(string) ( $args['content_locale'] ?? '' )
		);

		return $builder->render( Blueprint::normalize( (array) $args['blueprint'] ), $direction );
	}
}
