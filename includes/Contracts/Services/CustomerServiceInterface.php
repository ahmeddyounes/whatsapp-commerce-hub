<?php
/**
 * Customer Service Interface
 *
 * Contract for customer profile management operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Services;

use WhatsAppCommerceHub\Domain\Customer\Customer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CustomerServiceInterface
 *
 * Defines the contract for customer profile management operations.
 */
interface CustomerServiceInterface {

	/**
	 * Get or create customer profile.
	 *
	 * @param string $phone Phone number (will be normalized to E.164).
	 * @return Customer Customer entity.
	 */
	public function getOrCreateProfile( string $phone ): Customer;

	/**
	 * Find customer by phone number.
	 *
	 * @param string $phone Phone number.
	 * @return Customer|null Customer entity or null if not found.
	 */
	public function findByPhone( string $phone ): ?Customer;

	/**
	 * Link WhatsApp profile to WooCommerce customer.
	 *
	 * @param string $phone          Phone number.
	 * @param int    $wc_customer_id WooCommerce customer ID.
	 * @return bool Success status.
	 */
	public function linkToWooCommerceCustomer( string $phone, int $wc_customer_id ): bool;

	/**
	 * Find WooCommerce customer by phone number.
	 *
	 * Searches for WC customers by billing_phone meta with format variations.
	 *
	 * @param string $phone Phone number.
	 * @return int|null WooCommerce customer ID or null if not found.
	 */
	public function findWooCommerceCustomerByPhone( string $phone ): ?int;

	/**
	 * Save customer address.
	 *
	 * @param string $phone      Phone number.
	 * @param array  $address    Address data.
	 * @param bool   $is_default Mark as default address.
	 * @return bool Success status.
	 * @throws \InvalidArgumentException If address data is invalid.
	 */
	public function saveAddress( string $phone, array $address, bool $is_default = false ): bool;

	/**
	 * Get default customer address.
	 *
	 * @param string $phone Phone number.
	 * @return array|null Default address or null.
	 */
	public function getDefaultAddress( string $phone ): ?array;

	/**
	 * Get all saved addresses for customer.
	 *
	 * @param string $phone Phone number.
	 * @return array Array of saved addresses.
	 */
	public function getSavedAddresses( string $phone ): array;

	/**
	 * Delete a saved address.
	 *
	 * @param string $phone         Phone number.
	 * @param int    $address_index Address index.
	 * @return bool Success status.
	 */
	public function deleteAddress( string $phone, int $address_index ): bool;

	/**
	 * Update customer preferences.
	 *
	 * @param string $phone       Phone number.
	 * @param array  $preferences Preferences to update.
	 * @return bool Success status.
	 */
	public function updatePreferences( string $phone, array $preferences ): bool;

	/**
	 * Get customer preferences.
	 *
	 * @param string $phone Phone number.
	 * @return array Customer preferences.
	 */
	public function getPreferences( string $phone ): array;

	/**
	 * Update customer name.
	 *
	 * @param string $phone Phone number.
	 * @param string $name  Customer name.
	 * @return bool Success status.
	 */
	public function updateName( string $phone, string $name ): bool;

	/**
	 * Set marketing opt-in status.
	 *
	 * @param string $phone  Phone number.
	 * @param bool   $opt_in Opt-in status.
	 * @return bool Success status.
	 */
	public function setMarketingOptIn( string $phone, bool $opt_in ): bool;

	/**
	 * Set notification opt-out status.
	 *
	 * @param string $phone   Phone number.
	 * @param bool   $opt_out Opt-out status.
	 * @return bool Success status.
	 */
	public function setNotificationOptOut( string $phone, bool $opt_out ): bool;

	/**
	 * Get customer order history.
	 *
	 * @param string $phone Phone number.
	 * @param int    $limit Maximum orders to return.
	 * @return array Array of order data.
	 */
	public function getOrderHistory( string $phone, int $limit = 10 ): array;

	/**
	 * Calculate customer statistics.
	 *
	 * @param string $phone Phone number.
	 * @return array{total_orders: int, total_spent: float, average_order_value: float, days_since_last_order: int|null}
	 */
	public function calculateStats( string $phone ): array;

	/**
	 * Export customer data for GDPR compliance.
	 *
	 * @param string $phone Phone number.
	 * @return array Customer data export.
	 */
	public function exportForGDPR( string $phone ): array;

	/**
	 * Delete customer data for GDPR compliance.
	 *
	 * Anonymizes conversations and deletes profile.
	 *
	 * @param string $phone Phone number.
	 * @return bool Success status.
	 */
	public function deleteForGDPR( string $phone ): bool;

	/**
	 * Get customers opted in for marketing.
	 *
	 * @param int $limit Maximum customers to return.
	 * @return array<Customer> Customers opted in for marketing.
	 */
	public function getOptedInForMarketing( int $limit = 100 ): array;

	/**
	 * Search customers by name or phone.
	 *
	 * @param string $query  Search query.
	 * @param int    $limit  Maximum results.
	 * @return array<Customer> Matching customers.
	 */
	public function search( string $query, int $limit = 20 ): array;
}
