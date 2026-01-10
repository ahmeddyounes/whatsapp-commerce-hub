<?php
/**
 * Show Cart Action
 *
 * Displays customer's cart contents.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShowCartAction
 *
 * Displays cart with itemized list and actions.
 */
class ShowCartAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'show_cart';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			$this->log( 'Showing cart', array( 'phone' => $phone ) );

			$cart = $this->getCart( $phone );

			if ( ! $cart ) {
				return $this->showEmptyCart();
			}

			$items = is_array( $cart ) ? ( $cart['items'] ?? array() ) : ( $cart->items ?? array() );

			if ( empty( $items ) ) {
				return $this->showEmptyCart();
			}

			$cartData = array(
				'id'    => is_array( $cart ) ? ( $cart['id'] ?? null ) : ( $cart->id ?? null ),
				'items' => $items,
				'total' => is_array( $cart ) ? ( $cart['total'] ?? 0 ) : ( $cart->total ?? 0 ),
			);

			$message = $this->buildCartMessage( $cartData );

			return ActionResult::success(
				array( $message ),
				null,
				array(
					'cart_id'         => $cartData['id'],
					'cart_item_count' => count( $cartData['items'] ),
					'cart_total'      => $cartData['total'],
				)
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error showing cart', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( __( 'Sorry, we could not load your cart. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Build cart message.
	 *
	 * @param array $cart Cart data.
	 * @return \WCH_Message_Builder
	 */
	private function buildCartMessage( array $cart ): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		$message->header( __( 'Your Cart', 'whatsapp-commerce-hub' ) );

		// Calculate subtotal from items for consistency check.
		$subtotal = $this->calculateItemsSubtotal( $cart['items'] );
		$total    = (float) $cart['total'];

		// Build cart items text.
		$cartText = $this->formatCartItems( $cart['items'] );

		// SECURITY: Show discount line if there's a difference between subtotal and total.
		// This prevents confusion and makes coupon/discount application visible.
		$discountAmount = $subtotal - $total;
		if ( $discountAmount > 0.01 ) { // Allow for floating point tolerance.
			$cartText .= sprintf(
				"\n\n%s: -%s",
				__( 'Discount', 'whatsapp-commerce-hub' ),
				$this->formatPrice( $discountAmount )
			);
		}

		$message->body( $cartText );

		// Footer with total.
		$message->footer( sprintf( '%s: %s', __( 'Total', 'whatsapp-commerce-hub' ), $this->formatPrice( $total ) ) );

		// Build item management section.
		$rows = $this->buildItemRows( $cart['items'] );
		if ( ! empty( $rows ) ) {
			$message->section( __( 'Manage Items', 'whatsapp-commerce-hub' ), $rows );
		}

		// Action buttons.
		$message->button(
			'reply',
			array(
				'id'    => 'checkout',
				'title' => __( 'Checkout', 'whatsapp-commerce-hub' ),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'continue_shopping',
				'title' => __( 'Continue Shopping', 'whatsapp-commerce-hub' ),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'clear_cart',
				'title' => __( 'Clear Cart', 'whatsapp-commerce-hub' ),
			)
		);

		return $message;
	}

	/**
	 * Calculate subtotal from cart items.
	 *
	 * @param array $items Cart items.
	 * @return float Subtotal.
	 */
	private function calculateItemsSubtotal( array $items ): float {
		$subtotal = 0.0;

		foreach ( $items as $item ) {
			$price = isset( $item['price'] ) ? (float) $item['price'] : null;

			if ( null === $price ) {
				// Fallback to live price if not stored.
				$targetId = ! empty( $item['variant_id'] ) ? $item['variant_id'] : $item['product_id'];
				$product  = wc_get_product( $targetId );
				$price    = $product ? (float) $product->get_price() : 0.0;
			}

			$quantity  = (int) $item['quantity'];
			$subtotal += $price * $quantity;
		}

		return $subtotal;
	}

	/**
	 * Format cart items as text.
	 *
	 * SECURITY: Use cart-stored prices for consistency with cart total.
	 * Using live product prices could show different amounts than the cart total,
	 * confusing customers or creating price manipulation opportunities.
	 *
	 * @param array $items Cart items.
	 * @return string Formatted items.
	 */
	private function formatCartItems( array $items ): string {
		$lines = array();

		foreach ( $items as $index => $item ) {
			$product   = wc_get_product( $item['product_id'] );
			$targetId  = $item['product_id'];

			if ( ! $product ) {
				continue;
			}

			$productName = $product->get_name();

			// Handle variants.
			if ( ! empty( $item['variant_id'] ) ) {
				$variation = wc_get_product( $item['variant_id'] );
				if ( $variation ) {
					$productName = $variation->get_name();
					$targetId    = $item['variant_id'];
				}
			}

			// SECURITY: Use cart-stored price if available for consistency with cart total.
			// Fall back to current product price only if cart doesn't have stored price.
			$price = isset( $item['price'] ) ? (float) $item['price'] : null;

			if ( null === $price ) {
				// Fallback: fetch current price.
				$targetProduct = wc_get_product( $targetId );
				$price         = $targetProduct ? (float) $targetProduct->get_price() : 0.0;

				$this->log(
					'Cart item missing stored price, using live price',
					array(
						'product_id' => $item['product_id'],
						'variant_id' => $item['variant_id'] ?? null,
					),
					'warning'
				);
			}

			$quantity  = (int) $item['quantity'];
			$itemTotal = $price * $quantity;

			$lines[] = sprintf(
				"%d. %s\n   %s: %d × %s = %s",
				$index + 1,
				$productName,
				__( 'Qty', 'whatsapp-commerce-hub' ),
				$quantity,
				$this->formatPrice( $price ),
				$this->formatPrice( $itemTotal )
			);
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Build item management rows.
	 *
	 * @param array $items Cart items.
	 * @return array Rows for interactive list.
	 */
	private function buildItemRows( array $items ): array {
		$rows = array();

		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );

			if ( ! $product ) {
				continue;
			}

			$productName = $product->get_name();

			if ( ! empty( $item['variant_id'] ) ) {
				$variation = wc_get_product( $item['variant_id'] );
				if ( $variation ) {
					$productName = $variation->get_name();
				}
			}

			$itemKey = $this->getCartItemKey( $item['product_id'], $item['variant_id'] ?? null );

			$rows[] = array(
				'id'          => 'modify_item_' . $itemKey,
				'title'       => wp_trim_words( $productName, 3, '...' ),
				'description' => sprintf(
					'%s: %d | %s',
					__( 'Qty', 'whatsapp-commerce-hub' ),
					$item['quantity'],
					__( 'Edit or Remove', 'whatsapp-commerce-hub' )
				),
			);

			// Limit to 10 items.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Generate cart item key.
	 *
	 * @param int      $productId Product ID.
	 * @param int|null $variantId Variant ID or null.
	 * @return string Item key.
	 */
	private function getCartItemKey( int $productId, ?int $variantId ): string {
		return $variantId ? "{$productId}_{$variantId}" : (string) $productId;
	}

	/**
	 * Show empty cart message.
	 *
	 * @return ActionResult
	 */
	private function showEmptyCart(): ActionResult {
		$message = $this->createMessageBuilder();

		$text = sprintf(
			"%s\n\n%s",
			__( 'Your cart is empty.', 'whatsapp-commerce-hub' ),
			__( 'Would you like to browse our products?', 'whatsapp-commerce-hub' )
		);

		$message->text( $text );

		$message->button(
			'reply',
			array(
				'id'    => 'browse_products',
				'title' => __( 'Browse Products', 'whatsapp-commerce-hub' ),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'main_menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			)
		);

		return ActionResult::success( array( $message ) );
	}
}
