<?php
/**
 * Checkout Orchestrator Interface
 *
 * Contract for orchestrating the checkout flow.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\Checkout;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CheckoutOrchestratorInterface
 *
 * Defines contract for checkout flow coordination.
 */
interface CheckoutOrchestratorInterface {

	/**
	 * Start checkout process.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, step: string, data: array, error: string|null}
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
	 * @param string       $phone   Customer phone number.
	 * @param array|string $address Address data or saved address ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processAddress( string $phone, array|string $address ): array;

	/**
	 * Process shipping method selection.
	 *
	 * @param string $phone    Customer phone number.
	 * @param string $methodId Shipping method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processShippingMethod( string $phone, string $methodId ): array;

	/**
	 * Process payment method selection.
	 *
	 * @param string $phone    Customer phone number.
	 * @param string $methodId Payment method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processPaymentMethod( string $phone, string $methodId ): array;

	/**
	 * Confirm and create order.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, order_id: int|null, order_number: string|null, error: string|null}
	 */
	public function confirmOrder( string $phone ): array;

	/**
	 * Cancel checkout.
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
	 * @param string $phone Customer phone number.
	 * @return array{valid: bool, issues: array}
	 */
	public function validateCheckout( string $phone ): array;

	/**
	 * Apply coupon during checkout.
	 *
	 * @param string $phone      Customer phone number.
	 * @param string $couponCode Coupon code.
	 * @return array{success: bool, discount: float, error: string|null}
	 */
	public function applyCoupon( string $phone, string $couponCode ): array;

	/**
	 * Remove coupon during checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function removeCoupon( string $phone ): bool;

	/**
	 * Get available shipping methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of shipping methods.
	 */
	public function getShippingMethods( string $phone ): array;

	/**
	 * Get available payment methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of payment methods.
	 */
	public function getPaymentMethods( string $phone ): array;

	/**
	 * Get order review data.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Order review data.
	 */
	public function getOrderReview( string $phone ): array;

	/**
	 * Calculate final totals.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Totals breakdown.
	 */
	public function calculateTotals( string $phone ): array;
}
