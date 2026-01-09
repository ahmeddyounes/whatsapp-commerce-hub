<?php
/**
 * Message Repository Interface
 *
 * Interface for message data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Repositories;

use WhatsAppCommerceHub\Entities\Message;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface MessageRepositoryInterface
 *
 * Defines message-specific data access operations.
 */
interface MessageRepositoryInterface extends RepositoryInterface {

	/**
	 * Find messages by conversation ID.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @param int $limit           Maximum messages to return.
	 * @param int $offset          Number of messages to skip.
	 * @return array<Message> Array of messages, newest first.
	 */
	public function findByConversation( int $conversation_id, int $limit = 50, int $offset = 0 ): array;

	/**
	 * Find a message by WhatsApp message ID.
	 *
	 * @param string $wa_message_id The WhatsApp message ID.
	 * @return Message|null The message or null if not found.
	 */
	public function findByWhatsAppMessageId( string $wa_message_id ): ?Message;

	/**
	 * Update message delivery status.
	 *
	 * @param string $wa_message_id The WhatsApp message ID.
	 * @param string $status        The new status (sent, delivered, read, failed).
	 * @return bool True on success.
	 */
	public function updateStatus( string $wa_message_id, string $status ): bool;

	/**
	 * Get unread message count for a conversation.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @return int Number of unread messages.
	 */
	public function getUnreadCount( int $conversation_id ): int;

	/**
	 * Mark messages as read.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @return int Number of messages marked as read.
	 */
	public function markAsRead( int $conversation_id ): int;

	/**
	 * Find failed messages for retry.
	 *
	 * @param int $max_retries Maximum retry attempts.
	 * @param int $limit       Maximum messages to return.
	 * @return array<Message> Failed messages eligible for retry.
	 */
	public function findFailedForRetry( int $max_retries = 3, int $limit = 50 ): array;

	/**
	 * Increment retry count for a message.
	 *
	 * @param int    $message_id The message ID.
	 * @param string $error      The error message.
	 * @return bool True on success.
	 */
	public function incrementRetryCount( int $message_id, string $error ): bool;

	/**
	 * Get message statistics for a date range.
	 *
	 * @param \DateTimeInterface $start_date Start of the period.
	 * @param \DateTimeInterface $end_date   End of the period.
	 * @return array{total: int, inbound: int, outbound: int, delivered: int, failed: int}
	 */
	public function getStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array;
}
