<?php
/**
 * Generic WordPress and curated Flatsome theme controls.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Theme_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'get_theme_context',
			__( 'Get the active parent/child theme context and declared WordPress feature support.', 'mindio-magic-mcp' ),
			$this->empty_schema(),
			array( 'type' => 'object' ),
			array( $this, 'get_theme_context' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'get_theme_mods',
			__( 'Read active-theme modifications with sensitive values redacted. Optionally select specific keys.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'keys' => array(
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,100}$' ),
					),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'get_theme_mods' ),
			Auth::SCOPE_ADMIN,
			'edit_theme_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'update_theme_mods',
			__( 'Update only the portable WordPress theme modifications in the documented allowlist. Requires confirm=true.', 'mindio-magic-mcp' ),
			$this->theme_mod_update_schema(),
			array( 'type' => 'object' ),
			array( $this, 'update_theme_mods' ),
			Auth::SCOPE_ADMIN,
			'edit_theme_options',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'create_child_theme',
			__( 'Create a minimal child theme for the active parent theme and optionally activate it. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'slug'        => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,99}$' ),
					'name'        => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 100 ),
					'description' => array( 'type' => 'string', 'maxLength' => 300 ),
					'activate'    => array( 'type' => 'boolean' ),
					'confirm'     => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'slug', 'name', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'create_child_theme' ),
			Auth::SCOPE_ADMIN,
			'install_themes',
			array( 'openWorldHint' => true )
		);
		$this->registry->register(
			'get_flatsome_theme_settings',
			__( 'Read typed, curated Flatsome settings for colors, typography, layout, header, footer, blog, shop, and performance.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'group' => array( 'type' => 'string', 'enum' => array( 'all', 'colors', 'typography', 'layout', 'header', 'footer', 'blog', 'shop', 'performance' ) ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'get_flatsome_theme_settings' ),
			Auth::SCOPE_ADMIN,
			'edit_theme_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'update_flatsome_theme_settings',
			__( 'Update typed settings from the curated Flatsome allowlist. Unknown or structurally unsafe settings are rejected. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'values'              => array( 'type' => 'object' ),
					'reset'               => array(
						'type'     => 'array',
						'maxItems' => 50,
						'items'    => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,100}$' ),
					),
					'expected_stylesheet' => array( 'type' => 'string', 'maxLength' => 100 ),
					'confirm'             => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'update_flatsome_theme_settings' ),
			Auth::SCOPE_ADMIN,
			'edit_theme_options',
			array( 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed> */
	public function get_theme_context( array $args = array() ): array {
		unset( $args );
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$supports = array();
		foreach ( array( 'title-tag', 'post-thumbnails', 'custom-logo', 'custom-background', 'custom-header', 'menus', 'widgets', 'editor-styles', 'align-wide', 'responsive-embeds', 'block-templates', 'woocommerce' ) as $feature ) {
			$supports[ $feature ] = current_theme_supports( $feature );
		}

		return array(
			'stylesheet'       => get_stylesheet(),
			'template'         => get_template(),
			'name'             => (string) $theme->get( 'Name' ),
			'version'          => (string) $theme->get( 'Version' ),
			'is_child'         => $parent instanceof \WP_Theme,
			'parent_name'      => $parent instanceof \WP_Theme ? (string) $parent->get( 'Name' ) : '',
			'parent_version'   => $parent instanceof \WP_Theme ? (string) $parent->get( 'Version' ) : '',
			'is_flatsome'      => 'flatsome' === get_template(),
			'text_domain'      => (string) $theme->get( 'TextDomain' ),
			'theme_supports'   => $supports,
			'customizer_url'   => current_user_can( 'edit_theme_options' ) ? admin_url( 'customize.php' ) : '',
		);
	}

	/** @return array<string,mixed> */
	public function get_theme_mods( array $args ): array {
		$mods     = (array) get_theme_mods();
		$selected = isset( $args['keys'] ) ? array_map( 'sanitize_key', (array) $args['keys'] ) : array_keys( $mods );
		$values   = array();
		$writable = array_keys( $this->generic_specs() );
		foreach ( array_unique( $selected ) as $key ) {
			if ( ! array_key_exists( $key, $mods ) ) {
				continue;
			}
			$values[ $key ] = $this->safe_output_value( $key, $mods[ $key ] );
		}

		return array(
			'stylesheet'    => get_stylesheet(),
			'values'        => $values,
			'writable_keys' => $writable,
			'redacted'      => true,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_theme_mods( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Theme modification updates require confirm=true.', 'mindio-magic-mcp' ) );
		}
		$stale = $this->check_stylesheet( (string) ( $args['expected_stylesheet'] ?? '' ) );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		$specs   = $this->generic_specs();
		$values  = (array) ( $args['values'] ?? array() );
		$resets  = array_map( 'sanitize_key', (array) ( $args['reset'] ?? array() ) );
		$changed = array();
		foreach ( $values as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! isset( $specs[ $key ] ) ) {
				return new \WP_Error(
					'theme_mod_not_allowed',
					sprintf(
						/* translators: %s: WordPress theme modification key. */
						__( 'Theme modification "%s" is not in the portable write allowlist.', 'mindio-magic-mcp' ),
						$key
					)
				);
			}
			$clean = $this->validate_setting( $key, $value, $specs[ $key ] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			if ( get_theme_mod( $key ) !== $clean ) {
				set_theme_mod( $key, $clean );
				$changed[] = $key;
			}
		}
		foreach ( array_unique( $resets ) as $key ) {
			if ( ! isset( $specs[ $key ] ) ) {
				return new \WP_Error(
					'theme_mod_not_allowed',
					sprintf(
						/* translators: %s: WordPress theme modification key. */
						__( 'Theme modification "%s" is not in the portable write allowlist.', 'mindio-magic-mcp' ),
						$key
					)
				);
			}
			remove_theme_mod( $key );
			$changed[] = $key;
		}
		do_action( 'flatsome_mcp_theme_mods_updated', array_unique( $changed ), get_stylesheet() );
		return array( 'stylesheet' => get_stylesheet(), 'updated' => array_values( array_unique( $changed ) ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_child_theme( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Child theme creation requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		if ( ! empty( $args['activate'] ) && ! current_user_can( 'switch_themes' ) ) {
			return new \WP_Error( 'cannot_switch_theme', __( 'Your user cannot activate the new child theme.', 'mindio-magic-mcp' ) );
		}
		$slug   = sanitize_key( (string) $args['slug'] );
		$name   = str_replace( array( '*/', "\r", "\n" ), '', sanitize_text_field( (string) $args['name'] ) );
		$parent = get_template();
		if ( $slug === $parent || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $slug ) ) {
			return new \WP_Error( 'invalid_child_theme_slug', __( 'Choose a valid child-theme slug that differs from the parent theme.', 'mindio-magic-mcp' ) );
		}
		$root = untrailingslashit( get_theme_root( $parent ) );
		$dir  = $root . '/' . $slug;
		if ( file_exists( $dir ) || wp_get_theme( $slug )->exists() ) {
			return new \WP_Error( 'child_theme_exists', __( 'A theme already exists at the requested child-theme slug.', 'mindio-magic-mcp' ) );
		}
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
			return new \WP_Error( 'child_theme_create_failed', __( 'WordPress could not create the child-theme directory.', 'mindio-magic-mcp' ) );
		}
		$description = str_replace( array( '*/', "\r", "\n" ), '', sanitize_text_field( (string) ( $args['description'] ?? '' ) ) );
		$style       = "/*\nTheme Name: {$name}\nDescription: {$description}\nTemplate: {$parent}\nVersion: 1.0.0\nText Domain: {$slug}\n*/\n";
		$functions   = "<?php\n/**\n * Child theme bootstrap.\n */\n";
		if ( 'flatsome' !== $parent ) {
			$handle    = sanitize_key( $slug . '-parent-style' );
			$functions .= "\nadd_action( 'wp_enqueue_scripts', static function (): void {\n\twp_enqueue_style( '{$handle}', get_template_directory_uri() . '/style.css', array(), wp_get_theme( get_template() )->get( 'Version' ) );\n} );\n";
		}
		if ( ! $wp_filesystem->put_contents( $dir . '/style.css', $style, FS_CHMOD_FILE ) || ! $wp_filesystem->put_contents( $dir . '/functions.php', $functions, FS_CHMOD_FILE ) ) {
			$wp_filesystem->delete( $dir, true );
			return new \WP_Error( 'child_theme_write_failed', __( 'WordPress could not write the child-theme files.', 'mindio-magic-mcp' ) );
		}
		wp_clean_themes_cache( true );
		$activated = false;
		if ( ! empty( $args['activate'] ) ) {
			switch_theme( $slug );
			$activated = get_stylesheet() === $slug;
		}
		return array( 'stylesheet' => $slug, 'template' => $parent, 'created' => true, 'activated' => $activated );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_flatsome_theme_settings( array $args ): array|\WP_Error {
		$available = $this->require_flatsome();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$group    = sanitize_key( (string) ( $args['group'] ?? 'all' ) );
		$settings = array();
		foreach ( $this->flatsome_specs() as $key => $spec ) {
			if ( 'all' !== $group && $group !== $spec['group'] ) {
				continue;
			}
			$settings[ $key ] = array(
				'group'   => $spec['group'],
				'label'   => $spec['label'],
				'type'    => $spec['type'],
				'value'   => get_theme_mod( $key, $spec['default'] ?? null ),
				'default' => $spec['default'] ?? null,
				'choices' => $spec['choices'] ?? array(),
			);
		}
		return array( 'stylesheet' => get_stylesheet(), 'template' => get_template(), 'group' => $group, 'settings' => $settings );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_flatsome_theme_settings( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Flatsome setting updates require confirm=true.', 'mindio-magic-mcp' ) );
		}
		$available = $this->require_flatsome();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		$stale = $this->check_stylesheet( (string) ( $args['expected_stylesheet'] ?? '' ) );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		$specs   = $this->flatsome_specs();
		$values  = (array) ( $args['values'] ?? array() );
		$resets  = array_map( 'sanitize_key', (array) ( $args['reset'] ?? array() ) );
		$changed = array();
		foreach ( $values as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! isset( $specs[ $key ] ) ) {
				return new \WP_Error(
					'flatsome_setting_not_allowed',
					sprintf(
						/* translators: %s: Flatsome theme setting key. */
						__( 'Flatsome setting "%s" is not in the curated allowlist.', 'mindio-magic-mcp' ),
						$key
					)
				);
			}
			$clean = $this->validate_setting( $key, $value, $specs[ $key ] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			if ( get_theme_mod( $key, $specs[ $key ]['default'] ?? null ) !== $clean ) {
				set_theme_mod( $key, $clean );
				$changed[] = $key;
			}
		}
		foreach ( array_unique( $resets ) as $key ) {
			if ( ! isset( $specs[ $key ] ) ) {
				return new \WP_Error(
					'flatsome_setting_not_allowed',
					sprintf(
						/* translators: %s: Flatsome theme setting key. */
						__( 'Flatsome setting "%s" is not in the curated allowlist.', 'mindio-magic-mcp' ),
						$key
					)
				);
			}
			remove_theme_mod( $key );
			$changed[] = $key;
		}
		do_action( 'flatsome_mcp_flatsome_settings_updated', array_unique( $changed ), get_stylesheet() );
		return array( 'stylesheet' => get_stylesheet(), 'updated' => array_values( array_unique( $changed ) ) );
	}

	/** @return array<string,array<string,mixed>> */
	private function generic_specs(): array {
		return array(
			'custom_logo'           => array( 'type' => 'image_id' ),
			'header_textcolor'      => array( 'type' => 'hex_no_hash', 'allow_blank' => true ),
			'background_color'      => array( 'type' => 'hex_no_hash', 'allow_blank' => true ),
			'background_image'      => array( 'type' => 'url', 'allow_blank' => true ),
			'background_position_x' => array( 'type' => 'enum', 'choices' => array( 'left', 'center', 'right' ) ),
			'background_position_y' => array( 'type' => 'enum', 'choices' => array( 'top', 'center', 'bottom' ) ),
			'background_size'       => array( 'type' => 'enum', 'choices' => array( 'auto', 'cover', 'contain' ) ),
			'background_repeat'     => array( 'type' => 'enum', 'choices' => array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ) ),
			'background_attachment' => array( 'type' => 'enum', 'choices' => array( 'scroll', 'fixed' ) ),
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function flatsome_specs(): array {
		return array(
			'color_primary'       => $this->spec( 'colors', __( 'Primary color', 'mindio-magic-mcp' ), 'color', '#446084' ),
			'color_secondary'     => $this->spec( 'colors', __( 'Secondary color', 'mindio-magic-mcp' ), 'color', '#d26e4b' ),
			'color_success'       => $this->spec( 'colors', __( 'Success color', 'mindio-magic-mcp' ), 'color', '#7a9c59' ),
			'color_alert'         => $this->spec( 'colors', __( 'Alert color', 'mindio-magic-mcp' ), 'color', '#b20000' ),
			'color_texts'         => $this->spec( 'colors', __( 'Base text color', 'mindio-magic-mcp' ), 'color', '#777777' ),
			'type_headings_color' => $this->spec( 'colors', __( 'Heading color', 'mindio-magic-mcp' ), 'color', '#555555' ),
			'color_links'         => $this->spec( 'colors', __( 'Link color', 'mindio-magic-mcp' ), 'color', '#334862' ),
			'color_links_hover'   => $this->spec( 'colors', __( 'Link hover color', 'mindio-magic-mcp' ), 'color', '#000000' ),
			'type_headings'       => $this->spec( 'typography', __( 'Heading font', 'mindio-magic-mcp' ), 'typography', array( 'font-family' => 'Lato', 'variant' => '700' ) ),
			'type_texts'          => $this->spec( 'typography', __( 'Base text font', 'mindio-magic-mcp' ), 'typography', array( 'font-family' => 'Lato', 'variant' => 'regular' ) ),
			'type_nav'            => $this->spec( 'typography', __( 'Navigation font', 'mindio-magic-mcp' ), 'typography', array( 'font-family' => 'Lato', 'variant' => '700' ) ),
			'type_alt'            => $this->spec( 'typography', __( 'Alternate font', 'mindio-magic-mcp' ), 'typography', array( 'font-family' => 'Dancing Script', 'variant' => 'regular' ) ),
			'type_size'           => $this->spec( 'typography', __( 'Base font size percent', 'mindio-magic-mcp' ), 'integer', 100, array( 'min' => 50, 'max' => 200 ) ),
			'type_size_mobile'    => $this->spec( 'typography', __( 'Mobile font size percent', 'mindio-magic-mcp' ), 'integer', 100, array( 'min' => 50, 'max' => 200 ) ),
			'body_layout'         => $this->spec( 'layout', __( 'Body layout', 'mindio-magic-mcp' ), 'enum', 'full-width', array( 'choices' => array( 'full-width', 'boxed', 'framed' ) ) ),
			'site_width'          => $this->spec( 'layout', __( 'Content width', 'mindio-magic-mcp' ), 'integer', 1080, array( 'min' => 560, 'max' => 4000 ) ),
			'site_width_boxed'    => $this->spec( 'layout', __( 'Boxed site width', 'mindio-magic-mcp' ), 'integer', 1170, array( 'min' => 560, 'max' => 4000 ) ),
			'content_color'       => $this->spec( 'layout', __( 'Content contrast', 'mindio-magic-mcp' ), 'enum', 'light', array( 'choices' => array( 'light', 'dark' ) ) ),
			'content_bg'          => $this->spec( 'layout', __( 'Content background', 'mindio-magic-mcp' ), 'color', '', array( 'allow_blank' => true ) ),
			'body_bg'             => $this->spec( 'layout', __( 'Body background', 'mindio-magic-mcp' ), 'color', '', array( 'allow_blank' => true ) ),
			'header_width'        => $this->spec( 'header', __( 'Header width', 'mindio-magic-mcp' ), 'enum', 'container', array( 'choices' => array( 'container', 'full-width' ) ) ),
			'header_height'       => $this->spec( 'header', __( 'Header height', 'mindio-magic-mcp' ), 'integer', 90, array( 'min' => 30, 'max' => 200 ) ),
			'header_height_sticky'=> $this->spec( 'header', __( 'Sticky header height', 'mindio-magic-mcp' ), 'integer', 70, array( 'min' => 20, 'max' => 200 ) ),
			'header_color'        => $this->spec( 'header', __( 'Header contrast', 'mindio-magic-mcp' ), 'enum', 'light', array( 'choices' => array( 'light', 'dark' ) ) ),
			'header_sticky'       => $this->spec( 'header', __( 'Sticky header', 'mindio-magic-mcp' ), 'boolean', true ),
			'footer_1'            => $this->spec( 'footer', __( 'Footer area one', 'mindio-magic-mcp' ), 'boolean', true ),
			'footer_2'            => $this->spec( 'footer', __( 'Footer area two', 'mindio-magic-mcp' ), 'boolean', true ),
			'footer_bottom_align' => $this->spec( 'footer', __( 'Bottom footer alignment', 'mindio-magic-mcp' ), 'enum', 'center', array( 'choices' => array( 'left', 'center' ) ) ),
			'footer_bottom_color' => $this->spec( 'footer', __( 'Bottom footer contrast', 'mindio-magic-mcp' ), 'enum', 'dark', array( 'choices' => array( 'light', 'dark' ) ) ),
			'footer_left_text'    => $this->spec( 'footer', __( 'Left footer text', 'mindio-magic-mcp' ), 'html', '' ),
			'footer_right_text'   => $this->spec( 'footer', __( 'Right footer text', 'mindio-magic-mcp' ), 'html', '' ),
			'blog_layout'         => $this->spec( 'blog', __( 'Blog archive sidebar', 'mindio-magic-mcp' ), 'enum', 'right-sidebar', array( 'choices' => array( 'right-sidebar', 'left-sidebar', 'no-sidebar' ) ) ),
			'blog_post_layout'    => $this->spec( 'blog', __( 'Single post sidebar', 'mindio-magic-mcp' ), 'enum', 'right-sidebar', array( 'choices' => array( 'right-sidebar', 'left-sidebar', 'no-sidebar' ) ) ),
			'blog_pagination'     => $this->spec( 'blog', __( 'Blog pagination', 'mindio-magic-mcp' ), 'enum', '', array( 'choices' => array( '', 'ajax' ) ) ),
			'category_sidebar'    => $this->spec( 'shop', __( 'Shop sidebar', 'mindio-magic-mcp' ), 'enum', 'left-sidebar', array( 'choices' => array( 'none', 'left-sidebar', 'right-sidebar', 'off-canvas' ) ) ),
			'product_layout'      => $this->spec( 'shop', __( 'Product page layout', 'mindio-magic-mcp' ), 'enum', 'no-sidebar', array( 'choices' => array( 'no-sidebar', 'left-sidebar', 'left-sidebar-full', 'left-sidebar-small', 'right-sidebar', 'right-sidebar-small', 'right-sidebar-full', 'gallery-wide', 'stacked-right', 'custom' ) ) ),
			'shop_pagination'     => $this->spec( 'shop', __( 'Shop pagination', 'mindio-magic-mcp' ), 'enum', '', array( 'choices' => array( '', 'ajax', 'infinite-scroll' ) ) ),
			'live_search'         => $this->spec( 'shop', __( 'Live product search', 'mindio-magic-mcp' ), 'boolean', true ),
			'ajax_add_to_cart'    => $this->spec( 'shop', __( 'AJAX add to cart', 'mindio-magic-mcp' ), 'boolean', false ),
			'lazy_load_images'    => $this->spec( 'performance', __( 'Lazy-load images', 'mindio-magic-mcp' ), 'boolean', false ),
			'perf_instant_page'   => $this->spec( 'performance', __( 'Instant page preloading', 'mindio-magic-mcp' ), 'boolean', false ),
			'disable_emoji'       => $this->spec( 'performance', __( 'Disable emoji scripts', 'mindio-magic-mcp' ), 'boolean', false ),
			'disable_blockcss'    => $this->spec( 'performance', __( 'Disable WordPress block CSS', 'mindio-magic-mcp' ), 'boolean', false ),
			'jquery_migrate'      => $this->spec( 'performance', __( 'Remove jQuery Migrate', 'mindio-magic-mcp' ), 'boolean', false ),
		);
	}

	/** @param array<string,mixed> $extra
	 *  @return array<string,mixed>
	 */
	private function spec( string $group, string $label, string $type, mixed $default, array $extra = array() ): array {
		return array_merge( array( 'group' => $group, 'label' => $label, 'type' => $type, 'default' => $default ), $extra );
	}

	/** @param array<string,mixed> $spec
	 *  @return mixed|\WP_Error
	 */
	private function validate_setting( string $key, mixed $value, array $spec ): mixed {
		$type = (string) $spec['type'];
		if ( 'boolean' === $type ) {
			return is_bool( $value ) ? $value : $this->invalid_value( $key );
		}
		if ( 'integer' === $type ) {
			if ( ! is_int( $value ) || $value < (int) ( $spec['min'] ?? PHP_INT_MIN ) || $value > (int) ( $spec['max'] ?? PHP_INT_MAX ) ) {
				return $this->invalid_value( $key );
			}
			return $value;
		}
		if ( 'enum' === $type ) {
			return is_string( $value ) && in_array( $value, (array) $spec['choices'], true ) ? $value : $this->invalid_value( $key );
		}
		if ( 'color' === $type ) {
			if ( '' === $value && ! empty( $spec['allow_blank'] ) ) {
				return '';
			}
			return is_string( $value ) && sanitize_hex_color( $value ) === $value ? strtolower( $value ) : $this->invalid_value( $key );
		}
		if ( 'hex_no_hash' === $type ) {
			if ( '' === $value && ! empty( $spec['allow_blank'] ) ) {
				return '';
			}
			return is_string( $value ) && preg_match( '/^[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : $this->invalid_value( $key );
		}
		if ( 'url' === $type ) {
			if ( '' === $value && ! empty( $spec['allow_blank'] ) ) {
				return '';
			}
			$clean = is_string( $value ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
			return $clean && $clean === $value ? $clean : $this->invalid_value( $key );
		}
		if ( 'image_id' === $type ) {
			if ( ! is_int( $value ) || $value < 0 || ( $value > 0 && ! wp_attachment_is_image( $value ) ) ) {
				return $this->invalid_value( $key );
			}
			return $value;
		}
		if ( 'html' === $type ) {
			return is_string( $value ) && strlen( $value ) <= 5000 ? wp_kses_post( $value ) : $this->invalid_value( $key );
		}
		if ( 'typography' === $type ) {
			if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'font-family', 'variant' ) ) || empty( $value['font-family'] ) || empty( $value['variant'] ) ) {
				return $this->invalid_value( $key );
			}
			$family  = sanitize_text_field( (string) $value['font-family'] );
			$variant = sanitize_key( (string) $value['variant'] );
			if ( strlen( $family ) > 100 || ! preg_match( '/^(regular|italic|[1-9]00(?:italic)?)$/', $variant ) ) {
				return $this->invalid_value( $key );
			}
			return array( 'font-family' => $family, 'variant' => $variant );
		}
		return $this->invalid_value( $key );
	}

	private function invalid_value( string $key ): \WP_Error {
		return new \WP_Error(
			'invalid_theme_setting',
			sprintf(
				/* translators: %s: WordPress theme setting key. */
				__( 'Theme setting "%s" has an invalid value or type.', 'mindio-magic-mcp' ),
				$key
			)
		);
	}

	/** @return true|\WP_Error */
	private function require_flatsome(): bool|\WP_Error {
		return 'flatsome' === get_template() ? true : new \WP_Error( 'flatsome_not_active', __( 'The active theme is not Flatsome or a Flatsome child theme.', 'mindio-magic-mcp' ) );
	}

	/** @return true|\WP_Error */
	private function check_stylesheet( string $expected ): bool|\WP_Error {
		if ( $expected && $expected !== get_stylesheet() ) {
			return new \WP_Error( 'theme_context_changed', __( 'The active stylesheet changed since the agent read the theme context.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function filesystem_ready(): bool|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			return new \WP_Error( 'filesystem_credentials_required', __( 'This site requires interactive filesystem credentials, so MCP cannot safely create a child theme.', 'mindio-magic-mcp' ) );
		}
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new \WP_Error( 'filesystem_unavailable', __( 'WordPress could not initialize direct filesystem access.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	private function safe_output_value( string $key, mixed $value, int $depth = 0 ): mixed {
		if ( preg_match( '/(?:secret|token|password|private|license|purchase|api[_-]?key|maps[_-]?api)/i', $key ) ) {
			return '[redacted]';
		}
		if ( $depth > 5 ) {
			return '[truncated]';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return is_string( $value ) && strlen( $value ) > 2000 ? substr( $value, 0, 2000 ) . '…' : $value;
		}
		if ( ! is_array( $value ) ) {
			return '[unsupported]';
		}
		$output = array();
		foreach ( array_slice( $value, 0, 100, true ) as $child_key => $child ) {
			$output[ $child_key ] = $this->safe_output_value( (string) $child_key, $child, $depth + 1 );
		}
		return $output;
	}

	private function empty_schema(): array {
		return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
	}

	private function theme_mod_update_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'values'              => array( 'type' => 'object' ),
				'reset'               => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,100}$' ),
				),
				'expected_stylesheet' => array( 'type' => 'string', 'maxLength' => 100 ),
				'confirm'             => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'confirm' ),
			'additionalProperties' => false,
		);
	}
}
