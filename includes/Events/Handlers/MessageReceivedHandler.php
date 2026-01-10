<?php
/**
 * Message Received Event Handler
 *
 * Handles incoming WhatsApp message events.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Events\Handlers;

use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\MessageReceivedEvent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageReceivedHandler
 *
 * Processes incoming message events for analytics and automation.
 */
class MessageReceivedHandler implements EventHandlerInterface {

	/**
	 * Handle the message received event.
	 *
	 * @param Event $event The message received event.
	 * @return void
	 */
	public function handle( Event|AsyncEventData $event ): void {
		// For async events, verify by event name since we can't use instanceof.
		if ( $event instanceof AsyncEventData ) {
			if ( $event->getName() !== 'wch.message.received' ) {
				return;
			}
		} elseif ( ! $event instanceof MessageReceivedEvent ) {
			return;
		}

		$payload = $event->getPayload();

		// Log for analytics.
		\WCH_Logger::debug(
			'Message received event handled',
			array(
				'category'        => 'events',
				'message_id'      => $payload['message_id'] ?? 0,
				'conversation_id' => $payload['conversation_id'] ?? 0,
				'from'            => $payload['from'] ?? '',
				'type'            => $payload['type'] ?? '',
				'event_id'        => $event->id,
			)
		);

		// Update customer activity timestamp.
		$this->updateCustomerActivity( $payload );

		// Track message metrics.
		$this->trackMessageMetrics( $payload );

		// Trigger custom action for plugins.
		do_action( 'wch_message_received', $payload );
	}

	/**
	 * Update customer's last activity timestamp.
	 *
	 * @param array $payload Event payload.
	 */
	private function updateCustomerActivity( array $payload ): void {
		$phone = $payload['from'] ?? '';

		if ( empty( $phone ) ) {
			return;
		}

		try {
			$customer_service = \WCH_Customer_Service::instance();
			$customer_service->update_customer_profile(
				$phone,
				array(
					'last_activity_at'  => current_time( 'mysql' ),
					'last_message_at'   => current_time( 'mysql' ),
					'total_messages_in' => 'INCREMENT', // Special handling in update method.
				)
			);
		} catch ( \Exception $e ) {
			// Non-critical, just log.
			\WCH_Logger::debug(
				'Failed to update customer activity',
				array(
					'category' => 'events',
					'error'    => $e->getMessage(),
					'phone'    => $phone,
				)
			);
		}
	}

	/**
	 * Track message metrics for analytics.
	 *
	 * @param array $payload Event payload.
	 */
	private function trackMessageMetrics( array $payload ): void {
		// Increment daily message counter.
		$today_key = 'wch_messages_in_' . gmdate( 'Y-m-d' );
		$count     = (int) get_transient( $today_key );
		set_transient( $today_key, $count + 1, DAY_IN_SECONDS );
	}
}
