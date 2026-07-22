<?php
/**
 * Structured Gutenberg block discovery and editing tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gutenberg_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register( 'list_blocks', __( 'List registered Gutenberg block types with category, title, and availability metadata.', 'mindio-magic-mcp' ), $this->list_schema(), array( 'type' => 'object' ), array( $this, 'list_blocks' ), Auth::SCOPE_READ, 'edit_posts', array( 'readOnlyHint' => true, 'idempotentHint' => true ) );
		$this->registry->register( 'get_block_schema', __( 'Get the registered attributes, supports, styles, and example markup for one or more Gutenberg block types.', 'mindio-magic-mcp' ), $this->block_schema(), array( 'type' => 'object' ), array( $this, 'get_block_schema' ), Auth::SCOPE_READ, 'edit_posts', array( 'readOnlyHint' => true, 'idempotentHint' => true ) );
		$this->registry->register( 'get_post_blocks', __( 'Read a post as a structured Gutenberg block tree with stable-for-this-revision index paths.', 'mindio-magic-mcp' ), $this->post_read_schema(), array( 'type' => 'object' ), array( $this, 'get_post_blocks' ), Auth::SCOPE_READ, array( $this, 'can_read_post' ), array( 'readOnlyHint' => true, 'idempotentHint' => true ) );
		$this->registry->register( 'list_patterns', __( 'List registered Gutenberg block patterns that can be inserted into posts.', 'mindio-magic-mcp' ), $this->list_schema( true ), array( 'type' => 'object' ), array( $this, 'list_patterns' ), Auth::SCOPE_READ, 'edit_posts', array( 'readOnlyHint' => true, 'idempotentHint' => true ) );
		$this->registry->register( 'add_block', __( 'Insert one or more Gutenberg blocks at a root or nested block-tree position.', 'mindio-magic-mcp' ), $this->markup_write_schema( false ), array( 'type' => 'object' ), array( $this, 'add_block' ), Auth::SCOPE_EDITOR, array( $this, 'can_edit_post' ) );
		$this->registry->register( 'update_block', __( 'Replace the Gutenberg block at an index path while preserving WordPress revision history.', 'mindio-magic-mcp' ), $this->markup_write_schema( true ), array( 'type' => 'object' ), array( $this, 'update_block' ), Auth::SCOPE_EDITOR, array( $this, 'can_edit_post' ) );
		$this->registry->register( 'remove_block', __( 'Remove a Gutenberg block and its descendants. Requires confirm=true.', 'mindio-magic-mcp' ), $this->path_write_schema( true ), array( 'type' => 'object' ), array( $this, 'remove_block' ), Auth::SCOPE_EDITOR, array( $this, 'can_edit_post' ), array( 'destructiveHint' => true ) );
		$this->registry->register( 'move_block', __( 'Move a Gutenberg block to another root or nested position.', 'mindio-magic-mcp' ), $this->move_schema(), array( 'type' => 'object' ), array( $this, 'move_block' ), Auth::SCOPE_EDITOR, array( $this, 'can_edit_post' ) );
		$this->registry->register( 'duplicate_block', __( 'Duplicate a Gutenberg block and insert the copy immediately after it.', 'mindio-magic-mcp' ), $this->path_write_schema(), array( 'type' => 'object' ), array( $this, 'duplicate_block' ), Auth::SCOPE_EDITOR, array( $this, 'can_edit_post' ) );
		$this->registry->register( 'insert_pattern', __( 'Insert a registered Gutenberg pattern at a root or nested block-tree position.', 'mindio-magic-mcp' ), $this->pattern_write_schema(), array( 'type' => 'object' ), array( $this, 'insert_pattern' ), Auth::SCOPE_EDITOR, array( $this, 'can_edit_post' ) );
	}

	public function can_read_post( array $args ): bool {
		return current_user_can( 'read_post', absint( $args['post_id'] ?? 0 ) );
	}

	public function can_edit_post( array $args ): bool {
		return current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) );
	}

	/** @return array<string,mixed> */
	public function list_blocks( array $args ): array {
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		$search   = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$blocks   = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$title = '' !== (string) ( $type->title ?? '' ) ? (string) $type->title : (string) $name;
			$group = (string) ( $type->category ?? '' );
			if ( $category && $category !== $group ) {
				continue;
			}
			if ( $search && ! str_contains( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$blocks[] = array(
				'name'       => (string) $name,
				'title'      => $title,
				'category'   => $group,
				'description'=> wp_strip_all_tags( (string) ( $type->description ?? '' ) ),
				'dynamic'    => is_callable( $type->render_callback ?? null ),
				'api_version'=> (int) ( $type->api_version ?? 1 ),
			);
		}
		return array( 'count' => count( $blocks ), 'blocks' => $blocks );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_block_schema( array $args ): array|\WP_Error {
		$names = ! empty( $args['names'] ) ? array_map( 'strval', (array) $args['names'] ) : array_filter( array( (string) ( $args['name'] ?? '' ) ) );
		if ( empty( $names ) ) {
			return new \WP_Error( 'block_name_required', __( 'Provide name or names for the block schemas to retrieve.', 'mindio-magic-mcp' ) );
		}
		$schemas  = array();
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array_values( array_unique( $names ) ) as $name ) {
			$type = $registry->get_registered( $name );
			if ( ! $type ) {
				$schemas[] = array( 'name' => $name, 'available' => false, 'error' => 'block_type_unavailable' );
				continue;
			}
			$short = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
			$schemas[] = array(
				'name'       => $name,
				'available'  => true,
				'title'      => (string) ( $type->title ?? $name ),
				'category'   => (string) ( $type->category ?? '' ),
				'attributes' => (array) ( $type->attributes ?? array() ),
				'supports'   => (array) ( $type->supports ?? array() ),
				'styles'     => (array) ( $type->styles ?? array() ),
				'example'    => sprintf( '<!-- wp:%1$s --><!-- /wp:%1$s -->', $short ),
			);
		}
		return array( 'blocks' => $schemas );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_post_blocks( array $args ): array|\WP_Error {
		$post = get_post( absint( $args['post_id'] ) );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$mode = $this->content_mode( $post );
		$tree = Block_Tree::parse( (string) $post->post_content );
		return array(
			'post_id'      => $post->ID,
			'content_mode' => $mode,
			'modified_gmt' => $this->modified_gmt( $post ),
			'block_count'  => $this->count_blocks( $tree ),
			'blocks'       => Block_Tree::summarize( $tree, isset( $args['depth'] ) ? (int) $args['depth'] : null ),
			'write_safe'   => ! in_array( $mode, array( 'flatsome', 'mixed' ), true ),
		);
	}

	/** @return array<string,mixed> */
	public function list_patterns( array $args ): array {
		$search   = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		$patterns = array();
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name  = (string) ( $pattern['name'] ?? '' );
			$title = (string) ( $pattern['title'] ?? $name );
			$groups = array_map( 'strval', (array) ( $pattern['categories'] ?? array() ) );
			if ( $category && ! in_array( $category, $groups, true ) ) {
				continue;
			}
			if ( $search && ! str_contains( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$patterns[] = array( 'name' => $name, 'title' => $title, 'categories' => $groups, 'description' => wp_strip_all_tags( (string) ( $pattern['description'] ?? '' ) ) );
		}
		return array( 'count' => count( $patterns ), 'patterns' => $patterns );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function add_block( array $args ): array|\WP_Error {
		$loaded = $this->load_for_write( $args );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		$new = $this->parse_new_blocks( (string) $args['markup'] );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$path = Block_Tree::insert( $tree, $new, $this->position( $args ) );
		return is_wp_error( $path ) ? $path : $this->save( $post, $tree, array( 'added' => count( $new ), 'path' => $path ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_block( array $args ): array|\WP_Error {
		$loaded = $this->load_for_write( $args );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		$new = $this->parse_new_blocks( (string) $args['markup'] );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$path = array_map( 'intval', (array) $args['path'] );
		if ( ! Block_Tree::replace( $tree, $path, $new ) ) {
			return new \WP_Error( 'block_path_not_found', __( 'The block path no longer resolves. Read the current block tree and try again.', 'mindio-magic-mcp' ) );
		}
		return $this->save( $post, $tree, array( 'updated' => true, 'path' => $path, 'replacement_count' => count( $new ) ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function remove_block( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Removing a block requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$loaded = $this->load_for_write( $args );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		$path = array_map( 'intval', (array) $args['path'] );
		if ( null === Block_Tree::remove( $tree, $path ) ) {
			return new \WP_Error( 'block_path_not_found', __( 'The block path no longer resolves. Read the current block tree and try again.', 'mindio-magic-mcp' ) );
		}
		return $this->save( $post, $tree, array( 'removed' => true, 'path' => $path ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function move_block( array $args ): array|\WP_Error {
		$loaded = $this->load_for_write( $args );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		$path = Block_Tree::move( $tree, array_map( 'intval', (array) $args['path'] ), (array) $args['position'] );
		return is_wp_error( $path ) ? $path : $this->save( $post, $tree, array( 'moved' => true, 'path' => $path ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function duplicate_block( array $args ): array|\WP_Error {
		$loaded = $this->load_for_write( $args );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		$path = Block_Tree::duplicate( $tree, array_map( 'intval', (array) $args['path'] ) );
		return is_wp_error( $path ) ? $path : $this->save( $post, $tree, array( 'duplicated' => true, 'path' => $path ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function insert_pattern( array $args ): array|\WP_Error {
		$loaded = $this->load_for_write( $args );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $args['pattern_name'] );
		if ( ! $pattern || empty( $pattern['content'] ) ) {
			return new \WP_Error( 'pattern_not_found', __( 'The requested Gutenberg pattern is not registered.', 'mindio-magic-mcp' ) );
		}
		$new = $this->parse_new_blocks( (string) $pattern['content'] );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$path = Block_Tree::insert( $tree, $new, $this->position( $args ) );
		return is_wp_error( $path ) ? $path : $this->save( $post, $tree, array( 'pattern_name' => (string) $args['pattern_name'], 'added' => count( $new ), 'path' => $path ) );
	}

	/** @return array{0:\WP_Post,1:array<int,array<string,mixed>>}|\WP_Error */
	private function load_for_write( array $args ): array|\WP_Error {
		$post = get_post( absint( $args['post_id'] ) );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$modified = $this->modified_gmt( $post );
		if ( ! empty( $args['expected_modified_gmt'] ) && ! hash_equals( $modified, (string) $args['expected_modified_gmt'] ) ) {
			return new \WP_Error( 'stale_post_revision', __( 'The post changed after the block tree was read. Fetch the current blocks and retry.', 'mindio-magic-mcp' ), array( 'modified_gmt' => $modified ) );
		}
		$mode = $this->content_mode( $post );
		if ( in_array( $mode, array( 'flatsome', 'mixed' ), true ) && ( empty( $args['force_non_gutenberg'] ) || empty( $args['confirm'] ) ) ) {
			return new \WP_Error( 'flatsome_content_guard', __( 'This post contains Flatsome UX Builder content. Use Flatsome tools, or pass force_non_gutenberg=true and confirm=true to override.', 'mindio-magic-mcp' ) );
		}
		return array( $post, Block_Tree::parse( (string) $post->post_content ) );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function parse_new_blocks( string $markup ): array|\WP_Error {
		if ( '' === trim( $markup ) ) {
			return new \WP_Error( 'empty_block_markup', __( 'Block markup cannot be empty.', 'mindio-magic-mcp' ) );
		}
		$tree = Block_Tree::parse( wp_kses_post( $markup ) );
		$registered = Block_Tree::validate_registered( $tree );
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}
		$limits = Block_Tree::validate_limits( $tree );
		return is_wp_error( $limits ) ? $limits : $tree;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function save( \WP_Post $post, array $tree, array $result ): array|\WP_Error {
		$limits = Block_Tree::validate_limits( $tree );
		if ( is_wp_error( $limits ) ) {
			return $limits;
		}
		wp_save_post_revision( $post->ID );
		$updated = wp_update_post( array( 'ID' => $post->ID, 'post_content' => wp_slash( Block_Tree::serialize( $tree ) ) ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$fresh = get_post( $post->ID );
		return array_merge( array( 'post_id' => $post->ID, 'modified_gmt' => $fresh ? $this->modified_gmt( $fresh ) : '', 'block_count' => $this->count_blocks( $tree ) ), $result );
	}

	private function content_mode( \WP_Post $post ): string {
		$content  = (string) $post->post_content;
		$flatsome = false !== strpos( $content, '[section' ) || false !== strpos( $content, '[row' ) || false !== strpos( $content, '<!-- wp:flatsome/uxbuilder' ) || ! empty( get_post_meta( $post->ID, '_ux_builder_data', true ) );
		$blocks   = has_blocks( $content );
		if ( $flatsome && $blocks && false === strpos( $content, '<!-- wp:flatsome/uxbuilder' ) ) {
			return 'mixed';
		}
		if ( $flatsome ) {
			return 'flatsome';
		}
		return $blocks ? 'gutenberg' : 'classic';
	}

	private function modified_gmt( \WP_Post $post ): string {
		$date = get_post_datetime( $post, 'modified' );
		return $date ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : '';
	}

	private function count_blocks( array $tree ): int {
		$count = 0;
		foreach ( $tree as $block ) {
			++$count;
			$count += $this->count_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
		}
		return $count;
	}

	private function position( array $args ): array {
		return isset( $args['position'] ) && is_array( $args['position'] ) ? $args['position'] : array( 'mode' => 'append' );
	}

	private function list_schema( bool $patterns = false ): array {
		return array( 'type' => 'object', 'properties' => array( 'search' => array( 'type' => 'string', 'maxLength' => 200 ), 'category' => array( 'type' => 'string', 'maxLength' => $patterns ? 100 : 64 ) ), 'additionalProperties' => false );
	}

	private function block_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string', 'maxLength' => 200 ), 'names' => array( 'type' => 'array', 'maxItems' => 50, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'maxLength' => 200 ) ) ), 'additionalProperties' => false );
	}

	private function post_read_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'depth' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => Block_Tree::MAX_DEPTH ) ), 'required' => array( 'post_id' ), 'additionalProperties' => false );
	}

	private function path_schema(): array {
		return array( 'type' => 'array', 'minItems' => 1, 'maxItems' => Block_Tree::MAX_DEPTH, 'items' => array( 'type' => 'integer', 'minimum' => 0 ) );
	}

	private function position_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'mode' => array( 'type' => 'string', 'enum' => array( 'append', 'prepend', 'before', 'after', 'inside' ) ), 'path' => $this->path_schema() ), 'additionalProperties' => false );
	}

	private function write_common(): array {
		return array(
			'post_id'              => array( 'type' => 'integer', 'minimum' => 1 ),
			'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
			'force_non_gutenberg'  => array( 'type' => 'boolean' ),
			'confirm'               => array( 'type' => 'boolean' ),
		);
	}

	private function markup_write_schema( bool $path_required ): array {
		$properties = array_merge( $this->write_common(), array( 'markup' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000000 ) ) );
		if ( $path_required ) {
			$properties['path'] = $this->path_schema();
		} else {
			$properties['position'] = $this->position_schema();
		}
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $path_required ? array( 'post_id', 'path', 'markup' ) : array( 'post_id', 'markup' ), 'additionalProperties' => false );
	}

	private function path_write_schema( bool $confirm_required = false ): array {
		$properties = array_merge( $this->write_common(), array( 'path' => $this->path_schema() ) );
		$required   = array( 'post_id', 'path' );
		if ( $confirm_required ) {
			$required[] = 'confirm';
		}
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	private function move_schema(): array {
		return array( 'type' => 'object', 'properties' => array_merge( $this->write_common(), array( 'path' => $this->path_schema(), 'position' => $this->position_schema() ) ), 'required' => array( 'post_id', 'path', 'position' ), 'additionalProperties' => false );
	}

	private function pattern_write_schema(): array {
		return array( 'type' => 'object', 'properties' => array_merge( $this->write_common(), array( 'pattern_name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ), 'position' => $this->position_schema() ) ), 'required' => array( 'post_id', 'pattern_name' ), 'additionalProperties' => false );
	}
}
