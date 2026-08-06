<?php
/**
 * Progress reporting for long-running tool calls.
 *
 * Tools call `Progress_Reporter::report()` as they work. When the client asked
 * for a stream and supplied a progress token, those reports become MCP
 * `notifications/progress` messages; otherwise they cost nothing.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Progress_Reporter {
	/** @var callable|null */
	private static $listener = null;

	private static string $token = '';

	/**
	 * Route progress to a listener for the duration of one streamed call.
	 */
	public static function listen( string $token, callable $listener ): void {
		self::$token    = $token;
		self::$listener = $listener;
	}

	public static function stop(): void {
		self::$token    = '';
		self::$listener = null;
	}

	public static function is_active(): bool {
		return null !== self::$listener;
	}

	public static function token(): string {
		return self::$token;
	}

	/**
	 * Report progress from inside a tool callback.
	 *
	 * @param float      $progress Work completed so far.
	 * @param float|null $total    Total work, when known.
	 */
	public static function report( float $progress, ?float $total = null, string $message = '' ): void {
		if ( null === self::$listener ) {
			return;
		}

		call_user_func( self::$listener, $progress, $total, $message );
	}
}
