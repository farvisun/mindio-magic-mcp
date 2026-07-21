<?php
/**
 * Conditional WordPress multisite discovery and per-call context tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Multisite_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$this->registry->register(
			'list_sites',
			__( 'List WordPress multisite sites the network administrator can target with the site_id argument.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'search'   => array( 'type' => 'string', 'maxLength' => 200 ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_sites' ),
			Auth::SCOPE_ADMIN,
			'manage_sites',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'switch_site_context',
			__( 'Validate a multisite site context. Context is intentionally stateless: pass this site_id on every subsequent tool call.', 'mindio-magic-mcp' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'site_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'             => array( 'site_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'site_context' ),
			Auth::SCOPE_ADMIN,
			'manage_sites',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
	}

	/** @return array<string,mixed> */
	public function list_sites( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$query_args = array( 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page, 'count' => false, 'orderby' => 'id', 'order' => 'ASC' );
		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = sanitize_text_field( (string) $args['search'] );
		}
		$sites = get_sites( $query_args );
		$count_args          = $query_args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$total = (int) get_sites( $count_args );
		return array(
			'items'       => array_map( array( $this, 'serialize' ), $sites ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function site_context( array $args ): array|\WP_Error {
		$site = get_site( absint( $args['site_id'] ) );
		if ( ! $site ) {
			return new \WP_Error( 'site_not_found', __( 'Site not found.', 'mindio-magic-mcp' ) );
		}
		return array(
			'site'         => $this->serialize( $site ),
			'context_mode' => 'per_call',
			'instruction'  => __( 'Include this site_id in every tool call that should run on this site.', 'mindio-magic-mcp' ),
		);
	}

	/** @return array<string,mixed> */
	private function serialize( \WP_Site $site ): array {
		$details = get_blog_details( $site->blog_id, false );
		return array(
			'site_id'      => (int) $site->blog_id,
			'domain'       => $site->domain,
			'path'         => $site->path,
			'url'          => get_home_url( $site->blog_id, '/' ),
			'name'         => $details ? $details->blogname : '',
			'public'       => (bool) $site->public,
			'archived'     => (bool) $site->archived,
			'deleted'      => (bool) $site->deleted,
			'spam'         => (bool) $site->spam,
			'current_site' => get_current_blog_id() === (int) $site->blog_id,
		);
	}
}

