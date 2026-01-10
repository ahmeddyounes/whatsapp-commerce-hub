<?php
/**
 * Checkout Totals Calculator Interface
 *
 * Contract for calculating checkout totals.
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
 * Interface CheckoutTotalsCalculatorInterface
 *
 * Defines contract for calculating order totals.
 */
interface CheckoutTotalsCalculatorInterface {

	/**
	 * Calculate complete order totals.
	 *
	 * @param array $params Calculation parameters.
	 * @return array{subtotal: float, discount: float, shipping: float, tax: float, payment_fee: float, total: float}
	 */
	public function calculateTotals( array $params ): array;

	/**
	 * Calculate cart subtotal.
	 *
	 * @param array $items Cart items.
	 * @return float Subtotal amount.
	 */
	public function calculateSubtotal( array $items ): float;

	/**
	 * Calculate discount amount.
	 *
	 * @param float  $subtotal   Subtotal amount.
	 * @param string $couponCode Coupon code.
	 * @return float Discount amount.
	 */
	public function calculateDiscount( float $subtotal, string $couponCode ): float;

	/**
	 * Calculate tax amount.
	 *
	 * @param float $taxableAmount Taxable amount.
	 * @param array $address       Shipping address for tax location.
	 * @return float Tax amount.
	 */
	public function calculateTax( float $taxableAmount, array $address = [] ): float;

	/**
	 * Get order review data with formatted totals.
	 *
	 * @param string $phone Customer phone number.
	 * @param array  $state Checkout state.
	 * @param array  $items Cart items.
	 * @return array Order review data.
	 */
	public function getOrderReview( string $phone, array $state, array $items ): array;

	/**
	 * Format currency amount for display.
	 *
	 * @param float $amount Amount to format.
	 * @return string Formatted amount.
	 */
	public function formatAmount( float $amount ): string;
}
