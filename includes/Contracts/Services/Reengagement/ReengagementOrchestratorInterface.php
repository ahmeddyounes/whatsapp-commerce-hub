<?php
/**
 * Reengagement Orchestrator Interface
 *
 * Contract for orchestrating re-engagement campaigns.
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
 * Interface ReengagementOrchestratorInterface
 *
 * Defines methods for orchestrating re-engagement.
 */
interface ReengagementOrchestratorInterface {

	/**
	 * Initialize the re-engagement system.
	 *
	 * Sets up scheduled tasks.
	 *
	 * @return void
	 */
	public function init(): void;

	/**
	 * Process re-engagement campaigns.
	 *
	 * Main scheduled task that runs daily.
	 *
	 * @return int Number of messages queued.
	 */
	public function processCampaigns(): int;

	/**
	 * Queue a re-engagement message for a customer.
	 *
	 * @param array $customer Customer data.
	 * @return bool True if queued.
	 */
	public function queueMessage( array $customer ): bool;

	/**
	 * Send a re-engagement message.
	 *
	 * Job handler for queued messages.
	 *
	 * @param array $args Job arguments with customer_phone and campaign_type.
	 * @return array Result with success status.
	 */
	public function sendMessage( array $args ): array;

	/**
	 * Check if re-engagement is enabled.
	 *
	 * @return bool True if enabled.
	 */
	public function isEnabled(): bool;
}
