<?php
/**
 * Read-only view of the human approval queue.
 *
 * Deciding a request stays in the admin console; agents can only look.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Approval_Tools {
	private Tool_Registry $registry;
	private Approval_Queue $approvals;

	public function __construct( Tool_Registry $registry, Approval_Queue $approvals ) {
		$this->registry  = $registry;
		$this->approvals = $approvals;
	}

	public function register(): void {
		$this->registry->register(
			'list_approvals',
			__( 'List tool calls parked for human approval, with their status. Poll this after a call returns approval_required.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'pending', 'approved', 'rejected', 'executed' ),
					),
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_approvals' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);

		$this->registry->register(
			'get_approval',
			__( 'Read one approval request, including the exact arguments awaiting a decision.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'approval_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				),
				'required'   => array( 'approval_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'get_approval' ),
			Auth::SCOPE_READ,
			'read',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed> */
	public function list_approvals( array $args ): array {
		$owner    = $this->owner_filter();
		$requests = $this->approvals->list_requests(
			(string) ( $args['status'] ?? '' ),
			absint( $args['limit'] ?? 50 ),
			$owner
		);

		return array(
			'count'     => count( $requests ),
			'pending'   => $this->approvals->pending_count( $owner ),
			'approvals' => $requests,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_approval( array $args ) {
		$request = $this->approvals->get( (string) $args['approval_id'] );
		$owner   = $this->owner_filter();
		// Report someone else's request as unknown so IDs cannot be probed.
		if ( ! $request || ( 0 !== $owner && $owner !== (int) $request['requested_by'] ) ) {
			return new \WP_Error( 'unknown_approval', __( 'Unknown approval request.', 'mindio-magic-mcp' ) );
		}

		return array( 'approval' => $request );
	}

	/**
	 * Queued calls hold the exact arguments of other users' privileged tool
	 * calls, so only administrators may see requests they did not make.
	 *
	 * @return int 0 for administrators (no filter), otherwise the current user ID
	 *             (-1, matching nothing, when no user is set).
	 */
	private function owner_filter(): int {
		if ( current_user_can( 'manage_options' ) ) {
			return 0;
		}
		return get_current_user_id() ?: -1;
	}
}
