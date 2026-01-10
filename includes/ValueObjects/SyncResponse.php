<?php
/**
 * Sync Response Value Object
 *
 * Represents the result of a synchronization operation.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\ValueObjects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SyncResponse
 *
 * Immutable value object representing a synchronization operation result.
 */
final class SyncResponse {

	/**
	 * Sync types.
	 */
	public const TYPE_PRODUCT   = 'product';
	public const TYPE_ORDER     = 'order';
	public const TYPE_INVENTORY = 'inventory';
	public const TYPE_CATALOG   = 'catalog';
	public const TYPE_CUSTOMER  = 'customer';

	/**
	 * Sync statuses.
	 */
	public const STATUS_SUCCESS = 'success';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param string $type          Sync type.
	 * @param string $status        Overall status.
	 * @param int    $total_items   Total items to sync.
	 * @param int    $synced_count  Successfully synced count.
	 * @param int    $failed_count  Failed count.
	 * @param int    $skipped_count Skipped count.
	 * @param array  $errors        Array of error messages.
	 * @param array  $synced_ids    IDs of successfully synced items.
	 * @param array  $failed_ids    IDs of failed items.
	 * @param array  $details       Additional sync details.
	 * @param float  $duration      Sync duration in seconds.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $status,
		public readonly int $total_items,
		public readonly int $synced_count,
		public readonly int $failed_count,
		public readonly int $skipped_count = 0,
		public readonly array $errors = array(),
		public readonly array $synced_ids = array(),
		public readonly array $failed_ids = array(),
		public readonly array $details = array(),
		public readonly float $duration = 0.0,
	) {}

	/**
	 * Create a successful sync response.
	 *
	 * @param string $type        Sync type.
	 * @param int    $count       Number of synced items.
	 * @param array  $synced_ids  IDs of synced items.
	 * @param array  $details     Additional details.
	 * @param float  $duration    Duration in seconds.
	 * @return self
	 */
	public static function success(
		string $type,
		int $count,
		array $synced_ids = array(),
		array $details = array(),
		float $duration = 0.0
	): self {
		return new self(
			type: $type,
			status: self::STATUS_SUCCESS,
			total_items: $count,
			synced_count: $count,
			failed_count: 0,
			synced_ids: $synced_ids,
			details: $details,
			duration: $duration,
		);
	}

	/**
	 * Create a partial sync response (some items failed).
	 *
	 * @param string $type          Sync type.
	 * @param int    $total         Total items attempted.
	 * @param int    $synced        Synced count.
	 * @param int    $failed        Failed count.
	 * @param array  $errors        Error messages.
	 * @param array  $synced_ids    IDs of synced items.
	 * @param array  $failed_ids    IDs of failed items.
	 * @param float  $duration      Duration in seconds.
	 * @return self
	 */
	public static function partial(
		string $type,
		int $total,
		int $synced,
		int $failed,
		array $errors = array(),
		array $synced_ids = array(),
		array $failed_ids = array(),
		float $duration = 0.0
	): self {
		return new self(
			type: $type,
			status: self::STATUS_PARTIAL,
			total_items: $total,
			synced_count: $synced,
			failed_count: $failed,
			errors: $errors,
			synced_ids: $synced_ids,
			failed_ids: $failed_ids,
			duration: $duration,
		);
	}

	/**
	 * Create a failed sync response.
	 *
	 * @param string $type   Sync type.
	 * @param string $error  Error message.
	 * @param int    $total  Total items that would have been synced.
	 * @param array  $errors Additional errors.
	 * @return self
	 */
	public static function failure( string $type, string $error, int $total = 0, array $errors = array() ): self {
		$all_errors = array_merge( array( $error ), $errors );

		return new self(
			type: $type,
			status: self::STATUS_FAILED,
			total_items: $total,
			synced_count: 0,
			failed_count: $total,
			errors: $all_errors,
		);
	}

	/**
	 * Create a skipped sync response.
	 *
	 * @param string $type   Sync type.
	 * @param string $reason Reason for skipping.
	 * @param int    $count  Number of skipped items.
	 * @return self
	 */
	public static function skipped( string $type, string $reason, int $count = 0 ): self {
		return new self(
			type: $type,
			status: self::STATUS_SKIPPED,
			total_items: $count,
			synced_count: 0,
			failed_count: 0,
			skipped_count: $count,
			details: array( 'skip_reason' => $reason ),
		);
	}

	/**
	 * Check if sync was fully successful.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return self::STATUS_SUCCESS === $this->status;
	}

	/**
	 * Check if sync was partial.
	 *
	 * @return bool
	 */
	public function isPartial(): bool {
		return self::STATUS_PARTIAL === $this->status;
	}

	/**
	 * Check if sync failed completely.
	 *
	 * @return bool
	 */
	public function isFailed(): bool {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Check if sync was skipped.
	 *
	 * @return bool
	 */
	public function isSkipped(): bool {
		return self::STATUS_SKIPPED === $this->status;
	}

	/**
	 * Check if there are any errors.
	 *
	 * @return bool
	 */
	public function hasErrors(): bool {
		return ! empty( $this->errors );
	}

	/**
	 * Get success rate as percentage.
	 *
	 * @return float
	 */
	public function getSuccessRate(): float {
		if ( 0 === $this->total_items ) {
			return 100.0;
		}

		return round( ( $this->synced_count / $this->total_items ) * 100, 2 );
	}

	/**
	 * Get first error message.
	 *
	 * @return string|null
	 */
	public function getFirstError(): ?string {
		return $this->errors[0] ?? null;
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'type'          => $this->type,
			'status'        => $this->status,
			'total_items'   => $this->total_items,
			'synced_count'  => $this->synced_count,
			'failed_count'  => $this->failed_count,
			'skipped_count' => $this->skipped_count,
			'success_rate'  => $this->getSuccessRate(),
			'errors'        => $this->errors,
			'synced_ids'    => $this->synced_ids,
			'failed_ids'    => $this->failed_ids,
			'details'       => $this->details,
			'duration'      => $this->duration,
		);
	}

	/**
	 * Convert to JSON.
	 *
	 * @return string
	 */
	public function toJson(): string {
		return wp_json_encode( $this->toArray() );
	}

	/**
	 * Create a summary message.
	 *
	 * @return string
	 */
	public function getSummary(): string {
		$type_label = ucfirst( $this->type );

		switch ( $this->status ) {
			case self::STATUS_SUCCESS:
				return sprintf(
					/* translators: 1: sync type, 2: count */
					__( '%1$s sync completed: %2$d items synced successfully.', 'whatsapp-commerce-hub' ),
					$type_label,
					$this->synced_count
				);

			case self::STATUS_PARTIAL:
				return sprintf(
					/* translators: 1: sync type, 2: synced count, 3: failed count */
					__( '%1$s sync partially completed: %2$d synced, %3$d failed.', 'whatsapp-commerce-hub' ),
					$type_label,
					$this->synced_count,
					$this->failed_count
				);

			case self::STATUS_FAILED:
				return sprintf(
					/* translators: 1: sync type, 2: first error */
					__( '%1$s sync failed: %2$s', 'whatsapp-commerce-hub' ),
					$type_label,
					$this->getFirstError() ?? __( 'Unknown error', 'whatsapp-commerce-hub' )
				);

			case self::STATUS_SKIPPED:
				$reason = $this->details['skip_reason'] ?? __( 'No reason provided', 'whatsapp-commerce-hub' );
				return sprintf(
					/* translators: 1: sync type, 2: reason */
					__( '%1$s sync skipped: %2$s', 'whatsapp-commerce-hub' ),
					$type_label,
					$reason
				);

			default:
				return sprintf(
					/* translators: %s: sync type */
					__( '%s sync status unknown.', 'whatsapp-commerce-hub' ),
					$type_label
				);
		}
	}
}
