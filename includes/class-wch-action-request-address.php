<?php
/**
 * WCH Action: Request Address
 *
 * Request shipping address from customer.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_RequestAddress class
 *
 * Handles address collection:
 * - Shows saved addresses if available
 * - Allows selection or entry of new address
 * - Validates address format
 */
class WCH_Action_RequestAddress extends WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			$this->log( 'Requesting address', array( 'phone' => $conversation->customer_phone ) );

			// Get customer profile.
			$customer = $this->get_customer_profile( $conversation->customer_phone );

			// Check for saved addresses.
			$saved_addresses = array();
			if ( $customer && ! empty( $customer->saved_addresses ) ) {
				$saved_addresses = json_decode( $customer->saved_addresses, true );
			}

			// Build message based on saved addresses.
			if ( ! empty( $saved_addresses ) ) {
				$message = $this->build_saved_addresses_message( $saved_addresses );
			} else {
				$message = $this->build_new_address_prompt();
			}

			return WCH_Action_Result::success(
				array( $message ),
				null,
				array( 'awaiting_address' => true )
			);

		} catch ( Exception $e ) {
			$this->log( 'Error requesting address', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not process your address. Please try again.' );
		}
	}

	/**
	 * Build message with saved addresses
	 *
	 * @param array $addresses Saved addresses.
	 * @return WCH_Message_Builder
	 */
	private function build_saved_addresses_message( $addresses ) {
		$message = new WCH_Message_Builder();

		$message->header( 'Shipping Address' );
		$message->body( 'Please select a saved address or enter a new one:' );

		// Build address rows.
		$rows = array();

		foreach ( $addresses as $index => $address ) {
			$address_text = $this->format_address_summary( $address );

			$rows[] = array(
				'id'          => 'saved_address_' . $index,
				'title'       => ! empty( $address['label'] ) ? $address['label'] : 'Address ' . ( $index + 1 ),
				'description' => wp_trim_words( $address_text, 10, '...' ),
			);

			// Limit to 10 addresses.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		// Add "Enter New Address" option.
		$rows[] = array(
			'id'          => 'new_address',
			'title'       => 'Enter New Address',
			'description' => 'Provide a different address',
		);

		$message->section( 'Select Address', $rows );

		return $message;
	}

	/**
	 * Build new address prompt
	 *
	 * @return WCH_Message_Builder
	 */
	private function build_new_address_prompt() {
		$message = new WCH_Message_Builder();

		$text = "Please provide your shipping address.\n\n"
			. "Include:\n"
			. "• Street address\n"
			. "• City\n"
			. "• State/Province\n"
			. "• Postal/ZIP code\n"
			. "• Country\n\n"
			. 'Example:\n'
			. "123 Main Street\nApt 4B\nNew York, NY 10001\nUSA";

		$message->text( $text );

		return $message;
	}

	/**
	 * Format address summary
	 *
	 * @param array $address Address data.
	 * @return string Formatted address.
	 */
	private function format_address_summary( $address ) {
		$parts = array();

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
	 * Validate address format
	 *
	 * @param array $address Address data.
	 * @return array Validation result with 'valid' and 'message'.
	 */
	public static function validate_address( $address ) {
		$required_fields = array( 'street', 'city', 'postal_code', 'country' );

		foreach ( $required_fields as $field ) {
			if ( empty( $address[ $field ] ) ) {
				return array(
					'valid'   => false,
					'message' => sprintf( 'Missing required field: %s', $field ),
				);
			}
		}

		return array(
			'valid'   => true,
			'message' => 'Address is valid',
		);
	}

	/**
	 * Parse address from text
	 *
	 * @param string $text Address text.
	 * @return array Parsed address.
	 */
	public static function parse_address_from_text( $text ) {
		// Simple parsing - in production, use more sophisticated parsing.
		$lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );

		$address = array(
			'street'      => '',
			'city'        => '',
			'state'       => '',
			'postal_code' => '',
			'country'     => '',
		);

		if ( isset( $lines[0] ) ) {
			$address['street'] = $lines[0];
		}

		// Try to parse last line as country.
		if ( count( $lines ) > 1 ) {
			$last_line          = array_pop( $lines );
			$address['country'] = $last_line;
		}

		// Second to last might be city, state, zip.
		if ( count( $lines ) > 1 ) {
			$location_line = array_pop( $lines );

			// Try to extract postal code (simple pattern).
			if ( preg_match( '/\b(\d{5}(-\d{4})?)\b/', $location_line, $matches ) ) {
				$address['postal_code'] = $matches[1];
				$location_line          = str_replace( $matches[1], '', $location_line );
			}

			// Remaining is city and state.
			$parts = array_map( 'trim', explode( ',', $location_line ) );
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
