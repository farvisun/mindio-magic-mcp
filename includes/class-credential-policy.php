<?php
/**
 * Per-credential tool allowances and daily call budgets.
 *
 * Site-wide tool exposure decides what the server offers at all. A credential
 * policy narrows that further for one API key or OAuth client, so a single
 * installation can host several agents with different reach.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Credential_Policy {
	public const MAX_PATTERNS = 100;
	public const MAX_BUDGET   = 1000000;

	/** @var array<int,string> */
	private array $allow;

	/** @var array<int,string> */
	private array $deny;

	private int $daily_budget;

	/**
	 * @param array<int,string> $allow Tool names or `prefix_*` patterns. Empty means every exposed tool.
	 * @param array<int,string> $deny  Tool names or patterns evaluated after allow.
	 * @param int               $daily_budget Calls per UTC day, or 0 for unlimited.
	 */
	public function __construct( array $allow = array(), array $deny = array(), int $daily_budget = 0 ) {
		$this->allow        = $allow;
		$this->deny         = $deny;
		$this->daily_budget = $daily_budget;
	}

	/**
	 * Build a policy from a stored token record.
	 *
	 * @param array<string,mixed> $record
	 */
	public static function from_record( array $record ): self {
		$policy = (array) ( $record['policy'] ?? array() );

		return new self(
			self::sanitize_patterns( (array) ( $policy['allow'] ?? array() ) ),
			self::sanitize_patterns( (array) ( $policy['deny'] ?? array() ) ),
			self::sanitize_budget( $policy['daily_budget'] ?? 0 )
		);
	}

	/**
	 * Normalize administrator input into a storable policy array.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		return array(
			'allow'        => self::sanitize_patterns( (array) ( $input['allow'] ?? array() ) ),
			'deny'         => self::sanitize_patterns( (array) ( $input['deny'] ?? array() ) ),
			'daily_budget' => self::sanitize_budget( $input['daily_budget'] ?? 0 ),
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'allow'        => $this->allow,
			'deny'         => $this->deny,
			'daily_budget' => $this->daily_budget,
		);
	}

	public function is_unrestricted(): bool {
		return ! $this->allow && ! $this->deny && 0 === $this->daily_budget;
	}

	public function daily_budget(): int {
		return $this->daily_budget;
	}

	/**
	 * An empty allow list permits everything the site already exposes.
	 * Deny always wins over allow.
	 */
	public function allows_tool( string $tool ): bool {
		if ( $this->matches( $tool, $this->deny ) ) {
			return false;
		}
		if ( ! $this->allow ) {
			return true;
		}

		return $this->matches( $tool, $this->allow );
	}

	/**
	 * Consume one unit of the daily budget.
	 *
	 * @return array{allowed:bool,limit:int,used:int,remaining:int,resets_at:string}
	 */
	public function consume( string $token_id ): array {
		$day       = gmdate( 'Y-m-d' );
		$resets_at = gmdate( DATE_ATOM, strtotime( $day . ' +1 day UTC' ) ?: time() );

		if ( 0 === $this->daily_budget || '' === $token_id ) {
			return array( 'allowed' => true, 'limit' => 0, 'used' => 0, 'remaining' => 0, 'resets_at' => $resets_at );
		}

		$key  = self::budget_key( $token_id, $day );
		$used = (int) get_transient( $key );
		++$used;
		set_transient( $key, $used, 2 * DAY_IN_SECONDS );

		return array(
			'allowed'   => $used <= $this->daily_budget,
			'limit'     => $this->daily_budget,
			'used'      => $used,
			'remaining' => max( 0, $this->daily_budget - $used ),
			'resets_at' => $resets_at,
		);
	}

	/**
	 * Read budget usage without consuming any.
	 *
	 * @return array{limit:int,used:int,remaining:int,resets_at:string}
	 */
	public function usage( string $token_id ): array {
		$day  = gmdate( 'Y-m-d' );
		$used = '' === $token_id ? 0 : (int) get_transient( self::budget_key( $token_id, $day ) );

		return array(
			'limit'     => $this->daily_budget,
			'used'      => $used,
			'remaining' => 0 === $this->daily_budget ? 0 : max( 0, $this->daily_budget - $used ),
			'resets_at' => gmdate( DATE_ATOM, strtotime( $day . ' +1 day UTC' ) ?: time() ),
		);
	}

	private static function budget_key( string $token_id, string $day ): string {
		return 'mindio_magic_mcp_budget_' . md5( $token_id . '|' . $day );
	}

	/** @param array<int,string> $patterns */
	private function matches( string $tool, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( $pattern === $tool ) {
				return true;
			}
			if ( str_ends_with( $pattern, '*' ) && str_starts_with( $tool, substr( $pattern, 0, -1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,mixed> $patterns
	 * @return array<int,string>
	 */
	private static function sanitize_patterns( array $patterns ): array {
		$clean = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$pattern = strtolower( trim( $pattern ) );
			if ( '' === $pattern || ! preg_match( '/^[a-z][a-z0-9_]{0,63}\*?$/', $pattern ) ) {
				continue;
			}
			$clean[] = $pattern;
			if ( count( $clean ) >= self::MAX_PATTERNS ) {
				break;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private static function sanitize_budget( mixed $value ): int {
		return max( 0, min( self::MAX_BUDGET, absint( $value ) ) );
	}
}
