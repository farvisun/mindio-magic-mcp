<?php
/**
 * Guarded plugin and theme lifecycle tools.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Extension_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'list_plugins',
			__( 'List installed WordPress plugins, versions, activation state, and available updates.', 'mindio-magic-mcp' ),
			$this->empty_schema(),
			array( 'type' => 'object' ),
			array( $this, 'list_plugins' ),
			Auth::SCOPE_ADMIN,
			'activate_plugins',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'search_plugins',
			__( 'Search the WordPress.org plugin directory without installing anything.', 'mindio-magic-mcp' ),
			$this->directory_search_schema(),
			array( 'type' => 'object' ),
			array( $this, 'search_plugins' ),
			Auth::SCOPE_ADMIN,
			'install_plugins',
			array( 'readOnlyHint' => true, 'openWorldHint' => true )
		);
		$this->registry->register(
			'install_plugin',
			__( 'Install a plugin by exact WordPress.org slug. Custom package URLs are never accepted. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'slug'     => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,99}$' ),
					'activate' => array( 'type' => 'boolean' ),
					'confirm'  => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'slug', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'install_plugin' ),
			Auth::SCOPE_ADMIN,
			'install_plugins',
			array( 'openWorldHint' => true )
		);
		$this->registry->register(
			'update_plugin',
			__( 'Update one installed plugin to its latest available version. Requires confirm=true.', 'mindio-magic-mcp' ),
			$this->confirmed_plugin_file_schema(),
			array( 'type' => 'object' ),
			array( $this, 'update_plugin' ),
			Auth::SCOPE_ADMIN,
			'update_plugins',
			array( 'openWorldHint' => true )
		);
		$this->registry->register(
			'activate_plugin',
			__( 'Activate an installed plugin by its plugin file, for example akismet/akismet.php.', 'mindio-magic-mcp' ),
			$this->plugin_file_schema(),
			array( 'type' => 'object' ),
			array( $this, 'activate_plugin' ),
			Auth::SCOPE_ADMIN,
			'activate_plugins',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'deactivate_plugin',
			__( 'Deactivate an installed plugin. Mindio Magic MCP cannot deactivate itself through its own connection.', 'mindio-magic-mcp' ),
			$this->plugin_file_schema(),
			array( 'type' => 'object' ),
			array( $this, 'deactivate_plugin' ),
			Auth::SCOPE_ADMIN,
			'activate_plugins',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'delete_plugin',
			__( 'Permanently delete an inactive plugin. Mindio Magic MCP is protected and confirm=true is required.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'plugin_file' => array( 'type' => 'string', 'maxLength' => 300 ),
					'confirm'     => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'plugin_file', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'delete_plugin' ),
			Auth::SCOPE_ADMIN,
			'delete_plugins',
			array( 'destructiveHint' => true )
		);
		$this->registry->register(
			'list_themes',
			__( 'List installed WordPress themes and identify the active template and child theme.', 'mindio-magic-mcp' ),
			$this->empty_schema(),
			array( 'type' => 'object' ),
			array( $this, 'list_themes' ),
			Auth::SCOPE_ADMIN,
			'switch_themes',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'search_themes',
			__( 'Search the WordPress.org theme directory without installing anything.', 'mindio-magic-mcp' ),
			$this->directory_search_schema(),
			array( 'type' => 'object' ),
			array( $this, 'search_themes' ),
			Auth::SCOPE_ADMIN,
			'install_themes',
			array( 'readOnlyHint' => true, 'openWorldHint' => true )
		);
		$this->registry->register(
			'install_theme',
			__( 'Install a theme by exact WordPress.org slug. Custom package URLs are never accepted. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'slug'     => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,99}$' ),
					'activate' => array( 'type' => 'boolean' ),
					'confirm'  => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'slug', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'install_theme' ),
			Auth::SCOPE_ADMIN,
			'install_themes',
			array( 'openWorldHint' => true )
		);
		$this->registry->register(
			'update_theme',
			__( 'Update one installed theme to its latest available version. Requires confirm=true.', 'mindio-magic-mcp' ),
			$this->confirmed_stylesheet_schema(),
			array( 'type' => 'object' ),
			array( $this, 'update_theme' ),
			Auth::SCOPE_ADMIN,
			'update_themes',
			array( 'openWorldHint' => true )
		);
		$this->registry->register(
			'delete_theme',
			__( 'Permanently delete an inactive theme. Active themes and their parent themes are protected. Requires confirm=true.', 'mindio-magic-mcp' ),
			$this->confirmed_stylesheet_schema(),
			array( 'type' => 'object' ),
			array( $this, 'delete_theme' ),
			Auth::SCOPE_ADMIN,
			'delete_themes',
			array( 'destructiveHint' => true )
		);
		$this->registry->register(
			'switch_theme',
			__( 'Switch to an installed theme by stylesheet slug. Requires confirm=true because this changes the public site.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'stylesheet' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{1,100}$' ),
					'confirm'    => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'stylesheet', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'switch_theme' ),
			Auth::SCOPE_ADMIN,
			'switch_themes'
		);
	}

	/** @return array<string,mixed> */
	public function list_plugins( array $args = array() ): array {
		unset( $args );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$updates = get_site_transient( 'update_plugins' );
		$items   = array();
		foreach ( get_plugins() as $file => $data ) {
			$items[] = array(
				'plugin_file'      => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'author'           => wp_strip_all_tags( $data['Author'] ),
				'active'           => is_plugin_active( $file ),
				'network_active'   => is_multisite() && is_plugin_active_for_network( $file ),
				'update_available' => is_object( $updates ) && isset( $updates->response[ $file ] ),
				'new_version'      => is_object( $updates ) && isset( $updates->response[ $file ] ) ? (string) $updates->response[ $file ]->new_version : '',
				'protected'        => plugin_basename( MINDIO_MAGIC_MCP_FILE ) === $file,
			);
		}
		return array( 'plugins' => $items );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function search_plugins( array $args ): array|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$result = plugins_api(
			'query_plugins',
			(object) array(
				'search'   => sanitize_text_field( (string) $args['search'] ),
				'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
				'per_page' => min( 30, max( 1, (int) ( $args['per_page'] ?? 12 ) ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$plugins = array();
		foreach ( (array) ( $result->plugins ?? array() ) as $plugin ) {
			$plugin    = (array) $plugin;
			$plugins[] = array(
				'slug'             => sanitize_key( (string) ( $plugin['slug'] ?? '' ) ),
				'name'             => sanitize_text_field( (string) ( $plugin['name'] ?? '' ) ),
				'version'          => sanitize_text_field( (string) ( $plugin['version'] ?? '' ) ),
				'author'           => wp_strip_all_tags( (string) ( $plugin['author'] ?? '' ) ),
				'short_description' => wp_strip_all_tags( (string) ( $plugin['short_description'] ?? '' ) ),
				'rating'           => (int) ( $plugin['rating'] ?? 0 ),
				'active_installs'  => (int) ( $plugin['active_installs'] ?? 0 ),
				'last_updated'     => sanitize_text_field( (string) ( $plugin['last_updated'] ?? '' ) ),
				'requires'         => sanitize_text_field( (string) ( $plugin['requires'] ?? '' ) ),
				'tested'           => sanitize_text_field( (string) ( $plugin['tested'] ?? '' ) ),
			);
		}

		return array(
			'plugins' => $plugins,
			'page'    => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'pages'   => (int) ( $result->info['pages'] ?? 1 ),
			'results' => (int) ( $result->info['results'] ?? count( $plugins ) ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function install_plugin( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Plugin installation requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$slug = sanitize_key( (string) $args['slug'] );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/', $slug ) ) {
			return new \WP_Error( 'invalid_plugin_slug', __( 'The WordPress.org plugin slug is invalid.', 'mindio-magic-mcp' ) );
		}
		$this->load_upgrader();
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$info = plugins_api(
			'plugin_information',
			(object) array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$download = $this->official_download_url( (string) ( $info->download_link ?? '' ), 'plugin', $slug );
		if ( is_wp_error( $download ) ) {
			return $download;
		}
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $download );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'plugin_install_failed', $skin->get_errors()->get_error_message() ?: __( 'Plugin installation failed.', 'mindio-magic-mcp' ) );
		}
		wp_clean_plugins_cache( true );
		$plugin_file = $upgrader->plugin_info() ?: $this->find_plugin_file( $slug );
		$activated   = false;
		if ( ! empty( $args['activate'] ) && $plugin_file ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return new \WP_Error( 'cannot_activate', __( 'The plugin installed, but your user cannot activate it.', 'mindio-magic-mcp' ) );
			}
			$activation = activate_plugin( $plugin_file );
			if ( is_wp_error( $activation ) ) {
				return $activation;
			}
			$activated = true;
		}
		return array( 'slug' => $slug, 'plugin_file' => $plugin_file, 'installed' => true, 'activated' => $activated );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_plugin( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Plugin updates require confirm=true.', 'mindio-magic-mcp' ) );
		}
		$file = $this->valid_plugin_file( (string) $args['plugin_file'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$this->load_upgrader();
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_plugins();
		$updates = get_plugin_updates();
		if ( ! isset( $updates[ $file ] ) ) {
			return array( 'plugin_file' => $file, 'updated' => false, 'reason' => 'up_to_date' );
		}
		$previous = (string) ( $updates[ $file ]->Version ?? '' );
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'plugin_update_failed', $skin->get_errors()->get_error_message() ?: __( 'Plugin update failed.', 'mindio-magic-mcp' ) );
		}
		wp_clean_plugins_cache( true );
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		return array( 'plugin_file' => $file, 'updated' => true, 'previous_version' => $previous, 'version' => (string) $data['Version'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function activate_plugin( array $args ): array|\WP_Error {
		$file = $this->valid_plugin_file( (string) $args['plugin_file'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( is_plugin_active( $file ) ) {
			return array( 'plugin_file' => $file, 'active' => true, 'changed' => false );
		}
		$result = activate_plugin( $file );
		return is_wp_error( $result ) ? $result : array( 'plugin_file' => $file, 'active' => true, 'changed' => true );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function deactivate_plugin( array $args ): array|\WP_Error {
		$file = $this->valid_plugin_file( (string) $args['plugin_file'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( plugin_basename( MINDIO_MAGIC_MCP_FILE ) === $file ) {
			return new \WP_Error( 'self_deactivation_forbidden', __( 'Mindio Magic MCP cannot deactivate itself through MCP.', 'mindio-magic-mcp' ) );
		}
		if ( ! is_plugin_active( $file ) ) {
			return array( 'plugin_file' => $file, 'active' => false, 'changed' => false );
		}
		deactivate_plugins( $file, false, false );
		return array( 'plugin_file' => $file, 'active' => is_plugin_active( $file ), 'changed' => true );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_plugin( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Plugin deletion requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$file = $this->valid_plugin_file( (string) $args['plugin_file'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( plugin_basename( MINDIO_MAGIC_MCP_FILE ) === $file ) {
			return new \WP_Error( 'protected_plugin', __( 'Mindio Magic MCP cannot delete itself.', 'mindio-magic-mcp' ) );
		}
		if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
			return new \WP_Error( 'plugin_active', __( 'Deactivate the plugin before deleting it.', 'mindio-magic-mcp' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$result = delete_plugins( array( $file ) );
		return is_wp_error( $result ) ? $result : array( 'plugin_file' => $file, 'deleted' => (bool) $result );
	}

	/** @return array<string,mixed> */
	public function list_themes( array $args = array() ): array {
		unset( $args );
		$active  = get_stylesheet();
		$parent  = get_template();
		$updates = get_site_transient( 'update_themes' );
		$items   = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[] = array(
				'stylesheet'       => $stylesheet,
				'template'         => $theme->get_template(),
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'active'           => $stylesheet === $active,
				'is_child'         => $theme->parent() instanceof \WP_Theme,
				'protected'        => $stylesheet === $active || $stylesheet === $parent,
				'update_available' => is_object( $updates ) && isset( $updates->response[ $stylesheet ] ),
				'new_version'      => is_object( $updates ) && isset( $updates->response[ $stylesheet ] ) ? (string) $updates->response[ $stylesheet ]['new_version'] : '',
				'errors'           => $theme->errors() ? $theme->errors()->get_error_messages() : array(),
			);
		}
		return array( 'themes' => $items );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function search_themes( array $args ): array|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$result = themes_api(
			'query_themes',
			array(
				'search'   => sanitize_text_field( (string) $args['search'] ),
				'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
				'per_page' => min( 30, max( 1, (int) ( $args['per_page'] ?? 12 ) ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$themes = array();
		foreach ( (array) ( $result->themes ?? array() ) as $theme ) {
			$theme  = (array) $theme;
			$author = $theme['author'] ?? '';
			if ( is_object( $author ) ) {
				$author = get_object_vars( $author );
			}
			if ( is_array( $author ) ) {
				$author = $author['display_name'] ?? $author['name'] ?? $author['user_nicename'] ?? '';
			}
			$themes[] = array(
				'slug'        => sanitize_key( (string) ( $theme['slug'] ?? '' ) ),
				'name'        => sanitize_text_field( (string) ( $theme['name'] ?? '' ) ),
				'version'     => sanitize_text_field( (string) ( $theme['version'] ?? '' ) ),
				'author'      => wp_strip_all_tags( is_scalar( $author ) ? (string) $author : '' ),
				'description' => wp_strip_all_tags( (string) ( $theme['description'] ?? '' ) ),
				'rating'      => (int) ( $theme['rating'] ?? 0 ),
				'num_ratings' => (int) ( $theme['num_ratings'] ?? 0 ),
				'updated'     => sanitize_text_field( (string) ( $theme['last_updated'] ?? '' ) ),
			);
		}
		$info = (array) ( $result->info ?? array() );
		return array(
			'themes'  => $themes,
			'page'    => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'pages'   => (int) ( $info['pages'] ?? 1 ),
			'results' => (int) ( $info['results'] ?? count( $themes ) ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function install_theme( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Theme installation requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$slug = sanitize_key( (string) $args['slug'] );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/', $slug ) ) {
			return new \WP_Error( 'invalid_theme_slug', __( 'The WordPress.org theme slug is invalid.', 'mindio-magic-mcp' ) );
		}
		$this->load_upgrader();
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$info = themes_api( 'theme_information', array( 'slug' => $slug ) );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$download = $this->official_download_url( (string) ( $info->download_link ?? '' ), 'theme', $slug );
		if ( is_wp_error( $download ) ) {
			return $download;
		}
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $download );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'theme_install_failed', $skin->get_errors()->get_error_message() ?: __( 'Theme installation failed.', 'mindio-magic-mcp' ) );
		}
		wp_clean_themes_cache( true );
		$theme      = $upgrader->theme_info();
		$stylesheet = $theme instanceof \WP_Theme ? $theme->get_stylesheet() : $slug;
		$activated  = false;
		if ( ! empty( $args['activate'] ) ) {
			if ( ! current_user_can( 'switch_themes' ) ) {
				return new \WP_Error( 'cannot_activate_theme', __( 'The theme installed, but your user cannot activate it.', 'mindio-magic-mcp' ) );
			}
			switch_theme( $stylesheet );
			$activated = get_stylesheet() === $stylesheet;
		}
		return array( 'slug' => $slug, 'stylesheet' => $stylesheet, 'installed' => true, 'activated' => $activated );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_theme( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Theme updates require confirm=true.', 'mindio-magic-mcp' ) );
		}
		$stylesheet = $this->valid_stylesheet( (string) $args['stylesheet'] );
		if ( is_wp_error( $stylesheet ) ) {
			return $stylesheet;
		}
		$this->load_upgrader();
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_themes();
		$updates = get_theme_updates();
		if ( ! isset( $updates[ $stylesheet ] ) ) {
			return array( 'stylesheet' => $stylesheet, 'updated' => false, 'reason' => 'up_to_date' );
		}
		$previous = (string) wp_get_theme( $stylesheet )->get( 'Version' );
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'theme_update_failed', $skin->get_errors()->get_error_message() ?: __( 'Theme update failed.', 'mindio-magic-mcp' ) );
		}
		wp_clean_themes_cache( true );
		return array( 'stylesheet' => $stylesheet, 'updated' => true, 'previous_version' => $previous, 'version' => (string) wp_get_theme( $stylesheet )->get( 'Version' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_theme( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Theme deletion requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$stylesheet = $this->valid_stylesheet( (string) $args['stylesheet'] );
		if ( is_wp_error( $stylesheet ) ) {
			return $stylesheet;
		}
		if ( $stylesheet === get_stylesheet() || $stylesheet === get_template() ) {
			return new \WP_Error( 'protected_theme', __( 'The active theme and its parent theme cannot be deleted.', 'mindio-magic-mcp' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		$filesystem = $this->filesystem_ready();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$result = delete_theme( $stylesheet );
		return is_wp_error( $result ) ? $result : array( 'stylesheet' => $stylesheet, 'deleted' => (bool) $result );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function switch_theme( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Theme switching requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$stylesheet = $this->valid_stylesheet( (string) $args['stylesheet'] );
		if ( is_wp_error( $stylesheet ) ) {
			return $stylesheet;
		}
		if ( get_stylesheet() === $stylesheet ) {
			return array( 'stylesheet' => $stylesheet, 'active' => true, 'changed' => false );
		}
		switch_theme( $stylesheet );
		return array( 'stylesheet' => $stylesheet, 'template' => get_template(), 'active' => get_stylesheet() === $stylesheet, 'changed' => true );
	}

	private function empty_schema(): array {
		return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
	}

	private function plugin_file_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'plugin_file' => array( 'type' => 'string', 'maxLength' => 300 ) ),
			'required'             => array( 'plugin_file' ),
			'additionalProperties' => false,
		);
	}

	private function confirmed_plugin_file_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'plugin_file' => array( 'type' => 'string', 'maxLength' => 300 ),
				'confirm'     => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'plugin_file', 'confirm' ),
			'additionalProperties' => false,
		);
	}

	private function confirmed_stylesheet_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'stylesheet' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{1,100}$' ),
				'confirm'    => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'stylesheet', 'confirm' ),
			'additionalProperties' => false,
		);
	}

	private function directory_search_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search'   => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 100 ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 30 ),
			),
			'required'             => array( 'search' ),
			'additionalProperties' => false,
		);
	}

	/** @return string|\WP_Error */
	private function valid_plugin_file( string $file ): string|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file    = plugin_basename( sanitize_text_field( $file ) );
		$plugins = get_plugins();
		if ( str_contains( $file, '..' ) || ! isset( $plugins[ $file ] ) ) {
			return new \WP_Error( 'plugin_not_found', __( 'The installed plugin file was not found.', 'mindio-magic-mcp' ) );
		}
		return $file;
	}

	private function find_plugin_file( string $slug ): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( str_starts_with( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return $file;
			}
		}
		return '';
	}

	/** @return string|\WP_Error */
	private function valid_stylesheet( string $stylesheet ): string|\WP_Error {
		$stylesheet = sanitize_file_name( $stylesheet );
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $stylesheet ) ) {
			return new \WP_Error( 'invalid_stylesheet', __( 'The theme stylesheet slug is invalid.', 'mindio-magic-mcp' ) );
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() || $theme->errors() ) {
			return new \WP_Error( 'theme_not_found', __( 'The installed theme is missing or broken.', 'mindio-magic-mcp' ) );
		}
		return $stylesheet;
	}

	/** @return true|\WP_Error */
	private function filesystem_ready(): bool|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			return new \WP_Error(
				'filesystem_credentials_required',
				__( 'This site requires interactive filesystem credentials, so MCP cannot safely perform the package operation.', 'mindio-magic-mcp' )
			);
		}
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new \WP_Error( 'filesystem_unavailable', __( 'WordPress could not initialize direct filesystem access.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	/** @return string|\WP_Error */
	private function official_download_url( string $url, string $type, string $slug ): string|\WP_Error {
		$parts = wp_parse_url( $url );
		$path  = (string) ( $parts['path'] ?? '' );
		if (
			'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ||
			'downloads.wordpress.org' !== strtolower( (string) ( $parts['host'] ?? '' ) ) ||
			! in_array( $type, array( 'plugin', 'theme' ), true ) ||
			! preg_match( '#^/' . preg_quote( $type, '#' ) . '/' . preg_quote( $slug, '#' ) . '(?:[.-][^/]*)?\.zip$#i', $path )
		) {
			return new \WP_Error( 'untrusted_package_url', __( 'The WordPress.org API returned an untrusted package URL.', 'mindio-magic-mcp' ) );
		}
		return esc_url_raw( $url );
	}

	private function load_upgrader(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}
}
