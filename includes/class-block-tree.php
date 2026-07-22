<?php
/**
 * Pure Gutenberg block-tree parsing and mutation helpers.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Block_Tree {
	public const MAX_DEPTH = 40;
	public const MAX_NODES = 2000;

	/** @return array<int,array<string,mixed>> */
	public static function parse( string $markup ): array {
		return function_exists( 'parse_blocks' ) ? (array) parse_blocks( $markup ) : array();
	}

	/** @param array<int,array<string,mixed>> $tree */
	public static function serialize( array $tree ): string {
		if ( function_exists( 'serialize_blocks' ) ) {
			return serialize_blocks( $tree );
		}
		return implode( '', array_map( 'serialize_block', $tree ) );
	}

	/**
	 * Return a bounded, agent-friendly representation of a block tree.
	 *
	 * @param array<int,array<string,mixed>> $tree
	 * @return array<int,array<string,mixed>>
	 */
	public static function summarize( array $tree, ?int $max_depth = null, array $prefix = array(), int $depth = 1 ): array {
		$rows      = array();
		$max_depth = null === $max_depth ? self::MAX_DEPTH : max( 1, min( self::MAX_DEPTH, $max_depth ) );
		foreach ( $tree as $index => $block ) {
			$path    = array_merge( $prefix, array( (int) $index ) );
			$name    = (string) ( $block['blockName'] ?? '' );
			$html    = (string) ( $block['innerHTML'] ?? '' );
			$preview = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) ?? '' );
			if ( function_exists( 'mb_substr' ) ) {
				$preview = mb_substr( $preview, 0, 240 );
			} else {
				$preview = substr( $preview, 0, 240 );
			}
			$row = array(
				'path'        => $path,
				'name'        => '' !== $name ? $name : 'core/freeform',
				'attributes'  => self::bound_value( (array) ( $block['attrs'] ?? array() ) ),
				'preview'     => $preview,
				'inner_count' => count( (array) ( $block['innerBlocks'] ?? array() ) ),
			);
			if ( $depth < $max_depth && ! empty( $block['innerBlocks'] ) ) {
				$row['inner_blocks'] = self::summarize( (array) $block['innerBlocks'], $max_depth, $path, $depth + 1 );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** @param array<int,array<string,mixed>> $tree */
	public static function at( array $tree, array $path ): ?array {
		if ( empty( $path ) || count( $path ) > self::MAX_DEPTH ) {
			return null;
		}
		$cursor = $tree;
		$node   = null;
		foreach ( $path as $index ) {
			$index = (int) $index;
			if ( $index < 0 || ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
				return null;
			}
			$node   = $cursor[ $index ];
			$cursor = (array) ( $node['innerBlocks'] ?? array() );
		}
		return $node;
	}

	/**
	 * Insert one or more blocks and return the path of the first inserted block.
	 *
	 * @param array<int,array<string,mixed>> $tree
	 * @param array<int,array<string,mixed>> $blocks
	 * @return array<int,int>|\WP_Error
	 */
	public static function insert( array &$tree, array $blocks, array $position ) {
		$mode = (string) ( $position['mode'] ?? 'append' );
		$path = array_map( 'intval', (array) ( $position['path'] ?? array() ) );
		if ( ! in_array( $mode, array( 'append', 'prepend', 'before', 'after', 'inside' ), true ) ) {
			return new \WP_Error( 'invalid_block_position', __( 'The requested block position is invalid.', 'mindio-magic-mcp' ) );
		}
		if ( empty( $blocks ) ) {
			return new \WP_Error( 'empty_block_set', __( 'At least one parsed block is required.', 'mindio-magic-mcp' ) );
		}

		if ( 'append' === $mode || 'prepend' === $mode ) {
			$index = 'prepend' === $mode ? 0 : count( $tree );
			array_splice( $tree, $index, 0, $blocks );
			return array( $index );
		}

		if ( empty( $path ) || null === self::at( $tree, $path ) ) {
			return new \WP_Error( 'block_path_not_found', __( 'The block path no longer resolves. Read the current block tree and try again.', 'mindio-magic-mcp' ) );
		}
		if ( 'inside' === $mode ) {
			$ok        = true;
			$container =& self::inner_container( $tree, $path, $ok );
			if ( ! $ok ) {
				return new \WP_Error( 'block_path_not_found', __( 'The block path no longer resolves. Read the current block tree and try again.', 'mindio-magic-mcp' ) );
			}
			$index = count( $container );
			array_splice( $container, $index, 0, $blocks );
			return array_merge( $path, array( $index ) );
		}

		$parent = $path;
		$index  = (int) array_pop( $parent );
		$ok     = true;
		$container =& self::container( $tree, $parent, $ok );
		if ( ! $ok ) {
			return new \WP_Error( 'block_path_not_found', __( 'The block path no longer resolves. Read the current block tree and try again.', 'mindio-magic-mcp' ) );
		}
		$insert_at = 'after' === $mode ? $index + 1 : $index;
		array_splice( $container, $insert_at, 0, $blocks );
		return array_merge( $parent, array( $insert_at ) );
	}

	/** @param array<int,array<string,mixed>> $replacement */
	public static function replace( array &$tree, array $path, array $replacement ): bool {
		$parent = $path;
		$index  = (int) array_pop( $parent );
		$ok     = ! empty( $path );
		$container =& self::container( $tree, $parent, $ok );
		if ( ! $ok || ! isset( $container[ $index ] ) ) {
			return false;
		}
		array_splice( $container, $index, 1, $replacement );
		return true;
	}

	/** @return array<string,mixed>|null */
	public static function remove( array &$tree, array $path ): ?array {
		$parent = $path;
		$index  = (int) array_pop( $parent );
		$ok     = ! empty( $path );
		$container =& self::container( $tree, $parent, $ok );
		if ( ! $ok || ! isset( $container[ $index ] ) ) {
			return null;
		}
		$removed = array_splice( $container, $index, 1 );
		return isset( $removed[0] ) && is_array( $removed[0] ) ? $removed[0] : null;
	}

	/** @return array<int,int>|\WP_Error */
	public static function move( array &$tree, array $from, array $position ) {
		$target = array_map( 'intval', (array) ( $position['path'] ?? array() ) );
		$mode   = (string) ( $position['mode'] ?? 'append' );
		if ( self::is_prefix( $from, $target ) && count( $target ) >= count( $from ) ) {
			return new \WP_Error( 'invalid_block_move', __( 'A block cannot be moved into itself or one of its descendants.', 'mindio-magic-mcp' ) );
		}
		$node = self::remove( $tree, $from );
		if ( null === $node ) {
			return new \WP_Error( 'block_path_not_found', __( 'The source block path no longer resolves.', 'mindio-magic-mcp' ) );
		}
		if ( ! empty( $target ) ) {
			$target = self::adjust_path_after_removal( $target, $from );
		}
		$result = self::insert( $tree, array( $node ), array( 'mode' => $mode, 'path' => $target ) );
		if ( is_wp_error( $result ) ) {
			// Restore the source tree when a target becomes invalid.
			self::insert( $tree, array( $node ), array( 'mode' => 'before', 'path' => self::restore_anchor( $tree, $from ) ) );
			return $result;
		}
		return $result;
	}

	/** @return array<int,int>|\WP_Error */
	public static function duplicate( array &$tree, array $path ) {
		$node = self::at( $tree, $path );
		if ( null === $node ) {
			return new \WP_Error( 'block_path_not_found', __( 'The block path no longer resolves.', 'mindio-magic-mcp' ) );
		}
		return self::insert( $tree, array( $node ), array( 'mode' => 'after', 'path' => $path ) );
	}

	/** @param array<int,array<string,mixed>> $tree */
	public static function validate_limits( array $tree ): bool|\WP_Error {
		$nodes = 0;
		$depth = 0;
		self::measure( $tree, 1, $nodes, $depth );
		if ( $nodes > self::MAX_NODES ) {
			return new \WP_Error( 'block_tree_too_large', __( 'The block tree exceeds the maximum number of blocks.', 'mindio-magic-mcp' ) );
		}
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error( 'block_tree_too_deep', __( 'The block tree exceeds the maximum nesting depth.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	/** @param array<int,array<string,mixed>> $tree */
	public static function validate_registered( array $tree ): bool|\WP_Error {
		$registry = class_exists( '\WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance() : null;
		foreach ( $tree as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					return new \WP_Error( 'raw_html_not_block', __( 'Raw HTML must be wrapped in an explicit Gutenberg block.', 'mindio-magic-mcp' ) );
				}
				continue;
			}
			if ( $registry && ! $registry->is_registered( $name ) ) {
				return new \WP_Error(
					'block_type_unavailable',
					sprintf(
						/* translators: %s: Gutenberg block type name. */
						__( 'The Gutenberg block type %s is not registered on this site.', 'mindio-magic-mcp' ),
						$name
					)
				);
			}
			$result = self::validate_registered( (array) ( $block['innerBlocks'] ?? array() ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/** @return array<int,int> */
	private static function adjust_path_after_removal( array $target, array $source ): array {
		$parent = $source;
		$index  = (int) array_pop( $parent );
		if ( count( $target ) > count( $parent ) && self::is_prefix( $parent, $target ) ) {
			$position = count( $parent );
			if ( (int) $target[ $position ] > $index ) {
				$target[ $position ] = (int) $target[ $position ] - 1;
			}
		}
		return $target;
	}

	/** @return array<int,int> */
	private static function restore_anchor( array $tree, array $path ): array {
		$parent = $path;
		$index  = (int) array_pop( $parent );
		$ok     = true;
		$container =& self::container( $tree, $parent, $ok );
		if ( ! $ok || empty( $container ) ) {
			return array();
		}
		return array_merge( $parent, array( min( $index, count( $container ) - 1 ) ) );
	}

	/** @return array<int,array<string,mixed>> */
	private static function &container( array &$tree, array $parent, bool &$ok ): array {
		$cursor =& $tree;
		foreach ( $parent as $index ) {
			$index = (int) $index;
			if ( $index < 0 || ! isset( $cursor[ $index ] ) || ! is_array( $cursor[ $index ] ) ) {
				$ok = false;
				return $cursor;
			}
			if ( ! isset( $cursor[ $index ]['innerBlocks'] ) || ! is_array( $cursor[ $index ]['innerBlocks'] ) ) {
				$cursor[ $index ]['innerBlocks'] = array();
			}
			$cursor =& $cursor[ $index ]['innerBlocks'];
		}
		return $cursor;
	}

	/** @return array<int,array<string,mixed>> */
	private static function &inner_container( array &$tree, array $path, bool &$ok ): array {
		$parent = $path;
		$index  = (int) array_pop( $parent );
		$container =& self::container( $tree, $parent, $ok );
		if ( ! $ok || ! isset( $container[ $index ] ) || ! is_array( $container[ $index ] ) ) {
			$ok = false;
			return $container;
		}
		if ( ! isset( $container[ $index ]['innerBlocks'] ) || ! is_array( $container[ $index ]['innerBlocks'] ) ) {
			$container[ $index ]['innerBlocks'] = array();
		}
		return $container[ $index ]['innerBlocks'];
	}

	private static function is_prefix( array $prefix, array $path ): bool {
		if ( count( $prefix ) > count( $path ) ) {
			return false;
		}
		foreach ( $prefix as $index => $value ) {
			if ( (int) $value !== (int) $path[ $index ] ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<int,array<string,mixed>> $tree */
	private static function measure( array $tree, int $level, int &$nodes, int &$depth ): void {
		$depth = max( $depth, $level );
		foreach ( $tree as $block ) {
			++$nodes;
			if ( $nodes > self::MAX_NODES || $level > self::MAX_DEPTH ) {
				return;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::measure( (array) $block['innerBlocks'], $level + 1, $nodes, $depth );
			}
		}
	}

	private static function bound_value( mixed $value, int $depth = 0 ): mixed {
		if ( $depth >= 8 ) {
			return '[depth-limited]';
		}
		if ( is_string( $value ) ) {
			return strlen( $value ) > 2000 ? substr( $value, 0, 2000 ) . '…' : $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 100, true ) as $key => $child ) {
				$out[ $key ] = self::bound_value( $child, $depth + 1 );
			}
			return $out;
		}
		return $value;
	}
}
