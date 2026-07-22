<?php
/**
 * Optional full data cleanup.
 *
 * @package MindioMagicMCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin-owned configuration, credentials, scheduled jobs, and logs
 * for the current site only when its administrator opted in.
 */
function mindio_magic_mcp_uninstall_site(): void {
	$settings = get_option( 'mindio_magic_mcp_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	wp_clear_scheduled_hook( 'mindio_magic_mcp_cleanup_logs' );
	wp_clear_scheduled_hook( 'mindio_magic_mcp_deliver_webhook' );

	foreach (
		array(
			'mindio_magic_mcp_settings',
			'mindio_magic_mcp_disabled_tools',
			'mindio_magic_mcp_operation_policy',
			'mindio_magic_mcp_db_version',
			'mindio_magic_mcp_tokens',
			'mindio_magic_mcp_refresh_tokens',
			'mindio_magic_mcp_oauth_clients',
			'mindio_magic_mcp_webhooks',
		) as $option
	) {
		delete_option( $option );
	}

	global $wpdb;
	$audit_table   = $wpdb->prefix . 'mindio_magic_mcp_audit_log';
	$webhook_table = $wpdb->prefix . 'mindio_magic_mcp_webhook_log';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $audit_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Opted-in uninstall removes the exact plugin-owned table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $webhook_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Opted-in uninstall removes the exact plugin-owned table.
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mindio_magic_mcp_site_id ) {
		switch_to_blog( (int) $mindio_magic_mcp_site_id );
		mindio_magic_mcp_uninstall_site();
		restore_current_blog();
	}
} else {
	mindio_magic_mcp_uninstall_site();
}
