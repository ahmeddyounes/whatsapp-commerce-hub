<?php
/**
 * Checkout Service Interface
 *
 * Contract for checkout flow management.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

use WhatsAppCommerceHub\Domain\Cart\Cart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CheckoutServiceInterface
 *
 * Defines the contract for checkout flow operations.
 */
interface CheckoutServiceInterface {

	/**
	 * Checkout steps.
	 */
	public const STEP_ADDRESS         = 'ADDRESS';
	public const STEP_SHIPPING_METHOD = 'SHIPPING_METHOD';
	public const STEP_PAYMENT_METHOD  = 'PAYMENT_METHOD';
	public const STEP_REVIEW          = 'REVIEW';
	public const STEP_CONFIRM         = 'CONFIRM';

	/**
	 * Start checkout process.
	 *
	 * Validates cart and transitions to address collection.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 * @throws \RuntimeException If cart is empty or invalid.
	 */
	public function startCheckout( string $phone ): array;

	/**
	 * Get current checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{step: string|null, data: array}
	 */
	public function getCheckoutState( string $phone ): array;

	/**
	 * Process address input.
	 *
	 * Handles saved address selection or new address entry.
	 *
	 * @param string       $phone   Customer phone number.
	 * @param array|string $address Address data or saved address ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 * @throws \InvalidArgumentException If address is invalid.
	 */
	public function processAddress( string $phone, array|string $address ): array;

	/**
	 * Get available shipping methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of shipping methods with id, label, cost.
	 */
	public function getShippingMethods( string $phone ): array;

	/**
	 * Process shipping method selection.
	 *
	 * @param string $phone     Customer phone number.
	 * @param string $method_id Shipping method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 * @throws \InvalidArgumentException If method is invalid.
	 */
	public function processShippingMethod( string $phone, string $method_id ): array;

	/**
	 * Get available payment methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of payment methods with id, label, description, fee.
	 */
	public function getPaymentMethods( string $phone ): array;

	/**
	 * Process payment method selection.
	 *
	 * @param string $phone     Customer phone number.
	 * @param string $method_id Payment method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 * @throws \InvalidArgumentException If method is invalid.
	 */
	public function processPaymentMethod( string $phone, string $method_id ): array;

	/**
	 * Get order review data.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{items: array, address: array, shipping: array, payment: string, totals: array}
	 */
	public function getOrderReview( string $phone ): array;

	/**
	 * Calculate final totals.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{subtotal: float, discount: float, shipping: float, tax: float, payment_fee: float, total: float}
	 */
	public function calculateTotals( string $phone ): array;

	/**
	 * Confirm and create order.
	 *
	 * Performs final validation, creates order, clears cart.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, order_id: int|null, order_number: string|null, error: string|null}
	 * @throws \RuntimeException If order creation fails.
	 */
	public function confirmOrder( string $phone ): array;

	/**
	 * Cancel checkout.
	 *
	 * Clears checkout state but preserves cart.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function cancelCheckout( string $phone ): bool;

	/**
	 * Go back to previous step.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, step: string, data: array}
	 */
	public function goBack( string $phone ): array;

	/**
	 * Validate checkout can proceed.
	 *
	 * Checks cart validity, stock, address completeness.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{valid: bool, issues: array}
	 */
	public function validateCheckout( string $phone ): array;

	/**
	 * Apply coupon during checkout.
	 *
	 * @param string $phone       Customer phone number.
	 * @param string $coupon_code Coupon code.
	 * @return array{success: bool, discount: float, error: string|null}
	 */
	public function applyCoupon( string $phone, string $coupon_code ): array;

	/**
	 * Remove coupon during checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function removeCoupon( string $phone ): bool;

	/**
	 * Get checkout timeout in seconds.
	 *
	 * @return int Timeout in seconds.
	 */
	public function getCheckoutTimeout(): int;

	/**
	 * Check if checkout has timed out.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if checkout has timed out.
	 */
	public function hasTimedOut( string $phone ): bool;

	/**
	 * Extend checkout timeout.
	 *
	 * @param string $phone   Customer phone number.
	 * @param int    $seconds Additional seconds.
	 * @return bool Success status.
	 */
	public function extendTimeout( string $phone, int $seconds = 900 ): bool;
}
