<?php
/**
 * Conversation State Machine
 *
 * Domain service for managing conversation state transitions.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Domain\Conversation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StateMachine
 *
 * Implements finite state machine for conversation flow.
 *
 * Note: This is a transitional class.
 */
class StateMachine {
	/**
	 * State constants.
	 */
	public const STATE_INITIAL   = 'initial';
	public const STATE_BROWSING  = 'browsing';
	public const STATE_CART      = 'cart';
	public const STATE_CHECKOUT  = 'checkout';
	public const STATE_PAYMENT   = 'payment';
	public const STATE_COMPLETED = 'completed';
	public const STATE_ABANDONED = 'abandoned';

	/**
	 * Valid state transitions.
	 */
	private array $transitions = [
		self::STATE_INITIAL   => [ self::STATE_BROWSING, self::STATE_ABANDONED ],
		self::STATE_BROWSING  => [ self::STATE_CART, self::STATE_INITIAL, self::STATE_ABANDONED ],
		self::STATE_CART      => [ self::STATE_CHECKOUT, self::STATE_BROWSING, self::STATE_ABANDONED ],
		self::STATE_CHECKOUT  => [ self::STATE_PAYMENT, self::STATE_CART, self::STATE_ABANDONED ],
		self::STATE_PAYMENT   => [ self::STATE_COMPLETED, self::STATE_CHECKOUT, self::STATE_ABANDONED ],
		self::STATE_COMPLETED => [ self::STATE_INITIAL, self::STATE_BROWSING ],
		self::STATE_ABANDONED => [ self::STATE_INITIAL, self::STATE_BROWSING ],
	];

	/**
	 * Conversation context.
	 */
	private Context $context;

	/**
	 * Constructor.
	 *
	 * @param Context $context Conversation context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Get current state.
	 *
	 * @return string
	 */
	public function getCurrentState(): string {
		return $this->context->getCurrentState();
	}

	/**
	 * Check if transition is valid.
	 *
	 * @param string $toState Target state.
	 * @return bool
	 */
	public function canTransitionTo( string $toState ): bool {
		$currentState = $this->getCurrentState();

		if ( ! isset( $this->transitions[ $currentState ] ) ) {
			return false;
		}

		return in_array( $toState, $this->transitions[ $currentState ], true );
	}

	/**
	 * Transition to new state.
	 *
	 * @param string $toState Target state.
	 * @return bool True if transition successful.
	 * @throws \InvalidArgumentException If transition is invalid.
	 */
	public function transitionTo( string $toState ): bool {
		if ( ! $this->canTransitionTo( $toState ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Invalid state transition from %s to %s',
					$this->getCurrentState(),
					$toState
				)
			);
		}

		$this->context->setState( $toState );
		return true;
	}

	/**
	 * Get available transitions from current state.
	 *
	 * @return array
	 */
	public function getAvailableTransitions(): array {
		$currentState = $this->getCurrentState();
		return $this->transitions[ $currentState ] ?? [];
	}

	/**
	 * Check if state is terminal.
	 *
	 * @param string|null $state State to check (null = current).
	 * @return bool
	 */
	public function isTerminalState( ?string $state = null ): bool {
		$state = $state ?? $this->getCurrentState();
		return in_array( $state, [ self::STATE_COMPLETED, self::STATE_ABANDONED ], true );
	}

	/**
	 * Reset to initial state.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->context->setState( self::STATE_INITIAL );
	}
}
