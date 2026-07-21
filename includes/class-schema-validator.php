<?php
/**
 * Small, fail-closed JSON Schema validator for tool inputs.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema_Validator {
	/**
	 * Validate a decoded JSON value against the schema subset used by tools.
	 *
	 * @return true|\WP_Error
	 */
	public function validate( mixed $value, array $schema, string $path = '$' ): bool|\WP_Error {
		if ( isset( $schema['oneOf'] ) || isset( $schema['anyOf'] ) ) {
			$variants = (array) ( $schema['oneOf'] ?? $schema['anyOf'] );
			$valid    = 0;
			foreach ( $variants as $variant ) {
				if ( true === $this->validate( $value, (array) $variant, $path ) ) {
					++$valid;
				}
			}
			$required = isset( $schema['oneOf'] ) ? 1 : 1;
			if ( $valid < $required || ( isset( $schema['oneOf'] ) && 1 !== $valid ) ) {
				return $this->error( $path, __( 'does not match an allowed schema', 'mindio-magic-mcp' ) );
			}
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, (array) $schema['enum'], true ) ) {
			return $this->error( $path, __( 'contains a value outside the allowed set', 'mindio-magic-mcp' ) );
		}

		$types = isset( $schema['type'] ) ? (array) $schema['type'] : array();
		if ( $types && ! $this->matches_any_type( $value, $types ) ) {
			return $this->error(
				$path,
				sprintf(
					/* translators: %s: comma-separated JSON types. */
					__( 'must be of type %s', 'mindio-magic-mcp' ),
					implode( '|', array_map( 'strval', $types ) )
				)
			);
		}

		if ( is_string( $value ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
				return $this->error( $path, __( 'is shorter than allowed', 'mindio-magic-mcp' ) );
			}
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
				return $this->error( $path, __( 'is longer than allowed', 'mindio-magic-mcp' ) );
			}
			if ( isset( $schema['pattern'] ) && 1 !== preg_match( '/' . str_replace( '/', '\\/', (string) $schema['pattern'] ) . '/u', $value ) ) {
				return $this->error( $path, __( 'has an invalid format', 'mindio-magic-mcp' ) );
			}
			if ( 'uri' === ( $schema['format'] ?? '' ) && false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return $this->error( $path, __( 'must be a valid URL', 'mindio-magic-mcp' ) );
			}
			if ( 'date-time' === ( $schema['format'] ?? '' ) && false === strtotime( $value ) ) {
				return $this->error( $path, __( 'must be a valid date-time', 'mindio-magic-mcp' ) );
			}
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				return $this->error( $path, __( 'is below the minimum', 'mindio-magic-mcp' ) );
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				return $this->error( $path, __( 'is above the maximum', 'mindio-magic-mcp' ) );
			}
		}

		if ( is_array( $value ) && 'array' === ( $schema['type'] ?? null ) ) {
			$count = count( $value );
			if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
				return $this->error( $path, __( 'contains too few items', 'mindio-magic-mcp' ) );
			}
			if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
				return $this->error( $path, __( 'contains too many items', 'mindio-magic-mcp' ) );
			}
			if ( ! empty( $schema['uniqueItems'] ) && count( array_unique( array_map( 'serialize', $value ) ) ) !== $count ) {
				return $this->error( $path, __( 'contains duplicate items', 'mindio-magic-mcp' ) );
			}
			if ( isset( $schema['items'] ) ) {
				foreach ( $value as $index => $item ) {
					$result = $this->validate( $item, (array) $schema['items'], $path . '[' . $index . ']' );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
			}
		}

		if ( is_array( $value ) && 'object' === ( $schema['type'] ?? null ) ) {
			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				if ( ! array_key_exists( (string) $required, $value ) ) {
					return $this->error( $path . '.' . $required, __( 'is required', 'mindio-magic-mcp' ) );
				}
			}

			$properties = (array) ( $schema['properties'] ?? array() );
			foreach ( $value as $key => $item ) {
				if ( isset( $properties[ $key ] ) ) {
					$result = $this->validate( $item, (array) $properties[ $key ], $path . '.' . $key );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return $this->error( $path . '.' . $key, __( 'is not an allowed property', 'mindio-magic-mcp' ) );
				}
			}
		}

		return true;
	}

	private function matches_any_type( mixed $value, array $types ): bool {
		foreach ( $types as $type ) {
			$matches = match ( $type ) {
				'object'  => is_array( $value ) && ( array() === $value || ! $this->is_list( $value ) ),
				'array'   => is_array( $value ) && ( array() === $value || $this->is_list( $value ) ),
				'string'  => is_string( $value ),
				'integer' => is_int( $value ),
				'number'  => is_int( $value ) || is_float( $value ),
				'boolean' => is_bool( $value ),
				'null'    => null === $value,
				default   => false,
			};
			if ( $matches ) {
				return true;
			}
		}
		return false;
	}

	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function error( string $path, string $message ): \WP_Error {
		return new \WP_Error(
			'invalid_arguments',
			sprintf(
				/* translators: 1: JSON path, 2: validation error. */
				__( '%1$s %2$s.', 'mindio-magic-mcp' ),
				$path,
				$message
			),
			array( 'path' => $path )
		);
	}
}
