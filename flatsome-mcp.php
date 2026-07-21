<?php
/**
 * Plugin Name:       Mindio Magic MCP
 * Plugin URI:        https://github.com/farvisun/mindio-magic-mcp
 * Description:       A secure MCP server for WordPress that supports Flatsome UX Builder, content automation, and site management.
 * Version:           0.5.4
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mohammad Askari <farvisun@gmail.com>
 * Author URI:        https://profiles.wordpress.org/farvisun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mindio-magic-mcp
 * Domain Path:       /languages
 *
 * @package FlatsomeMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Avoid a fatal collision during a manual transition from the legacy package slug.
if ( defined( 'FLATSOME_MCP_FILE' ) ) {
	return;
}

define( 'FLATSOME_MCP_VERSION', '0.5.4' );
define( 'FLATSOME_MCP_DB_VERSION', '1' );
define( 'FLATSOME_MCP_FILE', __FILE__ );
define( 'FLATSOME_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLATSOME_MCP_URL', plugin_dir_url( __FILE__ ) );

$flatsome_mcp_files = array(
	'includes/class-installer.php',
	'includes/class-schema-validator.php',
	'includes/class-auth.php',
	'includes/class-rate-limiter.php',
	'includes/class-audit-log.php',
	'includes/class-tool-registry.php',
	'includes/class-integration-dispatcher.php',
	'includes/class-url-guard.php',
	'includes/class-secret-box.php',
	'includes/class-flatsome-component-catalog.php',
	'includes/class-flatsome-renderer.php',
	'includes/class-block-tree.php',
	'includes/class-webhook-engine.php',
	'includes/class-oauth-server.php',
	'includes/class-mcp-server.php',
	'includes/tools/class-content-tools.php',
	'includes/tools/class-gutenberg-tools.php',
	'includes/tools/class-media-tools.php',
	'includes/tools/class-seo-tools.php',
	'includes/tools/class-seo-provider-tools.php',
	'includes/tools/class-site-tools.php',
	'includes/tools/class-comment-tools.php',
	'includes/tools/class-user-tools.php',
	'includes/tools/class-search-tools.php',
	'includes/tools/class-automation-tools.php',
	'includes/tools/class-extension-tools.php',
	'includes/tools/class-theme-tools.php',
	'includes/tools/class-acf-tools.php',
	'includes/tools/class-contact-form-7-tools.php',
	'includes/tools/class-woocommerce-tools.php',
	'includes/tools/class-woocommerce-operation-tools.php',
	'includes/tools/class-multisite-tools.php',
	'includes/tools/class-developer-tools.php',
	'includes/tools/class-filesystem-tools.php',
	'includes/tools/class-performance-tools.php',
	'includes/tools/class-webhook-tools.php',
	'includes/tools/class-flatsome-tools.php',
	'includes/tools/class-system-tools.php',
	'includes/class-admin.php',
	'includes/class-plugin.php',
);

foreach ( $flatsome_mcp_files as $flatsome_mcp_file ) {
	require_once FLATSOME_MCP_DIR . $flatsome_mcp_file;
}

register_activation_hook( __FILE__, array( '\FlatsomeMCP\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\FlatsomeMCP\Installer', 'deactivate' ) );

if ( did_action( 'plugins_loaded' ) ) {
	\FlatsomeMCP\Plugin::instance()->boot();
} else {
	add_action( 'plugins_loaded', array( \FlatsomeMCP\Plugin::instance(), 'boot' ), 20 );
}
