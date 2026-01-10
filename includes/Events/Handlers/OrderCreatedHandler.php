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

use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\OrderCreatedEvent;

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
		\WCH_Logger::info(
			'Order created event received',
			[
				'category'       => 'events',
				'order_id'       => $payload['order_id'] ?? 0,
				'customer_phone' => $payload['customer_phone'] ?? '',
				'total'          => $payload['total'] ?? 0,
				'source'         => $payload['source'] ?? 'whatsapp',
				'event_id'       => $event->id,
			]
		);

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
		$phone = $payload['customer_phone'] ?? '';

		if ( empty( $phone ) ) {
			return;
		}

		try {
			$customer_service = \WCH_Customer_Service::instance();
			$profile          = $customer_service->get_customer_profile( $phone );

			if ( $profile ) {
				$customer_service->update_customer_profile(
					$phone,
					[
						'total_orders'  => ( $profile['total_orders'] ?? 0 ) + 1,
						'total_spent'   => ( $profile['total_spent'] ?? 0 ) + ( $payload['total'] ?? 0 ),
						'last_order_at' => current_time( 'mysql' ),
						'last_order_id' => $payload['order_id'] ?? 0,
					]
				);
			}
		} catch ( \Exception $e ) {
			\WCH_Logger::warning(
				'Failed to update customer stats on order',
				[
					'category' => 'events',
					'error'    => $e->getMessage(),
					'phone'    => $phone,
				]
			);
		}
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
			\WCH_Logger::info(
				'Abandoned cart converted to order',
				[
					'category' => 'events',
					'cart_id'  => $cart_id,
					'order_id' => $payload['order_id'] ?? 0,
				]
			);

			// Track the recovery in the recovery system.
			if ( class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
				$recovery = \WCH_Abandoned_Cart_Recovery::getInstance();
				if ( method_exists( $recovery, 'track_conversion' ) ) {
					$recovery->track_conversion( $cart_id, $payload['order_id'] ?? 0 );
				}
			}
		}
	}
}
