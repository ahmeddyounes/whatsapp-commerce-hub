<?php
/**
 * Payment Status Value Object
 *
 * Represents the current status of a payment.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments\Contracts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentStatus
 *
 * Immutable value object representing payment status.
 */
final class PaymentStatus {
	/**
	 * Status constants.
	 */
	public const COMPLETED = 'completed';
	public const PENDING   = 'pending';
	public const FAILED    = 'failed';
	public const UNKNOWN   = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param string $status        Payment status.
	 * @param string $transactionId Transaction ID.
	 * @param float  $amount        Payment amount.
	 * @param string $currency      Currency code.
	 * @param array  $metadata      Additional metadata.
	 */
	public function __construct(
		private readonly string $status,
		private readonly string $transactionId,
		private readonly float $amount = 0.0,
		private readonly string $currency = '',
		private readonly array $metadata = []
	) {
	}

	/**
	 * Create completed status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @param float  $amount        Payment amount.
	 * @param string $currency      Currency code.
	 * @param array  $metadata      Additional metadata.
	 * @return self
	 */
	public static function completed(
		string $transactionId,
		float $amount = 0.0,
		string $currency = '',
		array $metadata = []
	): self {
		return new self( self::COMPLETED, $transactionId, $amount, $currency, $metadata );
	}

	/**
	 * Create pending status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @param float  $amount        Payment amount.
	 * @param string $currency      Currency code.
	 * @param array  $metadata      Additional metadata.
	 * @return self
	 */
	public static function pending(
		string $transactionId,
		float $amount = 0.0,
		string $currency = '',
		array $metadata = []
	): self {
		return new self( self::PENDING, $transactionId, $amount, $currency, $metadata );
	}

	/**
	 * Create failed status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @param array  $metadata      Additional metadata.
	 * @return self
	 */
	public static function failed( string $transactionId, array $metadata = [] ): self {
		return new self( self::FAILED, $transactionId, 0.0, '', $metadata );
	}

	/**
	 * Create unknown status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @return self
	 */
	public static function unknown( string $transactionId ): self {
		return new self( self::UNKNOWN, $transactionId );
	}

	/**
	 * Get status.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * Get transaction ID.
	 *
	 * @return string
	 */
	public function getTransactionId(): string {
		return $this->transactionId;
	}

	/**
	 * Get amount.
	 *
	 * @return float
	 */
	public function getAmount(): float {
		return $this->amount;
	}

	/**
	 * Get currency.
	 *
	 * @return string
	 */
	public function getCurrency(): string {
		return $this->currency;
	}

	/**
	 * Get metadata.
	 *
	 * @return array
	 */
	public function getMetadata(): array {
		return $this->metadata;
	}

	/**
	 * Check if payment is completed.
	 *
	 * @return bool
	 */
	public function isCompleted(): bool {
		return $this->status === self::COMPLETED;
	}

	/**
	 * Check if payment is pending.
	 *
	 * @return bool
	 */
	public function isPending(): bool {
		return $this->status === self::PENDING;
	}

	/**
	 * Check if payment failed.
	 *
	 * @return bool
	 */
	public function isFailed(): bool {
		return $this->status === self::FAILED;
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return [
			'status'         => $this->status,
			'transaction_id' => $this->transactionId,
			'amount'         => $this->amount,
			'currency'       => $this->currency,
			'metadata'       => $this->metadata,
		];
	}
}
