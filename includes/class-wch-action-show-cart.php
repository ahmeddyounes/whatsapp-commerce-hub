<?php
/**
 * WCH Action: Show Cart
 *
 * Display customer's cart with items and actions.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_ShowCart class
 *
 * Displays cart with:
 * - Itemized list with quantities
 * - Item totals
 * - Cart subtotal
 * - Modify/remove buttons per item
 * - Checkout button
 */
class WCH_Action_ShowCart extends WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			$this->log( 'Showing cart', array( 'phone' => $conversation->customer_phone ) );

			// Get customer cart.
			$cart = $this->get_or_create_cart( $conversation->customer_phone );

			if ( ! $cart ) {
				$this->log( 'Failed to get cart', array(), 'error' );
				return $this->error( 'Failed to access your cart. Please try again.' );
			}

			// Check if cart is empty.
			if ( empty( $cart['items'] ) ) {
				return $this->show_empty_cart();
			}

			// Build cart message.
			$message = $this->build_cart_message( $cart );

			return WCH_Action_Result::success(
				array( $message ),
				null,
				array(
					'cart_id'         => $cart['id'],
					'cart_item_count' => count( $cart['items'] ),
					'cart_total'      => $cart['total'],
				)
			);

		} catch ( Exception $e ) {
			$this->log( 'Error showing cart', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not load your cart. Please try again.' );
		}
	}

	/**
	 * Build cart message
	 *
	 * @param array $cart Cart data.
	 * @return WCH_Message_Builder
	 */
	private function build_cart_message( $cart ) {
		$message = new WCH_Message_Builder();

		$message->header( 'Your Cart' );

		// Build cart items text.
		$cart_text = $this->format_cart_items( $cart['items'] );
		$message->body( $cart_text );

		// Footer with total.
		$total = $this->format_price( $cart['total'] );
		$message->footer( sprintf( 'Total: %s', $total ) );

		// Build item management section.
		$rows = $this->build_item_rows( $cart['items'] );
		if ( ! empty( $rows ) ) {
			$message->section( 'Manage Items', $rows );
		}

		// Action buttons.
		$message->button(
			'reply',
			array(
				'id'    => 'checkout',
				'title' => 'Checkout',
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'continue_shopping',
				'title' => 'Continue Shopping',
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'clear_cart',
				'title' => 'Clear Cart',
			)
		);

		return $message;
	}

	/**
	 * Format cart items as text
	 *
	 * @param array $items Cart items.
	 * @return string Formatted items.
	 */
	private function format_cart_items( $items ) {
		$lines = array();

		foreach ( $items as $index => $item ) {
			$product = wc_get_product( $item['product_id'] );

			if ( ! $product ) {
				continue;
			}

			$product_name = $product->get_name();
			$price        = floatval( $product->get_price() );

			// Handle variants.
			if ( ! empty( $item['variant_id'] ) ) {
				$variation = wc_get_product( $item['variant_id'] );
				if ( $variation ) {
					$product_name = $variation->get_name();
					$price        = floatval( $variation->get_price() );
				}
			}

			$quantity   = intval( $item['quantity'] );
			$item_total = $price * $quantity;

			$lines[] = sprintf(
				'%d. %s\n   Qty: %d × %s = %s',
				$index + 1,
				$product_name,
				$quantity,
				$this->format_price( $price ),
				$this->format_price( $item_total )
			);
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Build item management rows
	 *
	 * @param array $items Cart items.
	 * @return array Rows for interactive list.
	 */
	private function build_item_rows( $items ) {
		$rows = array();

		foreach ( $items as $index => $item ) {
			$product = wc_get_product( $item['product_id'] );

			if ( ! $product ) {
				continue;
			}

			$product_name = $product->get_name();

			// Handle variants.
			if ( ! empty( $item['variant_id'] ) ) {
				$variation = wc_get_product( $item['variant_id'] );
				if ( $variation ) {
					$product_name = $variation->get_name();
				}
			}

			$item_key = $this->get_cart_item_key( $item['product_id'], $item['variant_id'] ?? null );

			$rows[] = array(
				'id'          => 'modify_item_' . $item_key,
				'title'       => wp_trim_words( $product_name, 3, '...' ),
				'description' => sprintf( 'Qty: %d | Edit or Remove', $item['quantity'] ),
			);

			// Limit to 10 items.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		return $rows;
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
	 * Show empty cart message
	 *
	 * @return WCH_Action_Result
	 */
	private function show_empty_cart() {
		$message = new WCH_Message_Builder();

		$text = "Your cart is empty.\n\nWould you like to browse our products?";
		$message->text( $text );

		$message->button(
			'reply',
			array(
				'id'    => 'browse_products',
				'title' => 'Browse Products',
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'main_menu',
				'title' => 'Main Menu',
			)
		);

		return WCH_Action_Result::success( array( $message ) );
	}
}
