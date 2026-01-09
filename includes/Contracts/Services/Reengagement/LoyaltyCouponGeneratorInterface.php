<?php
/**
 * Loyalty Coupon Generator Interface
 *
 * Contract for generating loyalty discount coupons.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\Reengagement;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface LoyaltyCouponGeneratorInterface
 *
 * Defines methods for loyalty coupon generation.
 */
interface LoyaltyCouponGeneratorInterface {

	/**
	 * Generate a loyalty discount coupon for a customer.
	 *
	 * @param object $customer Customer profile.
	 * @return string|null Coupon code or null on failure.
	 */
	public function generate( object $customer ): ?string;

	/**
	 * Get the configured discount amount.
	 *
	 * @return int Discount percentage.
	 */
	public function getDiscountAmount(): int;

	/**
	 * Get the minimum lifetime value for loyalty rewards.
	 *
	 * @return float Minimum LTV amount.
	 */
	public function getMinimumLtv(): float;

	/**
	 * Check if customer qualifies for loyalty discount.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if qualifies.
	 */
	public function qualifiesForLoyaltyDiscount( string $customerPhone ): bool;
}
