<?php
/**
 * Opt-in, bounded, read-only filesystem inspection.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Filesystem_Tools {
	private const MAX_FILE_BYTES = 262144;
	private const TEXT_EXTENSIONS = array( 'php', 'css', 'scss', 'less', 'js', 'jsx', 'ts', 'tsx', 'json', 'html', 'htm', 'xml', 'svg', 'md', 'txt', 'po', 'pot', 'yml', 'yaml', 'twig' );

	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'read_file',
			__( 'Read a bounded UTF-8 text file from an allowlisted WordPress content root. Sensitive files and common secret assignments are redacted.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'root'       => $this->root_schema(),
					'path'       => $this->path_schema(),
					'start_line' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000000 ),
					'end_line'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000000 ),
				),
				'required'             => array( 'root', 'path' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'read_file' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'list_directory',
			__( 'List a bounded directory tree inside an allowlisted WordPress content root without following symbolic links.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'root'        => $this->root_schema(),
					'path'        => $this->path_schema( true ),
					'depth'       => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 3 ),
					'max_entries' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
				),
				'required'             => array( 'root' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_directory' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'search_files',
			__( 'Search bounded UTF-8 text files for a literal string inside an allowlisted WordPress content root.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'root'           => $this->root_schema(),
					'path'           => $this->path_schema( true ),
					'query'          => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 200 ),
					'extensions'     => array( 'type' => 'array', 'maxItems' => 20, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,10}$' ) ),
					'case_sensitive' => array( 'type' => 'boolean' ),
					'max_files'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
					'max_matches'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
				),
				'required'             => array( 'root', 'query' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'search_files' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function read_file( array $args ): array|\WP_Error {
		$enabled = $this->ensure_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$resolved = $this->resolve( (string) $args['root'], (string) $args['path'], 'file' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$allowed = $this->file_allowed( $resolved['absolute'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$size = filesize( $resolved['absolute'] );
		if ( false === $size || $size > self::MAX_FILE_BYTES ) {
			return new \WP_Error( 'file_too_large', __( 'Readable files are limited to 256 KB.', 'mindio-magic-mcp' ) );
		}
		$content = file_get_contents( $resolved['absolute'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded, opt-in, read-only inspection.
		if ( false === $content || str_contains( $content, "\0" ) || ! $this->is_valid_utf8( $content ) ) {
			return new \WP_Error( 'file_not_text', __( 'The selected file is not readable UTF-8 text.', 'mindio-magic-mcp' ) );
		}
		$lines      = preg_split( '/\R/u', $content ) ?: array();
		$total      = count( $lines );
		$start      = max( 1, (int) ( $args['start_line'] ?? 1 ) );
		$end        = min( $total, max( $start, (int) ( $args['end_line'] ?? min( $total, $start + 4999 ) ) ) );
		$truncated  = $start > 1 || $end < $total;
		$selection  = array_slice( $lines, $start - 1, max( 0, $end - $start + 1 ) );
		$content    = implode( "\n", $selection );
		$content    = $this->redact_secrets( $content );
		return array(
			'root'        => $args['root'],
			'path'        => $resolved['relative'],
			'size'        => $size,
			'start_line'  => $start,
			'end_line'    => $end,
			'total_lines' => $total,
			'truncated'   => $truncated,
			'redacted'    => true,
			'content'     => $content,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function list_directory( array $args ): array|\WP_Error {
		$enabled = $this->ensure_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$resolved = $this->resolve( (string) $args['root'], (string) ( $args['path'] ?? '' ), 'directory' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$depth       = min( 3, max( 0, (int) ( $args['depth'] ?? 1 ) ) );
		$max_entries = min( 500, max( 1, (int) ( $args['max_entries'] ?? 200 ) ) );
		$entries     = array();
		$this->walk_directory( $resolved['absolute'], $resolved['base'], $depth, $max_entries, $entries );
		return array(
			'root'       => $args['root'],
			'path'       => $resolved['relative'],
			'entries'    => $entries,
			'count'      => count( $entries ),
			'truncated'  => count( $entries ) >= $max_entries,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function search_files( array $args ): array|\WP_Error {
		$enabled = $this->ensure_enabled();
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		$resolved = $this->resolve( (string) $args['root'], (string) ( $args['path'] ?? '' ), 'directory' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$query       = (string) $args['query'];
		$case        = ! empty( $args['case_sensitive'] );
		$max_files   = min( 500, max( 1, (int) ( $args['max_files'] ?? 200 ) ) );
		$max_matches = min( 200, max( 1, (int) ( $args['max_matches'] ?? 100 ) ) );
		$extensions  = isset( $args['extensions'] ) ? array_values( array_intersect( self::TEXT_EXTENSIONS, array_map( static fn( $ext ): string => strtolower( (string) $ext ), (array) $args['extensions'] ) ) ) : self::TEXT_EXTENSIONS;
		if ( ! $extensions ) {
			return new \WP_Error( 'no_allowed_extensions', __( 'No requested file extensions are in the text-file allowlist.', 'mindio-magic-mcp' ) );
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $resolved['absolute'], \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		$files   = 0;
		$matches = array();
		$started = microtime( true );
		foreach ( $iterator as $file ) {
			if ( $files >= $max_files || count( $matches ) >= $max_matches || microtime( true ) - $started > 2.0 ) {
				break;
			}
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink() || ! in_array( strtolower( $file->getExtension() ), $extensions, true ) || $file->getSize() > self::MAX_FILE_BYTES ) {
				continue;
			}
			$path_check = $this->file_allowed( $file->getRealPath() ?: '' );
			if ( is_wp_error( $path_check ) ) {
				continue;
			}
			++$files;
			$content = file_get_contents( $file->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded read-only search.
			if ( false === $content || str_contains( $content, "\0" ) || ! $this->is_valid_utf8( $content ) ) {
				continue;
			}
			foreach ( preg_split( '/\R/u', $content ) ?: array() as $index => $line ) {
				$found = $case ? false !== strpos( $line, $query ) : false !== stripos( $line, $query );
				if ( ! $found ) {
					continue;
				}
				$relative  = ltrim( str_replace( '\\', '/', substr( $file->getRealPath(), strlen( $resolved['base'] ) ) ), '/' );
				$matches[] = array( 'path' => $relative, 'line' => $index + 1, 'excerpt' => substr( $this->redact_secrets( trim( $line ) ), 0, 500 ) );
				if ( count( $matches ) >= $max_matches ) {
					break 2;
				}
			}
		}
		return array(
			'root'            => $args['root'],
			'path'            => $resolved['relative'],
			'query'           => $query,
			'files_searched'  => $files,
			'matches'         => $matches,
			'match_count'     => count( $matches ),
			'truncated'       => $files >= $max_files || count( $matches ) >= $max_matches || microtime( true ) - $started > 2.0,
			'redacted'        => true,
		);
	}

	/** @return bool|\WP_Error */
	private function ensure_enabled(): bool|\WP_Error {
		$settings = get_option( 'flatsome_mcp_settings', array() );
		return ! empty( $settings['allow_filesystem_read'] )
			? true
			: new \WP_Error( 'filesystem_read_disabled', __( 'Read-only filesystem tools are disabled in Mindio Magic MCP settings.', 'mindio-magic-mcp' ) );
	}

	/** @return array{base:string,absolute:string,relative:string}|\WP_Error */
	private function resolve( string $root, string $path, string $type ): array|\WP_Error {
		$roots = $this->roots();
		if ( ! isset( $roots[ $root ] ) ) {
			return new \WP_Error( 'filesystem_root_forbidden', __( 'The requested filesystem root is not allowlisted.', 'mindio-magic-mcp' ) );
		}
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( str_contains( $path, "\0" ) || str_starts_with( $path, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return new \WP_Error( 'invalid_file_path', __( 'Filesystem paths must be safe relative paths without traversal.', 'mindio-magic-mcp' ) );
		}
		$base      = realpath( $roots[ $root ] );
		$candidate = realpath( $base . ( '' === $path ? '' : DIRECTORY_SEPARATOR . $path ) );
		if ( ! $base || ! $candidate || ( $candidate !== $base && ! str_starts_with( $candidate, $base . DIRECTORY_SEPARATOR ) ) ) {
			return new \WP_Error( 'file_not_found', __( 'The requested path does not exist inside the selected root.', 'mindio-magic-mcp' ) );
		}
		if ( ( 'file' === $type && ! is_file( $candidate ) ) || ( 'directory' === $type && ! is_dir( $candidate ) ) ) {
			return new \WP_Error( 'file_type_mismatch', __( 'The requested path is not the required file or directory type.', 'mindio-magic-mcp' ) );
		}
		return array( 'base' => $base, 'absolute' => $candidate, 'relative' => ltrim( str_replace( '\\', '/', substr( $candidate, strlen( $base ) ) ), '/' ) );
	}

	/** @return array<string,string> */
	private function roots(): array {
		$uploads = wp_upload_dir();
		return array(
			'active_theme' => get_stylesheet_directory(),
			'parent_theme' => get_template_directory(),
			'plugins'      => WP_PLUGIN_DIR,
			'uploads'      => (string) $uploads['basedir'],
			'wp_content'   => WP_CONTENT_DIR,
		);
	}

	/** @return bool|\WP_Error */
	private function file_allowed( string $path ): bool|\WP_Error {
		$name = strtolower( basename( $path ) );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( str_starts_with( $name, '.' ) || preg_match( '/(?:wp-config|\.env|auth\.json|credentials|id_rsa|id_ed25519|debug\.log|error_log|\.sql(?:\.|$))/i', $name ) || ! in_array( $ext, self::TEXT_EXTENSIONS, true ) ) {
			return new \WP_Error( 'sensitive_file_forbidden', __( 'The selected file type or sensitive filename is not readable through MCP.', 'mindio-magic-mcp' ) );
		}
		return true;
	}

	/** @param array<int,array<string,mixed>> $entries */
	private function walk_directory( string $directory, string $base, int $depth, int $max_entries, array &$entries, int $level = 0 ): void {
		if ( count( $entries ) >= $max_entries ) {
			return;
		}
		$items = scandir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- bounded read-only directory listing.
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $name ) {
			if ( '.' === $name || '..' === $name || count( $entries ) >= $max_entries ) {
				continue;
			}
			$absolute = $directory . DIRECTORY_SEPARATOR . $name;
			$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $base ) ) ), '/' );
			$link     = is_link( $absolute );
			$is_dir   = is_dir( $absolute );
			$entries[] = array(
				'path'      => $relative,
				'name'      => $name,
				'type'      => $link ? 'symlink' : ( $is_dir ? 'directory' : 'file' ),
				'size'      => ! $is_dir && ! $link ? (int) ( filesize( $absolute ) ?: 0 ) : null,
				'modified'  => gmdate( DATE_ATOM, (int) ( filemtime( $absolute ) ?: 0 ) ),
				'readable'  => ! $link && ( $is_dir || ! is_wp_error( $this->file_allowed( $absolute ) ) ),
			);
			if ( $is_dir && ! $link && $level < $depth ) {
				$real = realpath( $absolute );
				if ( $real && str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
					$this->walk_directory( $real, $base, $depth, $max_entries, $entries, $level + 1 );
				}
			}
		}
	}

	private function redact_secrets( string $content ): string {
		$patterns = array(
			'/\b(password|passwd|secret|client_secret|consumer_secret|access_token|authorization|api[_-]?key)\b(\s*[=:>]\s*)["\']?[^"\'\s,;]+/i',
			'/\b(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)\b([^\r\n]*)/i',
		);
		foreach ( $patterns as $pattern ) {
			$content = preg_replace( $pattern, '$1$2[REDACTED]', $content ) ?? $content;
		}
		return $content;
	}

	private function is_valid_utf8( string $content ): bool {
		return 1 === preg_match( '//u', $content );
	}

	/** @return array<string,mixed> */
	private function root_schema(): array {
		return array( 'type' => 'string', 'enum' => array( 'active_theme', 'parent_theme', 'plugins', 'uploads', 'wp_content' ) );
	}

	/** @return array<string,mixed> */
	private function path_schema( bool $allow_empty = false ): array {
		return array( 'type' => 'string', 'minLength' => $allow_empty ? 0 : 1, 'maxLength' => 1000, 'pattern' => '^[^\\x00]*$' );
	}
}
