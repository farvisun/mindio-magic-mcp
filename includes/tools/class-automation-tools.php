<?php
/**
 * Agent-oriented automation tools and provider extension points.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Automation_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'generate_post_from_prompt',
			__( 'Generate and create a WordPress post through a configured automation provider. The provider must implement the flatsome_mcp_automation_generate_post filter.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'prompt'       => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 50000 ),
					'title_hint'   => array( 'type' => 'string', 'maxLength' => 500 ),
					'post_type'    => array( 'type' => 'string', 'maxLength' => 32 ),
					'status'       => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish' ) ),
					'language'     => array( 'type' => 'string', 'maxLength' => 20 ),
					'tone'         => array( 'type' => 'string', 'maxLength' => 100 ),
					'max_words'    => array( 'type' => 'integer', 'minimum' => 50, 'maximum' => 10000 ),
					'provider_options' => array( 'type' => 'object' ),
				),
				'required'             => array( 'prompt' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'generate_post_from_prompt' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_generate' ),
			array( 'openWorldHint' => true )
		);

		$this->registry->register(
			'summarize_content',
			__( 'Summarize supplied content or a readable WordPress post. Uses a configured provider when available and a local extractive fallback otherwise.', 'mindio-magic-mcp' ),
			$this->content_input_schema(
				array(
					'target_words' => array( 'type' => 'integer', 'minimum' => 20, 'maximum' => 2000 ),
					'language'     => array( 'type' => 'string', 'maxLength' => 20 ),
				)
			),
			array( 'type' => 'object' ),
			array( $this, 'summarize_content' ),
			Auth::SCOPE_READ,
			array( $this, 'can_read_source' ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => true )
		);

		$this->registry->register(
			'translate_content',
			__( 'Translate supplied content or a readable WordPress post through a configured provider. The provider must implement the flatsome_mcp_automation_translate_content filter.', 'mindio-magic-mcp' ),
			$this->content_input_schema(
				array(
					'target_locale' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 20 ),
					'source_locale' => array( 'type' => 'string', 'maxLength' => 20 ),
					'preserve_html' => array( 'type' => 'boolean' ),
				),
				array( 'target_locale' )
			),
			array( 'type' => 'object' ),
			array( $this, 'translate_content' ),
			Auth::SCOPE_READ,
			array( $this, 'can_read_source' ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => true )
		);

		$this->registry->register(
			'bulk_actions',
			__( 'Apply one status or deletion action to up to 50 posts, checking permissions separately for every post.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'minItems'    => 1,
						'maxItems'    => 50,
						'uniqueItems' => true,
					),
					'action'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'trash', 'delete' ) ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'post_ids', 'action' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'bulk_actions' ),
			Auth::SCOPE_EDITOR,
			'edit_posts',
			array( 'destructiveHint' => true )
		);
	}

	public function can_generate( array $args ): bool {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$type      = get_post_type_object( $post_type );
		if ( ! $type || ! ( $type->public || $type->show_ui ) || ! current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) ) {
			return false;
		}
		return 'publish' !== ( $args['status'] ?? 'draft' ) || current_user_can( $type->cap->publish_posts );
	}

	public function can_read_source( array $args ): bool {
		if ( ! empty( $args['post_id'] ) ) {
			return current_user_can( 'read_post', absint( $args['post_id'] ) );
		}
		return isset( $args['content'] ) && is_string( $args['content'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function generate_post_from_prompt( array $args ): array|\WP_Error {
		$request = array(
			'prompt'           => (string) $args['prompt'],
			'title_hint'       => sanitize_text_field( (string) ( $args['title_hint'] ?? '' ) ),
			'post_type'        => sanitize_key( (string) ( $args['post_type'] ?? 'post' ) ),
			'language'         => sanitize_locale_name( (string) ( $args['language'] ?? get_locale() ) ),
			'tone'             => sanitize_text_field( (string) ( $args['tone'] ?? '' ) ),
			'max_words'        => max( 50, min( 10000, absint( $args['max_words'] ?? 1200 ) ) ),
			'provider_options' => (array) ( $args['provider_options'] ?? array() ),
		);
		$generated = apply_filters( 'flatsome_mcp_automation_generate_post', null, $request, get_current_user_id() );
		if ( null === $generated ) {
			return $this->provider_unavailable( 'flatsome_mcp_automation_generate_post' );
		}
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}
		if ( ! is_array( $generated ) || empty( $generated['title'] ) || ! isset( $generated['content'] ) ) {
			return new \WP_Error( 'invalid_automation_result', __( 'The automation provider returned an invalid generated-post payload.', 'mindio-magic-mcp' ) );
		}
		$title   = (string) $generated['title'];
		$content = (string) $generated['content'];
		$excerpt = (string) ( $generated['excerpt'] ?? '' );
		if ( $this->text_length( $title ) > 500 || $this->text_length( $content ) > 2000000 || $this->text_length( $excerpt ) > 10000 ) {
			return new \WP_Error( 'automation_result_too_large', __( 'The generated post exceeds the allowed title, content, or excerpt size.', 'mindio-magic-mcp' ) );
		}

		$status  = sanitize_key( (string) ( $args['status'] ?? 'draft' ) );
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $request['post_type'],
					'post_status'  => $status,
					'post_title'   => sanitize_text_field( $title ),
					'post_content' => $this->sanitize_content( $content ),
					'post_excerpt' => wp_kses_post( $excerpt ),
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( (int) $post_id, '_flatsome_mcp_automation', wp_json_encode( array( 'provider' => sanitize_text_field( (string) ( $generated['provider'] ?? 'custom' ) ), 'prompt_hash' => hash( 'sha256', $request['prompt'] ) ) ) );
		do_action( 'flatsome_mcp_post_created', (int) $post_id, $args );

		return array(
			'post_id'  => (int) $post_id,
			'status'   => get_post_status( $post_id ) ?: '',
			'url'      => get_permalink( $post_id ) ?: '',
			'edit_url' => get_edit_post_link( $post_id, 'raw' ) ?: '',
			'provider' => sanitize_text_field( (string) ( $generated['provider'] ?? 'custom' ) ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function summarize_content( array $args ): array|\WP_Error {
		$source = $this->source_content( $args );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$request = array(
			'content'      => $source['content'],
			'title'        => $source['title'],
			'post_id'      => $source['post_id'],
			'target_words' => max( 20, min( 2000, absint( $args['target_words'] ?? 120 ) ) ),
			'language'     => sanitize_locale_name( (string) ( $args['language'] ?? get_locale() ) ),
		);
		$result = apply_filters( 'flatsome_mcp_automation_summarize_content', null, $request, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null === $result ) {
			return array(
				'summary'        => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $source['content'] ) ), $request['target_words'], '…' ),
				'source_post_id' => $source['post_id'],
				'provider'       => 'local_extractive',
			);
		}
		$summary = is_array( $result ) ? ( $result['summary'] ?? null ) : $result;
		if ( ! is_string( $summary ) ) {
			return new \WP_Error( 'invalid_automation_result', __( 'The automation provider returned an invalid summary payload.', 'mindio-magic-mcp' ) );
		}
		if ( $this->text_length( $summary ) > 200000 ) {
			return new \WP_Error( 'automation_result_too_large', __( 'The automation summary exceeds the allowed size.', 'mindio-magic-mcp' ) );
		}
		return array(
			'summary'        => sanitize_textarea_field( $summary ),
			'source_post_id' => $source['post_id'],
			'provider'       => is_array( $result ) ? sanitize_text_field( (string) ( $result['provider'] ?? 'custom' ) ) : 'custom',
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function translate_content( array $args ): array|\WP_Error {
		$source = $this->source_content( $args );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$request = array(
			'content'        => $source['content'],
			'title'          => $source['title'],
			'post_id'        => $source['post_id'],
			'target_locale'  => sanitize_locale_name( (string) $args['target_locale'] ),
			'source_locale'  => sanitize_locale_name( (string) ( $args['source_locale'] ?? '' ) ),
			'preserve_html'  => ! array_key_exists( 'preserve_html', $args ) || (bool) $args['preserve_html'],
		);
		$result = apply_filters( 'flatsome_mcp_automation_translate_content', null, $request, get_current_user_id() );
		if ( null === $result ) {
			return $this->provider_unavailable( 'flatsome_mcp_automation_translate_content' );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_string( $result ) ) {
			$result = array( 'content' => $result );
		}
		if ( ! is_array( $result ) || ! isset( $result['content'] ) || ! is_string( $result['content'] ) ) {
			return new \WP_Error( 'invalid_automation_result', __( 'The automation provider returned an invalid translation payload.', 'mindio-magic-mcp' ) );
		}
		if ( $this->text_length( (string) $result['content'] ) > 2000000 || $this->text_length( (string) ( $result['title'] ?? '' ) ) > 500 ) {
			return new \WP_Error( 'automation_result_too_large', __( 'The translated title or content exceeds the allowed size.', 'mindio-magic-mcp' ) );
		}
		return array(
			'title'          => sanitize_text_field( (string) ( $result['title'] ?? $source['title'] ) ),
			'content'        => $request['preserve_html'] ? $this->sanitize_content( $result['content'] ) : sanitize_textarea_field( $result['content'] ),
			'target_locale'  => $request['target_locale'],
			'source_post_id' => $source['post_id'],
			'provider'       => sanitize_text_field( (string) ( $result['provider'] ?? 'custom' ) ),
		);
	}

	/** @return array<string,mixed> */
	public function bulk_actions( array $args ): array {
		$action = sanitize_key( (string) $args['action'] );
		$items  = array();
		foreach ( array_map( 'absint', (array) $args['post_ids'] ) as $post_id ) {
			$result = $this->apply_bulk_action( $post_id, $action, ! empty( $args['confirm'] ) );
			$items[] = is_wp_error( $result )
				? array( 'post_id' => $post_id, 'success' => false, 'error' => $result->get_error_code(), 'message' => $result->get_error_message() )
				: array( 'post_id' => $post_id, 'success' => true, 'status' => $result );
		}
		$successes = count( array_filter( $items, static fn( array $item ): bool => $item['success'] ) );
		return array( 'action' => $action, 'processed' => count( $items ), 'succeeded' => $successes, 'failed' => count( $items ) - $successes, 'items' => $items );
	}

	private function apply_bulk_action( int $post_id, string $action, bool $confirm ): string|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! ( $type->public || $type->show_ui ) || in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'Bulk actions are limited to editable content post types.', 'mindio-magic-mcp' ) );
		}
		if ( in_array( $action, array( 'trash', 'delete' ), true ) ) {
			if ( ! current_user_can( 'delete_post', $post_id ) ) {
				return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot delete this post.', 'mindio-magic-mcp' ) );
			}
			if ( 'delete' === $action && ! $confirm ) {
				return new \WP_Error( 'confirmation_required', __( 'Permanent deletion requires confirm=true.', 'mindio-magic-mcp' ) );
			}
			return wp_delete_post( $post_id, 'delete' === $action ) ? ( 'delete' === $action ? 'deleted' : 'trash' ) : new \WP_Error( 'delete_failed', __( 'The post could not be deleted.', 'mindio-magic-mcp' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot edit this post.', 'mindio-magic-mcp' ) );
		}
		if ( 'publish' === $action ) {
			$type = get_post_type_object( $post->post_type );
			if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
				return new \WP_Error( 'forbidden', __( 'Your WordPress user cannot publish this post.', 'mindio-magic-mcp' ) );
			}
		}
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $action ), true );
		return is_wp_error( $result ) ? $result : $action;
	}

	/** @return array{content:string,title:string,post_id:int}|\WP_Error */
	private function source_content( array $args ): array|\WP_Error {
		$has_post    = ! empty( $args['post_id'] );
		$has_content = array_key_exists( 'content', $args );
		if ( $has_post === $has_content ) {
			return new \WP_Error( 'invalid_source', __( 'Provide exactly one of post_id or content.', 'mindio-magic-mcp' ) );
		}
		if ( $has_post ) {
			$post = get_post( absint( $args['post_id'] ) );
			if ( ! $post ) {
				return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
			}
			if ( $this->text_length( $post->post_content ) > 2000000 ) {
				return new \WP_Error( 'source_too_large', __( 'The source content exceeds the 2,000,000-character automation limit.', 'mindio-magic-mcp' ) );
			}
			return array( 'content' => $post->post_content, 'title' => get_the_title( $post ), 'post_id' => $post->ID );
		}
		return array( 'content' => (string) $args['content'], 'title' => sanitize_text_field( (string) ( $args['title'] ?? '' ) ), 'post_id' => 0 );
	}

	private function content_input_schema( array $extra, array $extra_required = array() ): array {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'content' => array( 'type' => 'string', 'maxLength' => 2000000 ),
					'title'   => array( 'type' => 'string', 'maxLength' => 500 ),
				),
				$extra
			),
			'required'             => $extra_required,
			'additionalProperties' => false,
		);
	}

	private function sanitize_content( string $content ): string {
		return wp_kses_post( $content );
	}

	private function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function provider_unavailable( string $filter ): \WP_Error {
		return new \WP_Error(
			'automation_provider_unavailable',
			sprintf(
				/* translators: %s: WordPress filter name. */
				__( 'No automation provider is configured. Integrate one with the %s filter.', 'mindio-magic-mcp' ),
				$filter
			),
			array( 'filter' => $filter )
		);
	}
}
