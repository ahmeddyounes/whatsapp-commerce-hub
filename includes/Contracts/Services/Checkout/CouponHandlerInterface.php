<?php
/**
 * Coupon Handler Interface
 *
 * Contract for handling checkout coupons.
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
 * Interface CouponHandlerInterface
 *
 * Defines contract for coupon application and validation.
 */
interface CouponHandlerInterface {

	/**
	 * Apply a coupon to checkout.
	 *
	 * @param string $couponCode Coupon code.
	 * @param float  $cartTotal  Cart total for discount calculation.
	 * @return array{success: bool, discount: float, error: string|null}
	 */
	public function applyCoupon( string $couponCode, float $cartTotal ): array;

	/**
	 * Validate coupon code.
	 *
	 * @param string $couponCode Coupon code.
	 * @return array{valid: bool, error: string|null}
	 */
	public function validateCoupon( string $couponCode ): array;

	/**
	 * Calculate discount amount for a coupon.
	 *
	 * @param string $couponCode Coupon code.
	 * @param float  $cartTotal  Cart total.
	 * @return float Discount amount.
	 */
	public function calculateDiscount( string $couponCode, float $cartTotal ): float;

	/**
	 * Get coupon details.
	 *
	 * @param string $couponCode Coupon code.
	 * @return array|null Coupon details or null if not found.
	 */
	public function getCouponDetails( string $couponCode ): ?array;

	/**
	 * Check if coupon can be applied to items.
	 *
	 * @param string $couponCode Coupon code.
	 * @param array  $items      Cart items.
	 * @return bool True if applicable.
	 */
	public function isApplicableToItems( string $couponCode, array $items ): bool;

	/**
	 * Sanitize coupon code.
	 *
	 * @param string $couponCode Raw coupon code.
	 * @return string Sanitized coupon code.
	 */
	public function sanitizeCouponCode( string $couponCode ): string;
}
