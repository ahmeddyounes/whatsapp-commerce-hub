<?php
/**
 * Context Manager Interface
 *
 * Contract for managing conversation contexts.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ContextManagerInterface
 *
 * Defines the contract for conversation context management.
 */
interface ContextManagerInterface {

	/**
	 * Get context for a conversation.
	 *
	 * @param int $conversationId Conversation ID.
	 * @return ConversationContext Context object.
	 */
	public function getContext( int $conversationId ): ConversationContext;

	/**
	 * Get context by phone number.
	 *
	 * @param string $phone Customer phone number.
	 * @return ConversationContext Context object.
	 */
	public function getContextByPhone( string $phone ): ConversationContext;

	/**
	 * Save context for a conversation.
	 *
	 * @param int                 $conversationId Conversation ID.
	 * @param ConversationContext $context        Context object.
	 * @return bool Success status.
	 */
	public function saveContext( int $conversationId, ConversationContext $context ): bool;

	/**
	 * Clear context for a conversation.
	 *
	 * @param int $conversationId Conversation ID.
	 * @return bool Success status.
	 */
	public function clearContext( int $conversationId ): bool;

	/**
	 * Merge old context with new session data.
	 *
	 * @param ConversationContext $oldContext Old context.
	 * @param array               $newData    New session data.
	 * @return ConversationContext Merged context.
	 */
	public function mergeContexts( ConversationContext $oldContext, array $newData ): ConversationContext;

	/**
	 * Get all expired conversations.
	 *
	 * @return int[] Array of conversation IDs.
	 */
	public function getExpiredConversations(): array;

	/**
	 * Archive expired conversations.
	 *
	 * @return int Number of archived conversations.
	 */
	public function archiveExpiredConversations(): int;
}
