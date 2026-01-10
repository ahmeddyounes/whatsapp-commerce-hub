<?php
/**
 * Checkout Response Value Object
 *
 * Represents the result of a checkout operation.
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
 * Class CheckoutResponse
 *
 * Immutable value object representing a checkout operation result.
 */
final class CheckoutResponse {

	/**
	 * Checkout steps.
	 */
	public const STEP_ADDRESS   = 'address';
	public const STEP_SHIPPING  = 'shipping';
	public const STEP_PAYMENT   = 'payment';
	public const STEP_REVIEW    = 'review';
	public const STEP_CONFIRM   = 'confirm';
	public const STEP_COMPLETED = 'completed';

	/**
	 * Constructor.
	 *
	 * @param bool        $success      Whether the operation succeeded.
	 * @param string      $step         Current checkout step.
	 * @param array       $messages     Messages to send to customer.
	 * @param array       $data         Additional response data.
	 * @param string|null $error        Error message if failed.
	 * @param string|null $error_code   Error code for programmatic handling.
	 * @param int|null    $order_id     WooCommerce order ID if created.
	 * @param string|null $next_step    Next step in the flow.
	 * @param array       $step_data    Data for the current/next step.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $step,
		public readonly array $messages = array(),
		public readonly array $data = array(),
		public readonly ?string $error = null,
		public readonly ?string $error_code = null,
		public readonly ?int $order_id = null,
		public readonly ?string $next_step = null,
		public readonly array $step_data = array(),
	) {}

	/**
	 * Create a successful response.
	 *
	 * @param string      $step      Current step.
	 * @param array       $messages  Messages to send.
	 * @param array       $data      Additional data.
	 * @param string|null $next_step Next step.
	 * @param array       $step_data Step-specific data.
	 * @return self
	 */
	public static function success(
		string $step,
		array $messages = array(),
		array $data = array(),
		?string $next_step = null,
		array $step_data = array()
	): self {
		return new self(
			success: true,
			step: $step,
			messages: $messages,
			data: $data,
			next_step: $next_step,
			step_data: $step_data,
		);
	}

	/**
	 * Create a failure response.
	 *
	 * @param string      $step       Current step.
	 * @param string      $error      Error message.
	 * @param string|null $error_code Error code.
	 * @param array       $messages   Messages to send (e.g., error message to customer).
	 * @param array       $data       Additional data.
	 * @return self
	 */
	public static function failure(
		string $step,
		string $error,
		?string $error_code = null,
		array $messages = array(),
		array $data = array()
	): self {
		return new self(
			success: false,
			step: $step,
			messages: $messages,
			data: $data,
			error: $error,
			error_code: $error_code,
		);
	}

	/**
	 * Create a completed checkout response.
	 *
	 * @param int   $order_id WooCommerce order ID.
	 * @param array $messages Confirmation messages.
	 * @param array $data     Additional data (order details, etc.).
	 * @return self
	 */
	public static function completed( int $order_id, array $messages = array(), array $data = array() ): self {
		return new self(
			success: true,
			step: self::STEP_COMPLETED,
			messages: $messages,
			data: $data,
			order_id: $order_id,
		);
	}

	/**
	 * Check if checkout is complete.
	 *
	 * @return bool
	 */
	public function isCompleted(): bool {
		return self::STEP_COMPLETED === $this->step && null !== $this->order_id;
	}

	/**
	 * Check if there's an error.
	 *
	 * @return bool
	 */
	public function hasError(): bool {
		return ! $this->success && null !== $this->error;
	}

	/**
	 * Get first message or null.
	 *
	 * @return mixed|null
	 */
	public function getFirstMessage() {
		return $this->messages[0] ?? null;
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'success'    => $this->success,
			'step'       => $this->step,
			'messages'   => $this->messages,
			'data'       => $this->data,
			'error'      => $this->error,
			'error_code' => $this->error_code,
			'order_id'   => $this->order_id,
			'next_step'  => $this->next_step,
			'step_data'  => $this->step_data,
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
}
