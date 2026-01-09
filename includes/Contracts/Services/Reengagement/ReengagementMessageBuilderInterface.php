<?php
/**
 * Reengagement Message Builder Interface
 *
 * Contract for building re-engagement messages.
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
 * Interface ReengagementMessageBuilderInterface
 *
 * Defines methods for building campaign messages.
 */
interface ReengagementMessageBuilderInterface {

	/**
	 * Build a campaign message for a customer.
	 *
	 * @param object $customer Customer profile object.
	 * @param string $campaignType Campaign type.
	 * @return array|null Message data with 'text' and 'type' keys, or null on failure.
	 */
	public function build( object $customer, string $campaignType ): ?array;

	/**
	 * Format a product list for message content.
	 *
	 * @param array $products Array of products.
	 * @param bool  $showPriceDrop Whether to show price drop info.
	 * @param int   $limit Max products to show.
	 * @return string Formatted product list.
	 */
	public function formatProductList( array $products, bool $showPriceDrop = false, int $limit = 3 ): string;

	/**
	 * Get new arrivals for a customer based on purchase history.
	 *
	 * @param object $customer Customer profile.
	 * @param int    $limit Number of products.
	 * @return array Array of product data.
	 */
	public function getNewArrivalsForCustomer( object $customer, int $limit = 3 ): array;

	/**
	 * Get customer's purchased category IDs.
	 *
	 * @param object $customer Customer profile.
	 * @return array Array of category term IDs.
	 */
	public function getCustomerCategories( object $customer ): array;

	/**
	 * Get the last purchased product for a customer.
	 *
	 * @param object $customer Customer profile.
	 * @return array|null Product data or null.
	 */
	public function getLastPurchasedProduct( object $customer ): ?array;
}
