<?php
/**
 * Order Created Event Handler
 *
 * Handles order creation events from WhatsApp checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events\Handlers;

use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\OrderCreatedEvent;
use WhatsAppCommerceHub\Features\AbandonedCart\RecoveryService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderCreatedHandler
 *
 * Processes order creation events for notifications and analytics.
 */
class OrderCreatedHandler implements EventHandlerInterface {

	/**
	 * Handle the order created event.
	 *
	 * @param Event $event The order created event.
	 * @return void
	 */
	public function handle( Event|AsyncEventData $event ): void {
		// For async events, verify by event name since we can't use instanceof.
		if ( $event instanceof AsyncEventData ) {
			if ( $event->getName() !== 'wch.order.created' ) {
				return;
			}
		} elseif ( ! $event instanceof OrderCreatedEvent ) {
			return;
		}

		$payload = $event->getPayload();

		// Log the order creation.
		try {
			$logger = wch( LoggerInterface::class );
			$logger->info(
				'Order created event received',
				'events',
				[
					'order_id'       => $payload['order_id'] ?? 0,
					'customer_phone' => $payload['customer_phone'] ?? '',
					'total'          => $payload['total'] ?? 0,
					'source'         => $payload['source'] ?? 'whatsapp',
					'event_id'       => $event->id,
				]
			);
		} catch ( \Throwable $e ) {
			// Ignore logging failures.
		}

		// Update customer statistics.
		$this->updateCustomerStats( $payload );

		// Check if this was a recovered cart.
		$this->checkCartRecovery( $payload );

		// Trigger custom action for plugins.
		do_action( 'wch_order_created', $payload );
	}

	/**
	 * Update customer order statistics.
	 *
	 * @param array $payload Event payload.
	 */
	private function updateCustomerStats( array $payload ): void {
		// Reserved for future customer stats updates.
	}

	/**
	 * Check if the order was from a recovered cart.
	 *
	 * @param array $payload Event payload.
	 */
	private function checkCartRecovery( array $payload ): void {
		$cart_id = $payload['cart_id'] ?? 0;

		if ( empty( $cart_id ) ) {
			return;
		}

		// Check if this cart was previously marked as abandoned.
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d AND status = 'abandoned'",
				$cart_id
			),
			ARRAY_A
		);

		if ( $cart ) {
			// This was an abandoned cart that converted - dispatch recovery event.
			try {
				$logger = wch( LoggerInterface::class );
				$logger->info(
					'Abandoned cart converted to order',
					'events',
					[
						'cart_id'  => $cart_id,
						'order_id' => $payload['order_id'] ?? 0,
					]
				);
			} catch ( \Throwable $e ) {
				// Ignore logging failures.
			}

			try {
				$recovery = wch( RecoveryService::class );
				$recovery->markCartRecovered(
					(int) $cart_id,
					(int) ( $payload['order_id'] ?? 0 ),
					(float) ( $payload['total'] ?? 0 )
				);
			} catch ( \Throwable $e ) {
				// Ignore recovery failures.
			}
		}
	}
}
