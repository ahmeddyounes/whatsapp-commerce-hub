<?php
/**
 * Order Created Event
 *
 * Dispatched when a WooCommerce order is created via WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Events;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderCreatedEvent
 *
 * Event for new orders originating from WhatsApp conversations.
 */
class OrderCreatedEvent extends Event {

	/**
	 * The WooCommerce order ID.
	 *
	 * @var int
	 */
	public readonly int $order_id;

	/**
	 * The customer phone number.
	 *
	 * @var string
	 */
	public readonly string $customer_phone;

	/**
	 * The order total.
	 *
	 * @var float
	 */
	public readonly float $total;

	/**
	 * The conversation ID.
	 *
	 * @var int|null
	 */
	public readonly ?int $conversation_id;

	/**
	 * Whether this was a recovered cart.
	 *
	 * @var bool
	 */
	public readonly bool $from_recovery;

	/**
	 * Constructor.
	 *
	 * @param int      $order_id        The WooCommerce order ID.
	 * @param string   $customer_phone  The customer phone.
	 * @param float    $total           The order total.
	 * @param int|null $conversation_id The conversation ID.
	 * @param bool     $from_recovery   Whether from cart recovery.
	 */
	public function __construct(
		int $order_id,
		string $customer_phone,
		float $total,
		?int $conversation_id = null,
		bool $from_recovery = false
	) {
		parent::__construct();
		$this->order_id = $order_id;
		$this->customer_phone = $customer_phone;
		$this->total = $total;
		$this->conversation_id = $conversation_id;
		$this->from_recovery = $from_recovery;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'wch.order.created';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPayload(): array {
		return array(
			'order_id'        => $this->order_id,
			'customer_phone'  => $this->customer_phone,
			'total'           => $this->total,
			'conversation_id' => $this->conversation_id,
			'from_recovery'   => $this->from_recovery,
		);
	}
}
