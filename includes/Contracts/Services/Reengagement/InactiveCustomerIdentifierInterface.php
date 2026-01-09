<?php
/**
 * Inactive Customer Identifier Interface
 *
 * Contract for identifying inactive customers for re-engagement.
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
 * Interface InactiveCustomerIdentifierInterface
 *
 * Defines methods for identifying inactive customers.
 */
interface InactiveCustomerIdentifierInterface {

	/**
	 * Identify inactive customers.
	 *
	 * Finds customers who:
	 * - Have made at least one purchase
	 * - No orders in X days (configurable)
	 * - Opted in to marketing
	 * - Haven't been messaged recently
	 *
	 * @return array Array of customer data.
	 */
	public function identify(): array;

	/**
	 * Get the inactivity threshold in days.
	 *
	 * @return int Number of days.
	 */
	public function getInactivityThreshold(): int;

	/**
	 * Check if a specific customer is inactive.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if inactive.
	 */
	public function isInactive( string $customerPhone ): bool;

	/**
	 * Get customer purchase history summary.
	 *
	 * @param int $wcCustomerId WooCommerce customer ID.
	 * @return array Summary with last_order_date, total_orders.
	 */
	public function getCustomerPurchaseSummary( int $wcCustomerId ): array;
}
