<?php
/**
 * Cross-content WordPress search tool.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Search_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'search_content',
			__( 'Search across WordPress posts, pages, and public custom post types with taxonomy, date-range, and pagination filters.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'query'              => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
					'post_types'         => array( 'type' => 'array', 'maxItems' => 20, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'maxLength' => 32 ) ),
					'taxonomy'           => array( 'type' => 'string', 'maxLength' => 32 ),
					'terms'              => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => array( 'string', 'integer' ) ) ),
					'after'              => array( 'type' => 'string', 'format' => 'date-time' ),
					'before'             => array( 'type' => 'string', 'format' => 'date-time' ),
					'include_non_public' => array( 'type' => 'boolean' ),
					'page'               => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'search' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function search( array $args ): array|\WP_Error {
		$include_private = ! empty( $args['include_non_public'] );
		$post_types = ! empty( $args['post_types'] ) ? array_map( 'sanitize_key', (array) $args['post_types'] ) : get_post_types( array( 'public' => true ), 'names' );
		$post_types = array_values( array_diff( array_unique( $post_types ), array( 'attachment' ) ) );
		foreach ( $post_types as $post_type ) {
			$type = get_post_type_object( $post_type );
			if ( ! $type || ( ! $type->public && ( ! $include_private || ! $type->show_ui ) ) ) {
				return new \WP_Error(
					'invalid_post_type',
					sprintf(
						/* translators: %s: WordPress post type name. */
						__( 'Post type %s is not searchable.', 'mindio-magic-mcp' ),
						$post_type
					)
				);
			}
			if ( $include_private ) {
				$can_edit_others = current_user_can( $type->cap->edit_others_posts ?? $type->cap->edit_posts );
				$can_read_private = current_user_can( $type->cap->read_private_posts ?? $type->cap->edit_posts );
				if ( ! $can_edit_others || ! $can_read_private ) {
					return new \WP_Error( 'forbidden', __( 'Your user cannot search non-public content for every requested post type.', 'mindio-magic-mcp' ) );
				}
			}
		}
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => $include_private ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : 'publish',
			's'              => sanitize_text_field( (string) $args['query'] ),
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'orderby'        => 'relevance date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['after'] ) || ! empty( $args['before'] ) ) {
			$query_args['date_query'] = array(
				'after'     => sanitize_text_field( (string) ( $args['after'] ?? '' ) ),
				'before'    => sanitize_text_field( (string) ( $args['before'] ?? '' ) ),
				'inclusive' => true,
			);
		}
		if ( ! empty( $args['taxonomy'] ) && ! empty( $args['terms'] ) ) {
			$taxonomy = sanitize_key( (string) $args['taxonomy'] );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new \WP_Error( 'invalid_taxonomy', __( 'The taxonomy does not exist.', 'mindio-magic-mcp' ) );
			}
			$numeric = count( array_filter( $args['terms'], 'is_int' ) ) === count( $args['terms'] );
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Explicit paginated taxonomy search requested by the MCP caller.
				array(
					'taxonomy' => $taxonomy,
					'field'    => $numeric ? 'term_id' : 'slug',
					'terms'    => $numeric ? array_map( 'absint', $args['terms'] ) : array_map( 'sanitize_title', $args['terms'] ),
				),
			);
		}

		$query = new \WP_Query( $query_args );
		$items = array_map(
			static function ( \WP_Post $post ): array {
				$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
				return array(
					'post_id'      => $post->ID,
					'post_type'    => $post->post_type,
					'status'       => $post->post_status,
					'title'        => get_the_title( $post ),
					'excerpt'      => $post->post_excerpt ?: wp_trim_words( $text, 55, '…' ),
					'url'          => get_permalink( $post ) ?: '',
					'modified_gmt' => ( $date = get_post_datetime( $post, 'modified' ) ) ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : '',
				);
			},
			$query->posts
		);
		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}
}
