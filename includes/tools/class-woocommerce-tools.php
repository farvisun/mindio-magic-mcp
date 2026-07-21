<?php
/**
 * Conditional WooCommerce product, order, customer, inventory, and coupon tools.
 *
 * @package FlatsomeMCP
 */

namespace FlatsomeMCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce_Tools {
	private Tool_Registry $registry;

	public function __construct( Tool_Registry $registry ) {
		$this->registry = $registry;
	}

	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$this->registry->register(
			'create_product',
			__( 'Create a WooCommerce simple product with pricing, images, categories, SKU, and inventory fields.', 'mindio-magic-mcp' ),
			$this->product_schema( false ),
			array( 'type' => 'object' ),
			array( $this, 'create_product' ),
			Auth::SCOPE_ADMIN,
			array( $this, 'can_create_product' )
		);
		$this->registry->register(
			'update_product',
			__( 'Update selected fields on an existing WooCommerce product.', 'mindio-magic-mcp' ),
			$this->product_schema( true ),
			array( 'type' => 'object' ),
			array( $this, 'update_product' ),
			Auth::SCOPE_ADMIN,
			array( $this, 'can_update_product' ),
			array( 'idempotentHint' => true )
		);
		$this->registry->register(
			'list_orders',
			__( 'List WooCommerce orders through the HPOS-compatible order API with status, customer, date, and pagination filters.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'status'      => array( 'type' => 'string', 'maxLength' => 50 ),
					'customer_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'after'       => array( 'type' => 'string', 'format' => 'date-time' ),
					'before'      => array( 'type' => 'string', 'format' => 'date-time' ),
					'page'        => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'list_orders' ),
			Auth::SCOPE_READ,
			'manage_woocommerce',
			array( 'readOnlyHint' => true, 'idempotentHint' => true )
		);
		$this->registry->register(
			'manage_customers',
			__( 'List, create, or update WooCommerce customer accounts without exposing passwords.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'action'       => array( 'type' => 'string', 'enum' => array( 'list', 'create', 'update' ) ),
					'user_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
					'email'        => array( 'type' => 'string', 'maxLength' => 254 ),
					'username'     => array( 'type' => 'string', 'maxLength' => 60 ),
					'first_name'   => array( 'type' => 'string', 'maxLength' => 100 ),
					'last_name'    => array( 'type' => 'string', 'maxLength' => 100 ),
					'billing_phone' => array( 'type' => 'string', 'maxLength' => 100 ),
					'search'       => array( 'type' => 'string', 'maxLength' => 200 ),
					'page'         => array( 'type' => 'integer', 'minimum' => 1 ),
					'per_page'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					'send_invite'  => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'action' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'manage_customers' ),
			Auth::SCOPE_ADMIN,
			'manage_woocommerce'
		);
		$this->registry->register(
			'manage_inventory',
			__( 'Set or adjust WooCommerce product stock and stock status.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'product_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'stock_quantity' => array( 'type' => 'integer', 'minimum' => 0 ),
					'adjustment'     => array( 'type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000 ),
					'stock_status'   => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				),
				'required'             => array( 'product_id' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'manage_inventory' ),
			Auth::SCOPE_ADMIN,
			'edit_products'
		);
		$this->registry->register(
			'apply_coupons',
			__( 'Apply or remove a coupon on an existing WooCommerce order and recalculate totals. Requires confirm=true.', 'mindio-magic-mcp' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'order_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'coupon_code' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
					'action'      => array( 'type' => 'string', 'enum' => array( 'apply', 'remove' ) ),
					'confirm'     => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'order_id', 'coupon_code', 'action', 'confirm' ),
				'additionalProperties' => false,
			),
			array( 'type' => 'object' ),
			array( $this, 'apply_coupons' ),
			Auth::SCOPE_ADMIN,
			'manage_woocommerce'
		);
	}

	public function can_create_product( array $args ): bool {
		return current_user_can( 'edit_products' )
			&& ( ! in_array( $args['status'] ?? 'draft', array( 'publish', 'private' ), true ) || current_user_can( 'publish_products' ) );
	}

	public function can_update_product( array $args ): bool {
		$product_id = absint( $args['product_id'] ?? 0 );
		return $product_id > 0
			&& current_user_can( 'edit_post', $product_id )
			&& ( ! in_array( $args['status'] ?? '', array( 'publish', 'private' ), true ) || current_user_can( 'publish_products' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create_product( array $args ): array|\WP_Error {
		$media = $this->validate_product_media( $args );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		try {
			$product = new \WC_Product_Simple();
			$this->apply_product_fields( $product, $args );
			$product->save();
			return $this->serialize_product( $product );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'product_create_failed', $error->getMessage() );
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public function update_product( array $args ): array|\WP_Error {
		$product = wc_get_product( absint( $args['product_id'] ) );
		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'mindio-magic-mcp' ) );
		}
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return new \WP_Error( 'forbidden', __( 'Your user cannot edit this product.', 'mindio-magic-mcp' ) );
		}
		$media = $this->validate_product_media( $args );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		try {
			$this->apply_product_fields( $product, $args );
			$product->save();
			return $this->serialize_product( $product );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'product_update_failed', $error->getMessage() );
		}
	}

	/** @return array<string,mixed> */
	public function list_orders( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$query = array( 'paginate' => true, 'limit' => $per_page, 'page' => $page, 'orderby' => 'date', 'order' => 'DESC' );
		if ( ! empty( $args['status'] ) ) {
			$query['status'] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['customer_id'] ) ) {
			$query['customer_id'] = absint( $args['customer_id'] );
		}
		if ( ! empty( $args['after'] ) || ! empty( $args['before'] ) ) {
			$after  = ! empty( $args['after'] ) ? strtotime( (string) $args['after'] ) : 0;
			$before = ! empty( $args['before'] ) ? strtotime( (string) $args['before'] ) : time();
			$query['date_created'] = $after . '...' . $before;
		}
		$result = wc_get_orders( $query );
		$orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : array();
		return array(
			'items'       => array_map( array( $this, 'serialize_order' ), $orders ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) ( $result->total ?? count( $orders ) ),
			'total_pages' => (int) ( $result->max_num_pages ?? 1 ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function manage_customers( array $args ): array|\WP_Error {
		$action = (string) $args['action'];
		if ( 'list' === $action ) {
			$page     = max( 1, absint( $args['page'] ?? 1 ) );
			$per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
			$query_args = array( 'role' => 'customer', 'number' => $per_page, 'paged' => $page, 'count_total' => true );
			if ( ! empty( $args['search'] ) ) {
				$query_args['search'] = '*' . sanitize_text_field( (string) $args['search'] ) . '*';
			}
			$query = new \WP_User_Query( $query_args );
			return array( 'items' => array_map( array( $this, 'serialize_customer' ), $query->get_results() ), 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->get_total() );
		}

		if ( 'create' === $action ) {
			$email = sanitize_email( (string) ( $args['email'] ?? '' ) );
			if ( ! is_email( $email ) ) {
				return new \WP_Error( 'invalid_email', __( 'A valid customer email is required.', 'mindio-magic-mcp' ) );
			}
			$user_id = wc_create_new_customer(
				$email,
				sanitize_user( (string) ( $args['username'] ?? '' ), true ),
				wp_generate_password( 32, true, true ),
				array( 'first_name' => sanitize_text_field( (string) ( $args['first_name'] ?? '' ) ), 'last_name' => sanitize_text_field( (string) ( $args['last_name'] ?? '' ) ) )
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			if ( ! array_key_exists( 'send_invite', $args ) || $args['send_invite'] ) {
				wp_new_user_notification( $user_id, null, 'user' );
			}
			if ( isset( $args['billing_phone'] ) ) {
				update_user_meta( $user_id, 'billing_phone', wc_clean( $args['billing_phone'] ) );
			}
			return $this->serialize_customer( get_user_by( 'id', $user_id ) );
		}

		$user_id = absint( $args['user_id'] ?? 0 );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'customer', $user->roles, true ) ) {
			return new \WP_Error( 'customer_not_found', __( 'Customer not found.', 'mindio-magic-mcp' ) );
		}
		$update = array( 'ID' => $user_id );
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( isset( $args[ $field ] ) ) {
				$update[ $field ] = sanitize_text_field( (string) $args[ $field ] );
			}
		}
		if ( isset( $args['email'] ) ) {
			$email = sanitize_email( (string) $args['email'] );
			if ( ! is_email( $email ) ) {
				return new \WP_Error( 'invalid_email', __( 'A valid customer email is required.', 'mindio-magic-mcp' ) );
			}
			$update['user_email'] = $email;
		}
		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( isset( $args['billing_phone'] ) ) {
			update_user_meta( $user_id, 'billing_phone', wc_clean( $args['billing_phone'] ) );
		}
		return $this->serialize_customer( get_user_by( 'id', $user_id ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function manage_inventory( array $args ): array|\WP_Error {
		$product = wc_get_product( absint( $args['product_id'] ) );
		if ( ! $product || ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found or not editable.', 'mindio-magic-mcp' ) );
		}
		if ( ! isset( $args['stock_quantity'] ) && ! isset( $args['adjustment'] ) && ! isset( $args['stock_status'] ) ) {
			return new \WP_Error( 'inventory_value_required', __( 'Provide stock_quantity, adjustment, or stock_status.', 'mindio-magic-mcp' ) );
		}
		if ( isset( $args['stock_quantity'] ) && isset( $args['adjustment'] ) ) {
			return new \WP_Error( 'ambiguous_inventory', __( 'Use stock_quantity or adjustment, not both.', 'mindio-magic-mcp' ) );
		}
		$product->set_manage_stock( true );
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_stock_quantity( max( 0, (int) $args['stock_quantity'] ) );
		} elseif ( isset( $args['adjustment'] ) ) {
			$product->set_stock_quantity( max( 0, (int) $product->get_stock_quantity() + (int) $args['adjustment'] ) );
		}
		if ( isset( $args['stock_status'] ) ) {
			$product->set_stock_status( (string) $args['stock_status'] );
		} elseif ( 0 === (int) $product->get_stock_quantity() ) {
			$product->set_stock_status( 'outofstock' );
		}
		$product->save();
		return array( 'product_id' => $product->get_id(), 'stock_quantity' => $product->get_stock_quantity(), 'stock_status' => $product->get_stock_status(), 'manage_stock' => $product->get_manage_stock() );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function apply_coupons( array $args ): array|\WP_Error {
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Order coupon changes require confirm=true.', 'mindio-magic-mcp' ) );
		}
		$order = wc_get_order( absint( $args['order_id'] ) );
		if ( ! $order ) {
			return new \WP_Error( 'order_not_found', __( 'Order not found.', 'mindio-magic-mcp' ) );
		}
		$code = wc_format_coupon_code( (string) $args['coupon_code'] );
		if ( 'apply' === $args['action'] ) {
			$result = $order->apply_coupon( $code );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			$order->remove_coupon( $code );
		}
		$order->calculate_totals( true );
		$order->save();
		return array( 'order_id' => $order->get_id(), 'coupons' => $order->get_coupon_codes(), 'discount_total' => $order->get_discount_total(), 'total' => $order->get_total(), 'currency' => $order->get_currency() );
	}

	private function product_schema( bool $updating ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'             => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
				'status'           => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
				'description'      => array( 'type' => 'string', 'maxLength' => 1000000 ),
				'short_description' => array( 'type' => 'string', 'maxLength' => 100000 ),
				'sku'              => array( 'type' => 'string', 'maxLength' => 100 ),
				'regular_price'    => array( 'type' => 'string', 'pattern' => '^\d+(?:\.\d+)?$' ),
				'sale_price'       => array( 'type' => 'string', 'pattern' => '^(?:\d+(?:\.\d+)?)?$' ),
				'manage_stock'     => array( 'type' => 'boolean' ),
				'stock_quantity'   => array( 'type' => 'integer', 'minimum' => 0 ),
				'stock_status'     => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'category_ids'     => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'image_id'         => array( 'type' => 'integer', 'minimum' => 0 ),
				'gallery_image_ids' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'virtual'          => array( 'type' => 'boolean' ),
			),
			'required'             => $updating ? array( 'product_id' ) : array( 'name' ),
			'additionalProperties' => false,
		);
	}

	private function apply_product_fields( \WC_Product $product, array $args ): void {
		$setters = array(
			'name'              => 'set_name',
			'status'            => 'set_status',
			'sku'               => 'set_sku',
			'manage_stock'      => 'set_manage_stock',
			'stock_quantity'    => 'set_stock_quantity',
			'stock_status'      => 'set_stock_status',
			'category_ids'      => 'set_category_ids',
			'image_id'          => 'set_image_id',
			'gallery_image_ids' => 'set_gallery_image_ids',
			'virtual'           => 'set_virtual',
		);
		foreach ( $setters as $field => $setter ) {
			if ( array_key_exists( $field, $args ) ) {
				$product->{$setter}( $args[ $field ] );
			}
		}
		if ( isset( $args['description'] ) ) {
			$product->set_description( wp_kses_post( (string) $args['description'] ) );
		}
		if ( isset( $args['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( (string) $args['short_description'] ) );
		}
		if ( isset( $args['regular_price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $args['regular_price'] ) );
		}
		if ( isset( $args['sale_price'] ) ) {
			$product->set_sale_price( '' === $args['sale_price'] ? '' : wc_format_decimal( $args['sale_price'] ) );
		}
	}

	/** @return bool|\WP_Error */
	private function validate_product_media( array $args ): bool|\WP_Error {
		$ids = array();
		if ( ! empty( $args['image_id'] ) ) {
			$ids[] = absint( $args['image_id'] );
		}
		foreach ( (array) ( $args['gallery_image_ids'] ?? array() ) as $image_id ) {
			$ids[] = absint( $image_id );
		}
		foreach ( array_unique( $ids ) as $image_id ) {
			if ( ! wp_attachment_is_image( $image_id ) ) {
				return new \WP_Error(
					'invalid_product_image',
					sprintf(
						/* translators: %d: WordPress media attachment ID. */
						__( 'Media ID %d is not an image attachment.', 'mindio-magic-mcp' ),
						$image_id
					)
				);
			}
		}
		return true;
	}

	/** @return array<string,mixed> */
	private function serialize_product( \WC_Product $product ): array {
		return array(
			'product_id'        => $product->get_id(),
			'name'              => $product->get_name(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'sku'               => $product->get_sku(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'stock_status'      => $product->get_stock_status(),
			'image_id'          => $product->get_image_id(),
			'gallery_image_ids' => $product->get_gallery_image_ids(),
			'url'               => $product->get_permalink(),
		);
	}

	/** @return array<string,mixed> */
	private function serialize_order( \WC_Order $order ): array {
		$date = $order->get_date_created();
		return array(
			'order_id'       => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'currency'       => $order->get_currency(),
			'total'          => $order->get_total(),
			'discount_total' => $order->get_discount_total(),
			'customer_id'    => $order->get_customer_id(),
			'billing_email'  => $order->get_billing_email(),
			'coupon_codes'   => $order->get_coupon_codes(),
			'created_gmt'    => $date ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : '',
		);
	}

	/** @return array<string,mixed> */
	private function serialize_customer( ?\WP_User $user ): array {
		if ( ! $user ) {
			return array();
		}
		return array(
			'user_id'       => $user->ID,
			'username'      => $user->user_login,
			'email'         => $user->user_email,
			'first_name'    => $user->first_name,
			'last_name'     => $user->last_name,
			'billing_phone' => get_user_meta( $user->ID, 'billing_phone', true ),
			'order_count'   => function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $user->ID ) : 0,
			'total_spent'   => function_exists( 'wc_get_customer_total_spent' ) ? wc_get_customer_total_spent( $user->ID ) : '0',
		);
	}
}
