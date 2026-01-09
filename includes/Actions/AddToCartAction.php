<?php
/**
 * Add to Cart Action
 *
 * Handles adding products to customer's cart.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AddToCartAction
 *
 * Adds products to cart with stock validation.
 */
class AddToCartAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'add_to_cart';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters with product_id, variant_id, quantity.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			// Validate required fields.
			if ( empty( $params['product_id'] ) ) {
				return $this->error( __( 'Product not specified. Please try again.', 'whatsapp-commerce-hub' ) );
			}

			$productId = (int) $params['product_id'];
			$variantId = ! empty( $params['variant_id'] ) ? (int) $params['variant_id'] : null;
			$quantity  = ! empty( $params['quantity'] ) ? (int) $params['quantity'] : 1;

			$this->log( 'Adding to cart', array(
				'product_id' => $productId,
				'variant_id' => $variantId,
				'quantity'   => $quantity,
			) );

			// Validate product.
			$product = wc_get_product( $productId );
			if ( ! $product ) {
				return $this->error( __( 'Product not found.', 'whatsapp-commerce-hub' ) );
			}

			// For variable products, variant_id is required.
			if ( $product->is_type( 'variable' ) && ! $variantId ) {
				return $this->error( __( 'Please select a variant for this product.', 'whatsapp-commerce-hub' ) );
			}

			// Validate stock.
			if ( ! $this->hasStock( $productId, $quantity, $variantId ) ) {
				return $this->error( __( 'Sorry, this product is out of stock or the requested quantity is not available.', 'whatsapp-commerce-hub' ) );
			}

			// Add to cart via service.
			$result = $this->addToCart( $phone, $productId, $variantId, $quantity );

			if ( ! $result['success'] ) {
				return $this->error( $result['error'] ?? __( 'Failed to add item to cart.', 'whatsapp-commerce-hub' ) );
			}

			// Build confirmation message.
			$messages = $this->buildConfirmationMessages( $product, $variantId, $quantity, $result['cart'] );

			return ActionResult::success(
				$messages,
				null,
				array(
					'cart_id'         => $result['cart']['id'] ?? null,
					'cart_item_count' => count( $result['cart']['items'] ?? array() ),
					'cart_total'      => $result['cart']['total'] ?? 0,
				)
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error adding to cart', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( __( 'Sorry, we could not add the item to your cart. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Add item to cart.
	 *
	 * @param string   $phone     Customer phone.
	 * @param int      $productId Product ID.
	 * @param int|null $variantId Variant ID.
	 * @param int      $quantity  Quantity.
	 * @return array{success: bool, cart: array|null, error: string|null}
	 */
	private function addToCart( string $phone, int $productId, ?int $variantId, int $quantity ): array {
		if ( $this->cartService ) {
			$result = $this->cartService->addItem( $phone, $productId, $variantId, $quantity );

			if ( $result ) {
				$cart = $this->cartService->getCart( $phone );
				return array(
					'success' => true,
					'cart'    => array(
						'id'    => $cart->id ?? null,
						'items' => $cart->items ?? array(),
						'total' => $cart->total ?? 0,
					),
					'error'   => null,
				);
			}

			return array( 'success' => false, 'cart' => null, 'error' => __( 'Failed to add item to cart.', 'whatsapp-commerce-hub' ) );
		}

		// Fallback to legacy cart manager.
		$cartManager = \WCH_Cart_Manager::instance();
		$cart        = $cartManager->get_or_create_cart( $phone );

		if ( ! $cart ) {
			return array( 'success' => false, 'cart' => null, 'error' => __( 'Failed to access cart.', 'whatsapp-commerce-hub' ) );
		}

		$result = $cartManager->add_item( $phone, $productId, $quantity, $variantId );

		if ( $result ) {
			$updatedCart = $cartManager->get_cart( $phone );
			return array(
				'success' => true,
				'cart'    => array(
					'id'    => $updatedCart->id ?? $cart['id'] ?? null,
					'items' => $updatedCart->items ?? $cart['items'] ?? array(),
					'total' => $updatedCart->total ?? $this->calculateCartTotal( $cart['items'] ?? array() ),
				),
				'error'   => null,
			);
		}

		return array( 'success' => false, 'cart' => null, 'error' => __( 'Failed to add item.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Build confirmation messages.
	 *
	 * @param \WC_Product $product   Product object.
	 * @param int|null    $variantId Variant ID or null.
	 * @param int         $quantity  Quantity added.
	 * @param array       $cart      Updated cart.
	 * @return array Array of message builders.
	 */
	private function buildConfirmationMessages( \WC_Product $product, ?int $variantId, int $quantity, array $cart ): array {
		$messages = array();

		$confirmation = $this->createMessageBuilder();

		$productName = $product->get_name();
		if ( $variantId ) {
			$variation = wc_get_product( $variantId );
			$productName = $variation ? $variation->get_name() : $productName;
		}

		$text = sprintf(
			"%s %s\n\n%s\n%s: %d\n\n%s",
			'✅',
			__( 'Added to cart!', 'whatsapp-commerce-hub' ),
			$productName,
			__( 'Quantity', 'whatsapp-commerce-hub' ),
			$quantity,
			$this->formatCartSummary( $cart )
		);

		$confirmation->text( $text );

		$confirmation->button(
			'reply',
			array(
				'id'    => 'continue_shopping',
				'title' => __( 'Continue Shopping', 'whatsapp-commerce-hub' ),
			)
		);

		$confirmation->button(
			'reply',
			array(
				'id'    => 'view_cart',
				'title' => __( 'View Cart', 'whatsapp-commerce-hub' ),
			)
		);

		$confirmation->button(
			'reply',
			array(
				'id'    => 'checkout',
				'title' => __( 'Checkout', 'whatsapp-commerce-hub' ),
			)
		);

		$messages[] = $confirmation;

		return $messages;
	}

	/**
	 * Format cart summary.
	 *
	 * @param array $cart Cart data.
	 * @return string Formatted summary.
	 */
	private function formatCartSummary( array $cart ): string {
		$itemCount = count( $cart['items'] ?? array() );
		$total     = $this->formatPrice( (float) ( $cart['total'] ?? 0 ) );

		return sprintf(
			"%s:\n%d %s | %s: %s",
			__( 'Cart Summary', 'whatsapp-commerce-hub' ),
			$itemCount,
			_n( 'item', 'items', $itemCount, 'whatsapp-commerce-hub' ),
			__( 'Total', 'whatsapp-commerce-hub' ),
			$total
		);
	}
}
