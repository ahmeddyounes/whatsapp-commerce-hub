<?php
/**
 * Payment Gateway Registry Interface
 *
 * Defines the contract for payment gateway registration and retrieval.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.1.0
 */

namespace WhatsAppCommerceHub\Contracts\Payments;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface PaymentGatewayRegistryInterface
 *
 * Contract for payment gateway registry operations.
 */
interface PaymentGatewayRegistryInterface {

	/**
	 * Register a payment gateway.
	 *
	 * @param string                $id Gateway identifier.
	 * @param \WCH_Payment_Gateway $gateway Gateway instance.
	 * @return void
	 */
	public function register( string $id, \WCH_Payment_Gateway $gateway ): void;

	/**
	 * Get a gateway by ID.
	 *
	 * @param string $id Gateway identifier.
	 * @return \WCH_Payment_Gateway|null Gateway instance or null if not found.
	 */
	public function get( string $id ): ?\WCH_Payment_Gateway;

	/**
	 * Check if a gateway is registered.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if gateway exists.
	 */
	public function has( string $id ): bool;

	/**
	 * Get all registered gateways.
	 *
	 * @return array<string, \WCH_Payment_Gateway> All registered gateways.
	 */
	public function all(): array;

	/**
	 * Get all enabled gateways.
	 *
	 * @return array<string, \WCH_Payment_Gateway> Enabled gateways.
	 */
	public function getEnabled(): array;

	/**
	 * Get available gateways for a specific country.
	 *
	 * @param string $country Two-letter country code.
	 * @return array<string, \WCH_Payment_Gateway> Available gateways.
	 */
	public function getAvailable( string $country ): array;

	/**
	 * Remove a gateway from the registry.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if removed, false if not found.
	 */
	public function remove( string $id ): bool;

	/**
	 * Get all gateway IDs.
	 *
	 * @return array<string> List of gateway IDs.
	 */
	public function ids(): array;

	/**
	 * Enable a gateway.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if enabled.
	 */
	public function enable( string $id ): bool;

	/**
	 * Disable a gateway.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if disabled.
	 */
	public function disable( string $id ): bool;

	/**
	 * Check if a gateway is enabled.
	 *
	 * @param string $id Gateway identifier.
	 * @return bool True if enabled.
	 */
	public function isEnabled( string $id ): bool;

	/**
	 * Get gateway metadata for admin display.
	 *
	 * @return array Gateway metadata.
	 */
	public function getMetadata(): array;
}
