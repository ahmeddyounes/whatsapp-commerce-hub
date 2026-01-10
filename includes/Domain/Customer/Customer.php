<?php
/**
 * Customer Entity
 *
 * Represents a customer in the WhatsApp Commerce Hub.
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
 * Class Customer
 *
 * Immutable value object representing a WhatsApp customer.
 */
final class Customer {

	/**
	 * Constructor.
	 *
	 * @param int                     $id                   The customer ID.
	 * @param string                  $phone                The customer phone number.
	 * @param string|null             $name                 The customer name.
	 * @param string|null             $email                The customer email.
	 * @param int|null                $wc_customer_id       The linked WooCommerce customer ID.
	 * @param array                   $preferences          Customer preferences.
	 * @param array                   $tags                 Customer tags.
	 * @param bool                    $opt_in_marketing   Whether opted in for marketing.
	 * @param string|null             $language             Preferred language code.
	 * @param string|null             $timezone             Customer timezone.
	 * @param array|null              $last_known_address   Last known shipping address.
	 * @param int                     $total_orders         Total number of orders.
	 * @param float                   $total_spent          Total amount spent.
	 * @param \DateTimeImmutable      $created_at           When the customer was created.
	 * @param \DateTimeImmutable      $updated_at           When the customer was last updated.
	 * @param \DateTimeImmutable|null $last_interaction_at  When the last interaction occurred.
	 * @param \DateTimeImmutable|null $marketing_opted_at   When marketing opt-in occurred.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $phone,
		public readonly ?string $name = null,
		public readonly ?string $email = null,
		public readonly ?int $wc_customer_id = null,
		public readonly array $preferences = array(),
		public readonly array $tags = array(),
		public readonly bool $opt_in_marketing = false,
		public readonly ?string $language = null,
		public readonly ?string $timezone = null,
		public readonly ?array $last_known_address = null,
		public readonly int $total_orders = 0,
		public readonly float $total_spent = 0.0,
		public readonly \DateTimeImmutable $created_at = new \DateTimeImmutable(),
		public readonly \DateTimeImmutable $updated_at = new \DateTimeImmutable(),
		public readonly ?\DateTimeImmutable $last_interaction_at = null,
		public readonly ?\DateTimeImmutable $marketing_opted_at = null,
	) {}

	/**
	 * Create a Customer from a database row.
	 *
	 * @param array $row The database row.
	 * @return self
	 * @throws \InvalidArgumentException If phone is missing or invalid.
	 */
	public static function fromArray( array $row ): self {
		// Validate required phone field.
		$phone = $row['phone'] ?? '';
		if ( '' === $phone ) {
			throw new \InvalidArgumentException( 'Customer phone number is required' );
		}

		// Validate phone format using DataValidator if available.
		$phone = self::validatePhone( $phone );

		// Validate email if provided.
		$email = isset( $row['email'] ) && '' !== $row['email']
			? self::validateEmail( $row['email'] )
			: null;

		return new self(
			id: (int) $row['id'],
			phone: $phone,
			name: $row['name'] ?? null,
			email: $email,
			wc_customer_id: isset( $row['wc_customer_id'] )
				? (int) $row['wc_customer_id']
				: null,
			preferences: self::parseJson( $row['preferences'] ?? null, array(), 'preferences' ),
			tags: self::parseJson( $row['tags'] ?? null, array(), 'tags' ),
			opt_in_marketing: (bool) ( $row['opt_in_marketing'] ?? false ),
			language: $row['language'] ?? null,
			timezone: $row['timezone'] ?? null,
			last_known_address: self::parseJson( $row['last_known_address'] ?? null, null, 'last_known_address' ),
			total_orders: max( 0, (int) ( $row['total_orders'] ?? 0 ) ),
			total_spent: max( 0.0, (float) ( $row['total_spent'] ?? 0 ) ),
			created_at: self::parseDate( $row['created_at'] ?? null ),
			updated_at: self::parseDate( $row['updated_at'] ?? null ),
			last_interaction_at: self::parseDate( $row['last_interaction_at'] ?? null, null ),
			marketing_opted_at: self::parseDate( $row['marketing_opted_at'] ?? null, null ),
		);
	}

	/**
	 * Validate a phone number using DataValidator.
	 *
	 * Falls back to basic sanitization if DataValidator is not available.
	 *
	 * @param string $phone The phone number to validate.
	 * @return string The validated/sanitized phone number.
	 */
	private static function validatePhone( string $phone ): string {
		$validator_class = '\\WhatsAppCommerceHub\\Validation\\DataValidator';

		if ( class_exists( $validator_class ) ) {
			$validated = $validator_class::validatePhone( $phone );
			if ( null !== $validated ) {
				return $validated;
			}
			// If validation fails, return sanitized version.
			return $validator_class::sanitizePhone( $phone );
		}

		// Fallback: basic sanitization (remove non-digits).
		return preg_replace( '/[^0-9]/', '', $phone );
	}

	/**
	 * Validate an email address using DataValidator.
	 *
	 * Falls back to filter_var if DataValidator is not available.
	 *
	 * @param string $email The email address to validate.
	 * @return string|null The validated email or null if invalid.
	 */
	private static function validateEmail( string $email ): ?string {
		$validator_class = '\\WhatsAppCommerceHub\\Validation\\DataValidator';

		if ( class_exists( $validator_class ) ) {
			return $validator_class::validateEmail( $email );
		}

		// Fallback: use filter_var.
		$email = strtolower( trim( $email ) );
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : null;
	}

	/**
	 * Safely parse a date string to DateTimeImmutable.
	 *
	 * @param string|null                   $date    The date string to parse.
	 * @param \DateTimeImmutable|null|false $default Default value if parsing fails.
	 * @return \DateTimeImmutable|null
	 */
	private static function parseDate( ?string $date, \DateTimeImmutable|null|false $default = false ): ?\DateTimeImmutable {
		if ( empty( $date ) || '0000-00-00 00:00:00' === $date || '0000-00-00' === $date ) {
			return false === $default ? new \DateTimeImmutable() : $default;
		}

		try {
			return new \DateTimeImmutable( $date );
		} catch ( \Exception $e ) {
			return false === $default ? new \DateTimeImmutable() : $default;
		}
	}

	/**
	 * Safely parse a JSON string.
	 *
	 * Logs an error if JSON parsing fails to aid in debugging corrupted data.
	 *
	 * @param string|null $json    The JSON string to parse.
	 * @param mixed       $default Default value if parsing fails.
	 * @param string      $field   Optional field name for error logging.
	 * @return mixed
	 */
	private static function parseJson( ?string $json, mixed $default, string $field = 'unknown' ): mixed {
		if ( empty( $json ) ) {
			return $default;
		}

		$decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// Log the error for debugging - helps identify corrupted data in the database.
			do_action(
				'wch_log_warning',
				'Customer: JSON decode failed',
				array(
					'field'     => $field,
					'error'     => json_last_error_msg(),
					'json_head' => mb_substr( $json, 0, 100 ), // First 100 chars for debugging.
				)
			);
			return $default;
		}

		return $decoded ?? $default;
	}

	/**
	 * Convert to array for database storage.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'id'                  => $this->id,
			'phone'               => $this->phone,
			'name'                => $this->name,
			'email'               => $this->email,
			'wc_customer_id'      => $this->wc_customer_id,
			'preferences'         => wp_json_encode( $this->preferences ),
			'tags'                => wp_json_encode( $this->tags ),
			'opt_in_marketing'    => $this->opt_in_marketing ? 1 : 0,
			'language'            => $this->language,
			'timezone'            => $this->timezone,
			'last_known_address'  => $this->last_known_address
				? wp_json_encode( $this->last_known_address )
				: null,
			'total_orders'        => $this->total_orders,
			'total_spent'         => $this->total_spent,
			'created_at'          => $this->created_at->format( 'Y-m-d H:i:s' ),
			'updated_at'          => $this->updated_at->format( 'Y-m-d H:i:s' ),
			'last_interaction_at' => $this->last_interaction_at?->format( 'Y-m-d H:i:s' ),
			'marketing_opted_at'  => $this->marketing_opted_at?->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Check if the customer is linked to WooCommerce.
	 *
	 * @return bool
	 */
	public function isLinkedToWooCommerce(): bool {
		return null !== $this->wc_customer_id;
	}

	/**
	 * Check if the customer opted in for marketing.
	 *
	 * @return bool
	 */
	public function canReceiveMarketing(): bool {
		return $this->opt_in_marketing;
	}

	/**
	 * Check if the customer has a specific tag.
	 *
	 * @param string $tag The tag to check.
	 * @return bool
	 */
	public function hasTag( string $tag ): bool {
		return in_array( $tag, $this->tags, true );
	}

	/**
	 * Get a preference value.
	 *
	 * @param string $key     The preference key.
	 * @param mixed  $default The default value.
	 * @return mixed
	 */
	public function getPreference( string $key, mixed $default = null ): mixed {
		return $this->preferences[ $key ] ?? $default;
	}

	/**
	 * Get the display name.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		if ( $this->name ) {
			return $this->name;
		}

		// Format phone for display.
		$phone = $this->phone;
		if ( strlen( $phone ) > 4 ) {
			return substr( $phone, 0, -4 ) . '****';
		}

		return $phone;
	}

	/**
	 * Check if the customer is a repeat customer.
	 *
	 * @return bool
	 */
	public function isRepeatCustomer(): bool {
		return $this->total_orders > 1;
	}

	/**
	 * Get customer segment based on spending.
	 *
	 * @return string One of: new, bronze, silver, gold, platinum.
	 */
	public function getSegment(): string {
		if ( 0 === $this->total_orders ) {
			return 'new';
		}

		if ( $this->total_spent >= 1000 ) {
			return 'platinum';
		}

		if ( $this->total_spent >= 500 ) {
			return 'gold';
		}

		if ( $this->total_spent >= 100 ) {
			return 'silver';
		}

		return 'bronze';
	}

	/**
	 * Create a new customer with added tag.
	 *
	 * @param string $tag The tag to add.
	 * @return self
	 */
	public function withTag( string $tag ): self {
		if ( $this->hasTag( $tag ) ) {
			return $this;
		}

		$tags   = $this->tags;
		$tags[] = $tag;

		return new self(
			id: $this->id,
			phone: $this->phone,
			name: $this->name,
			email: $this->email,
			wc_customer_id: $this->wc_customer_id,
			preferences: $this->preferences,
			tags: $tags,
			opt_in_marketing: $this->opt_in_marketing,
			language: $this->language,
			timezone: $this->timezone,
			last_known_address: $this->last_known_address,
			total_orders: $this->total_orders,
			total_spent: $this->total_spent,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			last_interaction_at: $this->last_interaction_at,
			marketing_opted_at: $this->marketing_opted_at,
		);
	}

	/**
	 * Create a new customer with removed tag.
	 *
	 * @param string $tag The tag to remove.
	 * @return self
	 */
	public function withoutTag( string $tag ): self {
		if ( ! $this->hasTag( $tag ) ) {
			return $this;
		}

		$tags = array_filter(
			$this->tags,
			fn( $t ) => $t !== $tag
		);

		return new self(
			id: $this->id,
			phone: $this->phone,
			name: $this->name,
			email: $this->email,
			wc_customer_id: $this->wc_customer_id,
			preferences: $this->preferences,
			tags: array_values( $tags ),
			opt_in_marketing: $this->opt_in_marketing,
			language: $this->language,
			timezone: $this->timezone,
			last_known_address: $this->last_known_address,
			total_orders: $this->total_orders,
			total_spent: $this->total_spent,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			last_interaction_at: $this->last_interaction_at,
			marketing_opted_at: $this->marketing_opted_at,
		);
	}

	/**
	 * Create a new customer with updated preferences.
	 *
	 * @param array $preferences The preferences to merge.
	 * @return self
	 */
	public function withPreferences( array $preferences ): self {
		return new self(
			id: $this->id,
			phone: $this->phone,
			name: $this->name,
			email: $this->email,
			wc_customer_id: $this->wc_customer_id,
			preferences: array_merge( $this->preferences, $preferences ),
			tags: $this->tags,
			opt_in_marketing: $this->opt_in_marketing,
			language: $this->language,
			timezone: $this->timezone,
			last_known_address: $this->last_known_address,
			total_orders: $this->total_orders,
			total_spent: $this->total_spent,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			last_interaction_at: $this->last_interaction_at,
			marketing_opted_at: $this->marketing_opted_at,
		);
	}

	/**
	 * Export all customer data for GDPR compliance.
	 *
	 * @return array
	 */
	public function exportData(): array {
		return array(
			'personal_information' => array(
				'phone'    => $this->phone,
				'name'     => $this->name,
				'email'    => $this->email,
				'language' => $this->language,
				'timezone' => $this->timezone,
			),
			'preferences'          => $this->preferences,
			'marketing'            => array(
				'opted_in' => $this->opt_in_marketing,
				'opted_at' => $this->marketing_opted_at?->format( 'c' ),
			),
			'order_history'        => array(
				'total_orders' => $this->total_orders,
				'total_spent'  => $this->total_spent,
			),
			'tags'                 => $this->tags,
			'addresses'            => array(
				'last_known' => $this->last_known_address,
			),
			'account'              => array(
				'created_at'          => $this->created_at->format( 'c' ),
				'last_interaction_at' => $this->last_interaction_at?->format( 'c' ),
			),
		);
	}
}
