<?php
/**
 * Provider-specific Yoast SEO Free and Rank Math Free operation families.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SEO_Provider_Tools extends Integration_Dispatcher {
	private string $provider;

	public function __construct( Tool_Registry $registry, string $provider ) {
		$this->provider = 'yoast' === $provider ? 'yoast' : 'rank_math';
		parent::__construct(
			$registry,
			'yoast' === $this->provider ? 'yoast_seo' : 'rank_math',
			'yoast' === $this->provider ? 'Yoast SEO Free' : 'Rank Math SEO Free'
		);
	}

	public function register(): void {
		$post_id = array( 'type' => 'integer', 'minimum' => 1 );
		$term_properties = array(
			'term_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
			'taxonomy' => array( 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,32}$' ),
		);
		$operations = array(
			'get_post_seo' => $this->operation(
				'read',
				__( 'Get post SEO', 'mindio-magic-mcp' ),
				__( 'Read title, description, focus keyword, robots, canonical, social, and schema data for one post.', 'mindio-magic-mcp' ),
				$this->object_schema( array( 'post_id' => $post_id ), array( 'post_id' ) ),
				array( $this, 'get_post_seo' ),
				array( $this, 'can_read_post' )
			),
			'get_term_seo' => $this->operation(
				'read',
				__( 'Get term SEO', 'mindio-magic-mcp' ),
				__( 'Read title, description, focus keyword, robots, canonical, and social data for one taxonomy term.', 'mindio-magic-mcp' ),
				$this->object_schema( $term_properties, array( 'term_id', 'taxonomy' ) ),
				array( $this, 'get_term_seo' ),
				array( $this, 'can_manage_term' )
			),
			'get_settings' => $this->operation(
				'read',
				__( 'Get provider settings', 'mindio-magic-mcp' ),
				__( 'Read a curated set of safe homepage, social, robots, and title settings.', 'mindio-magic-mcp' ),
				$this->empty_schema(),
				array( $this, 'get_settings' ),
				$this->settings_capability(),
				false,
				Auth::SCOPE_ADMIN
			),
			'update_post_seo' => $this->operation(
				'write',
				__( 'Update post SEO', 'mindio-magic-mcp' ),
				__( 'Update an allowlisted set of post SEO and social fields with optional concurrency protection.', 'mindio-magic-mcp' ),
				$this->post_update_schema( $post_id ),
				array( $this, 'update_post_seo' ),
				array( $this, 'can_edit_post' )
			),
			'update_term_seo' => $this->operation(
				'write',
				__( 'Update term SEO', 'mindio-magic-mcp' ),
				__( 'Update an allowlisted set of taxonomy-term SEO and social fields.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $term_properties, $this->seo_field_schema( false ) ), array( 'term_id', 'taxonomy' ) ),
				array( $this, 'update_term_seo' ),
				array( $this, 'can_manage_term' )
			),
			'update_settings' => $this->operation(
				'write',
				__( 'Update provider settings', 'mindio-magic-mcp' ),
				__( 'Update only curated homepage, social, robots, and title settings. Requires confirmation.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'values'  => array( 'type' => 'object' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					array( 'values', 'confirm' )
				),
				array( $this, 'update_settings' ),
				$this->settings_capability(),
				false,
				Auth::SCOPE_ADMIN
			),
		);
		$this->register_operations( $operations, Auth::SCOPE_READ, Auth::SCOPE_EDITOR );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_post_seo( array $args ): array|\WP_Error {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$data = array();
		foreach ( $this->post_map() as $logical => $meta_key ) {
			$data[ $logical ] = get_post_meta( $post->ID, $meta_key, true );
		}
		$data['robots'] = $this->read_robots( 'post', $post->ID, $data );
		unset( $data['_robots_index'], $data['_robots_follow'] );
		if ( 'rank_math' === $this->provider ) {
			$data['schemas'] = $this->rank_math_schemas( $post->ID );
		}
		return array(
			'post_id'      => $post->ID,
			'post_type'    => $post->post_type,
			'modified_gmt' => get_post_modified_time( 'c', true, $post ),
			'seo'          => $this->safe_value( $data ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_term_seo( array $args ): array|\WP_Error {
		$term = get_term( (int) $args['term_id'], sanitize_key( (string) $args['taxonomy'] ) );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'term_not_found', __( 'Taxonomy term not found.', 'mindio-magic-mcp' ) );
		}
		$data = 'yoast' === $this->provider ? $this->yoast_term_data( $term ) : $this->rank_math_term_data( $term );
		return array( 'term_id' => $term->term_id, 'taxonomy' => $term->taxonomy, 'seo' => $this->safe_value( $data ) );
	}

	/** @return array<string,mixed> */
	public function get_settings( array $args ): array {
		unset( $args );
		$values = array();
		foreach ( $this->settings_specs() as $logical => $spec ) {
			$values[ $logical ] = $this->read_setting( $spec );
		}
		return array( 'provider' => $this->provider, 'settings' => $values );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_post_seo( array $args ): array|\WP_Error {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		if ( ! empty( $args['expected_modified_gmt'] ) && (string) $args['expected_modified_gmt'] !== get_post_modified_time( 'c', true, $post ) ) {
			return new \WP_Error( 'stale_post', __( 'The post changed since it was read. Fetch it again before updating SEO data.', 'mindio-magic-mcp' ) );
		}
		$clean = $this->sanitize_seo_input( $args );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$prepared_schemas = null;
		if ( array_key_exists( 'schemas', $args ) ) {
			if ( 'rank_math' !== $this->provider ) {
				return new \WP_Error( 'unsupported_schema_write', __( 'Yoast SEO Free generates its graph; use schema_page_type and schema_article_type instead of arbitrary schemas.', 'mindio-magic-mcp' ) );
			}
			if ( empty( $args['replace_schemas'] ) || empty( $args['confirm'] ) ) {
				return new \WP_Error( 'schema_confirmation_required', __( 'Replacing Rank Math schemas requires replace_schemas=true and confirm=true.', 'mindio-magic-mcp' ) );
			}
			$prepared_schemas = $this->prepare_rank_math_schemas( (array) $args['schemas'] );
			if ( is_wp_error( $prepared_schemas ) ) {
				return $prepared_schemas;
			}
		}
		$map = $this->post_map();
		foreach ( $clean as $logical => $value ) {
			if ( isset( $map[ $logical ] ) ) {
				update_post_meta( $post->ID, $map[ $logical ], $value );
			}
		}
		if ( isset( $clean['robots'] ) ) {
			$this->write_robots( 'post', $post->ID, $clean['robots'] );
		}
		if ( is_array( $prepared_schemas ) ) {
			$this->replace_rank_math_schemas( $post->ID, $prepared_schemas );
		}
		do_action( 'flatsome_mcp_provider_seo_updated', $this->provider, 'post', $post->ID, $clean );
		return $this->get_post_seo( array( 'post_id' => $post->ID ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_term_seo( array $args ): array|\WP_Error {
		$term = get_term( (int) $args['term_id'], sanitize_key( (string) $args['taxonomy'] ) );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_Error( 'term_not_found', __( 'Taxonomy term not found.', 'mindio-magic-mcp' ) );
		}
		$clean = $this->sanitize_seo_input( $args );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( 'yoast' === $this->provider ) {
			$updates = array();
			foreach ( $clean as $logical => $value ) {
				if ( isset( $this->yoast_term_map()[ $logical ] ) ) {
					$updates[ $this->yoast_term_map()[ $logical ] ] = $value;
				}
			}
			if ( isset( $clean['robots'] ) ) {
				$updates['wpseo_noindex'] = in_array( 'noindex', $clean['robots'], true ) ? 'noindex' : 'index';
			}
			\WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $updates );
		} else {
			foreach ( $clean as $logical => $value ) {
				if ( isset( $this->rank_math_map()[ $logical ] ) ) {
					update_term_meta( $term->term_id, $this->rank_math_map()[ $logical ], $value );
				}
			}
			if ( isset( $clean['robots'] ) ) {
				update_term_meta( $term->term_id, 'rank_math_robots', $clean['robots'] );
			}
		}
		do_action( 'flatsome_mcp_provider_seo_updated', $this->provider, 'term', $term->term_id, $clean );
		return $this->get_term_seo( array( 'term_id' => $term->term_id, 'taxonomy' => $term->taxonomy ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_settings( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Sitewide SEO setting updates require confirm=true.', 'mindio-magic-mcp' ) );
		}
		$specs   = $this->settings_specs();
		$values  = (array) $args['values'];
		$changed = array();
		foreach ( $values as $logical => $value ) {
			$logical = sanitize_key( (string) $logical );
			if ( ! isset( $specs[ $logical ] ) ) {
				return new \WP_Error(
					'seo_setting_not_allowed',
					sprintf(
						/* translators: %s: SEO setting key. */
						__( 'SEO setting "%s" is not in the curated allowlist.', 'mindio-magic-mcp' ),
						$logical
					)
				);
			}
			$clean = $this->sanitize_setting( $logical, $value, $specs[ $logical ] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$this->write_setting( $specs[ $logical ], $clean );
			$changed[] = $logical;
		}
		do_action( 'flatsome_mcp_provider_seo_settings_updated', $this->provider, $changed );
		$result            = $this->get_settings( array() );
		$result['updated'] = $changed;
		return $result;
	}

	public function can_read_post( array $args ): bool {
		return current_user_can( 'read_post', (int) ( $args['post_id'] ?? 0 ) );
	}

	public function can_edit_post( array $args ): bool {
		return current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) );
	}

	public function can_manage_term( array $args ): bool {
		$taxonomy = get_taxonomy( sanitize_key( (string) ( $args['taxonomy'] ?? '' ) ) );
		return $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
	}

	protected function dependency_installed(): bool {
		if ( $this->dependency_available() ) {
			return true;
		}
		return 'yoast' === $this->provider
			? $this->plugin_is_installed( array( 'wordpress-seo/wp-seo.php' ), array( 'wordpress-seo' ) )
			: $this->plugin_is_installed( array( 'seo-by-rank-math/rank-math.php' ), array( 'rank-math' ) );
	}

	protected function dependency_available(): bool {
		return 'yoast' === $this->provider
			? ( defined( 'WPSEO_VERSION' ) && class_exists( '\\WPSEO_Options' ) && class_exists( '\\WPSEO_Taxonomy_Meta' ) )
			: ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' ) );
	}

	protected function dependency_label(): string {
		return 'yoast' === $this->provider ? 'Yoast SEO Free' : 'Rank Math SEO Free';
	}

	/** @return array<string,mixed> */
	private function operation( string $mode, string $label, string $description, array $schema, callable $callback, string|callable $capability, bool $destructive = false, ?string $scope = null ): array {
		$data = compact( 'mode', 'label', 'description', 'schema', 'callback', 'capability', 'destructive' );
		if ( $scope ) {
			$data['scope'] = $scope;
		}
		return $data;
	}

	private function settings_capability(): string {
		return 'yoast' === $this->provider ? 'wpseo_manage_options' : 'rank_math_titles';
	}

	/** @return array<string,string> */
	private function post_map(): array {
		if ( 'rank_math' === $this->provider ) {
			return $this->rank_math_map();
		}
		return array(
			'title'               => '_yoast_wpseo_title',
			'description'         => '_yoast_wpseo_metadesc',
			'focus_keyword'       => '_yoast_wpseo_focuskw',
			'canonical_url'       => '_yoast_wpseo_canonical',
			'og_title'            => '_yoast_wpseo_opengraph-title',
			'og_description'      => '_yoast_wpseo_opengraph-description',
			'og_image_url'        => '_yoast_wpseo_opengraph-image',
			'twitter_title'       => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
			'twitter_image_url'   => '_yoast_wpseo_twitter-image',
			'schema_page_type'    => '_yoast_wpseo_schema_page_type',
			'schema_article_type' => '_yoast_wpseo_schema_article_type',
			'_robots_index'       => '_yoast_wpseo_meta-robots-noindex',
			'_robots_follow'      => '_yoast_wpseo_meta-robots-nofollow',
		);
	}

	/** @return array<string,string> */
	private function rank_math_map(): array {
		return array(
			'title'               => 'rank_math_title',
			'description'         => 'rank_math_description',
			'focus_keyword'       => 'rank_math_focus_keyword',
			'canonical_url'       => 'rank_math_canonical_url',
			'og_title'            => 'rank_math_facebook_title',
			'og_description'      => 'rank_math_facebook_description',
			'og_image_url'        => 'rank_math_facebook_image',
			'twitter_title'       => 'rank_math_twitter_title',
			'twitter_description' => 'rank_math_twitter_description',
			'twitter_image_url'   => 'rank_math_twitter_image',
		);
	}

	/** @return array<string,string> */
	private function yoast_term_map(): array {
		return array(
			'title'               => 'wpseo_title',
			'description'         => 'wpseo_desc',
			'focus_keyword'       => 'wpseo_focuskw',
			'canonical_url'       => 'wpseo_canonical',
			'og_title'            => 'wpseo_opengraph-title',
			'og_description'      => 'wpseo_opengraph-description',
			'og_image_url'        => 'wpseo_opengraph-image',
			'twitter_title'       => 'wpseo_twitter-title',
			'twitter_description' => 'wpseo_twitter-description',
			'twitter_image_url'   => 'wpseo_twitter-image',
		);
	}

	/** @return array<string,mixed> */
	private function yoast_term_data( \WP_Term $term ): array {
		$raw  = (array) \WPSEO_Taxonomy_Meta::get_term_meta( $term, $term->taxonomy );
		$data = array();
		foreach ( $this->yoast_term_map() as $logical => $key ) {
			$data[ $logical ] = $raw[ $key ] ?? '';
		}
		$data['robots'] = array( 'noindex' === ( $raw['wpseo_noindex'] ?? 'default' ) ? 'noindex' : 'index', 'follow' );
		return $data;
	}

	/** @return array<string,mixed> */
	private function rank_math_term_data( \WP_Term $term ): array {
		$data = array();
		foreach ( $this->rank_math_map() as $logical => $key ) {
			$data[ $logical ] = get_term_meta( $term->term_id, $key, true );
		}
		$data['robots'] = (array) get_term_meta( $term->term_id, 'rank_math_robots', true );
		return $data;
	}

	/** @param array<string,mixed> $data
	 *  @return array<int,string>
	 */
	private function read_robots( string $type, int $id, array $data ): array {
		unset( $type );
		if ( 'yoast' === $this->provider ) {
			return array( '1' === (string) ( $data['_robots_index'] ?? '' ) ? 'noindex' : 'index', '1' === (string) ( $data['_robots_follow'] ?? '' ) ? 'nofollow' : 'follow' );
		}
		$robots = (array) get_post_meta( $id, 'rank_math_robots', true );
		return $robots ?: array( 'index', 'follow' );
	}

	/** @param array<int,string> $robots */
	private function write_robots( string $type, int $id, array $robots ): void {
		unset( $type );
		if ( 'yoast' === $this->provider ) {
			update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', in_array( 'noindex', $robots, true ) ? '1' : '2' );
			update_post_meta( $id, '_yoast_wpseo_meta-robots-nofollow', in_array( 'nofollow', $robots, true ) ? '1' : '0' );
			return;
		}
		update_post_meta( $id, 'rank_math_robots', $robots );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_seo_input( array $args ): array|\WP_Error {
		$output = array();
		foreach ( array( 'title', 'description', 'focus_keyword', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'schema_page_type', 'schema_article_type' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$output[ $key ] = sanitize_text_field( (string) $args[ $key ] );
			}
		}
		foreach ( array( 'canonical_url', 'og_image_url', 'twitter_image_url' ) as $key ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}
			if ( '' === $args[ $key ] ) {
				$output[ $key ] = '';
				continue;
			}
			$url = esc_url_raw( (string) $args[ $key ], array( 'http', 'https' ) );
			if ( ! $url || $url !== $args[ $key ] ) {
					return new \WP_Error(
						'invalid_url',
						sprintf(
							/* translators: %s: SEO URL field name. */
							__( '%s must be a valid HTTP(S) URL.', 'mindio-magic-mcp' ),
							$key
						)
					);
			}
			$output[ $key ] = $url;
		}
		if ( isset( $args['robots'] ) ) {
			$robots = array_values( array_unique( array_map( 'sanitize_key', (array) $args['robots'] ) ) );
			if ( array_diff( $robots, array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) ) || ( in_array( 'index', $robots, true ) && in_array( 'noindex', $robots, true ) ) || ( in_array( 'follow', $robots, true ) && in_array( 'nofollow', $robots, true ) ) ) {
				return new \WP_Error( 'invalid_robots', __( 'Robots directives contain unsupported or contradictory values.', 'mindio-magic-mcp' ) );
			}
			$output['robots'] = $robots;
		}
		return $output;
	}

	/** @return array<string,array<string,mixed>> */
	private function settings_specs(): array {
		if ( 'yoast' === $this->provider ) {
			return array(
				'homepage_title'       => array( 'key' => 'title-home-wpseo', 'type' => 'text' ),
				'homepage_description' => array( 'key' => 'metadesc-home-wpseo', 'type' => 'text' ),
				'title_separator'      => array( 'key' => 'separator', 'type' => 'enum', 'choices' => array( 'sc-dash', 'sc-ndash', 'sc-mdash', 'sc-middot', 'sc-bull', 'sc-star', 'sc-smstar', 'sc-pipe', 'sc-tilde', 'sc-laquo', 'sc-raquo', 'sc-lt', 'sc-gt' ) ),
				'facebook_url'         => array( 'key' => 'facebook_site', 'type' => 'url' ),
				'default_social_image' => array( 'key' => 'og_default_image', 'type' => 'url' ),
				'twitter_username'     => array( 'key' => 'twitter_site', 'type' => 'handle' ),
				'open_graph_enabled'   => array( 'key' => 'opengraph', 'type' => 'boolean' ),
				'twitter_cards_enabled'=> array( 'key' => 'twitter', 'type' => 'boolean' ),
			);
		}
		return array(
			'homepage_title'       => array( 'key' => 'homepage_title', 'type' => 'text' ),
			'homepage_description' => array( 'key' => 'homepage_description', 'type' => 'text' ),
			'title_separator'      => array( 'key' => 'title_separator', 'type' => 'enum', 'choices' => array( '-', '&ndash;', '&mdash;', '&raquo;', '|', '&bull;' ) ),
			'facebook_url'         => array( 'key' => 'social_url_facebook', 'type' => 'url' ),
			'default_social_image' => array( 'key' => 'open_graph_image', 'type' => 'url' ),
			'twitter_username'     => array( 'key' => 'twitter_author_names', 'type' => 'handle' ),
			'twitter_card_type'    => array( 'key' => 'twitter_card_type', 'type' => 'enum', 'choices' => array( 'summary_large_image', 'summary_card' ) ),
			'global_robots'        => array( 'key' => 'robots_global', 'type' => 'robots' ),
		);
	}

	private function read_setting( array $spec ): mixed {
		if ( 'yoast' === $this->provider ) {
			return \WPSEO_Options::get( $spec['key'] );
		}
		$options = (array) get_option( 'rank-math-options-titles', array() );
		return $options[ $spec['key'] ] ?? null;
	}

	private function write_setting( array $spec, mixed $value ): void {
		if ( 'yoast' === $this->provider ) {
			\WPSEO_Options::set( $spec['key'], $value );
			return;
		}
		$options                 = (array) get_option( 'rank-math-options-titles', array() );
		$options[ $spec['key'] ] = $value;
		update_option( 'rank-math-options-titles', $options, false );
	}

	/** @return mixed|\WP_Error */
	private function sanitize_setting( string $key, mixed $value, array $spec ): mixed {
		$type = $spec['type'];
		if ( 'boolean' === $type ) {
			return is_bool( $value ) ? $value : $this->invalid_setting( $key );
		}
		if ( 'text' === $type ) {
			return is_string( $value ) && strlen( $value ) <= 2000 ? sanitize_text_field( $value ) : $this->invalid_setting( $key );
		}
		if ( 'enum' === $type ) {
			return is_string( $value ) && in_array( $value, $spec['choices'], true ) ? $value : $this->invalid_setting( $key );
		}
		if ( 'url' === $type ) {
			if ( '' === $value ) {
				return '';
			}
			$url = is_string( $value ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
			return $url && $url === $value ? $url : $this->invalid_setting( $key );
		}
		if ( 'handle' === $type ) {
			return is_string( $value ) && preg_match( '/^@?[A-Za-z0-9_]{0,50}$/', $value ) ? ltrim( $value, '@' ) : $this->invalid_setting( $key );
		}
		if ( 'robots' === $type ) {
			$robots = is_array( $value ) ? array_values( array_unique( array_map( 'sanitize_key', $value ) ) ) : array();
			return ! array_diff( $robots, array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) ) ? $robots : $this->invalid_setting( $key );
		}
		return $this->invalid_setting( $key );
	}

	private function invalid_setting( string $key ): \WP_Error {
		return new \WP_Error(
			'invalid_seo_setting',
			sprintf(
				/* translators: %s: SEO setting key. */
				__( 'SEO setting "%s" has an invalid value or type.', 'mindio-magic-mcp' ),
				$key
			)
		);
	}

	/** @return array<string,mixed> */
	private function post_update_schema( array $post_id ): array {
		return $this->object_schema(
			array_merge(
				array(
					'post_id'               => $post_id,
					'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time', 'maxLength' => 30 ),
				),
				$this->seo_field_schema( true ),
				array(
					'schemas'         => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'object' ) ),
					'replace_schemas' => array( 'type' => 'boolean' ),
					'confirm'         => array( 'type' => 'boolean' ),
				)
			),
			array( 'post_id' )
		);
	}

	/** @return array<string,mixed> */
	private function seo_field_schema( bool $include_schema_types ): array {
		$fields = array(
			'title'               => array( 'type' => 'string', 'maxLength' => 1000 ),
			'description'         => array( 'type' => 'string', 'maxLength' => 2000 ),
			'focus_keyword'       => array( 'type' => 'string', 'maxLength' => 500 ),
			'canonical_url'       => array( 'type' => 'string', 'maxLength' => 2048 ),
			'robots'             => array( 'type' => 'array', 'maxItems' => 7, 'uniqueItems' => true, 'items' => array( 'type' => 'string' ) ),
			'og_title'            => array( 'type' => 'string', 'maxLength' => 1000 ),
			'og_description'      => array( 'type' => 'string', 'maxLength' => 2000 ),
			'og_image_url'        => array( 'type' => 'string', 'maxLength' => 2048 ),
			'twitter_title'       => array( 'type' => 'string', 'maxLength' => 1000 ),
			'twitter_description' => array( 'type' => 'string', 'maxLength' => 2000 ),
			'twitter_image_url'   => array( 'type' => 'string', 'maxLength' => 2048 ),
		);
		if ( $include_schema_types ) {
			$fields['schema_page_type']    = array( 'type' => 'string', 'maxLength' => 100 );
			$fields['schema_article_type'] = array( 'type' => 'string', 'maxLength' => 100 );
		}
		return $fields;
	}

	/** @return array<int,mixed> */
	private function rank_math_schemas( int $post_id ): array {
		$schemas = array();
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( ! str_starts_with( $key, 'rank_math_schema_' ) ) {
				continue;
			}
			$value = maybe_unserialize( $values[0] ?? null );
			if ( is_array( $value ) ) {
				$schemas[] = $this->safe_value( $value );
			}
			if ( count( $schemas ) >= 20 ) {
				break;
			}
		}
		return $schemas;
	}

	/** @return array<string,array<string,mixed>>|\WP_Error */
	private function prepare_rank_math_schemas( array $schemas ): array|\WP_Error {
		$encoded = wp_json_encode( $schemas );
		if ( false === $encoded || strlen( $encoded ) > 100000 ) {
			return new \WP_Error( 'invalid_schema', __( 'Schema JSON is invalid or exceeds 100 KB.', 'mindio-magic-mcp' ) );
		}
		$prepared = array();
		foreach ( $schemas as $schema ) {
			$schema = (array) $schema;
			$type   = (string) ( $schema['@type'] ?? '' );
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,49}$/', $type ) ) {
				return new \WP_Error( 'invalid_schema_type', __( 'Every Rank Math schema must contain a safe @type value.', 'mindio-magic-mcp' ) );
			}
			$key = 'rank_math_schema_' . $type;
			if ( isset( $prepared[ $key ] ) ) {
				return new \WP_Error( 'duplicate_schema_type', __( 'Rank Math schema types must be unique per update.', 'mindio-magic-mcp' ) );
			}
			$prepared[ $key ] = $schema;
		}
		return $prepared;
	}

	/** @param array<string,array<string,mixed>> $prepared */
	private function replace_rank_math_schemas( int $post_id, array $prepared ): void {
		foreach ( array_keys( get_post_meta( $post_id ) ) as $key ) {
			if ( str_starts_with( $key, 'rank_math_schema_' ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
		foreach ( $prepared as $key => $schema ) {
			update_post_meta( $post_id, $key, $schema );
		}
	}

	private function safe_value( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 10 ) {
			return '[truncated]';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return '[unsupported]';
		}
		$output = array();
		foreach ( array_slice( $value, 0, 500, true ) as $key => $child ) {
			$output[ $key ] = $this->safe_value( $child, $depth + 1 );
		}
		return $output;
	}
}
