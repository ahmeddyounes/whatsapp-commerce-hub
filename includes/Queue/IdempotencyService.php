<?php
/**
 * Idempotency Service
 *
 * Provides atomic claim mechanism for preventing duplicate processing
 * of webhooks, notifications, and other operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// SQL uses safe table names from $wpdb->prefix. Hook names use wch_ project prefix.

/**
 * Class IdempotencyService
 *
 * Uses database atomic operations (INSERT IGNORE) to ensure only one
 * process can claim a specific operation key. Supports multiple scopes
 * for different operation types and TTL-based cleanup.
 */
class IdempotencyService {

	/**
	 * Scope constants for different operation types.
	 */
	public const SCOPE_WEBHOOK      = 'webhook';
	public const SCOPE_NOTIFICATION = 'notification';
	public const SCOPE_ORDER        = 'order';
	public const SCOPE_BROADCAST    = 'broadcast';
	public const SCOPE_SYNC         = 'sync';

	/**
	 * Default TTL in hours for idempotency keys.
	 */
	private const DEFAULT_TTL_HOURS = 24;

	/**
	 * Database table name (without prefix).
	 */
	private const TABLE_NAME = 'wch_webhook_idempotency';

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb WordPress database instance.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Get the full table name.
	 *
	 * @return string Full table name with prefix.
	 */
	private function getTableName(): string {
		return $this->wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Attempt to atomically claim an operation key.
	 *
	 * Uses INSERT IGNORE for atomic claim - only succeeds if no duplicate exists.
	 * The UNIQUE KEY on (message_id, scope) ensures atomicity.
	 *
	 * @param string   $key      Unique identifier for the operation (e.g., message_id).
	 * @param string   $scope    Operation scope (webhook, notification, etc.).
	 * @param int|null $ttlHours TTL in hours for cleanup. Null for no expiry.
	 * @return bool True if claim succeeded (first to process), false if already claimed.
	 */
	public function claim( string $key, string $scope = self::SCOPE_WEBHOOK, ?int $ttlHours = null ): bool {
		$table = $this->getTableName();

		$ttlHours  = $ttlHours ?? self::DEFAULT_TTL_HOURS;
		$expiresAt = gmdate( 'Y-m-d H:i:s', strtotime( "+{$ttlHours} hours" ) );

		// Use INSERT IGNORE for atomic claim.
		// The UNIQUE KEY ensures only one process can insert.
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT IGNORE INTO {$table} (message_id, scope, processed_at, expires_at)
				VALUES (%s, %s, %s, %s)",
				$key,
				$scope,
				current_time( 'mysql', true ),
				$expiresAt
			)
		);

		// INSERT IGNORE returns:
		// - 1 if a new row was inserted (we claimed it).
		// - 0 if the row already exists (duplicate key).
		// - false on error.
		return 1 === $result;
	}

	/**
	 * Check if a key is already claimed without claiming it.
	 *
	 * @param string $key   Unique identifier for the operation.
	 * @param string $scope Operation scope.
	 * @return bool True if already claimed.
	 */
	public function isClaimed( string $key, string $scope = self::SCOPE_WEBHOOK ): bool {
		$table = $this->getTableName();

		$result = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE message_id = %s AND scope = %s LIMIT 1",
				$key,
				$scope
			)
		);

		return null !== $result;
	}

	/**
	 * Release a claim (allow reprocessing).
	 *
	 * Useful for error recovery or manual retry scenarios.
	 *
	 * @param string $key   Unique identifier for the operation.
	 * @param string $scope Operation scope.
	 * @return bool True if released.
	 */
	public function release( string $key, string $scope = self::SCOPE_WEBHOOK ): bool {
		$table = $this->getTableName();

		$result = $this->wpdb->delete(
			$table,
			[
				'message_id' => $key,
				'scope'      => $scope,
			],
			[ '%s', '%s' ]
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Release all claims for a specific scope.
	 *
	 * @param string $scope Operation scope to release.
	 * @return int Number of claims released.
	 */
	public function releaseByScope( string $scope ): int {
		$table = $this->getTableName();

		$result = $this->wpdb->delete(
			$table,
			[ 'scope' => $scope ],
			[ '%s' ]
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Clean up expired idempotency keys.
	 *
	 * Should be called periodically via cron.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup(): int {
		$table = $this->getTableName();
		$now = current_time( 'mysql', true );

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",
				$now
			)
		);

		$deleted = false === $result ? 0 : (int) $result;

		if ( $deleted > 0 ) {
			do_action( 'wch_log_info', "IdempotencyService: Cleaned up {$deleted} expired entries" );
		}

		return $deleted;
	}

	/**
	 * Get statistics about idempotency entries.
	 *
	 * @return array<string, mixed> Statistics by scope.
	 */
	public function getStats(): array {
		$table = $this->getTableName();

		$results = $this->wpdb->get_results(
			"SELECT
				scope,
				COUNT(*) as total,
				SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP() THEN 1 ELSE 0 END) as expired
			FROM {$table}
			GROUP BY scope"
		);

		$stats = [
			'total'    => 0,
			'expired'  => 0,
			'by_scope' => [],
		];

		foreach ( $results as $row ) {
			$stats['total']                  += (int) $row->total;
			$stats['expired']                += (int) $row->expired;
			$stats['by_scope'][ $row->scope ] = [
				'total'   => (int) $row->total,
				'expired' => (int) $row->expired,
			];
		}

		return $stats;
	}

	/**
	 * Generate a unique idempotency key from multiple parts.
	 *
	 * @param string ...$parts Parts to combine into a key.
	 * @return string The generated key.
	 */
	public static function generateKey( string ...$parts ): string {
		return hash( 'sha256', implode( ':', $parts ) );
	}

	/**
	 * Claim with automatic key generation.
	 *
	 * @param string $scope     Operation scope.
	 * @param string ...$parts  Parts to combine into a key.
	 * @return bool True if claim succeeded.
	 */
	public function claimWithParts( string $scope, string ...$parts ): bool {
		$key = self::generateKey( ...$parts );
		return $this->claim( $key, $scope );
	}

	/**
	 * Extend the expiry time of an existing claim.
	 *
	 * @param string $key             Unique identifier for the operation.
	 * @param string $scope           Operation scope.
	 * @param int    $additionalHours Additional hours to extend.
	 * @return bool True if extended.
	 */
	public function extendExpiry( string $key, string $scope, int $additionalHours ): bool {
		$table = $this->getTableName();

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table}
				SET expires_at = DATE_ADD(expires_at, INTERVAL %d HOUR)
				WHERE message_id = %s AND scope = %s",
				$additionalHours,
				$key,
				$scope
			)
		);

		return false !== $result && $result > 0;
	}
}
