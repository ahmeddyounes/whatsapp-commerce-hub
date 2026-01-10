<?php
/**
 * Campaign Type Resolver
 *
 * Determines the best re-engagement campaign type for a customer.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\CampaignTypeResolverInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ProductTrackingServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\LoyaltyCouponGeneratorInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementMessageBuilderInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignTypeResolver
 *
 * Resolves the optimal campaign type for a customer.
 */
class CampaignTypeResolver implements CampaignTypeResolverInterface {

	/**
	 * Product tracking service.
	 *
	 * @var ProductTrackingServiceInterface
	 */
	protected ProductTrackingServiceInterface $productTracking;

	/**
	 * Loyalty coupon generator.
	 *
	 * @var LoyaltyCouponGeneratorInterface
	 */
	protected LoyaltyCouponGeneratorInterface $loyaltyGenerator;

	/**
	 * Message builder.
	 *
	 * @var ReengagementMessageBuilderInterface
	 */
	protected ReengagementMessageBuilderInterface $messageBuilder;

	/**
	 * Customer service.
	 *
	 * @var \WCH_Customer_Service
	 */
	protected \WCH_Customer_Service $customerService;

	/**
	 * Constructor.
	 *
	 * @param ProductTrackingServiceInterface     $productTracking  Product tracking.
	 * @param LoyaltyCouponGeneratorInterface     $loyaltyGenerator Loyalty generator.
	 * @param ReengagementMessageBuilderInterface $messageBuilder   Message builder.
	 */
	public function __construct(
		ProductTrackingServiceInterface $productTracking,
		LoyaltyCouponGeneratorInterface $loyaltyGenerator,
		ReengagementMessageBuilderInterface $messageBuilder
	) {
		$this->productTracking  = $productTracking;
		$this->loyaltyGenerator = $loyaltyGenerator;
		$this->messageBuilder   = $messageBuilder;
		$this->customerService  = \WCH_Customer_Service::instance();
	}

	/**
	 * Determine the best campaign type for a customer.
	 *
	 * @param array $customer Customer data from profile.
	 * @return string Campaign type constant.
	 */
	public function resolve( array $customer ): string {
		$customerPhone = $customer['phone'] ?? '';

		if ( empty( $customerPhone ) ) {
			return self::TYPE_WE_MISS_YOU;
		}

		// Priority 1: Back in stock items.
		if ( $this->productTracking->hasBackInStockItems( $customerPhone ) ) {
			return self::TYPE_BACK_IN_STOCK;
		}

		// Priority 2: Price drops.
		if ( $this->productTracking->hasPriceDrops( $customerPhone ) ) {
			return self::TYPE_PRICE_DROP;
		}

		// Priority 3: Loyalty reward for high LTV customers.
		if ( $this->loyaltyGenerator->qualifiesForLoyaltyDiscount( $customerPhone ) ) {
			return self::TYPE_LOYALTY_REWARD;
		}

		// Priority 4: New arrivals in customer's categories.
		if ( $this->hasNewArrivalsForCustomer( $customer ) ) {
			return self::TYPE_NEW_ARRIVALS;
		}

		// Default: Generic re-engagement.
		return self::TYPE_WE_MISS_YOU;
	}

	/**
	 * Get all available campaign types with descriptions.
	 *
	 * @return array Associative array of type => description.
	 */
	public function getAvailableTypes(): array {
		return [
			self::TYPE_WE_MISS_YOU    => __( 'Generic re-engagement', 'whatsapp-commerce-hub' ),
			self::TYPE_NEW_ARRIVALS   => __( 'New products since last visit', 'whatsapp-commerce-hub' ),
			self::TYPE_BACK_IN_STOCK  => __( 'Previously viewed items back in stock', 'whatsapp-commerce-hub' ),
			self::TYPE_PRICE_DROP     => __( 'Price drops on viewed products', 'whatsapp-commerce-hub' ),
			self::TYPE_LOYALTY_REWARD => __( 'Discount based on lifetime value', 'whatsapp-commerce-hub' ),
		];
	}

	/**
	 * Check if customer qualifies for a specific campaign type.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param string $campaignType Campaign type.
	 * @return bool True if qualifies.
	 */
	public function qualifiesFor( string $customerPhone, string $campaignType ): bool {
		switch ( $campaignType ) {
			case self::TYPE_BACK_IN_STOCK:
				return $this->productTracking->hasBackInStockItems( $customerPhone );

			case self::TYPE_PRICE_DROP:
				return $this->productTracking->hasPriceDrops( $customerPhone );

			case self::TYPE_LOYALTY_REWARD:
				return $this->loyaltyGenerator->qualifiesForLoyaltyDiscount( $customerPhone );

			case self::TYPE_NEW_ARRIVALS:
				$customer = $this->customerService->get_or_create_profile( $customerPhone );
				if ( ! $customer ) {
					return false;
				}
				$arrivals = $this->messageBuilder->getNewArrivalsForCustomer( $customer );
				return ! empty( $arrivals );

			case self::TYPE_WE_MISS_YOU:
				return true; // Always qualifies.

			default:
				return false;
		}
	}

	/**
	 * Check if customer has new arrivals.
	 *
	 * @param array $customer Customer data.
	 * @return bool True if has new arrivals.
	 */
	protected function hasNewArrivalsForCustomer( array $customer ): bool {
		$profile = $this->customerService->get_or_create_profile( $customer['phone'] ?? '' );
		if ( ! $profile ) {
			return false;
		}
		$products = $this->messageBuilder->getNewArrivalsForCustomer( $profile );
		return ! empty( $products );
	}
}
