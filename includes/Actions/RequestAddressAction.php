<?php
/**
 * Request Address Action
 *
 * Requests shipping address from customer.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RequestAddressAction
 *
 * Handles address collection with saved addresses support.
 */
class RequestAddressAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'request_address';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			$this->log( 'Requesting address', [ 'phone' => $phone ] );

			// Get customer profile.
			$customer = $this->getCustomerProfile( $phone );

			// Check for saved addresses.
			$savedAddresses = [];
			if ( $customer && ! empty( $customer->saved_addresses ) && is_array( $customer->saved_addresses ) ) {
				$savedAddresses = $customer->saved_addresses;
			}

			// Build message based on saved addresses.
			if ( ! empty( $savedAddresses ) ) {
				$message = $this->buildSavedAddressesMessage( $savedAddresses );
			} else {
				$message = $this->buildNewAddressPrompt();
			}

			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_address' => true ]
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error requesting address', [ 'error' => $e->getMessage() ], 'error' );
			return $this->error( __( 'Sorry, we could not process your address. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Build message with saved addresses.
	 *
	 * @param array $addresses Saved addresses.
	 * @return \WCH_Message_Builder
	 */
	private function buildSavedAddressesMessage( array $addresses ): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		$message->header( __( 'Shipping Address', 'whatsapp-commerce-hub' ) );
		$message->body( __( 'Please select a saved address or enter a new one:', 'whatsapp-commerce-hub' ) );

		// Build address rows.
		$rows = [];

		foreach ( $addresses as $index => $address ) {
			$addressText = $this->formatAddressSummary( $address );
			$label       = ! empty( $address['label'] )
				? $address['label']
				: sprintf( __( 'Address %d', 'whatsapp-commerce-hub' ), $index + 1 );

			$rows[] = [
				'id'          => 'saved_address_' . $index,
				'title'       => $label,
				'description' => wp_trim_words( $addressText, 10, '...' ),
			];

			// Limit to 9 addresses to leave room for "new address" option.
			if ( count( $rows ) >= 9 ) {
				break;
			}
		}

		// Add "Enter New Address" option.
		$rows[] = [
			'id'          => 'new_address',
			'title'       => __( 'Enter New Address', 'whatsapp-commerce-hub' ),
			'description' => __( 'Provide a different address', 'whatsapp-commerce-hub' ),
		];

		$message->section( __( 'Select Address', 'whatsapp-commerce-hub' ), $rows );

		return $message;
	}

	/**
	 * Build new address prompt.
	 *
	 * @return \WCH_Message_Builder
	 */
	private function buildNewAddressPrompt(): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		$text = sprintf(
			"%s\n\n%s\n• %s\n• %s\n• %s\n• %s\n• %s\n\n%s:\n%s",
			__( 'Please provide your shipping address.', 'whatsapp-commerce-hub' ),
			__( 'Include:', 'whatsapp-commerce-hub' ),
			__( 'Street address', 'whatsapp-commerce-hub' ),
			__( 'City', 'whatsapp-commerce-hub' ),
			__( 'State/Province', 'whatsapp-commerce-hub' ),
			__( 'Postal/ZIP code', 'whatsapp-commerce-hub' ),
			__( 'Country', 'whatsapp-commerce-hub' ),
			__( 'Example', 'whatsapp-commerce-hub' ),
			"123 Main Street\nApt 4B\nNew York, NY 10001\nUSA"
		);

		$message->text( $text );

		return $message;
	}

	/**
	 * Format address summary.
	 *
	 * @param array $address Address data.
	 * @return string Formatted address.
	 */
	private function formatAddressSummary( array $address ): string {
		$parts = [];

		if ( ! empty( $address['street'] ) ) {
			$parts[] = $address['street'];
		}

		if ( ! empty( $address['city'] ) ) {
			$parts[] = $address['city'];
		}

		if ( ! empty( $address['state'] ) ) {
			$parts[] = $address['state'];
		}

		if ( ! empty( $address['postal_code'] ) ) {
			$parts[] = $address['postal_code'];
		}

		if ( ! empty( $address['country'] ) ) {
			$parts[] = $address['country'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Validate address format.
	 *
	 * @param array $address Address data.
	 * @return array{valid: bool, message: string} Validation result.
	 */
	public static function validateAddress( array $address ): array {
		$requiredFields = [ 'street', 'city', 'postal_code', 'country' ];

		foreach ( $requiredFields as $field ) {
			if ( empty( $address[ $field ] ) ) {
				return [
					'valid'   => false,
					'message' => sprintf(
						__( 'Missing required field: %s', 'whatsapp-commerce-hub' ),
						$field
					),
				];
			}
		}

		return [
			'valid'   => true,
			'message' => __( 'Address is valid', 'whatsapp-commerce-hub' ),
		];
	}

	/**
	 * Parse address from text.
	 *
	 * @param string $text Address text.
	 * @return array Parsed address.
	 */
	public static function parseAddressFromText( string $text ): array {
		$lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );

		$address = [
			'street'      => '',
			'city'        => '',
			'state'       => '',
			'postal_code' => '',
			'country'     => '',
		];

		if ( isset( $lines[0] ) ) {
			$address['street'] = $lines[0];
		}

		// Try to parse last line as country.
		if ( count( $lines ) > 1 ) {
			$lastLine           = array_pop( $lines );
			$address['country'] = $lastLine;
		}

		// Second to last might be city, state, zip.
		if ( count( $lines ) > 1 ) {
			$locationLine = array_pop( $lines );

			// Try to extract postal code (simple pattern).
			if ( preg_match( '/\b(\d{5}(-\d{4})?)\b/', $locationLine, $matches ) ) {
				$address['postal_code'] = $matches[1];
				$locationLine           = str_replace( $matches[1], '', $locationLine );
			}

			// Remaining is city and state.
			$parts = array_map( 'trim', explode( ',', $locationLine ) );
			if ( ! empty( $parts[0] ) ) {
				$address['city'] = $parts[0];
			}
			if ( ! empty( $parts[1] ) ) {
				$address['state'] = $parts[1];
			}
		}

		// Any remaining lines add to street.
		if ( ! empty( $lines ) ) {
			$address['street'] .= "\n" . implode( "\n", $lines );
		}

		return $address;
	}
}
