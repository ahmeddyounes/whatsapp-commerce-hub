<?php
/**
 * Conversation Context Value Object
 *
 * Stores conversation state and temporary data for the FSM.
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
 * Class ConversationContext
 *
 * Holds the current state and data for a customer conversation.
 */
final class ConversationContext {

	/**
	 * Default idle state.
	 */
	public const STATE_IDLE = 'idle';

	/**
	 * Default timeout duration (24 hours in seconds).
	 */
	public const DEFAULT_TIMEOUT = 86400;

	/**
	 * Max history entries to keep.
	 */
	public const MAX_HISTORY_ENTRIES = 10;

	/**
	 * Current state.
	 *
	 * @var string
	 */
	protected string $currentState;

	/**
	 * State-specific data.
	 *
	 * @var array<string, mixed>
	 */
	protected array $stateData;

	/**
	 * Conversation history.
	 *
	 * @var array<int, array>
	 */
	protected array $history;

	/**
	 * Extracted entity values (slots).
	 *
	 * @var array<string, mixed>
	 */
	protected array $slots;

	/**
	 * Customer phone number.
	 *
	 * @var string
	 */
	protected string $customerPhone;

	/**
	 * When the conversation started.
	 *
	 * @var string
	 */
	protected string $startedAt;

	/**
	 * Last activity timestamp.
	 *
	 * @var string
	 */
	protected string $lastActivityAt;

	/**
	 * When the context expires.
	 *
	 * @var string
	 */
	protected string $expiresAt;

	/**
	 * Constructor.
	 *
	 * @param array $data Context data.
	 */
	public function __construct( array $data = [] ) {
		$this->currentState   = $data['current_state'] ?? self::STATE_IDLE;
		$this->stateData      = $data['state_data'] ?? [];
		$this->history        = $data['conversation_history'] ?? $data['history'] ?? [];
		$this->slots          = $data['slots'] ?? [];
		$this->customerPhone  = $data['customer_phone'] ?? '';
		$this->startedAt      = $data['started_at'] ?? gmdate( 'Y-m-d H:i:s' );
		$this->lastActivityAt = $data['last_activity_at'] ?? gmdate( 'Y-m-d H:i:s' );

		if ( isset( $data['expires_at'] ) ) {
			$this->expiresAt = $data['expires_at'];
		} else {
			$this->expiresAt = gmdate( 'Y-m-d H:i:s', strtotime( $this->lastActivityAt ) + self::DEFAULT_TIMEOUT );
		}
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
	 * Set current state.
	 *
	 * @param string $state New state.
	 * @return void
	 */
	public function setCurrentState( string $state ): void {
		$this->currentState = $state;
		$this->updateActivity();
	}

	/**
	 * Get state data.
	 *
	 * @return array
	 */
	public function getStateData(): array {
		return $this->stateData;
	}

	/**
	 * Get combined context data.
	 *
	 * @return array
	 */
	public function getData(): array {
		return array_merge( $this->stateData, $this->slots );
	}

	/**
	 * Get state data value.
	 *
	 * @param string $key     Data key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->stateData[ $key ] ?? $default;
	}

	/**
	 * Set state data value.
	 *
	 * @param string $key   Data key.
	 * @param mixed  $value Data value.
	 * @return void
	 */
	public function set( string $key, mixed $value ): void {
		$this->stateData[ $key ] = $value;
		$this->updateActivity();
	}

	/**
	 * Update state data.
	 *
	 * @param array $data Data to merge.
	 * @return void
	 */
	public function updateStateData( array $data ): void {
		$this->stateData = array_merge( $this->stateData, $data );
		$this->updateActivity();
	}

	/**
	 * Clear state data.
	 *
	 * @return void
	 */
	public function clearStateData(): void {
		$this->stateData = [];
		$this->updateActivity();
	}

	/**
	 * Get customer phone.
	 *
	 * @return string
	 */
	public function getCustomerPhone(): string {
		return $this->customerPhone;
	}

	/**
	 * Set customer phone.
	 *
	 * @param string $phone Phone number.
	 * @return void
	 */
	public function setCustomerPhone( string $phone ): void {
		$this->customerPhone = $phone;
	}

	/**
	 * Get a slot value.
	 *
	 * @param string $name    Slot name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function getSlot( string $name, mixed $default = null ): mixed {
		return $this->slots[ $name ] ?? $default;
	}

	/**
	 * Set a slot value.
	 *
	 * @param string $name  Slot name.
	 * @param mixed  $value Slot value.
	 * @return void
	 */
	public function setSlot( string $name, mixed $value ): void {
		$this->slots[ $name ] = $value;
		$this->updateActivity();
	}

	/**
	 * Check if a slot exists.
	 *
	 * @param string $name Slot name.
	 * @return bool
	 */
	public function hasSlot( string $name ): bool {
		return isset( $this->slots[ $name ] );
	}

	/**
	 * Clear a slot.
	 *
	 * @param string $name Slot name.
	 * @return void
	 */
	public function clearSlot( string $name ): void {
		unset( $this->slots[ $name ] );
		$this->updateActivity();
	}

	/**
	 * Get all slots.
	 *
	 * @return array
	 */
	public function getAllSlots(): array {
		return $this->slots;
	}

	/**
	 * Clear all slots.
	 *
	 * @return void
	 */
	public function clearAllSlots(): void {
		$this->slots = [];
		$this->updateActivity();
	}

	/**
	 * Get conversation history.
	 *
	 * @return array
	 */
	public function getHistory(): array {
		return $this->history;
	}

	/**
	 * Add entry to conversation history.
	 *
	 * @param string $event     Event name.
	 * @param string $fromState From state.
	 * @param string $toState   To state.
	 * @param array  $payload   Event payload.
	 * @return void
	 */
	public function addHistoryEntry( string $event, string $fromState, string $toState, array $payload = [] ): void {
		$this->history[] = [
			'timestamp'  => gmdate( 'Y-m-d H:i:s' ),
			'event'      => $event,
			'from_state' => $fromState,
			'to_state'   => $toState,
			'payload'    => $payload,
		];

		// Keep only last N entries.
		if ( count( $this->history ) > self::MAX_HISTORY_ENTRIES ) {
			$this->history = array_slice( $this->history, -self::MAX_HISTORY_ENTRIES );
		}

		$this->updateActivity();
	}

	/**
	 * Add message exchange to history.
	 *
	 * @param string $userMessage User message.
	 * @param string $botResponse Bot response.
	 * @return void
	 */
	public function addExchange( string $userMessage, string $botResponse ): void {
		$this->history[] = [
			'timestamp'    => gmdate( 'Y-m-d H:i:s' ),
			'user_message' => $userMessage,
			'bot_response' => $botResponse,
		];

		if ( count( $this->history ) > self::MAX_HISTORY_ENTRIES ) {
			$this->history = array_slice( $this->history, -self::MAX_HISTORY_ENTRIES );
		}

		$this->updateActivity();
	}

	/**
	 * Get the last exchange.
	 *
	 * @return array|null
	 */
	public function getLastExchange(): ?array {
		if ( empty( $this->history ) ) {
			return null;
		}
		return end( $this->history ) ?: null;
	}

	/**
	 * Get started at timestamp.
	 *
	 * @return string
	 */
	public function getStartedAt(): string {
		return $this->startedAt;
	}

	/**
	 * Get last activity timestamp.
	 *
	 * @return string
	 */
	public function getLastActivityAt(): string {
		return $this->lastActivityAt;
	}

	/**
	 * Get expires at timestamp.
	 *
	 * @return string
	 */
	public function getExpiresAt(): string {
		return $this->expiresAt;
	}

	/**
	 * Check if context has timed out.
	 *
	 * @param int $timeoutSeconds Timeout in seconds.
	 * @return bool
	 */
	public function isTimedOut( int $timeoutSeconds = self::DEFAULT_TIMEOUT ): bool {
		$lastActivityTimestamp = strtotime( $this->lastActivityAt );
		return ( time() - $lastActivityTimestamp ) >= $timeoutSeconds;
	}

	/**
	 * Check if context is expired.
	 *
	 * @return bool
	 */
	public function isExpired(): bool {
		return time() >= strtotime( $this->expiresAt );
	}

	/**
	 * Get inactive duration in seconds.
	 *
	 * @return int
	 */
	public function getInactiveDuration(): int {
		return time() - strtotime( $this->lastActivityAt );
	}

	/**
	 * Reset context to initial state.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->currentState   = self::STATE_IDLE;
		$this->stateData      = [];
		$this->history        = [];
		$this->startedAt      = gmdate( 'Y-m-d H:i:s' );
		$this->lastActivityAt = gmdate( 'Y-m-d H:i:s' );
		$this->expiresAt      = gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_TIMEOUT );
	}

	/**
	 * Update activity timestamps.
	 *
	 * @return void
	 */
	protected function updateActivity(): void {
		$this->lastActivityAt = gmdate( 'Y-m-d H:i:s' );
		$this->expiresAt      = gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_TIMEOUT );
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return [
			'current_state'        => $this->currentState,
			'state_data'           => $this->stateData,
			'conversation_history' => $this->history,
			'slots'                => $this->slots,
			'customer_phone'       => $this->customerPhone,
			'started_at'           => $this->startedAt,
			'last_activity_at'     => $this->lastActivityAt,
			'expires_at'           => $this->expiresAt,
		];
	}

	/**
	 * Convert to JSON.
	 *
	 * @return string
	 */
	public function toJson(): string {
		return (string) wp_json_encode( $this->toArray() );
	}

	/**
	 * Create from array.
	 *
	 * @param array $data Context data.
	 * @return static
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * Create from JSON.
	 *
	 * @param string $json JSON string.
	 * @return static
	 */
	public static function fromJson( string $json ): self {
		$data = json_decode( $json, true );
		return new self( is_array( $data ) ? $data : [] );
	}

	/**
	 * Create new context for phone.
	 *
	 * @param string $phone Customer phone.
	 * @return static
	 */
	public static function forPhone( string $phone ): self {
		return new self( [ 'customer_phone' => $phone ] );
	}

	/**
	 * Build AI context string for prompts.
	 *
	 * @return string
	 */
	public function buildAiContext(): string {
		$parts = [];

		// Business information.
		$businessName = get_bloginfo( 'name' );
		$parts[]      = "Business: {$businessName}";
		$parts[]      = "Current State: {$this->currentState}";

		// Filled slots.
		if ( ! empty( $this->slots ) ) {
			$parts[] = "\nExtracted Information:";
			foreach ( $this->slots as $name => $value ) {
				$displayValue = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
				$parts[]      = "- {$name}: {$displayValue}";
			}
		}

		// Recent conversation history.
		if ( ! empty( $this->history ) ) {
			$parts[] = "\nRecent Conversation:";
			foreach ( $this->history as $entry ) {
				if ( isset( $entry['user_message'], $entry['bot_response'] ) ) {
					$parts[] = "User: {$entry['user_message']}";
					$parts[] = "Bot: {$entry['bot_response']}";
				}
			}
		}

		return implode( "\n", $parts );
	}
}
