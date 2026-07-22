<?php
/**
 * WordPress Media Library tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Media_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'upload_media',
			__( 'Upload media from an HTTPS URL or base64 payload. Returns the attachment ID and direct URL.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'source_url' => array( 'type' => 'string', 'format' => 'uri', 'maxLength' => 2048 ),
					'data_base64' => array( 'type' => 'string', 'maxLength' => 20000000 ),
					'filename'   => array( 'type' => 'string', 'maxLength' => 255 ),
					'title'      => array( 'type' => 'string', 'maxLength' => 500 ),
					'alt_text'   => array( 'type' => 'string', 'maxLength' => 1000 ),
					'caption'    => array( 'type' => 'string', 'maxLength' => 5000 ),
					'description' => array( 'type' => 'string', 'maxLength' => 20000 ),
					'parent_id'  => array( 'type' => 'integer', 'minimum' => 0 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'upload_media' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_upload' )
		);
		$this->registry->register(
			'list_media',
			__( 'List and search WordPress Media Library attachments with direct URLs and image dimensions.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'search'    => array( 'type' => 'string', 'maxLength' => 200 ),
					'mime_type' => array( 'type' => 'string', 'maxLength' => 100 ),
					'parent_id' => array( 'type' => 'integer', 'minimum' => 0 ),
					'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_media' ),
			Auth::SCOPE_READ,
			'upload_files',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'attach_media',
			__( 'Attach an existing Media Library item to a post or detach it with post_id 0.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'media_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'post_id'  => array( 'type' => 'integer', 'minimum' => 0 ),
				),
				'required'             => array( 'media_id', 'post_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'attach_media' ),
			Auth::SCOPE_EDITOR,
			array( $this, 'can_attach' ),
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'delete_media',
			__( 'Permanently delete a Media Library item. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'media_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'confirm'  => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'media_id', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'delete_media' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'delete_post', absint( $args['media_id'] ?? 0 ) ),
			array( 'destructiveHint' => true )
		);
	}

	public function can_upload( array $args ): bool {
		$parent_id = absint( $args['parent_id'] ?? 0 );
		return current_user_can( 'upload_files' ) && ( 0 === $parent_id || current_user_can( 'edit_post', $parent_id ) );
	}

	public function can_attach( array $args ): bool {
		$media_id = absint( $args['media_id'] ?? 0 );
		$post_id  = absint( $args['post_id'] ?? 0 );
		return current_user_can( 'edit_post', $media_id ) && ( 0 === $post_id || current_user_can( 'edit_post', $post_id ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function upload_media( array $args ): array|\WP_Error {
		$has_url  = ! empty( $args['source_url'] );
		$has_data = ! empty( $args['data_base64'] );
		if ( $has_url === $has_data ) {
			return new \WP_Error( 'invalid_media_source', __( 'Provide exactly one of source_url or data_base64.', 'mindio-magic-mcp' ) );
		}

		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		$max      = max( 1, min( 100, absint( $settings['max_upload_mb'] ?? 10 ) ) ) * MB_IN_BYTES;
		$tmp      = '';
		$filename = sanitize_file_name( (string) ( $args['filename'] ?? '' ) );
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( $has_url ) {
			$url_check = URL_Guard::validate( (string) $args['source_url'], true );
			if ( is_wp_error( $url_check ) ) {
				return $url_check;
			}
			if ( '' === $filename ) {
				$filename = sanitize_file_name( basename( (string) wp_parse_url( (string) $args['source_url'], PHP_URL_PATH ) ) );
			}
			$tmp = wp_tempnam( $filename ?: 'magicmcp-upload' );
			if ( ! $tmp ) {
				return new \WP_Error( 'temporary_file_failed', __( 'The temporary upload file could not be created.', 'mindio-magic-mcp' ) );
			}
			$response = wp_safe_remote_get(
				(string) $args['source_url'],
				array(
					'timeout'             => 20,
					'redirection'         => 3,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => $max + 1,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$this->unlink( $tmp );
				return is_wp_error( $response ) ? $response : new \WP_Error( 'media_download_failed', __( 'The remote server did not return a successful response.', 'mindio-magic-mcp' ) );
			}
		} else {
			$data = (string) $args['data_base64'];
			if ( preg_match( '/^data:([^;,]+);base64,(.*)$/s', $data, $matches ) ) {
				$data = $matches[2];
			}
			$data = preg_replace( '/\s+/', '', $data ) ?? '';
			if ( strlen( $data ) > (int) ceil( $max * 1.38 ) ) {
				return new \WP_Error( 'media_too_large', __( 'The media payload exceeds the configured upload limit.', 'mindio-magic-mcp' ) );
			}
			$decoded = base64_decode( $data, true );
			if ( false === $decoded ) {
				return new \WP_Error( 'invalid_base64', __( 'The base64 media payload is invalid.', 'mindio-magic-mcp' ) );
			}
			if ( '' === $filename ) {
				return new \WP_Error( 'filename_required', __( 'filename is required for base64 uploads.', 'mindio-magic-mcp' ) );
			}
			$tmp = wp_tempnam( $filename );
			if ( ! $tmp ) {
				return new \WP_Error( 'temporary_file_failed', __( 'The temporary upload file could not be created.', 'mindio-magic-mcp' ) );
			}
			if ( false === file_put_contents( $tmp, $decoded, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp_tempnam confines this one-time sideload file.
				$this->unlink( $tmp );
				return new \WP_Error( 'temporary_file_failed', __( 'The temporary upload file could not be written.', 'mindio-magic-mcp' ) );
			}
		}

		if ( ! $tmp || ! file_exists( $tmp ) || filesize( $tmp ) > $max ) {
			$this->unlink( $tmp );
			return new \WP_Error( 'media_too_large', __( 'The media payload exceeds the configured upload limit.', 'mindio-magic-mcp' ) );
		}
		if ( '' === $filename ) {
			$filename = 'upload-' . gmdate( 'Ymd-His' );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$filetype = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $filetype['type'] ) || empty( $filetype['ext'] ) ) {
			$this->unlink( $tmp );
			return new \WP_Error( 'media_type_forbidden', __( 'The file type is not permitted by WordPress.', 'mindio-magic-mcp' ) );
		}
		if ( ! str_ends_with( strtolower( $filename ), '.' . strtolower( (string) $filetype['ext'] ) ) ) {
			$filename .= '.' . $filetype['ext'];
		}

		$file = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
			'type'     => $filetype['type'],
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		$attachment_id = media_handle_sideload( $file, absint( $args['parent_id'] ?? 0 ), sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
		if ( is_wp_error( $attachment_id ) ) {
			$this->unlink( $tmp );
			return $attachment_id;
		}

		$update = array( 'ID' => $attachment_id );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$update['post_excerpt'] = wp_kses_post( (string) $args['caption'] );
		}
		if ( isset( $args['description'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $args['description'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( wp_slash( $update ) );
		}
		if ( isset( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt_text'] ) );
		}

		do_action( 'mindio_magic_mcp_media_uploaded', (int) $attachment_id, $args );
		return $this->serialize_attachment( get_post( $attachment_id ) );
	}

	/** @return array<string,mixed> */
	public function list_media( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$mime = sanitize_mime_type( (string) ( $args['mime_type'] ?? 'image' ) );
		if ( '' !== $mime && 'any' !== ( $args['mime_type'] ?? '' ) ) {
			$query_args['post_mime_type'] = $mime;
		}
		if ( isset( $args['parent_id'] ) ) {
			$query_args['post_parent'] = absint( $args['parent_id'] );
		}

		$query = new \WP_Query( $query_args );
		return array(
			'items'       => array_values( array_filter( array_map( fn( \WP_Post $post ): array => $this->serialize_attachment( $post ), $query->posts ) ) ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function attach_media( array $args ): array|\WP_Error {
		$media_id = absint( $args['media_id'] );
		if ( 'attachment' !== get_post_type( $media_id ) ) {
			return new \WP_Error( 'media_not_found', __( 'Media attachment not found.', 'mindio-magic-mcp' ) );
		}
		$result = wp_update_post( array( 'ID' => $media_id, 'post_parent' => absint( $args['post_id'] ) ), true );
		return is_wp_error( $result ) ? $result : $this->serialize_attachment( get_post( $media_id ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_media( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Media deletion requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$media_id = absint( $args['media_id'] );
		if ( 'attachment' !== get_post_type( $media_id ) || ! wp_delete_attachment( $media_id, true ) ) {
			return new \WP_Error( 'delete_failed', __( 'The media attachment could not be deleted.', 'mindio-magic-mcp' ) );
		}
		return array( 'media_id' => $media_id, 'deleted' => true );
	}

	/** @return array<string,mixed> */
	private function serialize_attachment( ?\WP_Post $post ): array {
		if ( ! $post ) {
			return array();
		}
		$metadata = wp_get_attachment_metadata( $post->ID );
		$url      = wp_get_attachment_url( $post->ID ) ?: '';
		return array(
			'media_id'      => $post->ID,
			'url'           => $url,
			'title'         => $post->post_title,
			'alt_text'      => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'       => $post->post_excerpt,
			'description'   => $post->post_content,
			'mime_type'     => $post->post_mime_type,
			'parent_id'     => (int) $post->post_parent,
			'width'         => (int) ( $metadata['width'] ?? 0 ),
			'height'        => (int) ( $metadata['height'] ?? 0 ),
			'filesize'      => (int) ( $metadata['filesize'] ?? 0 ),
			'thumbnail_url' => wp_get_attachment_image_url( $post->ID, 'thumbnail' ) ?: '',
			'date_gmt'      => get_post_time( DATE_ATOM, true, $post ),
		);
	}

	private function unlink( string $path ): void {
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
