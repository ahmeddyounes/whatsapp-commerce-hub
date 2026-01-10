<?php
/**
 * Cart Service Interface
 *
 * Contract for cart management operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Services;

use WhatsAppCommerceHub\Domain\Cart\Cart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CartServiceInterface
 *
 * Defines the contract for cart management operations.
 */
interface CartServiceInterface {

	/**
	 * Get cart for a customer.
	 *
	 * Returns existing cart or creates new empty cart.
	 *
	 * @param string $phone Customer phone number.
	 * @return Cart The cart entity.
	 */
	public function getCart( string $phone ): Cart;

	/**
	 * Add item to cart.
	 *
	 * @param string   $phone        Customer phone number.
	 * @param int      $product_id   Product ID.
	 * @param int|null $variation_id Variation ID (nullable).
	 * @param int      $quantity     Quantity to add.
	 * @return Cart Updated cart.
	 * @throws \InvalidArgumentException If product not found or invalid.
	 * @throws \RuntimeException If stock validation fails.
	 */
	public function addItem( string $phone, int $product_id, ?int $variation_id, int $quantity ): Cart;

	/**
	 * Update item quantity in cart.
	 *
	 * @param string $phone       Customer phone number.
	 * @param int    $item_index  Item index in cart.
	 * @param int    $new_quantity New quantity (0 to remove).
	 * @return Cart Updated cart.
	 * @throws \InvalidArgumentException If item not found.
	 * @throws \RuntimeException If stock validation fails.
	 */
	public function updateQuantity( string $phone, int $item_index, int $new_quantity ): Cart;

	/**
	 * Remove item from cart.
	 *
	 * @param string $phone      Customer phone number.
	 * @param int    $item_index Item index in cart.
	 * @return Cart Updated cart.
	 * @throws \InvalidArgumentException If item not found.
	 */
	public function removeItem( string $phone, int $item_index ): Cart;

	/**
	 * Clear all items from cart.
	 *
	 * @param string $phone Customer phone number.
	 * @return Cart Empty cart.
	 */
	public function clearCart( string $phone ): Cart;

	/**
	 * Apply coupon to cart.
	 *
	 * @param string $phone       Customer phone number.
	 * @param string $coupon_code Coupon code.
	 * @return array{discount: float, cart: Cart} Discount amount and updated cart.
	 * @throws \InvalidArgumentException If coupon is invalid.
	 */
	public function applyCoupon( string $phone, string $coupon_code ): array;

	/**
	 * Remove coupon from cart.
	 *
	 * @param string $phone Customer phone number.
	 * @return Cart Updated cart.
	 */
	public function removeCoupon( string $phone ): Cart;

	/**
	 * Calculate cart totals.
	 *
	 * @param Cart $cart Cart entity.
	 * @return array{subtotal: float, discount: float, tax: float, shipping_estimate: float, total: float}
	 */
	public function calculateTotals( Cart $cart ): array;

	/**
	 * Get cart summary as formatted message.
	 *
	 * @param string $phone Customer phone number.
	 * @return string Formatted cart summary.
	 */
	public function getCartSummaryMessage( string $phone ): string;

	/**
	 * Check cart validity.
	 *
	 * Verifies all items still exist, are in stock, and prices haven't changed.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{is_valid: bool, issues: array, cart: Cart}
	 */
	public function checkCartValidity( string $phone ): array;

	/**
	 * Get abandoned carts.
	 *
	 * @param int $hours Hours of inactivity to consider abandoned.
	 * @return array<Cart> Abandoned carts.
	 */
	public function getAbandonedCarts( int $hours = 24 ): array;

	/**
	 * Mark cart reminder as sent.
	 *
	 * @param int $cart_id        Cart ID.
	 * @param int $reminder_number Reminder number (1, 2, or 3).
	 * @return bool Success status.
	 */
	public function markReminderSent( int $cart_id, int $reminder_number = 1 ): bool;

	/**
	 * Clean up expired carts.
	 *
	 * @return int Number of carts cleaned.
	 */
	public function cleanupExpiredCarts(): int;

	/**
	 * Set shipping address for cart.
	 *
	 * @param string $phone   Customer phone number.
	 * @param array  $address Shipping address data.
	 * @return Cart Updated cart.
	 */
	public function setShippingAddress( string $phone, array $address ): Cart;

	/**
	 * Mark cart as completed (converted to order).
	 *
	 * @param string $phone    Customer phone number.
	 * @param int    $order_id WooCommerce order ID.
	 * @return bool Success status.
	 */
	public function markCompleted( string $phone, int $order_id ): bool;
}
