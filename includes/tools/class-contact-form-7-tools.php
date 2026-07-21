<?php
/**
 * Contact Form 7 Free integration.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Contact_Form_7_Tools extends Integration_Dispatcher {
	public function __construct( Tool_Registry $registry ) {
		parent::__construct( $registry, 'contact_form_7', 'Contact Form 7' );
	}

	public function register(): void {
		$form_id = array( 'type' => 'integer', 'minimum' => 1 );
		$operations = array(
			'list_forms' => $this->operation(
				'read',
				__( 'List contact forms', 'mindio-magic-mcp' ),
				__( 'List Contact Form 7 forms with shortcode and modification metadata.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'search'   => array( 'type' => 'string', 'maxLength' => 100 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					)
				),
				array( $this, 'list_forms' ),
				'wpcf7_read_contact_forms'
			),
			'get_form' => $this->operation(
				'read',
				__( 'Get contact form', 'mindio-magic-mcp' ),
				__( 'Get one Contact Form 7 form, mail templates, messages, and additional settings.', 'mindio-magic-mcp' ),
				$this->object_schema( array( 'form_id' => $form_id ), array( 'form_id' ) ),
				array( $this, 'get_form' ),
				'wpcf7_read_contact_forms'
			),
			'create_form' => $this->operation(
				'write',
				__( 'Create contact form', 'mindio-magic-mcp' ),
				__( 'Create a Contact Form 7 form from its default template and sanitized property overrides.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'title'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
						'locale'     => array( 'type' => 'string', 'maxLength' => 20 ),
						'properties' => array( 'type' => 'object' ),
						'confirm'    => array( 'type' => 'boolean' ),
					),
					array( 'title', 'confirm' )
				),
				array( $this, 'create_form' ),
				'wpcf7_edit_contact_forms'
			),
			'update_form' => $this->operation(
				'write',
				__( 'Update contact form', 'mindio-magic-mcp' ),
				__( 'Update a Contact Form 7 title or property set with optional optimistic concurrency.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'form_id'               => $form_id,
						'title'                 => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
						'properties'            => array( 'type' => 'object' ),
						'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time', 'maxLength' => 30 ),
						'confirm'               => array( 'type' => 'boolean' ),
					),
					array( 'form_id', 'confirm' )
				),
				array( $this, 'update_form' ),
				'wpcf7_edit_contact_forms'
			),
			'duplicate_form' => $this->operation(
				'write',
				__( 'Duplicate contact form', 'mindio-magic-mcp' ),
				__( 'Duplicate a Contact Form 7 form and optionally assign a new title.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'form_id' => $form_id,
						'title'   => array( 'type' => 'string', 'maxLength' => 200 ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					array( 'form_id', 'confirm' )
				),
				array( $this, 'duplicate_form' ),
				'wpcf7_edit_contact_forms'
			),
			'delete_form' => $this->operation(
				'write',
				__( 'Delete contact form', 'mindio-magic-mcp' ),
				__( 'Permanently delete a Contact Form 7 form after explicit confirmation.', 'mindio-magic-mcp' ),
				$this->object_schema( array( 'form_id' => $form_id, 'confirm' => array( 'type' => 'boolean' ) ), array( 'form_id', 'confirm' ) ),
				array( $this, 'delete_form' ),
				'wpcf7_delete_contact_forms',
				true
			),
			'submit_form' => $this->operation(
				'write',
				__( 'Submit contact form', 'mindio-magic-mcp' ),
				__( 'Submit non-file fields through Contact Form 7 validation and delivery. This can send external email and requires confirmation.', 'mindio-magic-mcp' ),
				$this->object_schema(
					array(
						'form_id' => $form_id,
						'fields'  => array( 'type' => 'object' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					array( 'form_id', 'fields', 'confirm' )
				),
				array( $this, 'submit_form' ),
				'edit_posts'
			),
		);
		$this->register_operations( $operations, Auth::SCOPE_ADMIN, Auth::SCOPE_ADMIN );
	}

	/** @return array<string,mixed> */
	public function list_forms( array $args ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$query    = array(
			'posts_per_page' => $per_page,
			'offset'         => ( $page - 1 ) * $per_page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}
		$items = array();
		foreach ( \WPCF7_ContactForm::find( $query ) as $form ) {
			$items[] = $this->form_summary( $form );
		}
		return array(
			'forms'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => (int) \WPCF7_ContactForm::count(),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_form( array $args ): array|\WP_Error {
		$form = $this->find_form( (int) $args['form_id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		return array( 'form' => array_merge( $this->form_summary( $form ), array( 'properties' => $this->safe_value( $form->get_properties() ) ) ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_form( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$locale = sanitize_locale_name( (string) ( $args['locale'] ?? determine_locale() ) );
		if ( ! wpcf7_is_valid_locale( $locale ) ) {
			return new \WP_Error( 'invalid_form_locale', __( 'Contact Form 7 does not accept the requested locale.', 'mindio-magic-mcp' ) );
		}
		$form = \WPCF7_ContactForm::get_template( array( 'title' => sanitize_text_field( (string) $args['title'] ), 'locale' => $locale ) );
		if ( ! empty( $args['properties'] ) ) {
			$properties = $this->sanitize_properties( (array) $args['properties'], $form->get_properties() );
			if ( is_wp_error( $properties ) ) {
				return $properties;
			}
			$form->set_properties( $properties );
		}
		$id = $form->save();
		return $id ? array( 'form' => $this->form_summary( $form ) ) : new \WP_Error( 'form_save_failed', __( 'Contact Form 7 could not create the form.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_form( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$form = $this->find_form( (int) $args['form_id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$stale = $this->check_modified( $form->id(), (string) ( $args['expected_modified_gmt'] ?? '' ) );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}
		if ( isset( $args['title'] ) ) {
			$form->set_title( sanitize_text_field( (string) $args['title'] ) );
		}
		if ( isset( $args['properties'] ) ) {
			$properties = $this->sanitize_properties( (array) $args['properties'], $form->get_properties() );
			if ( is_wp_error( $properties ) ) {
				return $properties;
			}
			$form->set_properties( $properties );
		}
		$id = $form->save();
		return $id ? array( 'form' => $this->form_summary( $form ) ) : new \WP_Error( 'form_save_failed', __( 'Contact Form 7 could not update the form.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function duplicate_form( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$form = $this->find_form( (int) $args['form_id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$copy = $form->copy();
		if ( ! empty( $args['title'] ) ) {
			$copy->set_title( sanitize_text_field( (string) $args['title'] ) );
		}
		$id = $copy->save();
		return $id ? array( 'form' => $this->form_summary( $copy ), 'source_form_id' => $form->id() ) : new \WP_Error( 'form_copy_failed', __( 'Contact Form 7 could not duplicate the form.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_form( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return $this->confirmation_error();
		}
		$form = $this->find_form( (int) $args['form_id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$id = $form->id();
		return $form->delete() ? array( 'form_id' => $id, 'deleted' => true ) : new \WP_Error( 'form_delete_failed', __( 'Contact Form 7 could not delete the form.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function submit_form( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Form submission can send external email and requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$form = $this->find_form( (int) $args['form_id'] );
		if ( is_wp_error( $form ) ) {
			return $form;
		}
		$fields = (array) $args['fields'];
		if ( count( $fields ) > 100 ) {
			return new \WP_Error( 'too_many_form_fields', __( 'A form submission may contain at most 100 fields.', 'mindio-magic-mcp' ) );
		}
		$params = array(
			'_wpcf7'        => $form->id(),
			'_wpcf7_version'=> defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
			'_wpcf7_locale' => $form->locale(),
			'_wpcf7_unit_tag'=> 'wpcf7-f' . $form->id() . '-mcp',
		);
		foreach ( $fields as $key => $value ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $key ) ) {
				return new \WP_Error( 'invalid_form_field', __( 'Form field names must be safe and cannot override Contact Form 7 control fields.', 'mindio-magic-mcp' ) );
			}
			if ( is_array( $value ) ) {
				$params[ $key ] = array_map( static fn( $item ): string => sanitize_textarea_field( (string) $item ), array_slice( $value, 0, 100 ) );
			} elseif ( is_scalar( $value ) ) {
				$params[ $key ] = sanitize_textarea_field( (string) $value );
			} else {
				return new \WP_Error( 'invalid_form_field', __( 'Form field values must be strings or arrays of strings.', 'mindio-magic-mcp' ) );
			}
		}
		$request = new \WP_REST_Request( 'POST', '/contact-form-7/v1/contact-forms/' . $form->id() . '/feedback' );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		return array( 'form_id' => $form->id(), 'response' => $this->safe_value( $response->get_data() ) );
	}

	protected function dependency_installed(): bool {
		return $this->dependency_available()
			|| $this->plugin_is_installed( array( 'contact-form-7/wp-contact-form-7.php' ), array( 'contact-form-7' ) );
	}

	protected function dependency_available(): bool {
		return class_exists( '\\WPCF7_ContactForm' );
	}

	protected function dependency_label(): string {
		return 'Contact Form 7';
	}

	/** @return array<string,mixed> */
	private function operation( string $mode, string $label, string $description, array $schema, callable $callback, string $capability, bool $destructive = false ): array {
		return compact( 'mode', 'label', 'description', 'schema', 'callback', 'capability', 'destructive' );
	}

	/** @return \WPCF7_ContactForm|\WP_Error */
	private function find_form( int $id ): \WPCF7_ContactForm|\WP_Error {
		$form = \WPCF7_ContactForm::get_instance( $id );
		return $form ?: new \WP_Error( 'form_not_found', __( 'Contact Form 7 form not found.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed> */
	private function form_summary( \WPCF7_ContactForm $form ): array {
		$post = get_post( $form->id() );
		return array(
			'id'           => $form->id(),
			'title'        => $form->title(),
			'locale'       => $form->locale(),
			'shortcode'    => $form->shortcode(),
			'modified_gmt' => $post instanceof \WP_Post ? get_post_modified_time( 'c', true, $post ) : '',
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_properties( array $input, array $defaults ): array|\WP_Error {
		$unknown = array_diff( array_keys( $input ), array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' ) );
		if ( $unknown ) {
			return new \WP_Error( 'unsupported_form_property', __( 'Only form, mail, mail_2, messages, and additional_settings may be updated.', 'mindio-magic-mcp' ) );
		}
		$output = $defaults;
		if ( isset( $input['form'] ) ) {
			$form = (string) $input['form'];
			if ( strlen( $form ) > 100000 || str_contains( $form, '<?' ) || str_contains( $form, "\0" ) ) {
				return new \WP_Error( 'invalid_form_template', __( 'The contact form template is unsafe or too large.', 'mindio-magic-mcp' ) );
			}
			$output['form'] = wp_kses_post( $form );
		}
		foreach ( array( 'mail', 'mail_2' ) as $mail_key ) {
			if ( isset( $input[ $mail_key ] ) ) {
				$output[ $mail_key ] = $this->sanitize_mail( (array) $input[ $mail_key ], (array) ( $defaults[ $mail_key ] ?? array() ) );
			}
		}
		if ( isset( $input['messages'] ) ) {
			$messages = array();
			foreach ( array_slice( (array) $input['messages'], 0, 100, true ) as $key => $message ) {
				$key = sanitize_key( (string) $key );
				if ( $key && is_scalar( $message ) ) {
					$messages[ $key ] = sanitize_text_field( (string) $message );
				}
			}
			$output['messages'] = array_merge( (array) ( $defaults['messages'] ?? array() ), $messages );
		}
		if ( isset( $input['additional_settings'] ) ) {
			$output['additional_settings'] = substr( sanitize_textarea_field( (string) $input['additional_settings'] ), 0, 10000 );
		}
		return $output;
	}

	/** @return array<string,mixed> */
	private function sanitize_mail( array $input, array $defaults ): array {
		$output = $defaults;
		foreach ( array( 'active', 'use_html', 'exclude_blank' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = (bool) $input[ $key ];
			}
		}
		foreach ( array( 'subject', 'sender', 'recipient', 'additional_headers', 'attachments' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = substr( sanitize_textarea_field( (string) $input[ $key ] ), 0, 10000 );
			}
		}
		if ( array_key_exists( 'body', $input ) ) {
			$output['body'] = substr( wp_kses_post( (string) $input['body'] ), 0, 100000 );
		}
		return $output;
	}

	/** @return true|\WP_Error */
	private function check_modified( int $post_id, string $expected ): bool|\WP_Error {
		if ( ! $expected ) {
			return true;
		}
		$current = get_post_modified_time( 'c', true, $post_id );
		return $expected === $current ? true : new \WP_Error( 'stale_form', __( 'The form changed since it was read. Fetch it again before updating.', 'mindio-magic-mcp' ) );
	}

	private function safe_value( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 8 ) {
			return '[truncated]';
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

	private function confirmation_error(): \WP_Error {
		return new \WP_Error( 'confirmation_required', __( 'Contact form structure changes require confirm=true.', 'mindio-magic-mcp' ) );
	}
}
