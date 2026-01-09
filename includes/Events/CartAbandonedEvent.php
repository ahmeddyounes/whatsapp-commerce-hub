<?php
/**
 * Cart Abandoned Event
 *
 * Dispatched when a cart is marked as abandoned.
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
 * Class CartAbandonedEvent
 *
 * Event for abandoned shopping carts.
 */
class CartAbandonedEvent extends Event {

	/**
	 * The cart entity.
	 *
	 * @var Cart
	 */
	public readonly Cart $cart;

	/**
	 * Constructor.
	 *
	 * @param Cart $cart The abandoned cart.
	 */
	public function __construct( Cart $cart ) {
		parent::__construct();
		$this->cart = $cart;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'wch.cart.abandoned';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPayload(): array {
		return array(
			'cart_id'        => $this->cart->id,
			'customer_phone' => $this->cart->customer_phone,
			'total'          => $this->cart->total,
			'item_count'     => $this->cart->getItemCount(),
			'created_at'     => $this->cart->created_at->format( 'c' ),
		);
	}
}
