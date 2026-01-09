<?php
/**
 * Campaign Dispatcher Interface
 *
 * Contract for dispatching broadcast campaigns.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\Broadcasts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CampaignDispatcherInterface
 *
 * Defines the contract for campaign dispatch operations.
 */
interface CampaignDispatcherInterface {

	/**
	 * Schedule a campaign for sending.
	 *
	 * @param array $campaign Campaign data.
	 * @param int   $delay    Delay in seconds (0 for immediate).
	 * @return string|null Job ID or null on failure.
	 */
	public function schedule( array $campaign, int $delay = 0 ): ?string;

	/**
	 * Send a test broadcast message.
	 *
	 * @param array  $campaign  Campaign data.
	 * @param string $testPhone Phone number to send test to.
	 * @return array{success: bool, message: string} Result.
	 */
	public function sendTest( array $campaign, string $testPhone ): array;

	/**
	 * Cancel a scheduled campaign.
	 *
	 * @param int $campaignId Campaign ID.
	 * @return bool True on success.
	 */
	public function cancel( int $campaignId ): bool;

	/**
	 * Build campaign message from template.
	 *
	 * @param array $campaign Campaign data.
	 * @return array Message data for sending.
	 */
	public function buildMessage( array $campaign ): array;

	/**
	 * Get estimated send cost.
	 *
	 * @param int $recipientCount Number of recipients.
	 * @return float Estimated cost.
	 */
	public function getEstimatedCost( int $recipientCount ): float;
}
