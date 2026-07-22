<?php
/**
 * WordPress post, page, and custom-post-type tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Content_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'create_post',
			__( 'Create a WordPress post, page, or public custom post type as a draft, pending item, private item, or published item.', 'mindio-magic-mcp' ),
			$this->write_schema( false ),
			$this->post_output_schema(),
			array( $this, 'create_post' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_create' ),
			array( 'destructiveHint' => false )
		);
		$this->registry->register(
			'get_post',
			__( 'Get one WordPress post, page, or custom post type including its editable content and revision information.', 'mindio-magic-mcp' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'get_post' ),
			Auth::SCOPE_READ,
			array( $this, 'can_read_post' ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'update_post',
			__( 'Update selected fields of an existing WordPress post while preserving revision history.', 'mindio-magic-mcp' ),
			$this->write_schema( true ),
			$this->post_output_schema(),
			array( $this, 'update_post' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_edit_post' ),
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'delete_post',
			__( 'Move a post to Trash, or permanently delete it only when force and confirm are both true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'force'   => array( 'type' => 'boolean' ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'delete_post' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_delete_post' ),
			array( 'destructiveHint' => true )
		);
		$this->registry->register(
			'publish_post',
			__( 'Publish an existing post immediately.', 'mindio-magic-mcp' ),
			$this->id_schema(),
			$this->post_output_schema(),
			array( $this, 'publish_post' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_publish_post' ),
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'schedule_post',
			__( 'Schedule an existing post using an ISO-8601 future date-time interpreted in the site timezone unless an offset is supplied.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'publish_at' => array( 'type' => 'string', 'format' => 'date-time' ),
				),
				'required'             => array( 'post_id', 'publish_at' ),
				'additionalProperties' => false,
			),
			$this->post_output_schema(),
			array( $this, 'schedule_post' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_publish_post' ),
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'list_posts',
			__( 'List and search posts, pages, or a custom post type with status, taxonomy, date-range, and pagination filters.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array( 'type' => 'string', 'maxLength' => 32 ),
					'status'    => array( 'type' => 'string', 'enum' => array( 'any', 'draft', 'pending', 'private', 'publish', 'future', 'trash' ) ),
					'search'    => array( 'type' => 'string', 'maxLength' => 200 ),
					'taxonomy'  => array( 'type' => 'string', 'maxLength' => 32 ),
					'term'      => array( 'type' => array( 'string', 'integer' ) ),
					'after'     => array( 'type' => 'string', 'format' => 'date-time' ),
					'before'    => array( 'type' => 'string', 'format' => 'date-time' ),
					'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					'order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
					'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'id' ) ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_posts' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	public function can_create( array $args ): bool {
		$type = $this->post_type_object( (string) ( $args['post_type'] ?? 'post' ) );
		return $type && current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts );
	}

	public function can_read_post( array $args ): bool {
		$post = get_post( absint( $args['post_id'] ?? 0 ) );
		return $post && current_user_can( 'read_post', $post->ID );
	}

	public function can_edit_post( array $args ): bool {
		return current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) );
	}

	public function can_delete_post( array $args ): bool {
		return current_user_can( 'delete_post', absint( $args['post_id'] ?? 0 ) );
	}

	public function can_publish_post( array $args ): bool {
		$post = get_post( absint( $args['post_id'] ?? 0 ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		return $type && current_user_can( $type->cap->publish_posts );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_post( array $args ): array|\WP_Error {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$type      = $this->post_type_object( $post_type );
		if ( ! $type ) {
			return new \WP_Error( 'invalid_post_type', __( 'The post type is not writable.', 'mindio-magic-mcp' ) );
		}

		$status = sanitize_key( (string) ( $args['status'] ?? 'draft' ) );
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new \WP_Error( 'cannot_publish', __( 'Your user cannot publish this post type.', 'mindio-magic-mcp' ) );
		}
		$featured_media = $this->validate_featured_media( $args );
		if ( is_wp_error( $featured_media ) ) {
			return $featured_media;
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => sanitize_text_field( (string) $args['title'] ),
			'post_content' => $this->sanitize_content( (string) ( $args['content'] ?? '' ) ),
			'post_excerpt' => wp_kses_post( (string) ( $args['excerpt'] ?? '' ) ),
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
		);
		if ( isset( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $args['slug'] );
		}
		if ( isset( $args['parent_id'] ) ) {
			$postarr['post_parent'] = absint( $args['parent_id'] );
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$this->apply_featured_media( (int) $post_id, $args );
		do_action( 'mindio_magic_mcp_post_created', (int) $post_id, $args );
		return $this->post_result( (int) $post_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_post( array $args ): array|\WP_Error {
		$post = get_post( absint( $args['post_id'] ) );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$data                 = $this->serialize_post( $post, true );
		$data['revision_ids'] = wp_get_post_revisions( $post->ID, array( 'fields' => 'ids', 'posts_per_page' => 20 ) );
		return $data;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_post( array $args ): array|\WP_Error {
		$post_id = absint( $args['post_id'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$type = get_post_type_object( $post->post_type );
		if ( isset( $args['status'] ) && in_array( $args['status'], array( 'publish', 'private' ), true ) && ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) ) {
			return new \WP_Error( 'cannot_publish', __( 'Your user cannot publish this post type.', 'mindio-magic-mcp' ) );
		}
		$featured_media = $this->validate_featured_media( $args );
		if ( is_wp_error( $featured_media ) ) {
			return $featured_media;
		}

		wp_save_post_revision( $post_id );
		$update = array( 'ID' => $post_id );
		$map    = array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'status' => 'post_status' );
		foreach ( $map as $input => $field ) {
			if ( array_key_exists( $input, $args ) ) {
				$update[ $field ] = 'title' === $input ? sanitize_text_field( (string) $args[ $input ] ) : ( 'excerpt' === $input ? wp_kses_post( (string) $args[ $input ] ) : sanitize_key( (string) $args[ $input ] ) );
			}
		}
		if ( array_key_exists( 'content', $args ) ) {
			$update['post_content'] = $this->sanitize_content( (string) $args['content'] );
		}
		if ( array_key_exists( 'slug', $args ) ) {
			$update['post_name'] = sanitize_title( (string) $args['slug'] );
		}
		if ( array_key_exists( 'parent_id', $args ) ) {
			$update['post_parent'] = absint( $args['parent_id'] );
		}

		$result = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->apply_featured_media( $post_id, $args );
		do_action( 'mindio_magic_mcp_post_updated', $post_id, $args );
		return $this->post_result( $post_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_post( array $args ): array|\WP_Error {
		$post_id = absint( $args['post_id'] );
		$force   = ! empty( $args['force'] );
		if ( $force && empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Permanent deletion requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$deleted = wp_delete_post( $post_id, $force );
		if ( ! $deleted ) {
			return new \WP_Error( 'delete_failed', __( 'The post could not be deleted.', 'mindio-magic-mcp' ) );
		}
		return array( 'post_id' => $post_id, 'deleted' => true, 'permanent' => $force );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function publish_post( array $args ): array|\WP_Error {
		$post_id = absint( $args['post_id'] );
		wp_save_post_revision( $post_id );
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ) ), true );
		return is_wp_error( $result ) ? $result : $this->post_result( $post_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function schedule_post( array $args ): array|\WP_Error {
		$timezone = wp_timezone();
		try {
			$date = new \DateTimeImmutable( (string) $args['publish_at'], $timezone );
		} catch ( \Exception ) {
			return new \WP_Error( 'invalid_date', __( 'The publish date is invalid.', 'mindio-magic-mcp' ) );
		}
		if ( $date->getTimestamp() <= time() + 60 ) {
			return new \WP_Error( 'date_not_future', __( 'The scheduled date must be at least one minute in the future.', 'mindio-magic-mcp' ) );
		}

		$post_id = absint( $args['post_id'] );
		wp_save_post_revision( $post_id );
		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $date->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			),
			true
		);
		return is_wp_error( $result ) ? $result : $this->post_result( $post_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function list_posts( array $args ): array|\WP_Error {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$type      = $this->post_type_object( $post_type );
		if ( ! $type ) {
			return new \WP_Error( 'invalid_post_type', __( 'The post type is not searchable through this tool.', 'mindio-magic-mcp' ) );
		}
		if ( ! $type->public && ! current_user_can( $type->cap->edit_posts ) ) {
			return new \WP_Error( 'forbidden', __( 'Your user cannot list this non-public post type.', 'mindio-magic-mcp' ) );
		}
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$status   = sanitize_key( (string) ( $args['status'] ?? 'publish' ) );
		$query_status = $status;
		if ( 'any' === $status ) {
			$query_status = array( 'publish' );
			if ( current_user_can( $type->cap->edit_posts ) ) {
				$query_status = array_merge( $query_status, array( 'draft', 'pending', 'future', 'trash' ) );
			}
			if ( current_user_can( $type->cap->read_private_posts ?? $type->cap->edit_posts ) ) {
				$query_status[] = 'private';
			}
		} elseif ( 'private' === $status && ! current_user_can( $type->cap->read_private_posts ?? $type->cap->edit_posts ) ) {
			$query_status = 'publish';
		} elseif ( 'publish' !== $status && ! current_user_can( $type->cap->edit_posts ) ) {
			$query_status = 'publish';
		}

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => $query_status,
			's'              => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'order'          => 'asc' === ( $args['order'] ?? 'desc' ) ? 'ASC' : 'DESC',
			'orderby'        => 'id' === ( $args['orderby'] ?? '' ) ? 'ID' : sanitize_key( (string) ( $args['orderby'] ?? 'date' ) ),
		);
		if ( ! empty( $args['after'] ) || ! empty( $args['before'] ) ) {
			$query_args['date_query'] = array(
				'after'     => sanitize_text_field( (string) ( $args['after'] ?? '' ) ),
				'before'    => sanitize_text_field( (string) ( $args['before'] ?? '' ) ),
				'inclusive' => true,
			);
		}
		if ( ! empty( $args['taxonomy'] ) && isset( $args['term'] ) ) {
			$taxonomy = sanitize_key( (string) $args['taxonomy'] );
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return new \WP_Error( 'invalid_taxonomy', __( 'The taxonomy is not attached to this post type.', 'mindio-magic-mcp' ) );
			}
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Explicit bounded taxonomy filter requested by the MCP caller.
				array(
					'taxonomy' => $taxonomy,
					'field'    => is_int( $args['term'] ) ? 'term_id' : 'slug',
					'terms'    => array( is_int( $args['term'] ) ? $args['term'] : sanitize_title( (string) $args['term'] ) ),
				),
			);
		}

		$query = new \WP_Query( $query_args );
		return array(
			'items'       => array_map( fn( \WP_Post $post ): array => $this->serialize_post( $post, false ), $query->posts ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	private function write_schema( bool $require_id ): array {
		$properties = array(
			'post_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
			'post_type'      => array( 'type' => 'string', 'maxLength' => 32 ),
			'title'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
			'content'        => array( 'type' => 'string', 'maxLength' => 2000000 ),
			'excerpt'        => array( 'type' => 'string', 'maxLength' => 10000 ),
			'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
			'slug'           => array( 'type' => 'string', 'maxLength' => 200 ),
			'parent_id'      => array( 'type' => 'integer', 'minimum' => 0 ),
			'featured_media' => array( 'type' => 'integer', 'minimum' => 0 ),
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $require_id ? array( 'post_id' ) : array( 'title' ),
			'additionalProperties' => false,
		);
	}

	private function id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	private function post_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'status'  => array( 'type' => 'string' ),
				'url'     => array( 'type' => 'string' ),
			),
		);
	}

	private function post_type_object( string $post_type ): ?\WP_Post_Type {
		$post_type = sanitize_key( $post_type );
		if ( in_array( $post_type, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ), true ) ) {
			return null;
		}
		$type = get_post_type_object( $post_type );
		return $type instanceof \WP_Post_Type && ( $type->public || $type->show_ui ) ? $type : null;
	}

	private function sanitize_content( string $content ): string {
		return wp_kses_post( $content );
	}

	private function apply_featured_media( int $post_id, array $args ): void {
		if ( ! array_key_exists( 'featured_media', $args ) ) {
			return;
		}
		$attachment_id = absint( $args['featured_media'] );
		if ( 0 === $attachment_id ) {
			delete_post_thumbnail( $post_id );
		} elseif ( 'attachment' === get_post_type( $attachment_id ) && wp_attachment_is_image( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/** @return bool|\WP_Error */
	private function validate_featured_media( array $args ): bool|\WP_Error {
		if ( ! array_key_exists( 'featured_media', $args ) || 0 === absint( $args['featured_media'] ) ) {
			return true;
		}
		return wp_attachment_is_image( absint( $args['featured_media'] ) )
			? true
			: new \WP_Error( 'invalid_featured_media', __( 'featured_media must be an image attachment or 0.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed> */
	private function post_result( int $post_id ): array {
		$post = get_post( $post_id );
		$revisions = wp_get_post_revisions( $post_id, array( 'fields' => 'ids', 'posts_per_page' => 1 ) );
		return array(
			'post_id'      => $post_id,
			'post_type'    => $post ? $post->post_type : '',
			'status'       => $post ? $post->post_status : '',
			'url'          => get_permalink( $post_id ) ?: '',
			'edit_url'     => get_edit_post_link( $post_id, 'raw' ) ?: '',
			'revision_id'  => $revisions ? (int) reset( $revisions ) : 0,
			'modified_gmt' => $post ? $this->post_time_gmt( $post, 'modified' ) : '',
		);
	}

	/** @return array<string,mixed> */
	private function serialize_post( \WP_Post $post, bool $include_content ): array {
		$data = array(
			'post_id'        => $post->ID,
			'post_type'      => $post->post_type,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'excerpt'        => $post->post_excerpt,
			'author_id'      => (int) $post->post_author,
			'parent_id'      => (int) $post->post_parent,
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			'url'            => get_permalink( $post ) ?: '',
			'date_gmt'       => $this->post_time_gmt( $post, 'date' ),
			'modified_gmt'   => $this->post_time_gmt( $post, 'modified' ),
			'is_flatsome'    => str_contains( $post->post_content, '[section' ) || str_contains( $post->post_content, '[row' ),
		);
		if ( $include_content ) {
			$data['content'] = $post->post_content;
		}
		return $data;
	}

	private function post_time_gmt( \WP_Post $post, string $field ): string {
		$date = get_post_datetime( $post, $field );
		return $date ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : '';
	}
}
