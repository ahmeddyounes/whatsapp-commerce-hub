<?php
/**
 * Shipping Calculator
 *
 * Calculates shipping methods and rates for checkout.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\ShippingCalculatorInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShippingCalculator
 *
 * Handles shipping method calculation and zone matching.
 */
class ShippingCalculator implements ShippingCalculatorInterface {

	/**
	 * Get available shipping methods for a customer.
	 *
	 * @param string $phone   Customer phone number.
	 * @param array  $address Shipping address.
	 * @param array  $items   Cart items.
	 * @return array Array of available shipping methods.
	 */
	public function getAvailableMethods( string $phone, array $address, array $items ): array {
		if ( empty( $address ) ) {
			return [];
		}

		$package     = $this->buildPackage( $items, $address );
		$matchedZone = $this->findMatchingZone( $address );

		if ( ! $matchedZone ) {
			// Fallback to "rest of world" zone.
			$matchedZone = new \WC_Shipping_Zone( 0 );
		}

		$zoneMethods = $matchedZone->get_shipping_methods( true );
		$methods     = [];

		foreach ( $zoneMethods as $method ) {
			if ( ! $method->is_enabled() ) {
				continue;
			}

			$rate = $this->calculateRate( $method->id . ':' . $method->instance_id, $package );

			$methods[] = [
				'id'          => $method->id . ':' . $method->instance_id,
				'label'       => $method->get_title(),
				'cost'        => $rate['cost'],
				'cost_html'   => wc_price( $rate['cost'] ),
				'description' => $method->get_method_description(),
			];
		}

		return apply_filters( 'wch_shipping_methods', $methods, $phone, $address );
	}

	/**
	 * Calculate shipping rate for a specific method.
	 *
	 * @param string $methodId Shipping method ID.
	 * @param array  $package  Shipping package data.
	 * @return array{cost: float, label: string}
	 */
	public function calculateRate( string $methodId, array $package ): array {
		$parts = explode( ':', $methodId );
		if ( count( $parts ) < 2 ) {
			return [
				'cost'  => 0.0,
				'label' => '',
			];
		}

		$methodType = $parts[0];
		$instanceId = (int) $parts[1];

		// Get the shipping method instance.
		$shippingMethods = WC()->shipping()->get_shipping_methods();

		if ( ! isset( $shippingMethods[ $methodType ] ) ) {
			return [
				'cost'  => 0.0,
				'label' => '',
			];
		}

		$method              = clone $shippingMethods[ $methodType ];
		$method->instance_id = $instanceId;
		$method->init_instance_settings();

		// Calculate shipping.
		$method->calculate_shipping( $package );
		$rates = $method->rates;

		if ( ! empty( $rates ) ) {
			$rate = reset( $rates );
			return [
				'cost'  => (float) $rate->get_cost(),
				'label' => $rate->get_label(),
			];
		}

		return [
			'cost'  => 0.0,
			'label' => $method->get_title(),
		];
	}

	/**
	 * Build shipping package for rate calculation.
	 *
	 * @param array $items   Cart items.
	 * @param array $address Shipping address.
	 * @return array Package data.
	 */
	public function buildPackage( array $items, array $address ): array {
		$contents = [];
		$total    = 0.0;

		foreach ( $items as $index => $item ) {
			$product = wc_get_product( $item['product_id'] );

			if ( $product ) {
				$lineTotal = ( $item['price'] ?? 0 ) * ( $item['quantity'] ?? 1 );

				$contents[ $index ] = [
					'product_id' => $item['product_id'],
					'quantity'   => $item['quantity'] ?? 1,
					'data'       => $product,
					'line_total' => $lineTotal,
				];

				$total += $lineTotal;
			}
		}

		return [
			'contents'        => $contents,
			'contents_cost'   => $total,
			'applied_coupons' => [],
			'destination'     => [
				'country'  => $address['country'] ?? '',
				'state'    => $address['state'] ?? '',
				'postcode' => $address['postcode'] ?? '',
				'city'     => $address['city'] ?? '',
				'address'  => $address['address_1'] ?? '',
			],
		];
	}

	/**
	 * Find matching shipping zone for address.
	 *
	 * @param array $address Shipping address.
	 * @return \WC_Shipping_Zone|null Matched zone or null.
	 */
	public function findMatchingZone( array $address ): ?\WC_Shipping_Zone {
		$shippingZones = \WC_Shipping_Zones::get_zones();

		foreach ( $shippingZones as $zoneData ) {
			$zone = new \WC_Shipping_Zone( $zoneData['id'] );

			if ( $this->zoneMatchesAddress( $zone, $address ) ) {
				return $zone;
			}
		}

		return null;
	}

	/**
	 * Check if shipping zone matches address.
	 *
	 * @param \WC_Shipping_Zone $zone    Shipping zone.
	 * @param array             $address Address data.
	 * @return bool True if matches.
	 */
	public function zoneMatchesAddress( \WC_Shipping_Zone $zone, array $address ): bool {
		$locations = $zone->get_zone_locations();

		foreach ( $locations as $location ) {
			// Match by country.
			if ( 'country' === $location->type && $location->code === ( $address['country'] ?? '' ) ) {
				return true;
			}

			// Match by state.
			if ( 'state' === $location->type ) {
				$parts = explode( ':', $location->code );
				if (
					count( $parts ) === 2 &&
					$parts[0] === ( $address['country'] ?? '' ) &&
					$parts[1] === ( $address['state'] ?? '' )
				) {
					return true;
				}
			}

			// Match by postcode.
			if ( 'postcode' === $location->type && $location->code === ( $address['postcode'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate selected shipping method.
	 *
	 * @param string $methodId Shipping method ID.
	 * @param array  $address  Shipping address.
	 * @return bool True if valid.
	 */
	public function validateMethod( string $methodId, array $address ): bool {
		$availableMethods = $this->getAvailableMethods( '', $address, [] );

		foreach ( $availableMethods as $method ) {
			if ( $method['id'] === $methodId ) {
				return true;
			}
		}

		return false;
	}
}
