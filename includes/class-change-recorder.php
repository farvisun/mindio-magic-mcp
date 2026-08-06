<?php
/**
 * Records every database entity a tool call touches.
 *
 * The recorder snapshots each post, meta row, term assignment, option, comment,
 * and user before the first write reaches it, then resolves the after-state on
 * demand. Dry runs use it to describe a rolled-back transaction; changesets use
 * it to build a reversible journal.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Change_Recorder {
	public const KIND_POST         = 'post';
	public const KIND_META         = 'meta';
	public const KIND_OBJECT_TERMS = 'object_terms';
	public const KIND_TERM         = 'term';
	public const KIND_OPTION       = 'option';
	public const KIND_COMMENT      = 'comment';
	public const KIND_USER         = 'user';

	private const MAX_VALUE_CHARS = 20000;

	/** @var array<string,array<string,mixed>> */
	private array $tracked = array();

	/** @var array<int,string> */
	private array $suppressed = array();

	private bool $recording = false;
	private bool $suppress_effects = false;
	private bool $truncated = false;

	/**
	 * @param bool $suppress_effects Block outbound HTTP, mail, and cron while recording.
	 */
	public function start( bool $suppress_effects = false ): void {
		if ( $this->recording ) {
			return;
		}
		$this->recording        = true;
		$this->suppress_effects = $suppress_effects;
		$this->add_capture_hooks();
		if ( $suppress_effects ) {
			$this->add_suppression_hooks();
		}
	}

	public function stop(): void {
		if ( ! $this->recording ) {
			return;
		}
		$this->remove_capture_hooks();
		if ( $this->suppress_effects ) {
			$this->remove_suppression_hooks();
		}
		$this->recording = false;
	}

	public function is_recording(): bool {
		return $this->recording;
	}

	public function truncated(): bool {
		return $this->truncated;
	}

	/** @return array<int,string> */
	public function suppressed(): array {
		return array_values( array_unique( $this->suppressed ) );
	}

	/**
	 * Resolve every tracked entity into a before/after entry.
	 *
	 * Entries with no effective change are dropped.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function entries(): array {
		$entries = array();

		foreach ( $this->tracked as $tracked ) {
			$kind   = (string) $tracked['kind'];
			$before = $tracked['before'];
			$after  = array_key_exists( 'after', $tracked ) ? $tracked['after'] : $this->read_state( $kind, $tracked );

			if ( $before === $after ) {
				continue;
			}

			$entries[] = array(
				'kind'      => $kind,
				'key'       => (string) $tracked['key'],
				'target'    => $tracked['target'],
				'operation' => $this->operation_of( $before, $after ),
				'before'    => $before,
				'after'     => $after,
			);
		}

		return $entries;
	}

	/**
	 * Group resolved entries into the shape reported by dry runs.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return array<string,mixed>
	 */
	public function summarize( array $entries ): array {
		$summary = array(
			'posts'    => array(),
			'meta'     => array(),
			'terms'    => array(),
			'options'  => array(),
			'comments' => array(),
			'users'    => array(),
		);

		foreach ( $entries as $entry ) {
			switch ( $entry['kind'] ) {
				case self::KIND_POST:
					$summary['posts'][] = array(
						'id'        => (int) $entry['target']['post_id'],
						'operation' => $entry['operation'],
						'fields'    => $this->diff_maps( (array) $entry['before'], (array) $entry['after'] ),
					);
					break;
				case self::KIND_META:
					$summary['meta'][] = array(
						'post_id'  => (int) $entry['target']['post_id'],
						'meta_key' => (string) $entry['target']['meta_key'],
						'before'   => $entry['before'],
						'after'    => $entry['after'],
					);
					break;
				case self::KIND_OBJECT_TERMS:
					$summary['terms'][] = array(
						'object_id' => (int) $entry['target']['object_id'],
						'taxonomy'  => (string) $entry['target']['taxonomy'],
						'before'    => $entry['before'],
						'after'     => $entry['after'],
					);
					break;
				case self::KIND_TERM:
					$summary['terms'][] = array(
						'term_id'   => (int) $entry['target']['term_id'],
						'operation' => $entry['operation'],
						'before'    => $entry['before'],
						'after'     => $entry['after'],
					);
					break;
				case self::KIND_OPTION:
					$summary['options'][] = array(
						'option' => (string) $entry['target']['option'],
						'before' => $entry['before'],
						'after'  => $entry['after'],
					);
					break;
				case self::KIND_COMMENT:
					$summary['comments'][] = array(
						'id'        => (int) $entry['target']['comment_id'],
						'operation' => $entry['operation'],
						'fields'    => $this->diff_maps( (array) $entry['before'], (array) $entry['after'] ),
					);
					break;
				case self::KIND_USER:
					$summary['users'][] = array(
						'id'        => (int) $entry['target']['user_id'],
						'operation' => $entry['operation'],
						'fields'    => $this->diff_maps( (array) $entry['before'], (array) $entry['after'] ),
					);
					break;
			}
		}

		$summary['total'] = count( $entries );

		return $summary;
	}

	/**
	 * Drop caches for every touched entity.
	 *
	 * Required after a transaction rollback, when caches still hold values the
	 * database no longer contains.
	 */
	public function invalidate_caches(): void {
		$flush_options = false;

		foreach ( $this->tracked as $tracked ) {
			$target = (array) $tracked['target'];
			switch ( $tracked['kind'] ) {
				case self::KIND_POST:
					clean_post_cache( (int) $target['post_id'] );
					break;
				case self::KIND_META:
					wp_cache_delete( (int) $target['post_id'], 'post_meta' );
					break;
				case self::KIND_OBJECT_TERMS:
					clean_object_term_cache( (int) $target['object_id'], (string) $target['taxonomy'] );
					break;
				case self::KIND_TERM:
					clean_term_cache( array( (int) $target['term_id'] ) );
					break;
				case self::KIND_OPTION:
					wp_cache_delete( (string) $target['option'], 'options' );
					$flush_options = true;
					break;
				case self::KIND_COMMENT:
					clean_comment_cache( (int) $target['comment_id'] );
					break;
				case self::KIND_USER:
					clean_user_cache( (int) $target['user_id'] );
					break;
			}
		}

		if ( $flush_options ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	private function add_capture_hooks(): void {
		add_action( 'pre_post_update', array( $this, 'on_post_touched' ), 0, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleting' ), 0, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_post_touched' ), 0, 1 );
		add_action( 'wp_insert_post', array( $this, 'on_post_touched' ), 9999, 1 );
		add_action( 'deleted_post', array( $this, 'on_post_touched' ), 9999, 1 );

		add_filter( 'update_post_metadata', array( $this, 'on_meta_touched' ), 0, 3 );
		add_filter( 'add_post_metadata', array( $this, 'on_meta_touched' ), 0, 3 );
		add_filter( 'delete_post_metadata', array( $this, 'on_meta_touched' ), 0, 3 );

		add_filter( 'pre_update_option', array( $this, 'on_option_updating' ), 0, 3 );
		add_action( 'add_option', array( $this, 'on_option_adding' ), 0, 1 );
		add_action( 'delete_option', array( $this, 'on_option_deleting' ), 0, 1 );

		add_action( 'set_object_terms', array( $this, 'on_object_terms' ), 0, 6 );
		add_action( 'edit_terms', array( $this, 'on_term_touched' ), 0, 1 );
		add_action( 'pre_delete_term', array( $this, 'on_term_touched' ), 0, 1 );
		add_action( 'created_term', array( $this, 'on_term_touched' ), 9999, 1 );
		add_action( 'edited_term', array( $this, 'on_term_touched' ), 9999, 1 );
		add_action( 'delete_term', array( $this, 'on_term_touched' ), 9999, 1 );

		add_action( 'edit_comment', array( $this, 'on_comment_touched' ), 0, 1 );
		add_action( 'delete_comment', array( $this, 'on_comment_touched' ), 0, 1 );
		add_action( 'wp_insert_comment', array( $this, 'on_comment_touched' ), 9999, 1 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 9999, 3 );

		add_action( 'profile_update', array( $this, 'on_user_touched' ), 0, 1 );
		add_action( 'delete_user', array( $this, 'on_user_touched' ), 0, 1 );
		add_action( 'user_register', array( $this, 'on_user_touched' ), 9999, 1 );
	}

	private function remove_capture_hooks(): void {
		remove_action( 'pre_post_update', array( $this, 'on_post_touched' ), 0 );
		remove_action( 'before_delete_post', array( $this, 'on_post_deleting' ), 0 );
		remove_action( 'wp_trash_post', array( $this, 'on_post_touched' ), 0 );
		remove_action( 'wp_insert_post', array( $this, 'on_post_touched' ), 9999 );
		remove_action( 'deleted_post', array( $this, 'on_post_touched' ), 9999 );

		remove_filter( 'update_post_metadata', array( $this, 'on_meta_touched' ), 0 );
		remove_filter( 'add_post_metadata', array( $this, 'on_meta_touched' ), 0 );
		remove_filter( 'delete_post_metadata', array( $this, 'on_meta_touched' ), 0 );

		remove_filter( 'pre_update_option', array( $this, 'on_option_updating' ), 0 );
		remove_action( 'add_option', array( $this, 'on_option_adding' ), 0 );
		remove_action( 'delete_option', array( $this, 'on_option_deleting' ), 0 );

		remove_action( 'set_object_terms', array( $this, 'on_object_terms' ), 0 );
		remove_action( 'edit_terms', array( $this, 'on_term_touched' ), 0 );
		remove_action( 'pre_delete_term', array( $this, 'on_term_touched' ), 0 );
		remove_action( 'created_term', array( $this, 'on_term_touched' ), 9999 );
		remove_action( 'edited_term', array( $this, 'on_term_touched' ), 9999 );
		remove_action( 'delete_term', array( $this, 'on_term_touched' ), 9999 );

		remove_action( 'edit_comment', array( $this, 'on_comment_touched' ), 0 );
		remove_action( 'delete_comment', array( $this, 'on_comment_touched' ), 0 );
		remove_action( 'wp_insert_comment', array( $this, 'on_comment_touched' ), 9999 );
		remove_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 9999 );

		remove_action( 'profile_update', array( $this, 'on_user_touched' ), 0 );
		remove_action( 'delete_user', array( $this, 'on_user_touched' ), 0 );
		remove_action( 'user_register', array( $this, 'on_user_touched' ), 9999 );
	}

	private function add_suppression_hooks(): void {
		add_filter( 'pre_http_request', array( $this, 'suppress_http' ), 0, 3 );
		add_filter( 'pre_wp_mail', array( $this, 'suppress_mail' ), 0 );
		add_filter( 'pre_schedule_event', array( $this, 'suppress_cron' ), 0 );
		add_filter( 'pre_reschedule_event', array( $this, 'suppress_cron' ), 0 );
	}

	private function remove_suppression_hooks(): void {
		remove_filter( 'pre_http_request', array( $this, 'suppress_http' ), 0 );
		remove_filter( 'pre_wp_mail', array( $this, 'suppress_mail' ), 0 );
		remove_filter( 'pre_schedule_event', array( $this, 'suppress_cron' ), 0 );
		remove_filter( 'pre_reschedule_event', array( $this, 'suppress_cron' ), 0 );
	}

	public function suppress_http( mixed $preempt, array $args, string $url ): \WP_Error {
		unset( $preempt, $args );
		$this->suppressed[] = 'http:' . preg_replace( '#(://[^/]+).*$#', '$1', $url );

		return new \WP_Error( 'dry_run_blocked', __( 'Outbound HTTP is blocked while previewing a call.', 'mindio-magic-mcp' ) );
	}

	public function suppress_mail(): bool {
		$this->suppressed[] = 'mail';

		return false;
	}

	public function suppress_cron(): bool {
		$this->suppressed[] = 'cron';

		return false;
	}

	public function on_post_touched( int $post_id ): void {
		$this->track(
			self::KIND_POST,
			(string) $post_id,
			array( 'post_id' => $post_id ),
			fn() => $this->post_snapshot( $post_id )
		);
	}

	/**
	 * A permanent delete needs the full row, meta, and terms so the entry can be replayed.
	 */
	public function on_post_deleting( int $post_id ): void {
		$this->track(
			self::KIND_POST,
			(string) $post_id,
			array( 'post_id' => $post_id ),
			fn() => $this->post_snapshot( $post_id, true )
		);
	}

	/** @return null Always null so WordPress performs the real write. */
	public function on_meta_touched( mixed $check, int $object_id, string $meta_key ) {
		if ( ! is_protected_meta( $meta_key, 'post' ) ) {
			$this->track(
				self::KIND_META,
				$object_id . ':' . $meta_key,
				array( 'post_id' => $object_id, 'meta_key' => $meta_key ),
				fn() => $this->clip( get_post_meta( $object_id, $meta_key, true ) )
			);
		}

		return $check;
	}

	/** @return mixed The unchanged value so WordPress performs the real write. */
	public function on_option_updating( mixed $value, string $option, mixed $old_value ): mixed {
		$this->track( self::KIND_OPTION, $option, array( 'option' => $option ), fn() => $this->clip( $old_value ) );

		return $value;
	}

	public function on_option_adding( string $option ): void {
		$this->track( self::KIND_OPTION, $option, array( 'option' => $option ), static fn() => null );
	}

	public function on_option_deleting( string $option ): void {
		$this->track( self::KIND_OPTION, $option, array( 'option' => $option ), fn() => $this->clip( get_option( $option ) ) );
	}

	/**
	 * @param array<int,int> $tt_ids
	 * @param array<int,int> $old_tt_ids
	 */
	public function on_object_terms( int $object_id, mixed $terms, array $tt_ids, string $taxonomy, mixed $append, array $old_tt_ids ): void {
		unset( $terms, $append );
		$key = $object_id . ':' . $taxonomy;
		$this->track(
			self::KIND_OBJECT_TERMS,
			$key,
			array( 'object_id' => $object_id, 'taxonomy' => $taxonomy ),
			fn() => $this->term_slugs( $old_tt_ids )
		);
		$this->tracked[ self::KIND_OBJECT_TERMS . '|' . $key ]['after'] = $this->term_slugs( $tt_ids );
	}

	public function on_term_touched( int $term_id ): void {
		$this->track(
			self::KIND_TERM,
			(string) $term_id,
			array( 'term_id' => $term_id ),
			fn() => $this->term_snapshot( $term_id )
		);
	}

	public function on_comment_touched( mixed $comment_id ): void {
		$comment_id = (int) $comment_id;
		$this->track(
			self::KIND_COMMENT,
			(string) $comment_id,
			array( 'comment_id' => $comment_id ),
			fn() => $this->comment_snapshot( $comment_id )
		);
	}

	public function on_comment_status( mixed $new_status, mixed $old_status, mixed $comment ): void {
		unset( $new_status, $old_status );
		if ( $comment instanceof \WP_Comment ) {
			$this->on_comment_touched( $comment->comment_ID );
		}
	}

	public function on_user_touched( int $user_id ): void {
		$this->track(
			self::KIND_USER,
			(string) $user_id,
			array( 'user_id' => $user_id ),
			fn() => $this->user_snapshot( $user_id )
		);
	}

	/**
	 * Record the before-state exactly once per entity.
	 *
	 * @param array<string,mixed> $target
	 */
	private function track( string $kind, string $key, array $target, callable $snapshot ): void {
		$index = $kind . '|' . $key;
		if ( isset( $this->tracked[ $index ] ) ) {
			return;
		}
		$this->tracked[ $index ] = array(
			'kind'   => $kind,
			'key'    => $key,
			'target' => $target,
			'before' => call_user_func( $snapshot ),
		);
	}

	/** @param array<string,mixed> $tracked */
	private function read_state( string $kind, array $tracked ): mixed {
		$target = (array) $tracked['target'];

		return match ( $kind ) {
			self::KIND_POST    => $this->post_snapshot( (int) $target['post_id'] ),
			self::KIND_META    => $this->clip( get_post_meta( (int) $target['post_id'], (string) $target['meta_key'], true ) ),
			self::KIND_TERM    => $this->term_snapshot( (int) $target['term_id'] ),
			self::KIND_OPTION  => $this->clip( get_option( (string) $target['option'], null ) ),
			self::KIND_COMMENT => $this->comment_snapshot( (int) $target['comment_id'] ),
			self::KIND_USER    => $this->user_snapshot( (int) $target['user_id'] ),
			default            => null,
		};
	}

	/** @return array<string,mixed>|null */
	private function post_snapshot( int $post_id, bool $full = false ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$snapshot = array(
			'post_title'   => $post->post_title,
			'post_name'    => $post->post_name,
			'post_status'  => $post->post_status,
			'post_type'    => $post->post_type,
			'post_parent'  => (int) $post->post_parent,
			'post_author'  => (int) $post->post_author,
			'post_excerpt' => $this->clip( $post->post_excerpt ),
			'post_content' => $this->clip( $post->post_content ),
			'menu_order'   => (int) $post->menu_order,
		);

		if ( $full ) {
			$meta = array();
			foreach ( (array) get_post_meta( $post_id ) as $meta_key => $values ) {
				if ( is_protected_meta( (string) $meta_key, 'post' ) ) {
					continue;
				}
				$meta[ (string) $meta_key ] = $this->clip( maybe_unserialize( $values[0] ?? '' ) );
			}
			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				$assigned = get_the_terms( $post, $taxonomy );
				if ( is_array( $assigned ) ) {
					$terms[ $taxonomy ] = wp_list_pluck( $assigned, 'slug' );
				}
			}
			$snapshot['_restore'] = array(
				'post_date_gmt' => $post->post_date_gmt,
				'post_mime_type' => $post->post_mime_type,
				'comment_status' => $post->comment_status,
				'ping_status'   => $post->ping_status,
				'meta'          => $meta,
				'terms'         => $terms,
			);
		}

		return $snapshot;
	}

	/** @return array<string,mixed>|null */
	private function term_snapshot( int $term_id ): ?array {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		return array(
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'parent'      => (int) $term->parent,
			'description' => $this->clip( $term->description ),
		);
	}

	/** @return array<string,mixed>|null */
	private function comment_snapshot( int $comment_id ): ?array {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return null;
		}

		return array(
			'comment_post_ID'  => (int) $comment->comment_post_ID,
			'comment_author'   => $comment->comment_author,
			'comment_approved' => $comment->comment_approved,
			'comment_content'  => $this->clip( $comment->comment_content ),
		);
	}

	/** @return array<string,mixed>|null */
	private function user_snapshot( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		return array(
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
		);
	}

	/**
	 * @param array<int,int> $term_taxonomy_ids
	 * @return array<int,string>
	 */
	private function term_slugs( array $term_taxonomy_ids ): array {
		$slugs = array();
		foreach ( $term_taxonomy_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id );
			if ( $term instanceof \WP_Term ) {
				$slugs[] = $term->slug;
			}
		}
		sort( $slugs, SORT_STRING );

		return $slugs;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @return array<string,array<string,mixed>>
	 */
	private function diff_maps( array $before, array $after ): array {
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $field ) {
			if ( '_restore' === $field ) {
				continue;
			}
			$old = $before[ $field ] ?? null;
			$new = $after[ $field ] ?? null;
			if ( $old !== $new ) {
				$diff[ $field ] = array( 'before' => $old, 'after' => $new );
			}
		}

		return $diff;
	}

	private function operation_of( mixed $before, mixed $after ): string {
		if ( null === $before && null !== $after ) {
			return 'create';
		}
		if ( null !== $before && null === $after ) {
			return 'delete';
		}

		return 'update';
	}

	private function clip( mixed $value ): mixed {
		if ( is_string( $value ) && mb_strlen( $value ) > self::MAX_VALUE_CHARS ) {
			$this->truncated = true;
			return mb_substr( $value, 0, self::MAX_VALUE_CHARS ) . '…';
		}

		return $value;
	}
}
