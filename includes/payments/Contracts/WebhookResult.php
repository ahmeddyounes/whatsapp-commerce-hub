<?php
/**
 * Webhook Result Value Object
 *
 * Represents the result of processing a payment webhook.
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
 * Class WebhookResult
 *
 * Immutable value object representing webhook processing result.
 */
final class WebhookResult {
	/**
	 * Payment status constants.
	 */
	public const STATUS_COMPLETED        = 'completed';
	public const STATUS_PENDING          = 'pending';
	public const STATUS_FAILED           = 'failed';
	public const STATUS_ALREADY_COMPLETED = 'already_completed';
	public const STATUS_UNKNOWN          = 'unknown';

	/**
	 * Whether the webhook was processed successfully.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Associated WooCommerce order ID.
	 *
	 * @var int
	 */
	private int $orderId;

	/**
	 * Payment status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Processing message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Transaction ID from the gateway.
	 *
	 * @var string
	 */
	private string $transactionId;

	/**
	 * Additional metadata.
	 *
	 * @var array
	 */
	private array $metadata;

	/**
	 * Constructor.
	 *
	 * @param bool   $success       Success status.
	 * @param int    $orderId       Order ID.
	 * @param string $status        Payment status.
	 * @param string $message       Processing message.
	 * @param string $transactionId Transaction ID.
	 * @param array  $metadata      Additional metadata.
	 */
	private function __construct(
		bool $success,
		int $orderId = 0,
		string $status = '',
		string $message = '',
		string $transactionId = '',
		array $metadata = array()
	) {
		$this->success       = $success;
		$this->orderId       = $orderId;
		$this->status        = $status;
		$this->message       = $message;
		$this->transactionId = $transactionId;
		$this->metadata      = $metadata;
	}

	/**
	 * Create a successful webhook result.
	 *
	 * @param int    $orderId       Order ID.
	 * @param string $status        Payment status.
	 * @param string $message       Processing message.
	 * @param string $transactionId Transaction ID.
	 * @param array  $metadata      Additional metadata.
	 * @return self
	 */
	public static function success(
		int $orderId,
		string $status,
		string $message,
		string $transactionId = '',
		array $metadata = array()
	): self {
		return new self( true, $orderId, $status, $message, $transactionId, $metadata );
	}

	/**
	 * Create a failed webhook result.
	 *
	 * @param string $message  Error message.
	 * @param array  $metadata Additional metadata.
	 * @return self
	 */
	public static function failure( string $message, array $metadata = array() ): self {
		return new self( false, 0, self::STATUS_UNKNOWN, $message, '', $metadata );
	}

	/**
	 * Create a result for already completed orders.
	 *
	 * @param int    $orderId       Order ID.
	 * @param string $transactionId Transaction ID.
	 * @return self
	 */
	public static function alreadyCompleted( int $orderId, string $transactionId = '' ): self {
		return new self(
			true,
			$orderId,
			self::STATUS_ALREADY_COMPLETED,
			__( 'Order already paid.', 'whatsapp-commerce-hub' ),
			$transactionId
		);
	}

	/**
	 * Check if webhook was processed successfully.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Get order ID.
	 *
	 * @return int
	 */
	public function getOrderId(): int {
		return $this->orderId;
	}

	/**
	 * Get payment status.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * Get processing message.
	 *
	 * @return string
	 */
	public function getMessage(): string {
		return $this->message;
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
	 * Get metadata.
	 *
	 * @return array
	 */
	public function getMetadata(): array {
		return $this->metadata;
	}

	/**
	 * Check if payment was completed.
	 *
	 * @return bool
	 */
	public function isCompleted(): bool {
		return $this->status === self::STATUS_COMPLETED;
	}

	/**
	 * Check if order was already completed.
	 *
	 * @return bool
	 */
	public function isAlreadyCompleted(): bool {
		return $this->status === self::STATUS_ALREADY_COMPLETED;
	}

	/**
	 * Convert to array (for backward compatibility).
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'success'        => $this->success,
			'order_id'       => $this->orderId,
			'status'         => $this->status,
			'message'        => $this->message,
			'transaction_id' => $this->transactionId,
			'metadata'       => $this->metadata,
		);
	}
}
