<?php
/**
 * Campaign Type Resolver Interface
 *
 * Contract for determining the best re-engagement campaign type.
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
 * Interface CampaignTypeResolverInterface
 *
 * Defines methods for resolving campaign types.
 */
interface CampaignTypeResolverInterface {

	/**
	 * Campaign type constants.
	 */
	public const TYPE_WE_MISS_YOU    = 'we_miss_you';
	public const TYPE_NEW_ARRIVALS   = 'new_arrivals';
	public const TYPE_BACK_IN_STOCK  = 'back_in_stock';
	public const TYPE_PRICE_DROP     = 'price_drop';
	public const TYPE_LOYALTY_REWARD = 'loyalty_reward';

	/**
	 * Determine the best campaign type for a customer.
	 *
	 * Priority order:
	 * 1. Back in stock (if has items)
	 * 2. Price drop (if has items)
	 * 3. Loyalty reward (if high LTV)
	 * 4. New arrivals (if has products in customer's categories)
	 * 5. We miss you (default)
	 *
	 * @param array $customer Customer data from profile.
	 * @return string Campaign type constant.
	 */
	public function resolve( array $customer ): string;

	/**
	 * Get all available campaign types with descriptions.
	 *
	 * @return array Associative array of type => description.
	 */
	public function getAvailableTypes(): array;

	/**
	 * Check if customer qualifies for a specific campaign type.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param string $campaignType Campaign type.
	 * @return bool True if qualifies.
	 */
	public function qualifiesFor( string $customerPhone, string $campaignType ): bool;
}
