<?php
/**
 * Shipping Calculator Interface
 *
 * Contract for calculating shipping methods and rates.
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
 * Interface ShippingCalculatorInterface
 *
 * Defines contract for shipping calculation operations.
 */
interface ShippingCalculatorInterface {

	/**
	 * Get available shipping methods for a customer.
	 *
	 * @param string $phone   Customer phone number.
	 * @param array  $address Shipping address.
	 * @param array  $items   Cart items.
	 * @return array Array of available shipping methods.
	 */
	public function getAvailableMethods( string $phone, array $address, array $items ): array;

	/**
	 * Calculate shipping rate for a specific method.
	 *
	 * @param string $methodId Shipping method ID.
	 * @param array  $package  Shipping package data.
	 * @return array{cost: float, label: string}
	 */
	public function calculateRate( string $methodId, array $package ): array;

	/**
	 * Build shipping package for rate calculation.
	 *
	 * @param array $items   Cart items.
	 * @param array $address Shipping address.
	 * @return array Package data.
	 */
	public function buildPackage( array $items, array $address ): array;

	/**
	 * Find matching shipping zone for address.
	 *
	 * @param array $address Shipping address.
	 * @return \WC_Shipping_Zone|null Matched zone or null.
	 */
	public function findMatchingZone( array $address ): ?\WC_Shipping_Zone;

	/**
	 * Check if shipping zone matches address.
	 *
	 * @param \WC_Shipping_Zone $zone    Shipping zone.
	 * @param array             $address Address data.
	 * @return bool True if matches.
	 */
	public function zoneMatchesAddress( \WC_Shipping_Zone $zone, array $address ): bool;

	/**
	 * Validate selected shipping method.
	 *
	 * @param string $methodId Shipping method ID.
	 * @param array  $address  Shipping address.
	 * @return bool True if valid.
	 */
	public function validateMethod( string $methodId, array $address ): bool;
}
