<?php
/**
 * Refund Result Value Object
 *
 * Represents the result of a refund processing attempt.
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
 * Class RefundResult
 *
 * Immutable value object representing refund processing result.
 */
final class RefundResult {
	/**
	 * Status constants.
	 */
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_PENDING   = 'pending';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_MANUAL    = 'manual';

	/**
	 * Whether the refund was processed successfully.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Refund ID from the gateway.
	 *
	 * @var string
	 */
	private string $refundId;

	/**
	 * Refund status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Refund amount.
	 *
	 * @var float
	 */
	private float $amount;

	/**
	 * Processing message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Error details if refund failed.
	 *
	 * @var array{code: string, message: string}|null
	 */
	private ?array $error;

	/**
	 * Constructor.
	 *
	 * @param bool       $success  Success status.
	 * @param string     $refundId Refund ID.
	 * @param string     $status   Refund status.
	 * @param float      $amount   Refund amount.
	 * @param string     $message  Processing message.
	 * @param array|null $error    Error details.
	 */
	private function __construct(
		bool $success,
		string $refundId = '',
		string $status = '',
		float $amount = 0.0,
		string $message = '',
		?array $error = null
	) {
		$this->success  = $success;
		$this->refundId = $refundId;
		$this->status   = $status;
		$this->amount   = $amount;
		$this->message  = $message;
		$this->error    = $error;
	}

	/**
	 * Create a successful refund result.
	 *
	 * @param string $refundId Refund ID.
	 * @param float  $amount   Refund amount.
	 * @param string $message  Processing message.
	 * @return self
	 */
	public static function success( string $refundId, float $amount, string $message = '' ): self {
		return new self(
			true,
			$refundId,
			self::STATUS_COMPLETED,
			$amount,
			$message ?: __( 'Refund processed successfully.', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Create a pending refund result.
	 *
	 * @param string $refundId Refund ID.
	 * @param float  $amount   Refund amount.
	 * @param string $message  Processing message.
	 * @return self
	 */
	public static function pending( string $refundId, float $amount, string $message = '' ): self {
		return new self(
			true,
			$refundId,
			self::STATUS_PENDING,
			$amount,
			$message ?: __( 'Refund is being processed.', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Create a manual refund result (for gateways that don't support automatic refunds).
	 *
	 * @param float  $amount  Refund amount.
	 * @param string $message Processing message.
	 * @return self
	 */
	public static function manual( float $amount, string $message = '' ): self {
		return new self(
			true,
			'',
			self::STATUS_MANUAL,
			$amount,
			$message ?: __( 'Manual refund required.', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Create a failed refund result.
	 *
	 * @param string $errorCode    Error code.
	 * @param string $errorMessage Error message.
	 * @return self
	 */
	public static function failure( string $errorCode, string $errorMessage ): self {
		return new self(
			false,
			'',
			self::STATUS_FAILED,
			0.0,
			'',
			array(
				'code'    => $errorCode,
				'message' => $errorMessage,
			)
		);
	}

	/**
	 * Check if refund was successful.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Get refund ID.
	 *
	 * @return string
	 */
	public function getRefundId(): string {
		return $this->refundId;
	}

	/**
	 * Get refund status.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}

	/**
	 * Get refund amount.
	 *
	 * @return float
	 */
	public function getAmount(): float {
		return $this->amount;
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
	 * Get error details.
	 *
	 * @return array{code: string, message: string}|null
	 */
	public function getError(): ?array {
		return $this->error;
	}

	/**
	 * Check if manual refund is required.
	 *
	 * @return bool
	 */
	public function requiresManualRefund(): bool {
		return $this->status === self::STATUS_MANUAL;
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		$result = array(
			'success'   => $this->success,
			'refund_id' => $this->refundId,
			'status'    => $this->status,
			'amount'    => $this->amount,
			'message'   => $this->message,
		);

		if ( $this->error ) {
			$result['error'] = $this->error;
		}

		return $result;
	}
}
