<?php
/**
 * Cart Manager Class
 *
 * Manages shopping cart operations for WhatsApp customers.
 *
 * @package WhatsApp_Commerce_Hub
 *
 * @deprecated 2.0.0 Use CartService via DI container instead:
 *             `WhatsAppCommerceHub\Container\Container::getInstance()->get(CartServiceInterface::class)`
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WhatsAppCommerceHub\Container\Container;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;

/**
 * Class WCH_Cart_Manager
 *
 * Handles cart CRUD operations, coupon application, and totals calculation.
 *
 * @deprecated 2.0.0 This class is a backward compatibility facade.
 *             Use CartServiceInterface via DI container for new code.
 */
class WCH_Cart_Manager {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Cart_Manager
	 */
	private static $instance = null;

	/**
	 * The underlying CartService instance.
	 *
	 * @var CartServiceInterface|null
	 */
	private ?CartServiceInterface $service = null;

	/**
	 * Cart expiry time in hours.
	 *
	 * @var int
	 */
	const CART_EXPIRY_HOURS = 72;

	/**
	 * Get singleton instance.
	 *
	 * @deprecated 2.0.0 Use getService() for new architecture.
	 * @return WCH_Cart_Manager
	 */
	public static function instance() {
		_deprecated_function( __METHOD__, '2.0.0', 'WCH_Cart_Manager::getService()' );

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the CartService from the DI container.
	 *
	 * This is the recommended way to access cart functionality in new code.
	 *
	 * @since 2.0.0
	 * @return CartServiceInterface
	 */
	public static function getService(): CartServiceInterface {
		return Container::getInstance()->get( CartServiceInterface::class );
	}

	/**
	 * Constructor.
	 *
	 * Initializes the facade by getting the CartService from the DI container.
	 */
	private function __construct() {
		try {
			$this->service = Container::getInstance()->get( CartServiceInterface::class );
		} catch ( \Throwable $e ) {
			// Log error but allow fallback behavior.
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::warning(
					'CartService not available in container, facade will fail gracefully',
					array( 'error' => $e->getMessage() )
				);
			}
		}
	}

	/**
	 * Get the underlying service instance.
	 *
	 * @return CartServiceInterface
	 * @throws \RuntimeException If service is not available.
	 */
	private function getServiceInstance(): CartServiceInterface {
		if ( null === $this->service ) {
			throw new \RuntimeException(
				'CartService not available. Ensure the DI container is properly initialized.'
			);
		}
		return $this->service;
	}

	/**
	 * Convert a Cart entity to array format for backward compatibility.
	 *
	 * @param \WhatsAppCommerceHub\Entities\Cart $cart Cart entity.
	 * @return array Cart data as array.
	 */
	private function cartToArray( \WhatsAppCommerceHub\Entities\Cart $cart ): array {
		return array(
			'id'               => $cart->id,
			'customer_phone'   => $cart->customer_phone,
			'items'            => $cart->items,
			'total'            => $cart->total,
			'coupon_code'      => $cart->coupon_code,
			'shipping_address' => $cart->shipping_address,
			'status'           => $cart->status,
			'expires_at'       => $cart->expires_at?->format( 'Y-m-d H:i:s' ),
			'created_at'       => $cart->created_at?->format( 'Y-m-d H:i:s' ) ?? current_time( 'mysql' ),
			'updated_at'       => $cart->updated_at?->format( 'Y-m-d H:i:s' ) ?? current_time( 'mysql' ),
		);
	}

	/**
	 * Get cart for customer.
	 *
	 * Returns existing cart or creates new empty cart with 72-hour expiry.
	 *
	 * @deprecated 2.0.0 Use CartService::getCart() instead.
	 * @param string $phone Customer phone number.
	 * @return array Cart data.
	 */
	public function get_cart( $phone ) {
		$cart = $this->getServiceInstance()->getCart( $phone );
		return $this->cartToArray( $cart );
	}

	/**
	 * Add item to cart.
	 *
	 * Validates product exists and has stock. If item already in cart, increments quantity.
	 *
	 * @deprecated 2.0.0 Use CartService::addItem() instead.
	 * @param string   $phone        Customer phone number.
	 * @param int      $product_id   Product ID.
	 * @param int|null $variation_id Variation ID (nullable).
	 * @param int      $quantity     Quantity to add.
	 * @return array Updated cart.
	 * @throws WCH_Cart_Exception If validation fails.
	 */
	public function add_item( $phone, $product_id, $variation_id, $quantity ) {
		try {
			$cart = $this->getServiceInstance()->addItem( $phone, $product_id, $variation_id, $quantity );
			return $this->cartToArray( $cart );
		} catch ( \InvalidArgumentException $e ) {
			// Map to legacy exception format.
			$code = str_contains( $e->getMessage(), 'not found' ) ? 'product_not_found' : 'invalid_argument';
			$http = str_contains( $e->getMessage(), 'not found' ) ? 404 : 400;
			throw new WCH_Cart_Exception( $e->getMessage(), $code, $http, array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			) );
		} catch ( \RuntimeException $e ) {
			// Map stock errors to legacy exception format.
			throw new WCH_Cart_Exception( $e->getMessage(), 'stock_error', 400, array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			) );
		}
	}

	/**
	 * Update item quantity in cart.
	 *
	 * Validates stock availability. Removes item if quantity is 0.
	 *
	 * @deprecated 2.0.0 Use CartService::updateQuantity() instead.
	 * @param string $phone      Customer phone number.
	 * @param int    $item_index Item index in cart.
	 * @param int    $new_quantity New quantity (0 to remove).
	 * @return array Updated cart.
	 * @throws WCH_Cart_Exception If validation fails.
	 */
	public function update_quantity( $phone, $item_index, $new_quantity ) {
		try {
			$cart = $this->getServiceInstance()->updateQuantity( $phone, $item_index, $new_quantity );
			return $this->cartToArray( $cart );
		} catch ( \InvalidArgumentException $e ) {
			throw new WCH_Cart_Exception( $e->getMessage(), 'item_not_found', 404, array(
				'item_index' => $item_index,
			) );
		} catch ( \RuntimeException $e ) {
			throw new WCH_Cart_Exception( $e->getMessage(), 'stock_error', 400, array(
				'item_index' => $item_index,
			) );
		}
	}

	/**
	 * Remove item from cart.
	 *
	 * @deprecated 2.0.0 Use CartService::removeItem() instead.
	 * @param string $phone      Customer phone number.
	 * @param int    $item_index Item index in cart.
	 * @return array Updated cart.
	 * @throws WCH_Cart_Exception If item not found.
	 */
	public function remove_item( $phone, $item_index ) {
		try {
			$cart = $this->getServiceInstance()->removeItem( $phone, $item_index );
			return $this->cartToArray( $cart );
		} catch ( \InvalidArgumentException $e ) {
			throw new WCH_Cart_Exception( $e->getMessage(), 'item_not_found', 404, array(
				'item_index' => $item_index,
			) );
		}
	}

	/**
	 * Clear all items from cart.
	 *
	 * Empties cart items but retains customer association.
	 *
	 * @deprecated 2.0.0 Use CartService::clearCart() instead.
	 * @param string $phone Customer phone number.
	 * @return array Updated cart.
	 */
	public function clear_cart( $phone ) {
		$cart = $this->getServiceInstance()->clearCart( $phone );
		return $this->cartToArray( $cart );
	}

	/**
	 * Apply coupon to cart.
	 *
	 * Validates WC coupon and checks restrictions.
	 *
	 * @deprecated 2.0.0 Use CartService::applyCoupon() instead.
	 * @param string $phone       Customer phone number.
	 * @param string $coupon_code Coupon code.
	 * @return array Array with discount amount and updated cart.
	 * @throws WCH_Cart_Exception If coupon is invalid.
	 */
	public function apply_coupon( $phone, $coupon_code ) {
		try {
			$result = $this->getServiceInstance()->applyCoupon( $phone, $coupon_code );
			return array(
				'discount' => $result['discount'],
				'cart'     => $this->cartToArray( $result['cart'] ),
			);
		} catch ( \InvalidArgumentException $e ) {
			throw new WCH_Cart_Exception( $e->getMessage(), 'invalid_coupon', 400, array(
				'coupon_code' => $coupon_code,
			) );
		}
	}

	/**
	 * Remove coupon from cart.
	 *
	 * @deprecated 2.0.0 Use CartService::removeCoupon() instead.
	 * @param string $phone Customer phone number.
	 * @return array Updated cart.
	 */
	public function remove_coupon( $phone ) {
		$cart = $this->getServiceInstance()->removeCoupon( $phone );
		return $this->cartToArray( $cart );
	}

	/**
	 * Calculate cart totals.
	 *
	 * Returns subtotal, discount, tax, shipping estimate, and total.
	 *
	 * @deprecated 2.0.0 Use CartService::calculateTotals() instead.
	 * @param array $cart Cart data.
	 * @return array Totals breakdown.
	 */
	public function calculate_totals( $cart ) {
		// Convert array to Cart entity if needed.
		$cart_entity = $this->getServiceInstance()->getCart( $cart['customer_phone'] );
		return $this->getServiceInstance()->calculateTotals( $cart_entity );
	}

	/**
	 * Get cart summary as formatted message.
	 *
	 * Builds itemized list with quantities, totals, discount, and shipping.
	 *
	 * @deprecated 2.0.0 Use CartService::getCartSummaryMessage() instead.
	 * @param string $phone Customer phone number.
	 * @return string Formatted cart summary.
	 */
	public function get_cart_summary_message( $phone ) {
		return $this->getServiceInstance()->getCartSummaryMessage( $phone );
	}

	/**
	 * Check cart validity.
	 *
	 * Verifies all items still exist, in stock, and prices unchanged.
	 *
	 * @deprecated 2.0.0 Use CartService::checkCartValidity() instead.
	 * @param string $phone Customer phone number.
	 * @return array Validation result with issues array.
	 */
	public function check_cart_validity( $phone ) {
		$result = $this->getServiceInstance()->checkCartValidity( $phone );
		return array(
			'is_valid' => $result['is_valid'],
			'issues'   => $result['issues'],
			'cart'     => $this->cartToArray( $result['cart'] ),
		);
	}

	/**
	 * Get abandoned carts.
	 *
	 * Returns carts that haven't been updated within configured hours and not converted.
	 *
	 * @deprecated 2.0.0 Use CartService::getAbandonedCarts() instead.
	 * @param int $hours Hours of inactivity to consider abandoned.
	 * @return array Abandoned carts.
	 */
	public function get_abandoned_carts( $hours = 24 ) {
		$carts = $this->getServiceInstance()->getAbandonedCarts( $hours );
		return array_map( fn( $cart ) => $this->cartToArray( $cart ), $carts );
	}

	/**
	 * Mark cart as abandoned and set reminder sent.
	 *
	 * @deprecated 2.0.0 Use CartService::markReminderSent() instead.
	 * @param int $cart_id Cart ID.
	 * @param int $reminder_number Reminder number (1, 2, or 3). Default 1 for backward compatibility.
	 * @return bool Success status.
	 */
	public function mark_reminder_sent( $cart_id, $reminder_number = 1 ) {
		return $this->getServiceInstance()->markReminderSent( $cart_id, $reminder_number );
	}

	/**
	 * Clean up expired carts.
	 *
	 * Marks carts as expired that have passed their expiry time.
	 *
	 * @deprecated 2.0.0 Use CartService::cleanupExpiredCarts() instead.
	 * @return int Number of carts cleaned.
	 */
	public function cleanup_expired_carts() {
		return $this->getServiceInstance()->cleanupExpiredCarts();
	}
}
