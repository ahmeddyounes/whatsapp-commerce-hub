<?php
/**
 * Conversation Entity
 *
 * Represents a WhatsApp conversation in the Commerce Hub.
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
 * Class Conversation
 *
 * Immutable value object representing a WhatsApp conversation.
 */
final class Conversation {

	/**
	 * Conversation statuses.
	 */
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_IDLE      = 'idle';
	public const STATUS_ESCALATED = 'escalated';
	public const STATUS_CLOSED    = 'closed';

	/**
	 * Conversation FSM states.
	 */
	public const STATE_IDLE              = 'idle';
	public const STATE_BROWSING          = 'browsing';
	public const STATE_VIEWING_PRODUCT   = 'viewing_product';
	public const STATE_CART_MANAGEMENT   = 'cart_management';
	public const STATE_CHECKOUT_ADDRESS  = 'checkout_address';
	public const STATE_CHECKOUT_PAYMENT  = 'checkout_payment';
	public const STATE_CHECKOUT_CONFIRM  = 'checkout_confirm';
	public const STATE_AWAITING_HUMAN    = 'awaiting_human';

	/**
	 * Constructor.
	 *
	 * @param int                     $id                  The conversation ID.
	 * @param string                  $customer_phone      The customer phone number.
	 * @param string|null             $wa_conversation_id  The WhatsApp conversation ID.
	 * @param string                  $status              The conversation status.
	 * @param string                  $state               The FSM state.
	 * @param array                   $context             The conversation context data.
	 * @param int|null                $assigned_agent_id   The assigned agent (user) ID.
	 * @param \DateTimeImmutable      $created_at          When the conversation was created.
	 * @param \DateTimeImmutable      $updated_at          When the conversation was last updated.
	 * @param \DateTimeImmutable|null $last_message_at     When the last message was received.
	 * @param int                     $message_count       Total message count.
	 * @param int                     $unread_count        Unread message count.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $customer_phone,
		public readonly ?string $wa_conversation_id,
		public readonly string $status,
		public readonly string $state,
		public readonly array $context,
		public readonly ?int $assigned_agent_id,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $updated_at,
		public readonly ?\DateTimeImmutable $last_message_at = null,
		public readonly int $message_count = 0,
		public readonly int $unread_count = 0,
	) {}

	/**
	 * Create a Conversation from a database row.
	 *
	 * @param array $row The database row.
	 * @return self
	 * @throws \InvalidArgumentException If customer_phone is missing.
	 */
	public static function fromArray( array $row ): self {
		// Validate required customer_phone field.
		$customer_phone = $row['customer_phone'] ?? '';
		if ( '' === $customer_phone ) {
			throw new \InvalidArgumentException( 'Conversation customer_phone is required' );
		}

		// Validate and sanitize phone number.
		$customer_phone = self::validatePhone( $customer_phone );

		// Parse JSON context safely, handling corruption.
		$context = array();
		if ( isset( $row['context'] ) && is_string( $row['context'] ) ) {
			$decoded = json_decode( $row['context'], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$context = $decoded;
			}
		} elseif ( isset( $row['context'] ) && is_array( $row['context'] ) ) {
			$context = $row['context'];
		}

		// Validate WhatsApp conversation ID if provided.
		$wa_conversation_id = $row['wa_conversation_id'] ?? null;
		if ( null !== $wa_conversation_id && '' !== $wa_conversation_id ) {
			$wa_conversation_id = self::validateConversationId( $wa_conversation_id );
		}

		return new self(
			id: (int) $row['id'],
			customer_phone: $customer_phone,
			wa_conversation_id: $wa_conversation_id,
			status: $row['status'] ?? self::STATUS_ACTIVE,
			state: $row['state'] ?? self::STATE_IDLE,
			context: $context,
			assigned_agent_id: isset( $row['assigned_agent_id'] )
				? (int) $row['assigned_agent_id']
				: null,
			created_at: self::parseDate( $row['created_at'] ?? null ),
			updated_at: self::parseDate( $row['updated_at'] ?? null ),
			last_message_at: self::parseDate( $row['last_message_at'] ?? null, null ),
			message_count: max( 0, (int) ( $row['message_count'] ?? 0 ) ),
			unread_count: max( 0, (int) ( $row['unread_count'] ?? 0 ) ),
		);
	}

	/**
	 * Validate a phone number using DataValidator.
	 *
	 * Falls back to basic sanitization if DataValidator is not available.
	 *
	 * @param string $phone The phone number to validate.
	 * @return string The validated/sanitized phone number.
	 */
	private static function validatePhone( string $phone ): string {
		$validator_class = '\\WhatsAppCommerceHub\\Validation\\DataValidator';

		if ( class_exists( $validator_class ) ) {
			$validated = $validator_class::validatePhone( $phone );
			if ( null !== $validated ) {
				return $validated;
			}
			// If validation fails, return sanitized version.
			return $validator_class::sanitizePhone( $phone );
		}

		// Fallback: basic sanitization (remove non-digits).
		return preg_replace( '/[^0-9]/', '', $phone );
	}

	/**
	 * Validate a WhatsApp conversation ID.
	 *
	 * @param string $conversation_id The conversation ID to validate.
	 * @return string|null The validated ID or null if invalid.
	 */
	private static function validateConversationId( string $conversation_id ): ?string {
		$validator_class = '\\WhatsAppCommerceHub\\Validation\\DataValidator';

		if ( class_exists( $validator_class ) ) {
			return $validator_class::isValidConversationId( $conversation_id )
				? $conversation_id
				: null;
		}

		// Fallback: accept if alphanumeric with allowed chars.
		return preg_match( '/^[a-zA-Z0-9._=-]{5,200}$/', $conversation_id )
			? $conversation_id
			: null;
	}

	/**
	 * Safely parse a date string to DateTimeImmutable.
	 *
	 * @param string|null                   $date    The date string to parse.
	 * @param \DateTimeImmutable|null|false $default Default value if parsing fails.
	 * @return \DateTimeImmutable|null
	 */
	private static function parseDate( ?string $date, \DateTimeImmutable|null|false $default = false ): ?\DateTimeImmutable {
		if ( empty( $date ) || '0000-00-00 00:00:00' === $date || '0000-00-00' === $date ) {
			return false === $default ? new \DateTimeImmutable() : $default;
		}

		try {
			return new \DateTimeImmutable( $date );
		} catch ( \Exception $e ) {
			return false === $default ? new \DateTimeImmutable() : $default;
		}
	}

	/**
	 * Convert to array for database storage.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'id'                 => $this->id,
			'customer_phone'     => $this->customer_phone,
			'wa_conversation_id' => $this->wa_conversation_id,
			'status'             => $this->status,
			'state'              => $this->state,
			'context'            => wp_json_encode( $this->context ),
			'assigned_agent_id'  => $this->assigned_agent_id,
			'created_at'         => $this->created_at->format( 'Y-m-d H:i:s' ),
			'updated_at'         => $this->updated_at->format( 'Y-m-d H:i:s' ),
			'last_message_at'    => $this->last_message_at?->format( 'Y-m-d H:i:s' ),
			'message_count'      => $this->message_count,
			'unread_count'       => $this->unread_count,
		);
	}

	/**
	 * Check if the conversation is active.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	/**
	 * Check if the conversation is escalated to human.
	 *
	 * @return bool
	 */
	public function isEscalated(): bool {
		return self::STATUS_ESCALATED === $this->status
			|| self::STATE_AWAITING_HUMAN === $this->state;
	}

	/**
	 * Check if the conversation has an assigned agent.
	 *
	 * @return bool
	 */
	public function hasAssignedAgent(): bool {
		return null !== $this->assigned_agent_id;
	}

	/**
	 * Check if the conversation has unread messages.
	 *
	 * @return bool
	 */
	public function hasUnread(): bool {
		return $this->unread_count > 0;
	}

	/**
	 * Get a context value.
	 *
	 * @param string $key     The context key.
	 * @param mixed  $default The default value.
	 * @return mixed
	 */
	public function getContextValue( string $key, mixed $default = null ): mixed {
		return $this->context[ $key ] ?? $default;
	}

	/**
	 * Check if the conversation is in checkout flow.
	 *
	 * @return bool
	 */
	public function isInCheckout(): bool {
		return in_array(
			$this->state,
			array(
				self::STATE_CHECKOUT_ADDRESS,
				self::STATE_CHECKOUT_PAYMENT,
				self::STATE_CHECKOUT_CONFIRM,
			),
			true
		);
	}

	/**
	 * Create a new conversation with updated state.
	 *
	 * @param string $state The new FSM state.
	 * @return self
	 */
	public function withState( string $state ): self {
		return new self(
			id: $this->id,
			customer_phone: $this->customer_phone,
			wa_conversation_id: $this->wa_conversation_id,
			status: $this->status,
			state: $state,
			context: $this->context,
			assigned_agent_id: $this->assigned_agent_id,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			last_message_at: $this->last_message_at,
			message_count: $this->message_count,
			unread_count: $this->unread_count,
		);
	}

	/**
	 * Create a new conversation with updated context.
	 *
	 * @param array $context The context to merge.
	 * @return self
	 */
	public function withContext( array $context ): self {
		return new self(
			id: $this->id,
			customer_phone: $this->customer_phone,
			wa_conversation_id: $this->wa_conversation_id,
			status: $this->status,
			state: $this->state,
			context: array_merge( $this->context, $context ),
			assigned_agent_id: $this->assigned_agent_id,
			created_at: $this->created_at,
			updated_at: new \DateTimeImmutable(),
			last_message_at: $this->last_message_at,
			message_count: $this->message_count,
			unread_count: $this->unread_count,
		);
	}
}
