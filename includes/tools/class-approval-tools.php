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
		$requests = $this->approvals->list_requests(
			(string) ( $args['status'] ?? '' ),
			absint( $args['limit'] ?? 50 )
		);

		return array(
			'count'     => count( $requests ),
			'pending'   => $this->approvals->pending_count(),
			'approvals' => $requests,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get_approval( array $args ) {
		$request = $this->approvals->get( (string) $args['approval_id'] );
		if ( ! $request ) {
			return new \WP_Error( 'unknown_approval', __( 'Unknown approval request.', 'mindio-magic-mcp' ) );
		}

		return array( 'approval' => $request );
	}
}
