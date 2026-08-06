<?php
/**
 * BetterDocs Free integration.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BetterDocs_Tools extends Integration_Dispatcher {
	private const POST_TYPE = 'docs';
	private const CATEGORY  = 'doc_category';
	private const TAG       = 'doc_tag';

	public function __construct( Tool_Registry $registry ) {
		parent::__construct( $registry, 'betterdocs', 'BetterDocs' );
	}

	public function register(): void {
		$doc_id       = array( 'type' => 'integer', 'minimum' => 1 );
		$term_id      = array( 'type' => 'integer', 'minimum' => 1 );
		$taxonomy     = array( 'type' => 'string', 'enum' => array( self::CATEGORY, self::TAG ) );
		$status       = array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private' ) );
		$list_status  = array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private', 'future', 'trash' ) );
		$term_ids     = array(
			'type'        => 'array',
			'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
			'maxItems'    => 100,
			'uniqueItems' => true,
		);
		$doc_fields   = array(
			'title'          => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
			'content'        => array( 'type' => 'string', 'maxLength' => 500000 ),
			'excerpt'        => array( 'type' => 'string', 'maxLength' => 50000 ),
			'status'         => $status,
			'slug'           => array( 'type' => 'string', 'maxLength' => 200 ),
			'category_ids'   => $term_ids,
			'tag_ids'        => $term_ids,
			'featured_media' => array( 'type' => 'integer', 'minimum' => 0 ),
			'menu_order'     => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647 ),
		);
		$operations   = array(
			'list_docs' => $this->operation(
				'read',
				__( 'List BetterDocs documents', 'mindio-magic-mcp' ),
				__( 'List BetterDocs documents with bounded search, status, taxonomy, ordering, and pagination filters.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'search'      => array( 'type' => 'string', 'maxLength' => 200 ),
						'status'      => $list_status,
						'category_id' => $term_id,
						'tag_id'      => $term_id,
						'page'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ),
						'per_page'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
						'orderby'     => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'menu_order', 'id' ) ),
						'order'       => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
					)
				),
				array( $this, 'list_docs' ),
				'edit_docs'
			),
			'get_doc' => $this->operation(
				'read',
				__( 'Get BetterDocs document', 'mindio-magic-mcp' ),
				__( 'Get one BetterDocs document, including its safely stored content and taxonomy assignments.', 'mindio-magic-mcp' ),
				$this->object_schema( array( 'doc_id' => $doc_id ), array( 'doc_id' ) ),
				array( $this, 'get_doc' ),
				array( $this, 'can_read_doc' )
			),
			'list_terms' => $this->operation(
				'read',
				__( 'List BetterDocs terms', 'mindio-magic-mcp' ),
				__( 'List BetterDocs document categories or tags with bounded hierarchy and pagination filters.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'taxonomy'  => $taxonomy,
						'search'    => array( 'type' => 'string', 'maxLength' => 200 ),
						'parent'    => array( 'type' => 'integer', 'minimum' => 0 ),
						'hide_empty'=> array( 'type' => 'boolean' ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000 ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
						'orderby'   => array( 'type' => 'string', 'enum' => array( 'name', 'slug', 'count', 'id' ) ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
					),
					array( 'taxonomy' )
				),
				array( $this, 'list_terms' ),
				'edit_docs'
			),
			'create_doc' => $this->operation(
				'write',
				__( 'Create BetterDocs document', 'mindio-magic-mcp' ),
				__( 'Create a sanitized BetterDocs document through its registered WordPress REST post type.', 'mindio-magic-mcp' ),
				$this->object_schema( $doc_fields, array( 'title' ) ),
				array( $this, 'create_doc' ),
				array( $this, 'can_create_doc' )
			),
			'update_doc' => $this->operation(
				'write',
				__( 'Update BetterDocs document', 'mindio-magic-mcp' ),
				__( 'Update a sanitized BetterDocs document with optional optimistic concurrency.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array_merge(
						array(
							'doc_id'                => $doc_id,
							'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time', 'maxLength' => 40 ),
						),
						$doc_fields
					),
					array( 'doc_id' )
				),
				array( $this, 'update_doc' ),
				array( $this, 'can_update_doc' )
			),
			'delete_doc' => $this->operation(
				'write',
				__( 'Delete BetterDocs document', 'mindio-magic-mcp' ),
				__( 'Move a BetterDocs document to Trash, or permanently delete it when force and confirmation are both supplied.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'doc_id'  => $doc_id,
						'force'   => array( 'type' => 'boolean' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					array( 'doc_id', 'confirm' )
				),
				array( $this, 'delete_doc' ),
				array( $this, 'can_delete_doc' ),
				true
			),
			'create_term' => $this->operation(
				'write',
				__( 'Create BetterDocs term', 'mindio-magic-mcp' ),
				__( 'Create a BetterDocs document category or tag through its registered WordPress REST taxonomy.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'taxonomy'  => $taxonomy,
						'name'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
						'slug'      => array( 'type' => 'string', 'maxLength' => 200 ),
						'description'=> array( 'type' => 'string', 'maxLength' => 10000 ),
						'parent'    => array( 'type' => 'integer', 'minimum' => 0 ),
					),
					array( 'taxonomy', 'name' )
				),
				array( $this, 'create_term' ),
				array( $this, 'can_create_term' )
			),
			'update_term' => $this->operation(
				'write',
				__( 'Update BetterDocs term', 'mindio-magic-mcp' ),
				__( 'Update a BetterDocs document category or tag through its registered WordPress REST taxonomy.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'taxonomy'  => $taxonomy,
						'term_id'   => $term_id,
						'name'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
						'slug'      => array( 'type' => 'string', 'maxLength' => 200 ),
						'description'=> array( 'type' => 'string', 'maxLength' => 10000 ),
						'parent'    => array( 'type' => 'integer', 'minimum' => 0 ),
					),
					array( 'taxonomy', 'term_id' )
				),
				array( $this, 'update_term' ),
				array( $this, 'can_update_term' )
			),
			'delete_term' => $this->operation(
				'write',
				__( 'Delete BetterDocs term', 'mindio-magic-mcp' ),
				__( 'Permanently delete a BetterDocs document category or tag after explicit confirmation.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'taxonomy' => $taxonomy,
						'term_id'  => $term_id,
						'confirm'  => array( 'type' => 'boolean' ),
					),
					array( 'taxonomy', 'term_id', 'confirm' )
				),
				array( $this, 'delete_term' ),
				array( $this, 'can_delete_term' ),
				true
			),
		);

		$this->register_operations( $operations );
	}

	/** @return array<string,mixed> */
	public function list_docs( array $args ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$status   = sanitize_key( (string) ( $args['status'] ?? 'publish' ) );
		$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'modified' ) );
		$query    = array(
			'post_type'           => self::POST_TYPE,
			'post_status'         => $status,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'id' === $orderby ? 'ID' : $orderby,
			'order'               => 'asc' === ( $args['order'] ?? 'desc' ) ? 'ASC' : 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);
		if ( 'publish' !== $status && ! current_user_can( 'edit_others_docs' ) ) {
			$query['author'] = get_current_user_id();
		}
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}
		$tax_query = array();
		if ( ! empty( $args['category_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => self::CATEGORY,
				'field'    => 'term_id',
				'terms'    => array( (int) $args['category_id'] ),
			);
		}
		if ( ! empty( $args['tag_id'] ) ) {
			$tax_query[] = array(
				'taxonomy' => self::TAG,
				'field'    => 'term_id',
				'terms'    => array( (int) $args['tag_id'] ),
			);
		}
		if ( $tax_query ) {
			$query['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded, user-requested taxonomy filtering.
		}

		$docs  = new \WP_Query( $query );
		$items = array();
		foreach ( $docs->posts as $post ) {
			if ( $post instanceof \WP_Post && $this->can_read_post( $post ) ) {
				$items[] = $this->doc_summary( $post );
			}
		}

		return array(
			'docs'        => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'returned'    => count( $items ),
			'total'       => (int) $docs->found_posts,
			'total_pages' => (int) $docs->max_num_pages,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_doc( array $args ): array|\WP_Error {
		$post = $this->find_doc( (int) $args['doc_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'doc' => array_merge(
				$this->doc_summary( $post ),
				array(
					'content'        => (string) $post->post_content,
					'excerpt'        => (string) $post->post_excerpt,
					'comment_status' => (string) $post->comment_status,
				)
			),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function list_terms( array $args ): array|\WP_Error {
		$taxonomy = $this->taxonomy_name( (string) $args['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page   = min( 100, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );
		$orderby    = sanitize_key( (string) ( $args['orderby'] ?? 'name' ) );
		$term_query = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => array_key_exists( 'hide_empty', $args ) ? (bool) $args['hide_empty'] : false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => 'id' === $orderby ? 'term_id' : $orderby,
			'order'      => 'desc' === ( $args['order'] ?? 'asc' ) ? 'DESC' : 'ASC',
		);
		if ( isset( $args['parent'] ) ) {
			$term_query['parent'] = (int) $args['parent'];
		}
		if ( ! empty( $args['search'] ) ) {
			$term_query['search'] = sanitize_text_field( (string) $args['search'] );
		}

		$terms = get_terms( $term_query );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$count_query = $term_query;
		unset( $count_query['number'], $count_query['offset'], $count_query['orderby'], $count_query['order'] );
		$total = wp_count_terms( $count_query );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		return array(
			'terms'       => array_map( array( $this, 'term_summary' ), $terms ),
			'taxonomy'    => $taxonomy,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $total,
			'total_pages' => (int) ceil( (int) $total / $per_page ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_doc( array $args ): array|\WP_Error {
		$payload = $this->doc_payload( $args, true );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$response = $this->rest_write( 'POST', $this->post_rest_path(), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$post = $this->find_doc( (int) ( $response['id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return new \WP_Error( 'betterdocs_create_failed', __( 'BetterDocs created no readable document.', 'mindio-magic-mcp' ) );
		}

		return array( 'doc' => $this->doc_summary( $post ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_doc( array $args ): array|\WP_Error {
		$post = $this->find_doc( (int) $args['doc_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$concurrency = $this->check_modified( $post, (string) ( $args['expected_modified_gmt'] ?? '' ) );
		if ( is_wp_error( $concurrency ) ) {
			return $concurrency;
		}
		$payload = $this->doc_payload( $args, false );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$response = $this->rest_write( 'POST', $this->post_rest_path() . '/' . $post->ID, $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$updated = $this->find_doc( (int) ( $response['id'] ?? $post->ID ) );
		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'betterdocs_update_failed', __( 'BetterDocs returned no readable updated document.', 'mindio-magic-mcp' ) );
		}

		return array( 'doc' => $this->doc_summary( $updated ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_doc( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$post = $this->find_doc( (int) $args['doc_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$force    = ! empty( $args['force'] );
		$response = $this->rest_write(
			'DELETE',
			$this->post_rest_path() . '/' . $post->ID,
			array( 'force' => $force )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'doc_id'  => $post->ID,
			'deleted' => $force,
			'trashed' => ! $force,
			'previous'=> $this->doc_summary( $post ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_term( array $args ): array|\WP_Error {
		$taxonomy = $this->taxonomy_name( (string) $args['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$payload = $this->term_payload( $args, true );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$response = $this->rest_write( 'POST', $this->taxonomy_rest_path( $taxonomy ), $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$term = get_term( (int) ( $response['id'] ?? 0 ), $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'betterdocs_term_create_failed', __( 'BetterDocs created no readable term.', 'mindio-magic-mcp' ) );
		}

		return array( 'term' => $this->term_summary( $term ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_term( array $args ): array|\WP_Error {
		$taxonomy = $this->taxonomy_name( (string) $args['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term = $this->find_term( (int) $args['term_id'], $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$payload = $this->term_payload( $args, false );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$response = $this->rest_write( 'POST', $this->taxonomy_rest_path( $taxonomy ) . '/' . $term->term_id, $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$updated = get_term( (int) ( $response['id'] ?? $term->term_id ), $taxonomy );
		if ( ! $updated instanceof \WP_Term ) {
			return new \WP_Error( 'betterdocs_term_update_failed', __( 'BetterDocs returned no readable updated term.', 'mindio-magic-mcp' ) );
		}

		return array( 'term' => $this->term_summary( $updated ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_term( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$taxonomy = $this->taxonomy_name( (string) $args['taxonomy'] );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}
		$term = $this->find_term( (int) $args['term_id'], $taxonomy );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$previous = $this->term_summary( $term );
		$response = $this->rest_write(
			'DELETE',
			$this->taxonomy_rest_path( $taxonomy ) . '/' . $term->term_id,
			array( 'force' => true )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'term_id'  => $term->term_id,
			'taxonomy' => $taxonomy,
			'deleted'  => true,
			'previous' => $previous,
		);
	}

	public function can_read_doc( array $args ): bool {
		$post = get_post( (int) ( $args['doc_id'] ?? 0 ) );
		return $post instanceof \WP_Post && $this->can_read_post( $post );
	}

	public function can_create_doc( array $args ): bool {
		$post_type = get_post_type_object( self::POST_TYPE );
		if ( ! $post_type ) {
			return false;
		}
		$create_cap = (string) ( $post_type->cap->create_posts ?? $post_type->cap->edit_posts ?? 'edit_docs' );
		return current_user_can( $create_cap ) && $this->can_use_status( (string) ( $args['status'] ?? 'draft' ) );
	}

	public function can_update_doc( array $args ): bool {
		$post = get_post( (int) ( $args['doc_id'] ?? 0 ) );
		return $post instanceof \WP_Post
			&& self::POST_TYPE === $post->post_type
			&& current_user_can( 'edit_post', $post->ID )
			&& $this->can_use_status( (string) ( $args['status'] ?? '' ) );
	}

	public function can_delete_doc( array $args ): bool {
		$post = get_post( (int) ( $args['doc_id'] ?? 0 ) );
		return $post instanceof \WP_Post
			&& self::POST_TYPE === $post->post_type
			&& current_user_can( 'delete_post', $post->ID );
	}

	public function can_create_term( array $args ): bool {
		return $this->can_manage_term( (string) ( $args['taxonomy'] ?? '' ), 'manage_terms' );
	}

	public function can_update_term( array $args ): bool {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		return $this->term_exists( (int) ( $args['term_id'] ?? 0 ), $taxonomy )
			&& $this->can_manage_term( $taxonomy, 'edit_terms' );
	}

	public function can_delete_term( array $args ): bool {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		return $this->term_exists( (int) ( $args['term_id'] ?? 0 ), $taxonomy )
			&& $this->can_manage_term( $taxonomy, 'delete_terms' );
	}

	protected function dependency_installed(): bool {
		return $this->dependency_available()
			|| function_exists( 'betterdocs' )
			|| $this->plugin_is_installed( array( 'betterdocs/betterdocs.php' ), array( 'betterdocs' ) );
	}

	protected function dependency_available(): bool {
		return function_exists( 'betterdocs' )
			&& post_type_exists( self::POST_TYPE )
			&& taxonomy_exists( self::CATEGORY )
			&& taxonomy_exists( self::TAG );
	}

	protected function dependency_label(): string {
		return 'BetterDocs';
	}

	/** @return array<string,mixed> */
	private function operation( string $mode, string $label, string $description, array $schema, callable $callback, string|callable $capability, bool $destructive = false ): array {
		return compact( 'mode', 'label', 'description', 'schema', 'callback', 'capability', 'destructive' );
	}

	/** @return \WP_Post|\WP_Error */
	private function find_doc( int $id ): \WP_Post|\WP_Error {
		$post = get_post( $id );
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type
			? $post
			: new \WP_Error( 'betterdocs_doc_not_found', __( 'BetterDocs document not found.', 'mindio-magic-mcp' ) );
	}

	private function can_read_post( \WP_Post $post ): bool {
		if ( self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( 'trash' === $post->post_status ) {
			return current_user_can( 'edit_post', $post->ID );
		}
		if ( '' !== $post->post_password && ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		return current_user_can( 'read_post', $post->ID );
	}

	private function can_use_status( string $status ): bool {
		if ( ! in_array( $status, array( 'publish', 'private' ), true ) ) {
			return true;
		}
		$post_type = get_post_type_object( self::POST_TYPE );
		$capability = $post_type ? (string) ( $post_type->cap->publish_posts ?? 'publish_docs' ) : 'publish_docs';
		return current_user_can( $capability );
	}

	private function can_manage_term( string $taxonomy, string $capability_key ): bool {
		if ( ! in_array( $taxonomy, array( self::CATEGORY, self::TAG ), true ) ) {
			return false;
		}
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || empty( $object->cap->{$capability_key} ) ) {
			return false;
		}
		return current_user_can( (string) $object->cap->{$capability_key} );
	}

	private function term_exists( int $term_id, string $taxonomy ): bool {
		if ( ! in_array( $taxonomy, array( self::CATEGORY, self::TAG ), true ) ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function doc_payload( array $args, bool $creating ): array|\WP_Error {
		$payload = array();
		if ( array_key_exists( 'title', $args ) ) {
			$payload['title'] = sanitize_text_field( (string) $args['title'] );
			if ( '' === $payload['title'] ) {
				return new \WP_Error( 'invalid_betterdocs_title', __( 'A non-empty BetterDocs document title is required.', 'mindio-magic-mcp' ) );
			}
		}
		if ( array_key_exists( 'content', $args ) ) {
			$payload['content'] = wp_kses_post( (string) $args['content'] );
		}
		if ( array_key_exists( 'excerpt', $args ) ) {
			$payload['excerpt'] = wp_kses_post( (string) $args['excerpt'] );
		}
		if ( array_key_exists( 'status', $args ) ) {
			$payload['status'] = sanitize_key( (string) $args['status'] );
		} elseif ( $creating ) {
			$payload['status'] = 'draft';
		}
		if ( array_key_exists( 'slug', $args ) ) {
			$payload['slug'] = sanitize_title( (string) $args['slug'] );
		}
		if ( array_key_exists( 'featured_media', $args ) ) {
			$payload['featured_media'] = absint( $args['featured_media'] );
		}
		if ( array_key_exists( 'menu_order', $args ) ) {
			$payload['menu_order'] = max( 0, (int) $args['menu_order'] );
		}
		foreach ( array( 'category_ids' => self::CATEGORY, 'tag_ids' => self::TAG ) as $argument => $taxonomy ) {
			if ( ! array_key_exists( $argument, $args ) ) {
				continue;
			}
			$ids = array_values( array_unique( array_map( 'absint', (array) $args[ $argument ] ) ) );
			foreach ( $ids as $term_id ) {
				if ( ! $this->term_exists( $term_id, $taxonomy ) ) {
					return new \WP_Error(
						'betterdocs_term_not_found',
						sprintf(
							/* translators: 1: term ID, 2: taxonomy name. */
							__( 'BetterDocs term %1$d does not exist in %2$s.', 'mindio-magic-mcp' ),
							$term_id,
							$taxonomy
						)
					);
				}
			}
			$taxonomy_object = get_taxonomy( $taxonomy );
			$rest_field      = $taxonomy_object && ! empty( $taxonomy_object->rest_base )
				? (string) $taxonomy_object->rest_base
				: $taxonomy;
			$payload[ $rest_field ] = $ids;
		}
		if ( ! $creating && empty( $payload ) ) {
			return new \WP_Error( 'empty_betterdocs_update', __( 'Provide at least one BetterDocs document field to update.', 'mindio-magic-mcp' ) );
		}

		return $payload;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function term_payload( array $args, bool $creating ): array|\WP_Error {
		$payload = array();
		if ( array_key_exists( 'name', $args ) ) {
			$payload['name'] = sanitize_text_field( (string) $args['name'] );
			if ( '' === $payload['name'] ) {
				return new \WP_Error( 'invalid_betterdocs_term_name', __( 'A non-empty BetterDocs term name is required.', 'mindio-magic-mcp' ) );
			}
		}
		if ( array_key_exists( 'slug', $args ) ) {
			$payload['slug'] = sanitize_title( (string) $args['slug'] );
		}
		if ( array_key_exists( 'description', $args ) ) {
			$payload['description'] = sanitize_textarea_field( (string) $args['description'] );
		}
		if ( array_key_exists( 'parent', $args ) ) {
			$parent = absint( $args['parent'] );
			if ( $parent > 0 && ! $this->term_exists( $parent, (string) $args['taxonomy'] ) ) {
				return new \WP_Error( 'betterdocs_parent_not_found', __( 'The requested BetterDocs parent term does not exist.', 'mindio-magic-mcp' ) );
			}
			if ( ! $creating && $parent === (int) ( $args['term_id'] ?? 0 ) ) {
				return new \WP_Error( 'invalid_betterdocs_parent', __( 'A BetterDocs term cannot be its own parent.', 'mindio-magic-mcp' ) );
			}
			$payload['parent'] = $parent;
		}
		if ( ! $creating && empty( $payload ) ) {
			return new \WP_Error( 'empty_betterdocs_term_update', __( 'Provide at least one BetterDocs term field to update.', 'mindio-magic-mcp' ) );
		}

		return $payload;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function rest_write( string $method, string $path, array $params ): array|\WP_Error {
		$request = new \WP_REST_Request( $method, $path );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		$data = $response->get_data();
		return is_array( $data )
			? $data
			: new \WP_Error( 'betterdocs_rest_error', __( 'BetterDocs returned an invalid WordPress REST response.', 'mindio-magic-mcp' ) );
	}

	private function post_rest_path(): string {
		$post_type = get_post_type_object( self::POST_TYPE );
		$namespace = $post_type && ! empty( $post_type->rest_namespace ) ? (string) $post_type->rest_namespace : 'wp/v2';
		$rest_base = $post_type && ! empty( $post_type->rest_base ) ? (string) $post_type->rest_base : self::POST_TYPE;
		return '/' . trim( $namespace, '/' ) . '/' . trim( $rest_base, '/' );
	}

	private function taxonomy_rest_path( string $taxonomy ): string {
		$object    = get_taxonomy( $taxonomy );
		$namespace = $object && ! empty( $object->rest_namespace ) ? (string) $object->rest_namespace : 'wp/v2';
		$rest_base = $object && ! empty( $object->rest_base ) ? (string) $object->rest_base : $taxonomy;
		return '/' . trim( $namespace, '/' ) . '/' . trim( $rest_base, '/' );
	}

	/** @return string|\WP_Error */
	private function taxonomy_name( string $taxonomy ): string|\WP_Error {
		$taxonomy = sanitize_key( $taxonomy );
		return in_array( $taxonomy, array( self::CATEGORY, self::TAG ), true ) && taxonomy_exists( $taxonomy )
			? $taxonomy
			: new \WP_Error( 'betterdocs_taxonomy_unavailable', __( 'The requested BetterDocs taxonomy is unavailable.', 'mindio-magic-mcp' ) );
	}

	/** @return \WP_Term|\WP_Error */
	private function find_term( int $term_id, string $taxonomy ): \WP_Term|\WP_Error {
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term
			? $term
			: new \WP_Error( 'betterdocs_term_not_found', __( 'BetterDocs term not found.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed> */
	private function doc_summary( \WP_Post $post ): array {
		return array(
			'id'                => $post->ID,
			'title'             => (string) $post->post_title,
			'status'            => (string) $post->post_status,
			'slug'              => (string) $post->post_name,
			'author_id'         => (int) $post->post_author,
			'date_gmt'          => $this->post_date_gmt( $post, false ),
			'modified_gmt'      => $this->post_date_gmt( $post, true ),
			'permalink'         => esc_url_raw( (string) get_permalink( $post ) ),
			'featured_media_id' => (int) get_post_thumbnail_id( $post ),
			'menu_order'        => (int) $post->menu_order,
			'categories'        => $this->post_terms( $post->ID, self::CATEGORY ),
			'tags'              => $this->post_terms( $post->ID, self::TAG ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function post_terms( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_map( array( $this, 'term_summary' ), $terms );
	}

	/** @return array<string,mixed> */
	public function term_summary( \WP_Term $term ): array {
		$link = get_term_link( $term );
		return array(
			'id'          => $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'link'        => is_wp_error( $link ) ? '' : esc_url_raw( $link ),
		);
	}

	/** @return true|\WP_Error */
	private function check_modified( \WP_Post $post, string $expected ): true|\WP_Error {
		if ( '' === $expected ) {
			return true;
		}
		$actual             = $this->post_date_gmt( $post, true );
		$expected_timestamp = strtotime( $expected );
		$actual_timestamp   = strtotime( $actual );
		if ( false === $expected_timestamp || false === $actual_timestamp || $expected_timestamp !== $actual_timestamp ) {
			return new \WP_Error(
				'stale_betterdocs_document',
				__( 'The BetterDocs document changed after the supplied modification time.', 'mindio-magic-mcp' ),
				array( 'modified_gmt' => $actual )
			);
		}
		return true;
	}

	private function post_date_gmt( \WP_Post $post, bool $modified ): string {
		$gmt   = $modified ? (string) $post->post_modified_gmt : (string) $post->post_date_gmt;
		$local = $modified ? (string) $post->post_modified : (string) $post->post_date;
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			$gmt = get_gmt_from_date( $local );
		}
		$timestamp = strtotime( $gmt . ' UTC' );
		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	private function confirmation_error(): \WP_Error {
		return new \WP_Error( 'confirmation_required', __( 'Deleting BetterDocs content requires confirm=true.', 'mindio-magic-mcp' ) );
	}
}
