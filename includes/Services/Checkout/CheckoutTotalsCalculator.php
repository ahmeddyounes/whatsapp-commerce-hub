<?php
/**
 * Checkout Totals Calculator
 *
 * Calculates checkout order totals.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutTotalsCalculatorInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutTotalsCalculator
 *
 * Handles order total calculations.
 */
class CheckoutTotalsCalculator implements CheckoutTotalsCalculatorInterface {

	/**
	 * Calculate complete order totals.
	 *
	 * @param array $params Calculation parameters.
	 * @return array{subtotal: float, discount: float, shipping: float, tax: float, payment_fee: float, total: float}
	 */
	public function calculateTotals( array $params ): array {
		$subtotal   = $this->calculateSubtotal( $params['items'] ?? array() );
		$discount   = $this->calculateDiscount( $subtotal, $params['coupon_code'] ?? '' );
		$shipping   = (float) ( $params['shipping_cost'] ?? 0 );
		$paymentFee = (float) ( $params['payment_fee'] ?? 0 );

		$taxable = $subtotal - $discount + $shipping;
		$tax     = $this->calculateTax( $taxable, $params['address'] ?? array() );

		$total = $subtotal - $discount + $shipping + $tax + $paymentFee;

		return array(
			'subtotal'    => round( $subtotal, 2 ),
			'discount'    => round( $discount, 2 ),
			'shipping'    => round( $shipping, 2 ),
			'tax'         => round( $tax, 2 ),
			'payment_fee' => round( $paymentFee, 2 ),
			'total'       => round( max( 0, $total ), 2 ),
		);
	}

	/**
	 * Calculate cart subtotal.
	 *
	 * @param array $items Cart items.
	 * @return float Subtotal amount.
	 */
	public function calculateSubtotal( array $items ): float {
		$subtotal = 0.0;

		foreach ( $items as $item ) {
			$price    = (float) ( $item['price'] ?? 0 );
			$quantity = (int) ( $item['quantity'] ?? 1 );
			$subtotal += $price * $quantity;
		}

		return $subtotal;
	}

	/**
	 * Calculate discount amount.
	 *
	 * @param float  $subtotal   Subtotal amount.
	 * @param string $couponCode Coupon code.
	 * @return float Discount amount.
	 */
	public function calculateDiscount( float $subtotal, string $couponCode ): float {
		if ( empty( $couponCode ) ) {
			return 0.0;
		}

		$coupon = new \WC_Coupon( $couponCode );

		if ( ! $coupon->get_id() ) {
			return 0.0;
		}

		$discountAmount = $coupon->get_amount();

		if ( $coupon->is_type( 'percent' ) ) {
			$discountAmount = $subtotal * ( $discountAmount / 100 );
		}

		// Cap discount at subtotal.
		return min( $discountAmount, $subtotal );
	}

	/**
	 * Calculate tax amount.
	 *
	 * @param float $taxableAmount Taxable amount.
	 * @param array $address       Shipping address for tax location.
	 * @return float Tax amount.
	 */
	public function calculateTax( float $taxableAmount, array $address = array() ): float {
		if ( ! wc_tax_enabled() || $taxableAmount <= 0 ) {
			return 0.0;
		}

		$taxClass = apply_filters( 'wch_checkout_tax_class', '' );
		$taxRates = \WC_Tax::get_rates( $taxClass );

		if ( empty( $taxRates ) ) {
			return 0.0;
		}

		$taxes = \WC_Tax::calc_tax( $taxableAmount, $taxRates );

		return array_sum( $taxes );
	}

	/**
	 * Get order review data with formatted totals.
	 *
	 * @param string $phone Customer phone number.
	 * @param array  $state Checkout state.
	 * @param array  $items Cart items.
	 * @return array Order review data.
	 */
	public function getOrderReview( string $phone, array $state, array $items ): array {
		$formattedItems = array();

		foreach ( $items as $item ) {
			$product = wc_get_product( $item['product_id'] );

			$formattedItems[] = array(
				'name'       => $product ? $product->get_name() : __( 'Unknown Product', 'whatsapp-commerce-hub' ),
				'quantity'   => $item['quantity'] ?? 1,
				'price'      => $item['price'] ?? 0,
				'total'      => ( $item['price'] ?? 0 ) * ( $item['quantity'] ?? 1 ),
				'total_html' => $this->formatAmount( ( $item['price'] ?? 0 ) * ( $item['quantity'] ?? 1 ) ),
			);
		}

		$totals = $this->calculateTotals( array(
			'items'        => $items,
			'coupon_code'  => $state['coupon_code'] ?? '',
			'shipping_cost' => $state['shipping_method']['cost'] ?? 0,
			'payment_fee'  => $state['payment_method']['fee'] ?? 0,
			'address'      => $state['address'] ?? array(),
		) );

		return array(
			'items'    => $formattedItems,
			'address'  => $state['address'] ?? array(),
			'shipping' => array(
				'method' => $state['shipping_method']['label'] ?? '',
				'cost'   => $state['shipping_method']['cost'] ?? 0,
			),
			'payment'  => $state['payment_method']['label'] ?? '',
			'totals'   => $totals,
			'totals_formatted' => array(
				'subtotal'    => $this->formatAmount( $totals['subtotal'] ),
				'discount'    => $this->formatAmount( $totals['discount'] ),
				'shipping'    => $this->formatAmount( $totals['shipping'] ),
				'tax'         => $this->formatAmount( $totals['tax'] ),
				'payment_fee' => $this->formatAmount( $totals['payment_fee'] ),
				'total'       => $this->formatAmount( $totals['total'] ),
			),
		);
	}

	/**
	 * Format currency amount for display.
	 *
	 * @param float $amount Amount to format.
	 * @return string Formatted amount.
	 */
	public function formatAmount( float $amount ): string {
		return wc_price( $amount );
	}
}
