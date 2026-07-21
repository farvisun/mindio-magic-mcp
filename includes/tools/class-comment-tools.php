<?php
/**
 * WordPress comment moderation tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Comment_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'list_comments',
			__( 'List comments for moderation with post, status, search, and pagination filters.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
					'status'   => array( 'type' => 'string', 'enum' => array( 'all', 'hold', 'approve', 'spam', 'trash' ) ),
					'search'   => array( 'type' => 'string', 'maxLength' => 200 ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_comments' ),
			Auth::SCOPE_READ,
			'moderate_comments',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'approve_comment',
			__( 'Approve a pending WordPress comment.', 'mindio-magic-mcp' ),
			$this->id_schema(),
			array( 'type' => 'object' ),
			array( $this, 'approve_comment' ),
			Auth::SCOPE_EDITOR,
			'moderate_comments',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'delete_comment',
			__( 'Move a comment to Trash, or permanently delete it only with force=true and confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'comment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'force'      => array( 'type' => 'boolean' ),
					'confirm'    => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'comment_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'delete_comment' ),
			Auth::SCOPE_EDITOR,
			'moderate_comments',
			array( 'destructiveHint' => true )
		);
		$this->registry->register(
			'reply_comment',
			__( 'Reply to an existing comment as the authenticated WordPress user.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'comment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'content'    => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20000 ),
				),
				'required'             => array( 'comment_id', 'content' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'reply_comment' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_reply' )
		);
	}

	public function can_reply( array $args ): bool {
		$comment = get_comment( absint( $args['comment_id'] ?? 0 ) );
		return $comment && current_user_can( 'moderate_comments' ) && current_user_can( 'edit_post', (int) $comment->comment_post_ID );
	}

	/** @return array<string,mixed> */
	public function list_comments( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$query_args = array(
			'status'  => sanitize_key( (string) ( $args['status'] ?? 'all' ) ),
			'search'  => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);
		if ( ! empty( $args['post_id'] ) ) {
			$query_args['post_id'] = absint( $args['post_id'] );
		}
		$query = new \WP_Comment_Query();
		$items = (array) $query->query( $query_args );
		$count_args          = $query_args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$total = (int) ( new \WP_Comment_Query() )->query( $count_args );

		return array(
			'items'       => array_map( array( $this, 'serialize' ), $items ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function approve_comment( array $args ): array|\WP_Error {
		$comment_id = absint( $args['comment_id'] );
		if ( ! get_comment( $comment_id ) ) {
			return new \WP_Error( 'comment_not_found', __( 'Comment not found.', 'mindio-magic-mcp' ) );
		}
		$result = wp_set_comment_status( $comment_id, 'approve', true );
		return $result ? $this->serialize( get_comment( $comment_id ) ) : new \WP_Error( 'comment_update_failed', __( 'The comment could not be approved.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_comment( array $args ): array|\WP_Error {
		$comment_id = absint( $args['comment_id'] );
		$force      = ! empty( $args['force'] );
		if ( $force && empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Permanent comment deletion requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		if ( ! get_comment( $comment_id ) ) {
			return new \WP_Error( 'comment_not_found', __( 'Comment not found.', 'mindio-magic-mcp' ) );
		}
		$result = $force ? wp_delete_comment( $comment_id, true ) : wp_trash_comment( $comment_id );
		return $result ? array( 'comment_id' => $comment_id, 'deleted' => true, 'permanent' => $force ) : new \WP_Error( 'comment_delete_failed', __( 'The comment could not be deleted.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function reply_comment( array $args ): array|\WP_Error {
		$parent = get_comment( absint( $args['comment_id'] ) );
		$user   = wp_get_current_user();
		if ( ! $parent || ! $user->exists() ) {
			return new \WP_Error( 'comment_not_found', __( 'The parent comment or current user is unavailable.', 'mindio-magic-mcp' ) );
		}
		$comment_id = wp_new_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $parent->comment_post_ID,
					'comment_parent'       => (int) $parent->comment_ID,
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_content'      => wp_kses_post( (string) $args['content'] ),
					'comment_approved'     => 1,
				)
			),
			true
		);
		return is_wp_error( $comment_id ) ? $comment_id : $this->serialize( get_comment( $comment_id ) );
	}

	private function id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'comment_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'required'             => array( 'comment_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function serialize( ?\WP_Comment $comment ): array {
		if ( ! $comment ) {
			return array();
		}
		return array(
			'comment_id'    => (int) $comment->comment_ID,
			'post_id'       => (int) $comment->comment_post_ID,
			'parent_id'     => (int) $comment->comment_parent,
			'author'        => $comment->comment_author,
			'author_email'  => $comment->comment_author_email,
			'content'       => $comment->comment_content,
			'status'        => wp_get_comment_status( $comment ),
			'date_gmt'      => mysql2date( DATE_ATOM, $comment->comment_date_gmt, false ),
		);
	}
}
