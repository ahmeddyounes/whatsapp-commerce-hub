<?php
/**
 * Conversation Repository
 *
 * Concrete implementation of conversation data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Repositories;

use WhatsAppCommerceHub\Contracts\Repositories\ConversationRepositoryInterface;
use WhatsAppCommerceHub\Domain\Conversation\Conversation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationRepository
 *
 * Provides conversation-specific data access operations.
 */
class ConversationRepository extends AbstractRepository implements ConversationRepositoryInterface {

	/**
	 * {@inheritdoc}
	 */
	protected function getTableName(): string {
		return 'wch_conversations';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function mapToEntity( array $row ): Conversation {
		return Conversation::fromArray( $row );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function prepareData( array $data ): array {
		// Convert arrays to JSON.
		if ( isset( $data['context'] ) && is_array( $data['context'] ) ) {
			$data['context'] = wp_json_encode( $data['context'] );
		}

		// Convert DateTimeImmutable objects.
		$date_fields = array(
			'created_at',
			'updated_at',
			'last_message_at',
		);

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
	public function find( int $id ): ?Conversation {
		$entity = parent::find( $id );
		return $entity instanceof Conversation ? $entity : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByPhone( string $phone ): ?Conversation {
		$sql = "SELECT * FROM {$this->table}
				WHERE customer_phone = %s
				ORDER BY created_at DESC
				LIMIT 1";

		$row = $this->queryRow( $sql, array( $phone ) );

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByWhatsAppId( string $wa_conversation_id ): ?Conversation {
		$row = $this->queryRow(
			"SELECT * FROM {$this->table} WHERE wa_conversation_id = %s LIMIT 1",
			array( $wa_conversation_id )
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findActive( int $limit = 50 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE status = %s
				ORDER BY last_message_at DESC
				LIMIT %d";

		$rows = $this->query(
			$sql,
			array( Conversation::STATUS_ACTIVE, $limit )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * {@inheritdoc}
	 */
	public function findPendingAssignment(): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE (status = %s OR state = %s)
				AND assigned_agent_id IS NULL
				ORDER BY updated_at ASC";

		$rows = $this->query(
			$sql,
			array( Conversation::STATUS_ESCALATED, Conversation::STATE_AWAITING_HUMAN )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * Valid conversation statuses.
	 *
	 * @var array
	 */
	private const VALID_STATUSES = array(
		Conversation::STATUS_ACTIVE,
		Conversation::STATUS_IDLE,
		Conversation::STATUS_ESCALATED,
		Conversation::STATUS_CLOSED,
	);

	/**
	 * Valid conversation states.
	 *
	 * @var array
	 */
	private const VALID_STATES = array(
		Conversation::STATE_IDLE,
		Conversation::STATE_BROWSING,
		Conversation::STATE_VIEWING_PRODUCT,
		Conversation::STATE_CART_MANAGEMENT,
		Conversation::STATE_CHECKOUT_ADDRESS,
		Conversation::STATE_CHECKOUT_PAYMENT,
		Conversation::STATE_CHECKOUT_CONFIRM,
		Conversation::STATE_AWAITING_HUMAN,
	);

	/**
	 * {@inheritdoc}
	 */
	public function updateStatus( int $id, string $status ): bool {
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid conversation status: %s', $status )
			);
		}

		return $this->update( $id, array( 'status' => $status ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateState( int $id, string $state ): bool {
		if ( ! in_array( $state, self::VALID_STATES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid conversation state: %s', $state )
			);
		}

		return $this->update( $id, array( 'state' => $state ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function assignAgent( int $conversation_id, int $agent_id ): bool {
		return $this->update(
			$conversation_id,
			array(
				'assigned_agent_id' => $agent_id,
				'status'            => Conversation::STATUS_ESCALATED,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses transaction with FOR UPDATE lock to prevent lost updates
	 * when context is updated concurrently.
	 */
	public function updateContext( int $id, array $context ): bool {
		$this->beginTransaction();

		try {
			// Lock the row for update to prevent concurrent modifications.
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT context FROM {$this->table} WHERE id = %d FOR UPDATE",
					$id
				),
				ARRAY_A
			);

			if ( ! $row ) {
				$this->rollback();
				return false;
			}

			$existing = json_decode( $row['context'] ?? '{}', true ) ?: array();
			$merged   = array_merge( $existing, $context );

			$result = $this->update( $id, array( 'context' => $merged ) );

			if ( $result ) {
				$this->commit();
				return true;
			}

			$this->rollback();
			return false;
		} catch ( \Throwable $e ) {
			$this->rollback();
			do_action( 'wch_log_error', 'updateContext failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function findTimedOut( int $timeout_minutes = 30 ): array {
		$threshold_time = ( new \DateTimeImmutable() )
			->modify( "-{$timeout_minutes} minutes" )
			->format( 'Y-m-d H:i:s' );

		$sql = "SELECT * FROM {$this->table}
				WHERE status = %s
				AND last_message_at < %s
				ORDER BY last_message_at ASC";

		$rows = $this->query(
			$sql,
			array( Conversation::STATUS_ACTIVE, $threshold_time )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array {
		$start = $start_date->format( 'Y-m-d H:i:s' );
		$end   = $end_date->format( 'Y-m-d H:i:s' );

		$sql = "SELECT
					COUNT(*) as total,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as completed,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as abandoned,
					SUM(CASE WHEN status = %s OR state = %s THEN 1 ELSE 0 END) as escalated
				FROM {$this->table}
				WHERE created_at BETWEEN %s AND %s";

		$row = $this->queryRow(
			$sql,
			array(
				Conversation::STATUS_CLOSED,
				Conversation::STATUS_IDLE,
				Conversation::STATUS_ESCALATED,
				Conversation::STATE_AWAITING_HUMAN,
				$start,
				$end,
			)
		);

		return array(
			'total'     => (int) ( $row['total'] ?? 0 ),
			'completed' => (int) ( $row['completed'] ?? 0 ),
			'abandoned' => (int) ( $row['abandoned'] ?? 0 ),
			'escalated' => (int) ( $row['escalated'] ?? 0 ),
		);
	}

	/**
	 * Find or create a conversation for a phone number.
	 *
	 * Uses transaction with locking to prevent race conditions
	 * where concurrent requests could create duplicate conversations.
	 *
	 * @param string $phone The customer phone number.
	 * @return Conversation The conversation.
	 */
	public function findOrCreate( string $phone ): Conversation {
		$this->beginTransaction();

		try {
			// Try to find an active conversation with FOR UPDATE lock.
			$sql = "SELECT * FROM {$this->table}
					WHERE customer_phone = %s
					AND status IN (%s, %s)
					ORDER BY created_at DESC
					LIMIT 1
					FOR UPDATE";

			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					$sql,
					$phone,
					Conversation::STATUS_ACTIVE,
					Conversation::STATUS_ESCALATED
				),
				ARRAY_A
			);

			if ( $row ) {
				$this->commit();
				return $this->mapToEntity( $row );
			}

			// Create a new conversation within the transaction.
			$id = $this->create(
				array(
					'customer_phone' => $phone,
					'status'         => Conversation::STATUS_ACTIVE,
					'state'          => Conversation::STATE_IDLE,
					'context'        => array(),
				)
			);

			$conversation = $this->find( $id );
			$this->commit();

			return $conversation;
		} catch ( \Throwable $e ) {
			$this->rollback();

			// If we failed due to duplicate, try to find existing.
			$existing = $this->findByPhone( $phone );
			if ( $existing ) {
				return $existing;
			}

			throw $e;
		}
	}

	/**
	 * Update conversation after receiving a new message.
	 *
	 * Uses atomic SQL increment to prevent race conditions when
	 * multiple messages arrive simultaneously.
	 *
	 * @param int $id The conversation ID.
	 * @return bool True on success.
	 */
	public function touchMessage( int $id ): bool {
		// Use atomic update to prevent race condition.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET last_message_at = %s,
					message_count = message_count + 1,
					updated_at = %s
				WHERE id = %d",
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				$id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Increment unread count.
	 *
	 * Uses atomic SQL increment to prevent race conditions when
	 * multiple messages arrive simultaneously.
	 *
	 * @param int $id The conversation ID.
	 * @return bool True on success.
	 */
	public function incrementUnread( int $id ): bool {
		// Use atomic update to prevent race condition.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET unread_count = unread_count + 1,
					updated_at = %s
				WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Reset unread count.
	 *
	 * @param int $id The conversation ID.
	 * @return bool True on success.
	 */
	public function resetUnread( int $id ): bool {
		return $this->update( $id, array( 'unread_count' => 0 ) );
	}

	/**
	 * Find conversations by agent.
	 *
	 * @param int $agent_id The agent (user) ID.
	 * @param int $limit    Maximum conversations to return.
	 * @return array<Conversation> Conversations assigned to the agent.
	 */
	public function findByAgent( int $agent_id, int $limit = 50 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE assigned_agent_id = %d
				AND status != %s
				ORDER BY last_message_at DESC
				LIMIT %d";

		$rows = $this->query(
			$sql,
			array( $agent_id, Conversation::STATUS_CLOSED, $limit )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * Get conversations in checkout flow.
	 *
	 * @param int $limit Maximum conversations to return.
	 * @return array<Conversation> Conversations in checkout.
	 */
	public function findInCheckout( int $limit = 50 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE state IN (%s, %s, %s)
				ORDER BY updated_at DESC
				LIMIT %d";

		$rows = $this->query(
			$sql,
			array(
				Conversation::STATE_CHECKOUT_ADDRESS,
				Conversation::STATE_CHECKOUT_PAYMENT,
				Conversation::STATE_CHECKOUT_CONFIRM,
				$limit,
			)
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}
}
