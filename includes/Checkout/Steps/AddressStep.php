<?php
/**
 * Address Step
 *
 * Handles address collection during checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Checkout\Steps;

use WhatsAppCommerceHub\Checkout\AbstractStep;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AddressStep
 *
 * Handles shipping address collection.
 */
class AddressStep extends AbstractStep {

	/**
	 * Get the step identifier.
	 *
	 * @return string
	 */
	public function getStepId(): string {
		return 'address';
	}

	/**
	 * Get the next step identifier.
	 *
	 * @return string|null
	 */
	public function getNextStep(): ?string {
		return 'shipping';
	}

	/**
	 * Get the previous step identifier.
	 *
	 * @return string|null
	 */
	public function getPreviousStep(): ?string {
		return null; // First step.
	}

	/**
	 * Get the step title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return __( 'Shipping Address', 'whatsapp-commerce-hub' );
	}

	/**
	 * Execute the step (render the address selection UI).
	 *
	 * @param array $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function execute( array $context ): CheckoutResponse {
		try {
			$this->log( 'Requesting address', [ 'phone' => $this->getCustomerPhone( $context ) ] );

			$customer        = $this->getCustomer( $context );
			$saved_addresses = $this->getSavedAddresses( $customer );

			if ( ! empty( $saved_addresses ) ) {
				$message = $this->buildSavedAddressesMessage( $saved_addresses );
			} else {
				$message = $this->buildNewAddressPrompt();
			}

			return $this->success( [ $message ] );

		} catch ( \Throwable $e ) {
			$this->logError( 'Error requesting address', [ 'error' => $e->getMessage() ] );

			return $this->failure(
				$e->getMessage(),
				'address_request_failed',
				[ $this->errorMessage( __( 'Sorry, we could not process your address request. Please try again.', 'whatsapp-commerce-hub' ) ) ]
			);
		}
	}

	/**
	 * Process user input for address selection.
	 *
	 * @param string $input   User's input.
	 * @param array  $context Checkout context.
	 * @return CheckoutResponse
	 */
	public function processInput( string $input, array $context ): CheckoutResponse {
		try {
			$this->log(
				'Processing address input',
				[
					'phone'        => $this->getCustomerPhone( $context ),
					'input_length' => strlen( $input ),
				]
			);

			$address = null;

			// Check if this is a saved address selection.
			if ( preg_match( '/^saved_address_(\d+)$/', $input, $matches ) ) {
				$address = $this->loadSavedAddress( (int) $matches[1], $context );

				if ( ! $address ) {
					return $this->failure(
						__( 'Saved address not found', 'whatsapp-commerce-hub' ),
						'saved_address_not_found',
						[
							$this->errorMessage(
								__( 'Sorry, we could not load that saved address. Please try again.', 'whatsapp-commerce-hub' )
							),
						]
					);
				}
			} elseif ( 'new_address' === $input ) {
				// Prompt for new address entry.
				return $this->success( [ $this->buildNewAddressPrompt() ], [], $this->getStepId() );
			} else {
				// Parse address from text input.
				$address = $this->address_service->fromText( $input );
			}

			// Validate address completeness.
			$validation = $this->validate( $address, $context );

			if ( ! $validation['is_valid'] ) {
				$error_message = $this->formatValidationErrors( $validation['errors'] );

				return $this->failure(
					__( 'Address validation failed', 'whatsapp-commerce-hub' ),
					'address_validation_failed',
					[ $this->errorMessage( $error_message ) ]
				);
			}

			// Return success with address data for storage.
			return $this->success(
				[],
				[],
				$this->getNextStep(),
				[ 'shipping_address' => $address ]
			);

		} catch ( \Throwable $e ) {
			$this->logError( 'Error processing address input', [ 'error' => $e->getMessage() ] );

			return $this->failure(
				$e->getMessage(),
				'address_processing_failed',
				[ $this->errorMessage( __( 'Sorry, we could not process your address. Please try again.', 'whatsapp-commerce-hub' ) ) ]
			);
		}
	}

	/**
	 * Validate address data.
	 *
	 * @param array $data    Address data.
	 * @param array $context Checkout context.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $data, array $context ): array {
		return $this->address_service->validate( $data );
	}

	/**
	 * Get saved addresses from customer profile.
	 *
	 * @param object|null $customer Customer profile.
	 * @return array
	 */
	private function getSavedAddresses( $customer ): array {
		if ( ! $customer || empty( $customer->saved_addresses ) ) {
			return [];
		}

		if ( is_array( $customer->saved_addresses ) ) {
			return $customer->saved_addresses;
		}

		return [];
	}

	/**
	 * Load a saved address by index.
	 *
	 * @param int   $index   Address index.
	 * @param array $context Checkout context.
	 * @return array|null
	 */
	private function loadSavedAddress( int $index, array $context ): ?array {
		$customer        = $this->getCustomer( $context );
		$saved_addresses = $this->getSavedAddresses( $customer );

		return $saved_addresses[ $index ] ?? null;
	}

	/**
	 * Build message showing saved addresses.
	 *
	 * @param array $addresses Saved addresses.
	 * @return MessageBuilder
	 */
	private function buildSavedAddressesMessage( array $addresses ): MessageBuilder {
		$message = $this->message_builder->create();
		$message->body( __( '📍 Select a shipping address or add a new one:', 'whatsapp-commerce-hub' ) );

		$rows = [];

		foreach ( $addresses as $index => $address ) {
			$summary = $this->address_service->formatSummary( $address );
			$rows[]  = [
				'id'          => 'saved_address_' . $index,
				'title'       => $address['name'] ?? __( 'Address', 'whatsapp-commerce-hub' ) . ' ' . ( $index + 1 ),
				'description' => mb_substr( $summary, 0, 72 ),
			];
		}

		// Add option for new address.
		$rows[] = [
			'id'          => 'new_address',
			'title'       => __( '+ Add New Address', 'whatsapp-commerce-hub' ),
			'description' => __( 'Enter a new shipping address', 'whatsapp-commerce-hub' ),
		];

		$message->section( __( 'Addresses', 'whatsapp-commerce-hub' ), $rows );

		return $message;
	}

	/**
	 * Build prompt for new address entry.
	 *
	 * @return MessageBuilder
	 */
	private function buildNewAddressPrompt(): MessageBuilder {
		$prompt = __(
			"📍 Please provide your shipping address:\n\n" .
			"Include:\n" .
			"• Street address\n" .
			"• City\n" .
			"• State/Province\n" .
			"• Postal/ZIP code\n" .
			"• Country\n\n" .
			"Example:\n" .
			"123 Main Street\n" .
			"New York, NY 10001\n" .
			'United States',
			'whatsapp-commerce-hub'
		);

		return $this->message_builder->text( $prompt );
	}

	/**
	 * Format validation errors for display.
	 *
	 * @param array $errors Validation errors.
	 * @return string
	 */
	private function formatValidationErrors( array $errors ): string {
		$message = __( "Address incomplete:\n\n", 'whatsapp-commerce-hub' );

		foreach ( $errors as $field => $error ) {
			$message .= '• ' . $error . "\n";
		}

		$message .= "\n" . __( 'Please provide a complete address.', 'whatsapp-commerce-hub' );

		return $message;
	}
}
