<?php
/**
 * Contract every page-building surface implements.
 *
 * A builder converts one neutral blueprint into whatever the target editor
 * stores, and reads a built page back into the same neutral shape. Callers work
 * against the blueprint, not against Flatsome shortcodes, block comments, or
 * Elementor JSON.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Page_Builder {
	/**
	 * Stable machine identifier, for example `flatsome`.
	 */
	public function id(): string;

	/**
	 * Human-readable name shown to agents and administrators.
	 */
	public function label(): string;

	/**
	 * Whether this builder can be used on the current site right now.
	 */
	public function is_available(): bool;

	/**
	 * Neutral element types this builder can render natively.
	 *
	 * @return array<int,string>
	 */
	public function supported_elements(): array;

	/**
	 * Whether a post appears to have been built with this builder.
	 */
	public function owns_post( \WP_Post $post ): bool;

	/**
	 * Convert a neutral blueprint into stored post content.
	 *
	 * The returned `content` is written to post_content. Anything that must live
	 * outside post_content is returned in `meta` as key/value pairs.
	 *
	 * @param array<string,mixed> $blueprint
	 * @return array{content:string,meta:array<string,mixed>,report:array<string,mixed>}|\WP_Error
	 */
	public function render( array $blueprint, string $direction );

	/**
	 * Read a built page back into a neutral outline.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function outline( \WP_Post $post );
}
