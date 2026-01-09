<?php
/**
 * Cart Recovered Event
 *
 * Dispatched when an abandoned cart is converted to an order.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Events;

use WhatsAppCommerceHub\Entities\Cart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartRecoveredEvent
 *
 * Event for recovered shopping carts.
 */
class CartRecoveredEvent extends Event {

	/**
	 * The cart entity.
	 *
	 * @var Cart
	 */
	public readonly Cart $cart;

	/**
	 * The WooCommerce order ID.
	 *
	 * @var int
	 */
	public readonly int $order_id;

	/**
	 * Constructor.
	 *
	 * @param Cart $cart     The recovered cart.
	 * @param int  $order_id The resulting order ID.
	 */
	public function __construct( Cart $cart, int $order_id ) {
		parent::__construct();
		$this->cart = $cart;
		$this->order_id = $order_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'wch.cart.recovered';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPayload(): array {
		return array(
			'cart_id'         => $this->cart->id,
			'order_id'        => $this->order_id,
			'customer_phone'  => $this->cart->customer_phone,
			'total'           => $this->cart->total,
			'reminders_sent'  => $this->cart->getRemindersSent(),
		);
	}
}
