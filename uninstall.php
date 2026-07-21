<?php
/**
 * Optional full data cleanup.
 *
 * @package FlatsomeMCP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin-owned configuration, credentials, scheduled jobs, and logs
 * for the current site only when its administrator opted in.
 */
function flatsome_mcp_uninstall_site(): void {
	$settings = get_option( 'flatsome_mcp_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	wp_clear_scheduled_hook( 'flatsome_mcp_cleanup_logs' );
	wp_clear_scheduled_hook( 'flatsome_mcp_deliver_webhook' );

	foreach (
		array(
			'flatsome_mcp_settings',
			'flatsome_mcp_disabled_tools',
			'flatsome_mcp_operation_policy',
			'flatsome_mcp_db_version',
			'flatsome_mcp_tokens',
			'flatsome_mcp_refresh_tokens',
			'flatsome_mcp_oauth_clients',
			'flatsome_mcp_webhooks',
		) as $option
	) {
		delete_option( $option );
	}

	global $wpdb;
	$audit_table   = $wpdb->prefix . 'flatsome_mcp_audit_log';
	$webhook_table = $wpdb->prefix . 'flatsome_mcp_webhook_log';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $audit_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Opted-in uninstall removes the exact plugin-owned table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $webhook_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Opted-in uninstall removes the exact plugin-owned table.
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $flatsome_mcp_site_id ) {
		switch_to_blog( (int) $flatsome_mcp_site_id );
		flatsome_mcp_uninstall_site();
		restore_current_blog();
	}
} else {
	flatsome_mcp_uninstall_site();
}
