<?php
/**
 * Advanced Custom Fields Free integration.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ACF_Tools extends Integration_Dispatcher {
	public function __construct( Tool_Registry $registry ) {
		parent::__construct( $registry, 'acf', 'Advanced Custom Fields Free' );
	}

	public function register(): void {
		$selector = array( 'oneOf' => array( array( 'type' => 'integer', 'minimum' => 1 ), array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 100 ) ) );
		$confirm  = array( 'confirm' => array( 'type' => 'boolean' ) );
		$operations = array(
			'list_field_groups' => $this->operation(
				'read',
				__( 'List field groups', 'mindio-magic-mcp' ),
				__( 'List ACF field groups and their field summaries.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'post_type' => array( 'type' => 'string', 'maxLength' => 50 ),
						'active'    => array( 'type' => 'boolean' ),
					)
				),
				array( $this, 'list_field_groups' ),
				'manage_options'
			),
			'get_field_group' => $this->operation(
				'read',
				__( 'Get field group', 'mindio-magic-mcp' ),
				__( 'Get one ACF field group and its complete free-field definitions.', 'mindio-magic-mcp' ),
				$this->object_schema( array( 'selector' => $selector ), array( 'selector' ) ),
				array( $this, 'get_field_group' ),
				'manage_options'
			),
			'get_field_value' => $this->operation(
				'read',
				__( 'Get field value', 'mindio-magic-mcp' ),
				__( 'Read one ACF value from a post using a field name or key.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
						'field'        => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
						'format_value' => array( 'type' => 'boolean' ),
					),
					array( 'post_id', 'field' )
				),
				array( $this, 'get_field_value' ),
				array( $this, 'can_read_value' )
			),
			'list_post_types' => $this->operation(
				'read',
				__( 'List ACF post types', 'mindio-magic-mcp' ),
				__( 'List custom post types registered through the ACF Free UI.', 'mindio-magic-mcp' ),
				$this->empty_schema(),
				array( $this, 'list_post_types' ),
				'manage_options'
			),
			'list_taxonomies' => $this->operation(
				'read',
				__( 'List ACF taxonomies', 'mindio-magic-mcp' ),
				__( 'List taxonomies registered through the ACF Free UI.', 'mindio-magic-mcp' ),
				$this->empty_schema(),
				array( $this, 'list_taxonomies' ),
				'manage_options'
			),
			'save_field_group' => $this->operation(
				'write',
				__( 'Create or update field group', 'mindio-magic-mcp' ),
				__( 'Create or update a sanitized ACF field group. Pro-only structures are not accepted.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'group' => array( 'type' => 'object' ) ) ), array( 'group', 'confirm' ) ),
				array( $this, 'save_field_group' ),
				'manage_options'
			),
			'delete_field_group' => $this->operation(
				'write',
				__( 'Delete field group', 'mindio-magic-mcp' ),
				__( 'Permanently delete an ACF field group after explicit confirmation.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'selector' => $selector ) ), array( 'selector', 'confirm' ) ),
				array( $this, 'delete_field_group' ),
				'manage_options',
				true
			),
			'save_field' => $this->operation(
				'write',
				__( 'Create or update field', 'mindio-magic-mcp' ),
				__( 'Create or update a native ACF Free field within a field group.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'field' => array( 'type' => 'object' ) ) ), array( 'field', 'confirm' ) ),
				array( $this, 'save_field' ),
				'manage_options'
			),
			'delete_field' => $this->operation(
				'write',
				__( 'Delete field', 'mindio-magic-mcp' ),
				__( 'Permanently delete an ACF field after explicit confirmation.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'selector' => $selector ) ), array( 'selector', 'confirm' ) ),
				array( $this, 'delete_field' ),
				'manage_options',
				true
			),
			'update_field_value' => $this->operation(
				'write',
				__( 'Update field value', 'mindio-magic-mcp' ),
				__( 'Update one ACF value on a post using a field name or key.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'field'   => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
						'value'   => array(),
					),
					array( 'post_id', 'field', 'value' )
				),
				array( $this, 'update_field_value' ),
				array( $this, 'can_edit_value' )
			),
			'save_post_type' => $this->operation(
				'write',
				__( 'Create or update ACF post type', 'mindio-magic-mcp' ),
				__( 'Create or update a custom post type using the ACF Free post-type UI storage.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'definition' => array( 'type' => 'object' ) ) ), array( 'definition', 'confirm' ) ),
				array( $this, 'save_post_type' ),
				'manage_options'
			),
			'delete_post_type' => $this->operation(
				'write',
				__( 'Delete ACF post type', 'mindio-magic-mcp' ),
				__( 'Delete an ACF-managed post-type registration, without deleting its content.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'selector' => $selector ) ), array( 'selector', 'confirm' ) ),
				array( $this, 'delete_post_type' ),
				'manage_options',
				true
			),
			'save_taxonomy' => $this->operation(
				'write',
				__( 'Create or update ACF taxonomy', 'mindio-magic-mcp' ),
				__( 'Create or update a taxonomy using the ACF Free taxonomy UI storage.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'definition' => array( 'type' => 'object' ) ) ), array( 'definition', 'confirm' ) ),
				array( $this, 'save_taxonomy' ),
				'manage_options'
			),
			'delete_taxonomy' => $this->operation(
				'write',
				__( 'Delete ACF taxonomy', 'mindio-magic-mcp' ),
				__( 'Delete an ACF-managed taxonomy registration, without deleting its terms.', 'mindio-magic-mcp' ),
				$this->object_schema( array_merge( $confirm, array( 'selector' => $selector ) ), array( 'selector', 'confirm' ) ),
				array( $this, 'delete_taxonomy' ),
				'manage_options',
				true
			),
		);

		$this->register_operations( $operations, Auth::SCOPE_READ, Auth::SCOPE_ADMIN );
	}

	/** @return array<string,mixed> */
	public function list_field_groups( array $args ): array {
		$filter = array();
		if ( isset( $args['active'] ) ) {
			$filter['active'] = (bool) $args['active'];
		}
		$groups = array();
		foreach ( acf_get_field_groups( $filter ) as $group ) {
			if ( ! empty( $args['post_type'] ) && ! $this->group_matches_post_type( $group, sanitize_key( (string) $args['post_type'] ) ) ) {
				continue;
			}
			$fields   = acf_get_fields( $group ) ?: array();
			$groups[] = array(
				'id'         => (int) ( $group['ID'] ?? 0 ),
				'key'        => (string) ( $group['key'] ?? '' ),
				'title'      => (string) ( $group['title'] ?? '' ),
				'active'     => ! empty( $group['active'] ),
				'show_in_rest'=> ! empty( $group['show_in_rest'] ),
				'field_count'=> count( $fields ),
				'fields'     => array_map( array( $this, 'field_summary' ), $fields ),
			);
		}
		return array( 'groups' => $groups );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_field_group( array $args ): array|\WP_Error {
		$group = acf_get_field_group( $args['selector'] );
		if ( ! $group ) {
			return new \WP_Error( 'acf_group_not_found', __( 'The ACF field group was not found.', 'mindio-magic-mcp' ) );
		}
		$group['fields'] = acf_get_fields( $group ) ?: array();
		return array( 'group' => $this->safe_value( $group ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_field_value( array $args ): array|\WP_Error {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$field = sanitize_text_field( (string) $args['field'] );
		$value = get_field( $field, $post->ID, array_key_exists( 'format_value', $args ) ? (bool) $args['format_value'] : true );
		return array( 'post_id' => $post->ID, 'field' => $field, 'value' => $this->safe_value( $value ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function list_post_types( array $args ): array|\WP_Error {
		unset( $args );
		if ( ! function_exists( 'acf_get_acf_post_types' ) ) {
			return $this->acf_version_error();
		}
		return array( 'post_types' => $this->safe_value( acf_get_acf_post_types() ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function list_taxonomies( array $args ): array|\WP_Error {
		unset( $args );
		if ( ! function_exists( 'acf_get_acf_taxonomies' ) ) {
			return $this->acf_version_error();
		}
		return array( 'taxonomies' => $this->safe_value( acf_get_acf_taxonomies() ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function save_field_group( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$group = $this->sanitize_group( (array) $args['group'] );
		if ( is_wp_error( $group ) ) {
			return $group;
		}
		$result = acf_update_field_group( $group );
		return $result ? array( 'group' => $this->safe_value( $result ) ) : new \WP_Error( 'acf_group_save_failed', __( 'ACF could not save the field group.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_field_group( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		return acf_delete_field_group( $args['selector'] )
			? array( 'selector' => $args['selector'], 'deleted' => true )
			: new \WP_Error( 'acf_group_delete_failed', __( 'ACF could not delete the field group.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function save_field( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$field = $this->sanitize_field( (array) $args['field'] );
		if ( is_wp_error( $field ) ) {
			return $field;
		}
		$result = acf_update_field( $field );
		return $result ? array( 'field' => $this->safe_value( $result ) ) : new \WP_Error( 'acf_field_save_failed', __( 'ACF could not save the field.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_field( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		return acf_delete_field( $args['selector'] )
			? array( 'selector' => $args['selector'], 'deleted' => true )
			: new \WP_Error( 'acf_field_delete_failed', __( 'ACF could not delete the field.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_field_value( array $args ): array|\WP_Error {
		$post = get_post( (int) $args['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'mindio-magic-mcp' ) );
		}
		$field        = sanitize_text_field( (string) $args['field'] );
		$field_object = get_field_object( $field, $post->ID, false, false );
		if ( ! is_array( $field_object ) || empty( $field_object['key'] ) ) {
			return new \WP_Error( 'acf_field_not_found', __( 'ACF field not found.', 'mindio-magic-mcp' ) );
		}
		$value  = $this->sanitize_field_value( $args['value'], sanitize_key( (string) ( $field_object['type'] ?? '' ) ) );
		$result = update_field( (string) $field_object['key'], $value, $post->ID );
		return false === $result
			? new \WP_Error( 'acf_value_update_failed', __( 'ACF could not update the field value.', 'mindio-magic-mcp' ) )
			: array( 'post_id' => $post->ID, 'field' => $field, 'updated' => true );
	}

	private function sanitize_field_value( mixed $value, string $type, int $depth = 0 ): mixed {
		if ( $depth > 6 ) {
			return null;
		}
		if ( in_array( $type, array( 'image', 'file', 'post_object', 'page_link', 'relationship', 'taxonomy', 'user' ), true ) ) {
			if ( is_array( $value ) ) {
				return array_values( array_filter( array_map( 'absint', array_slice( $value, 0, 500 ) ) ) );
			}
			return absint( $value );
		}
		if ( in_array( $type, array( 'number', 'range' ), true ) ) {
			return is_numeric( $value ) ? 0 + $value : '';
		}
		if ( 'true_false' === $type ) {
			return ! empty( $value );
		}
		if ( 'email' === $type ) {
			return sanitize_email( is_scalar( $value ) ? (string) $value : '' );
		}
		if ( in_array( $type, array( 'url', 'oembed' ), true ) ) {
			return esc_url_raw( is_scalar( $value ) ? (string) $value : '', array( 'http', 'https' ) );
		}
		if ( 'wysiwyg' === $type ) {
			return wp_kses_post( is_scalar( $value ) ? (string) $value : '' );
		}
		if ( 'textarea' === $type ) {
			return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
		}
		if ( 'link' === $type ) {
			$link = is_array( $value ) ? $value : array();
			return array(
				'url'    => esc_url_raw( (string) ( $link['url'] ?? '' ), array( 'http', 'https' ) ),
				'title'  => sanitize_text_field( (string) ( $link['title'] ?? '' ) ),
				'target' => '_blank' === ( $link['target'] ?? '' ) ? '_blank' : '',
			);
		}
		if ( 'google_map' === $type ) {
			$map    = is_array( $value ) ? array_slice( $value, 0, 50, true ) : array();
			$output = array();
			foreach ( $map as $key => $child ) {
				$key = sanitize_key( (string) $key );
				if ( $key && is_scalar( $child ) ) {
					$output[ $key ] = in_array( $key, array( 'lat', 'lng', 'zoom' ), true ) && is_numeric( $child ) ? 0 + $child : sanitize_text_field( (string) $child );
				}
			}
			return $output;
		}
		if ( is_array( $value ) ) {
			$output = array();
			foreach ( array_slice( $value, 0, 500, true ) as $key => $child ) {
				$clean_key            = is_int( $key ) ? $key : sanitize_key( (string) $key );
				$output[ $clean_key ] = $this->sanitize_field_value( $child, 'group' === $type ? '' : 'text', $depth + 1 );
			}
			return $output;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function save_post_type( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		if ( ! function_exists( 'acf_update_post_type' ) ) {
			return $this->acf_version_error();
		}
		$definition = $this->sanitize_post_type( (array) $args['definition'] );
		if ( is_wp_error( $definition ) ) {
			return $definition;
		}
		$result = acf_update_post_type( $definition );
		return $result ? array( 'post_type' => $this->safe_value( $result ) ) : new \WP_Error( 'acf_post_type_save_failed', __( 'ACF could not save the post type.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_post_type( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		if ( ! function_exists( 'acf_delete_post_type' ) ) {
			return $this->acf_version_error();
		}
		return acf_delete_post_type( $args['selector'] )
			? array( 'selector' => $args['selector'], 'deleted' => true, 'content_deleted' => false )
			: new \WP_Error( 'acf_post_type_delete_failed', __( 'ACF could not delete the post-type registration.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function save_taxonomy( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		if ( ! function_exists( 'acf_update_taxonomy' ) ) {
			return $this->acf_version_error();
		}
		$definition = $this->sanitize_taxonomy( (array) $args['definition'] );
		if ( is_wp_error( $definition ) ) {
			return $definition;
		}
		$result = acf_update_taxonomy( $definition );
		return $result ? array( 'taxonomy' => $this->safe_value( $result ) ) : new \WP_Error( 'acf_taxonomy_save_failed', __( 'ACF could not save the taxonomy.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_taxonomy( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		if ( ! function_exists( 'acf_delete_taxonomy' ) ) {
			return $this->acf_version_error();
		}
		return acf_delete_taxonomy( $args['selector'] )
			? array( 'selector' => $args['selector'], 'deleted' => true, 'terms_deleted' => false )
			: new \WP_Error( 'acf_taxonomy_delete_failed', __( 'ACF could not delete the taxonomy registration.', 'mindio-magic-mcp' ) );
	}

	public function can_read_value( array $args ): bool {
		return current_user_can( 'read_post', (int) ( $args['post_id'] ?? 0 ) );
	}

	public function can_edit_value( array $args ): bool {
		return current_user_can( 'edit_post', (int) ( $args['post_id'] ?? 0 ) );
	}

	protected function dependency_installed(): bool {
		return $this->dependency_available()
			|| $this->plugin_is_installed( array( 'advanced-custom-fields/acf.php' ), array( 'acf' ) );
	}

	protected function dependency_available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'get_field' );
	}

	protected function dependency_label(): string {
		return 'Advanced Custom Fields Free';
	}

	/** @return array<string,mixed> */
	private function operation( string $mode, string $label, string $description, array $schema, callable $callback, string|callable $capability, bool $destructive = false ): array {
		return compact( 'mode', 'label', 'description', 'schema', 'callback', 'capability', 'destructive' );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_group( array $input ): array|\WP_Error {
		if ( empty( $input['title'] ) ) {
			return new \WP_Error( 'invalid_acf_group', __( 'Field group title is required.', 'mindio-magic-mcp' ) );
		}
		$group = array(
			'ID'                    => absint( $input['ID'] ?? 0 ),
			'key'                   => $this->acf_key( (string) ( $input['key'] ?? '' ), 'group_' ),
			'title'                 => sanitize_text_field( (string) $input['title'] ),
			'menu_order'            => (int) ( $input['menu_order'] ?? 0 ),
			'position'              => in_array( (string) ( $input['position'] ?? 'normal' ), array( 'normal', 'side', 'acf_after_title' ), true ) ? (string) ( $input['position'] ?? 'normal' ) : 'normal',
			'style'                 => 'seamless' === ( $input['style'] ?? '' ) ? 'seamless' : 'default',
			'label_placement'       => 'left' === ( $input['label_placement'] ?? '' ) ? 'left' : 'top',
			'instruction_placement' => 'field' === ( $input['instruction_placement'] ?? '' ) ? 'field' : 'label',
			'hide_on_screen'        => array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['hide_on_screen'] ?? array() ) ) ) ),
			'active'                => array_key_exists( 'active', $input ) ? (bool) $input['active'] : true,
			'description'           => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'show_in_rest'          => ! empty( $input['show_in_rest'] ),
			'location'              => $this->sanitize_location( (array) ( $input['location'] ?? array() ) ),
		);
		return $group;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_field( array $input ): array|\WP_Error {
		$type = sanitize_key( (string) ( $input['type'] ?? '' ) );
		if ( empty( $input['name'] ) || empty( $input['label'] ) || empty( $input['parent'] ) || ! in_array( $type, $this->free_field_types(), true ) ) {
			return new \WP_Error( 'invalid_acf_field', __( 'Field name, label, parent, and a native ACF Free field type are required.', 'mindio-magic-mcp' ) );
		}
		$allowed = array( 'ID', 'key', 'label', 'name', 'type', 'instructions', 'required', 'conditional_logic', 'wrapper', 'default_value', 'placeholder', 'prepend', 'append', 'min', 'max', 'step', 'maxlength', 'rows', 'new_lines', 'choices', 'allow_null', 'multiple', 'ui', 'ajax', 'return_format', 'preview_size', 'library', 'mime_types', 'parent', 'menu_order', 'post_type', 'taxonomy', 'filters', 'elements', 'bidirectional', 'bidirectional_target', 'save_custom', 'load_custom' );
		$field   = array_intersect_key( $input, array_fill_keys( $allowed, true ) );
		$field['ID']           = absint( $field['ID'] ?? 0 );
		$field['key']          = $this->acf_key( (string) ( $field['key'] ?? '' ), 'field_' );
		$field['label']        = sanitize_text_field( (string) $field['label'] );
		$field['name']         = sanitize_key( (string) $field['name'] );
		$field['type']         = $type;
		$field['parent']       = is_int( $field['parent'] ) ? absint( $field['parent'] ) : sanitize_key( (string) $field['parent'] );
		$field['instructions'] = sanitize_textarea_field( (string) ( $field['instructions'] ?? '' ) );
		$field['required']     = ! empty( $field['required'] );
		$field['menu_order']   = (int) ( $field['menu_order'] ?? 0 );
		foreach ( array( 'allow_null', 'multiple', 'ui', 'ajax', 'bidirectional', 'save_custom', 'load_custom' ) as $boolean ) {
			if ( array_key_exists( $boolean, $field ) ) {
				$field[ $boolean ] = (bool) $field[ $boolean ];
			}
		}
		foreach ( array( 'placeholder', 'prepend', 'append', 'new_lines', 'return_format', 'preview_size', 'library', 'mime_types' ) as $text ) {
			if ( isset( $field[ $text ] ) ) {
				$field[ $text ] = sanitize_text_field( (string) $field[ $text ] );
			}
		}
		foreach ( array( 'min', 'max', 'step', 'maxlength', 'rows' ) as $number ) {
			if ( isset( $field[ $number ] ) && '' !== $field[ $number ] ) {
				$field[ $number ] = is_numeric( $field[ $number ] ) ? 0 + $field[ $number ] : '';
			}
		}
		foreach ( array( 'choices', 'conditional_logic', 'wrapper', 'default_value', 'post_type', 'taxonomy', 'filters', 'elements', 'bidirectional_target' ) as $nested ) {
			if ( isset( $field[ $nested ] ) ) {
				$field[ $nested ] = $this->sanitize_nested( $field[ $nested ] );
			}
		}
		return $field;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_post_type( array $input ): array|\WP_Error {
		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		$title     = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( ! $title || ! $post_type || strlen( $post_type ) > 20 || ! preg_match( '/^[a-z0-9_-]+$/', $post_type ) ) {
			return new \WP_Error( 'invalid_acf_post_type', __( 'A title and a valid post type key of at most 20 characters are required.', 'mindio-magic-mcp' ) );
		}
		$allowed = array( 'ID', 'key', 'title', 'menu_order', 'active', 'post_type', 'advanced_configuration', 'labels', 'description', 'public', 'hierarchical', 'exclude_from_search', 'publicly_queryable', 'show_ui', 'show_in_menu', 'admin_menu_parent', 'show_in_admin_bar', 'show_in_nav_menus', 'show_in_rest', 'rest_base', 'rest_namespace', 'menu_position', 'menu_icon', 'supports', 'taxonomies', 'has_archive', 'has_archive_slug', 'rewrite', 'query_var', 'query_var_name', 'can_export', 'delete_with_user', 'enter_title_here' );
		$result  = array_intersect_key( $input, array_fill_keys( $allowed, true ) );
		$result['ID']        = absint( $result['ID'] ?? 0 );
		$result['key']       = $this->acf_key( (string) ( $result['key'] ?? '' ), 'post_type_' );
		$result['title']     = $title;
		$result['post_type'] = $post_type;
		$result['labels']    = $this->sanitize_labels( (array) ( $result['labels'] ?? array() ), $title );
		return $this->sanitize_registration( $result );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_taxonomy( array $input ): array|\WP_Error {
		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$title    = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$objects  = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['object_type'] ?? array() ) ), 'post_type_exists' ) );
		if ( ! $title || ! $taxonomy || strlen( $taxonomy ) > 32 || ! preg_match( '/^[a-z0-9_-]+$/', $taxonomy ) || ! $objects ) {
			return new \WP_Error( 'invalid_acf_taxonomy', __( 'A title, valid taxonomy key, and at least one existing object type are required.', 'mindio-magic-mcp' ) );
		}
		$allowed = array( 'ID', 'key', 'title', 'menu_order', 'active', 'taxonomy', 'object_type', 'advanced_configuration', 'labels', 'description', 'public', 'publicly_queryable', 'hierarchical', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_rest', 'rest_base', 'rest_namespace', 'show_tagcloud', 'show_in_quick_edit', 'show_admin_column', 'rewrite', 'query_var', 'query_var_name', 'sort', 'meta_box' );
		$result  = array_intersect_key( $input, array_fill_keys( $allowed, true ) );
		$result['ID']          = absint( $result['ID'] ?? 0 );
		$result['key']         = $this->acf_key( (string) ( $result['key'] ?? '' ), 'taxonomy_' );
		$result['title']       = $title;
		$result['taxonomy']    = $taxonomy;
		$result['object_type'] = $objects;
		$result['labels']      = $this->sanitize_labels( (array) ( $result['labels'] ?? array() ), $title );
		return $this->sanitize_registration( $result );
	}

	/** @return array<string,mixed> */
	private function sanitize_registration( array $result ): array {
		$booleans = array( 'active', 'advanced_configuration', 'public', 'hierarchical', 'exclude_from_search', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_admin_bar', 'show_in_nav_menus', 'show_in_rest', 'has_archive', 'can_export', 'delete_with_user', 'show_tagcloud', 'show_in_quick_edit', 'show_admin_column' );
		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$result[ $key ] = (bool) $result[ $key ];
			}
		}
		foreach ( array( 'description', 'admin_menu_parent', 'rest_base', 'rest_namespace', 'menu_icon', 'has_archive_slug', 'query_var_name', 'enter_title_here', 'meta_box' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$result[ $key ] = sanitize_text_field( (string) $result[ $key ] );
			}
		}
		$result['menu_order'] = (int) ( $result['menu_order'] ?? 0 );
		if ( isset( $result['menu_position'] ) ) {
			$result['menu_position'] = null === $result['menu_position'] ? null : (int) $result['menu_position'];
		}
		foreach ( array( 'supports', 'taxonomies', 'rewrite' ) as $nested ) {
			if ( isset( $result[ $nested ] ) ) {
				$result[ $nested ] = $this->sanitize_nested( $result[ $nested ] );
			}
		}
		return $result;
	}

	/** @return array<string,string> */
	private function sanitize_labels( array $labels, string $fallback ): array {
		$output = array();
		foreach ( array_slice( $labels, 0, 50, true ) as $key => $label ) {
			$key = sanitize_key( (string) $key );
			if ( $key && is_scalar( $label ) ) {
				$output[ $key ] = sanitize_text_field( (string) $label );
			}
		}
		$output['name']          = $output['name'] ?? $fallback;
		$output['singular_name'] = $output['singular_name'] ?? $fallback;
		return $output;
	}

	/** @return array<int,array<int,array<string,string>>> */
	private function sanitize_location( array $location ): array {
		$output = array();
		foreach ( array_slice( $location, 0, 20 ) as $and_group ) {
			$clean_group = array();
			foreach ( array_slice( (array) $and_group, 0, 20 ) as $rule ) {
				$rule = (array) $rule;
				$param = sanitize_key( (string) ( $rule['param'] ?? '' ) );
				if ( ! $param ) {
					continue;
				}
				$clean_group[] = array(
					'param'    => $param,
					'operator' => '!=' === ( $rule['operator'] ?? '' ) ? '!=' : '==',
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}
			if ( $clean_group ) {
				$output[] = $clean_group;
			}
		}
		return $output;
	}

	private function sanitize_nested( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 5 ) {
			return null;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( substr( $value, 0, 2000 ) );
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$output = array();
		foreach ( array_slice( $value, 0, 200, true ) as $key => $child ) {
			$output[ is_int( $key ) ? $key : sanitize_key( (string) $key ) ] = $this->sanitize_nested( $child, $depth + 1 );
		}
		return $output;
	}

	private function safe_value( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 8 ) {
			return '[truncated]';
		}
		if ( $value instanceof \WP_Post ) {
			return array( 'id' => $value->ID, 'post_type' => $value->post_type, 'title' => get_the_title( $value ) );
		}
		if ( $value instanceof \WP_Term ) {
			return array( 'id' => $value->term_id, 'taxonomy' => $value->taxonomy, 'name' => $value->name );
		}
		if ( $value instanceof \WP_User ) {
			return array( 'id' => $value->ID, 'display_name' => $value->display_name );
		}
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return '[unsupported]';
		}
		$output = array();
		foreach ( array_slice( $value, 0, 500, true ) as $key => $child ) {
			$output[ $key ] = $this->safe_value( $child, $depth + 1 );
		}
		return $output;
	}

	/** @return array<string,mixed> */
	public function field_summary( array $field ): array {
		return array(
			'id'       => (int) ( $field['ID'] ?? 0 ),
			'key'      => (string) ( $field['key'] ?? '' ),
			'name'     => (string) ( $field['name'] ?? '' ),
			'label'    => (string) ( $field['label'] ?? '' ),
			'type'     => (string) ( $field['type'] ?? '' ),
			'required' => ! empty( $field['required'] ),
		);
	}

	private function group_matches_post_type( array $group, string $post_type ): bool {
		foreach ( (array) ( $group['location'] ?? array() ) as $and_group ) {
			foreach ( (array) $and_group as $rule ) {
				if ( 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) && $post_type === ( $rule['value'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function acf_key( string $key, string $prefix ): string {
		$key = sanitize_key( $key );
		if ( str_starts_with( $key, $prefix ) ) {
			return $key;
		}
		return $prefix . substr( md5( wp_generate_uuid4() ), 0, 13 );
	}

	/** @return array<int,string> */
	private function free_field_types(): array {
		return array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'image', 'file', 'wysiwyg', 'oembed', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'link', 'post_object', 'page_link', 'relationship', 'taxonomy', 'user', 'google_map', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'message', 'accordion', 'tab', 'group' );
	}

	private function confirmation_error(): \WP_Error {
		return new \WP_Error( 'confirmation_required', __( 'This ACF structure operation requires confirm=true.', 'mindio-magic-mcp' ) );
	}

	private function acf_version_error(): \WP_Error {
		return new \WP_Error( 'acf_version_unsupported', __( 'This operation requires ACF Free 6.1 or newer.', 'mindio-magic-mcp' ) );
	}
}
