<?php
/**
 * Customer Service Class
 *
 * Handles customer profile management linking WhatsApp users to WooCommerce customers.
 *
 * @package WhatsApp_Commerce_Hub
 *
 * @deprecated 2.0.0 Use CustomerService via DI container instead:
 *             `WhatsAppCommerceHub\Container\Container::getInstance()->get(CustomerServiceInterface::class)`
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WhatsAppCommerceHub\Container\Container;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;

/**
 * Class WCH_Customer_Service
 *
 * @deprecated 2.0.0 This class is a backward compatibility facade.
 *             Use CustomerServiceInterface via DI container for new code.
 */
class WCH_Customer_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Customer_Service
	 */
	private static $instance = null;

	/**
	 * The underlying CustomerService instance.
	 *
	 * @var CustomerServiceInterface|null
	 */
	private ?CustomerServiceInterface $service = null;

	/**
	 * Get singleton instance.
	 *
	 * @deprecated 2.0.0 Use getService() for new architecture.
	 * @return WCH_Customer_Service
	 */
	public static function instance() {
		_deprecated_function( __METHOD__, '2.0.0', 'WCH_Customer_Service::getService()' );

		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the CustomerService from the DI container.
	 *
	 * This is the recommended way to access customer functionality in new code.
	 *
	 * @since 2.0.0
	 * @return CustomerServiceInterface
	 */
	public static function getService(): CustomerServiceInterface {
		return Container::getInstance()->get( CustomerServiceInterface::class );
	}

	/**
	 * Constructor.
	 *
	 * Initializes the facade by getting the CustomerService from the DI container.
	 */
	private function __construct() {
		try {
			$this->service = Container::getInstance()->get( CustomerServiceInterface::class );
		} catch ( \Throwable $e ) {
			// Log error but allow fallback behavior.
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::warning(
					'CustomerService not available in container, facade will fail gracefully',
					array( 'error' => $e->getMessage() )
				);
			}
		}
	}

	/**
	 * Get the underlying service instance.
	 *
	 * @return CustomerServiceInterface
	 * @throws \RuntimeException If service is not available.
	 */
	private function getServiceInstance(): CustomerServiceInterface {
		if ( null === $this->service ) {
			throw new \RuntimeException(
				'CustomerService not available. Ensure the DI container is properly initialized.'
			);
		}
		return $this->service;
	}

	/**
	 * Convert a Customer entity to WCH_Customer_Profile for backward compatibility.
	 *
	 * @param \WhatsAppCommerceHub\Entities\Customer $customer Customer entity.
	 * @return WCH_Customer_Profile Profile object.
	 */
	private function customerToProfile( \WhatsAppCommerceHub\Entities\Customer $customer ): WCH_Customer_Profile {
		// Get saved addresses from preferences.
		$saved_addresses = $customer->getPreference( 'saved_addresses', array() );

		// Get order history via service if available.
		$order_history = array();
		try {
			$order_history = $this->getServiceInstance()->getOrderHistory( $customer->phone, 100 );
		} catch ( \Throwable $e ) {
			// Silently fail - order history is optional.
		}

		// Calculate last order date from order history.
		$last_order_date = null;
		if ( ! empty( $order_history ) ) {
			$last_order_date = $order_history[0]['date'] ?? null;
		}

		return new WCH_Customer_Profile(
			array(
				'phone'            => $customer->phone,
				'wc_customer_id'   => $customer->wc_customer_id,
				'name'             => $customer->name ?? '',
				'saved_addresses'  => is_array( $saved_addresses ) ? $saved_addresses : array(),
				'order_history'    => $order_history,
				'preferences'      => $customer->preferences,
				'opt_in_marketing' => $customer->opt_in_marketing,
				'lifetime_value'   => $customer->total_spent,
				'last_order_date'  => $last_order_date,
				'total_orders'     => $customer->total_orders,
				'created_at'       => $customer->created_at?->format( 'Y-m-d H:i:s' ) ?? current_time( 'mysql' ),
				'updated_at'       => $customer->updated_at?->format( 'Y-m-d H:i:s' ) ?? current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get or create customer profile.
	 *
	 * @deprecated 2.0.0 Use CustomerService::getOrCreateProfile() instead.
	 * @param string $phone Phone number (will be normalized to E.164).
	 * @return WCH_Customer_Profile|null Customer profile object or null on error.
	 */
	public function get_or_create_profile( $phone ) {
		try {
			$customer = $this->getServiceInstance()->getOrCreateProfile( $phone );
			return $this->customerToProfile( $customer );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::error(
					'Failed to get or create customer profile',
					array(
						'category' => 'customer-service',
						'phone'    => $phone,
						'error'    => $e->getMessage(),
					)
				);
			}
			return null;
		}
	}

	/**
	 * Link WhatsApp profile to WooCommerce customer.
	 *
	 * @deprecated 2.0.0 Use CustomerService::linkToWooCommerceCustomer() instead.
	 * @param string $phone          Phone number.
	 * @param int    $wc_customer_id WooCommerce customer ID.
	 * @return bool Success status.
	 */
	public function link_to_wc_customer( $phone, $wc_customer_id ) {
		return $this->getServiceInstance()->linkToWooCommerceCustomer( $phone, (int) $wc_customer_id );
	}

	/**
	 * Find WooCommerce customer by phone number.
	 *
	 * Searches for WC customers by billing_phone meta with format variations.
	 *
	 * @deprecated 2.0.0 Use CustomerService::findWooCommerceCustomerByPhone() instead.
	 * @param string $phone Phone number.
	 * @return int|null WooCommerce customer ID or null if not found.
	 */
	public function find_wc_customer_by_phone( $phone ) {
		return $this->getServiceInstance()->findWooCommerceCustomerByPhone( $phone );
	}

	/**
	 * Save customer address.
	 *
	 * @deprecated 2.0.0 Use CustomerService::saveAddress() instead.
	 * @param string $phone        Phone number.
	 * @param array  $address_data Address data.
	 * @param bool   $is_default   Mark as default address.
	 * @return bool Success status.
	 */
	public function save_address( $phone, $address_data, $is_default = false ) {
		try {
			return $this->getServiceInstance()->saveAddress( $phone, $address_data, $is_default );
		} catch ( \InvalidArgumentException $e ) {
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::warning(
					'Invalid address data',
					array(
						'category' => 'customer-service',
						'phone'    => $phone,
						'error'    => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * Get default customer address.
	 *
	 * @deprecated 2.0.0 Use CustomerService::getDefaultAddress() instead.
	 * @param string $phone Phone number.
	 * @return array|null Default address or null.
	 */
	public function get_default_address( $phone ) {
		return $this->getServiceInstance()->getDefaultAddress( $phone );
	}

	/**
	 * Update customer preferences.
	 *
	 * @deprecated 2.0.0 Use CustomerService::updatePreferences() instead.
	 * @param string $phone       Phone number.
	 * @param array  $preferences Preferences to update.
	 * @return bool Success status.
	 */
	public function update_preferences( $phone, $preferences ) {
		return $this->getServiceInstance()->updatePreferences( $phone, $preferences );
	}

	/**
	 * Get customer order history.
	 *
	 * @deprecated 2.0.0 Use CustomerService::getOrderHistory() instead.
	 * @param string $phone Phone number.
	 * @return array Array of order data.
	 */
	public function get_order_history( $phone ) {
		return $this->getServiceInstance()->getOrderHistory( $phone, 100 );
	}

	/**
	 * Calculate customer statistics.
	 *
	 * @deprecated 2.0.0 Use CustomerService::calculateStats() instead.
	 * @param string $phone Phone number.
	 * @return array Customer stats.
	 */
	public function calculate_customer_stats( $phone ) {
		return $this->getServiceInstance()->calculateStats( $phone );
	}

	/**
	 * Export customer data for GDPR compliance.
	 *
	 * @deprecated 2.0.0 Use CustomerService::exportForGDPR() instead.
	 * @param string $phone Phone number.
	 * @return array Customer data export.
	 */
	public function export_customer_data( $phone ) {
		return $this->getServiceInstance()->exportForGDPR( $phone );
	}

	/**
	 * Delete customer data for GDPR compliance.
	 *
	 * Anonymizes conversations and deletes profile.
	 *
	 * @deprecated 2.0.0 Use CustomerService::deleteForGDPR() instead.
	 * @param string $phone Phone number.
	 * @return bool Success status.
	 */
	public function delete_customer_data( $phone ) {
		return $this->getServiceInstance()->deleteForGDPR( $phone );
	}
}
