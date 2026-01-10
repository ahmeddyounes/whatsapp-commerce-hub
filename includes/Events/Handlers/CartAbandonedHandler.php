<?php
/**
 * Cart Abandoned Event Handler
 *
 * Handles abandoned cart events by scheduling recovery messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events\Handlers;

use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\CartAbandonedEvent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartAbandonedHandler
 *
 * Triggers abandoned cart recovery workflow.
 */
class CartAbandonedHandler implements EventHandlerInterface {

	/**
	 * Handle the cart abandoned event.
	 *
	 * @param Event|AsyncEventData $event The cart abandoned event.
	 * @return void
	 */
	public function handle( Event|AsyncEventData $event ): void {
		// For async events, verify by event name since we can't use instanceof.
		if ( $event instanceof AsyncEventData ) {
			if ( $event->getName() !== 'wch.cart.abandoned' ) {
				return;
			}
		} elseif ( ! $event instanceof CartAbandonedEvent ) {
			return;
		}

		$payload = $event->getPayload();

		// Log the abandoned cart event for analytics.
		\WCH_Logger::info(
			'Cart abandoned event received',
			array(
				'category'       => 'events',
				'cart_id'        => $payload['cart_id'] ?? 0,
				'customer_phone' => $payload['customer_phone'] ?? '',
				'total'          => $payload['total'] ?? 0,
				'item_count'     => $payload['item_count'] ?? 0,
				'event_id'       => $event->id,
			)
		);

		// Track the abandonment for analytics.
		$this->trackAbandonment( $payload );

		// The actual recovery message scheduling is handled by
		// WCH_Abandoned_Cart_Recovery::schedule_recovery_reminders()
		// which runs on a cron schedule to batch process abandoned carts.
		// We trigger a custom action here for any plugins that want to
		// respond immediately to cart abandonment.
		do_action( 'wch_cart_abandoned', $payload );
	}

	/**
	 * Track cart abandonment in analytics.
	 *
	 * @param array $payload Event payload.
	 */
	private function trackAbandonment( array $payload ): void {
		// Update customer profile with abandonment data.
		$customer_service = \WCH_Customer_Service::instance();

		try {
			$profile = $customer_service->get_customer_profile( $payload['customer_phone'] ?? '' );

			if ( $profile ) {
				$customer_service->update_customer_profile(
					$payload['customer_phone'],
					array(
						'cart_abandonment_count' => ( $profile['cart_abandonment_count'] ?? 0 ) + 1,
						'last_cart_abandoned_at' => current_time( 'mysql' ),
					)
				);
			}
		} catch ( \Exception $e ) {
			\WCH_Logger::warning(
				'Failed to track cart abandonment',
				array(
					'category' => 'events',
					'error'    => $e->getMessage(),
					'phone'    => $payload['customer_phone'] ?? '',
				)
			);
		}
	}
}
