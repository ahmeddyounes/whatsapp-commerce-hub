<?php
/**
 * Action Handler Interface
 *
 * Contract for action handlers that process user intents and drive conversation flow.
 *
 * Action handlers are the core business logic components that:
 * - Process user messages and button clicks
 * - Interact with WooCommerce products, cart, and orders
 * - Generate WhatsApp messages in response
 * - Manage FSM state transitions
 * - Update conversation context
 *
 * All action handlers must implement this interface and are registered with ActionRegistry.
 * Handlers are selected based on priority (higher = first to execute).
 *
 * Implementation Guide:
 * 1. Extend AbstractAction for common functionality (cart, customer, logging)
 * 2. Implement getName() to return primary action name (e.g., 'add_to_cart')
 * 3. Implement supports() to support additional action names or aliases
 * 4. Implement getPriority() - use higher values (20+) to override built-in handlers
 * 5. Implement handle() with validation, business logic, and message building
 *
 * Example:
 * ```php
 * class MyAction extends AbstractAction {
 *     public function getName(): string { return 'my_action'; }
 *     public function supports(string $action): bool { return $action === 'my_action'; }
 *     public function getPriority(): int { return 10; }
 *
 *     public function handle(string $phone, array $params, ConversationContext $ctx): ActionResult {
 *         // Validate, process, build messages
 *         $msg = $this->createMessageBuilder()->text("Result");
 *         return ActionResult::success([$msg], 'next_state');
 *     }
 * }
 * ```
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 * @see AbstractAction Base implementation with helpers
 * @see ActionRegistry Handler registration and dispatch
 * @see ActionResult Return value from handle()
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
 * Defines contract for action handlers that execute during conversation flow.
 *
 * Handlers process user intents, interact with WooCommerce, and generate responses.
 */
interface ActionHandlerInterface {

	/**
	 * Execute the action.
	 *
	 * This is the main entry point for action processing. Handlers should:
	 * 1. Validate parameters and business rules
	 * 2. Interact with services (cart, customer, products)
	 * 3. Build response messages using MessageBuilder
	 * 4. Return ActionResult with success/failure, messages, state transitions, context updates
	 *
	 * @param string              $phone   Customer phone number (E.164 format).
	 * @param array               $params  Action parameters extracted from message or button payload.
	 *                                     Common params: product_id, variant_id, quantity, category_id.
	 * @param ConversationContext $context Current conversation state, slots, and history.
	 * @return ActionResult Result containing messages, next state, and context updates.
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult;

	/**
	 * Check if this handler supports the given action name.
	 *
	 * Allows handlers to support multiple action names or aliases.
	 * Used by ActionRegistry::findSupporting() for broader action matching.
	 *
	 * @param string $actionName Action name to check.
	 * @return bool True if handler can process this action.
	 */
	public function supports( string $actionName ): bool;

	/**
	 * Get the primary action name this handler responds to.
	 *
	 * This is the canonical name registered in ActionRegistry.
	 * Should be lowercase snake_case (e.g., 'add_to_cart', 'show_product').
	 *
	 * @return string Action name.
	 */
	public function getName(): string;

	/**
	 * Get action priority (higher priority handlers execute first).
	 *
	 * Priority determines execution order when multiple handlers are registered for the same action.
	 * Common values:
	 * - Default: 10 (standard handlers)
	 * - High: 20+ (override built-in handlers)
	 * - Low: 1-9 (fallback handlers)
	 *
	 * @return int Priority level (default: 10).
	 */
	public function getPriority(): int;
}
