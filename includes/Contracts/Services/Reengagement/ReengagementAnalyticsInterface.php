<?php
/**
 * Reengagement Analytics Interface
 *
 * Contract for re-engagement campaign analytics.
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
 * Interface ReengagementAnalyticsInterface
 *
 * Defines methods for analytics tracking.
 */
interface ReengagementAnalyticsInterface {

	/**
	 * Get analytics by campaign type.
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Analytics data by campaign type.
	 */
	public function getAnalytics( int $days = 30 ): array;

	/**
	 * Track a campaign conversion.
	 *
	 * Called when customer makes a purchase after re-engagement.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param int    $orderId Order ID.
	 * @return bool True if tracked.
	 */
	public function trackConversion( string $customerPhone, int $orderId ): bool;

	/**
	 * Update message delivery status.
	 *
	 * @param string $messageId WhatsApp message ID.
	 * @param string $status New status (delivered, read).
	 * @return bool True if updated.
	 */
	public function updateMessageStatus( string $messageId, string $status ): bool;

	/**
	 * Get conversion rate for a campaign type.
	 *
	 * @param string $campaignType Campaign type.
	 * @param int    $days Number of days.
	 * @return float Conversion rate percentage.
	 */
	public function getConversionRate( string $campaignType, int $days = 30 ): float;

	/**
	 * Get total revenue attributed to re-engagement.
	 *
	 * @param int $days Number of days.
	 * @return float Total revenue.
	 */
	public function getAttributedRevenue( int $days = 30 ): float;
}
