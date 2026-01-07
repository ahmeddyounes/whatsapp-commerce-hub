<?php
/**
 * WCH Address Parser Class
 *
 * Parses and validates shipping addresses from text input.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Address_Parser
 *
 * Handles parsing of addresses from free-form text into structured components.
 * Extracts: name, street, city, state, postal code, and country.
 */
class WCH_Address_Parser {
	/**
	 * Parse address from text input
	 *
	 * Attempts to extract address components from multi-line or formatted text.
	 *
	 * @param string $text Address text.
	 * @return array Parsed address with components.
	 */
	public static function parse( $text ) {
		// Clean and split into lines.
		$text = trim( $text );
		$lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );

		// Initialize address structure.
		$address = array(
			'name' => '',
			'street' => '',
			'city' => '',
			'state' => '',
			'postal_code' => '',
			'country' => '',
		);

		if ( empty( $lines ) ) {
			return $address;
		}

		// Strategy: Work backwards from the end.
		// Last line is usually country.
		// Second to last is usually city, state, postal code.
		// Everything else is street address (and possibly name).

		$line_count = count( $lines );

		// Extract country (last line).
		if ( $line_count > 0 ) {
			$last_line = array_pop( $lines );
			$address['country'] = self::parse_country( $last_line );

			// If country not recognized, treat as part of address.
			if ( empty( $address['country'] ) ) {
				$lines[] = $last_line;
			}
		}

		// Extract city, state, postal code (second to last or last remaining line).
		if ( count( $lines ) > 0 ) {
			$location_line = array_pop( $lines );
			$location_data = self::parse_location_line( $location_line );

			$address['city'] = $location_data['city'];
			$address['state'] = $location_data['state'];
			$address['postal_code'] = $location_data['postal_code'];

			// If nothing was extracted, add back to lines.
			if ( empty( $location_data['city'] ) && empty( $location_data['state'] ) && empty( $location_data['postal_code'] ) ) {
				$lines[] = $location_line;
			}
		}

		// Remaining lines are street address and possibly name.
		if ( count( $lines ) > 0 ) {
			// First line might be name if it looks like a person's name.
			$first_line = $lines[0];

			if ( self::looks_like_name( $first_line ) && count( $lines ) > 1 ) {
				$address['name'] = array_shift( $lines );
			}

			// Rest is street address.
			if ( ! empty( $lines ) ) {
				$address['street'] = implode( "\n", $lines );
			}
		}

		// Clean up all fields.
		foreach ( $address as $key => $value ) {
			$address[ $key ] = trim( $value );
		}

		return $address;
	}

	/**
	 * Parse location line for city, state, and postal code
	 *
	 * Handles formats like:
	 * - "New York, NY 10001"
	 * - "San Francisco, CA, 94102"
	 * - "Toronto ON M5V 3A8"
	 * - "London SW1A 1AA"
	 *
	 * @param string $line Location line.
	 * @return array Parsed location data.
	 */
	private static function parse_location_line( $line ) {
		$data = array(
			'city' => '',
			'state' => '',
			'postal_code' => '',
		);

		// Try to extract postal code first (most distinctive pattern).
		$postal_patterns = array(
			'/\b(\d{5}(?:-\d{4})?)\b/', // US ZIP: 12345 or 12345-6789
			'/\b([A-Z]\d[A-Z]\s?\d[A-Z]\d)\b/i', // Canadian: A1A 1A1 or A1A1A1
			'/\b([A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2})\b/i', // UK: SW1A 1AA
			'/\b(\d{4,6})\b/', // Generic 4-6 digits
		);

		foreach ( $postal_patterns as $pattern ) {
			if ( preg_match( $pattern, $line, $matches ) ) {
				$data['postal_code'] = $matches[1];
				// Remove postal code from line for further parsing.
				$line = str_replace( $matches[0], '', $line );
				break;
			}
		}

		// Split by comma.
		$parts = array_map( 'trim', explode( ',', $line ) );
		$parts = array_filter( $parts );

		if ( count( $parts ) === 1 ) {
			// Single part - could be "City State" or just "City".
			$single = trim( $parts[0] );

			// Try to split by whitespace to find state code.
			$words = preg_split( '/\s+/', $single );

			if ( count( $words ) >= 2 ) {
				// Check if last word is a state code (2 letters).
				$last_word = end( $words );
				if ( strlen( $last_word ) === 2 && ctype_alpha( $last_word ) ) {
					$data['state'] = $last_word;
					array_pop( $words );
					$data['city'] = implode( ' ', $words );
				} else {
					$data['city'] = $single;
				}
			} else {
				$data['city'] = $single;
			}
		} elseif ( count( $parts ) === 2 ) {
			// Two parts: "City, State" or "City, State Code".
			$data['city'] = trim( $parts[0] );

			$second_part = trim( $parts[1] );
			// Check if it's a state code or full state name.
			$data['state'] = $second_part;
		} elseif ( count( $parts ) >= 3 ) {
			// Three or more parts: "City, State, ..." - take first two.
			$data['city'] = trim( $parts[0] );
			$data['state'] = trim( $parts[1] );
		}

		return $data;
	}

	/**
	 * Parse country from text
	 *
	 * Attempts to identify country name or code.
	 *
	 * @param string $text Country text.
	 * @return string Country code or name.
	 */
	private static function parse_country( $text ) {
		$text = trim( $text );

		// Common country mappings.
		$countries = array(
			'USA' => 'US',
			'United States' => 'US',
			'United States of America' => 'US',
			'US' => 'US',
			'Canada' => 'CA',
			'CA' => 'CA',
			'United Kingdom' => 'GB',
			'UK' => 'GB',
			'GB' => 'GB',
			'India' => 'IN',
			'IN' => 'IN',
			'Brazil' => 'BR',
			'BR' => 'BR',
			'Australia' => 'AU',
			'AU' => 'AU',
			'Germany' => 'DE',
			'DE' => 'DE',
			'France' => 'FR',
			'FR' => 'FR',
			'Italy' => 'IT',
			'IT' => 'IT',
			'Spain' => 'ES',
			'ES' => 'ES',
			'Mexico' => 'MX',
			'MX' => 'MX',
		);

		// Check for exact match (case-insensitive).
		foreach ( $countries as $name => $code ) {
			if ( strcasecmp( $text, $name ) === 0 ) {
				return $code;
			}
		}

		// If 2-letter code, return uppercase.
		if ( strlen( $text ) === 2 && ctype_alpha( $text ) ) {
			return strtoupper( $text );
		}

		// If it looks like a country name (alphabetic, reasonable length).
		if ( ctype_alpha( str_replace( ' ', '', $text ) ) && strlen( $text ) >= 3 ) {
			return $text;
		}

		// Not recognized as country.
		return '';
	}

	/**
	 * Check if text looks like a person's name
	 *
	 * Heuristic: Short (2-5 words), mostly alphabetic, no numbers.
	 *
	 * @param string $text Text to check.
	 * @return bool Whether it looks like a name.
	 */
	private static function looks_like_name( $text ) {
		// Remove punctuation for analysis.
		$clean = preg_replace( '/[^a-zA-Z\s]/', '', $text );
		$words = preg_split( '/\s+/', trim( $clean ) );
		$words = array_filter( $words );

		// Name typically has 2-4 words.
		if ( count( $words ) < 2 || count( $words ) > 5 ) {
			return false;
		}

		// All words should be mostly alphabetic.
		foreach ( $words as $word ) {
			if ( ! ctype_alpha( $word ) ) {
				return false;
			}
		}

		// Should not contain numbers.
		if ( preg_match( '/\d/', $text ) ) {
			return false;
		}

		// Likely a name.
		return true;
	}

	/**
	 * Validate address completeness
	 *
	 * Checks that required fields are present.
	 *
	 * @param array $address Address data.
	 * @return array Validation result with 'valid' boolean and 'message' string.
	 */
	public static function validate( $address ) {
		$required_fields = array(
			'street' => 'Street address',
			'city' => 'City',
			'postal_code' => 'Postal code',
			'country' => 'Country',
		);

		$missing_fields = array();

		foreach ( $required_fields as $field => $label ) {
			if ( empty( $address[ $field ] ) ) {
				$missing_fields[] = $label;
			}
		}

		if ( ! empty( $missing_fields ) ) {
			return array(
				'valid' => false,
				'message' => 'Missing required fields: ' . implode( ', ', $missing_fields ),
			);
		}

		// Additional validation: postal code format.
		if ( ! self::validate_postal_code( $address['postal_code'], $address['country'] ) ) {
			return array(
				'valid' => false,
				'message' => 'Invalid postal code format for ' . $address['country'],
			);
		}

		return array(
			'valid' => true,
			'message' => 'Address is valid',
		);
	}

	/**
	 * Validate postal code format
	 *
	 * Basic validation for common postal code formats.
	 *
	 * @param string $postal_code Postal code.
	 * @param string $country Country code.
	 * @return bool Whether postal code is valid.
	 */
	private static function validate_postal_code( $postal_code, $country ) {
		if ( empty( $postal_code ) ) {
			return false;
		}

		// Country-specific validation.
		switch ( strtoupper( $country ) ) {
			case 'US':
				// US ZIP: 12345 or 12345-6789.
				return (bool) preg_match( '/^\d{5}(-\d{4})?$/', $postal_code );

			case 'CA':
				// Canadian: A1A 1A1 or A1A1A1.
				return (bool) preg_match( '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i', $postal_code );

			case 'GB':
			case 'UK':
				// UK: SW1A 1AA, SW1A1AA, etc.
				return (bool) preg_match( '/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i', $postal_code );

			case 'IN':
				// India: 6 digits.
				return (bool) preg_match( '/^\d{6}$/', $postal_code );

			case 'BR':
				// Brazil: 12345-678 or 12345678.
				return (bool) preg_match( '/^\d{5}-?\d{3}$/', $postal_code );

			case 'AU':
				// Australia: 4 digits.
				return (bool) preg_match( '/^\d{4}$/', $postal_code );

			default:
				// Generic: Accept 3-10 alphanumeric characters.
				return (bool) preg_match( '/^[A-Z0-9\s-]{3,10}$/i', $postal_code );
		}
	}

	/**
	 * Format address for display
	 *
	 * Converts address array to formatted multi-line string.
	 *
	 * @param array $address Address data.
	 * @return string Formatted address.
	 */
	public static function format_display( $address ) {
		$parts = array();

		if ( ! empty( $address['name'] ) ) {
			$parts[] = $address['name'];
		}

		if ( ! empty( $address['street'] ) ) {
			$parts[] = $address['street'];
		}

		$city_line = array();
		if ( ! empty( $address['city'] ) ) {
			$city_line[] = $address['city'];
		}
		if ( ! empty( $address['state'] ) ) {
			$city_line[] = $address['state'];
		}
		if ( ! empty( $address['postal_code'] ) ) {
			$city_line[] = $address['postal_code'];
		}
		if ( ! empty( $city_line ) ) {
			$parts[] = implode( ', ', $city_line );
		}

		if ( ! empty( $address['country'] ) ) {
			$parts[] = $address['country'];
		}

		return implode( "\n", $parts );
	}

	/**
	 * Format address as single line
	 *
	 * Converts address array to single-line string.
	 *
	 * @param array $address Address data.
	 * @return string Formatted address.
	 */
	public static function format_single_line( $address ) {
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
}
