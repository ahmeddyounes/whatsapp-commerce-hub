<?php
/**
 * Address Service
 *
 * Handles address formatting, validation, and normalization.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AddressService
 *
 * Implementation of address formatting and validation operations.
 */
class AddressService implements AddressServiceInterface {

	/**
	 * Required address fields for validation.
	 */
	private const REQUIRED_FIELDS = array( 'street', 'city', 'country' );

	/**
	 * All possible address fields.
	 */
	private const ALL_FIELDS = array(
		'name',
		'street',
		'street_2',
		'city',
		'state',
		'postal_code',
		'country',
		'phone',
		'email',
	);

	/**
	 * Format address as a single-line summary.
	 *
	 * @param array $address Address data.
	 * @return string Formatted address summary.
	 */
	public function formatSummary( array $address ): string {
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
			$country = $this->getCountryName( $address['country'] );
			$parts[] = $country;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Format address for multi-line display.
	 *
	 * @param array $address Address data.
	 * @return string Formatted address with newlines.
	 */
	public function formatDisplay( array $address ): string {
		$parts = array();

		if ( ! empty( $address['name'] ) ) {
			$parts[] = $address['name'];
		}

		if ( ! empty( $address['street'] ) ) {
			$parts[] = $address['street'];
		}

		if ( ! empty( $address['street_2'] ) ) {
			$parts[] = $address['street_2'];
		}

		// Build city line.
		$city_line = array();
		if ( ! empty( $address['city'] ) ) {
			$city_line[] = $address['city'];
		}
		if ( ! empty( $address['state'] ) ) {
			$state_name = $this->getStateName(
				$address['state'],
				$address['country'] ?? ''
			);
			$city_line[] = $state_name;
		}
		if ( ! empty( $address['postal_code'] ) ) {
			$city_line[] = $address['postal_code'];
		}
		if ( ! empty( $city_line ) ) {
			$parts[] = implode( ', ', $city_line );
		}

		if ( ! empty( $address['country'] ) ) {
			$parts[] = $this->getCountryName( $address['country'] );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Format address for WooCommerce order.
	 *
	 * @param array  $address Address data.
	 * @param string $type    Address type: 'billing' or 'shipping'.
	 * @return array WooCommerce address format.
	 */
	public function formatForWooCommerce( array $address, string $type = 'shipping' ): array {
		$prefix = $type . '_';
		$normalized = $this->normalize( $address );

		// Split name into first/last if present.
		$first_name = '';
		$last_name = '';
		if ( ! empty( $normalized['name'] ) ) {
			$name_parts = explode( ' ', $normalized['name'], 2 );
			$first_name = $name_parts[0];
			$last_name = $name_parts[1] ?? '';
		}

		$wc_address = array(
			$prefix . 'first_name' => $first_name,
			$prefix . 'last_name'  => $last_name,
			$prefix . 'address_1'  => $normalized['street'] ?? '',
			$prefix . 'address_2'  => $normalized['street_2'] ?? '',
			$prefix . 'city'       => $normalized['city'] ?? '',
			$prefix . 'state'      => $normalized['state'] ?? '',
			$prefix . 'postcode'   => $normalized['postal_code'] ?? '',
			$prefix . 'country'    => $normalized['country'] ?? '',
		);

		// Add phone and email for billing address.
		if ( 'billing' === $type ) {
			$wc_address[ $prefix . 'phone' ] = $normalized['phone'] ?? '';
			$wc_address[ $prefix . 'email' ] = $normalized['email'] ?? '';
		}

		return $wc_address;
	}

	/**
	 * Validate address data.
	 *
	 * @param array $address Address data to validate.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $address ): array {
		$errors = array();

		// Check required fields.
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( empty( $address[ $field ] ) ) {
				$errors[ $field ] = sprintf(
					/* translators: %s: field name */
					__( '%s is required', 'whatsapp-commerce-hub' ),
					ucfirst( str_replace( '_', ' ', $field ) )
				);
			}
		}

		// Validate country code format (ISO 3166-1 alpha-2).
		if ( ! empty( $address['country'] ) && ! preg_match( '/^[A-Z]{2}$/i', $address['country'] ) ) {
			$errors['country'] = __( 'Invalid country code format', 'whatsapp-commerce-hub' );
		}

		// Validate postal code if country is known.
		if ( ! empty( $address['postal_code'] ) && ! empty( $address['country'] ) ) {
			if ( ! $this->validatePostalCode( $address['postal_code'], $address['country'] ) ) {
				$errors['postal_code'] = __( 'Invalid postal code format', 'whatsapp-commerce-hub' );
			}
		}

		// Validate email if present.
		if ( ! empty( $address['email'] ) && ! is_email( $address['email'] ) ) {
			$errors['email'] = __( 'Invalid email address', 'whatsapp-commerce-hub' );
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}

	/**
	 * Normalize address data.
	 *
	 * @param array $address Raw address data.
	 * @return array Normalized address data.
	 */
	public function normalize( array $address ): array {
		$normalized = array();

		foreach ( self::ALL_FIELDS as $field ) {
			$value = $address[ $field ] ?? '';

			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			// Uppercase country code.
			if ( 'country' === $field && ! empty( $value ) ) {
				$value = strtoupper( $value );
			}

			// Uppercase state code.
			if ( 'state' === $field && ! empty( $value ) && strlen( $value ) <= 3 ) {
				$value = strtoupper( $value );
			}

			$normalized[ $field ] = $value;
		}

		return $normalized;
	}

	/**
	 * Convert address to array with all expected keys.
	 *
	 * @param array $address Partial address data.
	 * @return array Complete address array.
	 */
	public function toArray( array $address ): array {
		$result = array();

		foreach ( self::ALL_FIELDS as $field ) {
			$result[ $field ] = $address[ $field ] ?? '';
		}

		return $result;
	}

	/**
	 * Parse address from a text string.
	 *
	 * @param string $text Free-form address text.
	 * @return array Parsed address data.
	 */
	public function fromText( string $text ): array {
		$address = $this->toArray( array() );

		// Clean up the text.
		$text = trim( $text );
		if ( empty( $text ) ) {
			return $address;
		}

		// Split by newlines or commas.
		$lines = preg_split( '/[\n,]+/', $text );
		$lines = array_map( 'trim', $lines );
		$lines = array_filter( $lines );

		if ( empty( $lines ) ) {
			return $address;
		}

		// First line is typically the street.
		$address['street'] = array_shift( $lines );

		// Last line might be country.
		if ( count( $lines ) > 0 ) {
			$last = end( $lines );
			// Check if it looks like a country code or name.
			if ( preg_match( '/^[A-Z]{2}$/i', $last ) || strlen( $last ) > 3 ) {
				$address['country'] = array_pop( $lines );
			}
		}

		// Try to parse remaining lines.
		foreach ( $lines as $line ) {
			// Look for postal code pattern.
			if ( preg_match( '/\b(\d{5}(?:-\d{4})?|\d{6}|[A-Z]\d[A-Z]\s?\d[A-Z]\d)\b/i', $line, $matches ) ) {
				$address['postal_code'] = $matches[1];
				$line = str_replace( $matches[0], '', $line );
			}

			// Look for state abbreviation.
			if ( preg_match( '/\b([A-Z]{2})\b/', $line, $matches ) ) {
				$address['state'] = $matches[1];
				$line = str_replace( $matches[0], '', $line );
			}

			// Remaining text is likely city.
			$line = trim( $line, ', ' );
			if ( ! empty( $line ) && empty( $address['city'] ) ) {
				$address['city'] = $line;
			}
		}

		return $address;
	}

	/**
	 * Get country name from country code.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @return string Country name.
	 */
	public function getCountryName( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );

		// Use WooCommerce countries if available.
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country_code ] ) ) {
				return $countries[ $country_code ];
			}
		}

		// Fallback to common countries.
		$common_countries = array(
			'US' => 'United States',
			'CA' => 'Canada',
			'GB' => 'United Kingdom',
			'UK' => 'United Kingdom',
			'AU' => 'Australia',
			'DE' => 'Germany',
			'FR' => 'France',
			'IN' => 'India',
			'BR' => 'Brazil',
			'MX' => 'Mexico',
			'JP' => 'Japan',
			'CN' => 'China',
			'AE' => 'United Arab Emirates',
			'SA' => 'Saudi Arabia',
			'EG' => 'Egypt',
			'NG' => 'Nigeria',
			'ZA' => 'South Africa',
			'SG' => 'Singapore',
			'MY' => 'Malaysia',
			'PH' => 'Philippines',
			'ID' => 'Indonesia',
		);

		return $common_countries[ $country_code ] ?? $country_code;
	}

	/**
	 * Get state/province name from state code.
	 *
	 * @param string $state_code   State code.
	 * @param string $country_code Country code.
	 * @return string State name.
	 */
	public function getStateName( string $state_code, string $country_code ): string {
		$state_code = strtoupper( trim( $state_code ) );
		$country_code = strtoupper( trim( $country_code ) );

		// Use WooCommerce states if available.
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( $country_code );
			if ( is_array( $states ) && isset( $states[ $state_code ] ) ) {
				return $states[ $state_code ];
			}
		}

		return $state_code;
	}

	/**
	 * Validate postal code format for a given country.
	 *
	 * @param string $postal_code  Postal code to validate.
	 * @param string $country_code Country code.
	 * @return bool Whether the postal code is valid.
	 */
	private function validatePostalCode( string $postal_code, string $country_code ): bool {
		$country_code = strtoupper( $country_code );
		$postal_code = trim( $postal_code );

		// Country-specific patterns.
		$patterns = array(
			'US' => '/^\d{5}(-\d{4})?$/',
			'CA' => '/^[A-Z]\d[A-Z]\s?\d[A-Z]\d$/i',
			'GB' => '/^[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}$/i',
			'DE' => '/^\d{5}$/',
			'FR' => '/^\d{5}$/',
			'IN' => '/^\d{6}$/',
			'BR' => '/^\d{5}-?\d{3}$/',
			'AU' => '/^\d{4}$/',
			'JP' => '/^\d{3}-?\d{4}$/',
		);

		// If no specific pattern, allow any alphanumeric.
		if ( ! isset( $patterns[ $country_code ] ) ) {
			return preg_match( '/^[A-Z0-9\s\-]{3,10}$/i', $postal_code ) === 1;
		}

		return preg_match( $patterns[ $country_code ], $postal_code ) === 1;
	}
}
