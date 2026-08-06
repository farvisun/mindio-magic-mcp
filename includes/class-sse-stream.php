<?php
/**
 * Server-sent event writer for the MCP Streamable HTTP transport.
 *
 * The writer is injectable so the event sequence can be asserted in tests
 * without taking over the PHP output buffer.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SSE_Stream {
	/** @var callable */
	private $writer;

	private int $event_id = 0;

	/**
	 * @param callable|null $writer Receives each raw SSE frame. Defaults to echoing and flushing.
	 */
	public function __construct( ?callable $writer = null ) {
		$this->writer = $writer ?? static function ( string $frame ): void {
			echo $frame; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE frames are JSON-encoded above and must not be HTML-escaped.
			flush();
		};
	}

	/**
	 * Emit the headers a streamed response requires.
	 */
	public static function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'Cache-Control: no-store, no-transform' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );
		header( 'MCP-Protocol-Version: ' . MCP_Server::protocol_version() );
	}

	/**
	 * Stop PHP and the web server from holding the response back.
	 */
	public static function disable_buffering(): void {
		ignore_user_abort( true );
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	/**
	 * Write one JSON-RPC message as an SSE `message` event.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function send( array $payload ): void {
		++$this->event_id;
		$json = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$frame = 'id: ' . $this->event_id . "\n"
			. "event: message\n"
			. 'data: ' . $json . "\n\n";

		call_user_func( $this->writer, $frame );
	}

	/**
	 * Write an SSE comment, used as a keep-alive that clients ignore.
	 */
	public function comment( string $text = '' ): void {
		call_user_func( $this->writer, ': ' . $text . "\n\n" );
	}

	public function event_count(): int {
		return $this->event_id;
	}
}
