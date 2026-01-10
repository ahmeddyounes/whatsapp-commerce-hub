<?php
/**
 * Payment Result Value Object
 *
 * Represents the result of a payment processing attempt.
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
 * Class PaymentResult
 *
 * Immutable value object representing payment processing result.
 */
final class PaymentResult {
	/**
	 * Whether the payment was initiated successfully.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Transaction ID from the gateway.
	 *
	 * @var string
	 */
	private string $transactionId;

	/**
	 * URL for customer to complete payment (if applicable).
	 *
	 * @var string
	 */
	private string $paymentUrl;

	/**
	 * Message to send to customer.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Error details if payment failed.
	 *
	 * @var array{code: string, message: string}|null
	 */
	private ?array $error;

	/**
	 * Additional metadata.
	 *
	 * @var array
	 */
	private array $metadata;

	/**
	 * Constructor.
	 *
	 * @param bool        $success       Success status.
	 * @param string      $transactionId Transaction ID.
	 * @param string      $paymentUrl    Payment URL.
	 * @param string      $message       Customer message.
	 * @param array|null  $error         Error details.
	 * @param array       $metadata      Additional metadata.
	 */
	private function __construct(
		bool $success,
		string $transactionId = '',
		string $paymentUrl = '',
		string $message = '',
		?array $error = null,
		array $metadata = array()
	) {
		$this->success       = $success;
		$this->transactionId = $transactionId;
		$this->paymentUrl    = $paymentUrl;
		$this->message       = $message;
		$this->error         = $error;
		$this->metadata      = $metadata;
	}

	/**
	 * Create a successful payment result.
	 *
	 * @param string $transactionId Transaction ID.
	 * @param string $message       Customer message.
	 * @param string $paymentUrl    Payment URL (optional).
	 * @param array  $metadata      Additional metadata.
	 * @return self
	 */
	public static function success(
		string $transactionId,
		string $message,
		string $paymentUrl = '',
		array $metadata = array()
	): self {
		return new self( true, $transactionId, $paymentUrl, $message, null, $metadata );
	}

	/**
	 * Create a failed payment result.
	 *
	 * @param string $errorCode    Error code.
	 * @param string $errorMessage Error message.
	 * @param array  $metadata     Additional metadata.
	 * @return self
	 */
	public static function failure( string $errorCode, string $errorMessage, array $metadata = array() ): self {
		return new self(
			false,
			'',
			'',
			'',
			array(
				'code'    => $errorCode,
				'message' => $errorMessage,
			),
			$metadata
		);
	}

	/**
	 * Check if payment was successful.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
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
	 * Get payment URL.
	 *
	 * @return string
	 */
	public function getPaymentUrl(): string {
		return $this->paymentUrl;
	}

	/**
	 * Get customer message.
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
	 * Get error code.
	 *
	 * @return string
	 */
	public function getErrorCode(): string {
		return $this->error['code'] ?? '';
	}

	/**
	 * Get error message.
	 *
	 * @return string
	 */
	public function getErrorMessage(): string {
		return $this->error['message'] ?? '';
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
	 * Convert to array (for backward compatibility).
	 *
	 * @return array
	 */
	public function toArray(): array {
		$result = array(
			'success'        => $this->success,
			'transaction_id' => $this->transactionId,
			'payment_url'    => $this->paymentUrl,
			'message'        => $this->message,
		);

		if ( $this->error ) {
			$result['error'] = $this->error;
		}

		if ( ! empty( $this->metadata ) ) {
			$result['metadata'] = $this->metadata;
		}

		return $result;
	}
}
