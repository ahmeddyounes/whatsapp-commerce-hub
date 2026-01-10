<?php

/**
 * Loyalty Coupon Generator
 *
 * Generates loyalty discount coupons for high-value customers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\LoyaltyCouponGeneratorInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LoyaltyCouponGenerator
 *
 * Creates WooCommerce coupons for loyalty rewards.
 */
class LoyaltyCouponGenerator implements LoyaltyCouponGeneratorInterface {

	/**
	 * Default discount percentage.
	 */
	protected const DEFAULT_DISCOUNT = 15;

	/**
	 * Default minimum lifetime value.
	 */
	protected const DEFAULT_MIN_LTV = 500;

	/**
	 * Coupon validity in days.
	 */
	protected const COUPON_VALIDITY_DAYS = 7;

	/**
	 * Coupon code prefix.
	 */
	protected const COUPON_PREFIX = 'LOYAL';

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Customer service.
	 *
	 * @var \WCH_Customer_Service
	 */
	protected \WCH_Customer_Service $customerService;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface $settings Settings service.
	 */
	public function __construct( SettingsInterface $settings ) {
		$this->settings        = $settings;
		$this->customerService = \WCH_Customer_Service::instance();
	}

	/**
	 * Generate a loyalty discount coupon for a customer.
	 *
	 * @param object $customer Customer profile.
	 * @return string|null Coupon code or null on failure.
	 */
	public function generate( object $customer ): ?string {
		$discountAmount = $this->getDiscountAmount();
		$couponCode     = self::COUPON_PREFIX . strtoupper( substr( md5( $customer->phone . time() ), 0, 8 ) );

		try {
			$coupon = new \WC_Coupon();
			$coupon->set_code( $couponCode );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( $discountAmount );
			$coupon->set_individual_use( true );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_date_expires( strtotime( '+' . self::COUPON_VALIDITY_DAYS . ' days' ) );

			// Restrict to customer's email if available.
			if ( isset( $customer->wc_customer_id ) && $customer->wc_customer_id ) {
				$wcCustomer = new \WC_Customer( $customer->wc_customer_id );
				$email      = $wcCustomer->get_email();
				if ( $email ) {
					$coupon->set_email_restrictions( array( $email ) );
				}
			}

			$coupon->save();

			return $couponCode;
		} catch ( \Exception $e ) {
			\WCH_Logger::error(
				'Failed to create loyalty discount coupon',
				array(
					'phone' => $customer->phone,
					'error' => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Get the configured discount amount.
	 *
	 * @return int Discount percentage.
	 */
	public function getDiscountAmount(): int {
		return (int) $this->settings->get( 'reengagement.loyalty_discount', self::DEFAULT_DISCOUNT );
	}

	/**
	 * Get the minimum lifetime value for loyalty rewards.
	 *
	 * @return float Minimum LTV amount.
	 */
	public function getMinimumLtv(): float {
		return (float) $this->settings->get( 'reengagement.loyalty_min_ltv', self::DEFAULT_MIN_LTV );
	}

	/**
	 * Check if customer qualifies for loyalty discount.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if qualifies.
	 */
	public function qualifiesForLoyaltyDiscount( string $customerPhone ): bool {
		$stats  = $this->customerService->calculate_customer_stats( $customerPhone );
		$minLtv = $this->getMinimumLtv();

		return ! empty( $stats['total_spent'] ) && $stats['total_spent'] >= $minLtv;
	}
}
