<?php
/**
 * Named, revertible groups of tool calls.
 *
 * A changeset journals the before and after state of everything its member
 * calls touch, so the whole group can be undone later. Post revisions only
 * cover post content; this also covers meta, term assignments, options,
 * comments, and users.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Changeset {
	public const STATUS_OPEN     = 'open';
	public const STATUS_CLOSED   = 'closed';
	public const STATUS_REVERTED = 'reverted';

	private const MAX_ENTRIES = 5000;

	/**
	 * Open a changeset and return its record.
	 *
	 * @return array<string,mixed>
	 */
	public function begin( string $label, Auth $auth ): array {
		global $wpdb;

		$changeset_id = 'cs_' . bin2hex( random_bytes( 8 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned journal table.
		$wpdb->insert(
			Installer::changeset_table(),
			array(
				'changeset_id' => $changeset_id,
				'label'        => mb_substr( sanitize_text_field( $label ), 0, 190 ),
				'status'       => self::STATUS_OPEN,
				'user_id'      => get_current_user_id(),
				'token_id'     => $auth->current_token_id(),
				'created_at'   => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return (array) $this->get( $changeset_id );
	}

	/**
	 * Record the entities one call touched.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return int Number of journal rows written.
	 */
	public function record( string $changeset_id, string $tool, array $entries ): int {
		global $wpdb;

		$changeset = $this->get( $changeset_id );
		if ( ! $changeset || self::STATUS_OPEN !== $changeset['status'] ) {
			return 0;
		}
		if ( $changeset['entries'] >= self::MAX_ENTRIES ) {
			return 0;
		}

		$written = 0;
		foreach ( $entries as $entry ) {
			if ( $changeset['entries'] + $written >= self::MAX_ENTRIES ) {
				break;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned journal table.
			$wpdb->insert(
				Installer::changeset_entry_table(),
				array(
					'changeset_id' => $changeset_id,
					'tool'         => sanitize_key( $tool ),
					'kind'         => sanitize_key( (string) $entry['kind'] ),
					'object_key'   => mb_substr( (string) $entry['key'], 0, 190 ),
					'operation'    => sanitize_key( (string) $entry['operation'] ),
					'target'       => (string) wp_json_encode( $entry['target'] ),
					'before_state' => (string) wp_json_encode( $entry['before'] ),
					'after_state'  => (string) wp_json_encode( $entry['after'] ),
					'created_at'   => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			++$written;
		}

		if ( $written ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned journal table.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET entries = entries + %d, updated_at = %s WHERE changeset_id = %s',
					Installer::changeset_table(),
					$written,
					current_time( 'mysql', true ),
					$changeset_id
				)
			);
		}

		return $written;
	}

	/** @return array<string,mixed>|null */
	public function get( string $changeset_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Journal reads must reflect the current transaction.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE changeset_id = %s', Installer::changeset_table(), $changeset_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_changesets( int $limit = 25, string $status = '' ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );
		if ( in_array( $status, array( self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_REVERTED ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Journal reads must be current.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', Installer::changeset_table(), $status, $limit ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Journal reads must be current.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Installer::changeset_table(), $limit ),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'normalize' ), (array) $rows );
	}

	/** @return array<int,array<string,mixed>> */
	public function entries( string $changeset_id, bool $newest_first = false ): array {
		global $wpdb;

		$order = $newest_first ? 'DESC' : 'ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Order direction is a fixed literal chosen above; journal reads must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE changeset_id = %s ORDER BY id ' . $order,
				Installer::changeset_entry_table(),
				$changeset_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']           = (int) $row['id'];
				$row['target']       = json_decode( (string) $row['target'], true );
				$row['before_state'] = json_decode( (string) $row['before_state'], true );
				$row['after_state']  = json_decode( (string) $row['after_state'], true );
				return $row;
			},
			(array) $rows
		);
	}

	public function close( string $changeset_id ): bool {
		return $this->set_status( $changeset_id, self::STATUS_CLOSED );
	}

	/**
	 * Undo every recorded entry, newest first.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function revert( string $changeset_id ): array|\WP_Error {
		$changeset = $this->get( $changeset_id );
		if ( ! $changeset ) {
			return new \WP_Error( 'unknown_changeset', __( 'Unknown changeset.', 'mindio-magic-mcp' ) );
		}
		if ( self::STATUS_REVERTED === $changeset['status'] ) {
			return new \WP_Error( 'changeset_reverted', __( 'This changeset has already been reverted.', 'mindio-magic-mcp' ) );
		}

		$reverted = 0;
		$skipped  = array();

		foreach ( $this->entries( $changeset_id, true ) as $entry ) {
			$outcome = $this->revert_entry( $entry );
			if ( is_wp_error( $outcome ) ) {
				$skipped[] = array(
					'kind'   => (string) $entry['kind'],
					'key'    => (string) $entry['object_key'],
					'reason' => $outcome->get_error_message(),
				);
				continue;
			}
			if ( $outcome ) {
				++$reverted;
			}
		}

		$this->set_status( $changeset_id, self::STATUS_REVERTED );

		return array(
			'changeset_id' => $changeset_id,
			'reverted'     => $reverted,
			'skipped'      => $skipped,
			'status'       => self::STATUS_REVERTED,
		);
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return bool|\WP_Error
	 */
	private function revert_entry( array $entry ) {
		$target = (array) $entry['target'];
		$before = $entry['before_state'];
		$after  = $entry['after_state'];

		return match ( (string) $entry['kind'] ) {
			Change_Recorder::KIND_POST         => $this->revert_post( (int) $target['post_id'], $before, $after ),
			Change_Recorder::KIND_META         => $this->revert_meta( (int) $target['post_id'], (string) $target['meta_key'], $before ),
			Change_Recorder::KIND_OBJECT_TERMS => $this->revert_object_terms( (int) $target['object_id'], (string) $target['taxonomy'], (array) $before ),
			Change_Recorder::KIND_TERM         => $this->revert_term( (int) $target['term_id'], $before, $after ),
			Change_Recorder::KIND_OPTION       => $this->revert_option( (string) $target['option'], $before, $after ),
			Change_Recorder::KIND_COMMENT      => $this->revert_comment( (int) $target['comment_id'], $before, $after ),
			Change_Recorder::KIND_USER         => $this->revert_user( (int) $target['user_id'], $before ),
			default                            => new \WP_Error( 'unknown_kind', __( 'Unsupported journal entry.', 'mindio-magic-mcp' ) ),
		};
	}

	/** @return bool|\WP_Error */
	private function revert_post( int $post_id, mixed $before, mixed $after ) {
		if ( null === $before && is_array( $after ) ) {
			if ( ! current_user_can( 'delete_post', $post_id ) ) {
				return new \WP_Error( 'forbidden', __( 'Your user cannot delete the post this changeset created.', 'mindio-magic-mcp' ) );
			}
			return (bool) wp_delete_post( $post_id, true );
		}
		if ( ! is_array( $before ) ) {
			return false;
		}
		if ( null === $after ) {
			return $this->restore_deleted_post( $post_id, $before );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'Your user cannot edit the post this changeset changed.', 'mindio-magic-mcp' ) );
		}

		$update = array( 'ID' => $post_id );
		foreach ( array( 'post_title', 'post_name', 'post_status', 'post_excerpt', 'post_content', 'post_parent', 'post_author', 'menu_order' ) as $field ) {
			if ( array_key_exists( $field, $before ) ) {
				$update[ $field ] = $before[ $field ];
			}
		}

		return ! is_wp_error( wp_update_post( $update, true ) );
	}

	/**
	 * @param array<string,mixed> $before
	 * @return bool|\WP_Error
	 */
	private function restore_deleted_post( int $post_id, array $before ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new \WP_Error( 'forbidden', __( 'Your user cannot restore a deleted post.', 'mindio-magic-mcp' ) );
		}
		$restore = (array) ( $before['_restore'] ?? array() );

		$insert = array(
			'import_id'    => $post_id,
			'post_title'   => (string) ( $before['post_title'] ?? '' ),
			'post_name'    => (string) ( $before['post_name'] ?? '' ),
			'post_status'  => (string) ( $before['post_status'] ?? 'draft' ),
			'post_type'    => (string) ( $before['post_type'] ?? 'post' ),
			'post_parent'  => (int) ( $before['post_parent'] ?? 0 ),
			'post_author'  => (int) ( $before['post_author'] ?? get_current_user_id() ),
			'post_excerpt' => (string) ( $before['post_excerpt'] ?? '' ),
			'post_content' => (string) ( $before['post_content'] ?? '' ),
			'menu_order'   => (int) ( $before['menu_order'] ?? 0 ),
		);
		if ( ! empty( $restore['post_date_gmt'] ) ) {
			$insert['post_date_gmt'] = (string) $restore['post_date_gmt'];
		}

		$new_id = wp_insert_post( $insert, true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		foreach ( (array) ( $restore['meta'] ?? array() ) as $meta_key => $meta_value ) {
			update_post_meta( (int) $new_id, (string) $meta_key, $meta_value );
		}
		foreach ( (array) ( $restore['terms'] ?? array() ) as $taxonomy => $slugs ) {
			wp_set_object_terms( (int) $new_id, (array) $slugs, (string) $taxonomy, false );
		}

		return true;
	}

	private function revert_meta( int $post_id, string $meta_key, mixed $before ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( null === $before || '' === $before ) {
			return (bool) delete_post_meta( $post_id, $meta_key );
		}

		return false !== update_post_meta( $post_id, $meta_key, $before );
	}

	/** @param array<int,string> $before */
	private function revert_object_terms( int $object_id, string $taxonomy, array $before ): bool {
		if ( ! current_user_can( 'edit_post', $object_id ) ) {
			return false;
		}

		return ! is_wp_error( wp_set_object_terms( $object_id, $before, $taxonomy, false ) );
	}

	/** @return bool|\WP_Error */
	private function revert_term( int $term_id, mixed $before, mixed $after ) {
		if ( null === $before && is_array( $after ) ) {
			$taxonomy = (string) ( $after['taxonomy'] ?? '' );
			return '' !== $taxonomy && true === wp_delete_term( $term_id, $taxonomy );
		}
		if ( ! is_array( $before ) ) {
			return false;
		}
		$taxonomy = (string) ( $before['taxonomy'] ?? '' );
		if ( '' === $taxonomy ) {
			return false;
		}
		if ( null === $after ) {
			$created = wp_insert_term(
				(string) $before['name'],
				$taxonomy,
				array( 'slug' => (string) $before['slug'], 'parent' => (int) $before['parent'], 'description' => (string) $before['description'] )
			);
			return ! is_wp_error( $created );
		}

		$updated = wp_update_term(
			$term_id,
			$taxonomy,
			array( 'name' => (string) $before['name'], 'slug' => (string) $before['slug'], 'parent' => (int) $before['parent'], 'description' => (string) $before['description'] )
		);

		return ! is_wp_error( $updated );
	}

	/** @return bool|\WP_Error */
	private function revert_option( string $option, mixed $before, mixed $after ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'Your user cannot restore site options.', 'mindio-magic-mcp' ) );
		}
		if ( null === $before && null !== $after ) {
			return delete_option( $option );
		}

		return update_option( $option, $before );
	}

	private function revert_comment( int $comment_id, mixed $before, mixed $after ): bool {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}
		if ( null === $before && is_array( $after ) ) {
			return (bool) wp_delete_comment( $comment_id, true );
		}
		if ( ! is_array( $before ) ) {
			return false;
		}
		if ( null === $after ) {
			$restored = wp_insert_comment(
				array(
					'comment_ID'       => $comment_id,
					'comment_post_ID'  => (int) $before['comment_post_ID'],
					'comment_author'   => (string) $before['comment_author'],
					'comment_approved' => (string) $before['comment_approved'],
					'comment_content'  => (string) $before['comment_content'],
				)
			);
			return (bool) $restored;
		}

		return (bool) wp_update_comment(
			array(
				'comment_ID'       => $comment_id,
				'comment_approved' => (string) $before['comment_approved'],
				'comment_content'  => (string) $before['comment_content'],
			)
		);
	}

	private function revert_user( int $user_id, mixed $before ): bool {
		if ( ! is_array( $before ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		$updated = wp_update_user(
			array(
				'ID'           => $user_id,
				'user_email'   => (string) $before['user_email'],
				'display_name' => (string) $before['display_name'],
			)
		);
		if ( is_wp_error( $updated ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User && current_user_can( 'promote_users' ) ) {
			$user->set_role( (string) ( $before['roles'][0] ?? 'subscriber' ) );
		}

		return true;
	}

	private function set_status( string $changeset_id, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned journal table.
		return (bool) $wpdb->update(
			Installer::changeset_table(),
			array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'changeset_id' => $changeset_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		$row['entries'] = (int) $row['entries'];

		return $row;
	}
}
