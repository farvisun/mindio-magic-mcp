<?php
/**
 * Cache, CDN, and image-optimization integration tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Performance_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'purge_cdn',
			__( 'Request a CDN purge through detected WordPress cache integrations and a documented extension hook.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array( 'url' => array( 'type' => 'string', 'maxLength' => 2048 ) ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'purge_cdn' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'openWorldHint' => true )
		);
		$this->registry->register(
			'control_cache',
			__( 'Inspect detected cache layers or purge all/page-specific WordPress caches.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'  => array( 'type' => 'string', 'enum' => array( 'status', 'purge_all', 'purge_post' ) ),
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'required'             => array( 'action' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'control_cache' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'trigger_image_optimization',
			__( 'Regenerate WordPress image metadata and sizes, then trigger optimization-plugin hooks for one image attachment.', 'mindio-magic-mcp' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'media_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'             => array( 'media_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'optimize_image' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'edit_post', absint( $args['media_id'] ?? 0 ) )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function purge_cdn( array $args ): array|\WP_Error {
		$url = '';
		if ( ! empty( $args['url'] ) ) {
			$url = esc_url_raw( (string) $args['url'], array( 'http', 'https' ) );
			$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! $url || ! hash_equals( $home_host, $url_host ) ) {
				return new \WP_Error( 'external_purge_url', __( 'A targeted purge URL must belong to this WordPress site.', 'mindio-magic-mcp' ) );
			}
		}

		$providers = array();
		if ( function_exists( 'w3tc_flush_cdn' ) ) {
			w3tc_flush_cdn();
			$providers[] = 'w3_total_cache_cdn';
		}
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- LiteSpeed Cache owns and documents these integration hooks.
			do_action( $url ? 'litespeed_purge_url' : 'litespeed_purge_all', $url ?: null );
			$providers[] = 'litespeed';
		}
		if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
			\autoptimizeCache::clearall();
			$providers[] = 'autoptimize';
		}
		do_action( 'mindio_magic_mcp_purge_cdn', $url );
		return array(
			'purge_requested' => true,
			'url'             => $url,
			'providers'       => $providers,
			'extension_hook'  => 'mindio_magic_mcp_purge_cdn',
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function control_cache( array $args ): array|\WP_Error {
		$action    = (string) $args['action'];
		$post_id   = absint( $args['post_id'] ?? 0 );
		$providers = $this->cache_providers();
		if ( 'status' === $action ) {
			return array( 'persistent_object_cache' => wp_using_ext_object_cache(), 'providers' => $providers );
		}
		if ( 'purge_post' === $action && ! $post_id ) {
			return new \WP_Error( 'post_id_required', __( 'purge_post requires post_id.', 'mindio-magic-mcp' ) );
		}

		if ( $post_id ) {
			clean_post_cache( $post_id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $post_id );
			}
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $post_id );
			}
			do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache integration hook.
		} else {
			wp_cache_flush();
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache integration hook.
		}
		do_action( 'mindio_magic_mcp_cache_cleared', $post_id );
		return array( 'action' => $action, 'post_id' => $post_id, 'purged' => true, 'providers' => $providers );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function optimize_image( array $args ): array|\WP_Error {
		$media_id = absint( $args['media_id'] );
		if ( ! wp_attachment_is_image( $media_id ) ) {
			return new \WP_Error( 'image_not_found', __( 'The media item is not an image attachment.', 'mindio-magic-mcp' ) );
		}
		$file = get_attached_file( $media_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'image_file_missing', __( 'The original image file is missing.', 'mindio-magic-mcp' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $media_id, $file );
		if ( empty( $metadata ) || is_wp_error( $metadata ) ) {
			return is_wp_error( $metadata ) ? $metadata : new \WP_Error( 'metadata_generation_failed', __( 'WordPress could not regenerate image sizes.', 'mindio-magic-mcp' ) );
		}
		wp_update_attachment_metadata( $media_id, $metadata );
		do_action( 'mindio_magic_mcp_optimize_attachment', $media_id, $file, $metadata );
		return array(
			'media_id'        => $media_id,
			'url'             => wp_get_attachment_url( $media_id ) ?: '',
			'width'           => (int) ( $metadata['width'] ?? 0 ),
			'height'          => (int) ( $metadata['height'] ?? 0 ),
			'generated_sizes' => array_keys( (array) ( $metadata['sizes'] ?? array() ) ),
			'extension_hook'  => 'mindio_magic_mcp_optimize_attachment',
		);
	}

	/** @return string[] */
	private function cache_providers(): array {
		$providers = array( 'wordpress_object_cache' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$providers[] = 'wp_rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$providers[] = 'w3_total_cache';
		}
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			$providers[] = 'litespeed';
		}
		if ( class_exists( 'autoptimizeCache' ) ) {
			$providers[] = 'autoptimize';
		}
		return $providers;
	}
}
