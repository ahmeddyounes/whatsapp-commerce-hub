<?php
/**
 * Event Handler Interface
 *
 * Contract for event handlers in the system.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface EventHandlerInterface
 *
 * All event handlers must implement this interface.
 */
interface EventHandlerInterface {

	/**
	 * Handle the event.
	 *
	 * Handlers must accept both Event objects (sync dispatch) and
	 * AsyncEventData objects (async dispatch via queue).
	 *
	 * @param Event|AsyncEventData $event The event to handle.
	 * @return void
	 */
	public function handle( Event|AsyncEventData $event ): void;
}
