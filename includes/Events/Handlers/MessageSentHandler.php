<?php
/**
 * Message Sent Event Handler
 *
 * Handles outgoing WhatsApp message events.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events\Handlers;

use WhatsAppCommerceHub\Events\Event;
use WhatsAppCommerceHub\Events\AsyncEventData;
use WhatsAppCommerceHub\Events\EventHandlerInterface;
use WhatsAppCommerceHub\Events\MessageSentEvent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageSentHandler
 *
 * Processes outgoing message events for analytics and tracking.
 */
class MessageSentHandler implements EventHandlerInterface {

	/**
	 * Handle the message sent event.
	 *
	 * @param Event $event The message sent event.
	 * @return void
	 */
	public function handle( Event|AsyncEventData $event ): void {
		// For async events, verify by event name since we can't use instanceof.
		if ( $event instanceof AsyncEventData ) {
			if ( $event->getName() !== 'wch.message.sent' ) {
				return;
			}
		} elseif ( ! $event instanceof MessageSentEvent ) {
			return;
		}

		$payload = $event->getPayload();

		// Log for analytics.
		\WCH_Logger::debug(
			'Message sent event handled',
			[
				'category'        => 'events',
				'message_id'      => $payload['message_id'] ?? 0,
				'conversation_id' => $payload['conversation_id'] ?? 0,
				'to'              => $payload['to'] ?? '',
				'type'            => $payload['type'] ?? '',
				'event_id'        => $event->id,
			]
		);

		// Track outgoing message metrics.
		$this->trackMessageMetrics( $payload );

		// Trigger custom action for plugins.
		do_action( 'wch_message_sent', $payload );
	}

	/**
	 * Track outgoing message metrics for analytics.
	 *
	 * @param array $payload Event payload.
	 */
	private function trackMessageMetrics( array $payload ): void {
		// Increment daily outgoing message counter.
		$today_key = 'wch_messages_out_' . gmdate( 'Y-m-d' );
		$count     = (int) get_transient( $today_key );
		set_transient( $today_key, $count + 1, DAY_IN_SECONDS );
	}
}
