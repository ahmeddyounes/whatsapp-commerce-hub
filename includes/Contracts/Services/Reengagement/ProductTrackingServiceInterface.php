<?php
/**
 * Product Tracking Service Interface
 *
 * Contract for tracking product views for re-engagement.
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
 * Interface ProductTrackingServiceInterface
 *
 * Defines methods for product view tracking.
 */
interface ProductTrackingServiceInterface {

	/**
	 * Track a product view.
	 *
	 * Records price and stock status at time of view.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param int    $productId Product ID.
	 * @return bool True if tracked.
	 */
	public function trackView( string $customerPhone, int $productId ): bool;

	/**
	 * Get products that are back in stock for a customer.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return array Array of product data.
	 */
	public function getBackInStockProducts( string $customerPhone ): array;

	/**
	 * Get products with price drops for a customer.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param float  $minDropPercent Minimum drop percentage (default 10%).
	 * @return array Array of product data with price info.
	 */
	public function getPriceDropProducts( string $customerPhone, float $minDropPercent = 10.0 ): array;

	/**
	 * Check if customer has back-in-stock items.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if has items.
	 */
	public function hasBackInStockItems( string $customerPhone ): bool;

	/**
	 * Check if customer has price drop items.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if has items.
	 */
	public function hasPriceDrops( string $customerPhone ): bool;

	/**
	 * Process back-in-stock notifications.
	 *
	 * Scheduled task to find and notify customers.
	 *
	 * @return int Number of notifications queued.
	 */
	public function processBackInStockNotifications(): int;

	/**
	 * Update stock status for a product.
	 *
	 * @param int  $productId Product ID.
	 * @param bool $inStock Whether in stock.
	 * @return bool True if updated.
	 */
	public function updateStockStatus( int $productId, bool $inStock ): bool;
}
