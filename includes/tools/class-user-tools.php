<?php
/**
 * Guarded WordPress user-management tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class User_Tools {
	private const PROTECTED_CAPABILITIES = array(
		'manage_options',
		'update_core',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'switch_themes',
		'edit_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'manage_network',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
	);

	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		$this->registry->register(
			'list_users',
			__( 'List WordPress users and roles with search and pagination. Passwords and secrets are never exposed.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'search'   => array( 'type' => 'string', 'maxLength' => 200 ),
					'role'     => array( 'type' => 'string', 'maxLength' => 100 ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_users' ),
			Auth::SCOPE_ADMIN,
			'list_users',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'create_user',
			__( 'Create a non-administrator WordPress user and optionally email the standard new-user invitation. Passwords are never returned.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'username'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 60 ),
					'email'        => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 254 ),
					'role'         => array( 'type' => 'string', 'maxLength' => 100 ),
					'display_name' => array( 'type' => 'string', 'maxLength' => 250 ),
					'first_name'   => array( 'type' => 'string', 'maxLength' => 100 ),
					'last_name'    => array( 'type' => 'string', 'maxLength' => 100 ),
					'send_invite'  => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'username', 'email', 'role' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'create_user' ),
			Auth::SCOPE_ADMIN,
			'create_users'
		);
		$this->registry->register(
			'update_user',
			__( 'Update a non-administrator user profile and optionally assign another non-administrator role.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'user_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
					'email'        => array( 'type' => 'string', 'maxLength' => 254 ),
					'role'         => array( 'type' => 'string', 'maxLength' => 100 ),
					'display_name' => array( 'type' => 'string', 'maxLength' => 250 ),
					'first_name'   => array( 'type' => 'string', 'maxLength' => 100 ),
					'last_name'    => array( 'type' => 'string', 'maxLength' => 100 ),
					'url'          => array( 'type' => 'string', 'maxLength' => 2048 ),
					'description'  => array( 'type' => 'string', 'maxLength' => 5000 ),
				),
				'required'             => array( 'user_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'update_user' ),
			Auth::SCOPE_ADMIN,
			array( $this, 'can_edit_user' ),
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'delete_user',
			__( 'Delete a non-administrator user, optionally reassigning their content. Requires confirm=true and never permits self-deletion.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'user_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'reassign_to' => array( 'type' => 'integer', 'minimum' => 1 ),
					'confirm'     => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'user_id', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'delete_user' ),
			Auth::SCOPE_ADMIN,
			'delete_users',
			array( 'destructiveHint' => true )
		);
		$this->registry->register(
			'send_password_reset',
			__( 'Send the normal WordPress password-reset email to a user. Reset keys and passwords are never returned.', 'mindio-magic-mcp' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'user_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'             => array( 'user_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'send_password_reset' ),
			Auth::SCOPE_ADMIN,
			array( $this, 'can_edit_user' )
		);
	}

	public function can_edit_user( array $args ): bool {
		$user_id = absint( $args['user_id'] ?? 0 );
		return $user_id > 0 && current_user_can( 'edit_user', $user_id );
	}

	/** @return array<string,mixed> */
	public function list_users( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$query_args = array(
			'number'      => $per_page,
			'paged'       => $page,
			'count_total' => true,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . sanitize_text_field( (string) $args['search'] ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		if ( ! empty( $args['role'] ) ) {
			$query_args['role'] = sanitize_key( (string) $args['role'] );
		}
		$query = new \WP_User_Query( $query_args );
		$total = (int) $query->get_total();
		return array(
			'items'       => array_map( array( $this, 'serialize' ), $query->get_results() ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_user( array $args ): array|\WP_Error {
		$role = sanitize_key( (string) $args['role'] );
		if ( ! $this->role_allowed( $role ) ) {
			return new \WP_Error( 'role_forbidden', __( 'Only an editable non-administrator role may be assigned.', 'mindio-magic-mcp' ) );
		}
		$username = sanitize_user( (string) $args['username'], true );
		$email    = sanitize_email( (string) $args['email'] );
		if ( '' === $username || ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_user_fields', __( 'A valid username and email address are required.', 'mindio-magic-mcp' ) );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => $role,
				'display_name' => sanitize_text_field( (string) ( $args['display_name'] ?? $username ) ),
				'first_name'   => sanitize_text_field( (string) ( $args['first_name'] ?? '' ) ),
				'last_name'    => sanitize_text_field( (string) ( $args['last_name'] ?? '' ) ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$send_invite = ! array_key_exists( 'send_invite', $args ) || (bool) $args['send_invite'];
		if ( $send_invite ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}
		$result                 = $this->serialize( get_user_by( 'id', $user_id ) );
		$result['invite_sent']  = $send_invite;
		$result['password_note'] = __( 'No password is exposed. The user can set one through the invitation or password-reset flow.', 'mindio-magic-mcp' );
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_user( array $args ): array|\WP_Error {
		$user_id = absint( $args['user_id'] );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'User not found.', 'mindio-magic-mcp' ) );
		}
		if ( $this->is_protected_user( $user ) || is_super_admin( $user_id ) ) {
			return new \WP_Error( 'administrator_protected', __( 'Administrator and super-admin accounts cannot be modified through MCP.', 'mindio-magic-mcp' ) );
		}

		$update = array( 'ID' => $user_id );
		foreach ( array( 'display_name', 'first_name', 'last_name', 'description' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$update[ $field ] = sanitize_text_field( (string) $args[ $field ] );
			}
		}
		if ( array_key_exists( 'email', $args ) ) {
			$email = sanitize_email( (string) $args['email'] );
			if ( ! is_email( $email ) ) {
				return new \WP_Error( 'invalid_email', __( 'The email address is invalid.', 'mindio-magic-mcp' ) );
			}
			$update['user_email'] = $email;
		}
		if ( array_key_exists( 'url', $args ) ) {
			$update['user_url'] = esc_url_raw( (string) $args['url'] );
		}
		if ( array_key_exists( 'role', $args ) ) {
			$role = sanitize_key( (string) $args['role'] );
			if ( ! current_user_can( 'promote_users' ) || ! $this->role_allowed( $role ) ) {
				return new \WP_Error( 'role_forbidden', __( 'The requested role cannot be assigned.', 'mindio-magic-mcp' ) );
			}
			$update['role'] = $role;
		}

		$result = wp_update_user( $update );
		return is_wp_error( $result ) ? $result : $this->serialize( get_user_by( 'id', $user_id ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delete_user( array $args ): array|\WP_Error {
		$user_id = absint( $args['user_id'] );
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'User deletion requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		if ( $user_id === get_current_user_id() ) {
			return new \WP_Error( 'self_delete_forbidden', __( 'The authenticated user cannot delete itself.', 'mindio-magic-mcp' ) );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'User not found.', 'mindio-magic-mcp' ) );
		}
		if ( $this->is_protected_user( $user ) || is_super_admin( $user_id ) ) {
			return new \WP_Error( 'administrator_protected', __( 'Administrator and super-admin accounts cannot be deleted through MCP.', 'mindio-magic-mcp' ) );
		}
		$reassign = absint( $args['reassign_to'] ?? 0 );
		if ( $reassign && ( $reassign === $user_id || ! get_user_by( 'id', $reassign ) ) ) {
			return new \WP_Error( 'invalid_reassign_user', __( 'The content-reassignment user is invalid.', 'mindio-magic-mcp' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return wp_delete_user( $user_id, $reassign ?: null )
			? array( 'user_id' => $user_id, 'deleted' => true, 'reassigned_to' => $reassign )
			: new \WP_Error( 'user_delete_failed', __( 'The user could not be deleted.', 'mindio-magic-mcp' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function send_password_reset( array $args ): array|\WP_Error {
		$user = get_user_by( 'id', absint( $args['user_id'] ) );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'User not found.', 'mindio-magic-mcp' ) );
		}
		$result = retrieve_password( $user->user_login );
		return is_wp_error( $result ) ? $result : array( 'user_id' => $user->ID, 'reset_email_sent' => true );
	}

	private function role_allowed( string $role ): bool {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$roles = get_editable_roles();
		$capabilities = (array) ( $roles[ $role ]['capabilities'] ?? array() );
		if ( ! isset( $roles[ $role ] ) || 'administrator' === $role ) {
			return false;
		}
		foreach ( self::PROTECTED_CAPABILITIES as $capability ) {
			if ( ! empty( $capabilities[ $capability ] ) ) {
				return false;
			}
		}
		return true;
	}

	private function is_protected_user( \WP_User $user ): bool {
		foreach ( self::PROTECTED_CAPABILITIES as $capability ) {
			if ( user_can( $user, $capability ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string,mixed> */
	private function serialize( ?\WP_User $user ): array {
		if ( ! $user ) {
			return array();
		}
		return array(
			'user_id'      => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'roles'        => array_values( $user->roles ),
			'url'          => $user->user_url,
			'registered'   => mysql2date( DATE_ATOM, $user->user_registered, false ),
		);
	}
}
