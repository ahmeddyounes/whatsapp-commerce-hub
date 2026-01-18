<?php
/**
 * Action Registry
 *
 * Manages registration and lookup of action handlers with priority-based dispatch.
 *
 * The ActionRegistry is the central orchestrator for all action handlers in the system.
 * It provides:
 *
 * - Priority-based handler selection (higher priority executes first)
 * - Multiple handlers per action (allows overriding and fallback handlers)
 * - Automatic sorting and caching for performance
 * - Query capabilities for registered actions
 *
 * Extension Points:
 * - Register handlers via ActionServiceProvider::addHandler() or
 * - Use the 'wch_register_action_handlers' WordPress hook
 *
 * Example Usage:
 * ```php
 * // Register a custom handler
 * add_action('wch_register_action_handlers', function($registry, $container) {
 *     $handler = new MyCustomAction();
 *     $handler->setLogger($container->get('logger'));
 *     $registry->register($handler);
 * }, 10, 2);
 * ```
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 * @see ActionHandlerInterface For handler contract
 * @see ActionServiceProvider For registration hooks
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Actions;

use WhatsAppCommerceHub\Actions\Contracts\ActionHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActionRegistry
 *
 * Central registry for action handlers with priority-based dispatch.
 */
class ActionRegistry {

	/**
	 * Registered action handlers.
	 *
	 * @var array<string, ActionHandlerInterface[]>
	 */
	private array $handlers = [];

	/**
	 * Sorted handlers cache.
	 *
	 * @var array<string, ActionHandlerInterface[]>
	 */
	private array $sortedHandlers = [];

	/**
	 * Register an action handler.
	 *
	 * @param ActionHandlerInterface $handler Handler instance.
	 * @return self
	 */
	public function register( ActionHandlerInterface $handler ): self {
		$actionName = $handler->getName();

		if ( ! isset( $this->handlers[ $actionName ] ) ) {
			$this->handlers[ $actionName ] = [];
		}

		$this->handlers[ $actionName ][] = $handler;

		// Clear sorted cache for this action.
		unset( $this->sortedHandlers[ $actionName ] );

		return $this;
	}

	/**
	 * Register multiple handlers at once.
	 *
	 * @param ActionHandlerInterface[] $handlers Array of handlers.
	 * @return self
	 */
	public function registerMany( array $handlers ): self {
		foreach ( $handlers as $handler ) {
			$this->register( $handler );
		}

		return $this;
	}

	/**
	 * Check if a handler exists for the given action.
	 *
	 * @param string $actionName Action name.
	 * @return bool
	 */
	public function has( string $actionName ): bool {
		return isset( $this->handlers[ $actionName ] ) && ! empty( $this->handlers[ $actionName ] );
	}

	/**
	 * Get handler for action.
	 *
	 * Returns the highest priority handler that supports the action.
	 *
	 * @param string $actionName Action name.
	 * @return ActionHandlerInterface|null Handler or null if not found.
	 */
	public function get( string $actionName ): ?ActionHandlerInterface {
		$handlers = $this->getHandlers( $actionName );

		return ! empty( $handlers ) ? $handlers[0] : null;
	}

	/**
	 * Get all handlers for action, sorted by priority.
	 *
	 * @param string $actionName Action name.
	 * @return ActionHandlerInterface[]
	 */
	public function getHandlers( string $actionName ): array {
		if ( ! $this->has( $actionName ) ) {
			return [];
		}

		// Return cached sorted handlers.
		if ( isset( $this->sortedHandlers[ $actionName ] ) ) {
			return $this->sortedHandlers[ $actionName ];
		}

		// Sort handlers by priority (higher priority first).
		$handlers = $this->handlers[ $actionName ];
		usort(
			$handlers,
			function ( ActionHandlerInterface $a, ActionHandlerInterface $b ) {
				return $b->getPriority() - $a->getPriority();
			}
		);

		$this->sortedHandlers[ $actionName ] = $handlers;

		return $handlers;
	}

	/**
	 * Execute action handler.
	 *
	 * @param string              $actionName Action name.
	 * @param string              $phone      Customer phone.
	 * @param array               $params     Action parameters.
	 * @param ConversationContext $context    Conversation context.
	 * @return ActionResult|null Result or null if no handler found.
	 */
	public function execute(
		string $actionName,
		string $phone,
		array $params,
		ConversationContext $context
	): ?ActionResult {
		$handler = $this->get( $actionName );

		if ( ! $handler ) {
			wch( LoggerInterface::class )->warning(
				'No handler found for action',
				'action',
				[ 'action' => $actionName ]
			);
			return null;
		}

		return $handler->handle( $phone, $params, $context );
	}

	/**
	 * Get all registered action names.
	 *
	 * @return string[]
	 */
	public function getRegisteredActions(): array {
		return array_keys( $this->handlers );
	}

	/**
	 * Get total handler count.
	 *
	 * @return int
	 */
	public function count(): int {
		$count = 0;
		foreach ( $this->handlers as $handlers ) {
			$count += count( $handlers );
		}
		return $count;
	}

	/**
	 * Remove handler for action.
	 *
	 * @param string $actionName Action name.
	 * @return self
	 */
	public function remove( string $actionName ): self {
		unset( $this->handlers[ $actionName ] );
		unset( $this->sortedHandlers[ $actionName ] );

		return $this;
	}

	/**
	 * Clear all registered handlers.
	 *
	 * @return self
	 */
	public function clear(): self {
		$this->handlers       = [];
		$this->sortedHandlers = [];

		return $this;
	}

	/**
	 * Find handler that supports the given action.
	 *
	 * This performs a broader search by checking all handlers' supports() method.
	 *
	 * @param string $actionName Action name.
	 * @return ActionHandlerInterface|null
	 */
	public function findSupporting( string $actionName ): ?ActionHandlerInterface {
		// First check direct registration.
		$handler = $this->get( $actionName );
		if ( $handler ) {
			return $handler;
		}

		// Check all handlers' supports() method.
		foreach ( $this->handlers as $handlers ) {
			foreach ( $handlers as $handler ) {
				if ( $handler->supports( $actionName ) ) {
					return $handler;
				}
			}
		}

		return null;
	}
}
