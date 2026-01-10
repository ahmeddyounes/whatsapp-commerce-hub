<?php
/**
 * Customer Repository
 *
 * Concrete implementation of customer data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Repositories;

use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Domain\Customer\Customer;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// SQL uses safe table names from $wpdb->prefix. Hook names use wch_ project prefix.

/**
 * Class CustomerRepository
 *
 * Provides customer-specific data access operations.
 */
class CustomerRepository extends AbstractRepository implements CustomerRepositoryInterface {

	/**
	 * {@inheritdoc}
	 */
	protected function getTableName(): string {
		return 'wch_customer_profiles';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function mapToEntity( array $row ): Customer {
		return Customer::fromArray( $row );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function prepareData( array $data ): array {
		// Convert arrays to JSON.
		if ( isset( $data['preferences'] ) && is_array( $data['preferences'] ) ) {
			$data['preferences'] = wp_json_encode( $data['preferences'] );
		}

		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$data['tags'] = wp_json_encode( $data['tags'] );
		}

		if ( isset( $data['last_known_address'] ) && is_array( $data['last_known_address'] ) ) {
			$data['last_known_address'] = wp_json_encode( $data['last_known_address'] );
		}

		// Convert booleans.
		if ( isset( $data['opt_in_marketing'] ) ) {
			$data['opt_in_marketing'] = $data['opt_in_marketing'] ? 1 : 0;
		}

		// Convert DateTimeImmutable objects.
		$date_fields = array(
			'created_at',
			'updated_at',
			'last_interaction_at',
			'marketing_opted_at',
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
	public function find( int $id ): ?Customer {
		$entity = parent::find( $id );
		return $entity instanceof Customer ? $entity : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByPhone( string $phone ): ?Customer {
		$row = $this->queryRow(
			"SELECT * FROM {$this->table} WHERE phone = %s LIMIT 1",
			array( $phone )
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByWcCustomerId( int $wc_customer_id ): ?Customer {
		$row = $this->queryRow(
			"SELECT * FROM {$this->table} WHERE wc_customer_id = %d LIMIT 1",
			array( $wc_customer_id )
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function linkToWcCustomer( string $phone, int $wc_customer_id ): bool {
		$customer = $this->findByPhone( $phone );

		if ( ! $customer ) {
			return false;
		}

		return $this->update(
			$customer->id,
			array( 'wc_customer_id' => $wc_customer_id )
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses transaction with FOR UPDATE lock to prevent lost updates
	 * when preferences are updated concurrently.
	 */
	public function updatePreferences( int $id, array $preferences ): bool {
		$this->beginTransaction();

		try {
			// Lock the row for update to prevent concurrent modifications.
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT preferences FROM {$this->table} WHERE id = %d FOR UPDATE",
					$id
				),
				ARRAY_A
			);

			if ( ! $row ) {
				$this->rollback();
				return false;
			}

			// Validate and decode existing preferences.
			$raw_json = $row['preferences'] ?? '{}';
			$existing = json_decode( $raw_json, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$this->rollback();
				do_action(
					'wch_log_error',
					'Corrupted preferences JSON',
					array(
						'customer_id' => $id,
						'json_error'  => json_last_error_msg(),
					)
				);
				return false;
			}

			$existing = $existing ?: array();
			$merged   = array_merge( $existing, $preferences );

			$result = $this->update( $id, array( 'preferences' => $merged ) );

			// update() returns rows affected (0 if no change) or false on error.
			// Treat 0 as success since no-change update is not an error.
			if ( false !== $result ) {
				$this->commit();
				return true;
			}

			$this->rollback();
			return false;
		} catch ( \Throwable $e ) {
			$this->rollback();
			do_action( 'wch_log_error', 'updatePreferences failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function findOptedInForMarketing( int $limit = 100, int $offset = 0 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE opt_in_marketing = 1
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d";

		$rows = $this->query( $sql, array( $limit, $offset ) );

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateMarketingOptIn( int $id, bool $opted_in ): bool {
		$data = array(
			'opt_in_marketing' => $opted_in ? 1 : 0,
		);

		if ( $opted_in ) {
			$data['marketing_opted_at'] = current_time( 'mysql' );
		}

		return $this->update( $id, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function findByTag( string $tag, int $limit = 100, int $offset = 0 ): array {
		// Use JSON_CONTAINS to search within the tags JSON array.
		$sql = "SELECT * FROM {$this->table}
				WHERE JSON_CONTAINS(tags, %s)
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d";

		$rows = $this->query(
			$sql,
			array( wp_json_encode( $tag ), $limit, $offset )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * {@inheritdoc}
	 */
	public function addTag( int $id, string $tag ): bool {
		$customer = $this->find( $id );

		if ( ! $customer || $customer->hasTag( $tag ) ) {
			return false;
		}

		$tags   = $customer->tags;
		$tags[] = $tag;

		return $this->update( $id, array( 'tags' => $tags ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeTag( int $id, string $tag ): bool {
		$customer = $this->find( $id );

		if ( ! $customer || ! $customer->hasTag( $tag ) ) {
			return false;
		}

		$tags = array_values(
			array_filter(
				$customer->tags,
				fn( $t ) => $t !== $tag
			)
		);

		return $this->update( $id, array( 'tags' => $tags ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function exportData( int $id ): array {
		$customer = $this->find( $id );

		if ( ! $customer ) {
			return array();
		}

		// Get associated conversations.
		$conversations_table = $this->wpdb->prefix . 'wch_conversations';
		$conversations       = $this->query(
			"SELECT id, status, state, created_at, message_count FROM {$conversations_table} WHERE customer_phone = %s",
			array( $customer->phone )
		);

		// Get associated carts.
		$carts_table = $this->wpdb->prefix . 'wch_carts';
		$carts       = $this->query(
			"SELECT id, total, status, created_at FROM {$carts_table} WHERE customer_phone = %s",
			array( $customer->phone )
		);

		// Get associated messages.
		$messages_table = $this->wpdb->prefix . 'wch_messages';
		$messages       = array();

		if ( ! empty( $conversations ) ) {
			$conversation_ids = array_column( $conversations, 'id' );
			$placeholders     = implode( ', ', array_fill( 0, count( $conversation_ids ), '%d' ) );

			$messages = $this->query(
				"SELECT id, direction, type, content, created_at FROM {$messages_table} WHERE conversation_id IN ({$placeholders})",
				$conversation_ids
			);
		}

		return array(
			'customer'      => $customer->exportData(),
			'conversations' => $conversations,
			'messages'      => $messages,
			'carts'         => $carts,
			'exported_at'   => ( new \DateTimeImmutable() )->format( 'c' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Deletes all customer data for GDPR compliance. This includes:
	 * - All messages in customer conversations
	 * - All conversations
	 * - All carts
	 * - The customer profile itself
	 *
	 * Uses transactions to ensure complete or no deletion.
	 */
	public function deleteAllData( int $id ): bool {
		$customer = $this->find( $id );

		if ( ! $customer ) {
			return false;
		}

		$this->beginTransaction();

		try {
			// Lock the customer row first to prevent concurrent modifications.
			// This ensures no new conversations/carts can be created while we delete.
			$locked = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->table} WHERE id = %d FOR UPDATE",
					$id
				)
			);

			if ( ! $locked ) {
				$this->rollback();
				return false;
			}

			// Delete messages in conversations.
			$conversations_table = $this->wpdb->prefix . 'wch_conversations';
			$messages_table      = $this->wpdb->prefix . 'wch_messages';

			// Get conversation IDs under the lock protection.
			$conversation_ids = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT id FROM {$conversations_table} WHERE customer_phone = %s",
					$customer->phone
				)
			);

			if ( ! empty( $conversation_ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $conversation_ids ), '%d' ) );

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$result = $this->wpdb->query(
					$this->wpdb->prepare(
						"DELETE FROM {$messages_table} WHERE conversation_id IN ({$placeholders})",
						...$conversation_ids
					)
				);

				if ( false === $result ) {
					throw new \RuntimeException( 'Failed to delete messages: ' . $this->wpdb->last_error );
				}
			}

			// Delete conversations.
			$result = $this->wpdb->delete(
				$conversations_table,
				array( 'customer_phone' => $customer->phone ),
				array( '%s' )
			);

			if ( false === $result ) {
				throw new \RuntimeException( 'Failed to delete conversations: ' . $this->wpdb->last_error );
			}

			// Delete carts.
			$carts_table = $this->wpdb->prefix . 'wch_carts';
			$result      = $this->wpdb->delete(
				$carts_table,
				array( 'customer_phone' => $customer->phone ),
				array( '%s' )
			);

			if ( false === $result ) {
				throw new \RuntimeException( 'Failed to delete carts: ' . $this->wpdb->last_error );
			}

			// Delete customer profile.
			if ( ! $this->delete( $id ) ) {
				throw new \RuntimeException( 'Failed to delete customer profile' );
			}

			$this->commit();

			return true;
		} catch ( \Exception $e ) {
			$this->rollback();

			// Log the error for debugging.
			do_action(
				'wch_log_error',
				'GDPR deleteAllData failed: ' . $e->getMessage(),
				array(
					'customer_id' => $id,
				)
			);

			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getStats(): array {
		$sql = "SELECT
					COUNT(*) as total,
					SUM(CASE WHEN opt_in_marketing = 1 THEN 1 ELSE 0 END) as opted_in,
					SUM(CASE WHEN total_orders > 0 THEN 1 ELSE 0 END) as with_orders
				FROM {$this->table}";

		$row = $this->queryRow( $sql, array() );

		return array(
			'total'       => (int) ( $row['total'] ?? 0 ),
			'opted_in'    => (int) ( $row['opted_in'] ?? 0 ),
			'with_orders' => (int) ( $row['with_orders'] ?? 0 ),
		);
	}

	/**
	 * Update customer interaction timestamp.
	 *
	 * @param int $id The customer ID.
	 * @return bool True on success.
	 */
	public function touchInteraction( int $id ): bool {
		return $this->update(
			$id,
			array( 'last_interaction_at' => current_time( 'mysql' ) )
		);
	}

	/**
	 * Update order statistics for a customer.
	 *
	 * Uses atomic SQL increment to prevent race conditions when
	 * multiple orders are processed simultaneously.
	 *
	 * @param int   $id           The customer ID.
	 * @param float $order_amount The order amount to add.
	 * @return bool True on success.
	 */
	public function incrementOrderStats( int $id, float $order_amount ): bool {
		// Use atomic update to prevent race condition.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table}
				SET total_orders = total_orders + 1,
					total_spent = total_spent + %f,
					updated_at = %s
				WHERE id = %d",
				$order_amount,
				current_time( 'mysql' ),
				$id
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Find customers by segment.
	 *
	 * Uses fully parameterized queries for maximum SQL injection protection.
	 *
	 * @param string $segment The segment (new, bronze, silver, gold, platinum).
	 * @param int    $limit   Maximum customers to return.
	 * @param int    $offset  Number of customers to skip.
	 * @return array<Customer> Customers in the segment.
	 */
	public function findBySegment( string $segment, int $limit = 100, int $offset = 0 ): array {
		// Whitelist valid segments.
		$valid_segments = array( 'new', 'bronze', 'silver', 'gold', 'platinum' );
		if ( ! in_array( $segment, $valid_segments, true ) ) {
			return array();
		}

		// Build fully parameterized query based on segment.
		$sql = "SELECT * FROM {$this->table} WHERE ";

		switch ( $segment ) {
			case 'new':
				$sql .= $this->wpdb->prepare( 'total_orders = %d', 0 );
				break;
			case 'bronze':
				$sql .= $this->wpdb->prepare( 'total_orders > %d AND total_spent < %f', 0, 100.0 );
				break;
			case 'silver':
				$sql .= $this->wpdb->prepare( 'total_spent >= %f AND total_spent < %f', 100.0, 500.0 );
				break;
			case 'gold':
				$sql .= $this->wpdb->prepare( 'total_spent >= %f AND total_spent < %f', 500.0, 1000.0 );
				break;
			case 'platinum':
				$sql .= $this->wpdb->prepare( 'total_spent >= %f', 1000.0 );
				break;
		}

		$sql .= $this->wpdb->prepare( ' ORDER BY total_spent DESC LIMIT %d OFFSET %d', $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'mapToEntity' ), $rows ?: array() );
	}

	/**
	 * Find or create a customer by phone.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE pattern to prevent
	 * race conditions where concurrent requests could create duplicates.
	 *
	 * @param string      $phone The customer phone number.
	 * @param string|null $name  Optional customer name.
	 * @return Customer The customer.
	 */
	public function findOrCreate( string $phone, ?string $name = null ): Customer {
		// Validate phone number format - must be numeric with optional + prefix.
		// WhatsApp phone numbers should be in E.164 format (e.g., +1234567890).
		$sanitized_phone = preg_replace( '/[^0-9+]/', '', $phone );
		if ( empty( $sanitized_phone ) || strlen( $sanitized_phone ) < 7 || strlen( $sanitized_phone ) > 20 ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid phone number format: %s', $phone )
			);
		}

		$now = current_time( 'mysql' );

		// Use INSERT ... ON DUPLICATE KEY UPDATE to atomically find or create.
		// The phone column has a UNIQUE constraint.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->table} (phone, name, preferences, tags, total_orders, total_spent, opt_in_marketing, created_at, updated_at)
				VALUES (%s, %s, %s, %s, %d, %f, %d, %s, %s)
				ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
				$sanitized_phone,
				$name ?? '',
				'{}',
				'[]',
				0,
				0.00,
				0,
				$now,
				$now
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf( 'Failed to create customer for phone: %s', $sanitized_phone )
			);
		}

		// Now fetch the customer (guaranteed to exist after successful INSERT/UPDATE).
		$customer = $this->findByPhone( $sanitized_phone );

		if ( ! $customer ) {
			// This should never happen if the INSERT succeeded, but defend against it.
			throw new \RuntimeException(
				sprintf( 'Failed to retrieve customer after creation for phone: %s', $sanitized_phone )
			);
		}

		return $customer;
	}
}
