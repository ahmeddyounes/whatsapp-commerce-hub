<?php
/**
 * Message Entity
 *
 * Represents a WhatsApp message in the Commerce Hub.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Entities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Message
 *
 * Immutable value object representing a WhatsApp message.
 */
final class Message {

	/**
	 * Message directions.
	 */
	public const DIRECTION_INBOUND  = 'inbound';
	public const DIRECTION_OUTBOUND = 'outbound';

	/**
	 * Message types.
	 */
	public const TYPE_TEXT        = 'text';
	public const TYPE_IMAGE       = 'image';
	public const TYPE_DOCUMENT    = 'document';
	public const TYPE_AUDIO       = 'audio';
	public const TYPE_VIDEO       = 'video';
	public const TYPE_LOCATION    = 'location';
	public const TYPE_INTERACTIVE = 'interactive';
	public const TYPE_BUTTON      = 'button';
	public const TYPE_TEMPLATE    = 'template';
	public const TYPE_REACTION    = 'reaction';

	/**
	 * Message statuses.
	 */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_SENT      = 'sent';
	public const STATUS_DELIVERED = 'delivered';
	public const STATUS_READ      = 'read';
	public const STATUS_FAILED    = 'failed';

	/**
	 * Constructor.
	 *
	 * @param int                     $id               The message ID.
	 * @param int                     $conversation_id  The conversation ID.
	 * @param string|null             $wa_message_id    The WhatsApp message ID.
	 * @param string                  $direction        Message direction (inbound/outbound).
	 * @param string                  $type             Message type.
	 * @param array                   $content          Message content.
	 * @param string                  $status           Message status.
	 * @param int                     $retry_count      Number of retry attempts.
	 * @param string|null             $error_message    Last error message.
	 * @param \DateTimeImmutable      $created_at       When the message was created.
	 * @param \DateTimeImmutable|null $sent_at          When the message was sent.
	 * @param \DateTimeImmutable|null $delivered_at     When the message was delivered.
	 * @param \DateTimeImmutable|null $read_at          When the message was read.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $conversation_id,
		public readonly ?string $wa_message_id,
		public readonly string $direction,
		public readonly string $type,
		public readonly array $content,
		public readonly string $status,
		public readonly int $retry_count = 0,
		public readonly ?string $error_message = null,
		public readonly \DateTimeImmutable $created_at = new \DateTimeImmutable(),
		public readonly ?\DateTimeImmutable $sent_at = null,
		public readonly ?\DateTimeImmutable $delivered_at = null,
		public readonly ?\DateTimeImmutable $read_at = null,
	) {}

	/**
	 * Create a Message from a database row.
	 *
	 * @param array $row The database row.
	 * @return self
	 */
	public static function fromArray( array $row ): self {
		// Parse JSON content safely, handling corruption.
		$content = [];
		if ( isset( $row['content'] ) && is_string( $row['content'] ) ) {
			$decoded = json_decode( $row['content'], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$content = $decoded;
			}
		} elseif ( isset( $row['content'] ) && is_array( $row['content'] ) ) {
			$content = $row['content'];
		}

		return new self(
			id: (int) $row['id'],
			conversation_id: (int) $row['conversation_id'],
			wa_message_id: $row['wa_message_id'] ?? null,
			direction: $row['direction'] ?? self::DIRECTION_INBOUND,
			type: $row['type'] ?? self::TYPE_TEXT,
			content: $content,
			status: $row['status'] ?? self::STATUS_PENDING,
			retry_count: max( 0, (int) ( $row['retry_count'] ?? 0 ) ),
			error_message: $row['error_message'] ?? null,
			created_at: self::parseDate( $row['created_at'] ?? null ),
			sent_at: self::parseDate( $row['sent_at'] ?? null, null ),
			delivered_at: self::parseDate( $row['delivered_at'] ?? null, null ),
			read_at: self::parseDate( $row['read_at'] ?? null, null ),
		);
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
		return [
			'id'              => $this->id,
			'conversation_id' => $this->conversation_id,
			'wa_message_id'   => $this->wa_message_id,
			'direction'       => $this->direction,
			'type'            => $this->type,
			'content'         => wp_json_encode( $this->content ),
			'status'          => $this->status,
			'retry_count'     => $this->retry_count,
			'error_message'   => $this->error_message,
			'created_at'      => $this->created_at->format( 'Y-m-d H:i:s' ),
			'sent_at'         => $this->sent_at?->format( 'Y-m-d H:i:s' ),
			'delivered_at'    => $this->delivered_at?->format( 'Y-m-d H:i:s' ),
			'read_at'         => $this->read_at?->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Check if the message is inbound.
	 *
	 * @return bool
	 */
	public function isInbound(): bool {
		return self::DIRECTION_INBOUND === $this->direction;
	}

	/**
	 * Check if the message is outbound.
	 *
	 * @return bool
	 */
	public function isOutbound(): bool {
		return self::DIRECTION_OUTBOUND === $this->direction;
	}

	/**
	 * Check if the message is a text message.
	 *
	 * @return bool
	 */
	public function isText(): bool {
		return self::TYPE_TEXT === $this->type;
	}

	/**
	 * Check if the message failed.
	 *
	 * @return bool
	 */
	public function isFailed(): bool {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Check if the message was read.
	 *
	 * @return bool
	 */
	public function isRead(): bool {
		return self::STATUS_READ === $this->status || null !== $this->read_at;
	}

	/**
	 * Check if the message can be retried.
	 *
	 * @param int $max_retries Maximum retry attempts.
	 * @return bool
	 */
	public function canRetry( int $max_retries = 3 ): bool {
		return $this->isFailed() && $this->retry_count < $max_retries;
	}

	/**
	 * Get the text content of the message.
	 *
	 * @return string
	 */
	public function getTextContent(): string {
		if ( self::TYPE_TEXT === $this->type ) {
			return $this->content['body'] ?? $this->content['text'] ?? '';
		}

		if ( isset( $this->content['caption'] ) ) {
			return $this->content['caption'];
		}

		return '';
	}

	/**
	 * Create a new message with updated status.
	 *
	 * @param string $status The new status.
	 * @return self
	 */
	public function withStatus( string $status ): self {
		$sent_at      = $this->sent_at;
		$delivered_at = $this->delivered_at;
		$read_at      = $this->read_at;

		$now = new \DateTimeImmutable();

		if ( self::STATUS_SENT === $status && null === $sent_at ) {
			$sent_at = $now;
		} elseif ( self::STATUS_DELIVERED === $status && null === $delivered_at ) {
			$delivered_at = $now;
		} elseif ( self::STATUS_READ === $status && null === $read_at ) {
			$read_at = $now;
		}

		return new self(
			id: $this->id,
			conversation_id: $this->conversation_id,
			wa_message_id: $this->wa_message_id,
			direction: $this->direction,
			type: $this->type,
			content: $this->content,
			status: $status,
			retry_count: $this->retry_count,
			error_message: $this->error_message,
			created_at: $this->created_at,
			sent_at: $sent_at,
			delivered_at: $delivered_at,
			read_at: $read_at,
		);
	}

	/**
	 * Create a new message with incremented retry count.
	 *
	 * @param string $error The error message.
	 * @return self
	 */
	public function withRetry( string $error ): self {
		return new self(
			id: $this->id,
			conversation_id: $this->conversation_id,
			wa_message_id: $this->wa_message_id,
			direction: $this->direction,
			type: $this->type,
			content: $this->content,
			status: self::STATUS_FAILED,
			retry_count: $this->retry_count + 1,
			error_message: $error,
			created_at: $this->created_at,
			sent_at: $this->sent_at,
			delivered_at: $this->delivered_at,
			read_at: $this->read_at,
		);
	}
}
