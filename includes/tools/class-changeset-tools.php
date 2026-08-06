<?php
/**
 * MCP tools for grouping and undoing writes.
 *
 * @package MindioMagicMCP
 */

namespace MindioMagicMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Changeset_Tools {
	private Tool_Registry $registry;
	private Changeset $changesets;
	private Auth $auth;

	public function __construct( Tool_Registry $registry, Changeset $changesets, Auth $auth ) {
		$this->registry   = $registry;
		$this->changesets = $changesets;
		$this->auth       = $auth;
	}

	public function register(): void {
		$this->registry->register(
			'begin_changeset',
			__( 'Open a named changeset and return its ID. Pass that ID as the changeset argument on later write calls to journal them, then undo the whole group with revert_changeset.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'label' => array(
						'type'        => 'string',
						'maxLength'   => 190,
						'description' => __( 'Human-readable description of what this group of changes is for.', 'mindio-magic-mcp' ),
					),
				),
				'required'   => array( 'label' ),
			),
			array( 'type' => 'object' ),
			array( $this, 'begin' ),
			Auth::SCOPE_EDITOR,
			'edit_posts',
			array( 'idempotentHint' => false ),
			array( 'dry_run' => false )
		);

		$this->registry->register(
			'list_changesets',
			__( 'List recent changesets with their status and recorded entry counts.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed', 'reverted' ),
						'description' => __( 'Optionally return only changesets in this state.', 'mindio-magic-mcp' ),
					),
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
			),
			array( 'type' => 'object' ),
			array( $this, 'list' ),
			Auth::SCOPE_READ,
			'edit_posts',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);

		$this->registry->register(
			'get_changeset',
			__( 'Read one changeset with every recorded before and after state, so the effect of a revert can be reviewed first.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				),
				'required'   => array( 'changeset_id' ),
			),
			array( 'type' => 'object' ),
			array( $this, 'get' ),
			Auth::SCOPE_READ,
			'edit_posts',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);

		$this->registry->register(
			'close_changeset',
			__( 'Close a changeset so no further calls can be journalled into it. A closed changeset can still be reverted.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				),
				'required'   => array( 'changeset_id' ),
			),
			array( 'type' => 'object' ),
			array( $this, 'close' ),
			Auth::SCOPE_EDITOR,
			'edit_posts',
			array( 'idempotentHint' => true ),
			array( 'dry_run' => false )
		);

		$this->registry->register(
			'revert_changeset',
			__( 'Undo every change recorded in a changeset, newest first. Restores posts, post meta, term assignments, terms, options, comments, and users. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'changeset_id' => array( 'type' => 'string', 'maxLength' => 64 ),
					'confirm'      => array(
						'type'        => 'boolean',
						'description' => __( 'Must be true. Reverting rewrites live content.', 'mindio-magic-mcp' ),
					),
				),
				'required'   => array( 'changeset_id', 'confirm' ),
			),
			array( 'type' => 'object' ),
			array( $this, 'revert' ),
			Auth::SCOPE_EDITOR,
			'edit_others_posts',
			array( 'destructiveHint' => true ),
			array( 'dry_run' => false )
		);
	}

	/** @return array<string,mixed> */
	public function begin( array $arguments ): array {
		return $this->changesets->begin( (string) $arguments['label'], $this->auth );
	}

	/** @return array<string,mixed> */
	public function list( array $arguments ): array {
		$changesets = $this->changesets->list_changesets(
			absint( $arguments['limit'] ?? 25 ),
			(string) ( $arguments['status'] ?? '' )
		);

		return array( 'count' => count( $changesets ), 'changesets' => $changesets );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get( array $arguments ) {
		$changeset_id = (string) $arguments['changeset_id'];
		$changeset    = $this->changesets->get( $changeset_id );
		if ( ! $changeset ) {
			return new \WP_Error( 'unknown_changeset', __( 'Unknown changeset.', 'mindio-magic-mcp' ) );
		}

		return array( 'changeset' => $changeset, 'entries' => $this->changesets->entries( $changeset_id ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function close( array $arguments ) {
		$changeset_id = (string) $arguments['changeset_id'];
		if ( ! $this->changesets->get( $changeset_id ) ) {
			return new \WP_Error( 'unknown_changeset', __( 'Unknown changeset.', 'mindio-magic-mcp' ) );
		}
		$this->changesets->close( $changeset_id );

		return array( 'changeset_id' => $changeset_id, 'status' => Changeset::STATUS_CLOSED );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function revert( array $arguments ) {
		if ( empty( $arguments['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Reverting a changeset requires confirm=true.', 'mindio-magic-mcp' ) );
		}

		return $this->changesets->revert( (string) $arguments['changeset_id'] );
	}
}
