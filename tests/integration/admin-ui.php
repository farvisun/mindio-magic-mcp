<?php
/**
 * Server-rendered admin console integration checks.
 *
 * Run with WP_PATH=/path/to/wordpress php tests/integration/admin-ui.php.
 *
 * @package MindioMagicMCP
 */

declare(strict_types=1);

$wp_path = getenv( 'WP_PATH' ) ?: dirname( __DIR__, 3 ) . '/wordpress';
require rtrim( $wp_path, '/\\' ) . '/wp-load.php';

if ( ! class_exists( '\MindioMagicMCP\Admin' ) ) {
	throw new RuntimeException( 'Activate Mindio Magic MCP before running the admin UI test.' );
}

/** @throws RuntimeException */
function fmp_admin_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class FMP_Admin_Plugin_Dependency_Fixture extends \MindioMagicMCP\Integration_Dispatcher {
	/** @var array<int,string> */
	private array $plugin_files;
	/** @var array<int,string> */
	private array $text_domains;

	/** @param array<int,string> $plugin_files @param array<int,string> $text_domains */
	public function __construct( \MindioMagicMCP\Tool_Registry $registry, string $name, array $plugin_files, array $text_domains ) {
		$this->plugin_files = $plugin_files;
		$this->text_domains = $text_domains;
		parent::__construct( $registry, $name, 'Dependency fixture' );
	}

	public function register(): void {
		$this->register_operations(
			array(
				'inspect' => array(
					'mode'        => 'read',
					'label'       => 'Inspect fixture',
					'description' => 'Inspect the installed dependency fixture.',
					'schema'      => $this->empty_schema(),
					'callback'    => static fn( array $args ): array => array( 'ok' => empty( $args ) ),
					'capability'  => 'read',
				),
			)
		);
	}

	protected function dependency_installed(): bool {
		return $this->plugin_is_installed( $this->plugin_files, $this->text_domains );
	}

	protected function dependency_available(): bool {
		return false;
	}

	protected function dependency_label(): string {
		return 'Dependency fixture';
	}
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
fmp_admin_assert( ! empty( $admins ), 'The WordPress fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0] );
switch_to_locale( 'en_US' );

$auth     = new \MindioMagicMCP\Auth();
$dependency_registry = new \MindioMagicMCP\Tool_Registry( $auth );
( new FMP_Admin_Plugin_Dependency_Fixture( $dependency_registry, 'missing_fixture', array( 'missing-fixture/missing.php' ), array( 'missing-fixture' ) ) )->register();
( new FMP_Admin_Plugin_Dependency_Fixture( $dependency_registry, 'installed_fixture', array( 'mindio-magic-mcp/mindio-magic-mcp.php' ), array( 'mindio-magic-mcp' ) ) )->register();
$dependency_catalog = array_column( $dependency_registry->catalog(), null, 'name' );
fmp_admin_assert( ! isset( $dependency_catalog['missing_fixture_read'] ), 'An integration tool was registered without its plugin dependency.' );
fmp_admin_assert( isset( $dependency_catalog['installed_fixture_read'] ), 'An installed plugin integration was not registered for policy management.' );

$dependency_admin = new \MindioMagicMCP\Admin(
	$auth,
	new \MindioMagicMCP\Audit_Log(),
	new \MindioMagicMCP\Webhook_Engine(),
	$dependency_registry
);
$_GET['tab'] = 'tools';
ob_start();
$dependency_admin->render();
$dependency_html = (string) ob_get_clean();
fmp_admin_assert( str_contains( $dependency_html, 'installed_fixture_read' ), 'Installed integration controls are missing from the tool manager.' );
fmp_admin_assert( ! str_contains( $dependency_html, 'missing_fixture_read' ), 'Missing integration controls leaked into the tool manager.' );

$registry = new \MindioMagicMCP\Tool_Registry( $auth );
$schema   = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
$callback = static fn(): array => array( 'ok' => true );
$registry->register( 'create_post', 'Create content.', $schema, $schema, $callback, \MindioMagicMCP\Auth::SCOPE_EDITOR, 'edit_posts' );
$registry->register( 'upload_media', 'Upload media.', $schema, $schema, $callback, \MindioMagicMCP\Auth::SCOPE_EDITOR, 'upload_files' );
$registry->register( 'get_server_status', 'Inspect server status.', $schema, $schema, $callback, \MindioMagicMCP\Auth::SCOPE_READ, 'read', array( 'readOnlyHint' => true ) );
$registry->register(
	'integration_read',
	'Inspect integration records.',
	array( 'type' => 'object', 'properties' => array( 'operation' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
	$schema,
	$callback,
	\MindioMagicMCP\Auth::SCOPE_READ,
	'read',
	array( 'readOnlyHint' => true ),
	array(
		'operations' => array(
			'list_records'  => array( 'label' => 'List records', 'description' => 'List safe integration records.', 'mode' => 'read', 'scope' => \MindioMagicMCP\Auth::SCOPE_READ ),
			'delete_record' => array( 'label' => 'Delete record', 'description' => 'Delete one integration record.', 'mode' => 'write', 'scope' => \MindioMagicMCP\Auth::SCOPE_ADMIN, 'destructive' => true ),
		),
	)
);

$original_exposure = get_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, null );
$original_operation_policy = get_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION, null );
try {
	$policy_summary = $registry->update_exposure( array( 'create_post', 'unknown_tool', 123 ) );
	$operation_summary = $registry->update_operation_exposure( array( 'integration_read:list_records', 'integration_read:unknown', 123 ) );
	$policy_catalog = array_column( $registry->catalog(), null, 'name' );
} finally {
	if ( null === $original_exposure ) {
		delete_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION );
	} else {
		update_option( \MindioMagicMCP\Tool_Registry::EXPOSURE_OPTION, $original_exposure, false );
	}
	if ( null === $original_operation_policy ) {
		delete_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION );
	} else {
		update_option( \MindioMagicMCP\Tool_Registry::OPERATION_POLICY_OPTION, $original_operation_policy, false );
	}
}
fmp_admin_assert( 1 === $policy_summary['exposed'] && 3 === $policy_summary['disabled'], 'Exposure updates did not retain only registered submitted tools.' );
fmp_admin_assert( 1 === $operation_summary['exposed'] && 1 === $operation_summary['disabled'], 'Operation exposure did not retain only registered submitted operations.' );
fmp_admin_assert( ! empty( $policy_catalog['create_post']['exposed'] ) && empty( $policy_catalog['upload_media']['exposed'] ), 'The persisted exposure state is not reflected in the registry catalog.' );
fmp_admin_assert( ! empty( $policy_catalog['integration_read']['operations'][0]['exposed'] ) && empty( $policy_catalog['integration_read']['operations'][1]['exposed'] ), 'The operation policy is not reflected in the registry catalog.' );

$admin = new \MindioMagicMCP\Admin(
	$auth,
	new \MindioMagicMCP\Audit_Log(),
	new \MindioMagicMCP\Webhook_Engine(),
	$registry
);

$tabs = array(
	'overview'    => 'System overview',
	'tools'       => 'Search tool name or description',
	'credentials' => 'Generate an API key',
	'webhooks'    => 'Add a webhook destination',
	'activity'    => 'Tool execution',
	'settings'    => 'Allowed browser origins',
);
$rendered_tabs = array();

foreach ( $tabs as $tab => $expected_copy ) {
	$_GET['tab'] = $tab;
	ob_start();
	$admin->render();
	$html = (string) ob_get_clean();
	$rendered_tabs[ $tab ] = $html;

	fmp_admin_assert( str_contains( $html, 'data-fmp-admin' ), 'Admin root is missing for tab: ' . $tab );
	fmp_admin_assert( str_contains( $html, 'aria-label="Mindio Magic MCP sections"' ), 'Accessible tab navigation is missing for tab: ' . $tab );
	fmp_admin_assert( 1 === substr_count( $html, 'aria-current="page"' ), 'Exactly one active tab was not rendered for tab: ' . $tab );
	fmp_admin_assert( str_contains( $html, $expected_copy ), 'Expected panel content is missing for tab: ' . $tab );
	fmp_admin_assert( ! str_contains( $html, ' style="' ), 'Inline styles leaked into the redesigned admin panel.' );
	fmp_admin_assert( ! str_contains( $html, ' onfocus="' ), 'Inline event handlers leaked into the redesigned admin panel.' );
}

$tools_html = $rendered_tabs['tools'];
fmp_admin_assert( str_contains( $tools_html, 'data-tool-manager' ), 'Tool exposure manager is missing.' );
fmp_admin_assert( str_contains( $tools_html, 'data-tool-enable-all' ) && str_contains( $tools_html, 'data-tool-disable-all' ), 'Tool bulk controls are missing.' );
fmp_admin_assert( 4 === substr_count( $tools_html, 'name="enabled_tools[]"' ), 'The tool manager did not render every registered tool.' );
fmp_admin_assert( 2 === substr_count( $tools_html, 'name="enabled_operations[]"' ), 'The tool manager did not render every registered integration operation.' );
fmp_admin_assert( str_contains( $tools_html, 'data-operation-disclosure' ) && str_contains( $tools_html, 'data-operation-enable-reads' ) && str_contains( $tools_html, 'data-operation-disable-writes' ), 'Granular operation controls are missing.' );
fmp_admin_assert( str_contains( $tools_html, 'Content and publishing' ) && str_contains( $tools_html, 'Media and SEO' ) && str_contains( $tools_html, 'Operations and diagnostics' ), 'Registered tools were not grouped by domain.' );
fmp_admin_assert( str_contains( $rendered_tabs['settings'], 'Read-only filesystem inspection' ), 'The filesystem read opt-in setting is missing.' );
fmp_admin_assert( str_contains( $rendered_tabs['settings'], 'Database schema inspection' ) && ! str_contains( $rendered_tabs['settings'], 'validated SELECT queries' ), 'The fixed-shape database inspection setting is missing.' );

$_GET['tab'] = 'unsupported';
ob_start();
$admin->render();
$fallback_html = (string) ob_get_clean();
fmp_admin_assert( str_contains( $fallback_html, 'System overview' ), 'Unsupported tabs do not fall back to Overview.' );

$_GET['tab'] = array( 'settings' );
ob_start();
$admin->render();
$array_fallback_html = (string) ob_get_clean();
fmp_admin_assert( str_contains( $array_fallback_html, 'System overview' ), 'Non-scalar tabs do not fall back to Overview.' );

$admin->enqueue_assets( 'settings_page_mindio-magic-mcp' );
fmp_admin_assert( wp_style_is( 'mindio-magic-mcp-admin', 'enqueued' ), 'Admin stylesheet was not enqueued.' );
fmp_admin_assert( wp_script_is( 'mindio-magic-mcp-admin', 'enqueued' ), 'Admin script was not enqueued.' );
fmp_admin_assert( is_file( MINDIO_MAGIC_MCP_DIR . 'assets/css/admin.css' ), 'Admin stylesheet is missing.' );
fmp_admin_assert( is_file( MINDIO_MAGIC_MCP_DIR . 'assets/js/admin.js' ), 'Admin script is missing.' );

unload_textdomain( 'mindio-magic-mcp' );
$development_catalog = dirname( __DIR__, 2 ) . '/languages/mindio-magic-mcp-fa_IR.mo';
fmp_admin_assert( load_textdomain( 'mindio-magic-mcp', $development_catalog ), 'Persian admin translations could not be loaded.' );
$_GET['tab'] = 'settings';
ob_start();
$admin->render();
$persian_html = (string) ob_get_clean();
fmp_admin_assert( str_contains( $persian_html, 'نمای کلی' ), 'Persian tab navigation is not translated.' );
fmp_admin_assert( str_contains( $persian_html, 'ذخیرهٔ تغییرات' ), 'Persian settings actions are not translated.' );

$_GET['tab'] = 'tools';
ob_start();
$admin->render();
$persian_tools_html = (string) ob_get_clean();
fmp_admin_assert( str_contains( $persian_tools_html, 'نمایش ابزارهای MCP' ), 'Persian tool governance UI is not translated.' );
fmp_admin_assert( str_contains( $persian_tools_html, 'ذخیرهٔ سیاست ابزارها' ), 'Persian tool policy action is not translated.' );

unset( $_GET['tab'] );

echo wp_json_encode(
	array(
		'ok'           => true,
		'tabs_checked' => count( $tabs ),
		'persian_ui'   => true,
		'assets'       => array( 'mindio-magic-mcp-admin' ),
	),
	JSON_UNESCAPED_SLASHES
) . PHP_EOL;
