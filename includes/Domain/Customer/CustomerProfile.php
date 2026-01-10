<?php
/**
 * Customer Profile Value Object
 *
 * Represents a WhatsApp customer profile with all associated data.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Domain\Customer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerProfile
 *
 * Value object representing customer profile information.
 */
final class CustomerProfile {
	/**
	 * Constructor.
	 *
	 * @param string      $phone             Customer phone number in E.164 format.
	 * @param int|null    $woocommerceId     WooCommerce customer ID.
	 * @param string|null $name              Customer name.
	 * @param string|null $email             Customer email.
	 * @param array       $addresses         Customer addresses.
	 * @param array       $preferences       Customer preferences.
	 * @param array       $metadata          Additional metadata.
	 */
	public function __construct(
		public readonly string $phone,
		public readonly ?int $woocommerceId = null,
		public readonly ?string $name = null,
		public readonly ?string $email = null,
		public readonly array $addresses = [],
		public readonly array $preferences = [],
		public readonly array $metadata = []
	) {}

	/**
	 * Get primary address.
	 *
	 * @return array|null
	 */
	public function getPrimaryAddress(): ?array {
		foreach ( $this->addresses as $address ) {
			if ( isset( $address['is_primary'] ) && $address['is_primary'] ) {
				return $address;
			}
		}

		return $this->addresses[0] ?? null;
	}

	/**
	 * Check if customer is registered.
	 *
	 * @return bool
	 */
	public function isRegistered(): bool {
		return null !== $this->woocommerceId;
	}

	/**
	 * Get preference value.
	 *
	 * @param string $key     Preference key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function getPreference( string $key, mixed $default = null ): mixed {
		return $this->preferences[ $key ] ?? $default;
	}

	/**
	 * Get metadata value.
	 *
	 * @param string $key     Metadata key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function getMetadata( string $key, mixed $default = null ): mixed {
		return $this->metadata[ $key ] ?? $default;
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return [
			'phone'          => $this->phone,
			'woocommerce_id' => $this->woocommerceId,
			'name'           => $this->name,
			'email'          => $this->email,
			'addresses'      => $this->addresses,
			'preferences'    => $this->preferences,
			'metadata'       => $this->metadata,
		];
	}

	/**
	 * Create from array.
	 *
	 * @param array $data Profile data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			$data['phone'] ?? '',
			$data['woocommerce_id'] ?? null,
			$data['name'] ?? null,
			$data['email'] ?? null,
			$data['addresses'] ?? [],
			$data['preferences'] ?? [],
			$data['metadata'] ?? []
		);
	}
}
