<?php
/**
 * Event Service Provider
 *
 * Registers the event bus and event handlers with the container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Service provider closures don't need docblocks.

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
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

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
	protected array $handlers = [
		'wch.message.received' => [
			MessageReceivedHandler::class,
		],
		'wch.message.sent'     => [
			MessageSentHandler::class,
		],
		'wch.order.created'    => [
			OrderCreatedHandler::class,
		],
		'wch.cart.abandoned'   => [
			CartAbandonedHandler::class,
		],
		'wch.cart.recovered'   => [
			CartRecoveredHandler::class,
		],
	];

	/**
	 * Events that should be dispatched asynchronously.
	 *
	 * @var array<string>
	 */
	protected array $async_events = [
		'wch.cart.abandoned',
		'wch.message.sent',
	];

	/**
	 * {@inheritdoc}
	 */
	public function register( ContainerInterface $container ): void {
		// Register the EventBus as a singleton with queue and logger injection.
		$container->singleton(
			EventBus::class,
			function ( ContainerInterface $c ): EventBus {
				$queue = $c->has( PriorityQueue::class ) ? $c->get( PriorityQueue::class ) : null;

				$loggerService = $c->get( LoggerInterface::class );
				$logger        = new class( $loggerService ) {
					public function __construct( private LoggerInterface $logger ) {
					}

					public function info( string $message, array $context = [] ): void {
						$this->logger->info( $message, 'events', $context );
					}

					public function error( string $message, array $context = [] ): void {
						$this->logger->error( $message, 'events', $context );
					}

					public function warning( string $message, array $context = [] ): void {
						$this->logger->warning( $message, 'events', $context );
					}

					public function debug( string $message, array $context = [] ): void {
						$this->logger->debug( $message, 'events', $context );
					}
				};

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
		// Note: Receives 1 arg (wrapped v2 payload) not 2 separate args.
		add_action( 'wch_process_async_event', [ $this, 'processAsyncEvent' ], 10, 1 );

		// Register WordPress hooks for event dispatch.
		$this->registerWordPressHooks( $event_bus );
	}

	/**
	 * Process an async event from Action Scheduler.
	 *
	 * Receives a wrapped v2 payload containing [$event_name, $event_data] and
	 * uses EventBus's native processAsyncEvent() method to handle the event.
	 *
	 * @param array $payload Wrapped v2 payload containing event name and data.
	 */
	public function processAsyncEvent( array $payload ): void {
		// Unwrap v2 payload to extract event name and data.
		if ( isset( $payload['_wch_version'] ) && 2 === (int) $payload['_wch_version'] ) {
			$args = $payload['args'] ?? [];
		} else {
			// Legacy format - assume direct args.
			$args = $payload;
		}

		// Validate we have both event name and data.
		if ( ! isset( $args[0] ) || ! isset( $args[1] ) || ! is_string( $args[0] ) || ! is_array( $args[1] ) ) {
			do_action(
				'wch_log_error',
				'Invalid async event payload structure',
				[
					'payload_keys' => array_keys( $payload ),
					'args_count'   => is_array( $args ) ? count( $args ) : 0,
				]
			);
			return;
		}

		$event_name = $args[0];
		$event_data = $args[1];

		try {
			$event_bus = wch_get_container()->get( EventBus::class );

			// Use EventBus's native async event processor which handles array data.
			$event_bus->processAsyncEvent( $event_name, $event_data );
		} catch ( \Throwable $e ) {
			do_action(
				'wch_log_error',
				'Failed to process async event: ' . $e->getMessage(),
				[
					'event_name' => $event_name,
					'event_id'   => $event_data['id'] ?? 'unknown',
				]
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
				if ( ! $cart instanceof \WhatsAppCommerceHub\Domain\Cart\Cart ) {
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
				if ( ! $cart instanceof \WhatsAppCommerceHub\Domain\Cart\Cart ) {
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
	/**
	 * @return array<class-string<\WhatsAppCommerceHub\Container\ServiceProviderInterface>>
	 */
	public function dependsOn(): array {
		return [
			\WhatsAppCommerceHub\Providers\CoreServiceProvider::class,
			\WhatsAppCommerceHub\Providers\QueueServiceProvider::class,
		];
	}

	public function provides(): array {
		return [
			EventBus::class,
			'events',
			// Handler classes.
			MessageReceivedHandler::class,
			MessageSentHandler::class,
			OrderCreatedHandler::class,
			CartAbandonedHandler::class,
			CartRecoveredHandler::class,
		];
	}
}
