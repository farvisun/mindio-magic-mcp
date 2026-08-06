<?php
/**
 * Transaction-backed preview of write tools.
 *
 * A dry run executes the real tool callback inside a database transaction,
 * records every post, meta, term, option, comment, and user row it touches,
 * then rolls the transaction back and reports the diff. Effects that would
 * escape the transaction (outbound HTTP, mail, cron) are suppressed for the
 * duration of the call.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dry_Run {
	private const SUPPORT_TRANSIENT = 'mindio_magic_mcp_dry_run_supported';
	private const MAX_VALUE_CHARS   = 20000;
	private const MAX_ENTRIES       = 500;

	private static bool $active = false;

	/** @var array<string,array<string,mixed>> */
	private array $posts = array();

	/** @var array<string,array<string,mixed>> */
	private array $meta = array();

	/** @var array<string,array<string,mixed>> */
	private array $options = array();

	/** @var array<string,array<string,mixed>> */
	private array $terms = array();

	/** @var array<string,array<string,mixed>> */
	private array $comments = array();

	/** @var array<string,array<string,mixed>> */
	private array $users = array();

	/** @var array<int,string> */
	private array $suppressed = array();

	private bool $truncated = false;

	/**
	 * True while a dry run is executing, so side-effecting subsystems can stand down.
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Transactions only roll back reliably on a transactional storage engine.
	 */
	public static function is_supported(): bool {
		global $wpdb;

		$cached = get_transient( self::SUPPORT_TRANSIENT );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Engine lookup is cached in a transient immediately below.
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$wpdb->posts
			)
		);

		$supported = is_string( $engine ) && 'innodb' === strtolower( $engine );
		set_transient( self::SUPPORT_TRANSIENT, $supported ? '1' : '0', DAY_IN_SECONDS );

		return $supported;
	}

	/**
	 * Execute a callback, capture what it would change, and roll it back.
	 *
	 * @param callable $callback Receives no arguments and returns the tool result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( callable $callback ) {
		global $wpdb;

		if ( self::$active ) {
			return new \WP_Error( 'dry_run_nested', __( 'A dry run cannot be started inside another dry run.', 'mindio-magic-mcp' ) );
		}
		if ( ! self::is_supported() ) {
			return new \WP_Error( 'dry_run_unsupported', __( 'Dry runs require a transactional database storage engine such as InnoDB.', 'mindio-magic-mcp' ) );
		}

		self::$active = true;
		$this->add_capture_hooks();
		$this->add_suppression_hooks();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control cannot use the caching helpers.
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = call_user_func( $callback );
			$changes = $this->resolve_changes();
		} catch ( \Throwable $throwable ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->query( 'SET autocommit = 1' );
			$this->finish();
			throw $throwable;
		}

		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( 'SET autocommit = 1' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->invalidate_caches();
		$this->finish();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'dry_run'    => true,
			'applied'    => false,
			'changes'    => $changes,
			'suppressed' => array_values( array_unique( $this->suppressed ) ),
			'truncated'  => $this->truncated,
			'result'     => is_array( $result ) ? $result : array( 'result' => $result ),
		);
	}

	private function finish(): void {
		$this->remove_capture_hooks();
		$this->remove_suppression_hooks();
		self::$active = false;
	}

	private function add_capture_hooks(): void {
		add_action( 'pre_post_update', array( $this, 'capture_post_before' ), 0, 1 );
		add_action( 'before_delete_post', array( $this, 'capture_post_before' ), 0, 1 );
		add_action( 'wp_trash_post', array( $this, 'capture_post_before' ), 0, 1 );
		add_action( 'wp_insert_post', array( $this, 'capture_post_touched' ), 9999, 1 );
		add_action( 'deleted_post', array( $this, 'capture_post_touched' ), 9999, 1 );

		add_filter( 'update_post_metadata', array( $this, 'capture_meta_before' ), 0, 3 );
		add_filter( 'add_post_metadata', array( $this, 'capture_meta_before' ), 0, 3 );
		add_filter( 'delete_post_metadata', array( $this, 'capture_meta_before' ), 0, 3 );

		add_filter( 'pre_update_option', array( $this, 'capture_option_before' ), 0, 3 );
		add_action( 'add_option', array( $this, 'capture_option_added' ), 0, 1 );
		add_action( 'delete_option', array( $this, 'capture_option_deleted' ), 0, 1 );

		add_action( 'set_object_terms', array( $this, 'capture_object_terms' ), 0, 6 );
		add_action( 'edit_terms', array( $this, 'capture_term_before' ), 0, 1 );
		add_action( 'pre_delete_term', array( $this, 'capture_term_before' ), 0, 1 );
		add_action( 'created_term', array( $this, 'capture_term_touched' ), 9999, 1 );
		add_action( 'edited_term', array( $this, 'capture_term_touched' ), 9999, 1 );
		add_action( 'delete_term', array( $this, 'capture_term_touched' ), 9999, 1 );

		add_action( 'edit_comment', array( $this, 'capture_comment_before' ), 0, 1 );
		add_action( 'delete_comment', array( $this, 'capture_comment_before' ), 0, 1 );
		add_action( 'wp_insert_comment', array( $this, 'capture_comment_touched' ), 9999, 1 );
		add_action( 'transition_comment_status', array( $this, 'capture_comment_status' ), 9999, 3 );

		add_action( 'profile_update', array( $this, 'capture_user_before' ), 0, 1 );
		add_action( 'delete_user', array( $this, 'capture_user_before' ), 0, 1 );
		add_action( 'user_register', array( $this, 'capture_user_touched' ), 9999, 1 );
	}

	private function remove_capture_hooks(): void {
		remove_action( 'pre_post_update', array( $this, 'capture_post_before' ), 0 );
		remove_action( 'before_delete_post', array( $this, 'capture_post_before' ), 0 );
		remove_action( 'wp_trash_post', array( $this, 'capture_post_before' ), 0 );
		remove_action( 'wp_insert_post', array( $this, 'capture_post_touched' ), 9999 );
		remove_action( 'deleted_post', array( $this, 'capture_post_touched' ), 9999 );

		remove_filter( 'update_post_metadata', array( $this, 'capture_meta_before' ), 0 );
		remove_filter( 'add_post_metadata', array( $this, 'capture_meta_before' ), 0 );
		remove_filter( 'delete_post_metadata', array( $this, 'capture_meta_before' ), 0 );

		remove_filter( 'pre_update_option', array( $this, 'capture_option_before' ), 0 );
		remove_action( 'add_option', array( $this, 'capture_option_added' ), 0 );
		remove_action( 'delete_option', array( $this, 'capture_option_deleted' ), 0 );

		remove_action( 'set_object_terms', array( $this, 'capture_object_terms' ), 0 );
		remove_action( 'edit_terms', array( $this, 'capture_term_before' ), 0 );
		remove_action( 'pre_delete_term', array( $this, 'capture_term_before' ), 0 );
		remove_action( 'created_term', array( $this, 'capture_term_touched' ), 9999 );
		remove_action( 'edited_term', array( $this, 'capture_term_touched' ), 9999 );
		remove_action( 'delete_term', array( $this, 'capture_term_touched' ), 9999 );

		remove_action( 'edit_comment', array( $this, 'capture_comment_before' ), 0 );
		remove_action( 'delete_comment', array( $this, 'capture_comment_before' ), 0 );
		remove_action( 'wp_insert_comment', array( $this, 'capture_comment_touched' ), 9999 );
		remove_action( 'transition_comment_status', array( $this, 'capture_comment_status' ), 9999 );

		remove_action( 'profile_update', array( $this, 'capture_user_before' ), 0 );
		remove_action( 'delete_user', array( $this, 'capture_user_before' ), 0 );
		remove_action( 'user_register', array( $this, 'capture_user_touched' ), 9999 );
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

	/** @return \WP_Error */
	public function suppress_http( mixed $preempt, array $args, string $url ): \WP_Error {
		$this->suppressed[] = 'http:' . preg_replace( '#(://[^/]+).*$#', '$1', $url );

		return new \WP_Error( 'dry_run_blocked', __( 'Outbound HTTP is blocked during a dry run.', 'mindio-magic-mcp' ) );
	}

	public function suppress_mail(): bool {
		$this->suppressed[] = 'mail';

		return false;
	}

	public function suppress_cron(): bool {
		$this->suppressed[] = 'cron';

		return false;
	}

	public function capture_post_before( int $post_id ): void {
		$key = (string) $post_id;
		if ( isset( $this->posts[ $key ] ) ) {
			return;
		}
		$this->posts[ $key ] = array( 'id' => $post_id, 'before' => $this->post_snapshot( $post_id ) );
	}

	public function capture_post_touched( int $post_id ): void {
		$this->capture_post_before( $post_id );
	}

	/**
	 * Runs on the pre-update metadata filters, so the stored value is still the old one.
	 *
	 * @return null Always null so WordPress proceeds with the real write.
	 */
	public function capture_meta_before( mixed $check, int $object_id, string $meta_key ) {
		$key = $object_id . ':' . $meta_key;
		if ( ! isset( $this->meta[ $key ] ) && ! is_protected_meta( $meta_key, 'post' ) ) {
			$this->meta[ $key ] = array(
				'post_id'  => $object_id,
				'meta_key' => $meta_key,
				'before'   => get_post_meta( $object_id, $meta_key, true ),
			);
		}

		return null;
	}

	/** @return mixed The unchanged value so WordPress proceeds with the real write. */
	public function capture_option_before( mixed $value, string $option, mixed $old_value ): mixed {
		if ( ! isset( $this->options[ $option ] ) ) {
			$this->options[ $option ] = array( 'option' => $option, 'before' => $old_value );
		}

		return $value;
	}

	public function capture_option_added( string $option ): void {
		if ( ! isset( $this->options[ $option ] ) ) {
			$this->options[ $option ] = array( 'option' => $option, 'before' => null );
		}
	}

	public function capture_option_deleted( string $option ): void {
		if ( ! isset( $this->options[ $option ] ) ) {
			$this->options[ $option ] = array( 'option' => $option, 'before' => get_option( $option ) );
		}
	}

	/**
	 * @param array<int,int> $tt_ids
	 * @param array<int,int> $old_tt_ids
	 */
	public function capture_object_terms( int $object_id, mixed $terms, array $tt_ids, string $taxonomy, mixed $append, array $old_tt_ids ): void {
		$key = $object_id . ':' . $taxonomy;
		if ( isset( $this->terms[ $key ] ) ) {
			return;
		}
		$this->terms[ $key ] = array(
			'object_id' => $object_id,
			'taxonomy'  => $taxonomy,
			'before'    => $this->term_names( $old_tt_ids ),
			'after'     => $this->term_names( $tt_ids ),
		);
	}

	public function capture_term_before( int $term_id ): void {
		$key = 'term:' . $term_id;
		if ( isset( $this->terms[ $key ] ) ) {
			return;
		}
		$term = get_term( $term_id );
		$this->terms[ $key ] = array(
			'term_id' => $term_id,
			'before'  => $term instanceof \WP_Term ? array( 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent ) : null,
		);
	}

	public function capture_term_touched( int $term_id ): void {
		$this->capture_term_before( $term_id );
	}

	public function capture_comment_before( mixed $comment_id ): void {
		$key = (string) (int) $comment_id;
		if ( isset( $this->comments[ $key ] ) ) {
			return;
		}
		$this->comments[ $key ] = array( 'id' => (int) $comment_id, 'before' => $this->comment_snapshot( (int) $comment_id ) );
	}

	public function capture_comment_touched( mixed $comment_id ): void {
		$this->capture_comment_before( $comment_id );
	}

	public function capture_comment_status( mixed $new_status, mixed $old_status, mixed $comment ): void {
		if ( $comment instanceof \WP_Comment ) {
			$this->capture_comment_before( $comment->comment_ID );
		}
	}

	public function capture_user_before( int $user_id ): void {
		$key = (string) $user_id;
		if ( isset( $this->users[ $key ] ) ) {
			return;
		}
		$this->users[ $key ] = array( 'id' => $user_id, 'before' => $this->user_snapshot( $user_id ) );
	}

	public function capture_user_touched( int $user_id ): void {
		$this->capture_user_before( $user_id );
	}

	/** @return array<string,mixed> */
	private function resolve_changes(): array {
		$changes = array(
			'posts'    => array(),
			'meta'     => array(),
			'terms'    => array(),
			'options'  => array(),
			'comments' => array(),
			'users'    => array(),
		);

		foreach ( $this->posts as $entry ) {
			$after = $this->post_snapshot( (int) $entry['id'] );
			$diff  = $this->diff_maps( (array) $entry['before'], (array) $after );
			if ( $diff || $entry['before'] !== $after ) {
				$changes['posts'][] = array(
					'id'        => (int) $entry['id'],
					'operation' => $this->operation_of( $entry['before'], $after ),
					'fields'    => $diff,
				);
			}
		}

		foreach ( $this->meta as $entry ) {
			$after = get_post_meta( (int) $entry['post_id'], (string) $entry['meta_key'], true );
			if ( $entry['before'] !== $after ) {
				$changes['meta'][] = array(
					'post_id'  => (int) $entry['post_id'],
					'meta_key' => (string) $entry['meta_key'],
					'before'   => $this->clip( $entry['before'] ),
					'after'    => $this->clip( $after ),
				);
			}
		}

		foreach ( $this->terms as $key => $entry ) {
			if ( isset( $entry['taxonomy'] ) ) {
				if ( $entry['before'] !== $entry['after'] ) {
					$changes['terms'][] = array(
						'object_id' => (int) $entry['object_id'],
						'taxonomy'  => (string) $entry['taxonomy'],
						'before'    => $entry['before'],
						'after'     => $entry['after'],
					);
				}
				continue;
			}

			$term  = get_term( (int) $entry['term_id'] );
			$after = $term instanceof \WP_Term ? array( 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent ) : null;
			if ( $entry['before'] !== $after ) {
				$changes['terms'][] = array(
					'term_id'   => (int) $entry['term_id'],
					'operation' => $this->operation_of( $entry['before'], $after ),
					'before'    => $entry['before'],
					'after'     => $after,
				);
			}
			unset( $key );
		}

		foreach ( $this->options as $entry ) {
			$after = get_option( (string) $entry['option'], null );
			if ( $entry['before'] !== $after ) {
				$changes['options'][] = array(
					'option' => (string) $entry['option'],
					'before' => $this->clip( $entry['before'] ),
					'after'  => $this->clip( $after ),
				);
			}
		}

		foreach ( $this->comments as $entry ) {
			$after = $this->comment_snapshot( (int) $entry['id'] );
			$diff  = $this->diff_maps( (array) $entry['before'], (array) $after );
			if ( $diff || $entry['before'] !== $after ) {
				$changes['comments'][] = array(
					'id'        => (int) $entry['id'],
					'operation' => $this->operation_of( $entry['before'], $after ),
					'fields'    => $diff,
				);
			}
		}

		foreach ( $this->users as $entry ) {
			$after = $this->user_snapshot( (int) $entry['id'] );
			$diff  = $this->diff_maps( (array) $entry['before'], (array) $after );
			if ( $diff || $entry['before'] !== $after ) {
				$changes['users'][] = array(
					'id'        => (int) $entry['id'],
					'operation' => $this->operation_of( $entry['before'], $after ),
					'fields'    => $diff,
				);
			}
		}

		$total = 0;
		foreach ( $changes as $kind => $entries ) {
			$total += count( $entries );
			if ( count( $entries ) > self::MAX_ENTRIES ) {
				$changes[ $kind ] = array_slice( $entries, 0, self::MAX_ENTRIES );
				$this->truncated  = true;
			}
		}
		$changes['total'] = $total;

		return $changes;
	}

	/** @return array<string,mixed>|null */
	private function post_snapshot( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return array(
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
	private function term_names( array $term_taxonomy_ids ): array {
		$names = array();
		foreach ( $term_taxonomy_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @return array<string,array<string,mixed>>
	 */
	private function diff_maps( array $before, array $after ): array {
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $field ) {
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
		if ( is_array( $value ) ) {
			$encoded = (string) wp_json_encode( $value );
			if ( strlen( $encoded ) > self::MAX_VALUE_CHARS ) {
				$this->truncated = true;
				return array( 'summary' => substr( $encoded, 0, self::MAX_VALUE_CHARS ) . '…' );
			}
		}

		return $value;
	}

	/**
	 * The rollback leaves object caches holding values that no longer exist in the
	 * database, so every touched entity is invalidated before returning.
	 */
	private function invalidate_caches(): void {
		foreach ( array_keys( $this->posts ) as $post_id ) {
			clean_post_cache( (int) $post_id );
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
		foreach ( $this->meta as $entry ) {
			wp_cache_delete( (int) $entry['post_id'], 'post_meta' );
		}
		foreach ( $this->terms as $entry ) {
			if ( isset( $entry['term_id'] ) ) {
				clean_term_cache( array( (int) $entry['term_id'] ) );
			}
			if ( isset( $entry['object_id'], $entry['taxonomy'] ) ) {
				clean_object_term_cache( (int) $entry['object_id'], (string) $entry['taxonomy'] );
			}
		}
		foreach ( array_keys( $this->options ) as $option ) {
			wp_cache_delete( (string) $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		if ( $this->options ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
		foreach ( array_keys( $this->comments ) as $comment_id ) {
			clean_comment_cache( (int) $comment_id );
		}
		foreach ( array_keys( $this->users ) as $user_id ) {
			clean_user_cache( (int) $user_id );
		}
	}
}
