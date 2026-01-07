<?php
/**
 * WCH Action: Show Product
 *
 * Display detailed product information.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_ShowProduct class
 *
 * Shows product details including:
 * - Full description
 * - Images (carousel if multiple)
 * - Price and stock status
 * - Variant selector if applicable
 * - Add to Cart button
 */
class WCH_Action_ShowProduct extends WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload with product_id.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			// Validate product_id.
			if ( empty( $payload['product_id'] ) ) {
				return $this->error( 'Product not specified. Please select a product.' );
			}

			$product_id = intval( $payload['product_id'] );

			$this->log( 'Showing product', array( 'product_id' => $product_id ) );

			// Get product.
			$product = wc_get_product( $product_id );

			if ( ! $product || ! $product->is_visible() ) {
				return $this->error( 'Product not found. Please try another product.' );
			}

			// Build product messages.
			$messages = $this->build_product_messages( $product );

			return WCH_Action_Result::success(
				$messages,
				null,
				array(
					'current_product' => $product_id,
					'product_name'    => $product->get_name(),
					'product_price'   => $product->get_price(),
					'has_variations'  => $product->is_type( 'variable' ),
				)
			);

		} catch ( Exception $e ) {
			$this->log( 'Error showing product', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not load the product. Please try again.' );
		}
	}

	/**
	 * Build product messages
	 *
	 * @param WC_Product $product Product object.
	 * @return array Array of WCH_Message_Builder instances.
	 */
	private function build_product_messages( $product ) {
		$messages = array();

		// Product images (if available).
		if ( $product->get_image_id() ) {
			$messages[] = $this->build_image_message( $product );
		}

		// Product details.
		$messages[] = $this->build_details_message( $product );

		// Variant selector (if variable product).
		if ( $product->is_type( 'variable' ) ) {
			$messages[] = $this->build_variant_selector( $product );
		}

		return $messages;
	}

	/**
	 * Build product image message
	 *
	 * @param WC_Product $product Product object.
	 * @return WCH_Message_Builder
	 */
	private function build_image_message( $product ) {
		$message = new WCH_Message_Builder();

		$image_id  = $product->get_image_id();
		$image_url = wp_get_attachment_image_url( $image_id, 'full' );

		if ( $image_url ) {
			// For now, send image URL as text.
			// In production, use WhatsApp media message.
			$message->text( sprintf( '📷 Product Image: %s', $image_url ) );
		}

		return $message;
	}

	/**
	 * Build product details message
	 *
	 * @param WC_Product $product Product object.
	 * @return WCH_Message_Builder
	 */
	private function build_details_message( $product ) {
		$message = new WCH_Message_Builder();

		// Product name.
		$message->header( $product->get_name() );

		// Build description.
		$description = $this->format_product_description( $product );
		$message->body( $description );

		// Price and stock.
		$footer = $this->format_price_stock( $product );
		$message->footer( $footer );

		// Add to cart button (if simple product).
		if ( $product->is_type( 'simple' ) && $product->is_in_stock() ) {
			$message->button(
				'reply',
				array(
					'id'    => 'add_to_cart_' . $product->get_id(),
					'title' => 'Add to Cart',
				)
			);
		}

		// Back button.
		$message->button(
			'reply',
			array(
				'id'    => 'back_to_category',
				'title' => 'Back',
			)
		);

		return $message;
	}

	/**
	 * Build variant selector message
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return WCH_Message_Builder
	 */
	private function build_variant_selector( $product ) {
		$message = new WCH_Message_Builder();

		$message->body( 'Please select a variant:' );

		$variations = $product->get_available_variations();
		$rows       = array();

		foreach ( $variations as $variation ) {
			$variation_obj = wc_get_product( $variation['variation_id'] );

			if ( ! $variation_obj ) {
				continue;
			}

			$attributes = array();
			foreach ( $variation['attributes'] as $attr_name => $attr_value ) {
				$attributes[] = $attr_value;
			}

			$variant_name  = implode( ', ', $attributes );
			$variant_price = $this->format_price( floatval( $variation_obj->get_price() ) );
			$in_stock      = $variation_obj->is_in_stock() ? '✅' : '❌';

			$rows[] = array(
				'id'          => 'variant_' . $variation['variation_id'],
				'title'       => wp_trim_words( $variant_name, 3, '...' ),
				'description' => sprintf( '%s %s', $variant_price, $in_stock ),
			);

			// Limit to 10 variations per message.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		if ( ! empty( $rows ) ) {
			$message->section( 'Available Variants', $rows );
		}

		return $message;
	}

	/**
	 * Format product description
	 *
	 * @param WC_Product $product Product object.
	 * @return string Formatted description.
	 */
	private function format_product_description( $product ) {
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
	 * Format price and stock info
	 *
	 * @param WC_Product $product Product object.
	 * @return string Formatted string.
	 */
	private function format_price_stock( $product ) {
		$price = $this->format_price( floatval( $product->get_price() ) );

		if ( $product->is_in_stock() ) {
			$stock = '✅ In Stock';

			if ( $product->managing_stock() ) {
				$qty   = $product->get_stock_quantity();
				$stock = sprintf( '✅ %d in stock', $qty );
			}
		} else {
			$stock = '❌ Out of Stock';
		}

		return sprintf( '%s | %s', $price, $stock );
	}
}
