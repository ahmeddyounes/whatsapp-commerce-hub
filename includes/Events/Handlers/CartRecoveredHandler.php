<?php
/**
 * Cart Recovered Event Handler
 *
 * Handles cart recovered events when an abandoned cart converts to an order.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events\Handlers;

use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\CartRecoveredEvent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartRecoveredHandler
 *
 * Tracks cart recovery conversions for analytics.
 */
class CartRecoveredHandler implements EventHandlerInterface {

	/**
	 * Handle the cart recovered event.
	 *
	 * @param Event $event The cart recovered event.
	 * @return void
	 */
	public function handle( Event|AsyncEventData $event ): void {
		// For async events, verify by event name since we can't use instanceof.
		if ( $event instanceof AsyncEventData ) {
			if ( $event->getName() !== 'wch.cart.recovered' ) {
				return;
			}
		} elseif ( ! $event instanceof CartRecoveredEvent ) {
			return;
		}

		$payload = $event->getPayload();

		// Log the recovery event.
		\WCH_Logger::info(
			'Cart recovered event received',
			[
				'category'       => 'events',
				'cart_id'        => $payload['cart_id'] ?? 0,
				'order_id'       => $payload['order_id'] ?? 0,
				'customer_phone' => $payload['customer_phone'] ?? '',
				'event_id'       => $event->id,
			]
		);

		// Track the conversion for analytics.
		$this->trackRecovery( $payload );

		// Trigger custom action for plugins.
		do_action( 'wch_cart_recovered', $payload );
	}

	/**
	 * Track cart recovery conversion in analytics.
	 *
	 * @param array $payload Event payload.
	 */
	private function trackRecovery( array $payload ): void {
		// Update customer profile with recovery data.
		$customer_service = \WCH_Customer_Service::instance();

		try {
			$profile = $customer_service->get_customer_profile( $payload['customer_phone'] ?? '' );

			if ( $profile ) {
				$customer_service->update_customer_profile(
					$payload['customer_phone'],
					[
						'cart_recovery_count'    => ( $profile['cart_recovery_count'] ?? 0 ) + 1,
						'last_cart_recovered_at' => current_time( 'mysql' ),
					]
				);
			}

			// Track conversion in abandoned cart recovery system.
			if ( class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
				$recovery = \WCH_Abandoned_Cart_Recovery::getInstance();
				if ( method_exists( $recovery, 'track_recovery' ) ) {
					$recovery->track_recovery(
						$payload['cart_id'] ?? 0,
						$payload['order_id'] ?? 0
					);
				}
			}
		} catch ( \Exception $e ) {
			\WCH_Logger::warning(
				'Failed to track cart recovery',
				[
					'category' => 'events',
					'error'    => $e->getMessage(),
					'cart_id'  => $payload['cart_id'] ?? 0,
				]
			);
		}
	}
}
