<?php
/**
 * Transaction-backed preview of write tools.
 *
 * A dry run executes the real tool callback inside a database transaction,
 * records every entity it touches through Change_Recorder, then rolls the
 * transaction back and reports the diff. Effects that would escape the
 * transaction are suppressed for the duration of the call.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dry_Run {
	private const SUPPORT_TRANSIENT = 'mindio_magic_mcp_dry_run_supported';
	private const MAX_ENTRIES       = 500;

	private static bool $active = false;

	/**
	 * True while a dry run is executing, so side-effecting subsystems can stand down.
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Transactions only roll back reliably on a transactional storage engine.
	 */
	public static function is_supported(): bool {
		global $wpdb;

		$cached = get_transient( self::SUPPORT_TRANSIENT );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Engine lookup is cached in a transient immediately below.
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$wpdb->posts
			)
		);

		$supported = is_string( $engine ) && 'innodb' === strtolower( $engine );
		set_transient( self::SUPPORT_TRANSIENT, $supported ? '1' : '0', DAY_IN_SECONDS );

		return $supported;
	}

	/**
	 * Execute a callback, capture what it would change, and roll it back.
	 *
	 * @param callable $callback Receives no arguments and returns the tool result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( callable $callback ) {
		global $wpdb;

		if ( self::$active ) {
			return new \WP_Error( 'dry_run_nested', __( 'A dry run cannot be started inside another dry run.', 'mindio-magic-mcp' ) );
		}
		if ( ! self::is_supported() ) {
			return new \WP_Error( 'dry_run_unsupported', __( 'Dry runs require a transactional database storage engine such as InnoDB.', 'mindio-magic-mcp' ) );
		}

		$recorder     = new Change_Recorder();
		self::$active = true;
		$recorder->start( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control cannot use the caching helpers.
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );

		try {
			$result  = call_user_func( $callback );
			$entries = $recorder->entries();
		} finally {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->query( 'SET autocommit = 1' );
			$recorder->stop();
			$recorder->invalidate_caches();
			self::$active = false;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$truncated = $recorder->truncated();
		$changes   = $recorder->summarize( $entries );
		foreach ( $changes as $kind => $group ) {
			if ( is_array( $group ) && count( $group ) > self::MAX_ENTRIES ) {
				$changes[ $kind ] = array_slice( $group, 0, self::MAX_ENTRIES );
				$truncated        = true;
			}
		}

		return array(
			'dry_run'    => true,
			'applied'    => false,
			'changes'    => $changes,
			'suppressed' => $recorder->suppressed(),
			'truncated'  => $truncated,
			'result'     => is_array( $result ) ? $result : array( 'result' => $result ),
		);
	}
}
