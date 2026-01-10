<?php
declare(strict_types=1);

/**
 * Coupon Handler
 *
 * Handles checkout coupon operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\CouponHandlerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CouponHandler
 *
 * Manages coupon validation and application.
 */
class CouponHandler implements CouponHandlerInterface {

	/**
	 * Apply a coupon to checkout.
	 *
	 * @param string $couponCode Coupon code.
	 * @param float  $cartTotal  Cart total for discount calculation.
	 * @return array{success: bool, discount: float, error: string|null}
	 */
	public function applyCoupon( string $couponCode, float $cartTotal ): array {
		$couponCode = $this->sanitizeCouponCode( $couponCode );

		$validation = $this->validateCoupon( $couponCode );

		if ( ! $validation['valid'] ) {
			return array(
				'success'  => false,
				'discount' => 0.0,
				'error'    => $validation['error'],
			);
		}

		$discount = $this->calculateDiscount( $couponCode, $cartTotal );

		return array(
			'success'  => true,
			'discount' => $discount,
			'error'    => null,
		);
	}

	/**
	 * Validate coupon code.
	 *
	 * @param string $couponCode Coupon code.
	 * @return array{valid: bool, error: string|null}
	 */
	public function validateCoupon( string $couponCode ): array {
		$couponCode = $this->sanitizeCouponCode( $couponCode );
		$coupon     = new \WC_Coupon( $couponCode );

		if ( ! $coupon->get_id() ) {
			return array(
				'valid' => false,
				'error' => __( 'Invalid coupon code', 'whatsapp-commerce-hub' ),
			);
		}

		// Check coupon validity using WooCommerce discounts class.
		$discounts = new \WC_Discounts();
		$valid     = $discounts->is_coupon_valid( $coupon );

		if ( is_wp_error( $valid ) ) {
			return array(
				'valid' => false,
				'error' => $valid->get_error_message(),
			);
		}

		// Check if coupon is expired.
		if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < time() ) {
			return array(
				'valid' => false,
				'error' => __( 'This coupon has expired', 'whatsapp-commerce-hub' ),
			);
		}

		// Check usage limits.
		if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return array(
				'valid' => false,
				'error' => __( 'This coupon has reached its usage limit', 'whatsapp-commerce-hub' ),
			);
		}

		return array( 'valid' => true, 'error' => null );
	}

	/**
	 * Calculate discount amount for a coupon.
	 *
	 * @param string $couponCode Coupon code.
	 * @param float  $cartTotal  Cart total.
	 * @return float Discount amount.
	 */
	public function calculateDiscount( string $couponCode, float $cartTotal ): float {
		$couponCode = $this->sanitizeCouponCode( $couponCode );
		$coupon     = new \WC_Coupon( $couponCode );

		if ( ! $coupon->get_id() ) {
			return 0.0;
		}

		$discount = $coupon->get_amount();

		if ( $coupon->is_type( 'percent' ) ) {
			$discount = $cartTotal * ( $discount / 100 );
		}

		// Check maximum discount.
		$maxDiscount = $coupon->get_maximum_amount();
		if ( $maxDiscount > 0 && $discount > $maxDiscount ) {
			$discount = $maxDiscount;
		}

		// Cap discount at cart total.
		$discount = min( $discount, $cartTotal );

		return round( $discount, 2 );
	}

	/**
	 * Get coupon details.
	 *
	 * @param string $couponCode Coupon code.
	 * @return array|null Coupon details or null if not found.
	 */
	public function getCouponDetails( string $couponCode ): ?array {
		$couponCode = $this->sanitizeCouponCode( $couponCode );
		$coupon     = new \WC_Coupon( $couponCode );

		if ( ! $coupon->get_id() ) {
			return null;
		}

		return array(
			'id'              => $coupon->get_id(),
			'code'            => $coupon->get_code(),
			'discount_type'   => $coupon->get_discount_type(),
			'amount'          => $coupon->get_amount(),
			'description'     => $coupon->get_description(),
			'expiry_date'     => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'Y-m-d' ) : null,
			'minimum_amount'  => $coupon->get_minimum_amount(),
			'maximum_amount'  => $coupon->get_maximum_amount(),
			'individual_use'  => $coupon->get_individual_use(),
			'usage_limit'     => $coupon->get_usage_limit(),
			'usage_count'     => $coupon->get_usage_count(),
		);
	}

	/**
	 * Check if coupon can be applied to items.
	 *
	 * @param string $couponCode Coupon code.
	 * @param array  $items      Cart items.
	 * @return bool True if applicable.
	 */
	public function isApplicableToItems( string $couponCode, array $items ): bool {
		$couponCode = $this->sanitizeCouponCode( $couponCode );
		$coupon     = new \WC_Coupon( $couponCode );

		if ( ! $coupon->get_id() ) {
			return false;
		}

		$productIds = $coupon->get_product_ids();
		$excludeIds = $coupon->get_excluded_product_ids();
		$categories = $coupon->get_product_categories();
		$excludeCats = $coupon->get_excluded_product_categories();

		// If no restrictions, coupon applies to all.
		if ( empty( $productIds ) && empty( $categories ) ) {
			// Check exclusions.
			foreach ( $items as $item ) {
				$productId = $item['product_id'] ?? 0;

				if ( in_array( $productId, $excludeIds, true ) ) {
					return false;
				}

				if ( ! empty( $excludeCats ) ) {
					$product = wc_get_product( $productId );
					if ( $product ) {
						$productCats = $product->get_category_ids();
						if ( array_intersect( $productCats, $excludeCats ) ) {
							return false;
						}
					}
				}
			}

			return true;
		}

		// Check if any item matches the coupon restrictions.
		foreach ( $items as $item ) {
			$productId = $item['product_id'] ?? 0;

			// Check product ID restriction.
			if ( ! empty( $productIds ) && in_array( $productId, $productIds, true ) ) {
				return true;
			}

			// Check category restriction.
			if ( ! empty( $categories ) ) {
				$product = wc_get_product( $productId );
				if ( $product ) {
					$productCats = $product->get_category_ids();
					if ( array_intersect( $productCats, $categories ) ) {
						return true;
					}
				}
			}
		}

		return empty( $productIds ) && empty( $categories );
	}

	/**
	 * Sanitize coupon code.
	 *
	 * @param string $couponCode Raw coupon code.
	 * @return string Sanitized coupon code.
	 */
	public function sanitizeCouponCode( string $couponCode ): string {
		return wc_sanitize_coupon_code( $couponCode );
	}
}
