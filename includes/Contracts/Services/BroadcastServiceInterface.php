<?php
/**
 * Broadcast Service Interface
 *
 * Defines the contract for broadcast campaign operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.1.0
 */

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface BroadcastServiceInterface
 *
 * Contract for broadcast campaign management.
 */
interface BroadcastServiceInterface {

	/**
	 * Get all campaigns.
	 *
	 * @return array List of campaigns.
	 */
	public function getCampaigns(): array;

	/**
	 * Get a campaign by ID.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|null Campaign data or null if not found.
	 */
	public function getCampaign( int $campaign_id ): ?array;

	/**
	 * Save a campaign.
	 *
	 * @param array $campaign_data Campaign data.
	 * @return array Saved campaign data with ID.
	 */
	public function saveCampaign( array $campaign_data ): array;

	/**
	 * Delete a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool True if deleted, false otherwise.
	 */
	public function deleteCampaign( int $campaign_id ): bool;

	/**
	 * Duplicate a campaign.
	 *
	 * @param int $campaign_id Campaign ID to duplicate.
	 * @return array|null Duplicated campaign data or null on failure.
	 */
	public function duplicateCampaign( int $campaign_id ): ?array;

	/**
	 * Schedule and send a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array Result with status and campaign data.
	 */
	public function sendCampaign( int $campaign_id ): array;

	/**
	 * Send a test broadcast message.
	 *
	 * @param array  $campaign_data Campaign data.
	 * @param string $phone_number Target phone number.
	 * @return bool True if sent successfully.
	 */
	public function sendTestBroadcast( array $campaign_data, string $phone_number ): bool;

	/**
	 * Calculate audience count based on criteria.
	 *
	 * @param array $criteria Audience criteria.
	 * @return int Number of recipients.
	 */
	public function calculateAudienceCount( array $criteria ): int;

	/**
	 * Get campaign recipients based on audience criteria.
	 *
	 * @param array $campaign Campaign data.
	 * @return array List of phone numbers.
	 */
	public function getCampaignRecipients( array $campaign ): array;

	/**
	 * Get campaign report/statistics.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array Campaign stats.
	 */
	public function getCampaignReport( int $campaign_id ): array;

	/**
	 * Get approved templates for broadcasts.
	 *
	 * @return array List of approved templates.
	 */
	public function getApprovedTemplates(): array;

	/**
	 * Process a broadcast batch.
	 *
	 * @param array $batch Batch of recipients.
	 * @param int   $campaign_id Campaign ID.
	 * @param array $message Message data.
	 * @return array Result with sent, failed counts.
	 */
	public function processBroadcastBatch( array $batch, int $campaign_id, array $message ): array;
}
