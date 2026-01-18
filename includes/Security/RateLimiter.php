<?php
/**
 * Rate Limiter
 *
 * Database-backed rate limiting with sliding window algorithm.
 * Provides atomic operations to prevent race conditions.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Security;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// The table name comes from $wpdb->prefix which is safe. All user data uses proper placeholders.
// Hook names use wch_ prefix which is the project's standard prefix.

/**
 * Class RateLimiter
 *
 * Implements rate limiting with database persistence.
 */
class RateLimiter {

	/**
	 * The WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * The rate limits table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Rate limit configurations.
	 *
	 * @var array<string, array{limit: int, window: int}>
	 */
	private array $limits = [
		'webhook'      => [
			'limit'  => 1000,
			'window' => 60,
		],    // 1000/min.
		'api'          => [
			'limit'  => 100,
			'window' => 60,
		],     // 100/min.
		'admin'        => [
			'limit'  => 60,
			'window' => 60,
		],      // 60/min.
		'auth'         => [
			'limit'  => 5,
			'window' => 300,
		],      // 5/5min.
		'message_send' => [
			'limit'  => 30,
			'window' => 60,
		],      // 30/min (WhatsApp limit).
		'broadcast'    => [
			'limit'  => 10,
			'window' => 3600,
		],    // 10/hour.
		'export'       => [
			'limit'  => 5,
			'window' => 3600,
		],     // 5/hour.
	];

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb The WordPress database instance.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'wch_rate_limits';
	}

	/**
	 * Check if a request is allowed (read-only, no side effects).
	 *
	 * WARNING: TOCTOU Race Condition
	 * ==============================
	 * This method is READ-ONLY and does NOT record a hit. Using check() followed
	 * by hit() separately is NOT atomic and is vulnerable to race conditions.
	 *
	 * Between check() returning "allowed" and hit() recording the request,
	 * concurrent requests could consume all available quota, leading to
	 * over-admission beyond the configured limit.
	 *
	 * CORRECT USAGE:
	 * - For enforcement: Use checkAndHit() which atomically checks and records.
	 * - For display only: Use check() to show remaining quota in UI (no enforcement).
	 *
	 * Example (CORRECT - atomic enforcement):
	 *   $result = $rateLimiter->checkAndHit($ip, 'api');
	 *   if (!$result['allowed']) {
	 *       return new WP_Error('rate_limited', 'Too many requests', 429);
	 *   }
	 *
	 * Example (CORRECT - display only):
	 *   $result = $rateLimiter->check($ip, 'api');
	 *   echo "Remaining: " . $result['remaining'];
	 *
	 * Example (WRONG - race condition):
	 *   $result = $rateLimiter->check($ip, 'api');
	 *   if ($result['allowed']) {
	 *       $rateLimiter->hit($ip, 'api'); // Race condition here!
	 *       doWork();
	 *   }
	 *
	 * @param string   $identifier The identifier (IP, user ID, phone, etc.).
	 * @param string   $limit_type The rate limit type.
	 * @param int|null $limit      Optional custom limit.
	 * @param int|null $window     Optional custom window in seconds.
	 * @return array{allowed: bool, remaining: int, reset_at: int}
	 */
	public function check(
		string $identifier,
		string $limit_type,
		?int $limit = null,
		?int $window = null
	): array {
		$config = $this->limits[ $limit_type ] ?? [
			'limit'  => 100,
			'window' => 60,
		];

		$limit  = $limit ?? $config['limit'];
		$window = $window ?? $config['window'];
		$now    = time();

		if ( ! $this->tableExists() ) {
			return [
				'allowed'   => true,
				'remaining' => $limit,
				'reset_at'  => $now + $window,
			];
		}

		$identifier_hash = $this->hashIdentifier( $identifier );

		// Block checks are stored as a single row keyed by window_start = 'blocked'.
		$blocked_until = $this->getBlockUntilTimestamp( $identifier_hash );
		if ( null !== $blocked_until && $blocked_until > $now ) {
			return [
				'allowed'   => false,
				'remaining' => 0,
				'reset_at'  => $blocked_until,
			];
		}

		$window_start_ts  = $this->getWindowStartTimestamp( $now, $window );
		$window_start_str = gmdate( 'Y-m-d H:i', $window_start_ts );

		// Clean expired entries for this identifier/type.
		$this->cleanExpiredEntries( $identifier_hash, $limit_type, gmdate( 'Y-m-d H:i:s', $now ) );

		$request_count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT request_count FROM {$this->table}
				WHERE identifier_hash = %s
				AND limit_type = %s
				AND window_start = %s",
				$identifier_hash,
				$limit_type,
				$window_start_str
			)
		);

		$allowed   = $request_count < $limit;
		$remaining = max( 0, $limit - min( $request_count, $limit ) );
		$reset_at  = $window_start_ts + $window;

		return [
			'allowed'   => $allowed,
			'remaining' => $remaining,
			'reset_at'  => $reset_at,
		];
	}

	/**
	 * Atomically check and record a request hit.
	 *
	 * Uses database locking to prevent race conditions between
	 * checking the limit and recording the hit.
	 *
	 * @param string   $identifier The identifier.
	 * @param string   $limit_type The rate limit type.
	 * @param int|null $limit      Optional custom limit.
	 * @param int|null $window     Optional custom window in seconds.
	 * @return array{allowed: bool, remaining: int, reset_at: int}
	 * @throws \Throwable If database transaction fails.
	 */
	public function checkAndHit(
		string $identifier,
		string $limit_type,
		?int $limit = null,
		?int $window = null
	): array {
		$config = $this->limits[ $limit_type ] ?? [
			'limit'  => 100,
			'window' => 60,
		];

		$limit  = $limit ?? $config['limit'];
		$window = $window ?? $config['window'];
		$now    = time();

		if ( ! $this->tableExists() ) {
			return [
				'allowed'   => true,
				'remaining' => $limit,
				'reset_at'  => $now + $window,
			];
		}

		$identifier_hash = $this->hashIdentifier( $identifier );

		$blocked_until = $this->getBlockUntilTimestamp( $identifier_hash );
		if ( null !== $blocked_until && $blocked_until > $now ) {
			return [
				'allowed'   => false,
				'remaining' => 0,
				'reset_at'  => $blocked_until,
			];
		}

		$window_start_ts   = $this->getWindowStartTimestamp( $now, $window );
		$window_start_str  = gmdate( 'Y-m-d H:i', $window_start_ts );
		$now_mysql         = gmdate( 'Y-m-d H:i:s', $now );
		$expires_at_mysql  = gmdate( 'Y-m-d H:i:s', $window_start_ts + $window );
		$reset_at          = $window_start_ts + $window;

		$this->wpdb->query( 'START TRANSACTION' );

		try {
			// Remove expired window entries for this identifier/type.
			$this->wpdb->query(
				$this->wpdb->prepare(
					"DELETE FROM {$this->table}
					WHERE identifier_hash = %s
					AND limit_type = %s
					AND window_start != %s
					AND expires_at IS NOT NULL
					AND expires_at < %s",
					$identifier_hash,
					$limit_type,
					'blocked',
					$now_mysql
				)
			);

			// Ensure the current window row exists.
			$this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO {$this->table} (identifier_hash, limit_type, request_count, window_start, created_at, expires_at)
					VALUES (%s, %s, 0, %s, %s, %s)
					ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)",
					$identifier_hash,
					$limit_type,
					$window_start_str,
					$now_mysql,
					$expires_at_mysql
				)
			);

			// Atomically increment ONLY if under limit.
			$rows_affected = $this->wpdb->query(
				$this->wpdb->prepare(
					"UPDATE {$this->table}
					SET request_count = request_count + 1
					WHERE identifier_hash = %s
					AND limit_type = %s
					AND window_start = %s
					AND request_count < %d",
					$identifier_hash,
					$limit_type,
					$window_start_str,
					$limit
				)
			);

			if ( false === $rows_affected ) {
				$this->wpdb->query( 'ROLLBACK' );
				return [
					'allowed'   => true,
					'remaining' => $limit,
					'reset_at'  => $reset_at,
				];
			}

			$allowed = $rows_affected > 0;

			$request_count = (int) $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT request_count FROM {$this->table}
					WHERE identifier_hash = %s
					AND limit_type = %s
					AND window_start = %s",
					$identifier_hash,
					$limit_type,
					$window_start_str
				)
			);

			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		$remaining  = max( 0, $limit - min( $request_count, $limit ) );

		return [
			'allowed'   => $allowed,
			'remaining' => $remaining,
			'reset_at'  => $reset_at,
		];
	}

	/**
	 * Record a request hit.
	 *
	 * WARNING: Do not use hit() alone for rate limiting enforcement.
	 * This method only records a hit without checking the limit first.
	 * For atomic rate limiting, use checkAndHit() instead.
	 *
	 * This method is intended for:
	 * - Recording hits after checkAndHit() in special cases
	 * - Analytics/tracking where enforcement isn't needed
	 *
	 * @see checkAndHit() For atomic rate limiting with enforcement.
	 *
	 * @param string $identifier The identifier.
	 * @param string $limit_type The rate limit type.
	 * @return bool True if recorded successfully.
	 */
	public function hit( string $identifier, string $limit_type ): bool {
		if ( ! $this->tableExists() ) {
			return true;
		}

		$config = $this->limits[ $limit_type ] ?? [
			'limit'  => 100,
			'window' => 60,
		];

		$window = $config['window'];
		$now    = time();

		$identifier_hash  = $this->hashIdentifier( $identifier );
		$window_start_ts  = $this->getWindowStartTimestamp( $now, $window );
		$window_start_str = gmdate( 'Y-m-d H:i', $window_start_ts );
		$now_mysql        = gmdate( 'Y-m-d H:i:s', $now );
		$expires_at_mysql = gmdate( 'Y-m-d H:i:s', $window_start_ts + $window );

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$this->table} (identifier_hash, limit_type, request_count, window_start, created_at, expires_at)
				VALUES (%s, %s, 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
				request_count = request_count + 1,
				expires_at = VALUES(expires_at)",
				$identifier_hash,
				$limit_type,
				$window_start_str,
				$now_mysql,
				$expires_at_mysql
			)
		);

		return false !== $result;
	}

	/**
	 * Attempt an action with rate limiting.
	 *
	 * Uses atomic check-and-hit to prevent race conditions.
	 *
	 * @param string   $identifier The identifier.
	 * @param string   $limit_type The rate limit type.
	 * @param callable $callback   The action to perform if allowed.
	 * @return array{success: bool, result: mixed, rate_limit: array}
	 */
	public function attempt( string $identifier, string $limit_type, callable $callback ): array {
		// Use atomic check-and-hit to prevent race conditions.
		$check = $this->checkAndHit( $identifier, $limit_type );

		if ( ! $check['allowed'] ) {
			return [
				'success'    => false,
				'result'     => null,
				'rate_limit' => $check,
			];
		}

		// Perform the action.
		$result = $callback();

		return [
			'success'    => true,
			'result'     => $result,
			'rate_limit' => $check,
		];
	}

	/**
	 * Reset rate limit for an identifier.
	 *
	 * @param string $identifier The identifier.
	 * @param string $limit_type The rate limit type (or empty for all).
	 * @return int Number of entries deleted.
	 */
	public function reset( string $identifier, string $limit_type = '' ): int {
		$identifier_hash = $this->hashIdentifier( $identifier );

		if ( ! $this->tableExists() ) {
			return 0;
		}

		if ( $limit_type ) {
			$this->wpdb->delete(
				$this->table,
				[
					'identifier_hash' => $identifier_hash,
					'limit_type'      => $limit_type,
				],
				[ '%s', '%s' ]
			);
		} else {
			$this->wpdb->delete(
				$this->table,
				[ 'identifier_hash' => $identifier_hash ],
				[ '%s' ]
			);
		}

		return $this->wpdb->rows_affected;
	}

	/**
	 * Block an identifier completely.
	 *
	 * @param string $identifier The identifier to block.
	 * @param int    $duration   Block duration in seconds.
	 * @param string $reason     Reason for blocking.
	 * @return bool True if blocked.
	 */
	public function block( string $identifier, int $duration = 3600, string $reason = '' ): bool {
		if ( ! $this->tableExists() ) {
			return true;
		}

		$identifier_hash = $this->hashIdentifier( $identifier );
		$now             = time();

		$result = $this->wpdb->replace(
			$this->table,
			[
				'identifier_hash' => $identifier_hash,
				'limit_type'      => 'blocked',
				'request_count'   => 0,
				'window_start'    => 'blocked',
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'      => gmdate( 'Y-m-d H:i:s', $now + $duration ),
				'metadata'        => wp_json_encode( [ 'reason' => $reason ] ),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( $result ) {
			do_action(
				'wch_security_log',
				'rate_limit_block',
				[
					'identifier' => $identifier_hash,
					'duration'   => $duration,
					'reason'     => $reason,
				]
			);
		}

		return false !== $result;
	}

	/**
	 * Check if an identifier is blocked.
	 *
	 * @param string $identifier The identifier.
	 * @return bool True if blocked.
	 */
	public function isBlocked( string $identifier ): bool {
		if ( ! $this->tableExists() ) {
			return false;
		}

		$identifier_hash = $this->hashIdentifier( $identifier );
		$now_mysql       = gmdate( 'Y-m-d H:i:s', time() );

		$blocked = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT 1 FROM {$this->table}
				WHERE identifier_hash = %s
				AND limit_type = 'blocked'
				AND window_start = %s
				AND expires_at > %s
				LIMIT 1",
				$identifier_hash,
				'blocked',
				$now_mysql
			)
		);

		return (bool) $blocked;
	}

	/**
	 * Unblock an identifier.
	 *
	 * @param string $identifier The identifier to unblock.
	 * @return bool True if unblocked.
	 */
	public function unblock( string $identifier ): bool {
		if ( ! $this->tableExists() ) {
			return true;
		}

		$identifier_hash = $this->hashIdentifier( $identifier );

		$this->wpdb->delete(
			$this->table,
			[
				'identifier_hash' => $identifier_hash,
				'limit_type'      => 'blocked',
				'window_start'    => 'blocked',
			],
			[ '%s', '%s', '%s' ]
		);

		return $this->wpdb->rows_affected > 0;
	}

	/**
	 * Configure a rate limit.
	 *
	 * @param string $limit_type The limit type name.
	 * @param int    $limit      Maximum requests.
	 * @param int    $window     Time window in seconds.
	 * @return void
	 */
	public function configure( string $limit_type, int $limit, int $window ): void {
		$this->limits[ $limit_type ] = [
			'limit'  => $limit,
			'window' => $window,
		];
	}

	/**
	 * Get rate limit headers for HTTP response.
	 *
	 * @param array $check The check result.
	 * @return array<string, string> HTTP headers.
	 */
	public function getHeaders( array $check ): array {
		return [
			'X-RateLimit-Remaining' => (string) $check['remaining'],
			'X-RateLimit-Reset'     => (string) $check['reset_at'],
		];
	}

	/**
	 * Clean old rate limit entries.
	 *
	 * @param string $identifier_hash The identifier hash.
	 * @param string $limit_type      The limit type.
	 * @param int    $window_start    The window start timestamp.
	 * @return void
	 */
	private function cleanExpiredEntries( string $identifier_hash, string $limit_type, string $now_mysql ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table}
				WHERE identifier_hash = %s
				AND limit_type = %s
				AND window_start != %s
				AND expires_at IS NOT NULL
				AND expires_at < %s",
				$identifier_hash,
				$limit_type,
				'blocked',
				$now_mysql
			)
		);
	}

	/**
	 * Hash an identifier for storage.
	 *
	 * @param string $identifier The identifier.
	 * @return string The hashed identifier.
	 */
	private function hashIdentifier( string $identifier ): string {
		return hash( 'sha256', $identifier );
	}

	/**
	 * Check whether the rate limits table exists.
	 *
	 * @return bool
	 */
	private function tableExists(): bool {
		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table )
		);

		$exists = ! empty( $result );
		return $exists;
	}

	/**
	 * Get the start of the current window.
	 *
	 * @param int $now    Current UNIX timestamp.
	 * @param int $window Window size in seconds.
	 * @return int
	 */
	private function getWindowStartTimestamp( int $now, int $window ): int {
		if ( $window <= 0 ) {
			return $now;
		}

		return (int) ( floor( $now / $window ) * $window );
	}

	/**
	 * Get block expiry timestamp for identifier hash.
	 *
	 * @param string $identifier_hash Hashed identifier.
	 * @return int|null
	 */
	private function getBlockUntilTimestamp( string $identifier_hash ): ?int {
		$expires_at = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT expires_at FROM {$this->table}
				WHERE identifier_hash = %s
				AND limit_type = 'blocked'
				AND window_start = %s
				AND expires_at IS NOT NULL
				LIMIT 1",
				$identifier_hash,
				'blocked'
			)
		);

		if ( empty( $expires_at ) || ! is_string( $expires_at ) ) {
			return null;
		}

		$timestamp = strtotime( $expires_at );
		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Calculate reset time with overflow protection.
	 *
	 * Safely handles:
	 * - Invalid date strings from database
	 * - Integer overflow from large window values
	 * - Timestamp values near PHP_INT_MAX
	 *
	 * @param string|null $oldest_date The oldest entry date string.
	 * @param int         $window      The rate limit window in seconds.
	 * @param int         $now         Current timestamp.
	 * @return int The reset timestamp (clamped to safe bounds).
	 */
	private function calculateResetTime( ?string $oldest_date, int $window, int $now ): int {
		// Maximum reasonable timestamp (year 2100 - far enough for any practical use).
		$max_timestamp = 4102444800;

		// Ensure window is positive and reasonable (max 1 year).
		$window = min( max( 0, $window ), 31536000 );

		if ( empty( $oldest_date ) ) {
			return min( $now + $window, $max_timestamp );
		}

		// Parse the date string - strtotime returns false on failure.
		$oldest_timestamp = strtotime( $oldest_date );

		if ( false === $oldest_timestamp || $oldest_timestamp < 0 ) {
			// Invalid date string - log and fallback to now + window.
			do_action(
				'wch_log_warning',
				'RateLimiter: Invalid date string in database',
				[
					'date_string' => $oldest_date,
				]
			);
			return min( $now + $window, $max_timestamp );
		}

		// Protect against overflow: check if addition would overflow.
		if ( $oldest_timestamp > $max_timestamp - $window ) {
			return $max_timestamp;
		}

		return $oldest_timestamp + $window;
	}

	/**
	 * Cleanup all expired entries.
	 *
	 * @return int Number of entries deleted.
	 */
	public function cleanup(): int {
		if ( ! $this->tableExists() ) {
			return 0;
		}

		// Delete all entries older than the longest window.
		$max_window = max( array_column( $this->limits, 'window' ) );
		$threshold  = gmdate( 'Y-m-d H:i:s', time() - $max_window );

		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table}
				WHERE created_at < %s
				AND (expires_at IS NULL OR expires_at < %s)",
				$threshold,
				current_time( 'mysql', true )
			)
		);

		return $this->wpdb->rows_affected;
	}

	/**
	 * Get rate limit statistics.
	 *
	 * Uses fully parameterized queries to prevent SQL injection.
	 *
	 * @param string $limit_type The limit type (or empty for all).
	 * @return array Statistics.
	 */
	public function getStats( string $limit_type = '' ): array {
		if ( ! $this->tableExists() ) {
			return [
				'total_hits'         => 0,
				'unique_identifiers' => 0,
				'top_identifiers'    => [],
			];
		}

		// Use separate fully parameterized queries for each case.
		if ( $limit_type ) {
			// Query for specific limit type.
			$stats = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT
						SUM(request_count) as total_hits,
						COUNT(DISTINCT identifier_hash) as unique_identifiers
					FROM {$this->table}
					WHERE limit_type = %s
					AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
					$limit_type
				),
				ARRAY_A
			);

			$top_identifiers = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT identifier_hash, SUM(request_count) as hits
					FROM {$this->table}
					WHERE limit_type = %s
					AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
					GROUP BY identifier_hash
					ORDER BY hits DESC
					LIMIT 10",
					$limit_type
				),
				ARRAY_A
			);
		} else {
			// Query for all types except 'blocked'.
			$stats = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT
						SUM(request_count) as total_hits,
						COUNT(DISTINCT identifier_hash) as unique_identifiers
					FROM {$this->table}
					WHERE limit_type != %s
					AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
					'blocked'
				),
				ARRAY_A
			);

			$top_identifiers = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT identifier_hash, SUM(request_count) as hits
					FROM {$this->table}
					WHERE limit_type != %s
					AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
					GROUP BY identifier_hash
					ORDER BY hits DESC
					LIMIT 10",
					'blocked'
				),
				ARRAY_A
			);
		}

		return [
			'total_hits'         => (int) ( $stats['total_hits'] ?? 0 ),
			'unique_identifiers' => (int) ( $stats['unique_identifiers'] ?? 0 ),
			'top_identifiers'    => $top_identifiers ?: [],
		];
	}
}
