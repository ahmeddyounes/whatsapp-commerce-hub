<?php
/**
 * WCH Action: Add to Cart
 *
 * Add products to customer's cart.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_AddToCart class
 *
 * Handles adding products to cart with:
 * - Stock validation
 * - Atomic cart updates
 * - Confirmation message with cart summary
 * - Continue Shopping / Checkout buttons
 */
class WCH_Action_AddToCart extends WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload with product_id, variant_id, quantity.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			// Validate required fields.
			if ( empty( $payload['product_id'] ) ) {
				return $this->error( 'Product not specified. Please try again.' );
			}

			$product_id = intval( $payload['product_id'] );
			$variant_id = ! empty( $payload['variant_id'] ) ? intval( $payload['variant_id'] ) : null;
			$quantity   = ! empty( $payload['quantity'] ) ? intval( $payload['quantity'] ) : 1;

			$this->log(
				'Adding to cart',
				array(
					'product_id' => $product_id,
					'variant_id' => $variant_id,
					'quantity'   => $quantity,
				)
			);

			// Validate product.
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return $this->error( 'Product not found.' );
			}

			// For variable products, variant_id is required.
			if ( $product->is_type( 'variable' ) && ! $variant_id ) {
				return $this->error( 'Please select a variant for this product.' );
			}

			// Validate stock.
			$check_product_id = $variant_id ? $variant_id : $product_id;
			if ( ! $this->has_stock( $product_id, $quantity, $variant_id ) ) {
				return $this->error( 'Sorry, this product is out of stock or the requested quantity is not available.' );
			}

			// Get or create cart.
			$cart = $this->get_or_create_cart( $conversation->customer_phone );

			if ( ! $cart ) {
				$this->log( 'Failed to get/create cart', array(), 'error' );
				return $this->error( 'Failed to access your cart. Please try again.' );
			}

			// Add item to cart (idempotent operation).
			$cart = $this->add_item_to_cart( $cart, $product_id, $variant_id, $quantity );

			// Recalculate total.
			$cart['total'] = $this->calculate_cart_total( $cart['items'] );

			// Update cart in database.
			if ( ! $this->update_cart( $cart['id'], $cart ) ) {
				$this->log( 'Failed to update cart', array( 'cart_id' => $cart['id'] ), 'error' );
				return $this->error( 'Failed to update cart. Please try again.' );
			}

			// Build confirmation message.
			$messages = $this->build_confirmation_messages( $product, $variant_id, $quantity, $cart );

			return WCH_Action_Result::success(
				$messages,
				null,
				array(
					'cart_id'         => $cart['id'],
					'cart_item_count' => count( $cart['items'] ),
					'cart_total'      => $cart['total'],
				)
			);

		} catch ( Exception $e ) {
			$this->log( 'Error adding to cart', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not add the item to your cart. Please try again.' );
		}
	}

	/**
	 * Add item to cart (idempotent)
	 *
	 * @param array $cart Cart data.
	 * @param int   $product_id Product ID.
	 * @param int   $variant_id Variant ID or null.
	 * @param int   $quantity Quantity to add.
	 * @return array Updated cart.
	 */
	private function add_item_to_cart( $cart, $product_id, $variant_id, $quantity ) {
		// Check if item already exists in cart.
		$item_key = $this->get_cart_item_key( $product_id, $variant_id );

		$found = false;
		foreach ( $cart['items'] as &$item ) {
			$existing_key = $this->get_cart_item_key( $item['product_id'], $item['variant_id'] ?? null );

			if ( $existing_key === $item_key ) {
				// Item exists, update quantity.
				$item['quantity'] += $quantity;
				$found             = true;
				break;
			}
		}

		if ( ! $found ) {
			// Add new item.
			$cart['items'][] = array(
				'product_id' => $product_id,
				'variant_id' => $variant_id,
				'quantity'   => $quantity,
				'added_at'   => current_time( 'mysql' ),
			);
		}

		return $cart;
	}

	/**
	 * Generate cart item key
	 *
	 * @param int $product_id Product ID.
	 * @param int $variant_id Variant ID or null.
	 * @return string Item key.
	 */
	private function get_cart_item_key( $product_id, $variant_id ) {
		return $variant_id ? "{$product_id}_{$variant_id}" : (string) $product_id;
	}

	/**
	 * Build confirmation messages
	 *
	 * @param WC_Product $product Product object.
	 * @param int        $variant_id Variant ID or null.
	 * @param int        $quantity Quantity added.
	 * @param array      $cart Updated cart.
	 * @return array Array of WCH_Message_Builder instances.
	 */
	private function build_confirmation_messages( $product, $variant_id, $quantity, $cart ) {
		$messages = array();

		// Confirmation message.
		$confirmation = new WCH_Message_Builder();

		$product_name = $product->get_name();
		if ( $variant_id ) {
			$variation    = wc_get_product( $variant_id );
			$product_name = $variation ? $variation->get_name() : $product_name;
		}

		$text = sprintf(
			"✅ Added to cart!\n\n%s\nQuantity: %d\n\n%s",
			$product_name,
			$quantity,
			$this->format_cart_summary( $cart )
		);

		$confirmation->text( $text );

		// Action buttons.
		$confirmation->button(
			'reply',
			array(
				'id'    => 'continue_shopping',
				'title' => 'Continue Shopping',
			)
		);

		$confirmation->button(
			'reply',
			array(
				'id'    => 'view_cart',
				'title' => 'View Cart',
			)
		);

		$confirmation->button(
			'reply',
			array(
				'id'    => 'checkout',
				'title' => 'Checkout',
			)
		);

		$messages[] = $confirmation;

		return $messages;
	}

	/**
	 * Format cart summary
	 *
	 * @param array $cart Cart data.
	 * @return string Formatted summary.
	 */
	private function format_cart_summary( $cart ) {
		$item_count = count( $cart['items'] );
		$total      = $this->format_price( $cart['total'] );

		return sprintf(
			'Cart Summary:\n%d %s | Total: %s',
			$item_count,
			_n( 'item', 'items', $item_count, 'whatsapp-commerce-hub' ),
			$total
		);
	}
}
