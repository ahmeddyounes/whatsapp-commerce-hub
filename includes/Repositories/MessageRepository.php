<?php
/**
 * Message Repository
 *
 * Concrete implementation of message data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Repositories;

use WhatsAppCommerceHub\Contracts\Repositories\MessageRepositoryInterface;
use WhatsAppCommerceHub\Entities\Message;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageRepository
 *
 * Provides message-specific data access operations.
 */
class MessageRepository extends AbstractRepository implements MessageRepositoryInterface {

	/**
	 * {@inheritdoc}
	 */
	protected function getTableName(): string {
		return 'wch_messages';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function mapToEntity( array $row ): Message {
		return Message::fromArray( $row );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function prepareData( array $data ): array {
		// Convert arrays to JSON.
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$data['content'] = wp_json_encode( $data['content'] );
		}

		// Convert DateTimeImmutable objects.
		$date_fields = [
			'created_at',
			'sent_at',
			'delivered_at',
			'read_at',
		];

		foreach ( $date_fields as $field ) {
			if ( isset( $data[ $field ] ) && $data[ $field ] instanceof \DateTimeInterface ) {
				$data[ $field ] = $data[ $field ]->format( 'Y-m-d H:i:s' );
			}
		}

		return $data;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find( int $id ): ?Message {
		$entity = parent::find( $id );
		return $entity instanceof Message ? $entity : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByConversation( int $conversation_id, int $limit = 50, int $offset = 0 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE conversation_id = %d
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d";

		$rows = $this->query(
			$sql,
			[ $conversation_id, $limit, $offset ]
		);

		return array_map( [ $this, 'mapToEntity' ], $rows );
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByWhatsAppMessageId( string $wa_message_id ): ?Message {
		$row = $this->queryRow(
			"SELECT * FROM {$this->table} WHERE wa_message_id = %s LIMIT 1",
			[ $wa_message_id ]
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateStatus( string $wa_message_id, string $status ): bool {
		$message = $this->findByWhatsAppMessageId( $wa_message_id );

		if ( ! $message ) {
			return false;
		}

		$data = [ 'status' => $status ];

		// Set the appropriate timestamp based on status.
		$now = current_time( 'mysql' );

		switch ( $status ) {
			case Message::STATUS_SENT:
				if ( null === $message->sent_at ) {
					$data['sent_at'] = $now;
				}
				break;

			case Message::STATUS_DELIVERED:
				if ( null === $message->delivered_at ) {
					$data['delivered_at'] = $now;
				}
				break;

			case Message::STATUS_READ:
				if ( null === $message->read_at ) {
					$data['read_at'] = $now;
				}
				break;
		}

		return $this->update( $message->id, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getUnreadCount( int $conversation_id ): int {
		$sql = "SELECT COUNT(*) FROM {$this->table}
				WHERE conversation_id = %d
				AND direction = %s
				AND status != %s";

		$count = $this->queryVar(
			$sql,
			[ $conversation_id, Message::DIRECTION_INBOUND, Message::STATUS_READ ]
		);

		return (int) ( $count ?? 0 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function markAsRead( int $conversation_id ): int {
		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET status = %s, read_at = %s, updated_at = %s
				WHERE conversation_id = %d
				AND direction = %s
				AND status != %s",
				Message::STATUS_READ,
				$now,
				$now,
				$conversation_id,
				Message::DIRECTION_INBOUND,
				Message::STATUS_READ
			)
		);

		return $this->wpdb->rows_affected;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findFailedForRetry( int $max_retries = 3, int $limit = 50 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE status = %s
				AND direction = %s
				AND retry_count < %d
				ORDER BY created_at ASC
				LIMIT %d";

		$rows = $this->query(
			$sql,
			[
				Message::STATUS_FAILED,
				Message::DIRECTION_OUTBOUND,
				$max_retries,
				$limit,
			]
		);

		return array_map( [ $this, 'mapToEntity' ], $rows );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses atomic SQL increment to prevent race conditions when
	 * multiple retry attempts occur simultaneously.
	 */
	public function incrementRetryCount( int $message_id, string $error ): bool {
		// Use atomic update to prevent race condition.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET retry_count = retry_count + 1,
					error_message = %s,
					status = %s,
					updated_at = %s
				WHERE id = %d",
				$error,
				Message::STATUS_FAILED,
				current_time( 'mysql' ),
				$message_id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array {
		$start = $start_date->format( 'Y-m-d H:i:s' );
		$end   = $end_date->format( 'Y-m-d H:i:s' );

		$sql = "SELECT
					COUNT(*) as total,
					SUM(CASE WHEN direction = %s THEN 1 ELSE 0 END) as inbound,
					SUM(CASE WHEN direction = %s THEN 1 ELSE 0 END) as outbound,
					SUM(CASE WHEN status IN (%s, %s) THEN 1 ELSE 0 END) as delivered,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as failed
				FROM {$this->table}
				WHERE created_at BETWEEN %s AND %s";

		$row = $this->queryRow(
			$sql,
			[
				Message::DIRECTION_INBOUND,
				Message::DIRECTION_OUTBOUND,
				Message::STATUS_DELIVERED,
				Message::STATUS_READ,
				Message::STATUS_FAILED,
				$start,
				$end,
			]
		);

		return [
			'total'     => (int) ( $row['total'] ?? 0 ),
			'inbound'   => (int) ( $row['inbound'] ?? 0 ),
			'outbound'  => (int) ( $row['outbound'] ?? 0 ),
			'delivered' => (int) ( $row['delivered'] ?? 0 ),
			'failed'    => (int) ( $row['failed'] ?? 0 ),
		];
	}

	/**
	 * Valid message types.
	 *
	 * @var array
	 */
	private const VALID_TYPES = [
		Message::TYPE_TEXT,
		Message::TYPE_IMAGE,
		Message::TYPE_DOCUMENT,
		Message::TYPE_AUDIO,
		Message::TYPE_VIDEO,
		Message::TYPE_LOCATION,
		Message::TYPE_INTERACTIVE,
		Message::TYPE_TEMPLATE,
		Message::TYPE_REACTION,
	];

	/**
	 * Validate message type.
	 *
	 * @param string $type The message type to validate.
	 * @return void
	 * @throws \InvalidArgumentException If type is invalid.
	 */
	private function validateType( string $type ): void {
		if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid message type: %s', $type )
			);
		}
	}

	/**
	 * Create a new outbound message.
	 *
	 * @param int    $conversation_id The conversation ID.
	 * @param string $type            The message type.
	 * @param array  $content         The message content.
	 * @return int The new message ID.
	 * @throws \InvalidArgumentException If type is invalid.
	 */
	public function createOutbound( int $conversation_id, string $type, array $content ): int {
		$this->validateType( $type );

		return $this->create(
			[
				'conversation_id' => $conversation_id,
				'direction'       => Message::DIRECTION_OUTBOUND,
				'type'            => $type,
				'content'         => $content,
				'status'          => Message::STATUS_PENDING,
			]
		);
	}

	/**
	 * Create a new inbound message.
	 *
	 * @param int    $conversation_id The conversation ID.
	 * @param string $wa_message_id   The WhatsApp message ID.
	 * @param string $type            The message type.
	 * @param array  $content         The message content.
	 * @return int The new message ID.
	 * @throws \InvalidArgumentException If type is invalid.
	 */
	public function createInbound(
		int $conversation_id,
		string $wa_message_id,
		string $type,
		array $content
	): int {
		$this->validateType( $type );

		return $this->create(
			[
				'conversation_id' => $conversation_id,
				'wa_message_id'   => $wa_message_id,
				'direction'       => Message::DIRECTION_INBOUND,
				'type'            => $type,
				'content'         => $content,
				'status'          => Message::STATUS_DELIVERED,
			]
		);
	}

	/**
	 * Update WhatsApp message ID after sending.
	 *
	 * @param int    $message_id    The message ID.
	 * @param string $wa_message_id The WhatsApp message ID.
	 * @return bool True on success.
	 */
	public function setWhatsAppMessageId( int $message_id, string $wa_message_id ): bool {
		return $this->update(
			$message_id,
			[
				'wa_message_id' => $wa_message_id,
				'status'        => Message::STATUS_SENT,
				'sent_at'       => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * Get the last message in a conversation.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @return Message|null The last message or null.
	 */
	public function getLastMessage( int $conversation_id ): ?Message {
		$sql = "SELECT * FROM {$this->table}
				WHERE conversation_id = %d
				ORDER BY created_at DESC
				LIMIT 1";

		$row = $this->queryRow( $sql, [ $conversation_id ] );

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * Get the last inbound message in a conversation.
	 *
	 * @param int $conversation_id The conversation ID.
	 * @return Message|null The last inbound message or null.
	 */
	public function getLastInboundMessage( int $conversation_id ): ?Message {
		$sql = "SELECT * FROM {$this->table}
				WHERE conversation_id = %d
				AND direction = %s
				ORDER BY created_at DESC
				LIMIT 1";

		$row = $this->queryRow(
			$sql,
			[ $conversation_id, Message::DIRECTION_INBOUND ]
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * Get messages by type.
	 *
	 * @param int    $conversation_id The conversation ID.
	 * @param string $type            The message type.
	 * @param int    $limit           Maximum messages to return.
	 * @return array<Message> Messages of the specified type.
	 */
	public function findByType( int $conversation_id, string $type, int $limit = 10 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE conversation_id = %d
				AND type = %s
				ORDER BY created_at DESC
				LIMIT %d";

		$rows = $this->query(
			$sql,
			[ $conversation_id, $type, $limit ]
		);

		return array_map( [ $this, 'mapToEntity' ], $rows );
	}

	/**
	 * Delete old messages for cleanup.
	 *
	 * @param int $days_old      Messages older than this will be deleted.
	 * @param int $batch_size    Number of messages to delete per batch.
	 * @return int Number of messages deleted.
	 */
	public function cleanupOldMessages( int $days_old = 90, int $batch_size = 1000 ): int {
		$threshold = ( new \DateTimeImmutable() )
			->modify( "-{$days_old} days" )
			->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table}
				WHERE created_at < %s
				LIMIT %d",
				$threshold,
				$batch_size
			)
		);

		return $this->wpdb->rows_affected;
	}
}
