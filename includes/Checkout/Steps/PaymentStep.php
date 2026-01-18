<?php
/**
 * Payment Step
 *
 * Handles payment method selection during checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Checkout\Steps;

use WhatsAppCommerceHub\Checkout\AbstractStep;
use WhatsAppCommerceHub\Providers\PaymentServiceProvider;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentStep
 *
 * Handles payment method selection.
 */
class PaymentStep extends AbstractStep {

	/**
	 * Get the step identifier.
	 *
	 * @return string
	 */
	public function getStepId(): string {
		return 'payment';
	}

	/**
	 * Get the next step identifier.
	 *
	 * @return string|null
	 */
	public function getNextStep(): ?string {
		return 'review';
	}

	/**
	 * Get the previous step identifier.
	 *
	 * @return string|null
	 */
	public function getPreviousStep(): ?string {
		return 'shipping';
	}

	/**
	 * Get the step title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return __( 'Payment Method', 'whatsapp-commerce-hub' );
	}

	/**
	 * Execute the step (show payment options).
	 *
	 * @param array $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function execute( array $context ): CheckoutResponse {
		try {
			$this->log( 'Showing payment methods', [ 'phone' => $this->getCustomerPhone( $context ) ] );

			$checkout_data = $this->getCheckoutData( $context );
			$address       = $checkout_data['shipping_address'] ?? [];
			$country       = $address['country'] ?? '';

			// Get available payment methods based on country.
			$payment_methods = $this->getAvailablePaymentMethods( $country );

			if ( empty( $payment_methods ) ) {
				return $this->failure(
					__( 'No payment methods available', 'whatsapp-commerce-hub' ),
					'no_payment_methods',
					[ $this->errorMessage( __( 'Sorry, no payment methods are available for your region.', 'whatsapp-commerce-hub' ) ) ]
				);
			}

			$message = $this->buildPaymentOptionsMessage( $payment_methods );

			return $this->success(
				[ $message ],
				[ 'available_payment_methods' => $payment_methods ]
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error showing payment methods', [ 'error' => $e->getMessage() ] );

			return $this->failure(
				$e->getMessage(),
				'payment_methods_failed',
				[ $this->errorMessage( __( 'Sorry, we could not load payment methods. Please try again.', 'whatsapp-commerce-hub' ) ) ]
			);
		}
	}

	/**
	 * Process user input for payment selection.
	 *
	 * @param string $input   User's input.
	 * @param array  $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function processInput( string $input, array $context ): CheckoutResponse {
		try {
			$this->log(
				'Processing payment selection',
				[
					'phone' => $this->getCustomerPhone( $context ),
					'input' => $input,
				]
			);

			// Parse payment method selection.
			if ( ! preg_match( '/^payment_(.+)$/', $input, $matches ) ) {
				return $this->failure(
					__( 'Invalid payment selection', 'whatsapp-commerce-hub' ),
					'invalid_payment_selection',
					[ $this->errorMessage( __( 'Please select a valid payment method.', 'whatsapp-commerce-hub' ) ) ]
				);
			}

			$method_id     = $matches[1];
			$checkout_data = $this->getCheckoutData( $context );
			$address       = $checkout_data['shipping_address'] ?? [];
			$country       = $address['country'] ?? '';

			// Validate selection against available methods.
			$payment_methods = $this->getAvailablePaymentMethods( $country );
			$selected_method = null;

			foreach ( $payment_methods as $method ) {
				if ( $method['id'] === $method_id ) {
					$selected_method = $method;
					break;
				}
			}

			if ( ! $selected_method ) {
				return $this->failure(
					__( 'Payment method not found', 'whatsapp-commerce-hub' ),
					'payment_method_not_found',
					[
						$this->errorMessage(
							__( 'The selected payment method is not available. Please choose another.', 'whatsapp-commerce-hub' )
						),
					]
				);
			}

			return $this->success(
				[],
				[],
				$this->getNextStep(),
				[ 'payment_method' => $selected_method ]
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error processing payment selection', [ 'error' => $e->getMessage() ] );

			return $this->failure(
				$e->getMessage(),
				'payment_processing_failed',
				[ $this->errorMessage( __( 'Sorry, we could not process your payment selection. Please try again.', 'whatsapp-commerce-hub' ) ) ]
			);
		}
	}

	/**
	 * Validate payment method selection.
	 *
	 * @param array $data    Payment data.
	 * @param array $context Checkout context.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $data, array $context ): array {
		$errors = [];

		if ( empty( $data['id'] ) ) {
			$errors['payment_method'] = __( 'Please select a payment method', 'whatsapp-commerce-hub' );
		}

		return [
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		];
	}

	/**
	 * Get available payment methods based on country.
	 *
	 * @param string $country Country code.
	 * @return array
	 */
	private function getAvailablePaymentMethods( string $country ): array {
		try {
			$available = PaymentServiceProvider::getAvailableForCountry( $country );
		} catch ( \Throwable $e ) {
			return [];
		}

		$methods = [];

		if ( isset( $available['cod'] ) ) {
			$gateway   = $available['cod'];
			$methods[] = [
				'id'          => $gateway->getId(),
				'label'       => $gateway->getTitle(),
				'description' => $gateway->getDescription(),
				'fee'         => 0,
			];
			unset( $available['cod'] );
		}

		foreach ( $available as $gateway ) {
			$methods[] = [
				'id'          => $gateway->getId(),
				'label'       => $gateway->getTitle(),
				'description' => $gateway->getDescription(),
				'fee'         => 0,
			];
		}

		return $methods;
	}

	/**
	 * Build payment options message.
	 *
	 * @param array $methods Available payment methods.
	 * @return MessageBuilder
	 */
	private function buildPaymentOptionsMessage( array $methods ): MessageBuilder {
		$message = $this->message_builder->create();
		$message->header( 'text', __( '💳 Payment Method', 'whatsapp-commerce-hub' ) );
		$message->body( __( 'Choose how you would like to pay:', 'whatsapp-commerce-hub' ) );

		$rows = [];

		foreach ( $methods as $method ) {
			$rows[] = [
				'id'          => 'payment_' . $method['id'],
				'title'       => $method['label'],
				'description' => $method['description'],
			];
		}

		$message->section( __( 'Payment Methods', 'whatsapp-commerce-hub' ), $rows );

		return $message;
	}
}
