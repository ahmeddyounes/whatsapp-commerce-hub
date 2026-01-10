<?php
/**
 * Message Sent Event
 *
 * Dispatched when an outbound WhatsApp message is sent successfully.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events;

use WhatsAppCommerceHub\Entities\Message;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageSentEvent
 *
 * Event for outgoing WhatsApp messages.
 */
class MessageSentEvent extends Event {

	/**
	 * The message entity.
	 *
	 * @var Message
	 */
	public readonly Message $message;

	/**
	 * The recipient phone number.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * Constructor.
	 *
	 * @param Message $message The message entity.
	 * @param string  $to      The recipient phone number.
	 */
	public function __construct( Message $message, string $to ) {
		parent::__construct();
		$this->message = $message;
		$this->to      = $to;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'wch.message.sent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPayload(): array {
		return array(
			'message_id'      => $this->message->id,
			'wa_message_id'   => $this->message->wa_message_id,
			'conversation_id' => $this->message->conversation_id,
			'to'              => $this->to,
			'type'            => $this->message->type,
		);
	}
}
