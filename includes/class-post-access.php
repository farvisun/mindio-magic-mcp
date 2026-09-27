<?php
/**
 * Read-access rules for post bodies returned through MCP tools, resources, and prompts.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Post_Access {
	/**
	 * Whether the current user may read a post's body, excerpt, blocks, and meta.
	 *
	 * Core's `read_post` capability deliberately ignores post passwords, post-type
	 * visibility, and revision ownership, so it alone would let any logged-in
	 * reader (a Subscriber) pull content core never shows them. MCP requests carry
	 * no post-password cookie, so a protected post is only readable by users who
	 * can edit it.
	 */
	public static function can_read( int|\WP_Post|null $post ): bool {
		// get_post() falls back to the global post for empty IDs; never do that here.
		if ( null === $post || ( is_int( $post ) && $post <= 0 ) ) {
			return false;
		}
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		// Revisions and autosaves pass `read_post` through their parent and never
		// carry a password themselves; like core's revisions endpoint, only users
		// who can edit the parent may read them.
		if ( 'revision' === $post->post_type ) {
			return $post->post_parent > 0 && current_user_can( 'edit_post', $post->post_parent );
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}

		// `read_post` does not check post-type visibility, so published records of
		// non-public types (form configurations, coupons, submissions) would pass.
		if ( ! is_post_type_viewable( $post->post_type ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( self::is_protected_for_user( $post ) ) {
			return false;
		}

		// Attachments inherit the protection of the entry they are attached to.
		if ( 'attachment' === $post->post_type && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof \WP_Post && self::is_protected_for_user( $parent ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the post has a password the current user cannot bypass by editing it.
	 */
	public static function is_protected_for_user( \WP_Post $post ): bool {
		return '' !== (string) $post->post_password && ! current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Tool capability callback for schemas that take a `post_id` argument.
	 *
	 * @param array<string,mixed> $args
	 */
	public static function can_read_post_arg( array $args ): bool {
		return self::can_read( absint( $args['post_id'] ?? 0 ) );
	}
}
