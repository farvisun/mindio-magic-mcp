<?php
/**
 * Database installation and retention jobs.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {
	public static function activate( bool $network_wide = false ): void {
		OAuth_Server::register_rewrite_rules();
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	public static function deactivate( bool $network_wide = false ): void {
		self::remove_rewrite_rules();
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		self::deactivate_site();
	}

	public static function initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( MINDIO_MAGIC_MCP_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	private static function deactivate_site(): void {
		wp_clear_scheduled_hook( 'mindio_magic_mcp_cleanup_logs' );
		flush_rewrite_rules( false );
	}

	private static function remove_rewrite_rules(): void {
		global $wp_rewrite;
		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return;
		}
		unset(
			$wp_rewrite->extra_rules_top['^\.well-known/oauth-protected-resource/?$'],
			$wp_rewrite->extra_rules_top['^\.well-known/oauth-authorization-server/?$']
		);
	}

	public static function maybe_upgrade(): void {
		if ( MINDIO_MAGIC_MCP_DB_VERSION !== (string) get_option( 'mindio_magic_mcp_db_version', '' ) ) {
			self::install_tables();
			update_option( 'mindio_magic_mcp_db_version', MINDIO_MAGIC_MCP_DB_VERSION, false );
		}
		$current = get_option( 'mindio_magic_mcp_settings', array() );
		$merged  = wp_parse_args( is_array( $current ) ? $current : array(), self::defaults() );
		if ( $merged !== $current ) {
			update_option( 'mindio_magic_mcp_settings', $merged, false );
		}
		if ( ! wp_next_scheduled( 'mindio_magic_mcp_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mindio_magic_mcp_cleanup_logs' );
		}
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mindio_magic_mcp_audit_log';
	}

	public static function webhook_log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mindio_magic_mcp_webhook_log';
	}

	public static function changeset_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mindio_magic_mcp_changesets';
	}

	public static function changeset_entry_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mindio_magic_mcp_changeset_entries';
	}

	public static function cleanup_logs(): void {
		global $wpdb;

		$settings      = get_option( 'mindio_magic_mcp_settings', array() );
		$audit_days    = max( 1, min( 365, absint( $settings['audit_retention_days'] ?? 30 ) ) );
		$webhook_days  = max( 1, min( 365, absint( $settings['webhook_retention_days'] ?? 14 ) ) );
		$audit_cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $audit_days * DAY_IN_SECONDS ) );
		$webhook_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $webhook_days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::audit_table(), $audit_cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup mutates plugin-owned logs and must not be cached.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::webhook_log_table(), $webhook_cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup mutates plugin-owned logs and must not be cached.
	}

	private static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$audit_sql = 'CREATE TABLE ' . self::audit_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_id varchar(64) NOT NULL DEFAULT '',
			scope varchar(20) NOT NULL DEFAULT '',
			tool varchar(100) NOT NULL,
			arguments longtext NULL,
			success tinyint(1) NOT NULL DEFAULT 0,
			error_code varchar(100) NOT NULL DEFAULT '',
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY tool (tool),
			KEY user_id (user_id)
		) $charset;";

		$webhook_sql = 'CREATE TABLE ' . self::webhook_log_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			webhook_id varchar(64) NOT NULL,
			event varchar(50) NOT NULL,
			payload longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			response_code smallint(5) unsigned NOT NULL DEFAULT 0,
			response_body text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			next_attempt datetime NULL,
			PRIMARY KEY  (id),
			KEY webhook_id (webhook_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";

		$changeset_sql = 'CREATE TABLE ' . self::changeset_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			changeset_id varchar(64) NOT NULL,
			label varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_id varchar(64) NOT NULL DEFAULT '',
			entries int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY changeset_id (changeset_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";

		$changeset_entry_sql = 'CREATE TABLE ' . self::changeset_entry_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			changeset_id varchar(64) NOT NULL,
			tool varchar(100) NOT NULL DEFAULT '',
			kind varchar(32) NOT NULL,
			object_key varchar(191) NOT NULL,
			operation varchar(20) NOT NULL DEFAULT 'update',
			target text NULL,
			before_state longtext NULL,
			after_state longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY changeset_id (changeset_id),
			KEY kind (kind)
		) $charset;";

		dbDelta( $audit_sql );
		dbDelta( $webhook_sql );
		dbDelta( $changeset_sql );
		dbDelta( $changeset_entry_sql );
	}

	private static function install_site(): void {
		self::install_tables();
		$current = get_option( 'mindio_magic_mcp_settings', array() );
		update_option( 'mindio_magic_mcp_settings', wp_parse_args( is_array( $current ) ? $current : array(), self::defaults() ), false );
		update_option( 'mindio_magic_mcp_db_version', MINDIO_MAGIC_MCP_DB_VERSION, false );
		if ( ! wp_next_scheduled( 'mindio_magic_mcp_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mindio_magic_mcp_cleanup_logs' );
		}
		flush_rewrite_rules( false );
	}

	/** @return array<string,mixed> */
	private static function defaults(): array {
		return array(
			'enabled'                => true,
			'rate_limit'             => 60,
			'max_upload_mb'          => 10,
			'audit_retention_days'   => 30,
			'webhook_retention_days' => 14,
			'allowed_origins'        => array(),
			'brand_voice'            => '',
			'delete_on_uninstall'    => false,
			'allow_database_inspection'       => false,
			'allow_filesystem_read'  => false,
			'allow_wp_cli'           => false,
		);
	}
}
