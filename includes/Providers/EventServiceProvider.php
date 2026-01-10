<?php
/**
 * Event Service Provider
 *
 * Registers the event bus and event handlers with the container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Events\EventBus;
use WhatsAppCommerceHub\Events\Handlers\CartAbandonedHandler;
use WhatsAppCommerceHub\Events\Handlers\CartRecoveredHandler;
use WhatsAppCommerceHub\Events\Handlers\MessageReceivedHandler;
use WhatsAppCommerceHub\Events\Handlers\MessageSentHandler;
use WhatsAppCommerceHub\Events\Handlers\OrderCreatedHandler;
use WhatsAppCommerceHub\Queue\PriorityQueue;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EventServiceProvider
 *
 * Provides event bus and handler registrations.
 */
class EventServiceProvider implements ServiceProviderInterface {

	/**
	 * Event handler mappings.
	 *
	 * Maps event names to handler class names.
	 *
	 * @var array<string, array<string>>
	 */
	protected array $handlers = array(
		'wch.message.received' => array(
			MessageReceivedHandler::class,
		),
		'wch.message.sent'     => array(
			MessageSentHandler::class,
		),
		'wch.order.created'    => array(
			OrderCreatedHandler::class,
		),
		'wch.cart.abandoned'   => array(
			CartAbandonedHandler::class,
		),
		'wch.cart.recovered'   => array(
			CartRecoveredHandler::class,
		),
	);

	/**
	 * Events that should be dispatched asynchronously.
	 *
	 * @var array<string>
	 */
	protected array $async_events = array(
		'wch.cart.abandoned',
		'wch.message.sent',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ContainerInterface $container ): void {
		// Register the EventBus as a singleton with queue and logger injection.
		$container->singleton(
			EventBus::class,
			function ( ContainerInterface $c ): EventBus {
				$queue = $c->has( PriorityQueue::class ) ? $c->get( PriorityQueue::class ) : null;

				// Create a logger adapter that wraps WCH_Logger static methods.
				$logger = null;
				if ( class_exists( 'WCH_Logger' ) ) {
					$logger = new class() {
						public function info( string $message, array $context = array() ): void {
							\WCH_Logger::info( $message, array_merge( array( 'category' => 'events' ), $context ) );
						}

						public function error( string $message, array $context = array() ): void {
							\WCH_Logger::error( $message, array_merge( array( 'category' => 'events' ), $context ) );
						}

						public function warning( string $message, array $context = array() ): void {
							\WCH_Logger::warning( $message, array_merge( array( 'category' => 'events' ), $context ) );
						}

						public function debug( string $message, array $context = array() ): void {
							\WCH_Logger::debug( $message, array_merge( array( 'category' => 'events' ), $context ) );
						}
					};
				}

				return new EventBus( $queue, $logger );
			}
		);

		// Alias for easier resolution.
		$container->singleton( 'events', fn( $c ) => $c->get( EventBus::class ) );

		// Register event handlers.
		$container->bind( MessageReceivedHandler::class, fn() => new MessageReceivedHandler() );
		$container->bind( MessageSentHandler::class, fn() => new MessageSentHandler() );
		$container->bind( OrderCreatedHandler::class, fn() => new OrderCreatedHandler() );
		$container->bind( CartAbandonedHandler::class, fn() => new CartAbandonedHandler() );
		$container->bind( CartRecoveredHandler::class, fn() => new CartRecoveredHandler() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ContainerInterface $container ): void {
		$event_bus = $container->get( EventBus::class );

		// Register all event handlers.
		foreach ( $this->handlers as $event_name => $handler_classes ) {
			foreach ( $handler_classes as $handler_class ) {
				// Create a closure that resolves the handler from the container.
				$event_bus->listen(
					$event_name,
					function ( $event ) use ( $container, $handler_class ) {
						$handler = $container->get( $handler_class );
						return $handler->handle( $event );
					}
				);
			}
		}

		// Configure async events.
		foreach ( $this->async_events as $event_name ) {
			$event_bus->configureAsync( $event_name );
		}

		// Hook into WordPress for async event processing.
		add_action( 'wch_process_async_event', array( $this, 'processAsyncEvent' ), 10, 2 );

		// Register WordPress hooks for event dispatch.
		$this->registerWordPressHooks( $event_bus );
	}

	/**
	 * Process an async event from Action Scheduler.
	 *
	 * Uses the EventBus's native processAsyncEvent() method which handles
	 * array-based event data directly, avoiding complex event reconstruction.
	 *
	 * @param string $event_name The event name (e.g., 'wch.cart.abandoned').
	 * @param array  $event_data The serialized event data from Event::toArray().
	 */
	public function processAsyncEvent( string $event_name, array $event_data ): void {
		try {
			$event_bus = wch_get_container()->get( EventBus::class );

			// Use EventBus's native async event processor which handles array data.
			$event_bus->processAsyncEvent( $event_name, $event_data );
		} catch ( \Throwable $e ) {
			do_action(
				'wch_log_error',
				'Failed to process async event: ' . $e->getMessage(),
				array(
					'event_name' => $event_name,
					'event_id'   => $event_data['id'] ?? 'unknown',
				)
			);
		}
	}

	/**
	 * Register WordPress action hooks that dispatch events.
	 *
	 * @param EventBus $event_bus The event bus instance.
	 */
	protected function registerWordPressHooks( EventBus $event_bus ): void {
		// Hook into WooCommerce order creation.
		add_action(
			'woocommerce_new_order',
			function ( $order_id ) use ( $event_bus ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return;
				}

				// Check if this order came from WhatsApp.
				$whatsapp_source = $order->get_meta( '_wch_whatsapp_order' );
				if ( ! $whatsapp_source ) {
					return;
				}

				$phone           = $order->get_billing_phone();
				$conversation_id = $order->get_meta( '_wch_conversation_id' );
				$from_recovery   = $order->get_meta( '_wch_from_recovery' );

				$event = new \WhatsAppCommerceHub\Events\OrderCreatedEvent(
					$order_id,
					$phone,
					(float) $order->get_total(),
					$conversation_id ? (int) $conversation_id : null,
					(bool) $from_recovery
				);

				$event_bus->dispatch( $event );
			}
		);

		// Hook into cart abandonment detection.
		add_action(
			'wch_cart_marked_abandoned',
			function ( $cart ) use ( $event_bus ) {
				if ( ! $cart instanceof \WhatsAppCommerceHub\Entities\Cart ) {
					return;
				}

				$event = new \WhatsAppCommerceHub\Events\CartAbandonedEvent( $cart );
				$event_bus->dispatch( $event ); // Async handling configured in boot().
			}
		);

		// Hook into cart recovery.
		add_action(
			'wch_cart_recovered',
			function ( $cart, $order_id ) use ( $event_bus ) {
				if ( ! $cart instanceof \WhatsAppCommerceHub\Entities\Cart ) {
					return;
				}

				$event = new \WhatsAppCommerceHub\Events\CartRecoveredEvent( $cart, $order_id );
				$event_bus->dispatch( $event );
			},
			10,
			2
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			EventBus::class,
			'events',
			// Handler classes.
			MessageReceivedHandler::class,
			MessageSentHandler::class,
			OrderCreatedHandler::class,
			CartAbandonedHandler::class,
			CartRecoveredHandler::class,
		);
	}
}
