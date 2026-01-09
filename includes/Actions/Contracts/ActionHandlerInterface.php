<?php
/**
 * Action Handler Interface
 *
 * Contract for flow action handlers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Actions\Contracts;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ActionHandlerInterface
 *
 * Defines contract for action handlers that execute during state transitions.
 */
interface ActionHandlerInterface {

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters from payload.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult Action result with messages and state updates.
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult;

	/**
	 * Check if this handler supports the given action name.
	 *
	 * @param string $actionName Action name to check.
	 * @return bool True if supported.
	 */
	public function supports( string $actionName ): bool;

	/**
	 * Get the action name this handler responds to.
	 *
	 * @return string Action name.
	 */
	public function getName(): string;

	/**
	 * Get action priority (higher priority handlers run first).
	 *
	 * @return int Priority level.
	 */
	public function getPriority(): int;
}
