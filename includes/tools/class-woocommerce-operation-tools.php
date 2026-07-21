<?php
/**
 * Broad WooCommerce Free coverage through fixed internal wc/v3 routes.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce_Operation_Tools extends Integration_Dispatcher {
	/** @var array<string,array<string,mixed>> */
	private array $descriptors = array();

	public function __construct( Tool_Registry $registry ) {
		parent::__construct( $registry, 'woocommerce', 'WooCommerce Free' );
	}

	public function register(): void {
		$this->descriptors = array_merge( $this->read_descriptors(), $this->write_descriptors() );
		$operations        = array();
		foreach ( $this->descriptors as $name => $descriptor ) {
			$operations[ $name ] = array(
				'mode'            => $descriptor['mode'],
				'label'           => $descriptor['label'],
				'description'     => $descriptor['description'],
				'schema'          => $this->descriptor_schema( $descriptor ),
				'callback'        => function ( array $args ) use ( $name ): array|\WP_Error {
					return $this->execute_operation( $name, $args );
				},
				'capability'      => $descriptor['capability'],
				'destructive'     => ! empty( $descriptor['destructive'] ),
				'default_exposed' => 'read' === $descriptor['mode'],
				'scope'           => $descriptor['scope'],
			);
		}
		$this->register_operations( $operations, Auth::SCOPE_READ, Auth::SCOPE_EDITOR );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function execute_operation( string $name, array $args ): array|\WP_Error {
		$descriptor = $this->descriptors[ $name ] ?? null;
		if ( ! $descriptor ) {
			return new \WP_Error( 'unknown_operation', __( 'Unknown WooCommerce operation.', 'mindio-magic-mcp' ) );
		}
		if ( ! empty( $descriptor['confirm'] ) && empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'This WooCommerce operation requires confirm=true.', 'mindio-magic-mcp' ) );
		}
		$path = (string) $descriptor['path'];
		foreach ( (array) ( $descriptor['params'] ?? array() ) as $param => $schema ) {
			$value = $args[ $param ] ?? null;
			if ( is_int( $value ) ) {
				$value = (string) absint( $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			$path = str_replace( '{' . $param . '}', rawurlencode( $value ), $path );
		}
		$query = isset( $args['query'] ) ? $this->sanitize_payload( (array) $args['query'], true ) : array();
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		if ( ! empty( $descriptor['destructive'] ) ) {
			$query['force'] = ! empty( $args['force'] );
		}
		$data = isset( $args['data'] ) ? $this->sanitize_payload( (array) $args['data'], false ) : array();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$request = new \WP_REST_Request( (string) $descriptor['method'], '/wc/v3' . $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( array_key_exists( 'data', $args ) ) {
			$request->set_body_params( $data );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}
		$status = $response->get_status();
		$body   = $response->get_data();
		if ( $status >= 400 ) {
			return new \WP_Error(
				sanitize_key( (string) ( is_array( $body ) ? ( $body['code'] ?? 'woocommerce_error' ) : 'woocommerce_error' ) ),
				is_array( $body ) ? sanitize_text_field( (string) ( $body['message'] ?? __( 'WooCommerce rejected the operation.', 'mindio-magic-mcp' ) ) ) : __( 'WooCommerce rejected the operation.', 'mindio-magic-mcp' ),
				array( 'status' => $status )
			);
		}
		$headers = $response->get_headers();
		return array(
			'status'      => $status,
			'total'       => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : null,
			'total_pages' => isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : null,
			'data'        => $this->redact_response( $body ),
		);
	}

	protected function dependency_installed(): bool {
		return $this->dependency_available()
			|| $this->plugin_is_installed( array( 'woocommerce/woocommerce.php' ), array( 'woocommerce' ) );
	}

	protected function dependency_available(): bool {
		return class_exists( '\\WooCommerce' ) && function_exists( 'WC' );
	}

	protected function dependency_label(): string {
		return 'WooCommerce Free';
	}

	/** @return array<string,array<string,mixed>> */
	private function read_descriptors(): array {
		$read   = Auth::SCOPE_READ;
		$admin  = Auth::SCOPE_ADMIN;
		$product_cap = 'edit_products';
		$admin_cap   = 'manage_woocommerce';
		$id     = $this->id_param();
		$slug   = $this->slug_param();
		return array(
			'system_status'              => $this->read( __( 'System status', 'mindio-magic-mcp' ), '/system_status', $admin_cap, $admin ),
			'list_system_status_tools'   => $this->read( __( 'List status tools', 'mindio-magic-mcp' ), '/system_status/tools', $admin_cap, $admin ),
			'list_products'              => $this->read( __( 'List products', 'mindio-magic-mcp' ), '/products', $product_cap, $read ),
			'get_product'                => $this->read( __( 'Get product', 'mindio-magic-mcp' ), '/products/{product_id}', $product_cap, $read, array( 'product_id' => $id ) ),
			'list_product_variations'    => $this->read( __( 'List product variations', 'mindio-magic-mcp' ), '/products/{product_id}/variations', $product_cap, $read, array( 'product_id' => $id ) ),
			'get_product_variation'      => $this->read( __( 'Get product variation', 'mindio-magic-mcp' ), '/products/{product_id}/variations/{variation_id}', $product_cap, $read, array( 'product_id' => $id, 'variation_id' => $id ) ),
			'list_product_categories'    => $this->read( __( 'List product categories', 'mindio-magic-mcp' ), '/products/categories', $product_cap, $read ),
			'get_product_category'       => $this->read( __( 'Get product category', 'mindio-magic-mcp' ), '/products/categories/{category_id}', $product_cap, $read, array( 'category_id' => $id ) ),
			'list_product_tags'          => $this->read( __( 'List product tags', 'mindio-magic-mcp' ), '/products/tags', $product_cap, $read ),
			'get_product_tag'           => $this->read( __( 'Get product tag', 'mindio-magic-mcp' ), '/products/tags/{tag_id}', $product_cap, $read, array( 'tag_id' => $id ) ),
			'list_product_attributes'    => $this->read( __( 'List product attributes', 'mindio-magic-mcp' ), '/products/attributes', $product_cap, $read ),
			'get_product_attribute'      => $this->read( __( 'Get product attribute', 'mindio-magic-mcp' ), '/products/attributes/{attribute_id}', $product_cap, $read, array( 'attribute_id' => $id ) ),
			'list_attribute_terms'       => $this->read( __( 'List attribute terms', 'mindio-magic-mcp' ), '/products/attributes/{attribute_id}/terms', $product_cap, $read, array( 'attribute_id' => $id ) ),
			'get_attribute_term'         => $this->read( __( 'Get attribute term', 'mindio-magic-mcp' ), '/products/attributes/{attribute_id}/terms/{term_id}', $product_cap, $read, array( 'attribute_id' => $id, 'term_id' => $id ) ),
			'list_product_reviews'       => $this->read( __( 'List product reviews', 'mindio-magic-mcp' ), '/products/reviews', 'moderate_comments', $admin ),
			'get_product_review'         => $this->read( __( 'Get product review', 'mindio-magic-mcp' ), '/products/reviews/{review_id}', 'moderate_comments', $admin, array( 'review_id' => $id ) ),
			'list_orders'                => $this->read( __( 'List orders', 'mindio-magic-mcp' ), '/orders', 'edit_shop_orders', $admin ),
			'get_order'                  => $this->read( __( 'Get order', 'mindio-magic-mcp' ), '/orders/{order_id}', 'edit_shop_orders', $admin, array( 'order_id' => $id ) ),
			'list_order_notes'           => $this->read( __( 'List order notes', 'mindio-magic-mcp' ), '/orders/{order_id}/notes', 'edit_shop_orders', $admin, array( 'order_id' => $id ) ),
			'get_order_note'             => $this->read( __( 'Get order note', 'mindio-magic-mcp' ), '/orders/{order_id}/notes/{note_id}', 'edit_shop_orders', $admin, array( 'order_id' => $id, 'note_id' => $id ) ),
			'list_refunds'               => $this->read( __( 'List order refunds', 'mindio-magic-mcp' ), '/orders/{order_id}/refunds', 'edit_shop_orders', $admin, array( 'order_id' => $id ) ),
			'get_refund'                 => $this->read( __( 'Get order refund', 'mindio-magic-mcp' ), '/orders/{order_id}/refunds/{refund_id}', 'edit_shop_orders', $admin, array( 'order_id' => $id, 'refund_id' => $id ) ),
			'list_coupons'               => $this->read( __( 'List coupons', 'mindio-magic-mcp' ), '/coupons', 'manage_woocommerce', $admin ),
			'get_coupon'                 => $this->read( __( 'Get coupon', 'mindio-magic-mcp' ), '/coupons/{coupon_id}', 'manage_woocommerce', $admin, array( 'coupon_id' => $id ) ),
			'list_customers'             => $this->read( __( 'List customers', 'mindio-magic-mcp' ), '/customers', $admin_cap, $admin ),
			'get_customer'               => $this->read( __( 'Get customer', 'mindio-magic-mcp' ), '/customers/{customer_id}', $admin_cap, $admin, array( 'customer_id' => $id ) ),
			'get_customer_downloads'     => $this->read( __( 'Get customer downloads', 'mindio-magic-mcp' ), '/customers/{customer_id}/downloads', $admin_cap, $admin, array( 'customer_id' => $id ) ),
			'list_tax_rates'             => $this->read( __( 'List tax rates', 'mindio-magic-mcp' ), '/taxes', $admin_cap, $admin ),
			'get_tax_rate'               => $this->read( __( 'Get tax rate', 'mindio-magic-mcp' ), '/taxes/{tax_id}', $admin_cap, $admin, array( 'tax_id' => $id ) ),
			'list_tax_classes'           => $this->read( __( 'List tax classes', 'mindio-magic-mcp' ), '/taxes/classes', $admin_cap, $admin ),
			'list_shipping_zones'        => $this->read( __( 'List shipping zones', 'mindio-magic-mcp' ), '/shipping/zones', $admin_cap, $admin ),
			'get_shipping_zone'          => $this->read( __( 'Get shipping zone', 'mindio-magic-mcp' ), '/shipping/zones/{zone_id}', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'list_zone_locations'        => $this->read( __( 'List shipping-zone locations', 'mindio-magic-mcp' ), '/shipping/zones/{zone_id}/locations', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'list_zone_methods'          => $this->read( __( 'List shipping-zone methods', 'mindio-magic-mcp' ), '/shipping/zones/{zone_id}/methods', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'get_zone_method'            => $this->read( __( 'Get shipping-zone method', 'mindio-magic-mcp' ), '/shipping/zones/{zone_id}/methods/{instance_id}', $admin_cap, $admin, array( 'zone_id' => $id, 'instance_id' => $id ) ),
			'list_shipping_methods'      => $this->read( __( 'List shipping methods', 'mindio-magic-mcp' ), '/shipping_methods', $admin_cap, $admin ),
			'list_payment_gateways'      => $this->read( __( 'List payment gateways', 'mindio-magic-mcp' ), '/payment_gateways', $admin_cap, $admin ),
			'get_payment_gateway'        => $this->read( __( 'Get payment gateway', 'mindio-magic-mcp' ), '/payment_gateways/{gateway_id}', $admin_cap, $admin, array( 'gateway_id' => $slug ) ),
			'list_setting_groups'        => $this->read( __( 'List setting groups', 'mindio-magic-mcp' ), '/settings', $admin_cap, $admin ),
			'get_setting_group'          => $this->read( __( 'Get setting group', 'mindio-magic-mcp' ), '/settings/{group_id}', $admin_cap, $admin, array( 'group_id' => $slug ) ),
			'get_setting_option'         => $this->read( __( 'Get setting option', 'mindio-magic-mcp' ), '/settings/{group_id}/{setting_id}', $admin_cap, $admin, array( 'group_id' => $slug, 'setting_id' => $slug ) ),
			'list_webhooks'              => $this->read( __( 'List WooCommerce webhooks', 'mindio-magic-mcp' ), '/webhooks', $admin_cap, $admin ),
			'get_webhook'                => $this->read( __( 'Get WooCommerce webhook', 'mindio-magic-mcp' ), '/webhooks/{webhook_id}', $admin_cap, $admin, array( 'webhook_id' => $id ) ),
			'list_countries'             => $this->read( __( 'List countries', 'mindio-magic-mcp' ), '/data/countries', $admin_cap, $read ),
			'list_currencies'            => $this->read( __( 'List currencies', 'mindio-magic-mcp' ), '/data/currencies', $admin_cap, $read ),
			'list_continents'            => $this->read( __( 'List continents', 'mindio-magic-mcp' ), '/data/continents', $admin_cap, $read ),
			'get_sales_report'           => $this->read( __( 'Get sales report', 'mindio-magic-mcp' ), '/reports/sales', 'view_woocommerce_reports', $admin ),
			'get_top_sellers_report'     => $this->read( __( 'Get top sellers report', 'mindio-magic-mcp' ), '/reports/top_sellers', 'view_woocommerce_reports', $admin ),
			'get_orders_report'          => $this->read( __( 'Get order totals report', 'mindio-magic-mcp' ), '/reports/orders/totals', 'view_woocommerce_reports', $admin ),
			'get_products_report'        => $this->read( __( 'Get product totals report', 'mindio-magic-mcp' ), '/reports/products/totals', 'view_woocommerce_reports', $admin ),
			'get_customers_report'       => $this->read( __( 'Get customer totals report', 'mindio-magic-mcp' ), '/reports/customers/totals', 'view_woocommerce_reports', $admin ),
			'get_coupons_report'         => $this->read( __( 'Get coupon totals report', 'mindio-magic-mcp' ), '/reports/coupons/totals', 'view_woocommerce_reports', $admin ),
			'get_reviews_report'         => $this->read( __( 'Get review totals report', 'mindio-magic-mcp' ), '/reports/reviews/totals', 'view_woocommerce_reports', $admin ),
		);
	}

	/** @return array<string,array<string,mixed>> */
	private function write_descriptors(): array {
		$editor = Auth::SCOPE_EDITOR;
		$admin  = Auth::SCOPE_ADMIN;
		$id     = $this->id_param();
		$slug   = $this->slug_param();
		$product_cap = 'edit_products';
		$admin_cap   = 'manage_woocommerce';
		$definitions = array(
			'create_product'            => $this->write( __( 'Create product', 'mindio-magic-mcp' ), 'POST', '/products', $product_cap, $editor ),
			'update_product'            => $this->write( __( 'Update product', 'mindio-magic-mcp' ), 'PUT', '/products/{product_id}', $product_cap, $editor, array( 'product_id' => $id ) ),
			'delete_product'            => $this->delete( __( 'Delete product', 'mindio-magic-mcp' ), '/products/{product_id}', $product_cap, $editor, array( 'product_id' => $id ) ),
			'create_product_variation'  => $this->write( __( 'Create product variation', 'mindio-magic-mcp' ), 'POST', '/products/{product_id}/variations', $product_cap, $editor, array( 'product_id' => $id ) ),
			'update_product_variation'  => $this->write( __( 'Update product variation', 'mindio-magic-mcp' ), 'PUT', '/products/{product_id}/variations/{variation_id}', $product_cap, $editor, array( 'product_id' => $id, 'variation_id' => $id ) ),
			'delete_product_variation'  => $this->delete( __( 'Delete product variation', 'mindio-magic-mcp' ), '/products/{product_id}/variations/{variation_id}', $product_cap, $editor, array( 'product_id' => $id, 'variation_id' => $id ) ),
			'create_product_category'   => $this->write( __( 'Create product category', 'mindio-magic-mcp' ), 'POST', '/products/categories', $product_cap, $editor ),
			'update_product_category'   => $this->write( __( 'Update product category', 'mindio-magic-mcp' ), 'PUT', '/products/categories/{category_id}', $product_cap, $editor, array( 'category_id' => $id ) ),
			'delete_product_category'   => $this->delete( __( 'Delete product category', 'mindio-magic-mcp' ), '/products/categories/{category_id}', $product_cap, $editor, array( 'category_id' => $id ) ),
			'create_product_tag'        => $this->write( __( 'Create product tag', 'mindio-magic-mcp' ), 'POST', '/products/tags', $product_cap, $editor ),
			'update_product_tag'        => $this->write( __( 'Update product tag', 'mindio-magic-mcp' ), 'PUT', '/products/tags/{tag_id}', $product_cap, $editor, array( 'tag_id' => $id ) ),
			'delete_product_tag'        => $this->delete( __( 'Delete product tag', 'mindio-magic-mcp' ), '/products/tags/{tag_id}', $product_cap, $editor, array( 'tag_id' => $id ) ),
			'create_product_attribute'  => $this->write( __( 'Create product attribute', 'mindio-magic-mcp' ), 'POST', '/products/attributes', $product_cap, $editor ),
			'update_product_attribute'  => $this->write( __( 'Update product attribute', 'mindio-magic-mcp' ), 'PUT', '/products/attributes/{attribute_id}', $product_cap, $editor, array( 'attribute_id' => $id ) ),
			'delete_product_attribute'  => $this->delete( __( 'Delete product attribute', 'mindio-magic-mcp' ), '/products/attributes/{attribute_id}', $product_cap, $editor, array( 'attribute_id' => $id ) ),
			'create_attribute_term'     => $this->write( __( 'Create attribute term', 'mindio-magic-mcp' ), 'POST', '/products/attributes/{attribute_id}/terms', $product_cap, $editor, array( 'attribute_id' => $id ) ),
			'update_attribute_term'     => $this->write( __( 'Update attribute term', 'mindio-magic-mcp' ), 'PUT', '/products/attributes/{attribute_id}/terms/{term_id}', $product_cap, $editor, array( 'attribute_id' => $id, 'term_id' => $id ) ),
			'delete_attribute_term'     => $this->delete( __( 'Delete attribute term', 'mindio-magic-mcp' ), '/products/attributes/{attribute_id}/terms/{term_id}', $product_cap, $editor, array( 'attribute_id' => $id, 'term_id' => $id ) ),
			'create_product_review'     => $this->write( __( 'Create product review', 'mindio-magic-mcp' ), 'POST', '/products/reviews', 'moderate_comments', $admin ),
			'update_product_review'     => $this->write( __( 'Update product review', 'mindio-magic-mcp' ), 'PUT', '/products/reviews/{review_id}', 'moderate_comments', $admin, array( 'review_id' => $id ) ),
			'delete_product_review'     => $this->delete( __( 'Delete product review', 'mindio-magic-mcp' ), '/products/reviews/{review_id}', 'moderate_comments', $admin, array( 'review_id' => $id ) ),
			'create_order'              => $this->write( __( 'Create order', 'mindio-magic-mcp' ), 'POST', '/orders', 'edit_shop_orders', $admin ),
			'update_order'              => $this->write( __( 'Update order', 'mindio-magic-mcp' ), 'PUT', '/orders/{order_id}', 'edit_shop_orders', $admin, array( 'order_id' => $id ) ),
			'delete_order'              => $this->delete( __( 'Delete order', 'mindio-magic-mcp' ), '/orders/{order_id}', 'delete_shop_orders', $admin, array( 'order_id' => $id ) ),
			'create_order_note'         => $this->write( __( 'Create order note', 'mindio-magic-mcp' ), 'POST', '/orders/{order_id}/notes', 'edit_shop_orders', $admin, array( 'order_id' => $id ) ),
			'delete_order_note'         => $this->delete( __( 'Delete order note', 'mindio-magic-mcp' ), '/orders/{order_id}/notes/{note_id}', 'edit_shop_orders', $admin, array( 'order_id' => $id, 'note_id' => $id ) ),
			'create_refund'             => $this->write( __( 'Create order refund', 'mindio-magic-mcp' ), 'POST', '/orders/{order_id}/refunds', 'edit_shop_orders', $admin, array( 'order_id' => $id ), true ),
			'delete_refund'             => $this->delete( __( 'Delete order refund', 'mindio-magic-mcp' ), '/orders/{order_id}/refunds/{refund_id}', 'edit_shop_orders', $admin, array( 'order_id' => $id, 'refund_id' => $id ) ),
			'create_coupon'             => $this->write( __( 'Create coupon', 'mindio-magic-mcp' ), 'POST', '/coupons', $admin_cap, $admin ),
			'update_coupon'             => $this->write( __( 'Update coupon', 'mindio-magic-mcp' ), 'PUT', '/coupons/{coupon_id}', $admin_cap, $admin, array( 'coupon_id' => $id ) ),
			'delete_coupon'             => $this->delete( __( 'Delete coupon', 'mindio-magic-mcp' ), '/coupons/{coupon_id}', $admin_cap, $admin, array( 'coupon_id' => $id ) ),
			'create_customer'           => $this->write( __( 'Create customer', 'mindio-magic-mcp' ), 'POST', '/customers', $admin_cap, $admin ),
			'update_customer'           => $this->write( __( 'Update customer', 'mindio-magic-mcp' ), 'PUT', '/customers/{customer_id}', $admin_cap, $admin, array( 'customer_id' => $id ) ),
			'delete_customer'           => $this->delete( __( 'Delete customer', 'mindio-magic-mcp' ), '/customers/{customer_id}', $admin_cap, $admin, array( 'customer_id' => $id ) ),
			'create_tax_rate'           => $this->write( __( 'Create tax rate', 'mindio-magic-mcp' ), 'POST', '/taxes', $admin_cap, $admin ),
			'update_tax_rate'           => $this->write( __( 'Update tax rate', 'mindio-magic-mcp' ), 'PUT', '/taxes/{tax_id}', $admin_cap, $admin, array( 'tax_id' => $id ) ),
			'delete_tax_rate'           => $this->delete( __( 'Delete tax rate', 'mindio-magic-mcp' ), '/taxes/{tax_id}', $admin_cap, $admin, array( 'tax_id' => $id ) ),
			'create_tax_class'          => $this->write( __( 'Create tax class', 'mindio-magic-mcp' ), 'POST', '/taxes/classes', $admin_cap, $admin ),
			'delete_tax_class'          => $this->delete( __( 'Delete tax class', 'mindio-magic-mcp' ), '/taxes/classes/{tax_class_slug}', $admin_cap, $admin, array( 'tax_class_slug' => $slug ) ),
			'create_shipping_zone'      => $this->write( __( 'Create shipping zone', 'mindio-magic-mcp' ), 'POST', '/shipping/zones', $admin_cap, $admin ),
			'update_shipping_zone'      => $this->write( __( 'Update shipping zone', 'mindio-magic-mcp' ), 'PUT', '/shipping/zones/{zone_id}', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'delete_shipping_zone'      => $this->delete( __( 'Delete shipping zone', 'mindio-magic-mcp' ), '/shipping/zones/{zone_id}', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'update_zone_locations'     => $this->write( __( 'Update shipping-zone locations', 'mindio-magic-mcp' ), 'PUT', '/shipping/zones/{zone_id}/locations', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'create_zone_method'        => $this->write( __( 'Create shipping-zone method', 'mindio-magic-mcp' ), 'POST', '/shipping/zones/{zone_id}/methods', $admin_cap, $admin, array( 'zone_id' => $id ) ),
			'update_zone_method'        => $this->write( __( 'Update shipping-zone method', 'mindio-magic-mcp' ), 'PUT', '/shipping/zones/{zone_id}/methods/{instance_id}', $admin_cap, $admin, array( 'zone_id' => $id, 'instance_id' => $id ) ),
			'delete_zone_method'        => $this->delete( __( 'Delete shipping-zone method', 'mindio-magic-mcp' ), '/shipping/zones/{zone_id}/methods/{instance_id}', $admin_cap, $admin, array( 'zone_id' => $id, 'instance_id' => $id ) ),
			'update_payment_gateway'    => $this->write( __( 'Update payment gateway', 'mindio-magic-mcp' ), 'PUT', '/payment_gateways/{gateway_id}', $admin_cap, $admin, array( 'gateway_id' => $slug ) ),
			'update_setting_option'     => $this->write( __( 'Update setting option', 'mindio-magic-mcp' ), 'PUT', '/settings/{group_id}/{setting_id}', $admin_cap, $admin, array( 'group_id' => $slug, 'setting_id' => $slug ), true ),
			'create_webhook'            => $this->write( __( 'Create WooCommerce webhook', 'mindio-magic-mcp' ), 'POST', '/webhooks', $admin_cap, $admin, array(), true ),
			'update_webhook'            => $this->write( __( 'Update WooCommerce webhook', 'mindio-magic-mcp' ), 'PUT', '/webhooks/{webhook_id}', $admin_cap, $admin, array( 'webhook_id' => $id ), true ),
			'delete_webhook'            => $this->delete( __( 'Delete WooCommerce webhook', 'mindio-magic-mcp' ), '/webhooks/{webhook_id}', $admin_cap, $admin, array( 'webhook_id' => $id ) ),
			'run_system_status_tool'    => $this->write( __( 'Run system status tool', 'mindio-magic-mcp' ), 'PUT', '/system_status/tools/{tool_id}', $admin_cap, $admin, array( 'tool_id' => $slug ), true, false ),
		);
		return $definitions;
	}

	/** @return array<string,mixed> */
	private function read( string $label, string $path, string $capability, string $scope, array $params = array() ): array {
		return array(
			'mode'        => 'read',
			'label'       => $label,
			'description' => sprintf(
				/* translators: %s: fixed WooCommerce REST API path. */
				__( 'Read WooCommerce data using the fixed %s endpoint.', 'mindio-magic-mcp' ),
				$path
			),
			'method'      => 'GET',
			'path'        => $path,
			'params'      => $params,
			'capability'  => $capability,
			'scope'       => $scope,
		);
	}

	/** @return array<string,mixed> */
	private function write( string $label, string $method, string $path, string $capability, string $scope, array $params = array(), bool $confirm = false, bool $data_required = true ): array {
		return array(
			'mode'          => 'write',
			'label'         => $label,
			'description'   => sprintf(
				/* translators: %s: fixed WooCommerce REST API path. */
				__( 'Mutate WooCommerce data using the fixed %s endpoint.', 'mindio-magic-mcp' ),
				$path
			),
			'method'        => $method,
			'path'          => $path,
			'params'        => $params,
			'capability'    => $capability,
			'scope'         => $scope,
			'confirm'       => $confirm,
			'data_required' => $data_required,
		);
	}

	/** @return array<string,mixed> */
	private function delete( string $label, string $path, string $capability, string $scope, array $params ): array {
		$descriptor                = $this->write( $label, 'DELETE', $path, $capability, $scope, $params, true, false );
		$descriptor['destructive'] = true;
		return $descriptor;
	}

	/** @return array<string,mixed> */
	private function descriptor_schema( array $descriptor ): array {
		$properties = (array) ( $descriptor['params'] ?? array() );
		$required   = array_keys( $properties );
		if ( 'read' === $descriptor['mode'] ) {
			$properties['query'] = array( 'type' => 'object' );
		} else {
			$properties['data']  = array( 'type' => array( 'object', 'array' ) );
			$properties['query'] = array( 'type' => 'object' );
			if ( ! empty( $descriptor['data_required'] ) ) {
				$required[] = 'data';
			}
			if ( ! empty( $descriptor['confirm'] ) ) {
				$properties['confirm'] = array( 'type' => 'boolean' );
				$required[]             = 'confirm';
			}
			if ( ! empty( $descriptor['destructive'] ) ) {
				$properties['force'] = array( 'type' => 'boolean' );
			}
		}
		return $this->object_schema( $properties, $required );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sanitize_payload( array $payload, bool $query ): array|\WP_Error {
		$encoded = wp_json_encode( $payload );
		if ( false === $encoded || strlen( $encoded ) > 524288 ) {
			return new \WP_Error( 'woocommerce_payload_too_large', __( 'WooCommerce operation payloads may not exceed 512 KB.', 'mindio-magic-mcp' ) );
		}
		$nodes = 0;
		$walk  = function ( mixed $value, string $key = '', int $depth = 0 ) use ( &$walk, &$nodes, $query ): mixed {
			++$nodes;
			if ( $nodes > 5000 || $depth > 12 ) {
				return new \WP_Error( 'woocommerce_payload_too_complex', __( 'WooCommerce operation payload is too deeply nested or complex.', 'mindio-magic-mcp' ) );
			}
			if ( preg_match( '/^(?:password|consumer_secret|secret|private_key|access_token|authorization)$/i', $key ) ) {
				return new \WP_Error( 'secret_write_forbidden', __( 'Secret and password fields are not writable through the WooCommerce MCP dispatcher.', 'mindio-magic-mcp' ) );
			}
			if ( is_string( $value ) ) {
				if ( strlen( $value ) > 100000 || str_contains( $value, "\0" ) ) {
					return new \WP_Error( 'invalid_woocommerce_value', __( 'A WooCommerce payload value is unsafe or too long.', 'mindio-magic-mcp' ) );
				}
				return $query ? sanitize_text_field( $value ) : wp_kses_post( $value );
			}
			if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				return $value;
			}
			if ( ! is_array( $value ) ) {
				return new \WP_Error( 'invalid_woocommerce_value', __( 'WooCommerce payload values must be valid JSON values.', 'mindio-magic-mcp' ) );
			}
			$output = array();
			foreach ( $value as $child_key => $child ) {
				if ( ! is_int( $child_key ) && ( ! preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', (string) $child_key ) || ( $query && in_array( strtolower( (string) $child_key ), array( '_jsonp', 'rest_route' ), true ) ) ) ) {
					return new \WP_Error( 'invalid_woocommerce_key', __( 'WooCommerce payload contains an invalid or reserved key.', 'mindio-magic-mcp' ) );
				}
				$clean = $walk( $child, (string) $child_key, $depth + 1 );
				if ( is_wp_error( $clean ) ) {
					return $clean;
				}
				$output[ $child_key ] = $clean;
			}
			return $output;
		};
		$clean = $walk( $payload );
		return is_wp_error( $clean ) ? $clean : (array) $clean;
	}

	private function redact_response( mixed $value, string $key = '', int $depth = 0 ): mixed {
		if ( preg_match( '/(?:consumer_secret|client_secret|password|private_key|access_token|authorization|webhook_secret|api_secret)/i', $key ) ) {
			return '[redacted]';
		}
		if ( $depth > 14 ) {
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
		foreach ( array_slice( $value, 0, 2000, true ) as $child_key => $child ) {
			$output[ $child_key ] = $this->redact_response( $child, (string) $child_key, $depth + 1 );
		}
		return $output;
	}

	/** @return array<string,mixed> */
	private function id_param(): array {
		return array( 'type' => 'integer', 'minimum' => 1 );
	}

	/** @return array<string,mixed> */
	private function slug_param(): array {
		return array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{1,100}$' );
	}
}
