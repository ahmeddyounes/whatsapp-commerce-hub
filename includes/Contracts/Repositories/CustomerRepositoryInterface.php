<?php
/**
 * Customer Repository Interface
 *
 * Interface for customer data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Repositories;

use WhatsAppCommerceHub\Entities\Customer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CustomerRepositoryInterface
 *
 * Defines customer-specific data access operations.
 */
interface CustomerRepositoryInterface extends RepositoryInterface {

	/**
	 * Find a customer by phone number.
	 *
	 * @param string $phone The customer phone number.
	 * @return Customer|null The customer or null if not found.
	 */
	public function findByPhone( string $phone ): ?Customer;

	/**
	 * Find a customer by WooCommerce customer ID.
	 *
	 * @param int $wc_customer_id The WooCommerce customer ID.
	 * @return Customer|null The customer or null if not found.
	 */
	public function findByWcCustomerId( int $wc_customer_id ): ?Customer;

	/**
	 * Link a WhatsApp customer to a WooCommerce customer.
	 *
	 * @param string $phone          The customer phone number.
	 * @param int    $wc_customer_id The WooCommerce customer ID.
	 * @return bool True on success.
	 */
	public function linkToWcCustomer( string $phone, int $wc_customer_id ): bool;

	/**
	 * Update customer preferences.
	 *
	 * @param int   $id          The customer ID.
	 * @param array $preferences The preferences to merge.
	 * @return bool True on success.
	 */
	public function updatePreferences( int $id, array $preferences ): bool;

	/**
	 * Find customers opted in for marketing.
	 *
	 * @param int $limit  Maximum customers to return.
	 * @param int $offset Number of customers to skip.
	 * @return array<Customer> Customers opted in for marketing.
	 */
	public function findOptedInForMarketing( int $limit = 100, int $offset = 0 ): array;

	/**
	 * Update marketing opt-in status.
	 *
	 * @param int  $id        The customer ID.
	 * @param bool $opted_in  Whether the customer opted in.
	 * @return bool True on success.
	 */
	public function updateMarketingOptIn( int $id, bool $opted_in ): bool;

	/**
	 * Find customers by tag.
	 *
	 * @param string $tag    The tag to search for.
	 * @param int    $limit  Maximum customers to return.
	 * @param int    $offset Number of customers to skip.
	 * @return array<Customer> Customers with the specified tag.
	 */
	public function findByTag( string $tag, int $limit = 100, int $offset = 0 ): array;

	/**
	 * Add a tag to a customer.
	 *
	 * @param int    $id  The customer ID.
	 * @param string $tag The tag to add.
	 * @return bool True on success.
	 */
	public function addTag( int $id, string $tag ): bool;

	/**
	 * Remove a tag from a customer.
	 *
	 * @param int    $id  The customer ID.
	 * @param string $tag The tag to remove.
	 * @return bool True on success.
	 */
	public function removeTag( int $id, string $tag ): bool;

	/**
	 * Export customer data for GDPR compliance.
	 *
	 * @param int $id The customer ID.
	 * @return array All customer data.
	 */
	public function exportData( int $id ): array;

	/**
	 * Delete all customer data for GDPR compliance.
	 *
	 * @param int $id The customer ID.
	 * @return bool True on success.
	 */
	public function deleteAllData( int $id ): bool;

	/**
	 * Get customer statistics.
	 *
	 * @return array{total: int, opted_in: int, with_orders: int}
	 */
	public function getStats(): array;
}
