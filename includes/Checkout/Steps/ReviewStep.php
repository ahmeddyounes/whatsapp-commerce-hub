<?php
/**
 * Review Step
 *
 * Handles order review during checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Checkout\Steps;

use WhatsAppCommerceHub\Checkout\AbstractStep;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReviewStep
 *
 * Handles order review before confirmation.
 */
class ReviewStep extends AbstractStep {

	/**
	 * Get the step identifier.
	 *
	 * @return string
	 */
	public function getStepId(): string {
		return 'review';
	}

	/**
	 * Get the next step identifier.
	 *
	 * @return string|null
	 */
	public function getNextStep(): ?string {
		return 'confirm';
	}

	/**
	 * Get the previous step identifier.
	 *
	 * @return string|null
	 */
	public function getPreviousStep(): ?string {
		return 'payment';
	}

	/**
	 * Get the step title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return __( 'Review Order', 'whatsapp-commerce-hub' );
	}

	/**
	 * Execute the step (show order summary).
	 *
	 * @param array $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function execute( array $context ): CheckoutResponse {
		try {
			$this->log( 'Showing order review', array( 'phone' => $this->getCustomerPhone( $context ) ) );

			$cart          = $this->getCart( $context );
			$checkout_data = $this->getCheckoutData( $context );

			if ( empty( $cart['items'] ) ) {
				return $this->failure(
					__( 'Cart is empty', 'whatsapp-commerce-hub' ),
					'empty_cart',
					array( $this->errorMessage( __( 'Your cart is empty. Please add items before checkout.', 'whatsapp-commerce-hub' ) ) )
				);
			}

			// Build order summary.
			$totals  = $this->calculateTotals( $cart, $checkout_data );
			$message = $this->buildOrderSummaryMessage( $cart, $checkout_data, $totals );

			return $this->success(
				array( $message ),
				array( 'totals' => $totals )
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error showing order review', array( 'error' => $e->getMessage() ) );

			return $this->failure(
				$e->getMessage(),
				'review_failed',
				array( $this->errorMessage( __( 'Sorry, we could not display your order summary. Please try again.', 'whatsapp-commerce-hub' ) ) )
			);
		}
	}

	/**
	 * Process user input for review step.
	 *
	 * @param string $input   User's input.
	 * @param array  $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function processInput( string $input, array $context ): CheckoutResponse {
		try {
			$this->log(
				'Processing review input',
				array(
					'phone' => $this->getCustomerPhone( $context ),
					'input' => $input,
				)
			);

			switch ( $input ) {
				case 'confirm_order':
					// Proceed to confirmation.
					return $this->success( array(), array(), $this->getNextStep() );

				case 'edit_address':
					// Go back to address step.
					return $this->success( array(), array(), 'address' );

				case 'edit_shipping':
					// Go back to shipping step.
					return $this->success( array(), array(), 'shipping' );

				case 'edit_payment':
					// Go back to payment step.
					return $this->success( array(), array(), 'payment' );

				case 'cancel_checkout':
					return $this->failure(
						__( 'Checkout cancelled', 'whatsapp-commerce-hub' ),
						'checkout_cancelled',
						array( $this->message_builder->text( __( 'Checkout cancelled. Your cart items are still saved.', 'whatsapp-commerce-hub' ) ) )
					);

				default:
					return $this->failure(
						__( 'Invalid selection', 'whatsapp-commerce-hub' ),
						'invalid_review_selection',
						array( $this->errorMessage( __( 'Please select a valid option.', 'whatsapp-commerce-hub' ) ) )
					);
			}
		} catch ( \Throwable $e ) {
			$this->logError( 'Error processing review input', array( 'error' => $e->getMessage() ) );

			return $this->failure(
				$e->getMessage(),
				'review_processing_failed',
				array( $this->errorMessage( __( 'Sorry, an error occurred. Please try again.', 'whatsapp-commerce-hub' ) ) )
			);
		}
	}

	/**
	 * Validate review data.
	 *
	 * @param array $data    Review data.
	 * @param array $context Checkout context.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $data, array $context ): array {
		$errors        = array();
		$checkout_data = $this->getCheckoutData( $context );

		if ( empty( $checkout_data['shipping_address'] ) ) {
			$errors['shipping_address'] = __( 'Shipping address is required', 'whatsapp-commerce-hub' );
		}

		if ( empty( $checkout_data['shipping_method'] ) ) {
			$errors['shipping_method'] = __( 'Shipping method is required', 'whatsapp-commerce-hub' );
		}

		if ( empty( $checkout_data['payment_method'] ) ) {
			$errors['payment_method'] = __( 'Payment method is required', 'whatsapp-commerce-hub' );
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}

	/**
	 * Calculate order totals.
	 *
	 * @param array $cart          Cart data.
	 * @param array $checkout_data Checkout data.
	 * @return array
	 */
	private function calculateTotals( array $cart, array $checkout_data ): array {
		$subtotal = 0.0;

		foreach ( $cart['items'] as $item ) {
			$subtotal += ( $item['price'] ?? 0 ) * ( $item['quantity'] ?? 1 );
		}

		$shipping_cost = $checkout_data['shipping_method']['cost'] ?? 0.0;
		$payment_fee   = $checkout_data['payment_method']['fee'] ?? 0.0;
		$discount      = $cart['discount'] ?? 0.0;
		$tax           = $this->calculateTax( $subtotal - $discount );

		$total = $subtotal + $shipping_cost + $payment_fee + $tax - $discount;

		return array(
			'subtotal'        => $subtotal,
			'shipping'        => $shipping_cost,
			'payment_fee'     => $payment_fee,
			'discount'        => $discount,
			'tax'             => $tax,
			'total'           => max( 0, $total ),
			'currency'        => get_woocommerce_currency(),
			'currency_symbol' => get_woocommerce_currency_symbol(),
		);
	}

	/**
	 * Calculate tax amount.
	 *
	 * @param float $amount Amount to calculate tax on.
	 * @return float
	 */
	private function calculateTax( float $amount ): float {
		// Use WooCommerce tax settings if available.
		if ( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
			// For now, return 0 as tax calculation is complex.
			// Full implementation would use WC_Tax class.
			return 0.0;
		}

		return 0.0;
	}

	/**
	 * Build order summary message.
	 *
	 * @param array $cart          Cart data.
	 * @param array $checkout_data Checkout data.
	 * @param array $totals        Calculated totals.
	 * @return \WCH_Message_Builder
	 */
	private function buildOrderSummaryMessage( array $cart, array $checkout_data, array $totals ): \WCH_Message_Builder {
		$message = $this->message_builder->create();

		// Build summary text.
		$summary = "📋 *Order Summary*\n\n";

		// Items.
		$summary .= "*Items:*\n";
		foreach ( $cart['items'] as $item ) {
			$name     = $item['name'] ?? __( 'Product', 'whatsapp-commerce-hub' );
			$qty      = $item['quantity'] ?? 1;
			$price    = $this->formatPrice( ( $item['price'] ?? 0 ) * $qty );
			$summary .= "• {$name} x{$qty} - {$price}\n";
		}
		$summary .= "\n";

		// Address.
		$address         = $checkout_data['shipping_address'] ?? array();
		$address_display = $this->address_service->formatDisplay( $address );
		$summary        .= "*Shipping to:*\n{$address_display}\n\n";

		// Shipping method.
		$shipping       = $checkout_data['shipping_method'] ?? array();
		$shipping_label = $shipping['label'] ?? __( 'Standard Shipping', 'whatsapp-commerce-hub' );
		$summary       .= "*Shipping:* {$shipping_label}\n";

		// Payment method.
		$payment       = $checkout_data['payment_method'] ?? array();
		$payment_label = $payment['label'] ?? __( 'Cash on Delivery', 'whatsapp-commerce-hub' );
		$summary      .= "*Payment:* {$payment_label}\n\n";

		// Totals.
		$summary .= "*Order Total:*\n";
		$summary .= 'Subtotal: ' . $this->formatPrice( $totals['subtotal'] ) . "\n";

		if ( $totals['discount'] > 0 ) {
			$summary .= 'Discount: -' . $this->formatPrice( $totals['discount'] ) . "\n";
		}

		if ( $totals['shipping'] > 0 ) {
			$summary .= 'Shipping: ' . $this->formatPrice( $totals['shipping'] ) . "\n";
		}

		if ( $totals['tax'] > 0 ) {
			$summary .= 'Tax: ' . $this->formatPrice( $totals['tax'] ) . "\n";
		}

		$summary .= '*Total: ' . $this->formatPrice( $totals['total'] ) . '*';

		$message->body( $summary );

		// Add action buttons.
		$message->button(
			'reply',
			array(
				'id'    => 'confirm_order',
				'title' => __( 'Confirm Order', 'whatsapp-commerce-hub' ),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'edit_address',
				'title' => __( 'Edit Address', 'whatsapp-commerce-hub' ),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'cancel_checkout',
				'title' => __( 'Cancel', 'whatsapp-commerce-hub' ),
			)
		);

		return $message;
	}
}
