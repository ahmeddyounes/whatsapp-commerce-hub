<?php
/**
 * Message Received Event
 *
 * Dispatched when an inbound WhatsApp message is received.
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
 * Class MessageReceivedEvent
 *
 * Event for incoming WhatsApp messages.
 */
class MessageReceivedEvent extends Event {

	/**
	 * The message entity.
	 *
	 * @var Message
	 */
	public readonly Message $message;

	/**
	 * The sender phone number.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The conversation ID.
	 *
	 * @var int
	 */
	public readonly int $conversation_id;

	/**
	 * Constructor.
	 *
	 * @param Message $message         The message entity.
	 * @param string  $from            The sender phone number.
	 * @param int     $conversation_id The conversation ID.
	 */
	public function __construct( Message $message, string $from, int $conversation_id ) {
		parent::__construct();
		$this->message         = $message;
		$this->from            = $from;
		$this->conversation_id = $conversation_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'wch.message.received';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPayload(): array {
		return array(
			'message_id'      => $this->message->id,
			'wa_message_id'   => $this->message->wa_message_id,
			'conversation_id' => $this->conversation_id,
			'from'            => $this->from,
			'type'            => $this->message->type,
			'content'         => $this->message->content,
		);
	}
}
