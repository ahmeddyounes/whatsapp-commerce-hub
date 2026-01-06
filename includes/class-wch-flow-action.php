<?php
/**
 * WCH Flow Action Abstract Base Class
 *
 * Base class for all flow action handlers.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Flow_Action abstract class
 *
 * Defines the interface for action handlers that execute during state transitions.
 * All concrete actions must extend this class and implement the execute method.
 */
abstract class WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * This method must be implemented by all concrete action handlers.
	 * It receives the conversation context, current context data, and event payload,
	 * and returns a WCH_Action_Result containing response messages and updates.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @param array                    $context Action-specific context data.
	 * @param array                    $payload Event payload data.
	 * @return WCH_Action_Result Result containing success, messages, and updates.
	 */
	abstract public function execute( $conversation, $context, $payload );

	/**
	 * Helper: Create error result with message
	 *
	 * @param string $error_message Error message to display.
	 * @param string $next_state Optional state to transition to on error.
	 * @return WCH_Action_Result
	 */
	protected function error( $error_message, $next_state = null ) {
		$message = ( new WCH_Message_Builder() )->text( $error_message );

		return WCH_Action_Result::failure(
			array( $message ),
			$next_state
		);
	}

	/**
	 * Helper: Log action execution
	 *
	 * @param string $message Log message.
	 * @param array  $data Additional data to log.
	 * @param string $level Log level (info, warning, error).
	 */
	protected function log( $message, $data = array(), $level = 'info' ) {
		WCH_Logger::log(
			get_class( $this ) . ': ' . $message,
			$data,
			$level
		);
	}

	/**
	 * Helper: Get customer profile
	 *
	 * @param string $phone Customer phone number.
	 * @return WCH_Customer_Profile|null
	 */
	protected function get_customer_profile( $phone ) {
		$customer_service = WCH_Customer_Service::instance();
		return $customer_service->get_or_create_profile( $phone );
	}

	/**
	 * Helper: Get or create cart for customer
	 *
	 * @param string $phone Customer phone number.
	 * @return array|null Cart data or null on error.
	 */
	protected function get_or_create_cart( $phone ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		// Try to get existing active cart.
		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE customer_phone = %s AND status = 'active'",
				$phone
			),
			ARRAY_A
		);

		if ( $cart ) {
			// Decode JSON items.
			if ( ! empty( $cart['items'] ) ) {
				$cart['items'] = json_decode( $cart['items'], true );
			} else {
				$cart['items'] = array();
			}
			if ( ! empty( $cart['shipping_address'] ) ) {
				$cart['shipping_address'] = json_decode( $cart['shipping_address'], true );
			}
			return $cart;
		}

		// Create new cart.
		$now = current_time( 'mysql' );
		$expires_at = date( 'Y-m-d H:i:s', strtotime( '+7 days' ) );

		$wpdb->insert(
			$table_name,
			array(
				'customer_phone'   => $phone,
				'items'            => wp_json_encode( array() ),
				'total'            => 0.00,
				'status'           => 'active',
				'expires_at'       => $expires_at,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
		);

		if ( $wpdb->insert_id ) {
			return array(
				'id'               => $wpdb->insert_id,
				'customer_phone'   => $phone,
				'items'            => array(),
				'total'            => 0.00,
				'status'           => 'active',
				'expires_at'       => $expires_at,
				'created_at'       => $now,
				'updated_at'       => $now,
			);
		}

		return null;
	}

	/**
	 * Helper: Update cart in database
	 *
	 * @param int   $cart_id Cart ID.
	 * @param array $cart_data Cart data to update.
	 * @return bool Success status.
	 */
	protected function update_cart( $cart_id, $cart_data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		$update_data = array(
			'updated_at' => current_time( 'mysql' ),
		);

		if ( isset( $cart_data['items'] ) ) {
			$update_data['items'] = wp_json_encode( $cart_data['items'] );
		}

		if ( isset( $cart_data['total'] ) ) {
			$update_data['total'] = $cart_data['total'];
		}

		if ( isset( $cart_data['coupon_code'] ) ) {
			$update_data['coupon_code'] = $cart_data['coupon_code'];
		}

		if ( isset( $cart_data['shipping_address'] ) ) {
			$update_data['shipping_address'] = wp_json_encode( $cart_data['shipping_address'] );
		}

		if ( isset( $cart_data['status'] ) ) {
			$update_data['status'] = $cart_data['status'];
		}

		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'id' => $cart_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Helper: Calculate cart total
	 *
	 * @param array $items Cart items.
	 * @return float Total price.
	 */
	protected function calculate_cart_total( $items ) {
		$total = 0.00;

		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			if ( ! empty( $item['variant_id'] ) ) {
				$variation = wc_get_product( $item['variant_id'] );
				if ( $variation ) {
					$total += floatval( $variation->get_price() ) * intval( $item['quantity'] );
				}
			} else {
				$total += floatval( $product->get_price() ) * intval( $item['quantity'] );
			}
		}

		return round( $total, 2 );
	}

	/**
	 * Helper: Format price for display
	 *
	 * @param float $price Price to format.
	 * @return string Formatted price.
	 */
	protected function format_price( $price ) {
		return wc_price( $price );
	}

	/**
	 * Helper: Check if product has stock
	 *
	 * @param int $product_id Product ID.
	 * @param int $quantity Quantity to check.
	 * @param int $variant_id Optional variation ID.
	 * @return bool Whether stock is available.
	 */
	protected function has_stock( $product_id, $quantity = 1, $variant_id = null ) {
		$product = wc_get_product( $variant_id ? $variant_id : $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( ! $product->managing_stock() ) {
			return true;
		}

		return $product->get_stock_quantity() >= $quantity;
	}

	/**
	 * Helper: Get product image URL
	 *
	 * @param WC_Product $product Product object.
	 * @return string|null Image URL or null.
	 */
	protected function get_product_image_url( $product ) {
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$image_url = wp_get_attachment_image_url( $image_id, 'full' );
			return $image_url ?: null;
		}
		return null;
	}
}
