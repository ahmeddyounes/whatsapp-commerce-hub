<?php
/**
 * Frequency Cap Manager Interface
 *
 * Contract for managing re-engagement message frequency.
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
 * Interface FrequencyCapManagerInterface
 *
 * Defines methods for frequency capping.
 */
interface FrequencyCapManagerInterface {

	/**
	 * Default caps.
	 */
	public const DEFAULT_WEEKLY_CAP  = 1;
	public const DEFAULT_MONTHLY_CAP = 4;

	/**
	 * Check if customer can receive a message.
	 *
	 * Max 1 message per 7 days, max 4 per month.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if can send.
	 */
	public function canSend( string $customerPhone ): bool;

	/**
	 * Log a sent re-engagement message.
	 *
	 * @param string      $customerPhone Customer phone number.
	 * @param string      $campaignType Campaign type.
	 * @param string|null $messageId WhatsApp message ID.
	 * @return bool True if logged.
	 */
	public function logMessage( string $customerPhone, string $campaignType, ?string $messageId = null ): bool;

	/**
	 * Get message count for a period.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param int    $days Number of days to look back.
	 * @return int Message count.
	 */
	public function getMessageCount( string $customerPhone, int $days ): int;

	/**
	 * Get the last message sent to a customer.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return array|null Message data or null.
	 */
	public function getLastMessage( string $customerPhone ): ?array;
}
