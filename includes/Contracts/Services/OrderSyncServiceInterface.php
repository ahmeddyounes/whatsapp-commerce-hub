<?php
/**
 * Order Sync Service Interface
 *
 * Contract for order synchronization between WhatsApp and WooCommerce.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface OrderSyncServiceInterface
 *
 * Defines the contract for order synchronization operations.
 */
interface OrderSyncServiceInterface {

	/**
	 * Create WooCommerce order from WhatsApp cart data.
	 *
	 * @param array  $cart_data      Cart data including items, shipping, payment method.
	 * @param string $customer_phone Customer phone number.
	 * @return int Order ID on success.
	 * @throws \InvalidArgumentException If cart data is invalid.
	 * @throws \RuntimeException If order creation fails.
	 */
	public function createOrderFromCart( array $cart_data, string $customer_phone ): int;

	/**
	 * Validate cart items for order creation.
	 *
	 * Checks stock, purchasability, and price validity.
	 *
	 * @param array $items Cart items.
	 * @return array{valid: bool, issues: array}
	 */
	public function validateCartItems( array $items ): array;

	/**
	 * Sync order status changes to WhatsApp.
	 *
	 * Sends appropriate notification based on status transition.
	 *
	 * @param int    $order_id   WooCommerce order ID.
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 * @return bool Success status.
	 */
	public function syncStatusToWhatsApp( int $order_id, string $old_status, string $new_status ): bool;

	/**
	 * Add tracking information to order.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $tracking_number Tracking number.
	 * @param string $carrier         Carrier name.
	 * @return bool Success status.
	 */
	public function addTrackingInfo( int $order_id, string $tracking_number, string $carrier ): bool;

	/**
	 * Get tracking information for order.
	 *
	 * @param int $order_id Order ID.
	 * @return array{tracking_number: string|null, carrier: string|null}
	 */
	public function getTrackingInfo( int $order_id ): array;

	/**
	 * Check if order originated from WhatsApp.
	 *
	 * @param int $order_id Order ID.
	 * @return bool True if WhatsApp order.
	 */
	public function isWhatsAppOrder( int $order_id ): bool;

	/**
	 * Get WhatsApp order metadata.
	 *
	 * @param int $order_id Order ID.
	 * @return array{customer_phone: string|null, conversation_id: int|null}
	 */
	public function getWhatsAppOrderMeta( int $order_id ): array;

	/**
	 * Get notification template for status change.
	 *
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 * @return string|null Template name or null if no notification.
	 */
	public function getNotificationTemplate( string $old_status, string $new_status ): ?string;

	/**
	 * Queue order notification for sending.
	 *
	 * @param int    $order_id       Order ID.
	 * @param string $template_name  Template name.
	 * @param array  $template_data  Template variable data.
	 * @return bool Success status.
	 */
	public function queueNotification( int $order_id, string $template_name, array $template_data = array() ): bool;

	/**
	 * Get orders by customer phone.
	 *
	 * @param string $phone Customer phone number.
	 * @param int    $limit Maximum orders to return.
	 * @return array Order data array.
	 */
	public function getOrdersByPhone( string $phone, int $limit = 10 ): array;

	/**
	 * Cancel order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $reason   Cancellation reason.
	 * @return bool Success status.
	 */
	public function cancelOrder( int $order_id, string $reason = '' ): bool;

	/**
	 * Get WhatsApp orders list.
	 *
	 * @param array $args Query arguments (status, date_from, date_to, limit, offset).
	 * @return array{orders: array, total: int}
	 */
	public function getWhatsAppOrders( array $args = array() ): array;

	/**
	 * Get order statistics.
	 *
	 * @param \DateTimeInterface $start_date Start date.
	 * @param \DateTimeInterface $end_date   End date.
	 * @return array{total: int, revenue: float, by_status: array}
	 */
	public function getOrderStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array;
}
