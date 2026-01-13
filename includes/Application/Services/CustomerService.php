<?php
/**
 * Customer Service
 *
 * Business logic for customer profile management operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Domain\Customer\Customer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerService
 *
 * Handles customer profile business logic with repository pattern.
 */
class CustomerService implements CustomerServiceInterface {

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepositoryInterface
	 */
	private CustomerRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param CustomerRepositoryInterface $repository Customer repository.
	 */
	public function __construct( CustomerRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOrCreateProfile( string $phone ): Customer {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->repository->findByPhone( $phone );

		if ( $customer ) {
			return $customer;
		}

		// Try to find WooCommerce customer.
		$wc_customer_id = $this->findWooCommerceCustomerByPhone( $phone );

		$data = [
			'phone'          => $phone,
			'wc_customer_id' => $wc_customer_id,
			'created_at'     => new \DateTimeImmutable(),
			'updated_at'     => new \DateTimeImmutable(),
		];

		// If WC customer found, sync data.
		if ( $wc_customer_id ) {
			$wc_customer   = new \WC_Customer( $wc_customer_id );
			$data['name']  = $wc_customer->get_first_name() . ' ' . $wc_customer->get_last_name();
			$data['email'] = $wc_customer->get_email();
		}

		$id = $this->repository->create( $data );

		return $this->repository->find( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByPhone( string $phone ): ?Customer {
		$phone = $this->normalizePhone( $phone );
		return $this->repository->findByPhone( $phone );
	}

	/**
	 * {@inheritdoc}
	 */
	public function linkToWooCommerceCustomer( string $phone, int $wc_customer_id ): bool {
		$phone = $this->normalizePhone( $phone );
		return $this->repository->linkToWcCustomer( $phone, $wc_customer_id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function findWooCommerceCustomerByPhone( string $phone ): ?int {
		$phone = $this->normalizePhone( $phone );

		// Build phone variations for searching.
		$variations = $this->buildPhoneVariations( $phone );

		foreach ( $variations as $variation ) {
			$customers = get_users(
				[
					'meta_key'   => 'billing_phone',
					'meta_value' => $variation,
					'fields'     => 'ID',
					'number'     => 1,
				]
			);

			if ( ! empty( $customers ) ) {
				return (int) $customers[0];
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function saveAddress( string $phone, array $address, bool $is_default = false ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->getOrCreateProfile( $phone );

		// Validate required address fields.
		$required = [ 'address_1', 'city', 'country' ];
		foreach ( $required as $field ) {
			if ( empty( $address[ $field ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Address field "%s" is required', $field )
				);
			}
		}

		// Get existing addresses from preferences.
		$addresses = $customer->getPreference( 'saved_addresses', [] );

		// Add new address.
		$address['id']         = uniqid( 'addr_' );
		$address['created_at'] = ( new \DateTimeImmutable() )->format( 'c' );

		if ( $is_default || empty( $addresses ) ) {
			$address['is_default'] = true;
			// Remove default flag from other addresses.
			foreach ( $addresses as &$addr ) {
				$addr['is_default'] = false;
			}
			unset( $addr );
		} else {
			$address['is_default'] = false;
		}

		$addresses[] = $address;

		// Update preferences.
		$this->repository->updatePreferences(
			$customer->id,
			[
				'saved_addresses' => $addresses,
			]
		);

		// Update last known address.
		$this->repository->update(
			$customer->id,
			[
				'last_known_address' => $address,
				'updated_at'         => new \DateTimeImmutable(),
			]
		);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDefaultAddress( string $phone ): ?array {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return null;
		}

		$addresses = $customer->getPreference( 'saved_addresses', [] );

		foreach ( $addresses as $address ) {
			if ( ! empty( $address['is_default'] ) ) {
				return $address;
			}
		}

		// Return last known address if no default.
		return $customer->last_known_address;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSavedAddresses( string $phone ): array {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return [];
		}

		return $customer->getPreference( 'saved_addresses', [] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteAddress( string $phone, int $address_index ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return false;
		}

		$addresses = $customer->getPreference( 'saved_addresses', [] );

		if ( ! isset( $addresses[ $address_index ] ) ) {
			return false;
		}

		$was_default = ! empty( $addresses[ $address_index ]['is_default'] );
		array_splice( $addresses, $address_index, 1 );

		// If deleted was default, make first one default.
		if ( $was_default && ! empty( $addresses ) ) {
			$addresses[0]['is_default'] = true;
		}

		return $this->repository->updatePreferences(
			$customer->id,
			[
				'saved_addresses' => $addresses,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function updatePreferences( string $phone, array $preferences ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->getOrCreateProfile( $phone );

		return $this->repository->updatePreferences( $customer->id, $preferences );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPreferences( string $phone ): array {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return [];
		}

		return $customer->preferences;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateName( string $phone, string $name ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->getOrCreateProfile( $phone );

		return $this->repository->update(
			$customer->id,
			[
				'name'       => $name,
				'updated_at' => new \DateTimeImmutable(),
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setMarketingOptIn( string $phone, bool $opt_in ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->getOrCreateProfile( $phone );

		return $this->repository->updateMarketingOptIn( $customer->id, $opt_in );
	}

	/**
	 * {@inheritdoc}
	 */
	public function setNotificationOptOut( string $phone, bool $opt_out ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->getOrCreateProfile( $phone );

		return $this->repository->updatePreferences(
			$customer->id,
			[
				'notifications_opt_out' => $opt_out,
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOrderHistory( string $phone, int $limit = 10 ): array {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return [];
		}

		// If linked to WC, get orders from WC.
		if ( $customer->wc_customer_id ) {
			return $this->getWooCommerceOrders( $customer->wc_customer_id, $limit );
		}

		// Otherwise, search by phone.
		return $this->getOrdersByPhone( $phone, $limit );
	}

	/**
	 * {@inheritdoc}
	 */
	public function calculateStats( string $phone ): array {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return [
				'total_orders'          => 0,
				'total_spent'           => 0.0,
				'average_order_value'   => 0.0,
				'days_since_last_order' => null,
			];
		}

		$avg = $customer->total_orders > 0
			? $customer->total_spent / $customer->total_orders
			: 0.0;

		$days_since_last = null;
		if ( $customer->last_interaction_at ) {
			$days_since_last = $customer->last_interaction_at->diff( new \DateTimeImmutable() )->days;
		}

		return [
			'total_orders'          => $customer->total_orders,
			'total_spent'           => $customer->total_spent,
			'average_order_value'   => round( $avg, 2 ),
			'days_since_last_order' => $days_since_last,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function exportForGDPR( string $phone ): array {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return [];
		}

		return $this->repository->exportData( $customer->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteForGDPR( string $phone ): bool {
		$phone    = $this->normalizePhone( $phone );
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return false;
		}

		return $this->repository->deleteAllData( $customer->id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOptedInForMarketing( int $limit = 100 ): array {
		return $this->repository->findOptedInForMarketing( $limit );
	}

	/**
	 * {@inheritdoc}
	 */
	public function search( string $query, int $limit = 20 ): array {
		// Search by phone first (exact match).
		$normalized = $this->normalizePhone( $query );
		$customer   = $this->repository->findByPhone( $normalized );

		if ( $customer ) {
			return [ $customer ];
		}

		// Search by partial phone or name using repository.
		// This would require adding a search method to the repository.
		// For now, return empty - the repository can be extended.
		return [];
	}

	/**
	 * Normalize phone number to E.164 format.
	 *
	 * @param string $phone Phone number.
	 * @return string Normalized phone number.
	 */
	private function normalizePhone( string $phone ): string {
		// Remove all non-digits except leading +.
		$phone = preg_replace( '/[^\d+]/', '', $phone );

		// Ensure it starts with +.
		if ( ! str_starts_with( $phone, '+' ) ) {
			$phone = '+' . $phone;
		}

		return $phone;
	}

	/**
	 * Build phone number variations for searching.
	 *
	 * @param string $phone Phone number in E.164 format.
	 * @return array Array of phone variations.
	 */
	private function buildPhoneVariations( string $phone ): array {
		$variations = [ $phone ];

		// Without + prefix.
		$without_plus = ltrim( $phone, '+' );
		$variations[] = $without_plus;

		// With 00 prefix instead of +.
		$variations[] = '00' . $without_plus;

		// Common formats with spaces/dashes.
		if ( strlen( $without_plus ) >= 10 ) {
			$last10       = substr( $without_plus, -10 );
			$variations[] = $last10;

			// With country code variants.
			$country_code = substr( $without_plus, 0, -10 );
			if ( $country_code ) {
				$variations[] = '+' . $country_code . ' ' . $last10;
				$variations[] = $country_code . ' ' . $last10;
			}
		}

		return array_unique( $variations );
	}

	/**
	 * Get WooCommerce orders for a customer.
	 *
	 * @param int $customer_id WooCommerce customer ID.
	 * @param int $limit       Maximum orders.
	 * @return array Order data.
	 */
	private function getWooCommerceOrders( int $customer_id, int $limit ): array {
		$orders = wc_get_orders(
			[
				'customer_id' => $customer_id,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);

		$result = [];
		foreach ( $orders as $order ) {
			$result[] = [
				'id'           => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'status'       => $order->get_status(),
				'total'        => (float) $order->get_total(),
				'date'         => $order->get_date_created()?->format( 'Y-m-d H:i:s' ) ?? current_time( 'mysql' ),
				'items_count'  => $order->get_item_count(),
			];
		}

		return $result;
	}

	/**
	 * Get orders by phone number.
	 *
	 * @param string $phone Phone number.
	 * @param int    $limit Maximum orders.
	 * @return array Order data.
	 */
	private function getOrdersByPhone( string $phone, int $limit ): array {
		$variations = $this->buildPhoneVariations( $phone );
		$all_orders = [];

		foreach ( $variations as $variation ) {
			$orders = wc_get_orders(
				[
					'billing_phone' => $variation,
					'limit'         => $limit,
					'orderby'       => 'date',
					'order'         => 'DESC',
				]
			);

			foreach ( $orders as $order ) {
				$all_orders[ $order->get_id() ] = [
					'id'           => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'status'       => $order->get_status(),
					'total'        => (float) $order->get_total(),
					'date'         => $order->get_date_created()?->format( 'Y-m-d H:i:s' ) ?? current_time( 'mysql' ),
					'items_count'  => $order->get_item_count(),
				];
			}

			if ( count( $all_orders ) >= $limit ) {
				break;
			}
		}

		// Sort by date descending and limit.
		usort( $all_orders, fn( $a, $b ) => strcmp( $b['date'], $a['date'] ) );

		return array_slice( array_values( $all_orders ), 0, $limit );
	}
}
