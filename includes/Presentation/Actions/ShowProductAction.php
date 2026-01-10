<?php
/**
 * Show Product Action
 *
 * Displays detailed product information.
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
 * Class ShowProductAction
 *
 * Shows product details including description, images, price, stock, and variants.
 */
class ShowProductAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'show_product';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters with product_id.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			if ( empty( $params['product_id'] ) ) {
				return $this->error( __( 'Product not specified. Please select a product.', 'whatsapp-commerce-hub' ) );
			}

			$productId = (int) $params['product_id'];

			$this->log( 'Showing product', [ 'product_id' => $productId ] );

			// Get product.
			$product = wc_get_product( $productId );

			if ( ! $product || ! $product->is_visible() ) {
				return $this->error( __( 'Product not found. Please try another product.', 'whatsapp-commerce-hub' ) );
			}

			// Build product messages.
			$messages = $this->buildProductMessages( $product );

			return ActionResult::success(
				$messages,
				null,
				[
					'current_product' => $productId,
					'product_name'    => $product->get_name(),
					'product_price'   => $product->get_price(),
					'has_variations'  => $product->is_type( 'variable' ),
				]
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error showing product', [ 'error' => $e->getMessage() ], 'error' );
			return $this->error( __( 'Sorry, we could not load the product. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Build product messages.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array Array of message builders.
	 */
	private function buildProductMessages( \WC_Product $product ): array {
		$messages = [];

		// Product image (if available).
		if ( $product->get_image_id() ) {
			$messages[] = $this->buildImageMessage( $product );
		}

		// Product details.
		$messages[] = $this->buildDetailsMessage( $product );

		// Variant selector (if variable product).
		if ( $product->is_type( 'variable' ) ) {
			$variantMessage = $this->buildVariantSelector( $product );
			if ( $variantMessage ) {
				$messages[] = $variantMessage;
			}
		}

		return $messages;
	}

	/**
	 * Build product image message.
	 *
	 * @param \WC_Product $product Product object.
	 * @return \WCH_Message_Builder
	 */
	private function buildImageMessage( \WC_Product $product ): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		$imageUrl = $this->getProductImageUrl( $product );

		if ( $imageUrl ) {
			$message->text( sprintf( '📷 %s: %s', __( 'Product Image', 'whatsapp-commerce-hub' ), $imageUrl ) );
		}

		return $message;
	}

	/**
	 * Build product details message.
	 *
	 * @param \WC_Product $product Product object.
	 * @return \WCH_Message_Builder
	 */
	private function buildDetailsMessage( \WC_Product $product ): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		// Product name.
		$message->header( $product->get_name() );

		// Build description.
		$description = $this->formatProductDescription( $product );
		$message->body( $description );

		// Price and stock.
		$footer = $this->formatPriceStock( $product );
		$message->footer( $footer );

		// Add to cart button (if simple product).
		if ( $product->is_type( 'simple' ) && $product->is_in_stock() ) {
			$message->button(
				'reply',
				[
					'id'    => 'add_to_cart_' . $product->get_id(),
					'title' => __( 'Add to Cart', 'whatsapp-commerce-hub' ),
				]
			);
		}

		// Back button.
		$message->button(
			'reply',
			[
				'id'    => 'back_to_category',
				'title' => __( 'Back', 'whatsapp-commerce-hub' ),
			]
		);

		return $message;
	}

	/**
	 * Build variant selector message.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return \WCH_Message_Builder|null
	 */
	private function buildVariantSelector( \WC_Product $product ): ?\WCH_Message_Builder {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return null;
		}

		$variations = $product->get_available_variations();

		if ( empty( $variations ) ) {
			return null;
		}

		$message = $this->createMessageBuilder();
		$message->body( __( 'Please select a variant:', 'whatsapp-commerce-hub' ) );

		$rows = [];

		foreach ( $variations as $variation ) {
			$variationObj = wc_get_product( $variation['variation_id'] );

			if ( ! $variationObj ) {
				continue;
			}

			$attributes = [];
			foreach ( $variation['attributes'] as $attrValue ) {
				if ( ! empty( $attrValue ) ) {
					$attributes[] = $attrValue;
				}
			}

			$variantName  = ! empty( $attributes ) ? implode( ', ', $attributes ) : __( 'Variant', 'whatsapp-commerce-hub' );
			$variantPrice = $this->formatPrice( (float) $variationObj->get_price() );
			$inStock      = $variationObj->is_in_stock() ? '✅' : '❌';

			$rows[] = [
				'id'          => 'variant_' . $variation['variation_id'],
				'title'       => wp_trim_words( $variantName, 3, '...' ),
				'description' => sprintf( '%s %s', $variantPrice, $inStock ),
			];

			// Limit to 10 variations per message.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		if ( ! empty( $rows ) ) {
			$message->section( __( 'Available Variants', 'whatsapp-commerce-hub' ), $rows );
		}

		return $message;
	}

	/**
	 * Format product description.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Formatted description.
	 */
	private function formatProductDescription( \WC_Product $product ): string {
		$description = $product->get_short_description();

		if ( empty( $description ) ) {
			$description = $product->get_description();
		}

		// Strip HTML and limit length.
		$description = wp_strip_all_tags( $description );
		$description = wp_trim_words( $description, 100, '...' );

		// Ensure within WhatsApp limits.
		if ( strlen( $description ) > 1024 ) {
			$description = substr( $description, 0, 1020 ) . '...';
		}

		return $description;
	}

	/**
	 * Format price and stock info.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string Formatted string.
	 */
	private function formatPriceStock( \WC_Product $product ): string {
		$price = $this->formatPrice( (float) $product->get_price() );

		if ( $product->is_in_stock() ) {
			if ( $product->managing_stock() ) {
				$qty   = $product->get_stock_quantity();
				$stock = sprintf( __( '✅ %d in stock', 'whatsapp-commerce-hub' ), $qty );
			} else {
				$stock = __( '✅ In Stock', 'whatsapp-commerce-hub' );
			}
		} else {
			$stock = __( '❌ Out of Stock', 'whatsapp-commerce-hub' );
		}

		return sprintf( '%s | %s', $price, $stock );
	}
}
