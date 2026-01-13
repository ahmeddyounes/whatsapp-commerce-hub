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

use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\CartRecoveredEvent;
use WhatsAppCommerceHub\Features\AbandonedCart\RecoveryService;

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
		$logger = null;
		try {
			$logger = wch( LoggerInterface::class );
		} catch ( \Throwable $e ) {
			$logger = null;
		}

		if ( $logger ) {
			$logger->info(
				'Cart recovered event received',
				'events',
				[
					'cart_id'        => $payload['cart_id'] ?? 0,
					'order_id'       => $payload['order_id'] ?? 0,
					'customer_phone' => $payload['customer_phone'] ?? '',
					'event_id'       => $event->id,
				]
			);
		}

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
		try {
			$recovery = wch( RecoveryService::class );
			$recovery->markCartRecovered(
				(int) ( $payload['cart_id'] ?? 0 ),
				(int) ( $payload['order_id'] ?? 0 ),
				(float) ( $payload['total'] ?? 0 )
			);
		} catch ( \Exception $e ) {
			try {
				$logger = wch( LoggerInterface::class );
				$logger->warning(
					'Failed to track cart recovery',
					'events',
					[
						'error'   => $e->getMessage(),
						'cart_id' => $payload['cart_id'] ?? 0,
					]
				);
			} catch ( \Throwable $loggerError ) {
				// No-op if logger unavailable.
			}
		}
	}
}
