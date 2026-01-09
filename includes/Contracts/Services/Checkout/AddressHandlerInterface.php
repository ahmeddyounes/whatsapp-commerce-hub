<?php
/**
 * Address Handler Interface
 *
 * Contract for handling checkout addresses.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\Checkout;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface AddressHandlerInterface
 *
 * Defines contract for address validation and management.
 */
interface AddressHandlerInterface {

	/**
	 * Validate address data.
	 *
	 * @param array $address Address data.
	 * @return array{valid: bool, error: string|null}
	 */
	public function validateAddress( array $address ): array;

	/**
	 * Get saved addresses for a customer.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of saved addresses.
	 */
	public function getSavedAddresses( string $phone ): array;

	/**
	 * Get a specific saved address by ID.
	 *
	 * @param string $phone     Customer phone number.
	 * @param string $addressId Address ID.
	 * @return array|null Address data or null if not found.
	 */
	public function getSavedAddress( string $phone, string $addressId ): ?array;

	/**
	 * Save an address for a customer.
	 *
	 * @param string $phone   Customer phone number.
	 * @param array  $address Address data.
	 * @return bool Success status.
	 */
	public function saveAddress( string $phone, array $address ): bool;

	/**
	 * Get WooCommerce-compatible address format.
	 *
	 * @param array $address Raw address data.
	 * @return array Formatted address.
	 */
	public function formatAddress( array $address ): array;

	/**
	 * Get required address fields.
	 *
	 * @return array Array of required field names.
	 */
	public function getRequiredFields(): array;

	/**
	 * Check if a country code is valid.
	 *
	 * @param string $countryCode Country code.
	 * @return bool True if valid.
	 */
	public function isValidCountry( string $countryCode ): bool;
}
