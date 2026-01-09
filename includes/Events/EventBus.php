<?php
/**
 * Event Bus
 *
 * Central dispatcher for domain events supporting sync and async handling.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Events;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EventBus
 *
 * Dispatches events to registered handlers synchronously or asynchronously.
 */
class EventBus {

	/**
	 * Maximum handlers per event to prevent memory exhaustion.
	 *
	 * @var int
	 */
	private const MAX_HANDLERS_PER_EVENT = 100;

	/**
	 * Maximum total handlers across all events.
	 *
	 * @var int
	 */
	private const MAX_TOTAL_HANDLERS = 1000;

	/**
	 * Registered event handlers.
	 *
	 * @var array<string, array<callable>>
	 */
	private array $handlers = array();

	/**
	 * Async handler configurations.
	 *
	 * @var array<string, array>
	 */
	private array $async_config = array();

	/**
	 * Total handler count for fast limit checking.
	 *
	 * @var int
	 */
	private int $total_handler_count = 0;

	/**
	 * Queue service for async dispatch.
	 *
	 * @var object|null
	 */
	private ?object $queue = null;

	/**
	 * Logger instance.
	 *
	 * @var object|null
	 */
	private ?object $logger = null;

	/**
	 * Constructor.
	 *
	 * @param object|null $queue  The queue service.
	 * @param object|null $logger The logger service.
	 */
	public function __construct( ?object $queue = null, ?object $logger = null ) {
		$this->queue = $queue;
		$this->logger = $logger;
	}

	/**
	 * Register an event handler.
	 *
	 * Enforces limits to prevent memory exhaustion:
	 * - Maximum handlers per event: MAX_HANDLERS_PER_EVENT
	 * - Maximum total handlers: MAX_TOTAL_HANDLERS
	 *
	 * @param string   $event_name The event name (supports wildcards with *).
	 * @param callable $handler    The handler callable.
	 * @param int      $priority   Handler priority (lower runs first).
	 *
	 * @return bool True if handler was registered, false if limit exceeded.
	 *
	 * @throws \OverflowException If total handler limit is exceeded and strict mode is enabled.
	 */
	public function listen( string $event_name, callable $handler, int $priority = 10 ): bool {
		// Check total handler limit.
		if ( $this->total_handler_count >= self::MAX_TOTAL_HANDLERS ) {
			$this->log( 'warning', 'Total handler limit reached', array(
				'event'     => $event_name,
				'limit'     => self::MAX_TOTAL_HANDLERS,
				'current'   => $this->total_handler_count,
			) );
			return false;
		}

		if ( ! isset( $this->handlers[ $event_name ] ) ) {
			$this->handlers[ $event_name ] = array();
		}

		// Check per-event handler limit.
		if ( count( $this->handlers[ $event_name ] ) >= self::MAX_HANDLERS_PER_EVENT ) {
			$this->log( 'warning', 'Per-event handler limit reached', array(
				'event' => $event_name,
				'limit' => self::MAX_HANDLERS_PER_EVENT,
			) );
			return false;
		}

		$this->handlers[ $event_name ][] = array(
			'handler'  => $handler,
			'priority' => $priority,
		);

		++$this->total_handler_count;

		// Sort by priority.
		usort(
			$this->handlers[ $event_name ],
			fn( $a, $b ) => $a['priority'] <=> $b['priority']
		);

		return true;
	}

	/**
	 * Configure an event for async handling.
	 *
	 * @param string $event_name The event name.
	 * @param int    $priority   Queue priority (1-5).
	 * @param int    $delay      Delay in seconds before handling.
	 * @return void
	 */
	public function configureAsync( string $event_name, int $priority = 3, int $delay = 0 ): void {
		$this->async_config[ $event_name ] = array(
			'priority' => $priority,
			'delay'    => $delay,
		);
	}

	/**
	 * Dispatch an event.
	 *
	 * @param Event $event The event to dispatch.
	 * @return void
	 */
	public function dispatch( Event $event ): void {
		$event_name = $event->getName();

		$this->log( 'debug', "Dispatching event: {$event_name}", array(
			'event_id' => $event->id,
		) );

		// Check if event should be async.
		if ( isset( $this->async_config[ $event_name ] ) && $this->queue ) {
			$this->dispatchAsync( $event );
			return;
		}

		// Dispatch synchronously.
		$this->dispatchSync( $event );
	}

	/**
	 * Dispatch event synchronously.
	 *
	 * @param Event $event The event.
	 * @return void
	 */
	private function dispatchSync( Event $event ): void {
		$event_name = $event->getName();
		$handlers = $this->getHandlersFor( $event_name );

		foreach ( $handlers as $handler_config ) {
			try {
				$handler = $handler_config['handler'];
				$handler( $event );
			} catch ( \Throwable $e ) {
				$this->log( 'error', "Handler failed for {$event_name}", array(
					'event_id' => $event->id,
					'error'    => $e->getMessage(),
				) );

				// Continue with other handlers.
			}
		}

		// Also trigger WordPress action for extensibility.
		do_action( $event_name, $event );
		do_action( 'wch.event', $event );
	}

	/**
	 * Dispatch event asynchronously via queue.
	 *
	 * @param Event $event The event.
	 * @return void
	 */
	private function dispatchAsync( Event $event ): void {
		$event_name = $event->getName();
		$config = $this->async_config[ $event_name ];

		try {
			// Pass event name and serialized data.
			// EventServiceProvider::processAsyncEvent expects (string $event_name, array $event_data).
			$this->queue->schedule(
				'wch_process_async_event',
				array( $event_name, $event->toArray() ),
				$config['priority'],
				$config['delay']
			);

			$this->log( 'debug', "Event queued for async: {$event_name}", array(
				'event_id' => $event->id,
			) );
		} catch ( \Throwable $e ) {
			$this->log( 'error', "Failed to queue async event: {$event_name}", array(
				'event_id' => $event->id,
				'error'    => $e->getMessage(),
			) );

			// Fall back to sync dispatch to avoid silent event loss.
			$this->dispatchSync( $event );
		}
	}

	/**
	 * Process a queued async event.
	 *
	 * @param string $event_name The event name.
	 * @param array  $event_data The serialized event data.
	 * @return void
	 */
	public function processAsyncEvent( string $event_name, array $event_data ): void {
		$handlers = $this->getHandlersFor( $event_name );

		// Wrap the array data in AsyncEventData for handler compatibility.
		// This provides the same interface as Event (getPayload(), getName(), etc.)
		// while not requiring complex entity reconstruction.
		$async_event = AsyncEventData::fromArray( $event_data );

		foreach ( $handlers as $handler_config ) {
			try {
				$handler = $handler_config['handler'];
				// Pass the AsyncEventData wrapper which has the same interface as Event.
				$handler( $async_event );
			} catch ( \Throwable $e ) {
				$this->log( 'error', "Async handler failed for {$event_name}", array(
					'event_id' => $event_data['id'] ?? 'unknown',
					'error'    => $e->getMessage(),
				) );
			}
		}

		// Trigger WordPress action with the wrapper.
		do_action( $event_name . '.async', $async_event );
	}

	/**
	 * Get handlers matching an event name.
	 *
	 * @param string $event_name The event name.
	 * @return array The matching handlers.
	 */
	private function getHandlersFor( string $event_name ): array {
		$handlers = array();

		foreach ( $this->handlers as $pattern => $pattern_handlers ) {
			if ( $this->matchesPattern( $event_name, $pattern ) ) {
				$handlers = array_merge( $handlers, $pattern_handlers );
			}
		}

		// Re-sort merged handlers by priority.
		usort(
			$handlers,
			fn( $a, $b ) => $a['priority'] <=> $b['priority']
		);

		return $handlers;
	}

	/**
	 * Check if event name matches a pattern.
	 *
	 * @param string $event_name The event name.
	 * @param string $pattern    The pattern (supports * wildcard).
	 * @return bool True if matches.
	 */
	private function matchesPattern( string $event_name, string $pattern ): bool {
		if ( $pattern === $event_name ) {
			return true;
		}

		if ( str_contains( $pattern, '*' ) ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			return (bool) preg_match( $regex, $event_name );
		}

		return false;
	}

	/**
	 * Remove a handler.
	 *
	 * @param string   $event_name The event name.
	 * @param callable $handler    The handler to remove.
	 * @return bool True if removed.
	 */
	public function removeListener( string $event_name, callable $handler ): bool {
		if ( ! isset( $this->handlers[ $event_name ] ) ) {
			return false;
		}

		foreach ( $this->handlers[ $event_name ] as $key => $config ) {
			if ( $config['handler'] === $handler ) {
				unset( $this->handlers[ $event_name ][ $key ] );
				$this->handlers[ $event_name ] = array_values( $this->handlers[ $event_name ] );

				--$this->total_handler_count;

				// Clean up empty event arrays.
				if ( empty( $this->handlers[ $event_name ] ) ) {
					unset( $this->handlers[ $event_name ] );
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Clear all handlers for a specific event.
	 *
	 * @param string $event_name The event name.
	 * @return int Number of handlers removed.
	 */
	public function clearHandlersFor( string $event_name ): int {
		if ( ! isset( $this->handlers[ $event_name ] ) ) {
			return 0;
		}

		$count = count( $this->handlers[ $event_name ] );
		unset( $this->handlers[ $event_name ] );

		$this->total_handler_count -= $count;

		$this->log( 'debug', "Cleared {$count} handlers for event: {$event_name}" );

		return $count;
	}

	/**
	 * Clear all registered handlers.
	 *
	 * Use this to prevent memory leaks in long-running processes
	 * or when reinitializing the event bus.
	 *
	 * @return int Total number of handlers removed.
	 */
	public function clearHandlers(): int {
		$count = $this->total_handler_count;

		$this->handlers = array();
		$this->async_config = array();
		$this->total_handler_count = 0;

		$this->log( 'debug', "Cleared all handlers ({$count} total)" );

		return $count;
	}

	/**
	 * Get handler count for monitoring.
	 *
	 * @return array<string, int> Handler counts by event.
	 */
	public function getHandlerCounts(): array {
		$counts = array();
		foreach ( $this->handlers as $event => $handlers ) {
			$counts[ $event ] = count( $handlers );
		}
		return $counts;
	}

	/**
	 * Get total handler count.
	 *
	 * @return int Total number of registered handlers.
	 */
	public function getTotalHandlerCount(): int {
		return $this->total_handler_count;
	}

	/**
	 * Check if any handlers are registered for an event.
	 *
	 * @param string $event_name The event name.
	 * @return bool True if handlers exist.
	 */
	public function hasListeners( string $event_name ): bool {
		return ! empty( $this->getHandlersFor( $event_name ) );
	}

	/**
	 * Get all registered event names.
	 *
	 * @return array<string> Event names.
	 */
	public function getRegisteredEvents(): array {
		return array_keys( $this->handlers );
	}

	/**
	 * Log a message.
	 *
	 * @param string $level   Log level.
	 * @param string $message The message.
	 * @param array  $context Context data.
	 * @return void
	 */
	private function log( string $level, string $message, array $context = array() ): void {
		if ( $this->logger ) {
			$this->logger->$level( $message, $context );
		}
	}
}
