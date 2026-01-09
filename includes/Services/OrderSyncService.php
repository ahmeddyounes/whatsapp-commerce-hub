<?php
/**
 * Order Sync Service
 *
 * Handles order synchronization between WhatsApp and WooCommerce.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Services;

use WhatsAppCommerceHub\Contracts\Services\OrderSyncServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\QueueServiceInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderSyncService
 *
 * Implements order synchronization operations.
 */
class OrderSyncService implements OrderSyncServiceInterface {

	/**
	 * Meta key for WhatsApp orders.
	 */
	private const META_WHATSAPP_ORDER = '_wch_whatsapp_order';

	/**
	 * Meta key for customer phone.
	 */
	private const META_CUSTOMER_PHONE = '_wch_customer_phone';

	/**
	 * Meta key for conversation ID.
	 */
	private const META_CONVERSATION_ID = '_wch_conversation_id';

	/**
	 * Meta key for tracking number.
	 */
	private const META_TRACKING_NUMBER = '_wch_tracking_number';

	/**
	 * Meta key for carrier.
	 */
	private const META_CARRIER = '_wch_carrier';

	/**
	 * Status transition templates.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const STATUS_TEMPLATES = array(
		'pending'    => array(
			'processing' => 'order_confirmed',
			'on-hold'    => 'order_on_hold',
			'cancelled'  => 'order_cancelled',
		),
		'processing' => array(
			'completed' => 'order_shipped',
			'cancelled' => 'order_cancelled',
			'refunded'  => 'order_refunded',
		),
		'on-hold'    => array(
			'processing' => 'order_confirmed',
			'cancelled'  => 'order_cancelled',
		),
	);

	/**
	 * Queue service.
	 *
	 * @var QueueServiceInterface|null
	 */
	private ?QueueServiceInterface $queue_service;

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepositoryInterface|null
	 */
	private ?CustomerRepositoryInterface $customer_repository;

	/**
	 * Constructor.
	 *
	 * @param QueueServiceInterface|null      $queue_service       Queue service for notifications.
	 * @param CustomerRepositoryInterface|null $customer_repository Customer repository.
	 */
	public function __construct(
		?QueueServiceInterface $queue_service = null,
		?CustomerRepositoryInterface $customer_repository = null
	) {
		$this->queue_service       = $queue_service;
		$this->customer_repository = $customer_repository;
	}

	/**
	 * Create WooCommerce order from WhatsApp cart data.
	 *
	 * @param array  $cart_data      Cart data including items, shipping, payment method.
	 * @param string $customer_phone Customer phone number.
	 * @return int Order ID on success.
	 * @throws \InvalidArgumentException If cart data is invalid.
	 * @throws \RuntimeException If order creation fails.
	 */
	public function createOrderFromCart( array $cart_data, string $customer_phone ): int {
		// Validate required data.
		if ( empty( $cart_data['items'] ) ) {
			throw new \InvalidArgumentException( 'Cart items are required' );
		}

		$customer_phone = $this->sanitizePhone( $customer_phone );
		if ( empty( $customer_phone ) ) {
			throw new \InvalidArgumentException( 'Valid customer phone is required' );
		}

		// Validate cart items.
		$validation = $this->validateCartItems( $cart_data['items'] );
		if ( ! $validation['valid'] ) {
			throw new \InvalidArgumentException(
				'Cart validation failed: ' . implode( ', ', array_column( $validation['issues'], 'message' ) )
			);
		}

		// Check WooCommerce availability.
		if ( ! function_exists( 'wc_create_order' ) ) {
			throw new \RuntimeException( 'WooCommerce is not available' );
		}

		try {
			// Create order.
			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				throw new \RuntimeException( 'Failed to create order: ' . $order->get_error_message() );
			}

			// Add items.
			foreach ( $cart_data['items'] as $item ) {
				$product_id   = (int) ( $item['product_id'] ?? 0 );
				$variation_id = (int) ( $item['variation_id'] ?? 0 );
				$quantity     = max( 1, (int) ( $item['quantity'] ?? 1 ) );

				$product = $variation_id > 0 ? wc_get_product( $variation_id ) : wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$order->add_product( $product, $quantity );
			}

			// Set billing/shipping address.
			$this->setOrderAddresses( $order, $cart_data, $customer_phone );

			// Set payment method.
			$payment_method = $cart_data['payment_method'] ?? 'cod';
			$order->set_payment_method( $payment_method );
			$order->set_payment_method_title( $this->getPaymentMethodTitle( $payment_method ) );

			// Set shipping.
			if ( ! empty( $cart_data['shipping_method'] ) ) {
				$this->addShippingToOrder( $order, $cart_data );
			}

			// Calculate totals.
			$order->calculate_totals();

			// Add WhatsApp metadata.
			$order->update_meta_data( self::META_WHATSAPP_ORDER, '1' );
			$order->update_meta_data( self::META_CUSTOMER_PHONE, $customer_phone );

			if ( ! empty( $cart_data['conversation_id'] ) ) {
				$order->update_meta_data( self::META_CONVERSATION_ID, (int) $cart_data['conversation_id'] );
			}

			// Add order note.
			$order->add_order_note( __( 'Order created via WhatsApp Commerce Hub.', 'whatsapp-commerce-hub' ) );

			// Set order status.
			$initial_status = $cart_data['initial_status'] ?? 'pending';
			$order->set_status( $initial_status );

			// Save order.
			$order->save();

			// Link customer if repository available.
			$this->linkCustomerToOrder( $order->get_id(), $customer_phone );

			// Fire event.
			do_action( 'wch_order_created', $order->get_id(), $customer_phone, $cart_data );

			return $order->get_id();

		} catch ( \Exception $e ) {
			do_action( 'wch_log_error', 'OrderSyncService: Order creation failed', array(
				'error'          => $e->getMessage(),
				'customer_phone' => $customer_phone,
			) );

			throw new \RuntimeException( 'Order creation failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Validate cart items for order creation.
	 *
	 * @param array $items Cart items.
	 * @return array{valid: bool, issues: array}
	 */
	public function validateCartItems( array $items ): array {
		$issues = array();

		if ( empty( $items ) ) {
			return array(
				'valid'  => false,
				'issues' => array( array( 'type' => 'empty_cart', 'message' => 'Cart is empty' ) ),
			);
		}

		foreach ( $items as $index => $item ) {
			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$quantity     = max( 1, (int) ( $item['quantity'] ?? 1 ) );

			// Get product.
			$product = $variation_id > 0 ? wc_get_product( $variation_id ) : wc_get_product( $product_id );

			if ( ! $product ) {
				$issues[] = array(
					'type'       => 'product_not_found',
					'product_id' => $product_id,
					'message'    => sprintf( 'Product #%d not found', $product_id ),
				);
				continue;
			}

			// Check purchasability.
			if ( ! $product->is_purchasable() ) {
				$issues[] = array(
					'type'       => 'not_purchasable',
					'product_id' => $product_id,
					'message'    => sprintf( 'Product "%s" is not purchasable', $product->get_name() ),
				);
				continue;
			}

			// Check stock.
			if ( ! $product->is_in_stock() ) {
				$issues[] = array(
					'type'       => 'out_of_stock',
					'product_id' => $product_id,
					'message'    => sprintf( 'Product "%s" is out of stock', $product->get_name() ),
				);
				continue;
			}

			// Check stock quantity.
			if ( $product->managing_stock() ) {
				$stock_qty = $product->get_stock_quantity();
				if ( $stock_qty !== null && $stock_qty < $quantity ) {
					$issues[] = array(
						'type'       => 'insufficient_stock',
						'product_id' => $product_id,
						'requested'  => $quantity,
						'available'  => $stock_qty,
						'message'    => sprintf(
							'Insufficient stock for "%s" (requested: %d, available: %d)',
							$product->get_name(),
							$quantity,
							$stock_qty
						),
					);
				}
			}

			// Check min/max purchase quantity.
			$min_qty = apply_filters( 'woocommerce_quantity_input_min', 1, $product );
			$max_qty = apply_filters( 'woocommerce_quantity_input_max', -1, $product );

			if ( $quantity < $min_qty ) {
				$issues[] = array(
					'type'       => 'below_min_quantity',
					'product_id' => $product_id,
					'message'    => sprintf( 'Quantity for "%s" below minimum (%d)', $product->get_name(), $min_qty ),
				);
			}

			if ( $max_qty > 0 && $quantity > $max_qty ) {
				$issues[] = array(
					'type'       => 'above_max_quantity',
					'product_id' => $product_id,
					'message'    => sprintf( 'Quantity for "%s" above maximum (%d)', $product->get_name(), $max_qty ),
				);
			}
		}

		return array(
			'valid'  => empty( $issues ),
			'issues' => $issues,
		);
	}

	/**
	 * Sync order status changes to WhatsApp.
	 *
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 * @return bool Success status.
	 */
	public function syncStatusToWhatsApp( int $order_id, string $old_status, string $new_status ): bool {
		// Check if WhatsApp order.
		if ( ! $this->isWhatsAppOrder( $order_id ) ) {
			return false;
		}

		// Get notification template.
		$template = $this->getNotificationTemplate( $old_status, $new_status );
		if ( null === $template ) {
			return false;
		}

		// Get order data for template.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$template_data = $this->buildTemplateData( $order );

		// Queue notification.
		return $this->queueNotification( $order_id, $template, $template_data );
	}

	/**
	 * Add tracking information to order.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $tracking_number Tracking number.
	 * @param string $carrier         Carrier name.
	 * @return bool Success status.
	 */
	public function addTrackingInfo( int $order_id, string $tracking_number, string $carrier ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$tracking_number = sanitize_text_field( $tracking_number );
		$carrier         = sanitize_text_field( $carrier );

		if ( empty( $tracking_number ) ) {
			return false;
		}

		$order->update_meta_data( self::META_TRACKING_NUMBER, $tracking_number );
		$order->update_meta_data( self::META_CARRIER, $carrier );
		$order->save();

		// Add order note.
		$order->add_order_note( sprintf(
			/* translators: 1: Carrier name, 2: Tracking number */
			__( 'Tracking added - %1$s: %2$s', 'whatsapp-commerce-hub' ),
			$carrier ?: __( 'Unknown carrier', 'whatsapp-commerce-hub' ),
			$tracking_number
		) );

		// Send tracking notification if WhatsApp order.
		if ( $this->isWhatsAppOrder( $order_id ) ) {
			$template_data = $this->buildTemplateData( $order );
			$template_data['tracking_number'] = $tracking_number;
			$template_data['carrier']         = $carrier;

			$this->queueNotification( $order_id, 'order_tracking', $template_data );
		}

		do_action( 'wch_order_tracking_added', $order_id, $tracking_number, $carrier );

		return true;
	}

	/**
	 * Get tracking information for order.
	 *
	 * @param int $order_id Order ID.
	 * @return array{tracking_number: string|null, carrier: string|null}
	 */
	public function getTrackingInfo( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'tracking_number' => null,
				'carrier'         => null,
			);
		}

		return array(
			'tracking_number' => $order->get_meta( self::META_TRACKING_NUMBER ) ?: null,
			'carrier'         => $order->get_meta( self::META_CARRIER ) ?: null,
		);
	}

	/**
	 * Check if order originated from WhatsApp.
	 *
	 * @param int $order_id Order ID.
	 * @return bool True if WhatsApp order.
	 */
	public function isWhatsAppOrder( int $order_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		return '1' === $order->get_meta( self::META_WHATSAPP_ORDER );
	}

	/**
	 * Get WhatsApp order metadata.
	 *
	 * @param int $order_id Order ID.
	 * @return array{customer_phone: string|null, conversation_id: int|null}
	 */
	public function getWhatsAppOrderMeta( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'customer_phone'  => null,
				'conversation_id' => null,
			);
		}

		$conversation_id = $order->get_meta( self::META_CONVERSATION_ID );

		return array(
			'customer_phone'  => $order->get_meta( self::META_CUSTOMER_PHONE ) ?: null,
			'conversation_id' => $conversation_id ? (int) $conversation_id : null,
		);
	}

	/**
	 * Get notification template for status change.
	 *
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 * @return string|null Template name or null if no notification.
	 */
	public function getNotificationTemplate( string $old_status, string $new_status ): ?string {
		// Remove 'wc-' prefix if present.
		$old_status = str_replace( 'wc-', '', $old_status );
		$new_status = str_replace( 'wc-', '', $new_status );

		return self::STATUS_TEMPLATES[ $old_status ][ $new_status ] ?? null;
	}

	/**
	 * Queue order notification for sending.
	 *
	 * @param int    $order_id      Order ID.
	 * @param string $template_name Template name.
	 * @param array  $template_data Template variable data.
	 * @return bool Success status.
	 */
	public function queueNotification( int $order_id, string $template_name, array $template_data = array() ): bool {
		$meta = $this->getWhatsAppOrderMeta( $order_id );
		if ( empty( $meta['customer_phone'] ) ) {
			return false;
		}

		$notification_data = array(
			'order_id'       => $order_id,
			'customer_phone' => $meta['customer_phone'],
			'template_name'  => $template_name,
			'template_data'  => $template_data,
		);

		// Use queue service if available.
		if ( $this->queue_service ) {
			return $this->queue_service->dispatch(
				'wch_send_order_notification',
				$notification_data,
				'wch-urgent'
			);
		}

		// Fallback to Action Scheduler.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action(
				'wch_send_order_notification',
				array( $notification_data ),
				'wch-orders'
			);
			return $action_id > 0;
		}

		// Fallback to immediate execution via hook.
		do_action( 'wch_send_order_notification', $notification_data );
		return true;
	}

	/**
	 * Get orders by customer phone.
	 *
	 * @param string $phone Customer phone number.
	 * @param int    $limit Maximum orders to return.
	 * @return array Order data array.
	 */
	public function getOrdersByPhone( string $phone, int $limit = 10 ): array {
		$phone = $this->sanitizePhone( $phone );
		if ( empty( $phone ) ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );

		$orders = wc_get_orders( array(
			'limit'      => $limit,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_key'   => self::META_CUSTOMER_PHONE,
			'meta_value' => $phone,
		) );

		$result = array();
		foreach ( $orders as $order ) {
			$result[] = $this->formatOrderData( $order );
		}

		return $result;
	}

	/**
	 * Cancel order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $reason   Cancellation reason.
	 * @return bool Success status.
	 */
	public function cancelOrder( int $order_id, string $reason = '' ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Check if order can be cancelled.
		$current_status = $order->get_status();
		$cancellable    = array( 'pending', 'processing', 'on-hold' );

		if ( ! in_array( $current_status, $cancellable, true ) ) {
			do_action( 'wch_log_warning', 'OrderSyncService: Cannot cancel order', array(
				'order_id'       => $order_id,
				'current_status' => $current_status,
			) );
			return false;
		}

		try {
			$order->set_status( 'cancelled', sanitize_text_field( $reason ) );
			$order->save();

			do_action( 'wch_order_cancelled', $order_id, $reason );

			return true;
		} catch ( \Exception $e ) {
			do_action( 'wch_log_error', 'OrderSyncService: Order cancellation failed', array(
				'order_id' => $order_id,
				'error'    => $e->getMessage(),
			) );
			return false;
		}
	}

	/**
	 * Get WhatsApp orders list.
	 *
	 * @param array $args Query arguments.
	 * @return array{orders: array, total: int}
	 */
	public function getWhatsAppOrders( array $args = array() ): array {
		$defaults = array(
			'status'    => array( 'any' ),
			'date_from' => null,
			'date_to'   => null,
			'limit'     => 20,
			'offset'    => 0,
		);

		$args  = wp_parse_args( $args, $defaults );
		$limit = max( 1, min( 100, (int) $args['limit'] ) );

		$query_args = array(
			'limit'      => $limit,
			'offset'     => max( 0, (int) $args['offset'] ),
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_key'   => self::META_WHATSAPP_ORDER,
			'meta_value' => '1',
			'paginate'   => true,
		);

		// Filter by status.
		if ( ! empty( $args['status'] ) && 'any' !== $args['status'] ) {
			$query_args['status'] = (array) $args['status'];
		}

		// Filter by date range.
		if ( ! empty( $args['date_from'] ) ) {
			$query_args['date_created'] = '>=' . $this->formatDate( $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			if ( isset( $query_args['date_created'] ) ) {
				$query_args['date_created'] .= '...' . $this->formatDate( $args['date_to'] );
			} else {
				$query_args['date_created'] = '<=' . $this->formatDate( $args['date_to'] );
			}
		}

		$result = wc_get_orders( $query_args );

		$orders = array();
		foreach ( $result->orders as $order ) {
			$orders[] = $this->formatOrderData( $order );
		}

		return array(
			'orders' => $orders,
			'total'  => (int) $result->total,
		);
	}

	/**
	 * Get order statistics.
	 *
	 * @param \DateTimeInterface $start_date Start date.
	 * @param \DateTimeInterface $end_date   End date.
	 * @return array{total: int, revenue: float, by_status: array}
	 */
	public function getOrderStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array {
		global $wpdb;

		$start_str = $start_date->format( 'Y-m-d H:i:s' );
		$end_str   = $end_date->format( 'Y-m-d H:i:s' );

		// Get order IDs that are WhatsApp orders.
		$orders_table    = $wpdb->prefix . 'wc_orders';
		$ordermeta_table = $wpdb->prefix . 'wc_orders_meta';

		// Check if HPOS is enabled.
		$hpos_enabled = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos_enabled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT o.status, COUNT(*) as count, SUM(o.total_amount) as revenue
				FROM {$orders_table} o
				INNER JOIN {$ordermeta_table} om ON o.id = om.order_id
				WHERE om.meta_key = %s AND om.meta_value = %s
				AND o.date_created_gmt BETWEEN %s AND %s
				GROUP BY o.status",
				self::META_WHATSAPP_ORDER,
				'1',
				$start_str,
				$end_str
			), ARRAY_A );
		} else {
			// Legacy post meta approach.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.post_status as status, COUNT(*) as count,
				SUM(CAST(pm_total.meta_value AS DECIMAL(10,2))) as revenue
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order'
				AND pm.meta_key = %s AND pm.meta_value = %s
				AND p.post_date BETWEEN %s AND %s
				GROUP BY p.post_status",
				self::META_WHATSAPP_ORDER,
				'1',
				$start_str,
				$end_str
			), ARRAY_A );
		}

		$total     = 0;
		$revenue   = 0.0;
		$by_status = array();

		foreach ( $results as $row ) {
			$status = str_replace( 'wc-', '', $row['status'] );
			$count  = (int) $row['count'];
			$rev    = (float) $row['revenue'];

			$total     += $count;
			$revenue   += $rev;
			$by_status[ $status ] = array(
				'count'   => $count,
				'revenue' => $rev,
			);
		}

		return array(
			'total'     => $total,
			'revenue'   => $revenue,
			'by_status' => $by_status,
		);
	}

	/**
	 * Set order billing and shipping addresses.
	 *
	 * @param \WC_Order $order          Order object.
	 * @param array     $cart_data      Cart data.
	 * @param string    $customer_phone Customer phone.
	 */
	private function setOrderAddresses( \WC_Order $order, array $cart_data, string $customer_phone ): void {
		// Billing address.
		$billing = $cart_data['billing_address'] ?? array();
		$order->set_billing_first_name( sanitize_text_field( $billing['first_name'] ?? '' ) );
		$order->set_billing_last_name( sanitize_text_field( $billing['last_name'] ?? '' ) );
		$order->set_billing_company( sanitize_text_field( $billing['company'] ?? '' ) );
		$order->set_billing_address_1( sanitize_text_field( $billing['address_1'] ?? '' ) );
		$order->set_billing_address_2( sanitize_text_field( $billing['address_2'] ?? '' ) );
		$order->set_billing_city( sanitize_text_field( $billing['city'] ?? '' ) );
		$order->set_billing_state( sanitize_text_field( $billing['state'] ?? '' ) );
		$order->set_billing_postcode( sanitize_text_field( $billing['postcode'] ?? '' ) );
		$order->set_billing_country( sanitize_text_field( $billing['country'] ?? '' ) );
		$order->set_billing_phone( $customer_phone );
		$order->set_billing_email( sanitize_email( $billing['email'] ?? '' ) );

		// Shipping address - use billing if not provided.
		$shipping = $cart_data['shipping_address'] ?? $billing;
		$order->set_shipping_first_name( sanitize_text_field( $shipping['first_name'] ?? '' ) );
		$order->set_shipping_last_name( sanitize_text_field( $shipping['last_name'] ?? '' ) );
		$order->set_shipping_company( sanitize_text_field( $shipping['company'] ?? '' ) );
		$order->set_shipping_address_1( sanitize_text_field( $shipping['address_1'] ?? '' ) );
		$order->set_shipping_address_2( sanitize_text_field( $shipping['address_2'] ?? '' ) );
		$order->set_shipping_city( sanitize_text_field( $shipping['city'] ?? '' ) );
		$order->set_shipping_state( sanitize_text_field( $shipping['state'] ?? '' ) );
		$order->set_shipping_postcode( sanitize_text_field( $shipping['postcode'] ?? '' ) );
		$order->set_shipping_country( sanitize_text_field( $shipping['country'] ?? '' ) );
	}

	/**
	 * Add shipping to order.
	 *
	 * @param \WC_Order $order     Order object.
	 * @param array     $cart_data Cart data.
	 */
	private function addShippingToOrder( \WC_Order $order, array $cart_data ): void {
		$shipping_method = $cart_data['shipping_method'];
		$shipping_cost   = (float) ( $cart_data['shipping_cost'] ?? 0 );
		$shipping_title  = $cart_data['shipping_title'] ?? $shipping_method;

		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_id( sanitize_text_field( $shipping_method ) );
		$shipping_item->set_method_title( sanitize_text_field( $shipping_title ) );
		$shipping_item->set_total( $shipping_cost );

		$order->add_item( $shipping_item );
	}

	/**
	 * Get payment method title.
	 *
	 * @param string $method_id Payment method ID.
	 * @return string Payment method title.
	 */
	private function getPaymentMethodTitle( string $method_id ): string {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( isset( $gateways[ $method_id ] ) ) {
			return $gateways[ $method_id ]->get_title();
		}

		// Fallback titles.
		$titles = array(
			'cod'    => __( 'Cash on Delivery', 'whatsapp-commerce-hub' ),
			'bacs'   => __( 'Direct Bank Transfer', 'whatsapp-commerce-hub' ),
			'cheque' => __( 'Check Payment', 'whatsapp-commerce-hub' ),
		);

		return $titles[ $method_id ] ?? $method_id;
	}

	/**
	 * Build template data from order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return array Template data.
	 */
	private function buildTemplateData( \WC_Order $order ): array {
		return array(
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'order_date'     => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : '',
			'order_total'    => $order->get_formatted_order_total(),
			'order_status'   => wc_get_order_status_name( $order->get_status() ),
			'customer_name'  => $order->get_formatted_billing_full_name(),
			'customer_email' => $order->get_billing_email(),
			'customer_phone' => $order->get_billing_phone(),
			'item_count'     => $order->get_item_count(),
			'payment_method' => $order->get_payment_method_title(),
			'shipping_method'=> $order->get_shipping_method(),
		);
	}

	/**
	 * Format order data for API response.
	 *
	 * @param \WC_Order $order Order object.
	 * @return array Formatted order data.
	 */
	private function formatOrderData( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => (float) $item->get_total(),
			);
		}

		$tracking = $this->getTrackingInfo( $order->get_id() );

		return array(
			'id'              => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'status'          => $order->get_status(),
			'total'           => (float) $order->get_total(),
			'currency'        => $order->get_currency(),
			'date_created'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'date_modified'   => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
			'items'           => $items,
			'tracking_number' => $tracking['tracking_number'],
			'carrier'         => $tracking['carrier'],
		);
	}

	/**
	 * Link customer record to order.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $customer_phone Customer phone.
	 */
	private function linkCustomerToOrder( int $order_id, string $customer_phone ): void {
		if ( ! $this->customer_repository ) {
			return;
		}

		try {
			$customer = $this->customer_repository->findByPhone( $customer_phone );
			if ( $customer ) {
				// Update customer stats handled by repository.
				$this->customer_repository->incrementOrderStats(
					$customer->id,
					1,
					(float) wc_get_order( $order_id )->get_total()
				);
			}
		} catch ( \Exception $e ) {
			// Non-critical, log and continue.
			do_action( 'wch_log_warning', 'OrderSyncService: Customer link failed', array(
				'order_id'       => $order_id,
				'customer_phone' => $customer_phone,
				'error'          => $e->getMessage(),
			) );
		}
	}

	/**
	 * Sanitize phone number.
	 *
	 * @param string $phone Phone number.
	 * @return string Sanitized phone.
	 */
	private function sanitizePhone( string $phone ): string {
		return preg_replace( '/[^0-9+]/', '', $phone );
	}

	/**
	 * Format date for WooCommerce query.
	 *
	 * @param mixed $date Date input.
	 * @return string Formatted date.
	 */
	private function formatDate( $date ): string {
		if ( $date instanceof \DateTimeInterface ) {
			return $date->format( 'Y-m-d H:i:s' );
		}

		if ( is_string( $date ) ) {
			return date( 'Y-m-d H:i:s', strtotime( $date ) ) ?: '';
		}

		return '';
	}
}
