<?php
/**
 * Action Result Value Object
 *
 * Represents the result of executing a flow action.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\ValueObjects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActionResult
 *
 * Contains the result of a flow action execution, including success status,
 * response messages, optional state override, and updated context data.
 */
class ActionResult {

	/**
	 * Whether the action executed successfully.
	 *
	 * @var bool
	 */
	protected bool $success;

	/**
	 * Array of message builders to send to the customer.
	 *
	 * @var array
	 */
	protected array $messages;

	/**
	 * Optional state override - if set, FSM will transition to this state.
	 *
	 * @var string|null
	 */
	protected ?string $nextState;

	/**
	 * Updated context data to merge into conversation context.
	 *
	 * @var array<string, mixed>
	 */
	protected array $contextUpdates;

	/**
	 * Error message if action failed.
	 *
	 * @var string|null
	 */
	protected ?string $errorMessage;

	/**
	 * Error code if action failed.
	 *
	 * @var string|null
	 */
	protected ?string $errorCode;

	/**
	 * Constructor.
	 *
	 * @param bool        $success        Whether the action succeeded.
	 * @param array       $messages       Array of message builders.
	 * @param string|null $nextState      Optional state override.
	 * @param array       $contextUpdates Context updates.
	 * @param string|null $errorMessage   Error message.
	 * @param string|null $errorCode      Error code.
	 */
	public function __construct(
		bool $success = true,
		array $messages = array(),
		?string $nextState = null,
		array $contextUpdates = array(),
		?string $errorMessage = null,
		?string $errorCode = null
	) {
		$this->success        = $success;
		$this->messages       = $messages;
		$this->nextState      = $nextState;
		$this->contextUpdates = $contextUpdates;
		$this->errorMessage   = $errorMessage;
		$this->errorCode      = $errorCode;
	}

	/**
	 * Create a successful result.
	 *
	 * @param array       $messages       Response messages.
	 * @param string|null $nextState      Optional state override.
	 * @param array       $contextUpdates Context updates.
	 * @return static
	 */
	public static function success(
		array $messages = array(),
		?string $nextState = null,
		array $contextUpdates = array()
	): static {
		return new static( true, $messages, $nextState, $contextUpdates );
	}

	/**
	 * Create a failure result.
	 *
	 * @param string      $errorMessage   Error message.
	 * @param array       $messages       Error response messages.
	 * @param string|null $errorCode      Error code.
	 * @param string|null $nextState      Optional state override.
	 * @param array       $contextUpdates Context updates.
	 * @return static
	 */
	public static function failure(
		string $errorMessage,
		array $messages = array(),
		?string $errorCode = null,
		?string $nextState = null,
		array $contextUpdates = array()
	): static {
		return new static( false, $messages, $nextState, $contextUpdates, $errorMessage, $errorCode );
	}

	/**
	 * Create a result that requires state transition.
	 *
	 * @param string $nextState New state to transition to.
	 * @param array  $messages  Response messages.
	 * @param array  $context   Context updates.
	 * @return static
	 */
	public static function transitionTo( string $nextState, array $messages = array(), array $context = array() ): static {
		return new static( true, $messages, $nextState, $context );
	}

	/**
	 * Check if action was successful.
	 *
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->success;
	}

	/**
	 * Check if action failed.
	 *
	 * @return bool
	 */
	public function isFailure(): bool {
		return ! $this->success;
	}

	/**
	 * Get all response messages.
	 *
	 * @return array
	 */
	public function getMessages(): array {
		return $this->messages;
	}

	/**
	 * Get built messages ready for WhatsApp API.
	 *
	 * @return array Array of built message arrays.
	 */
	public function getBuiltMessages(): array {
		$built = array();

		foreach ( $this->messages as $message ) {
			if ( is_object( $message ) && method_exists( $message, 'build' ) ) {
				$built[] = $message->build();
			} elseif ( is_array( $message ) ) {
				$built[] = $message;
			}
		}

		return $built;
	}

	/**
	 * Add a response message.
	 *
	 * @param mixed $message Message to add.
	 * @return static New instance with message added.
	 */
	public function withMessage( mixed $message ): static {
		$new            = clone $this;
		$new->messages[] = $message;
		return $new;
	}

	/**
	 * Set next state.
	 *
	 * @param string $state State to transition to.
	 * @return static New instance with state set.
	 */
	public function withNextState( string $state ): static {
		$new            = clone $this;
		$new->nextState = $state;
		return $new;
	}

	/**
	 * Add context updates.
	 *
	 * @param array $context Context data to merge.
	 * @return static New instance with context updated.
	 */
	public function withContext( array $context ): static {
		$new                 = clone $this;
		$new->contextUpdates = array_merge( $new->contextUpdates, $context );
		return $new;
	}

	/**
	 * Get next state if set.
	 *
	 * @return string|null
	 */
	public function getNextState(): ?string {
		return $this->nextState;
	}

	/**
	 * Check if result has a state transition.
	 *
	 * @return bool
	 */
	public function hasStateTransition(): bool {
		return null !== $this->nextState;
	}

	/**
	 * Get updated context.
	 *
	 * @return array
	 */
	public function getContextUpdates(): array {
		return $this->contextUpdates;
	}

	/**
	 * Get error message.
	 *
	 * @return string|null
	 */
	public function getErrorMessage(): ?string {
		return $this->errorMessage;
	}

	/**
	 * Get error code.
	 *
	 * @return string|null
	 */
	public function getErrorCode(): ?string {
		return $this->errorCode;
	}

	/**
	 * Check if result has messages.
	 *
	 * @return bool
	 */
	public function hasMessages(): bool {
		return ! empty( $this->messages );
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'success'         => $this->success,
			'messages'        => $this->getBuiltMessages(),
			'next_state'      => $this->nextState,
			'context_updates' => $this->contextUpdates,
			'error_message'   => $this->errorMessage,
			'error_code'      => $this->errorCode,
		);
	}
}
