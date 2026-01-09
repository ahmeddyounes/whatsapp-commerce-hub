<?php
/**
 * Conversation Repository Interface
 *
 * Interface for conversation data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Repositories;

use WhatsAppCommerceHub\Entities\Conversation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ConversationRepositoryInterface
 *
 * Defines conversation-specific data access operations.
 */
interface ConversationRepositoryInterface extends RepositoryInterface {

	/**
	 * Find a conversation by customer phone number.
	 *
	 * @param string $phone The customer phone number.
	 * @return Conversation|null The conversation or null if not found.
	 */
	public function findByPhone( string $phone ): ?Conversation;

	/**
	 * Find a conversation by WhatsApp conversation ID.
	 *
	 * @param string $wa_conversation_id The WhatsApp conversation ID.
	 * @return Conversation|null The conversation or null if not found.
	 */
	public function findByWhatsAppId( string $wa_conversation_id ): ?Conversation;

	/**
	 * Find active conversations.
	 *
	 * @param int $limit Maximum conversations to return.
	 * @return array<Conversation> Array of active conversations.
	 */
	public function findActive( int $limit = 50 ): array;

	/**
	 * Find conversations pending agent assignment.
	 *
	 * @return array<Conversation> Conversations awaiting human takeover.
	 */
	public function findPendingAssignment(): array;

	/**
	 * Update conversation status.
	 *
	 * @param int    $id     The conversation ID.
	 * @param string $status The new status.
	 * @return bool True on success.
	 */
	public function updateStatus( int $id, string $status ): bool;

	/**
	 * Update conversation state (FSM state).
	 *
	 * @param int    $id    The conversation ID.
	 * @param string $state The FSM state.
	 * @return bool True on success.
	 */
	public function updateState( int $id, string $state ): bool;

	/**
	 * Assign an agent to the conversation.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @param int $agent_id        The agent (user) ID.
	 * @return bool True on success.
	 */
	public function assignAgent( int $conversation_id, int $agent_id ): bool;

	/**
	 * Update the conversation context.
	 *
	 * @param int   $id      The conversation ID.
	 * @param array $context The context data to merge.
	 * @return bool True on success.
	 */
	public function updateContext( int $id, array $context ): bool;

	/**
	 * Find conversations that have been inactive (timed out).
	 *
	 * @param int $timeout_minutes Minutes of inactivity.
	 * @return array<Conversation> Timed out conversations.
	 */
	public function findTimedOut( int $timeout_minutes = 30 ): array;

	/**
	 * Get conversation statistics for a date range.
	 *
	 * @param \DateTimeInterface $start_date Start of the period.
	 * @param \DateTimeInterface $end_date   End of the period.
	 * @return array{total: int, completed: int, abandoned: int, escalated: int}
	 */
	public function getStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array;
}
