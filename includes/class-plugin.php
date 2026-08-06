<?php
/**
 * Plugin composition root.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Installer::maybe_upgrade();

		$auth        = new Auth();
		$rate_limiter = new Rate_Limiter();
		$audit       = new Audit_Log();
		$registry    = new Tool_Registry( $auth );
		$resources   = new Resource_Registry( $auth );
		$prompts     = new Prompt_Registry( $auth );
		$changesets  = new Changeset();
		$webhooks    = new Webhook_Engine();

		( new Content_Tools( $registry ) )->register();
		( new Gutenberg_Tools( $registry ) )->register();
		( new Media_Tools( $registry ) )->register();
		( new SEO_Tools( $registry ) )->register();
		( new SEO_Provider_Tools( $registry, 'yoast' ) )->register();
		( new SEO_Provider_Tools( $registry, 'rank_math' ) )->register();
		( new Site_Tools( $registry ) )->register();
		( new Comment_Tools( $registry ) )->register();
		( new User_Tools( $registry ) )->register();
		( new Search_Tools( $registry ) )->register();
		( new Automation_Tools( $registry ) )->register();
		( new Extension_Tools( $registry ) )->register();
		( new Theme_Tools( $registry ) )->register();
		( new ACF_Tools( $registry ) )->register();
		( new Contact_Form_7_Tools( $registry ) )->register();
		( new BetterDocs_Tools( $registry ) )->register();
		( new WooCommerce_Tools( $registry ) )->register();
		( new WooCommerce_Operation_Tools( $registry ) )->register();
		( new Multisite_Tools( $registry ) )->register();
		( new Developer_Tools( $registry ) )->register();
		( new Filesystem_Tools( $registry ) )->register();
		( new Performance_Tools( $registry ) )->register();
		( new Webhook_Tools( $registry, $webhooks ) )->register();
		$flatsome_catalog  = new Flatsome_Component_Catalog();
		$flatsome_renderer = new Flatsome_Renderer( $flatsome_catalog );
		( new Flatsome_Tools( $registry, $flatsome_renderer, $flatsome_catalog ) )->register();

		$builders = new Page_Builder_Registry();
		$builders->add( new Flatsome_Builder( $flatsome_renderer, $flatsome_catalog ) );
		$builders->add( new Elementor_Builder() );
		$builders->add( new Gutenberg_Builder() );
		( new Builder_Tools( $registry, $builders ) )->register();
		( new Page_Analysis_Tools( $registry, $builders ) )->register();
		( new Changeset_Tools( $registry, $changesets, $auth ) )->register();
		( new System_Tools( $registry, $audit, $webhooks, $auth ) )->register();

		( new MCP_Resources( $resources, $flatsome_catalog ) )->register();
		( new MCP_Prompts( $prompts, $flatsome_catalog ) )->register();

		( new MCP_Server( $registry, $auth, $rate_limiter, $audit, $resources, $prompts ) )->register_hooks();
		( new OAuth_Server( $auth, $rate_limiter ) )->register_hooks();
		$webhooks->register_hooks();

		if ( is_admin() ) {
			( new Admin( $auth, $audit, $webhooks, $registry ) )->register_hooks();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'mindio_magic_mcp_cleanup_logs', array( Installer::class, 'cleanup_logs' ) );
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( Installer::class, 'initialize_site' ), 100 );
		}
	}

	public function enqueue_frontend_assets(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		// The `fmp-rtl` class is frozen: it is stored in the content of every
		// RTL page generated so far, so renaming it would unstyle them.
		if ( $post && str_contains( $post->post_content, 'fmp-rtl' ) ) {
			wp_enqueue_style( 'mindio-magic-mcp-frontend', MINDIO_MAGIC_MCP_URL . 'assets/css/frontend.css', array(), MINDIO_MAGIC_MCP_VERSION );
		}
	}

	private function __construct() {}
}
