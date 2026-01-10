<?php
/**
 * Conversation Context
 *
 * Domain service for managing conversation context and state.
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
 * Class Context
 *
 * Manages conversation context including state, variables, and history.
 *
 * Note: This is a transitional class. Full migration will refactor
 * the legacy implementation in a future phase.
 */
class Context {
	/**
	 * Constructor.
	 *
	 * @param string $conversationId Conversation identifier.
	 * @param string $currentState   Current state.
	 * @param array  $variables      Context variables.
	 * @param array  $history        Message history.
	 */
	public function __construct(
		private string $conversationId,
		private string $currentState = 'initial',
		private array $variables = array(),
		private array $history = array()
	) {
	}

	/**
	 * Get conversation ID.
	 *
	 * @return string
	 */
	public function getConversationId(): string {
		return $this->conversationId;
	}

	/**
	 * Get current state.
	 *
	 * @return string
	 */
	public function getCurrentState(): string {
		return $this->currentState;
	}

	/**
	 * Set state.
	 *
	 * @param string $state New state.
	 * @return void
	 */
	public function setState( string $state ): void {
		$this->currentState = $state;
	}

	/**
	 * Get variable.
	 *
	 * @param string $key     Variable key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->variables[ $key ] ?? $default;
	}

	/**
	 * Set variable.
	 *
	 * @param string $key   Variable key.
	 * @param mixed  $value Variable value.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->variables[ $key ] = $value;
	}

	/**
	 * Check if variable exists.
	 *
	 * @param string $key Variable key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->variables[ $key ] );
	}

	/**
	 * Get all variables.
	 *
	 * @return array
	 */
	public function getVariables(): array {
		return $this->variables;
	}

	/**
	 * Add message to history.
	 *
	 * @param array $message Message data.
	 * @return void
	 */
	public function addMessage( array $message ): void {
		$this->history[] = $message;
	}

	/**
	 * Get message history.
	 *
	 * @param int $limit Maximum number of messages to return.
	 * @return array
	 */
	public function getHistory( int $limit = 10 ): array {
		return array_slice( $this->history, -$limit );
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'conversation_id' => $this->conversationId,
			'current_state'   => $this->currentState,
			'variables'       => $this->variables,
			'history'         => $this->history,
		);
	}

	/**
	 * Create from array.
	 *
	 * @param array $data Context data.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			$data['conversation_id'] ?? '',
			$data['current_state'] ?? 'initial',
			$data['variables'] ?? array(),
			$data['history'] ?? array()
		);
	}
}
