<?php
/**
 * Cart Entity
 *
 * Represents a shopping cart in the WhatsApp Commerce Hub.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Entities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cart
 *
 * Immutable value object representing a shopping cart.
 */
final class Cart {

	/**
	 * Cart statuses.
	 */
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_ABANDONED = 'abandoned';
	public const STATUS_CONVERTED = 'converted';
	public const STATUS_EXPIRED   = 'expired';

	/**
	 * Allowed cart statuses (whitelist for validation).
	 */
	private const ALLOWED_STATUSES = [
		self::STATUS_ACTIVE,
		self::STATUS_ABANDONED,
		self::STATUS_CONVERTED,
		self::STATUS_EXPIRED,
	];

	/**
	 * Constructor.
	 *
	 * @param int                     $id                  The cart ID.
	 * @param string                  $customer_phone      The customer phone number.
	 * @param array                   $items               The cart items.
	 * @param float                   $total               The cart total.
	 * @param string|null             $coupon_code         Applied coupon code.
	 * @param array|null              $shipping_address    The shipping address.
	 * @param string                  $status              The cart status.
	 * @param \DateTimeImmutable      $expires_at          When the cart expires.
	 * @param \DateTimeImmutable      $created_at          When the cart was created.
	 * @param \DateTimeImmutable      $updated_at          When the cart was last updated.
	 * @param \DateTimeImmutable|null $reminder_1_sent_at  When first reminder was sent.
	 * @param \DateTimeImmutable|null $reminder_2_sent_at  When second reminder was sent.
	 * @param \DateTimeImmutable|null $reminder_3_sent_at  When third reminder was sent.
	 * @param bool                    $recovered           Whether the cart was recovered.
	 * @param int|null                $recovered_order_id  The recovered order ID.
	 * @param float|null              $recovered_revenue   The recovered revenue amount.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $customer_phone,
		public readonly array $items,
		public readonly float $total,
		public readonly ?string $coupon_code,
		public readonly ?array $shipping_address,
		public readonly string $status,
		public readonly \DateTimeImmutable $expires_at,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at,
		public readonly ?\DateTimeImmutable $reminder_1_sent_at = null,
		public readonly ?\DateTimeImmutable $reminder_2_sent_at = null,
		public readonly ?\DateTimeImmutable $reminder_3_sent_at = null,
		public readonly bool $recovered = false,
		public readonly ?int $recovered_order_id = null,
		public readonly ?float $recovered_revenue = null,
	) {}

	/**
	 * Create a Cart from a database row.
	 *
	 * @param array $row The database row.
	 * @return self
	 * @throws \InvalidArgumentException If customer_phone is missing.
	 */
	public static function fromArray( array $row ): self {
		// Validate required customer_phone field.
		$customer_phone = $row['customer_phone'] ?? '';
		if ( '' === $customer_phone ) {
			throw new \InvalidArgumentException( 'Cart customer_phone is required' );
		}

		// Validate and sanitize phone number.
		$customer_phone = self::validatePhone( $customer_phone );

		return new self(
			id: (int) $row['id'],
			customer_phone: $customer_phone,
			items: self::decodeJsonArray( $row['items'] ?? '[]', 'items', (int) $row['id'] ),
			total: max( 0.0, (float) ( $row['total'] ?? 0 ) ),
			coupon_code: $row['coupon_code'] ?? null,
			shipping_address: isset( $row['shipping_address'] ) && '' !== $row['shipping_address']
				? self::decodeJsonArray( $row['shipping_address'], 'shipping_address', (int) $row['id'] )
				: null,
			status: self::validateStatus( $row['status'] ?? self::STATUS_ACTIVE ),
			expires_at: self::parseDate( $row['expires_at'] ?? null ),
			created_at: self::parseDate( $row['created_at'] ?? null ),
			updated_at: self::parseDate( $row['updated_at'] ?? null ),
			reminder_1_sent_at: self::parseDate( $row['reminder_1_sent_at'] ?? null, null ),
			reminder_2_sent_at: self::parseDate( $row['reminder_2_sent_at'] ?? null, null ),
			reminder_3_sent_at: self::parseDate( $row['reminder_3_sent_at'] ?? null, null ),
			recovered: (bool) ( $row['recovered'] ?? false ),
			recovered_order_id: isset( $row['recovered_order_id'] )
				? (int) $row['recovered_order_id']
				: null,
			recovered_revenue: isset( $row['recovered_revenue'] )
				? max( 0.0, (float) $row['recovered_revenue'] )
				: null,
		);
	}

	/**
	 * Validate and normalize a cart status.
	 *
	 * @param string $status The status to validate.
	 * @return string The validated status (defaults to STATUS_ACTIVE if invalid).
	 */
	private static function validateStatus( string $status ): string {
		if ( in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return $status;
		}

		// Log invalid status for debugging/security auditing.
		if ( function_exists( 'do_action' ) ) {
			do_action(
				'wch_log_warning',
				'Invalid cart status attempted',
				[
					'status'  => $status,
					'allowed' => self::ALLOWED_STATUSES,
				]
			);
		}

		// Return safe default.
		return self::STATUS_ACTIVE;
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
	 * Safely decode a JSON string to array with error logging.
	 *
	 * @param string $json    The JSON string to decode.
	 * @param string $field   The field name for error reporting.
	 * @param int    $cart_id The cart ID for error reporting.
	 * @return array The decoded array or empty array on failure.
	 */
	private static function decodeJsonArray( string $json, string $field, int $cart_id ): array {
		if ( '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			if ( function_exists( 'do_action' ) ) {
				do_action(
					'wch_log_warning',
					'JSON decode failed in Cart entity',
					[
						'field'   => $field,
						'cart_id' => $cart_id,
						'error'   => json_last_error_msg(),
					]
				);
			}
			return [];
		}

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Safely parse a date string to DateTimeImmutable.
	 *
	 * Handles invalid dates gracefully (e.g., '0000-00-00 00:00:00', corrupted data)
	 * by returning a default value instead of throwing an exception.
	 *
	 * @param string|null                   $date    The date string to parse.
	 * @param \DateTimeImmutable|null|false $default Default value if parsing fails.
	 *                                               Pass null to return null on failure.
	 *                                               Pass false (default) to return 'now'.
	 * @return \DateTimeImmutable|null The parsed date or default.
	 */
	private static function parseDate( ?string $date, \DateTimeImmutable|null|false $default = false ): ?\DateTimeImmutable {
		// Null or empty string.
		if ( empty( $date ) ) {
			return false === $default ? new \DateTimeImmutable() : $default;
		}

		// MySQL zero date (common for unset nullable dates).
		if ( '0000-00-00 00:00:00' === $date || '0000-00-00' === $date ) {
			return false === $default ? new \DateTimeImmutable() : $default;
		}

		try {
			return new \DateTimeImmutable( $date );
		} catch ( \Exception $e ) {
			// Log the invalid date for debugging.
			if ( function_exists( 'do_action' ) ) {
				do_action(
					'wch_log_warning',
					'Invalid date encountered in Cart entity',
					[
						'date'  => $date,
						'error' => $e->getMessage(),
					]
				);
			}

			return false === $default ? new \DateTimeImmutable() : $default;
		}
	}

	/**
	 * Convert to array for database storage.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return [
			'id'                 => $this->id,
			'customer_phone'     => $this->customer_phone,
			'items'              => wp_json_encode( $this->items ),
			'total'              => $this->total,
			'coupon_code'        => $this->coupon_code,
			'shipping_address'   => $this->shipping_address
				? wp_json_encode( $this->shipping_address )
				: null,
			'status'             => $this->status,
			'expires_at'         => $this->expires_at->format( 'Y-m-d H:i:s' ),
			'created_at'         => $this->created_at->format( 'Y-m-d H:i:s' ),
			'updated_at'         => $this->updated_at->format( 'Y-m-d H:i:s' ),
			'reminder_1_sent_at' => $this->reminder_1_sent_at?->format( 'Y-m-d H:i:s' ),
			'reminder_2_sent_at' => $this->reminder_2_sent_at?->format( 'Y-m-d H:i:s' ),
			'reminder_3_sent_at' => $this->reminder_3_sent_at?->format( 'Y-m-d H:i:s' ),
			'recovered'          => $this->recovered ? 1 : 0,
			'recovered_order_id' => $this->recovered_order_id,
			'recovered_revenue'  => $this->recovered_revenue,
		];
	}

	/**
	 * Check if the cart is empty.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return empty( $this->items );
	}

	/**
	 * Get the item count.
	 *
	 * Counts total quantity of all items. Items without an explicit
	 * 'quantity' field are counted as 1 (single item).
	 *
	 * @return int Total quantity of all items.
	 */
	public function getItemCount(): int {
		return array_reduce(
			$this->items,
			fn( int $carry, array $item ): int => $carry + max( 1, (int) ( $item['quantity'] ?? 1 ) ),
			0
		);
	}

	/**
	 * Check if the cart is expired.
	 *
	 * @return bool
	 */
	public function isExpired(): bool {
		return $this->expires_at < new \DateTimeImmutable();
	}

	/**
	 * Check if the cart is abandoned.
	 *
	 * @return bool
	 */
	public function isAbandoned(): bool {
		return self::STATUS_ABANDONED === $this->status;
	}

	/**
	 * Check if the cart was recovered.
	 *
	 * @return bool
	 */
	public function isRecovered(): bool {
		return $this->recovered;
	}

	/**
	 * Get the number of reminders sent.
	 *
	 * @return int
	 */
	public function getRemindersSent(): int {
		$count = 0;
		if ( $this->reminder_1_sent_at ) {
			++$count;
		}
		if ( $this->reminder_2_sent_at ) {
			++$count;
		}
		if ( $this->reminder_3_sent_at ) {
			++$count;
		}
		return $count;
	}

	/**
	 * Create a new cart with updated items.
	 *
	 * @param array $items The new items.
	 * @param float $total The new total.
	 * @return self
	 */
	public function withItems( array $items, float $total ): self {
		return new self(
			id: $this->id,
			customer_phone: $this->customer_phone,
			items: $items,
			total: $total,
			coupon_code: $this->coupon_code,
			shipping_address: $this->shipping_address,
			status: $this->status,
			expires_at: $this->expires_at,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			reminder_1_sent_at: $this->reminder_1_sent_at,
			reminder_2_sent_at: $this->reminder_2_sent_at,
			reminder_3_sent_at: $this->reminder_3_sent_at,
			recovered: $this->recovered,
			recovered_order_id: $this->recovered_order_id,
			recovered_revenue: $this->recovered_revenue,
		);
	}

	/**
	 * Create a new cart with updated status.
	 *
	 * @param string $status The new status (must be one of ALLOWED_STATUSES).
	 * @return self
	 * @throws \InvalidArgumentException If status is not valid.
	 */
	public function withStatus( string $status ): self {
		// Strict validation - throw exception for invalid status in write operations.
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid cart status "%s". Allowed values: %s',
					$status,
					implode( ', ', self::ALLOWED_STATUSES )
				)
			);
		}

		return new self(
			id: $this->id,
			customer_phone: $this->customer_phone,
			items: $this->items,
			total: $this->total,
			coupon_code: $this->coupon_code,
			shipping_address: $this->shipping_address,
			status: $status,
			expires_at: $this->expires_at,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			reminder_1_sent_at: $this->reminder_1_sent_at,
			reminder_2_sent_at: $this->reminder_2_sent_at,
			reminder_3_sent_at: $this->reminder_3_sent_at,
			recovered: $this->recovered,
			recovered_order_id: $this->recovered_order_id,
			recovered_revenue: $this->recovered_revenue,
		);
	}
}
