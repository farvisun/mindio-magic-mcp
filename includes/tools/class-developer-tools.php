<?php
/**
 * Bounded developer and diagnostic tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Developer_Tools {
	private const WP_CLI_COMMANDS = array(
		'cache flush',
		'rewrite flush',
		'cron event run --due-now',
		'transient delete --expired',
	);

	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'run_wp_cli',
			__( 'Run one allowlisted in-process WP-CLI maintenance command. No shell is spawned; the setting must be enabled and WP_CLI must already be loaded.', 'mindio-magic-mcp' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'command' => array( 'type' => 'string', 'enum' => self::WP_CLI_COMMANDS ) ),
				'required'             => array( 'command' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'run_wp_cli' ),
			Auth::SCOPE_ADMIN,
			'manage_options'
		);
		$this->registry->register(
			'run_safe_query',
			__( 'Run one bounded read-only SELECT, SHOW, DESCRIBE, or EXPLAIN query. Sensitive credential tables, comments, stacked statements, file access, locks, and timing functions are denied.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'sql'   => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 20000 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
				),
				'required'             => array( 'sql' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'run_safe_query' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true )
		);
		$this->registry->register(
			'list_database_tables',
			__( 'List non-sensitive tables for the current WordPress site with bounded storage metadata. Requires read-only SQL to be enabled.', 'mindio-magic-mcp' ),
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			array( 'type' => 'object' ),
			array( $this, 'list_database_tables' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'describe_database_table',
			__( 'Describe columns and indexes for one non-sensitive current-site table. No table rows are returned.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array( 'table' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_]{1,100}$' ) ),
				'required'             => array( 'table' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'describe_database_table' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'clear_cache',
			__( 'Clear WordPress object cache and detected page-cache plugins, or clean the cache for one post.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'clear_cache' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'get_error_logs',
			__( 'Read the tail of WordPress debug.log when WP_DEBUG_LOG is enabled. Common secret assignments are redacted.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array( 'lines' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ) ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'get_error_logs' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function run_wp_cli( array $args ): array|\WP_Error {
		$settings = get_option( 'flatsome_mcp_settings', array() );
		if ( empty( $settings['allow_wp_cli'] ) ) {
			return new \WP_Error( 'wp_cli_disabled', __( 'WP-CLI tools are disabled in Mindio Magic MCP settings.', 'mindio-magic-mcp' ) );
		}
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return new \WP_Error( 'wp_cli_unavailable', __( 'WP_CLI is not loaded in this process. Use the local WP-CLI MCP transport for this tool.', 'mindio-magic-mcp' ) );
		}
		$command = (string) $args['command'];
		if ( ! in_array( $command, self::WP_CLI_COMMANDS, true ) ) {
			return new \WP_Error( 'wp_cli_command_forbidden', __( 'The WP-CLI command is not allowlisted.', 'mindio-magic-mcp' ) );
		}
		try {
			$result = \WP_CLI::runcommand( $command, array( 'return' => 'all', 'exit_error' => false, 'launch' => false ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wp_cli_failed', $error->getMessage() );
		}
		return array(
			'command'     => $command,
			'return_code' => is_object( $result ) ? (int) ( $result->return_code ?? 0 ) : 0,
			'stdout'      => is_object( $result ) ? (string) ( $result->stdout ?? '' ) : (string) $result,
			'stderr'      => is_object( $result ) ? (string) ( $result->stderr ?? '' ) : '',
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function run_safe_query( array $args ): array|\WP_Error {
		global $wpdb;
		$settings = get_option( 'flatsome_mcp_settings', array() );
		if ( empty( $settings['allow_safe_query'] ) ) {
			return new \WP_Error( 'safe_query_disabled', __( 'Read-only SQL is disabled in Mindio Magic MCP settings.', 'mindio-magic-mcp' ) );
		}
		$sql   = trim( (string) $args['sql'] );
		$limit = max( 1, min( 500, absint( $args['limit'] ?? 200 ) ) );
		$check = $this->validate_query( $sql );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( preg_match( '/^SELECT\b/i', $sql ) ) {
			$sql = $this->cap_select_limit( $sql, $limit );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fail-closed read-only validator above; this is the explicitly opt-in SQL tool and each request must return current data.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'query_failed', $wpdb->last_error );
		}
		$bounded = $this->bound_rows( array_slice( (array) $rows, 0, $limit ) );
		return array( 'row_count' => count( $bounded['rows'] ), 'limit' => $limit, 'truncated' => $bounded['truncated'], 'rows' => $bounded['rows'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function list_database_tables( array $args = array() ): array|\WP_Error {
		unset( $args );
		$enabled = $this->ensure_safe_query_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		global $wpdb;
		$allowed = $this->safe_database_tables();
		$like    = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in inspection must return the current database state.
		$tables  = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) ( $row['Name'] ?? '' );
			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}
			$tables[] = array(
				'table'       => $allowed[ $name ],
				'rows'        => max( 0, (int) ( $row['Rows'] ?? 0 ) ),
				'data_bytes'  => max( 0, (int) ( $row['Data_length'] ?? 0 ) ),
				'index_bytes' => max( 0, (int) ( $row['Index_length'] ?? 0 ) ),
				'engine'      => sanitize_text_field( (string) ( $row['Engine'] ?? '' ) ),
				'collation'   => sanitize_text_field( (string) ( $row['Collation'] ?? '' ) ),
			);
		}
		return array( 'prefix_redacted' => true, 'tables' => $tables, 'count' => count( $tables ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function describe_database_table( array $args ): array|\WP_Error {
		$enabled = $this->ensure_safe_query_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		global $wpdb;
		$logical = sanitize_key( (string) $args['table'] );
		$physical = array_search( $logical, $this->safe_database_tables(), true );
		if ( false === $physical ) {
			return new \WP_Error( 'database_table_forbidden', __( 'The requested table is missing or classified as sensitive.', 'mindio-magic-mcp' ) );
		}
		$columns = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $physical ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in inspection must return current schema data.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $physical ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit opt-in inspection must return current schema data.
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'database_describe_failed', $wpdb->last_error );
		}
		return array(
			'table'   => $logical,
			'columns' => array_map(
				static fn( array $column ): array => array(
					'name'    => (string) ( $column['Field'] ?? '' ),
					'type'    => (string) ( $column['Type'] ?? '' ),
					'nullable'=> 'YES' === ( $column['Null'] ?? '' ),
					'key'     => (string) ( $column['Key'] ?? '' ),
					'default' => $column['Default'] ?? null,
					'extra'   => (string) ( $column['Extra'] ?? '' ),
				),
				(array) $columns
			),
			'indexes' => array_map(
				static fn( array $index ): array => array(
					'name'     => (string) ( $index['Key_name'] ?? '' ),
					'column'   => (string) ( $index['Column_name'] ?? '' ),
					'sequence' => (int) ( $index['Seq_in_index'] ?? 0 ),
					'unique'   => 0 === (int) ( $index['Non_unique'] ?? 1 ),
				),
				(array) $indexes
			),
		);
	}

	/** @return array<string,mixed> */
	public function clear_cache( array $args ): array {
		$post_id   = absint( $args['post_id'] ?? 0 );
		$providers = array( 'wordpress_object_cache' );
		if ( $post_id ) {
			clean_post_cache( $post_id );
		} else {
			wp_cache_flush();
		}
		if ( function_exists( 'rocket_clean_post' ) && $post_id ) {
			rocket_clean_post( $post_id );
			$providers[] = 'wp_rocket';
		} elseif ( function_exists( 'rocket_clean_domain' ) && ! $post_id ) {
			rocket_clean_domain();
			$providers[] = 'wp_rocket';
		}
		if ( function_exists( 'w3tc_flush_post' ) && $post_id ) {
			w3tc_flush_post( $post_id );
			$providers[] = 'w3_total_cache';
		} elseif ( function_exists( 'w3tc_flush_all' ) && ! $post_id ) {
			w3tc_flush_all();
			$providers[] = 'w3_total_cache';
		}
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- LiteSpeed Cache owns and documents these integration hooks.
			do_action( $post_id ? 'litespeed_purge_post' : 'litespeed_purge_all', $post_id ?: null );
			$providers[] = 'litespeed';
		}
		do_action( 'flatsome_mcp_cache_cleared', $post_id );
		return array( 'cleared' => true, 'post_id' => $post_id, 'providers' => array_values( array_unique( $providers ) ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_error_logs( array $args ): array|\WP_Error {
		$limit = max( 1, min( 500, absint( $args['lines'] ?? 100 ) ) );
		$path  = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$path = WP_DEBUG_LOG;
		}
		$real_content = realpath( WP_CONTENT_DIR );
		$real_path    = realpath( $path );
		if ( ! $real_path || ! $real_content || ! str_starts_with( $real_path, $real_content . DIRECTORY_SEPARATOR ) ) {
			return array( 'enabled' => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG, 'lines' => array(), 'message' => __( 'No readable debug.log exists inside wp-content.', 'mindio-magic-mcp' ) );
		}
		$size   = filesize( $real_path );
		$length = min( 262144, $size ?: 0 );
		$handle = fopen( $real_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bounded read-only log tail.
		if ( ! $handle ) {
			return new \WP_Error( 'log_unreadable', __( 'The debug log could not be opened.', 'mindio-magic-mcp' ) );
		}
		if ( $size > $length ) {
			fseek( $handle, -$length, SEEK_END );
		}
		$data = (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- bounded read-only log tail.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = preg_split( '/\R/', $data ) ?: array();
		if ( $size > $length ) {
			array_shift( $lines );
		}
		$lines = array_slice( $lines, -$limit );
		$lines = array_map(
			static fn( string $line ): string => preg_replace( '/\b(password|secret|token|authorization|api[_-]?key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $line ) ?? $line,
			$lines
		);
		return array( 'enabled' => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG, 'file' => basename( $real_path ), 'line_count' => count( $lines ), 'lines' => $lines );
	}

	/** @return true|\WP_Error */
	private function validate_query( string $sql ): bool|\WP_Error {
		global $wpdb;
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) {
			return new \WP_Error( 'query_not_read_only', __( 'Only SELECT, SHOW, DESCRIBE, and EXPLAIN are allowed.', 'mindio-magic-mcp' ) );
		}
		if ( preg_match( '/(;|--|#|\/\*|\*\/)/', $sql ) ) {
			return new \WP_Error( 'query_syntax_forbidden', __( 'Comments and statement separators are not allowed.', 'mindio-magic-mcp' ) );
		}
		if ( preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|GRANT|REVOKE|CALL|HANDLER|DO|LOCK|UNLOCK|INTO\s+(?:OUTFILE|DUMPFILE)|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK)\b/i', $sql ) ) {
			return new \WP_Error( 'query_operation_forbidden', __( 'The query contains a forbidden operation or function.', 'mindio-magic-mcp' ) );
		}
		if ( preg_match( '/\b(PROCESSLIST|SHOW\s+(?:GRANTS|CREATE\s+USER|ENGINE)|FOR\s+SHARE|PROCEDURE\s+ANALYSE)\b/i', $sql ) ) {
			return new \WP_Error( 'query_introspection_forbidden', __( 'The query requests forbidden server, grant, process, or locking information.', 'mindio-magic-mcp' ) );
		}
		if ( preg_match( '/\b(INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|MYSQL\.|SYS\.|@@|CURRENT_USER|SESSION_USER|SYSTEM_USER|VERSION\s*\(|DATABASE\s*\()/i', $sql ) ) {
			return new \WP_Error( 'query_server_metadata_forbidden', __( 'Server schemas, identities, variables, and version metadata cannot be queried.', 'mindio-magic-mcp' ) );
		}
		if ( preg_match( '/^SHOW\b/i', $sql ) && ! preg_match( '/^SHOW\s+(?:COLUMNS|FIELDS|INDEX|INDEXES|KEYS)\s+(?:FROM|IN)\s+/i', $sql ) ) {
			return new \WP_Error( 'query_show_forbidden', __( 'Use the table-list tool; raw SHOW is limited to columns and indexes of safe tables.', 'mindio-magic-mcp' ) );
		}
		$sensitive = array( $wpdb->users, $wpdb->usermeta, $wpdb->options );
		foreach ( $sensitive as $table ) {
			if ( preg_match( '/(?:`|\b)' . preg_quote( $table, '/' ) . '(?:`|\b)/i', $sql ) ) {
				return new \WP_Error( 'sensitive_table_forbidden', __( 'Credential-bearing users, usermeta, and options tables cannot be queried.', 'mindio-magic-mcp' ) );
			}
		}
		if ( preg_match( '/\b(?:FROM|JOIN)\s*\(/i', $sql ) ) {
			return new \WP_Error( 'query_subquery_forbidden', __( 'Derived-table subqueries are not supported by the safe SQL validator.', 'mindio-magic-mcp' ) );
		}
		preg_match_all( '/\b(?:FROM|JOIN)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches );
		$table_names = (array) ( $matches[1] ?? array() );
		if ( preg_match( '/^(?:DESCRIBE|DESC)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $describe_match ) ) {
			$table_names[] = $describe_match[1];
		}
		if ( preg_match( '/^SHOW\s+(?:COLUMNS|FIELDS|INDEX|INDEXES|KEYS)\s+(?:FROM|IN)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $show_match ) ) {
			$table_names[] = $show_match[1];
		}
		$table_names = array_values( array_unique( $table_names ) );
		if ( preg_match( '/^(?:SELECT|EXPLAIN)\b/i', $sql ) && ! $table_names ) {
			return new \WP_Error( 'query_table_required', __( 'Safe SELECT and EXPLAIN queries must reference an allowed current-site table.', 'mindio-magic-mcp' ) );
		}
		$allowed = $this->safe_database_tables();
		foreach ( $table_names as $table ) {
			if ( ! isset( $allowed[ $table ] ) ) {
				return new \WP_Error( 'database_table_forbidden', __( 'The query references a missing, cross-site, or sensitive table.', 'mindio-magic-mcp' ) );
			}
		}
		if ( in_array( $wpdb->postmeta, $table_names, true ) && preg_match( '/\bmeta_value\b/i', $sql ) ) {
			return new \WP_Error( 'postmeta_values_forbidden', __( 'Raw postmeta values may contain credentials and cannot be selected.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	private function cap_select_limit( string $sql, int $limit ): string {
		$pattern = '/\s+LIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+(\d+))?\s*$/i';
		if ( ! preg_match( $pattern, $sql, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $sql . ' LIMIT ' . $limit;
		}
		$first     = (int) $matches[1][0];
		$has_comma = isset( $matches[2][0] ) && '' !== $matches[2][0];
		$has_offset = isset( $matches[3][0] ) && '' !== $matches[3][0];
		$row_count = $has_comma ? (int) $matches[2][0] : $first;
		$offset    = $has_comma ? $first : ( $has_offset ? (int) $matches[3][0] : 0 );
		$replacement = ' LIMIT ' . min( $limit, max( 0, $row_count ) );
		if ( $offset > 0 ) {
			$replacement .= ' OFFSET ' . $offset;
		}
		return substr( $sql, 0, (int) $matches[0][1] ) . $replacement;
	}

	/** @return array{rows:array<int,array<string,mixed>>,truncated:bool} */
	private function bound_rows( array $rows ): array {
		$max_total = 2 * MB_IN_BYTES;
		$max_cell  = 64 * KB_IN_BYTES;
		$total     = 0;
		$output    = array();
		$truncated = false;
		foreach ( $rows as $row ) {
			if ( isset( $row['meta_key'], $row['meta_value'] ) && preg_match( '/(?:password|passwd|secret|token|authorization|api[_-]?key|private[_-]?key|license|purchase)/i', (string) $row['meta_key'] ) ) {
				$row['meta_value'] = '[REDACTED]'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- This is result redaction, not a query constraint.
			}
			$bounded_row = array();
			foreach ( (array) $row as $key => $value ) {
				if ( is_string( $value ) ) {
					$value = wp_check_invalid_utf8( $value, true );
					$remaining = max( 0, $max_total - $total );
					$allowed   = min( $max_cell, $remaining );
					if ( strlen( $value ) > $allowed ) {
						$value = function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $allowed, 'UTF-8' ) : wp_check_invalid_utf8( substr( $value, 0, $allowed ), true );
						$value .= '…';
						$truncated = true;
					}
					$total += strlen( $value );
				}
				$bounded_row[ (string) $key ] = $value;
			}
			$output[] = $bounded_row;
			if ( $total >= $max_total ) {
				$truncated = true;
				break;
			}
		}
		return array( 'rows' => $output, 'truncated' => $truncated );
	}

	/** @return bool|\WP_Error */
	private function ensure_safe_query_enabled(): bool|\WP_Error {
		$settings = get_option( 'flatsome_mcp_settings', array() );
		return ! empty( $settings['allow_safe_query'] )
			? true
			: new \WP_Error( 'safe_query_disabled', __( 'Read-only SQL is disabled in Mindio Magic MCP settings.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,string> Physical table => prefix-redacted logical name. */
	private function safe_database_tables(): array {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Server-derived allowlist for explicit inspection must reflect current tables.
		$safe   = array();
		foreach ( (array) $tables as $table ) {
			$table = (string) $table;
			if ( ! str_starts_with( $table, $wpdb->prefix ) ) {
				continue;
			}
			$logical = substr( $table, strlen( $wpdb->prefix ) );
			if ( ! $logical || preg_match( '/^(?:options|users|usermeta|comments|commentmeta|woocommerce_api_keys|woocommerce_sessions|woocommerce_payment_tokens|woocommerce_payment_tokenmeta|wc_webhooks|wc_orders|wc_order_|wc_customer_|actionscheduler_|flatsome_mcp_)/i', $logical ) ) {
				continue;
			}
			$safe[ $table ] = $logical;
		}
		return $safe;
	}
}
