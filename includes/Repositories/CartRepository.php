<?php
/**
 * Cart Repository
 *
 * Concrete implementation of cart data access operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Repositories;

use WhatsAppCommerceHub\Contracts\Repositories\CartRepositoryInterface;
use WhatsAppCommerceHub\Domain\Cart\Cart;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// SQL uses safe table names from $wpdb->prefix. Hook names use wch_ project prefix.

/**
 * Class CartRepository
 *
 * Provides cart-specific data access operations.
 */
class CartRepository extends AbstractRepository implements CartRepositoryInterface {

	/**
	 * Get the table name for carts.
	 *
	 * @return string Table name without prefix.
	 */
	protected function getTableName(): string {
		return 'wch_carts';
	}

	/**
	 * Map a database row to a Cart entity.
	 *
	 * @param array $row Database row data.
	 * @return Cart The mapped cart entity.
	 */
	protected function mapToEntity( array $row ): Cart {
		return Cart::fromArray( $row );
	}

	/**
	 * Prepare data for database insertion/update.
	 *
	 * @param array $data Data to prepare.
	 * @return array Prepared data.
	 */
	protected function prepareData( array $data ): array {
		// Convert arrays to JSON.
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$data['items'] = wp_json_encode( $data['items'] );
		}

		if ( isset( $data['shipping_address'] ) && is_array( $data['shipping_address'] ) ) {
			$data['shipping_address'] = wp_json_encode( $data['shipping_address'] );
		}

		// Convert booleans.
		if ( isset( $data['recovered'] ) ) {
			$data['recovered'] = $data['recovered'] ? 1 : 0;
		}

		// Convert DateTimeImmutable objects.
		$date_fields = array(
			'expires_at',
			'created_at',
			'updated_at',
			'reminder_1_sent_at',
			'reminder_2_sent_at',
			'reminder_3_sent_at',
		);

		foreach ( $date_fields as $field ) {
			if ( isset( $data[ $field ] ) && $data[ $field ] instanceof \DateTimeInterface ) {
				$data[ $field ] = $data[ $field ]->format( 'Y-m-d H:i:s' );
			}
		}

		return $data;
	}

	/**
	 * Find a cart by ID.
	 *
	 * @param int $id Cart ID.
	 * @return Cart|null The cart or null if not found.
	 */
	public function find( int $id ): ?Cart {
		$entity = parent::find( $id );
		return $entity instanceof Cart ? $entity : null;
	}

	/**
	 * Find active cart by customer phone number.
	 *
	 * @param string $phone Customer phone number.
	 * @return Cart|null The active cart or null.
	 */
	public function findActiveByPhone( string $phone ): ?Cart {
		$sql = "SELECT * FROM {$this->table}
				WHERE customer_phone = %s
				AND status = %s
				AND expires_at > %s
				ORDER BY created_at DESC
				LIMIT 1";

		$row = $this->queryRow(
			$sql,
			array( $phone, Cart::STATUS_ACTIVE, current_time( 'mysql' ) )
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * Find abandoned carts older than threshold.
	 *
	 * @param int $hours_threshold Hours since last update.
	 * @return array Array of abandoned Cart entities.
	 */
	public function findAbandonedCarts( int $hours_threshold = 24 ): array {
		$threshold_time = ( new \DateTimeImmutable() )
			->modify( "-{$hours_threshold} hours" )
			->format( 'Y-m-d H:i:s' );

		$sql = "SELECT * FROM {$this->table}
				WHERE status = %s
				AND updated_at < %s
				ORDER BY updated_at ASC";

		$rows = $this->query(
			$sql,
			array( Cart::STATUS_ABANDONED, $threshold_time )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * Find all expired carts.
	 *
	 * @return array Array of expired Cart entities.
	 */
	public function findExpiredCarts(): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE status = %s
				AND expires_at < %s
				ORDER BY expires_at ASC";

		$rows = $this->query(
			$sql,
			array( Cart::STATUS_ACTIVE, current_time( 'mysql' ) )
		);

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * Mark a cart as abandoned.
	 *
	 * @param int $cart_id Cart ID.
	 * @return bool True on success.
	 */
	public function markAsAbandoned( int $cart_id ): bool {
		return $this->update(
			$cart_id,
			array( 'status' => Cart::STATUS_ABANDONED )
		);
	}

	/**
	 * Allowed reminder column names (whitelist for SQL safety).
	 *
	 * @var array<int, string>
	 */
	private const REMINDER_COLUMNS = array(
		1 => 'reminder_1_sent_at',
		2 => 'reminder_2_sent_at',
		3 => 'reminder_3_sent_at',
	);

	/**
	 * Mark a reminder as sent.
	 *
	 * @param int $cart_id         Cart ID.
	 * @param int $reminder_number Reminder number (1, 2, or 3).
	 * @return bool True on success.
	 */
	public function markReminderSent( int $cart_id, int $reminder_number ): bool {
		// Use whitelist for SQL safety - prevents SQL injection via column name.
		if ( ! isset( self::REMINDER_COLUMNS[ $reminder_number ] ) ) {
			return false;
		}

		$column = self::REMINDER_COLUMNS[ $reminder_number ];

		return $this->update(
			$cart_id,
			array( $column => current_time( 'mysql' ) )
		);
	}

	/**
	 * Mark a cart as recovered with linked order.
	 *
	 * Uses transaction with FOR UPDATE lock to prevent double-marking
	 * when recovery is triggered concurrently.
	 *
	 * @param int $cart_id  Cart ID.
	 * @param int $order_id Order ID that recovered the cart.
	 * @return bool True on success.
	 */
	public function markAsRecovered( int $cart_id, int $order_id ): bool {
		$this->beginTransaction();

		try {
			// Lock the cart and check it hasn't already been recovered.
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT id, total, recovered FROM {$this->table}
					WHERE id = %d
					FOR UPDATE",
					$cart_id
				),
				ARRAY_A
			);

			if ( ! $row ) {
				$this->rollback();
				return false;
			}

			// Already recovered - skip.
			if ( (int) $row['recovered'] === 1 ) {
				$this->rollback();
				return false;
			}

			// Use direct wpdb->update instead of $this->update() to avoid
			// redundant exists() check - we already verified row exists via FOR UPDATE.
			// This also ensures we stay within the same transaction context.
			$result = $this->wpdb->update(
				$this->table,
				array(
					'status'             => Cart::STATUS_CONVERTED,
					'recovered'          => 1,
					'recovered_order_id' => $order_id,
					'recovered_revenue'  => (float) $row['total'],
					'updated_at'         => current_time( 'mysql' ),
				),
				array( 'id' => $cart_id ),
				array( '%s', '%d', '%d', '%f', '%s' ),
				array( '%d' )
			);

			if ( false !== $result ) {
				$this->commit();
				return true;
			}

			$this->rollback();
			return false;
		} catch ( \Throwable $e ) {
			$this->rollback();
			do_action( 'wch_log_error', 'markAsRecovered failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get recovery statistics for a date range.
	 *
	 * @param \DateTimeInterface $start_date Start date.
	 * @param \DateTimeInterface $end_date   End date.
	 * @return array Statistics array with total_abandoned, recovered_count, recovered_revenue, recovery_rate.
	 */
	public function getRecoveryStats( \DateTimeInterface $start_date, \DateTimeInterface $end_date ): array {
		$start = $start_date->format( 'Y-m-d H:i:s' );
		$end   = $end_date->format( 'Y-m-d H:i:s' );

		$sql = "SELECT
					COUNT(*) as total_abandoned,
					SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) as recovered_count,
					SUM(CASE WHEN recovered = 1 THEN recovered_revenue ELSE 0 END) as recovered_revenue
				FROM {$this->table}
				WHERE status IN (%s, %s)
				AND created_at BETWEEN %s AND %s";

		$row = $this->queryRow(
			$sql,
			array( Cart::STATUS_ABANDONED, Cart::STATUS_CONVERTED, $start, $end )
		);

		$total_abandoned   = (int) ( $row['total_abandoned'] ?? 0 );
		$recovered_count   = (int) ( $row['recovered_count'] ?? 0 );
		$recovered_revenue = (float) ( $row['recovered_revenue'] ?? 0 );
		$recovery_rate     = $total_abandoned > 0
			? round( ( $recovered_count / $total_abandoned ) * 100, 2 )
			: 0.0;

		return array(
			'total_abandoned'   => $total_abandoned,
			'recovered_count'   => $recovered_count,
			'recovered_revenue' => $recovered_revenue,
			'recovery_rate'     => $recovery_rate,
		);
	}

	/**
	 * Cleanup expired carts in batches.
	 *
	 * @param int $batch_size Number of carts to delete per batch.
	 * @return int Number of carts deleted.
	 */
	public function cleanupExpired( int $batch_size = 100 ): int {
		$sql = "DELETE FROM {$this->table}
				WHERE status = %s
				LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare( $sql, Cart::STATUS_EXPIRED, $batch_size )
		);

		return $this->wpdb->rows_affected;
	}

	/**
	 * Find carts due for a reminder.
	 *
	 * @param int $reminder_number Reminder number (1, 2, or 3).
	 * @param int $delay_hours     Hours since last activity.
	 * @param int $limit           Maximum carts to return.
	 * @return array Array of Cart entities due for reminder.
	 */
	public function findDueForReminder( int $reminder_number, int $delay_hours, int $limit = 50 ): array {
		// Use whitelist for SQL safety - prevents SQL injection via column name.
		if ( ! isset( self::REMINDER_COLUMNS[ $reminder_number ] ) ) {
			return array();
		}

		$reminder_column = self::REMINDER_COLUMNS[ $reminder_number ];
		$previous_column = $reminder_number > 1 ? self::REMINDER_COLUMNS[ $reminder_number - 1 ] : null;
		$threshold_time  = ( new \DateTimeImmutable() )
			->modify( "-{$delay_hours} hours" )
			->format( 'Y-m-d H:i:s' );

		$conditions = array(
			'status = %s',
			"{$reminder_column} IS NULL",
			'updated_at < %s',
		);

		$params = array( Cart::STATUS_ABANDONED, $threshold_time );

		// For reminder 2 and 3, require the previous reminder to have been sent.
		if ( $previous_column ) {
			$conditions[] = "{$previous_column} IS NOT NULL";
		}

		$sql = "SELECT * FROM {$this->table}
				WHERE " . implode( ' AND ', $conditions ) . '
				ORDER BY updated_at ASC
				LIMIT %d';

		$params[] = $limit;

		$rows = $this->query( $sql, $params );

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * Find cart by ID with row lock for transactional updates.
	 *
	 * Must be called within an active transaction. Uses FOR UPDATE to prevent
	 * concurrent modifications (TOCTOU race conditions).
	 *
	 * @param int $id Cart ID.
	 * @return Cart|null Cart entity or null if not found.
	 */
	public function findForUpdate( int $id ): ?Cart {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d FOR UPDATE",
				$id
			),
			ARRAY_A
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * Find active cart by phone with row lock for transactional updates.
	 *
	 * Must be called within an active transaction. Uses FOR UPDATE to prevent
	 * concurrent modifications (TOCTOU race conditions).
	 *
	 * @param string $phone Customer phone number.
	 * @return Cart|null Cart entity or null if not found.
	 */
	public function findActiveByPhoneForUpdate( string $phone ): ?Cart {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table}
				WHERE customer_phone = %s
				AND status = %s
				AND expires_at > %s
				ORDER BY created_at DESC
				LIMIT 1
				FOR UPDATE",
				$phone,
				Cart::STATUS_ACTIVE,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * Update cart within a transaction (no existence check).
	 *
	 * Used when the caller has already locked and verified the cart exists
	 * via findForUpdate() or findActiveByPhoneForUpdate().
	 *
	 * @param int   $id   Cart ID.
	 * @param array $data Data to update.
	 * @return bool Success status.
	 */
	public function updateLocked( int $id, array $data ): bool {
		$data               = $this->prepareData( $data );
		$data['updated_at'] = current_time( 'mysql' );

		$result = $this->wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			$this->getFormats( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get carts by customer phone.
	 *
	 * @param string $phone The customer phone number.
	 * @param int    $limit Maximum carts to return.
	 * @return array<Cart> Array of carts.
	 */
	public function findByPhone( string $phone, int $limit = 10 ): array {
		$sql = "SELECT * FROM {$this->table}
				WHERE customer_phone = %s
				ORDER BY created_at DESC
				LIMIT %d";

		$rows = $this->query( $sql, array( $phone, $limit ) );

		return array_map( array( $this, 'mapToEntity' ), $rows );
	}

	/**
	 * Get total cart value for a customer.
	 *
	 * @param string $phone The customer phone number.
	 * @return float Total cart value.
	 */
	public function getTotalCartValueByPhone( string $phone ): float {
		$sql = "SELECT SUM(total) FROM {$this->table}
				WHERE customer_phone = %s
				AND status = %s";

		$total = $this->queryVar(
			$sql,
			array( $phone, Cart::STATUS_CONVERTED )
		);

		return (float) ( $total ?? 0 );
	}

	/**
	 * Atomically find or create an active cart with row lock.
	 *
	 * Uses advisory locking to prevent TOCTOU race conditions when multiple
	 * concurrent requests try to create a cart for the same customer.
	 *
	 * MUST be called within an active transaction.
	 *
	 * @param string             $phone     Customer phone number.
	 * @param \DateTimeImmutable $expiresAt Cart expiration time.
	 * @return Cart The active cart (locked for update).
	 * @throws \RuntimeException If cart cannot be created or locked.
	 * @throws \Throwable If any other error occurs during cart creation.
	 */
	public function findOrCreateActiveForUpdate( string $phone, \DateTimeImmutable $expiresAt ): Cart {
		// Generate a unique lock key based on the phone number.
		$lock_key = 'wch_cart_' . md5( $phone );

		// Acquire advisory lock (5 second timeout).
		$lock_acquired = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key )
		);

		if ( '1' !== (string) $lock_acquired ) {
			throw new \RuntimeException(
				'Failed to acquire lock for cart creation. Another process may be creating a cart for this customer.'
			);
		}

		try {
			// Try to find existing active cart.
			$cart = $this->findActiveByPhoneForUpdate( $phone );

			if ( $cart ) {
				// Release lock and return existing cart.
				$this->wpdb->query(
					$this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key )
				);
				return $cart;
			}

			// No active cart exists - create one.
			$now = new \DateTimeImmutable();
			$id  = $this->create(
				array(
					'customer_phone' => $phone,
					'items'          => array(),
					'total'          => 0.00,
					'status'         => Cart::STATUS_ACTIVE,
					'expires_at'     => $expiresAt,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);

			// Lock the newly created cart.
			$cart = $this->findForUpdate( $id );

			// Release advisory lock.
			$this->wpdb->query(
				$this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key )
			);

			if ( ! $cart ) {
				throw new \RuntimeException( 'Failed to lock newly created cart' );
			}

			return $cart;
		} catch ( \Throwable $e ) {
			// Ensure lock is released on any error.
			$this->wpdb->query(
				$this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key )
			);
			throw $e;
		}
	}
}
