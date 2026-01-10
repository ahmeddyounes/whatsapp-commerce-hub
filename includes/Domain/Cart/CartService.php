<?php
/**
 * Cart Service
 *
 * Domain service for cart business logic and operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Domain\Cart;

use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CartRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartService
 *
 * Handles cart business logic with repository pattern.
 */
class CartService implements CartServiceInterface {

	/**
	 * Cart expiry time in hours.
	 */
	private const CART_EXPIRY_HOURS = 72;

	/**
	 * Constructor.
	 *
	 * @param CartRepositoryInterface $repository Cart repository.
	 */
	public function __construct( private CartRepositoryInterface $repository ) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCart( string $phone ): Cart {
		// Try to find existing active cart.
		$cart = $this->repository->findActiveByPhone( $phone );

		if ( $cart ) {
			return $cart;
		}

		// Create new cart with 72-hour expiry.
		$now       = new \DateTimeImmutable();
		$expiresAt = $now->modify( '+' . self::CART_EXPIRY_HOURS . ' hours' );

		$cart_id = $this->repository->create(
			[
				'customer_phone' => $phone,
				'items'          => [],
				'total'          => 0.00,
				'status'         => Cart::STATUS_ACTIVE,
				'expires_at'     => $expiresAt,
				'created_at'     => $now,
				'updated_at'     => $now,
			]
		);

		return $this->repository->find( $cart_id );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses transactional locking to prevent TOCTOU race conditions when
	 * concurrent requests modify the same cart.
	 */
	public function addItem( string $phone, int $product_id, ?int $variation_id, int $quantity ): Cart {
		// Validate quantity is positive (can be done outside transaction).
		if ( $quantity <= 0 ) {
			throw new \InvalidArgumentException(
				sprintf( 'Quantity must be positive, got: %d', $quantity )
			);
		}

		// Validate product exists (can be done outside transaction).
		$product = wc_get_product( $variation_id ?? $product_id );
		if ( ! $product ) {
			throw new \InvalidArgumentException(
				sprintf( 'Product not found: %d', $variation_id ?? $product_id )
			);
		}

		$this->repository->beginTransaction();

		try {
			// Get or create cart with lock to prevent concurrent modifications.
			$cart = $this->getCartWithLock( $phone );

			// Re-check stock within transaction for consistency.
			if ( ! $product->is_in_stock() ) {
				$this->repository->rollback();
				throw new \RuntimeException(
					sprintf( 'Product is out of stock: %s', $product->get_name() )
				);
			}

			// Check stock quantity.
			$existing_qty = $this->getExistingQuantity( $cart, $product_id, $variation_id );
			$total_qty    = $existing_qty + $quantity;

			if ( $product->managing_stock() && $product->get_stock_quantity() < $total_qty ) {
				$this->repository->rollback();
				throw new \RuntimeException(
					sprintf(
						'Insufficient stock for %s. Available: %d, Requested: %d',
						$product->get_name(),
						$product->get_stock_quantity(),
						$total_qty
					)
				);
			}

			// Update cart items.
			$items      = $cart->items;
			$item_found = false;

			foreach ( $items as $index => $item ) {
				if ( $item['product_id'] === $product_id && ( $item['variation_id'] ?? null ) === $variation_id ) {
					$items[ $index ]['quantity'] += $quantity;
					$item_found                   = true;
					break;
				}
			}

			if ( ! $item_found ) {
				$items[] = [
					'product_id'         => $product_id,
					'variation_id'       => $variation_id,
					'quantity'           => $quantity,
					'price_at_add'       => (float) $product->get_price(),
					'product_name'       => $product->get_name(),
					'variant_attributes' => $variation_id && $product->is_type( 'variation' )
						? $product->get_variation_attributes()
						: null,
				];
			}

			// Calculate new total and update.
			$total     = $this->calculateCartTotal( $items );
			$expiresAt = ( new \DateTimeImmutable() )->modify( '+' . self::CART_EXPIRY_HOURS . ' hours' );

			$this->repository->updateLocked(
				$cart->id,
				[
					'items'      => $items,
					'total'      => $total,
					'expires_at' => $expiresAt,
					'updated_at' => new \DateTimeImmutable(),
				]
			);

			$this->repository->commit();

			$this->stopRecoverySequence( $phone );

			return $this->repository->find( $cart->id );

		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses transactional locking to prevent TOCTOU race conditions when
	 * concurrent requests modify the same cart.
	 */
	public function updateQuantity( string $phone, int $item_index, int $new_quantity ): Cart {
		// Handle removal case separately (has its own transaction).
		if ( $new_quantity <= 0 ) {
			return $this->removeItem( $phone, $item_index );
		}

		$this->repository->beginTransaction();

		try {
			// Get cart with lock to prevent concurrent modifications.
			$cart = $this->getCartWithLock( $phone );

			if ( ! isset( $cart->items[ $item_index ] ) ) {
				$this->repository->rollback();
				throw new \InvalidArgumentException(
					sprintf( 'Item not found at index: %d', $item_index )
				);
			}

			$item    = $cart->items[ $item_index ];
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );

			if ( ! $product ) {
				$this->repository->rollback();
				throw new \InvalidArgumentException( 'Product no longer exists' );
			}

			if ( ! $product->is_in_stock() ) {
				$this->repository->rollback();
				throw new \RuntimeException(
					sprintf( 'Product is out of stock: %s', $product->get_name() )
				);
			}

			if ( $product->managing_stock() && $product->get_stock_quantity() < $new_quantity ) {
				$this->repository->rollback();
				throw new \RuntimeException(
					sprintf(
						'Insufficient stock for %s. Available: %d',
						$product->get_name(),
						$product->get_stock_quantity()
					)
				);
			}

			$items                            = $cart->items;
			$items[ $item_index ]['quantity'] = $new_quantity;
			$total                            = $this->calculateCartTotal( $items );

			$this->repository->updateLocked(
				$cart->id,
				[
					'items'      => $items,
					'total'      => $total,
					'updated_at' => new \DateTimeImmutable(),
				]
			);

			$this->repository->commit();

			$this->stopRecoverySequence( $phone );

			return $this->repository->find( $cart->id );

		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses transactional locking to prevent TOCTOU race conditions when
	 * concurrent requests modify the same cart.
	 */
	public function removeItem( string $phone, int $item_index ): Cart {
		$this->repository->beginTransaction();

		try {
			// Get cart with lock to prevent concurrent modifications.
			$cart = $this->getCartWithLock( $phone );

			if ( ! isset( $cart->items[ $item_index ] ) ) {
				$this->repository->rollback();
				throw new \InvalidArgumentException(
					sprintf( 'Item not found at index: %d', $item_index )
				);
			}

			$items = $cart->items;
			array_splice( $items, $item_index, 1 );
			$total = $this->calculateCartTotal( $items );

			$this->repository->updateLocked(
				$cart->id,
				[
					'items'      => $items,
					'total'      => $total,
					'updated_at' => new \DateTimeImmutable(),
				]
			);

			$this->repository->commit();

			$this->stopRecoverySequence( $phone );

			return $this->repository->find( $cart->id );

		} catch ( \Throwable $e ) {
			$this->repository->rollback();
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function clearCart( string $phone ): Cart {
		$cart = $this->getCart( $phone );

		$this->repository->update(
			$cart->id,
			[
				'items'       => [],
				'total'       => 0.00,
				'coupon_code' => null,
				'updated_at'  => new \DateTimeImmutable(),
			]
		);

		$this->stopRecoverySequence( $phone );

		return $this->repository->find( $cart->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function applyCoupon( string $phone, string $coupon_code ): array {
		$cart = $this->getCart( $phone );

		$coupon = new \WC_Coupon( $coupon_code );
		if ( ! $coupon->is_valid() ) {
			throw new \InvalidArgumentException( 'Invalid coupon code: ' . $coupon_code );
		}

		// Check expiry.
		$expiry_date = $coupon->get_date_expires();
		if ( $expiry_date && $expiry_date->getTimestamp() < time() ) {
			throw new \InvalidArgumentException( 'Coupon has expired' );
		}

		// Check global usage limit.
		$usage_limit = $coupon->get_usage_limit();
		if ( $usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit ) {
			throw new \InvalidArgumentException( 'Coupon usage limit reached' );
		}

		// SECURITY: Check per-user usage limit.
		// This prevents a single user from using a coupon multiple times by changing phone numbers.
		$usage_limit_per_user = $coupon->get_usage_limit_per_user();
		if ( $usage_limit_per_user > 0 ) {
			// SECURITY: First check phone-based usage tracking to prevent bypass.
			// This catches users who don't have WC accounts or changed their phone numbers.
			$phone_usage_count = $this->getCouponPhoneUsageCount( $coupon->get_id(), $phone );
			if ( $phone_usage_count >= $usage_limit_per_user ) {
				throw new \InvalidArgumentException(
					sprintf( 'You have already used this coupon %d time(s)', $phone_usage_count )
				);
			}

			// Also check WooCommerce customer-based usage (email/ID).
			// Try to find the WooCommerce customer by phone number.
			$customer = $this->findCustomerByPhone( $phone );

			if ( $customer ) {
				// Get customer's email to check coupon usage.
				$customer_email = $customer->get_email();
				$customer_id    = $customer->get_id();

				// WooCommerce tracks coupon usage by email and customer ID.
				$used_by = $coupon->get_used_by();

				$usage_count = 0;
				foreach ( $used_by as $used_by_entry ) {
					// Can be customer ID or email.
					if ( (int) $used_by_entry === $customer_id || $used_by_entry === $customer_email ) {
						++$usage_count;
					}
				}

				if ( $usage_count >= $usage_limit_per_user ) {
					throw new \InvalidArgumentException(
						sprintf( 'You have already used this coupon %d time(s)', $usage_count )
					);
				}
			}
		}

		// Calculate subtotal.
		$subtotal = $this->calculateCartTotal( $cart->items );

		// Check minimum amount.
		$min_amount = $coupon->get_minimum_amount();
		if ( $min_amount > 0 && $subtotal < $min_amount ) {
			throw new \InvalidArgumentException(
				sprintf( 'Minimum cart total of %s required', wc_price( $min_amount ) )
			);
		}

		// Check maximum amount.
		$max_amount = $coupon->get_maximum_amount();
		if ( $max_amount > 0 && $subtotal > $max_amount ) {
			throw new \InvalidArgumentException(
				sprintf( 'Maximum cart total of %s exceeded', wc_price( $max_amount ) )
			);
		}

		// Validate product restrictions.
		$this->validateCouponProductRestrictions( $coupon, $cart );

		// Validate category restrictions.
		$this->validateCouponCategoryRestrictions( $coupon, $cart );

		// Calculate discount.
		$discount = $this->calculateCouponDiscount( $coupon, $subtotal );

		// Update cart.
		$this->repository->update(
			$cart->id,
			[
				'coupon_code' => $coupon_code,
				'updated_at'  => new \DateTimeImmutable(),
			]
		);

		return [
			'discount' => round( $discount, 2 ),
			'cart'     => $this->repository->find( $cart->id ),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeCoupon( string $phone ): Cart {
		$cart = $this->getCart( $phone );

		$this->repository->update(
			$cart->id,
			[
				'coupon_code' => null,
				'updated_at'  => new \DateTimeImmutable(),
			]
		);

		return $this->repository->find( $cart->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function calculateTotals( Cart $cart ): array {
		$subtotal = $this->calculateCartTotal( $cart->items );
		$discount = 0.00;

		// Calculate discount from coupon.
		if ( ! empty( $cart->coupon_code ) ) {
			$coupon = new \WC_Coupon( $cart->coupon_code );
			if ( $coupon->is_valid() ) {
				$discount = $this->calculateCouponDiscount( $coupon, $subtotal );
			}
		}

		$amount_after_discount = max( 0, $subtotal - $discount );

		// Calculate tax.
		$tax = 0.00;
		if ( wc_tax_enabled() ) {
			$tax_rates = \WC_Tax::get_base_tax_rates();
			if ( ! empty( $tax_rates ) ) {
				$tax_rate = reset( $tax_rates );
				$tax      = ( $amount_after_discount * $tax_rate['rate'] ) / 100;
			}
		}

		// Get shipping estimate.
		$shipping_estimate = $this->getShippingEstimate();

		$total = $amount_after_discount + $tax + $shipping_estimate;

		return [
			'subtotal'          => round( $subtotal, 2 ),
			'discount'          => round( $discount, 2 ),
			'tax'               => round( $tax, 2 ),
			'shipping_estimate' => round( $shipping_estimate, 2 ),
			'total'             => round( $total, 2 ),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCartSummaryMessage( string $phone ): string {
		$cart = $this->getCart( $phone );

		if ( $cart->isEmpty() ) {
			return "Your cart is empty.\n\nStart shopping to add items!";
		}

		$message = "Your Cart\n\n";

		foreach ( $cart->items as $index => $item ) {
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$price      = (float) $product->get_price();
			$line_total = $price * $item['quantity'];

			$message .= sprintf(
				"%d. %s\n   Qty: %d x %s = %s\n\n",
				$index + 1,
				$product->get_name(),
				$item['quantity'],
				wc_price( $price ),
				wc_price( $line_total )
			);
		}

		$totals = $this->calculateTotals( $cart );

		$message .= "---\n";
		$message .= sprintf( "Subtotal: %s\n", wc_price( $totals['subtotal'] ) );

		if ( $totals['discount'] > 0 ) {
			$message .= sprintf( "Discount (%s): -%s\n", $cart->coupon_code, wc_price( $totals['discount'] ) );
		}

		if ( $totals['tax'] > 0 ) {
			$message .= sprintf( "Tax: %s\n", wc_price( $totals['tax'] ) );
		}

		if ( $totals['shipping_estimate'] > 0 ) {
			$message .= sprintf( "Shipping (est.): %s\n", wc_price( $totals['shipping_estimate'] ) );
		} else {
			$message .= "Shipping: Free\n";
		}

		$message .= "---\n";
		$message .= sprintf( 'Total: %s', wc_price( $totals['total'] ) );

		return $message;
	}

	/**
	 * {@inheritdoc}
	 */
	public function checkCartValidity( string $phone ): array {
		$cart = $this->getCart( $phone );

		$issues   = [];
		$is_valid = true;

		foreach ( $cart->items as $index => $item ) {
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );

			if ( ! $product ) {
				$issues[] = [
					'item_index' => $index,
					'product_id' => $item['product_id'],
					'issue'      => 'product_not_found',
					'message'    => sprintf( '%s is no longer available', $item['product_name'] ),
				];
				$is_valid = false;
				continue;
			}

			if ( ! $product->is_in_stock() ) {
				$issues[] = [
					'item_index' => $index,
					'product_id' => $item['product_id'],
					'issue'      => 'out_of_stock',
					'message'    => sprintf( '%s is out of stock', $product->get_name() ),
				];
				$is_valid = false;
				continue;
			}

			if ( $product->managing_stock() && $product->get_stock_quantity() < $item['quantity'] ) {
				$issues[] = [
					'item_index' => $index,
					'product_id' => $item['product_id'],
					'issue'      => 'insufficient_stock',
					'message'    => sprintf(
						'Only %d of %s available (you have %d in cart)',
						$product->get_stock_quantity(),
						$product->get_name(),
						$item['quantity']
					),
				];
				$is_valid = false;
				continue;
			}

			// Check price changes (warning only).
			$current_price = (float) $product->get_price();
			if ( abs( $current_price - $item['price_at_add'] ) > 0.01 ) {
				$issues[] = [
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
				];
			}
		}

		return [
			'is_valid' => $is_valid,
			'issues'   => $issues,
			'cart'     => $cart,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAbandonedCarts( int $hours = 24 ): array {
		return $this->repository->findAbandonedCarts( $hours );
	}

	/**
	 * {@inheritdoc}
	 */
	public function markReminderSent( int $cart_id, int $reminder_number = 1 ): bool {
		return $this->repository->markReminderSent( $cart_id, $reminder_number );
	}

	/**
	 * {@inheritdoc}
	 */
	public function cleanupExpiredCarts(): int {
		$expired_carts = $this->repository->findExpiredCarts();
		$count         = 0;

		foreach ( $expired_carts as $cart ) {
			$this->repository->update(
				$cart->id,
				[
					'status' => Cart::STATUS_EXPIRED,
				]
			);
			++$count;
		}

		return $count;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setShippingAddress( string $phone, array $address ): Cart {
		$cart = $this->getCart( $phone );

		$this->repository->update(
			$cart->id,
			[
				'shipping_address' => $address,
				'updated_at'       => new \DateTimeImmutable(),
			]
		);

		return $this->repository->find( $cart->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function markCompleted( string $phone, int $order_id ): bool {
		$cart = $this->getCart( $phone );

		$was_abandoned = $cart->status === Cart::STATUS_ABANDONED;

		$update_data = [
			'status'     => Cart::STATUS_CONVERTED,
			'updated_at' => new \DateTimeImmutable(),
		];

		if ( $was_abandoned ) {
			$update_data['recovered']          = true;
			$update_data['recovered_order_id'] = $order_id;
			$update_data['recovered_revenue']  = $cart->total;
		}

		return $this->repository->update( $cart->id, $update_data );
	}

	/**
	 * Calculate cart total from items.
	 *
	 * SECURITY: Uses stored price_at_add to prevent price manipulation attacks.
	 * If price_at_add is missing, falls back to current price but logs a warning.
	 *
	 * @param array $items Cart items.
	 * @return float Total price.
	 */
	private function calculateCartTotal( array $items ): float {
		$total = 0.00;

		foreach ( $items as $item ) {
			// Validate product still exists.
			$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			// SECURITY: Use stored price_at_add, NOT live product price.
			// This prevents price manipulation between add-to-cart and checkout.
			if ( isset( $item['price_at_add'] ) && is_numeric( $item['price_at_add'] ) ) {
				$price = (float) $item['price_at_add'];
			} else {
				// Fallback to current price if price_at_add is missing (legacy carts).
				$price = (float) $product->get_price();
				// Log warning for monitoring - this shouldn't happen with new carts.
				do_action(
					'wch_log_warning',
					'Cart item missing price_at_add, using live price',
					[
						'product_id' => $item['product_id'] ?? 0,
						'live_price' => $price,
					]
				);
			}

			$total += $price * (int) $item['quantity'];
		}

		return round( $total, 2 );
	}

	/**
	 * Get existing quantity in cart for a product.
	 *
	 * @param Cart     $cart         Cart entity.
	 * @param int      $product_id   Product ID.
	 * @param int|null $variation_id Variation ID.
	 * @return int Existing quantity.
	 */
	private function getExistingQuantity( Cart $cart, int $product_id, ?int $variation_id ): int {
		foreach ( $cart->items as $item ) {
			if ( $item['product_id'] === $product_id && ( $item['variation_id'] ?? null ) === $variation_id ) {
				return (int) $item['quantity'];
			}
		}
		return 0;
	}

	/**
	 * Calculate coupon discount.
	 *
	 * @param \WC_Coupon $coupon   WooCommerce coupon.
	 * @param float      $subtotal Cart subtotal.
	 * @return float Discount amount.
	 */
	private function calculateCouponDiscount( \WC_Coupon $coupon, float $subtotal ): float {
		if ( $coupon->is_type( 'percent' ) ) {
			return ( $subtotal * $coupon->get_amount() ) / 100;
		}

		if ( $coupon->is_type( 'fixed_cart' ) ) {
			return min( $coupon->get_amount(), $subtotal );
		}

		return 0.00;
	}

	/**
	 * Validate coupon product restrictions.
	 *
	 * @param \WC_Coupon $coupon Coupon to validate.
	 * @param Cart       $cart   Cart entity.
	 * @throws \InvalidArgumentException If restrictions not met.
	 */
	private function validateCouponProductRestrictions( \WC_Coupon $coupon, Cart $cart ): void {
		$product_ids          = $coupon->get_product_ids();
		$excluded_product_ids = $coupon->get_excluded_product_ids();

		if ( empty( $product_ids ) && empty( $excluded_product_ids ) ) {
			return;
		}

		$cart_product_ids = array_map(
			fn( array $item ) => $item['product_id'],
			$cart->items
		);

		if ( ! empty( $product_ids ) ) {
			$has_valid = false;
			foreach ( $cart_product_ids as $pid ) {
				if ( in_array( $pid, $product_ids, true ) ) {
					$has_valid = true;
					break;
				}
			}
			if ( ! $has_valid ) {
				throw new \InvalidArgumentException( 'Coupon does not apply to items in your cart' );
			}
		}

		if ( ! empty( $excluded_product_ids ) ) {
			foreach ( $cart_product_ids as $pid ) {
				if ( in_array( $pid, $excluded_product_ids, true ) ) {
					throw new \InvalidArgumentException( 'Coupon cannot be applied to some items in your cart' );
				}
			}
		}
	}

	/**
	 * Validate coupon category restrictions.
	 *
	 * @param \WC_Coupon $coupon Coupon to validate.
	 * @param Cart       $cart   Cart entity.
	 * @throws \InvalidArgumentException If restrictions not met.
	 */
	private function validateCouponCategoryRestrictions( \WC_Coupon $coupon, Cart $cart ): void {
		$category_ids          = $coupon->get_product_categories();
		$excluded_category_ids = $coupon->get_excluded_product_categories();

		if ( empty( $category_ids ) && empty( $excluded_category_ids ) ) {
			return;
		}

		// Get all category IDs from cart products.
		$cart_category_ids = [];
		foreach ( $cart->items as $item ) {
			$product = wc_get_product( $item['product_id'] );
			if ( $product ) {
				$product_cats      = $product->get_category_ids();
				$cart_category_ids = array_merge( $cart_category_ids, $product_cats );
			}
		}
		$cart_category_ids = array_unique( $cart_category_ids );

		// If specific categories are required.
		if ( ! empty( $category_ids ) ) {
			$has_valid = false;
			foreach ( $cart_category_ids as $cat_id ) {
				if ( in_array( $cat_id, $category_ids, true ) ) {
					$has_valid = true;
					break;
				}
			}
			if ( ! $has_valid ) {
				throw new \InvalidArgumentException( 'Coupon does not apply to items in your cart categories' );
			}
		}

		// If categories are excluded.
		if ( ! empty( $excluded_category_ids ) ) {
			foreach ( $cart_category_ids as $cat_id ) {
				if ( in_array( $cat_id, $excluded_category_ids, true ) ) {
					throw new \InvalidArgumentException( 'Coupon cannot be applied to some item categories in your cart' );
				}
			}
		}
	}

	/**
	 * Get shipping estimate.
	 *
	 * @return float Shipping cost estimate.
	 */
	private function getShippingEstimate(): float {
		$shipping_zones = \WC_Shipping_Zones::get_zones();
		if ( empty( $shipping_zones ) ) {
			return 0.00;
		}

		$zone = reset( $shipping_zones );
		if ( ! isset( $zone['shipping_methods'] ) ) {
			return 0.00;
		}

		foreach ( $zone['shipping_methods'] as $method ) {
			if ( $method->enabled !== 'yes' ) {
				continue;
			}

			if ( $method->id === 'flat_rate' && isset( $method->cost ) ) {
				return (float) $method->cost;
			}

			if ( $method->id === 'free_shipping' ) {
				return 0.00;
			}
		}

		return 0.00;
	}

	/**
	 * Stop recovery sequence for a cart.
	 *
	 * @param string $phone Customer phone.
	 */
	private function stopRecoverySequence( string $phone ): void {
		if ( class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
			$recovery = \WCH_Abandoned_Cart_Recovery::getInstance();
			$recovery->stop_sequence( $phone, 'cart_modified' );
		}
	}

	/**
	 * Get or create a cart with row lock for transactional operations.
	 *
	 * Must be called within an active transaction. Uses advisory locking to
	 * prevent TOCTOU race conditions where concurrent requests could both
	 * create carts for the same customer.
	 *
	 * @param string $phone Customer phone number.
	 * @return Cart The cart entity (locked for update).
	 * @throws \RuntimeException If cart cannot be created or locked.
	 */
	private function getCartWithLock( string $phone ): Cart {
		$now       = new \DateTimeImmutable();
		$expiresAt = $now->modify( '+' . self::CART_EXPIRY_HOURS . ' hours' );

		// Use atomic find-or-create with advisory locking.
		return $this->repository->findOrCreateActiveForUpdate( $phone, $expiresAt );
	}

	/**
	 * Find WooCommerce customer by phone number.
	 *
	 * Searches both billing phone and custom phone meta fields.
	 *
	 * @param string $phone Phone number to search.
	 * @return \WC_Customer|null Customer object or null if not found.
	 */
	private function findCustomerByPhone( string $phone ): ?\WC_Customer {
		// Normalize phone number for search.
		$normalized_phone = preg_replace( '/[^0-9+]/', '', $phone );

		// Try to find customer profile with linked WC customer ID.
		global $wpdb;
		$table = $wpdb->prefix . 'wch_customer_profiles';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wc_customer_id FROM {$table} WHERE phone = %s AND wc_customer_id IS NOT NULL LIMIT 1",
				$normalized_phone
			)
		);

		if ( $profile && $profile->wc_customer_id ) {
			$customer = new \WC_Customer( (int) $profile->wc_customer_id );
			if ( $customer->get_id() ) {
				return $customer;
			}
		}

		// Fallback: Search WooCommerce customers by billing phone.
		$customer_query = new \WC_Customer_Query(
			[
				'meta_key'   => 'billing_phone',
				'meta_value' => $normalized_phone,
				'number'     => 1,
			]
		);

		$customers = $customer_query->get_customers();
		if ( ! empty( $customers ) ) {
			return $customers[0];
		}

		return null;
	}

	/**
	 * Get coupon usage count for a specific phone number.
	 *
	 * SECURITY: This is the primary defense against coupon usage limit bypass.
	 * Phone-based tracking catches users who:
	 * 1. Don't have WooCommerce accounts (WhatsApp-only customers)
	 * 2. Changed their phone number to circumvent limits
	 *
	 * @param int    $coupon_id Coupon ID.
	 * @param string $phone     Phone number.
	 * @return int Usage count.
	 */
	private function getCouponPhoneUsageCount( int $coupon_id, string $phone ): int {
		global $wpdb;

		$table            = $wpdb->prefix . 'wch_coupon_phone_usage';
		$normalized_phone = preg_replace( '/[^0-9+]/', '', $phone );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND phone = %s",
				$coupon_id,
				$normalized_phone
			)
		);

		return (int) $count;
	}

	/**
	 * Record coupon usage for a phone number.
	 *
	 * SECURITY: This should be called when an order containing a coupon is completed.
	 * Uses INSERT IGNORE to handle potential duplicates (idempotent).
	 *
	 * @param int    $coupon_id Coupon ID.
	 * @param string $phone     Phone number.
	 * @param int    $order_id  Order ID.
	 * @return bool True if recorded, false on failure.
	 */
	public function recordCouponPhoneUsage( int $coupon_id, string $phone, int $order_id ): bool {
		global $wpdb;

		$table            = $wpdb->prefix . 'wch_coupon_phone_usage';
		$normalized_phone = preg_replace( '/[^0-9+]/', '', $phone );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (coupon_id, phone, order_id, used_at) VALUES (%d, %s, %d, %s)",
				$coupon_id,
				$normalized_phone,
				$order_id,
				current_time( 'mysql' )
			)
		);

		if ( false === $result ) {
			do_action(
				'wch_log_error',
				'Failed to record coupon phone usage',
				[
					'coupon_id' => $coupon_id,
					'phone'     => $normalized_phone,
					'order_id'  => $order_id,
					'error'     => $wpdb->last_error,
				]
			);
			return false;
		}

		return true;
	}
}
