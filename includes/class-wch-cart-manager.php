<?php
/**
 * Cart Manager Class
 *
 * Manages shopping cart operations for WhatsApp customers.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Cart_Manager
 *
 * Handles cart CRUD operations, coupon application, and totals calculation.
 */
class WCH_Cart_Manager {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Cart_Manager
	 */
	private static $instance = null;

	/**
	 * Global wpdb instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Table name for carts.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Cart expiry time in hours.
	 *
	 * @var int
	 */
	const CART_EXPIRY_HOURS = 72;

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Cart_Manager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table_name = $wpdb->prefix . 'wch_carts';
	}

	/**
	 * Get cart for customer.
	 *
	 * Returns existing cart or creates new empty cart with 72-hour expiry.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Cart data.
	 */
	public function get_cart( $phone ) {
		// Try to get existing active cart.
		$cart = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE customer_phone = %s AND status = 'active'",
				$phone
			),
			ARRAY_A
		);

		if ( $cart ) {
			// Decode JSON fields.
			$cart['items'] = ! empty( $cart['items'] ) ? json_decode( $cart['items'], true ) : array();
			$cart['shipping_address'] = ! empty( $cart['shipping_address'] ) ? json_decode( $cart['shipping_address'], true ) : null;
			return $cart;
		}

		// Create new cart with 72-hour expiry.
		$now = current_time( 'mysql' );
		$expires_at = date( 'Y-m-d H:i:s', strtotime( '+' . self::CART_EXPIRY_HOURS . ' hours' ) );

		$this->wpdb->insert(
			$this->table_name,
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

		return array(
			'id'               => $this->wpdb->insert_id,
			'customer_phone'   => $phone,
			'items'            => array(),
			'total'            => 0.00,
			'coupon_code'      => null,
			'shipping_address' => null,
			'status'           => 'active',
			'expires_at'       => $expires_at,
			'created_at'       => $now,
			'updated_at'       => $now,
		);
	}

	/**
	 * Add item to cart.
	 *
	 * Validates product exists and has stock. If item already in cart, increments quantity.
	 *
	 * @param string   $phone        Customer phone number.
	 * @param int      $product_id   Product ID.
	 * @param int|null $variation_id Variation ID (nullable).
	 * @param int      $quantity     Quantity to add.
	 * @return array Updated cart.
	 * @throws WCH_Cart_Exception If validation fails.
	 */
	public function add_item( $phone, $product_id, $variation_id, $quantity ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		// Validate product.
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			throw new WCH_Cart_Exception(
				'Product not found',
				'product_not_found',
				404,
				array( 'product_id' => $product_id, 'variation_id' => $variation_id )
			);
		}

		if ( ! $product->is_in_stock() ) {
			throw new WCH_Cart_Exception(
				'Product is out of stock',
				'out_of_stock',
				400,
				array( 'product_id' => $product_id, 'variation_id' => $variation_id )
			);
		}

		// Check stock quantity.
		if ( $product->managing_stock() ) {
			$existing_qty = 0;

			// Check if item already in cart.
			foreach ( $cart['items'] as $item ) {
				if ( $item['product_id'] == $product_id && ( $item['variation_id'] ?? null ) == $variation_id ) {
					$existing_qty = $item['quantity'];
					break;
				}
			}

			$total_qty = $existing_qty + $quantity;
			if ( $product->get_stock_quantity() < $total_qty ) {
				throw new WCH_Cart_Exception(
					sprintf( 'Only %d items available in stock', $product->get_stock_quantity() ),
					'insufficient_stock',
					400,
					array(
						'product_id'    => $product_id,
						'variation_id'  => $variation_id,
						'available'     => $product->get_stock_quantity(),
						'requested'     => $total_qty,
					)
				);
			}
		}

		// Get product details.
		$product_name = $product->get_name();
		$price = floatval( $product->get_price() );
		$variant_attributes = null;

		if ( $variation_id && $product->is_type( 'variation' ) ) {
			$variant_attributes = $product->get_variation_attributes();
		}

		// Check if item already exists in cart.
		$item_exists = false;
		foreach ( $cart['items'] as &$item ) {
			if ( $item['product_id'] == $product_id && ( $item['variation_id'] ?? null ) == $variation_id ) {
				$item['quantity'] += $quantity;
				$item_exists = true;
				break;
			}
		}
		unset( $item );

		// Add new item if not exists.
		if ( ! $item_exists ) {
			$cart['items'][] = array(
				'product_id'         => $product_id,
				'variation_id'       => $variation_id,
				'quantity'           => $quantity,
				'price_at_add'       => $price,
				'product_name'       => $product_name,
				'variant_attributes' => $variant_attributes,
			);
		}

		// Recalculate total.
		$cart['total'] = $this->calculate_cart_total( $cart['items'] );

		// Update expiry.
		$cart['expires_at'] = date( 'Y-m-d H:i:s', strtotime( '+' . self::CART_EXPIRY_HOURS . ' hours' ) );

		// Save cart.
		$this->save_cart( $cart );

		return $cart;
	}

	/**
	 * Update item quantity in cart.
	 *
	 * Validates stock availability. Removes item if quantity is 0.
	 *
	 * @param string $phone      Customer phone number.
	 * @param int    $item_index Item index in cart.
	 * @param int    $new_quantity New quantity (0 to remove).
	 * @return array Updated cart.
	 * @throws WCH_Cart_Exception If validation fails.
	 */
	public function update_quantity( $phone, $item_index, $new_quantity ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		// Validate item index.
		if ( ! isset( $cart['items'][ $item_index ] ) ) {
			throw new WCH_Cart_Exception(
				'Item not found in cart',
				'item_not_found',
				404,
				array( 'item_index' => $item_index )
			);
		}

		// If quantity is 0, remove item.
		if ( $new_quantity <= 0 ) {
			return $this->remove_item( $phone, $item_index );
		}

		$item = $cart['items'][ $item_index ];

		// Validate stock.
		$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
		if ( ! $product ) {
			throw new WCH_Cart_Exception(
				'Product no longer exists',
				'product_not_found',
				404,
				array( 'product_id' => $item['product_id'], 'variation_id' => $item['variation_id'] ?? null )
			);
		}

		if ( ! $product->is_in_stock() ) {
			throw new WCH_Cart_Exception(
				'Product is out of stock',
				'out_of_stock',
				400,
				array( 'product_id' => $item['product_id'], 'variation_id' => $item['variation_id'] ?? null )
			);
		}

		if ( $product->managing_stock() ) {
			if ( $product->get_stock_quantity() < $new_quantity ) {
				throw new WCH_Cart_Exception(
					sprintf( 'Only %d items available in stock', $product->get_stock_quantity() ),
					'insufficient_stock',
					400,
					array(
						'product_id'    => $item['product_id'],
						'variation_id'  => $item['variation_id'] ?? null,
						'available'     => $product->get_stock_quantity(),
						'requested'     => $new_quantity,
					)
				);
			}
		}

		// Update quantity.
		$cart['items'][ $item_index ]['quantity'] = $new_quantity;

		// Recalculate total.
		$cart['total'] = $this->calculate_cart_total( $cart['items'] );

		// Save cart.
		$this->save_cart( $cart );

		return $cart;
	}

	/**
	 * Remove item from cart.
	 *
	 * @param string $phone      Customer phone number.
	 * @param int    $item_index Item index in cart.
	 * @return array Updated cart.
	 * @throws WCH_Cart_Exception If item not found.
	 */
	public function remove_item( $phone, $item_index ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		// Validate item index.
		if ( ! isset( $cart['items'][ $item_index ] ) ) {
			throw new WCH_Cart_Exception(
				'Item not found in cart',
				'item_not_found',
				404,
				array( 'item_index' => $item_index )
			);
		}

		// Remove item.
		array_splice( $cart['items'], $item_index, 1 );

		// Recalculate total.
		$cart['total'] = $this->calculate_cart_total( $cart['items'] );

		// Save cart.
		$this->save_cart( $cart );

		return $cart;
	}

	/**
	 * Clear all items from cart.
	 *
	 * Empties cart items but retains customer association.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Updated cart.
	 */
	public function clear_cart( $phone ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		// Clear items.
		$cart['items'] = array();
		$cart['total'] = 0.00;
		$cart['coupon_code'] = null;

		// Save cart.
		$this->save_cart( $cart );

		return $cart;
	}

	/**
	 * Apply coupon to cart.
	 *
	 * Validates WC coupon and checks restrictions.
	 *
	 * @param string $phone       Customer phone number.
	 * @param string $coupon_code Coupon code.
	 * @return array Array with discount amount and updated cart.
	 * @throws WCH_Cart_Exception If coupon is invalid.
	 */
	public function apply_coupon( $phone, $coupon_code ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		// Validate coupon exists.
		$coupon = new WC_Coupon( $coupon_code );
		if ( ! $coupon->is_valid() ) {
			throw new WCH_Cart_Exception(
				'Invalid coupon code',
				'invalid_coupon',
				400,
				array( 'coupon_code' => $coupon_code )
			);
		}

		// Check if coupon is expired.
		$expiry_date = $coupon->get_date_expires();
		if ( $expiry_date && $expiry_date->getTimestamp() < time() ) {
			throw new WCH_Cart_Exception(
				'Coupon has expired',
				'coupon_expired',
				400,
				array( 'coupon_code' => $coupon_code )
			);
		}

		// Check usage limit.
		$usage_limit = $coupon->get_usage_limit();
		if ( $usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit ) {
			throw new WCH_Cart_Exception(
				'Coupon usage limit reached',
				'coupon_usage_limit',
				400,
				array( 'coupon_code' => $coupon_code )
			);
		}

		// Calculate cart subtotal.
		$subtotal = $this->calculate_cart_total( $cart['items'] );

		// Check minimum amount.
		$min_amount = $coupon->get_minimum_amount();
		if ( $min_amount > 0 && $subtotal < $min_amount ) {
			throw new WCH_Cart_Exception(
				sprintf( 'Minimum cart total of %s required', wc_price( $min_amount ) ),
				'coupon_min_amount',
				400,
				array(
					'coupon_code' => $coupon_code,
					'min_amount'  => $min_amount,
					'cart_total'  => $subtotal,
				)
			);
		}

		// Check maximum amount.
		$max_amount = $coupon->get_maximum_amount();
		if ( $max_amount > 0 && $subtotal > $max_amount ) {
			throw new WCH_Cart_Exception(
				sprintf( 'Maximum cart total of %s exceeded', wc_price( $max_amount ) ),
				'coupon_max_amount',
				400,
				array(
					'coupon_code' => $coupon_code,
					'max_amount'  => $max_amount,
					'cart_total'  => $subtotal,
				)
			);
		}

		// Check product restrictions.
		$product_ids = $coupon->get_product_ids();
		$excluded_product_ids = $coupon->get_excluded_product_ids();

		if ( ! empty( $product_ids ) || ! empty( $excluded_product_ids ) ) {
			$cart_product_ids = array_map( function( $item ) {
				return $item['product_id'];
			}, $cart['items'] );

			// If specific products are required.
			if ( ! empty( $product_ids ) ) {
				$has_valid_product = false;
				foreach ( $cart_product_ids as $pid ) {
					if ( in_array( $pid, $product_ids ) ) {
						$has_valid_product = true;
						break;
					}
				}
				if ( ! $has_valid_product ) {
					throw new WCH_Cart_Exception(
						'Coupon does not apply to items in your cart',
						'coupon_product_restriction',
						400,
						array( 'coupon_code' => $coupon_code )
					);
				}
			}

			// If products are excluded.
			if ( ! empty( $excluded_product_ids ) ) {
				foreach ( $cart_product_ids as $pid ) {
					if ( in_array( $pid, $excluded_product_ids ) ) {
						throw new WCH_Cart_Exception(
							'Coupon cannot be applied to some items in your cart',
							'coupon_excluded_products',
							400,
							array( 'coupon_code' => $coupon_code )
						);
					}
				}
			}
		}

		// Calculate discount.
		$discount = 0.00;
		if ( $coupon->is_type( 'percent' ) ) {
			$discount = ( $subtotal * $coupon->get_amount() ) / 100;
		} elseif ( $coupon->is_type( 'fixed_cart' ) ) {
			$discount = min( $coupon->get_amount(), $subtotal );
		}

		// Store coupon in cart.
		$cart['coupon_code'] = $coupon_code;

		// Save cart.
		$this->save_cart( $cart );

		return array(
			'discount' => round( $discount, 2 ),
			'cart'     => $cart,
		);
	}

	/**
	 * Remove coupon from cart.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Updated cart.
	 */
	public function remove_coupon( $phone ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		// Clear coupon.
		$cart['coupon_code'] = null;

		// Save cart.
		$this->save_cart( $cart );

		return $cart;
	}

	/**
	 * Calculate cart totals.
	 *
	 * Returns subtotal, discount, tax, shipping estimate, and total.
	 *
	 * @param array $cart Cart data.
	 * @return array Totals breakdown.
	 */
	public function calculate_totals( $cart ) {
		// Calculate subtotal.
		$subtotal = $this->calculate_cart_total( $cart['items'] );

		// Calculate discount from coupon.
		$discount = 0.00;
		if ( ! empty( $cart['coupon_code'] ) ) {
			$coupon = new WC_Coupon( $cart['coupon_code'] );
			if ( $coupon->is_valid() ) {
				if ( $coupon->is_type( 'percent' ) ) {
					$discount = ( $subtotal * $coupon->get_amount() ) / 100;
				} elseif ( $coupon->is_type( 'fixed_cart' ) ) {
					$discount = min( $coupon->get_amount(), $subtotal );
				}
			}
		}

		// Calculate amount after discount.
		$amount_after_discount = max( 0, $subtotal - $discount );

		// Calculate tax (simplified - using default tax rate).
		$tax = 0.00;
		if ( wc_tax_enabled() ) {
			$tax_rates = WC_Tax::get_base_tax_rates();
			if ( ! empty( $tax_rates ) ) {
				$tax_rate = reset( $tax_rates );
				$tax = ( $amount_after_discount * $tax_rate['rate'] ) / 100;
			}
		}

		// Get shipping estimate (simplified - using flat rate or free shipping).
		$shipping_estimate = 0.00;
		$shipping_zones = WC_Shipping_Zones::get_zones();
		if ( ! empty( $shipping_zones ) ) {
			$zone = reset( $shipping_zones );
			if ( isset( $zone['shipping_methods'] ) ) {
				foreach ( $zone['shipping_methods'] as $method ) {
					if ( $method->enabled === 'yes' ) {
						if ( $method->id === 'flat_rate' && isset( $method->cost ) ) {
							$shipping_estimate = floatval( $method->cost );
							break;
						} elseif ( $method->id === 'free_shipping' ) {
							$shipping_estimate = 0.00;
							break;
						}
					}
				}
			}
		}

		// Calculate total.
		$total = $amount_after_discount + $tax + $shipping_estimate;

		return array(
			'subtotal'          => round( $subtotal, 2 ),
			'discount'          => round( $discount, 2 ),
			'tax'               => round( $tax, 2 ),
			'shipping_estimate' => round( $shipping_estimate, 2 ),
			'total'             => round( $total, 2 ),
		);
	}

	/**
	 * Get cart summary as formatted message.
	 *
	 * Builds itemized list with quantities, totals, discount, and shipping.
	 *
	 * @param string $phone Customer phone number.
	 * @return string Formatted cart summary.
	 */
	public function get_cart_summary_message( $phone ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		if ( empty( $cart['items'] ) ) {
			return "Your cart is empty.\n\nStart shopping to add items!";
		}

		// Build message.
		$message = "🛒 *Your Cart*\n\n";

		// Add items.
		foreach ( $cart['items'] as $index => $item ) {
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$product_name = $product->get_name();
			$price = floatval( $product->get_price() );
			$quantity = $item['quantity'];
			$line_total = $price * $quantity;

			$message .= sprintf(
				"%d. *%s*\n   Qty: %d × %s = *%s*\n\n",
				$index + 1,
				$product_name,
				$quantity,
				wc_price( $price ),
				wc_price( $line_total )
			);
		}

		// Calculate totals.
		$totals = $this->calculate_totals( $cart );

		// Add subtotal.
		$message .= "━━━━━━━━━━━━━━━━━━━━\n";
		$message .= sprintf( "Subtotal: *%s*\n", wc_price( $totals['subtotal'] ) );

		// Add discount if applicable.
		if ( $totals['discount'] > 0 ) {
			$message .= sprintf(
				"Discount (%s): -*%s*\n",
				$cart['coupon_code'],
				wc_price( $totals['discount'] )
			);
		}

		// Add tax.
		if ( $totals['tax'] > 0 ) {
			$message .= sprintf( "Tax: *%s*\n", wc_price( $totals['tax'] ) );
		}

		// Add shipping estimate.
		if ( $totals['shipping_estimate'] > 0 ) {
			$message .= sprintf( "Shipping (est.): *%s*\n", wc_price( $totals['shipping_estimate'] ) );
		} else {
			$message .= "Shipping: *Free*\n";
		}

		// Add total.
		$message .= "━━━━━━━━━━━━━━━━━━━━\n";
		$message .= sprintf( "*Total: %s*", wc_price( $totals['total'] ) );

		return $message;
	}

	/**
	 * Check cart validity.
	 *
	 * Verifies all items still exist, in stock, and prices unchanged.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Validation result with issues array.
	 */
	public function check_cart_validity( $phone ) {
		// Get cart.
		$cart = $this->get_cart( $phone );

		$issues = array();
		$is_valid = true;

		foreach ( $cart['items'] as $index => $item ) {
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );

			// Check if product exists.
			if ( ! $product ) {
				$issues[] = array(
					'item_index' => $index,
					'product_id' => $item['product_id'],
					'issue'      => 'product_not_found',
					'message'    => sprintf( '%s is no longer available', $item['product_name'] ),
				);
				$is_valid = false;
				continue;
			}

			// Check stock status.
			if ( ! $product->is_in_stock() ) {
				$issues[] = array(
					'item_index' => $index,
					'product_id' => $item['product_id'],
					'issue'      => 'out_of_stock',
					'message'    => sprintf( '%s is out of stock', $product->get_name() ),
				);
				$is_valid = false;
				continue;
			}

			// Check stock quantity.
			if ( $product->managing_stock() ) {
				if ( $product->get_stock_quantity() < $item['quantity'] ) {
					$issues[] = array(
						'item_index' => $index,
						'product_id' => $item['product_id'],
						'issue'      => 'insufficient_stock',
						'message'    => sprintf(
							'Only %d of %s available (you have %d in cart)',
							$product->get_stock_quantity(),
							$product->get_name(),
							$item['quantity']
						),
					);
					$is_valid = false;
					continue;
				}
			}

			// Check price changes.
			$current_price = floatval( $product->get_price() );
			if ( abs( $current_price - $item['price_at_add'] ) > 0.01 ) {
				$issues[] = array(
					'item_index' => $index,
					'product_id' => $item['product_id'],
					'issue'      => 'price_changed',
					'message'    => sprintf(
						'Price of %s changed from %s to %s',
						$product->get_name(),
						wc_price( $item['price_at_add'] ),
						wc_price( $current_price )
					),
					'old_price'  => $item['price_at_add'],
					'new_price'  => $current_price,
				);
				// Price change is a warning, not a blocker.
			}
		}

		return array(
			'is_valid' => $is_valid,
			'issues'   => $issues,
			'cart'     => $cart,
		);
	}

	/**
	 * Save cart to database.
	 *
	 * @param array $cart Cart data.
	 * @return bool Success status.
	 */
	private function save_cart( $cart ) {
		$update_data = array(
			'items'      => wp_json_encode( $cart['items'] ),
			'total'      => $cart['total'],
			'updated_at' => current_time( 'mysql' ),
		);

		if ( isset( $cart['coupon_code'] ) ) {
			$update_data['coupon_code'] = $cart['coupon_code'];
		}

		if ( isset( $cart['shipping_address'] ) ) {
			$update_data['shipping_address'] = wp_json_encode( $cart['shipping_address'] );
		}

		if ( isset( $cart['expires_at'] ) ) {
			$update_data['expires_at'] = $cart['expires_at'];
		}

		if ( isset( $cart['status'] ) ) {
			$update_data['status'] = $cart['status'];
		}

		$result = $this->wpdb->update(
			$this->table_name,
			$update_data,
			array( 'id' => $cart['id'] ),
			null,
			array( '%d' )
		);

		// Stop recovery sequence when cart is modified (items changed).
		if ( $result !== false && class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
			$recovery = WCH_Abandoned_Cart_Recovery::getInstance();
			$recovery->stop_sequence( $cart['customer_phone'], 'cart_modified' );
		}

		return $result !== false;
	}

	/**
	 * Calculate cart total from items.
	 *
	 * @param array $items Cart items.
	 * @return float Total price.
	 */
	private function calculate_cart_total( $items ) {
		$total = 0.00;

		foreach ( $items as $item ) {
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$price = floatval( $product->get_price() );
			$total += $price * intval( $item['quantity'] );
		}

		return round( $total, 2 );
	}

	/**
	 * Get abandoned carts.
	 *
	 * Returns carts that haven't been updated within configured hours and not converted.
	 *
	 * @param int $hours Hours of inactivity to consider abandoned.
	 * @return array Abandoned carts.
	 */
	public function get_abandoned_carts( $hours = 24 ) {
		$cutoff_time = date( 'Y-m-d H:i:s', strtotime( '-' . $hours . ' hours' ) );

		$carts = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name}
				WHERE status = 'active'
				AND updated_at < %s
				AND (reminder_sent_at IS NULL OR reminder_sent_at < %s)
				ORDER BY updated_at ASC",
				$cutoff_time,
				$cutoff_time
			),
			ARRAY_A
		);

		// Decode JSON fields.
		foreach ( $carts as &$cart ) {
			$cart['items'] = ! empty( $cart['items'] ) ? json_decode( $cart['items'], true ) : array();
			$cart['shipping_address'] = ! empty( $cart['shipping_address'] ) ? json_decode( $cart['shipping_address'], true ) : null;
		}

		return $carts;
	}

	/**
	 * Mark cart as abandoned and set reminder sent.
	 *
	 * @param int $cart_id Cart ID.
	 * @return bool Success status.
	 */
	public function mark_reminder_sent( $cart_id ) {
		$result = $this->wpdb->update(
			$this->table_name,
			array(
				'reminder_sent_at' => current_time( 'mysql' ),
				'status'           => 'abandoned',
			),
			array( 'id' => $cart_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Clean up expired carts.
	 *
	 * Removes carts that have passed their expiry time.
	 *
	 * @return int Number of carts cleaned.
	 */
	public function cleanup_expired_carts() {
		$now = current_time( 'mysql' );

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE expires_at < %s AND status = 'active'",
				$now
			)
		);

		return $result !== false ? $result : 0;
	}
}
