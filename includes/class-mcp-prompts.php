<?php
/**
 * Site-specific MCP prompt templates.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MCP_Prompts {
	private Prompt_Registry $prompts;
	private ?Flatsome_Component_Catalog $catalog;

	public function __construct( Prompt_Registry $prompts, ?Flatsome_Component_Catalog $catalog = null ) {
		$this->prompts = $prompts;
		$this->catalog = $catalog;
	}

	public function register(): void {
		$this->prompts->register(
			'write_product_description',
			__( 'Write a product description', 'mindio-magic-mcp' ),
			__( 'Draft a product description in this site\'s brand voice, locale, and writing direction.', 'mindio-magic-mcp' ),
			array(
				array( 'name' => 'product', 'description' => __( 'Product name, or the numeric product ID to read from the store.', 'mindio-magic-mcp' ), 'required' => true ),
				array( 'name' => 'audience', 'description' => __( 'Optional target audience or buying context.', 'mindio-magic-mcp' ) ),
				array( 'name' => 'length', 'description' => __( 'Optional target length, for example "short" or "150 words".', 'mindio-magic-mcp' ) ),
			),
			array( $this, 'product_description' )
		);

		$this->prompts->register(
			'draft_blog_post',
			__( 'Draft a blog post', 'mindio-magic-mcp' ),
			__( 'Outline and draft a blog post that matches this site\'s existing categories and voice.', 'mindio-magic-mcp' ),
			array(
				array( 'name' => 'topic', 'description' => __( 'What the post should cover.', 'mindio-magic-mcp' ), 'required' => true ),
				array( 'name' => 'angle', 'description' => __( 'Optional editorial angle or key takeaway.', 'mindio-magic-mcp' ) ),
			),
			array( $this, 'blog_post' )
		);

		$this->prompts->register(
			'build_landing_page',
			__( 'Build a landing page', 'mindio-magic-mcp' ),
			__( 'Plan and build a landing page with the page builder this site actually runs.', 'mindio-magic-mcp' ),
			array(
				array( 'name' => 'goal', 'description' => __( 'The conversion goal of the page.', 'mindio-magic-mcp' ), 'required' => true ),
				array( 'name' => 'sections', 'description' => __( 'Optional comma-separated list of sections to include.', 'mindio-magic-mcp' ) ),
			),
			array( $this, 'landing_page' ),
			Auth::SCOPE_EDITOR
		);

		$this->prompts->register(
			'audit_post_seo',
			__( 'Audit a post for SEO', 'mindio-magic-mcp' ),
			__( 'Review one published entry against the SEO provider installed on this site.', 'mindio-magic-mcp' ),
			array(
				array( 'name' => 'post_id', 'description' => __( 'Numeric ID of the post to audit.', 'mindio-magic-mcp' ), 'required' => true ),
			),
			array( $this, 'seo_audit' )
		);

		$this->prompts->register(
			'triage_comments',
			__( 'Triage pending comments', 'mindio-magic-mcp' ),
			__( 'Review the moderation queue and recommend approve, spam, or trash for each comment.', 'mindio-magic-mcp' ),
			array(
				array( 'name' => 'limit', 'description' => __( 'Optional number of comments to review.', 'mindio-magic-mcp' ) ),
			),
			array( $this, 'comment_triage' )
		);
	}

	/** @param array<string,string> $arguments */
	public function product_description( array $arguments ): array {
		$product = $arguments['product'];
		$context = $this->site_context();

		if ( ctype_digit( $product ) && class_exists( 'WooCommerce' ) ) {
			$post = get_post( (int) $product );
			if ( $post instanceof \WP_Post && 'product' === $post->post_type ) {
				$context .= "\n\nExisting product record:\n" . wp_json_encode(
					array(
						'id'           => $post->ID,
						'name'         => $post->post_title,
						'short'        => $post->post_excerpt,
						'description'  => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 2000 ),
						'categories'   => wp_list_pluck( (array) get_the_terms( $post, 'product_cat' ) ?: array(), 'name' ),
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
			}
		}

		$instructions = array(
			sprintf(
				/* translators: %s: product name or identifier. */
				__( 'Write a product description for: %s.', 'mindio-magic-mcp' ),
				$product
			),
		);
		if ( '' !== $arguments['audience'] ) {
			$instructions[] = sprintf(
				/* translators: %s: target audience. */
				__( 'Target audience: %s.', 'mindio-magic-mcp' ),
				$arguments['audience']
			);
		}
		$instructions[] = '' !== $arguments['length']
			? sprintf(
				/* translators: %s: requested length. */
				__( 'Target length: %s.', 'mindio-magic-mcp' ),
				$arguments['length']
			)
			: __( 'Target length: roughly 120 words.', 'mindio-magic-mcp' );
		$instructions[] = __( 'Lead with the concrete benefit, keep claims verifiable, and end with one clear call to action. Return the description only, with no preamble.', 'mindio-magic-mcp' );

		return array(
			array( 'role' => 'user', 'text' => $context ),
			array( 'role' => 'user', 'text' => implode( "\n", $instructions ) ),
		);
	}

	/** @param array<string,string> $arguments */
	public function blog_post( array $arguments ): array {
		$categories = get_terms(
			array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 30, 'fields' => 'names' )
		);
		$context = $this->site_context();
		if ( is_array( $categories ) && $categories ) {
			$context .= "\n\n" . __( 'Existing categories: ', 'mindio-magic-mcp' ) . implode( ', ', $categories );
		}

		$instructions = array(
			sprintf(
				/* translators: %s: blog post topic. */
				__( 'Draft a blog post about: %s.', 'mindio-magic-mcp' ),
				$arguments['topic']
			),
		);
		if ( '' !== $arguments['angle'] ) {
			$instructions[] = sprintf(
				/* translators: %s: editorial angle. */
				__( 'Editorial angle: %s.', 'mindio-magic-mcp' ),
				$arguments['angle']
			);
		}
		$instructions[] = __( 'Propose a title, an outline, and the full body. Pick the closest existing category rather than inventing one. Create the entry as a draft with create_post, then report the resulting ID and edit link.', 'mindio-magic-mcp' );

		return array(
			array( 'role' => 'user', 'text' => $context ),
			array( 'role' => 'user', 'text' => implode( "\n", $instructions ) ),
		);
	}

	/** @param array<string,string> $arguments */
	public function landing_page( array $arguments ): array {
		$flatsome = $this->catalog instanceof Flatsome_Component_Catalog && $this->catalog->flatsome_active();
		$builder  = $flatsome
			? __( 'This site runs Flatsome, so build the page with the Flatsome tools and typed UX Builder components. Read mindio://flatsome/components first and use only components reported as available.', 'mindio-magic-mcp' )
			: __( 'This site does not run Flatsome, so build the page with the Gutenberg block tools and core blocks.', 'mindio-magic-mcp' );

		$instructions = array(
			sprintf(
				/* translators: %s: conversion goal. */
				__( 'Build a landing page whose goal is: %s.', 'mindio-magic-mcp' ),
				$arguments['goal']
			),
			$builder,
		);
		if ( '' !== $arguments['sections'] ) {
			$instructions[] = sprintf(
				/* translators: %s: comma-separated section list. */
				__( 'Include these sections in order: %s.', 'mindio-magic-mcp' ),
				$arguments['sections']
			);
		}
		$instructions[] = __( 'Create the page as a draft, never publish it directly, and report the render fallbacks so a human can review them.', 'mindio-magic-mcp' );

		return array(
			array( 'role' => 'user', 'text' => $this->site_context() ),
			array( 'role' => 'user', 'text' => implode( "\n", $instructions ) ),
		);
	}

	/** @param array<string,string> $arguments */
	public function seo_audit( array $arguments ): array {
		$provider = defined( 'WPSEO_VERSION' ) ? 'Yoast SEO' : ( class_exists( 'RankMath' ) ? 'Rank Math' : '' );
		$note     = '' !== $provider
			? sprintf(
				/* translators: %s: SEO plugin name. */
				__( 'This site uses %s, so read and write metadata through its provider tools.', 'mindio-magic-mcp' ),
				$provider
			)
			: __( 'No dedicated SEO plugin is active, so use the built-in SEO tools.', 'mindio-magic-mcp' );

		return array(
			array( 'role' => 'user', 'text' => $this->site_context() ),
			array(
				'role' => 'user',
				'text' => implode(
					"\n",
					array(
						sprintf(
							/* translators: %d: post ID. */
							__( 'Audit post %d for search visibility.', 'mindio-magic-mcp' ),
							absint( $arguments['post_id'] )
						),
						$note,
						__( 'Check the title length, meta description, heading structure, internal links, and image alternative text. Report findings first and apply fixes only after listing them.', 'mindio-magic-mcp' ),
					)
				),
			),
		);
	}

	/** @param array<string,string> $arguments */
	public function comment_triage( array $arguments ): array {
		$limit = max( 1, min( 100, absint( $arguments['limit'] ?: 20 ) ) );

		return array(
			array( 'role' => 'user', 'text' => $this->site_context() ),
			array(
				'role' => 'user',
				'text' => implode(
					"\n",
					array(
						sprintf(
							/* translators: %d: number of comments. */
							__( 'Review the %d oldest pending comments.', 'mindio-magic-mcp' ),
							$limit
						),
						__( 'For each one, recommend approve, spam, or trash with a one-line reason. Apply nothing until the full list has been presented and approved.', 'mindio-magic-mcp' ),
					)
				),
			),
		);
	}

	private function site_context(): string {
		$settings = get_option( 'mindio_magic_mcp_settings', array() );
		$voice    = trim( (string) ( $settings['brand_voice'] ?? '' ) );
		$theme    = wp_get_theme();

		$lines = array(
			sprintf(
				/* translators: 1: site name, 2: site URL. */
				__( 'You are working on the WordPress site "%1$s" at %2$s.', 'mindio-magic-mcp' ),
				get_bloginfo( 'name' ),
				home_url( '/' )
			),
			sprintf(
				/* translators: 1: locale, 2: text direction. */
				__( 'Write in the site locale %1$s using %2$s text direction.', 'mindio-magic-mcp' ),
				determine_locale(),
				is_rtl() ? 'RTL' : 'LTR'
			),
			sprintf(
				/* translators: %s: active theme name. */
				__( 'Active theme: %s.', 'mindio-magic-mcp' ),
				$theme->get( 'Name' )
			),
		);

		$tagline = get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$lines[] = sprintf(
				/* translators: %s: site tagline. */
				__( 'Site tagline: %s', 'mindio-magic-mcp' ),
				$tagline
			);
		}

		$lines[] = '' !== $voice
			? sprintf(
				/* translators: %s: configured brand voice. */
				__( 'Brand voice: %s', 'mindio-magic-mcp' ),
				$voice
			)
			: __( 'No brand voice is configured, so match the tone of the site\'s existing published content.', 'mindio-magic-mcp' );

		return implode( "\n", $lines );
	}
}
