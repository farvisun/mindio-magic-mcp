<?php
/**
 * Registry of available page-building surfaces.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Page_Builder_Registry {
	/** @var array<string,Page_Builder> */
	private array $builders = array();

	public function add( Page_Builder $builder ): void {
		$this->builders[ $builder->id() ] = $builder;
	}

	public function get( string $id ): ?Page_Builder {
		return $this->builders[ $id ] ?? null;
	}

	/**
	 * Builders usable on this site right now.
	 *
	 * @return array<string,Page_Builder>
	 */
	public function available(): array {
		return array_filter(
			$this->builders,
			static fn( Page_Builder $builder ): bool => $builder->is_available()
		);
	}

	/**
	 * The builder a page should use when the caller does not name one.
	 *
	 * A site-specific builder wins over core blocks, which are always present.
	 */
	public function preferred(): Page_Builder {
		foreach ( $this->available() as $builder ) {
			if ( 'gutenberg' !== $builder->id() ) {
				return $builder;
			}
		}

		return $this->builders['gutenberg'];
	}

	/**
	 * Identify which builder produced an existing page.
	 */
	public function detect( \WP_Post $post ): Page_Builder {
		foreach ( $this->available() as $builder ) {
			if ( 'gutenberg' !== $builder->id() && $builder->owns_post( $post ) ) {
				return $builder;
			}
		}
		$gutenberg = $this->builders['gutenberg'];

		return $gutenberg->owns_post( $post ) ? $gutenberg : $this->preferred();
	}

	/**
	 * Resolve a caller-supplied builder id, where `auto` means the preferred one.
	 *
	 * @return Page_Builder|\WP_Error
	 */
	public function resolve( string $requested ) {
		if ( '' === $requested || 'auto' === $requested ) {
			return $this->preferred();
		}

		$builder = $this->get( $requested );
		if ( ! $builder ) {
			return new \WP_Error( 'unknown_builder', __( 'Unknown page builder.', 'mindio-magic-mcp' ) );
		}
		if ( ! $builder->is_available() ) {
			return new \WP_Error(
				'builder_unavailable',
				sprintf(
					/* translators: %s: page builder name. */
					__( '%s is not active on this site.', 'mindio-magic-mcp' ),
					$builder->label()
				)
			);
		}

		return $builder;
	}

	/**
	 * Machine-readable inventory for discovery tools and resources.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function catalog(): array {
		$preferred = $this->preferred()->id();
		$catalog   = array();

		foreach ( $this->builders as $builder ) {
			$catalog[] = array(
				'id'        => $builder->id(),
				'label'     => $builder->label(),
				'available' => $builder->is_available(),
				'preferred' => $builder->id() === $preferred,
				'elements'  => $builder->supported_elements(),
			);
		}

		return $catalog;
	}

	/** @return array<int,string> */
	public function ids(): array {
		return array_keys( $this->builders );
	}
}
