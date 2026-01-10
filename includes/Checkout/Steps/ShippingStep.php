<?php
/**
 * Shipping Step
 *
 * Handles shipping method selection during checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Checkout\Steps;

use WhatsAppCommerceHub\Checkout\AbstractStep;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShippingStep
 *
 * Handles shipping method selection.
 */
class ShippingStep extends AbstractStep {

	/**
	 * Get the step identifier.
	 *
	 * @return string
	 */
	public function getStepId(): string {
		return 'shipping';
	}

	/**
	 * Get the next step identifier.
	 *
	 * @return string|null
	 */
	public function getNextStep(): ?string {
		return 'payment';
	}

	/**
	 * Get the previous step identifier.
	 *
	 * @return string|null
	 */
	public function getPreviousStep(): ?string {
		return 'address';
	}

	/**
	 * Get the step title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return __( 'Shipping Method', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if shipping can be skipped (e.g., digital products only).
	 *
	 * @param array $context Checkout context.
	 * @return bool
	 */
	public function canSkip( array $context ): bool {
		$cart = $this->getCart( $context );

		if ( empty( $cart['items'] ) ) {
			return false;
		}

		// Check if all items are virtual/downloadable.
		foreach ( $cart['items'] as $item ) {
			if ( empty( $item['is_virtual'] ) && empty( $item['is_downloadable'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Execute the step (show shipping options).
	 *
	 * @param array $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function execute( array $context ): CheckoutResponse {
		try {
			$this->log( 'Showing shipping methods', array( 'phone' => $this->getCustomerPhone( $context ) ) );

			$cart          = $this->getCart( $context );
			$checkout_data = $this->getCheckoutData( $context );
			$address       = $checkout_data['shipping_address'] ?? null;

			if ( ! $address ) {
				return $this->failure(
					__( 'Address not found', 'whatsapp-commerce-hub' ),
					'missing_address',
					array( $this->errorMessage( __( 'Address not found. Please start checkout again.', 'whatsapp-commerce-hub' ) ) )
				);
			}

			// Get available shipping methods.
			$shipping_methods = $this->calculateShippingMethods( $cart, $address );

			if ( empty( $shipping_methods ) ) {
				// Default to free shipping if none available.
				$shipping_methods = array(
					array(
						'id'    => 'free_shipping',
						'label' => __( 'Free Shipping', 'whatsapp-commerce-hub' ),
						'cost'  => 0.00,
					),
				);
			}

			// Build message with shipping options.
			$message = $this->buildShippingOptionsMessage( $shipping_methods );

			return $this->success(
				array( $message ),
				array( 'available_shipping_methods' => $shipping_methods )
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error showing shipping methods', array( 'error' => $e->getMessage() ) );

			return $this->failure(
				$e->getMessage(),
				'shipping_methods_failed',
				array( $this->errorMessage( __( 'Sorry, we could not load shipping methods. Please try again.', 'whatsapp-commerce-hub' ) ) )
			);
		}
	}

	/**
	 * Process user input for shipping selection.
	 *
	 * @param string $input   User's input.
	 * @param array  $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function processInput( string $input, array $context ): CheckoutResponse {
		try {
			$this->log(
				'Processing shipping selection',
				array(
					'phone' => $this->getCustomerPhone( $context ),
					'input' => $input,
				)
			);

			// Parse shipping method selection.
			if ( ! preg_match( '/^shipping_(.+)$/', $input, $matches ) ) {
				return $this->failure(
					__( 'Invalid shipping selection', 'whatsapp-commerce-hub' ),
					'invalid_shipping_selection',
					array( $this->errorMessage( __( 'Please select a valid shipping method.', 'whatsapp-commerce-hub' ) ) )
				);
			}

			$method_id     = $matches[1];
			$cart          = $this->getCart( $context );
			$checkout_data = $this->getCheckoutData( $context );
			$address       = $checkout_data['shipping_address'] ?? null;

			// Get available methods to validate selection.
			$shipping_methods = $this->calculateShippingMethods( $cart, $address );
			$selected_method  = null;

			foreach ( $shipping_methods as $method ) {
				if ( $method['id'] === $method_id ) {
					$selected_method = $method;
					break;
				}
			}

			// Allow free_shipping as default.
			if ( ! $selected_method && 'free_shipping' === $method_id ) {
				$selected_method = array(
					'id'    => 'free_shipping',
					'label' => __( 'Free Shipping', 'whatsapp-commerce-hub' ),
					'cost'  => 0.00,
				);
			}

			if ( ! $selected_method ) {
				return $this->failure(
					__( 'Shipping method not found', 'whatsapp-commerce-hub' ),
					'shipping_method_not_found',
					array(
						$this->errorMessage(
							__( 'The selected shipping method is not available. Please choose another.', 'whatsapp-commerce-hub' )
						),
					)
				);
			}

			return $this->success(
				array(),
				array(),
				$this->getNextStep(),
				array( 'shipping_method' => $selected_method )
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error processing shipping selection', array( 'error' => $e->getMessage() ) );

			return $this->failure(
				$e->getMessage(),
				'shipping_processing_failed',
				array(
					$this->errorMessage(
						__( 'Sorry, we could not process your shipping selection. Please try again.', 'whatsapp-commerce-hub' )
					),
				)
			);
		}
	}

	/**
	 * Validate shipping method selection.
	 *
	 * @param array $data    Shipping data.
	 * @param array $context Checkout context.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $data, array $context ): array {
		$errors = array();

		if ( empty( $data['id'] ) ) {
			$errors['shipping_method'] = __( 'Please select a shipping method', 'whatsapp-commerce-hub' );
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}

	/**
	 * Calculate available shipping methods.
	 *
	 * @param array $cart    Cart data.
	 * @param array $address Shipping address.
	 * @return array
	 */
	private function calculateShippingMethods( array $cart, array $address ): array {
		$methods = array();

		// Use WooCommerce shipping zones if available.
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$shipping_zones = \WC_Shipping_Zones::get_zones();

			foreach ( $shipping_zones as $zone ) {
				if ( isset( $zone['shipping_methods'] ) ) {
					foreach ( $zone['shipping_methods'] as $method ) {
						if ( 'yes' === $method->enabled ) {
							$cost = 0.00;

							if ( 'flat_rate' === $method->id && isset( $method->cost ) ) {
								$cost = floatval( $method->cost );
							}

							$methods[] = array(
								'id'    => $method->id . '_' . $method->instance_id,
								'label' => $method->get_title(),
								'cost'  => $cost,
							);
						}
					}
				}
			}
		}

		// Default free shipping if no methods found.
		if ( empty( $methods ) ) {
			$methods[] = array(
				'id'    => 'free_shipping',
				'label' => __( 'Free Shipping', 'whatsapp-commerce-hub' ),
				'cost'  => 0.00,
			);
		}

		return $methods;
	}

	/**
	 * Build shipping options message.
	 *
	 * @param array $methods Available shipping methods.
	 * @return \WCH_Message_Builder
	 */
	private function buildShippingOptionsMessage( array $methods ): \WCH_Message_Builder {
		$message = $this->message_builder->create();
		$message->header( 'text', __( '🚚 Shipping Method', 'whatsapp-commerce-hub' ) );
		$message->body( __( 'Choose your preferred shipping method:', 'whatsapp-commerce-hub' ) );

		$rows = array();

		foreach ( $methods as $method ) {
			$cost_display = $method['cost'] > 0
				? $this->formatPrice( $method['cost'] )
				: __( 'Free', 'whatsapp-commerce-hub' );

			$rows[] = array(
				'id'          => 'shipping_' . $method['id'],
				'title'       => $method['label'],
				'description' => sprintf( __( 'Cost: %s', 'whatsapp-commerce-hub' ), $cost_display ),
			);
		}

		$message->section( __( 'Shipping Methods', 'whatsapp-commerce-hub' ), $rows );

		return $message;
	}
}
