<?php
/**
 * Address Service Interface
 *
 * Contract for address formatting and validation operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface AddressServiceInterface
 *
 * Defines the contract for address formatting and validation operations.
 */
interface AddressServiceInterface {

	/**
	 * Format address as a single-line summary.
	 *
	 * Joins address parts with commas for compact display.
	 * Example: "123 Main St, New York, NY, 10001, US"
	 *
	 * @param array $address Address data with keys: street, city, state, postal_code, country.
	 * @return string Formatted address summary.
	 */
	public function formatSummary( array $address ): string;

	/**
	 * Format address for multi-line display.
	 *
	 * Creates a human-readable address with line breaks.
	 * Example:
	 * "John Doe
	 *  123 Main St
	 *  New York, NY, 10001
	 *  United States"
	 *
	 * @param array $address Address data with keys: name, street, city, state, postal_code, country.
	 * @return string Formatted address with newlines.
	 */
	public function formatDisplay( array $address ): string;

	/**
	 * Format address for WooCommerce order.
	 *
	 * Transforms address array into WooCommerce-compatible format.
	 *
	 * @param array  $address Address data.
	 * @param string $type    Address type: 'billing' or 'shipping'.
	 * @return array WooCommerce address format with prefixed keys.
	 */
	public function formatForWooCommerce( array $address, string $type = 'shipping' ): array;

	/**
	 * Validate address data.
	 *
	 * Checks required fields and validates format of postal codes, countries, etc.
	 *
	 * @param array $address Address data to validate.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $address ): array;

	/**
	 * Normalize address data.
	 *
	 * Trims whitespace, standardizes country codes, formats postal codes.
	 *
	 * @param array $address Raw address data.
	 * @return array Normalized address data.
	 */
	public function normalize( array $address ): array;

	/**
	 * Convert address to array (ensure all expected keys exist).
	 *
	 * @param array $address Partial address data.
	 * @return array Complete address array with all keys.
	 */
	public function toArray( array $address ): array;

	/**
	 * Parse address from a text string.
	 *
	 * Attempts to extract address components from free-form text.
	 *
	 * @param string $text Free-form address text.
	 * @return array Parsed address data.
	 */
	public function fromText( string $text ): array;

	/**
	 * Get country name from country code.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code.
	 * @return string Country name or the code if not found.
	 */
	public function getCountryName( string $country_code ): string;

	/**
	 * Get state/province name from state code.
	 *
	 * @param string $state_code   State code.
	 * @param string $country_code Country code.
	 * @return string State name or the code if not found.
	 */
	public function getStateName( string $state_code, string $country_code ): string;
}
