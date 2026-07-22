<?php
/**
 * Unified SEO metadata tools with Yoast and Rank Math adapters.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SEO_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 20, 2 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );
		add_action( 'wp_head', array( $this, 'render_frontend_meta' ), 2 );

		$this->registry->register(
			'get_meta',
			__( 'Read normalized SEO, Open Graph, canonical, robots, and schema metadata. Detects Yoast SEO and Rank Math automatically.', 'mindio-magic-mcp' ),
			$this->id_schema(),
			array( 'type' => 'object' ),
			array( $this, 'get_meta' ),
			Auth::SCOPE_READ,
			fn( array $args ): bool => current_user_can( 'read_post', absint( $args['post_id'] ?? 0 ) ),
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'update_meta',
			__( 'Update normalized SEO metadata. Values are mirrored to active Yoast SEO or Rank Math fields and retained in a plugin-neutral fallback.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'meta_title'  => array( 'type' => 'string', 'maxLength' => 1000 ),
					'meta_description' => array( 'type' => 'string', 'maxLength' => 2000 ),
					'canonical_url' => array( 'type' => 'string', 'maxLength' => 2048 ),
					'robots'      => array(
						'type'       => 'object',
						'properties' => array(
							'index'  => array( 'type' => 'boolean' ),
							'follow' => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
					),
					'og_title'       => array( 'type' => 'string', 'maxLength' => 1000 ),
					'og_description' => array( 'type' => 'string', 'maxLength' => 2000 ),
					'og_image_url'   => array( 'type' => 'string', 'maxLength' => 2048 ),
					'schema'         => array( 'type' => array( 'object', 'array' ) ),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'update_meta' ),
			Auth::SCOPE_EDITOR,
			fn( array $args ): bool => current_user_can( 'edit_post', absint( $args['post_id'] ?? 0 ) ),
			array( 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_meta( array $args ): array|\WP_Error {
		$post_id = absint( $args['post_id'] );
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}

		$provider = $this->provider();
		$meta     = array(
			'meta_title'       => (string) get_post_meta( $post_id, '_mindio_magic_mcp_meta_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, '_mindio_magic_mcp_meta_description', true ),
			'canonical_url'    => (string) get_post_meta( $post_id, '_mindio_magic_mcp_canonical', true ),
			'robots'          => get_post_meta( $post_id, '_mindio_magic_mcp_robots', true ) ?: array( 'index' => true, 'follow' => true ),
			'og_title'         => (string) get_post_meta( $post_id, '_mindio_magic_mcp_og_title', true ),
			'og_description'   => (string) get_post_meta( $post_id, '_mindio_magic_mcp_og_description', true ),
			'og_image_url'     => (string) get_post_meta( $post_id, '_mindio_magic_mcp_og_image', true ),
			'schema'           => get_post_meta( $post_id, '_mindio_magic_mcp_schema', true ) ?: array(),
		);

		if ( 'yoast' === $provider ) {
			$meta['meta_title']       = $this->prefer( get_post_meta( $post_id, '_yoast_wpseo_title', true ), $meta['meta_title'] );
			$meta['meta_description'] = $this->prefer( get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ), $meta['meta_description'] );
			$meta['canonical_url']    = $this->prefer( get_post_meta( $post_id, '_yoast_wpseo_canonical', true ), $meta['canonical_url'] );
			$meta['og_title']         = $this->prefer( get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ), $meta['og_title'] );
			$meta['og_description']   = $this->prefer( get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ), $meta['og_description'] );
			$meta['og_image_url']     = $this->prefer( get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ), $meta['og_image_url'] );
			$meta['robots']           = array(
				'index'  => '1' !== (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
				'follow' => '1' !== (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ),
			);
		} elseif ( 'rank_math' === $provider ) {
			$meta['meta_title']       = $this->prefer( get_post_meta( $post_id, 'rank_math_title', true ), $meta['meta_title'] );
			$meta['meta_description'] = $this->prefer( get_post_meta( $post_id, 'rank_math_description', true ), $meta['meta_description'] );
			$meta['canonical_url']    = $this->prefer( get_post_meta( $post_id, 'rank_math_canonical_url', true ), $meta['canonical_url'] );
			$meta['og_title']         = $this->prefer( get_post_meta( $post_id, 'rank_math_facebook_title', true ), $meta['og_title'] );
			$meta['og_description']   = $this->prefer( get_post_meta( $post_id, 'rank_math_facebook_description', true ), $meta['og_description'] );
			$meta['og_image_url']     = $this->prefer( get_post_meta( $post_id, 'rank_math_facebook_image', true ), $meta['og_image_url'] );
			$robots                   = (array) get_post_meta( $post_id, 'rank_math_robots', true );
			$meta['robots']           = array( 'index' => ! in_array( 'noindex', $robots, true ), 'follow' => ! in_array( 'nofollow', $robots, true ) );
		}

		return array( 'post_id' => $post_id, 'provider' => $provider, 'meta' => $meta );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_meta( array $args ): array|\WP_Error {
		$post_id = absint( $args['post_id'] );
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		foreach ( array( 'canonical_url', 'og_image_url' ) as $input ) {
			if ( array_key_exists( $input, $args ) ) {
				$value = '' === $args[ $input ] ? '' : esc_url_raw( (string) $args[ $input ], array( 'http', 'https' ) );
				if ( '' !== $args[ $input ] && '' === $value ) {
					return new \WP_Error(
						'invalid_url',
						sprintf(
							/* translators: %s: SEO URL field name. */
							__( '%s must be a valid HTTP(S) URL.', 'mindio-magic-mcp' ),
							$input
						)
					);
				}
				$args[ $input ] = $value;
			}
		}
		if ( isset( $args['schema'] ) ) {
			$encoded = wp_json_encode( $args['schema'] );
			if ( false === $encoded || strlen( $encoded ) > 100000 ) {
				return new \WP_Error( 'invalid_schema', __( 'Schema JSON is invalid or exceeds 100 KB.', 'mindio-magic-mcp' ) );
			}
		}
		if ( isset( $args['robots'] ) ) {
			$args['robots'] = array(
				'index'  => (bool) ( $args['robots']['index'] ?? true ),
				'follow' => (bool) ( $args['robots']['follow'] ?? true ),
			);
		}
		$provider = $this->provider();
		$strings  = array(
			'meta_title'       => '_mindio_magic_mcp_meta_title',
			'meta_description' => '_mindio_magic_mcp_meta_description',
			'og_title'         => '_mindio_magic_mcp_og_title',
			'og_description'   => '_mindio_magic_mcp_og_description',
		);
		foreach ( $strings as $input => $key ) {
			if ( array_key_exists( $input, $args ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( (string) $args[ $input ] ) );
			}
		}
		foreach ( array( 'canonical_url' => '_mindio_magic_mcp_canonical', 'og_image_url' => '_mindio_magic_mcp_og_image' ) as $input => $key ) {
			if ( array_key_exists( $input, $args ) ) {
				update_post_meta( $post_id, $key, $args[ $input ] );
			}
		}
		if ( isset( $args['robots'] ) ) {
			update_post_meta( $post_id, '_mindio_magic_mcp_robots', $args['robots'] );
		}
		if ( isset( $args['schema'] ) ) {
			update_post_meta( $post_id, '_mindio_magic_mcp_schema', $args['schema'] );
		}

		if ( 'yoast' === $provider ) {
			$this->mirror_fields(
				$post_id,
				$args,
				array(
					'meta_title'       => '_yoast_wpseo_title',
					'meta_description' => '_yoast_wpseo_metadesc',
					'canonical_url'    => '_yoast_wpseo_canonical',
					'og_title'         => '_yoast_wpseo_opengraph-title',
					'og_description'   => '_yoast_wpseo_opengraph-description',
					'og_image_url'     => '_yoast_wpseo_opengraph-image',
				)
			);
			if ( isset( $args['robots'] ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $args['robots']['index'] ? '2' : '1' );
				update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $args['robots']['follow'] ? '0' : '1' );
			}
		} elseif ( 'rank_math' === $provider ) {
			$this->mirror_fields(
				$post_id,
				$args,
				array(
					'meta_title'       => 'rank_math_title',
					'meta_description' => 'rank_math_description',
					'canonical_url'    => 'rank_math_canonical_url',
					'og_title'         => 'rank_math_facebook_title',
					'og_description'   => 'rank_math_facebook_description',
					'og_image_url'     => 'rank_math_facebook_image',
				)
			);
			if ( isset( $args['robots'] ) ) {
				update_post_meta( $post_id, 'rank_math_robots', array( $args['robots']['index'] ? 'index' : 'noindex', $args['robots']['follow'] ? 'follow' : 'nofollow' ) );
			}
		}

		do_action( 'mindio_magic_mcp_seo_updated', $post_id, $args, $provider );
		return $this->get_meta( array( 'post_id' => $post_id ) );
	}

	public function filter_document_title( string $title ): string {
		if ( 'generic' !== $this->provider() || ! is_singular() ) {
			return $title;
		}
		$custom = (string) get_post_meta( get_queried_object_id(), '_mindio_magic_mcp_meta_title', true );
		return '' !== $custom ? $custom : $title;
	}

	public function filter_canonical_url( mixed $canonical_url, mixed $post ): mixed {
		if ( 'generic' !== $this->provider() ) {
			return $canonical_url;
		}
		$post_id = $post instanceof \WP_Post ? $post->ID : absint( $post );
		$custom  = (string) get_post_meta( $post_id, '_mindio_magic_mcp_canonical', true );
		return '' !== $custom ? $custom : $canonical_url;
	}

	/** @param array<string,mixed> $robots */
	public function filter_robots( array $robots ): array {
		if ( 'generic' !== $this->provider() || ! is_singular() ) {
			return $robots;
		}
		$post_id = get_queried_object_id();
		if ( ! metadata_exists( 'post', $post_id, '_mindio_magic_mcp_robots' ) ) {
			return $robots;
		}
		$stored = (array) get_post_meta( $post_id, '_mindio_magic_mcp_robots', true );
		if ( isset( $stored['index'] ) && ! $stored['index'] ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
		}
		if ( isset( $stored['follow'] ) && ! $stored['follow'] ) {
			unset( $robots['follow'] );
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public function render_frontend_meta(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_id  = get_queried_object_id();
		$provider = $this->provider();
		if ( 'generic' === $provider ) {
			$description = (string) get_post_meta( $post_id, '_mindio_magic_mcp_meta_description', true );
			$og_title    = (string) get_post_meta( $post_id, '_mindio_magic_mcp_og_title', true );
			$og_desc     = (string) get_post_meta( $post_id, '_mindio_magic_mcp_og_description', true );
			$og_image    = (string) get_post_meta( $post_id, '_mindio_magic_mcp_og_image', true );
			if ( '' !== $description ) {
				echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
			}
			if ( '' !== $og_title ) {
				echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
			}
			if ( '' !== $og_desc ) {
				echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
			}
			if ( '' !== $og_image ) {
				echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
			}
		}

		$schema = get_post_meta( $post_id, '_mindio_magic_mcp_schema', true );
		if ( ! empty( $schema ) ) {
			$json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( is_string( $json ) ) {
				echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with all HTML-sensitive characters hex encoded.
			}
		}
	}

	private function id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	private function provider(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rank_math';
		}
		return 'generic';
	}

	private function prefer( mixed $preferred, mixed $fallback ): string {
		return '' !== (string) $preferred ? (string) $preferred : (string) $fallback;
	}

	/** @param array<string,string> $map */
	private function mirror_fields( int $post_id, array $args, array $map ): void {
		foreach ( $map as $input => $key ) {
			if ( array_key_exists( $input, $args ) ) {
				update_post_meta( $post_id, $key, (string) $args[ $input ] );
			}
		}
	}
}
