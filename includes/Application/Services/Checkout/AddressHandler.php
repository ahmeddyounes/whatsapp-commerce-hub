<?php

/**
 * Address Handler
 *
 * Handles checkout address validation and management.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\AddressHandlerInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AddressHandler
 *
 * Validates and manages checkout addresses.
 */
class AddressHandler implements AddressHandlerInterface {

	/**
	 * Required address fields.
	 *
	 * @var array
	 */
	private const REQUIRED_FIELDS = array( 'address_1', 'city', 'country' );

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepositoryInterface|null
	 */
	protected ?CustomerRepositoryInterface $customerRepository;

	/**
	 * Constructor.
	 *
	 * @param CustomerRepositoryInterface|null $customerRepository Customer repository.
	 */
	public function __construct( ?CustomerRepositoryInterface $customerRepository = null ) {
		$this->customerRepository = $customerRepository;
	}

	/**
	 * Validate address data.
	 *
	 * @param array $address Address data.
	 * @return array{valid: bool, error: string|null}
	 */
	public function validateAddress( array $address ): array {
		// Check required fields.
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( empty( $address[ $field ] ) ) {
				return array(
					'valid' => false,
					'error' => sprintf(
						/* translators: %s: field name */
						__( 'Missing required field: %s', 'whatsapp-commerce-hub' ),
						$field
					),
				);
			}
		}

		// Validate country code.
		if ( ! $this->isValidCountry( $address['country'] ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Invalid country', 'whatsapp-commerce-hub' ),
			);
		}

		// Validate state if country requires it.
		$states = WC()->countries->get_states( $address['country'] );
		if ( ! empty( $states ) && empty( $address['state'] ) ) {
			return array(
				'valid' => false,
				'error' => __( 'State/Province is required for this country', 'whatsapp-commerce-hub' ),
			);
		}

		// Validate postcode format if provided.
		if ( ! empty( $address['postcode'] ) ) {
			$postcodeValidation = \WC_Validation::is_postcode( $address['postcode'], $address['country'] );
			if ( ! $postcodeValidation ) {
				return array(
					'valid' => false,
					'error' => __( 'Invalid postcode format', 'whatsapp-commerce-hub' ),
				);
			}
		}

		return array(
			'valid' => true,
			'error' => null,
		);
	}

	/**
	 * Get saved addresses for a customer.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of saved addresses.
	 */
	public function getSavedAddresses( string $phone ): array {
		if ( ! $this->customerRepository ) {
			return array();
		}

		try {
			$customer = $this->customerRepository->findByPhone( $phone );

			if ( $customer && ! empty( $customer->last_known_address ) ) {
				return array(
					array(
						'id'      => 'last',
						'label'   => __( 'Last used address', 'whatsapp-commerce-hub' ),
						'address' => $customer->last_known_address,
					),
				);
			}
		} catch ( \Exception $e ) {
			// Silent failure - return empty array.
		}

		return array();
	}

	/**
	 * Get a specific saved address by ID.
	 *
	 * @param string $phone     Customer phone number.
	 * @param string $addressId Address ID.
	 * @return array|null Address data or null if not found.
	 */
	public function getSavedAddress( string $phone, string $addressId ): ?array {
		$addresses = $this->getSavedAddresses( $phone );

		foreach ( $addresses as $addr ) {
			if ( $addr['id'] === $addressId ) {
				return $addr['address'];
			}
		}

		return null;
	}

	/**
	 * Save an address for a customer.
	 *
	 * @param string $phone   Customer phone number.
	 * @param array  $address Address data.
	 * @return bool Success status.
	 */
	public function saveAddress( string $phone, array $address ): bool {
		if ( ! $this->customerRepository ) {
			return false;
		}

		try {
			$customer = $this->customerRepository->findByPhone( $phone );

			if ( $customer ) {
				return $this->customerRepository->update(
					$customer->id,
					array( 'last_known_address' => $address )
				);
			}
		} catch ( \Exception $e ) {
			// Silent failure.
		}

		return false;
	}

	/**
	 * Get WooCommerce-compatible address format.
	 *
	 * @param array $address Raw address data.
	 * @return array Formatted address.
	 */
	public function formatAddress( array $address ): array {
		return array(
			'first_name' => $address['first_name'] ?? '',
			'last_name'  => $address['last_name'] ?? '',
			'company'    => $address['company'] ?? '',
			'address_1'  => $address['address_1'] ?? '',
			'address_2'  => $address['address_2'] ?? '',
			'city'       => $address['city'] ?? '',
			'state'      => $address['state'] ?? '',
			'postcode'   => $address['postcode'] ?? '',
			'country'    => $address['country'] ?? '',
			'phone'      => $address['phone'] ?? '',
			'email'      => $address['email'] ?? '',
		);
	}

	/**
	 * Get required address fields.
	 *
	 * @return array Array of required field names.
	 */
	public function getRequiredFields(): array {
		return apply_filters( 'wch_required_address_fields', self::REQUIRED_FIELDS );
	}

	/**
	 * Check if a country code is valid.
	 *
	 * @param string $countryCode Country code.
	 * @return bool True if valid.
	 */
	public function isValidCountry( string $countryCode ): bool {
		$countries = WC()->countries->get_countries();
		return isset( $countries[ $countryCode ] );
	}
}
