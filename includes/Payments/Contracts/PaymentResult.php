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
	 * Constructor.
	 *
	 * @param bool       $success       Success status.
	 * @param string     $transactionId Transaction ID.
	 * @param string     $paymentUrl    Payment URL.
	 * @param string     $message       Customer message.
	 * @param array|null $error         Error details.
	 * @param array      $metadata      Additional metadata.
	 */
	private function __construct(
		private readonly bool $success,
		private readonly string $transactionId = '',
		private readonly string $paymentUrl = '',
		private readonly string $message = '',
		private readonly ?array $error = null,
		private readonly array $metadata = []
	) {
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
		array $metadata = []
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
	public static function failure( string $errorCode, string $errorMessage, array $metadata = [] ): self {
		return new self(
			false,
			'',
			'',
			'',
			[
				'code'    => $errorCode,
				'message' => $errorMessage,
			],
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
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		$result = [
			'success'        => $this->success,
			'transaction_id' => $this->transactionId,
			'payment_url'    => $this->paymentUrl,
			'message'        => $this->message,
		];

		if ( $this->error ) {
			$result['error'] = $this->error;
		}

		if ( ! empty( $this->metadata ) ) {
			$result['metadata'] = $this->metadata;
		}

		return $result;
	}
}
