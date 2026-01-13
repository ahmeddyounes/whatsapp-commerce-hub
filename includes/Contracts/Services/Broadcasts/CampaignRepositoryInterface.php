<?php
/**
 * Campaign Repository Interface
 *
 * Contract for campaign data access operations.
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
 * Interface CampaignRepositoryInterface
 *
 * Defines the contract for campaign CRUD operations.
 */
interface CampaignRepositoryInterface {

	/**
	 * Get all campaigns.
	 *
	 * @return array List of campaigns sorted by created_at descending.
	 */
	public function getAll(): array;

	/**
	 * Get a campaign by ID.
	 *
	 * @param int $campaignId Campaign ID.
	 * @return array|null Campaign data or null if not found.
	 */
	public function getById( int $campaignId ): ?array;

	/**
	 * Save a campaign.
	 *
	 * @param array $campaignData Campaign data.
	 * @return array Saved campaign data with ID.
	 */
	public function save( array $campaignData ): array;

	/**
	 * Delete a campaign.
	 *
	 * @param int $campaignId Campaign ID.
	 * @return bool True on success.
	 */
	public function delete( int $campaignId ): bool;

	/**
	 * Duplicate a campaign.
	 *
	 * @param int $campaignId Campaign ID to duplicate.
	 * @return array|null Duplicated campaign data or null on failure.
	 */
	public function duplicate( int $campaignId ): ?array;

	/**
	 * Update campaign status.
	 *
	 * @param int    $campaignId Campaign ID.
	 * @param string $status     New status.
	 * @param array  $extraData  Additional data to update.
	 * @return bool True on success.
	 */
	public function updateStatus( int $campaignId, string $status, array $extraData = [] ): bool;

	/**
	 * Update campaign statistics.
	 *
	 * @param int   $campaignId Campaign ID.
	 * @param array $stats      Statistics data.
	 * @return bool True on success.
	 */
	public function updateStats( int $campaignId, array $stats ): bool;
}
