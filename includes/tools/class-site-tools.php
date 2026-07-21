<?php
/**
 * Curated WordPress site settings tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'get_settings',
			__( 'Read a curated, non-secret set of WordPress general, reading, discussion, and permalink settings.', 'mindio-magic-mcp' ),
			array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			array( 'type' => 'object' ),
			array( $this, 'get_settings' ),
			Auth::SCOPE_READ,
			'manage_options',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'update_settings',
			__( 'Update only the allowlisted WordPress site settings. Permalink updates safely flush rewrite rules.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'settings' => array(
						'type'       => 'object',
						'properties' => array(
							'site_title'             => array( 'type' => 'string', 'maxLength' => 500 ),
							'tagline'                => array( 'type' => 'string', 'maxLength' => 1000 ),
							'timezone'               => array( 'type' => 'string', 'maxLength' => 100 ),
							'date_format'            => array( 'type' => 'string', 'maxLength' => 100 ),
							'time_format'            => array( 'type' => 'string', 'maxLength' => 100 ),
							'start_of_week'          => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 6 ),
							'posts_per_page'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
							'default_comment_status' => array( 'type' => 'string', 'enum' => array( 'open', 'closed' ) ),
							'permalink_structure'    => array( 'type' => 'string', 'maxLength' => 500 ),
						),
						'additionalProperties' => false,
					),
				),
				'required'             => array( 'settings' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'update_settings' ),
			Auth::SCOPE_ADMIN,
			'manage_options',
			array( 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed> */
	public function get_settings( array $args = array() ): array {
		unset( $args );
		return array(
			'site_title'             => get_option( 'blogname' ),
			'tagline'                => get_option( 'blogdescription' ),
			'site_url'               => site_url( '/' ),
			'home_url'               => home_url( '/' ),
			'timezone'               => wp_timezone_string(),
			'date_format'            => get_option( 'date_format' ),
			'time_format'            => get_option( 'time_format' ),
			'start_of_week'          => (int) get_option( 'start_of_week' ),
			'posts_per_page'         => (int) get_option( 'posts_per_page' ),
			'default_comment_status' => get_option( 'default_comment_status' ),
			'permalink_structure'    => get_option( 'permalink_structure' ),
			'locale'                 => get_locale(),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_settings( array $args ): array|\WP_Error {
		$settings = (array) $args['settings'];
		if ( empty( $settings ) ) {
			return new \WP_Error( 'empty_settings', __( 'Provide at least one setting to update.', 'mindio-magic-mcp' ) );
		}

		$normalized = $settings;
		foreach ( array( 'date_format', 'time_format' ) as $input ) {
			if ( array_key_exists( $input, $settings ) ) {
				$value = sanitize_text_field( (string) $settings[ $input ] );
				if ( preg_match( '/[<>\r\n]/', $value ) ) {
					return new \WP_Error( 'invalid_format', __( 'Date and time formats cannot contain markup or newlines.', 'mindio-magic-mcp' ) );
				}
				$normalized[ $input ] = $value;
			}
		}
		if ( array_key_exists( 'timezone', $settings ) ) {
			$timezone = sanitize_text_field( (string) $settings['timezone'] );
			if ( ! in_array( $timezone, timezone_identifiers_list(), true ) && ! preg_match( '/^[+-](?:0\d|1[0-4]):(?:00|15|30|45)$/', $timezone ) ) {
				return new \WP_Error( 'invalid_timezone', __( 'Use an IANA timezone such as Asia/Tehran or a valid UTC offset.', 'mindio-magic-mcp' ) );
			}
			$normalized['timezone'] = $timezone;
		}
		if ( array_key_exists( 'permalink_structure', $settings ) ) {
			$structure = trim( (string) $settings['permalink_structure'] );
			if ( '' !== $structure && ( ! str_starts_with( $structure, '/' ) || ! str_ends_with( $structure, '/' ) || preg_match( '/[^A-Za-z0-9%_\/-]/', $structure ) ) ) {
				return new \WP_Error( 'invalid_permalink', __( 'The permalink structure must start and end with / and contain only WordPress rewrite tokens.', 'mindio-magic-mcp' ) );
			}
			$normalized['permalink_structure'] = $structure;
		}

		$updated = array();
		$text    = array( 'site_title' => 'blogname', 'tagline' => 'blogdescription' );
		foreach ( $text as $input => $option ) {
			if ( array_key_exists( $input, $normalized ) ) {
				update_option( $option, sanitize_text_field( (string) $normalized[ $input ] ) );
				$updated[] = $input;
			}
		}
		foreach ( array( 'date_format', 'time_format' ) as $input ) {
			if ( array_key_exists( $input, $normalized ) ) {
				update_option( $input, $normalized[ $input ] );
				$updated[] = $input;
			}
		}
		foreach ( array( 'start_of_week', 'posts_per_page' ) as $input ) {
			if ( array_key_exists( $input, $normalized ) ) {
				update_option( $input, absint( $normalized[ $input ] ) );
				$updated[] = $input;
			}
		}
		if ( array_key_exists( 'default_comment_status', $normalized ) ) {
			update_option( 'default_comment_status', 'open' === $normalized['default_comment_status'] ? 'open' : 'closed' );
			$updated[] = 'default_comment_status';
		}
		if ( array_key_exists( 'timezone', $normalized ) ) {
			update_option( 'timezone_string', $normalized['timezone'] );
			$updated[] = 'timezone';
		}
		if ( array_key_exists( 'permalink_structure', $normalized ) ) {
			update_option( 'permalink_structure', $normalized['permalink_structure'] );
			flush_rewrite_rules( false );
			$updated[] = 'permalink_structure';
		}

		return array( 'updated' => $updated, 'settings' => $this->get_settings() );
	}
}
