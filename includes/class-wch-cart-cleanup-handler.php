<?php
/**
 * Cart Cleanup Handler Class
 *
 * Handles cleanup of expired carts (older than 72 hours).
 * Runs hourly via scheduled action.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Cart_Cleanup_Handler
 */
class WCH_Cart_Cleanup_Handler {
	/**
	 * Cart expiry time in hours.
	 *
	 * @var int
	 */
	const CART_EXPIRY_HOURS = 72;

	/**
	 * Process cart cleanup job.
	 *
	 * @param array $args Job arguments (not used).
	 */
	public static function process( $args = array() ) {
		global $wpdb;

		WCH_Logger::log(
			'info',
			'Starting cart cleanup job',
			'queue',
			array()
		);

		$expiry_timestamp = current_time( 'timestamp' ) - ( self::CART_EXPIRY_HOURS * HOUR_IN_SECONDS );
		$expiry_date      = gmdate( 'Y-m-d H:i:s', $expiry_timestamp );

		// Get the table name from database manager.
		$table_name = $wpdb->prefix . 'wch_carts';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists !== $table_name ) {
			WCH_Logger::log(
				'warning',
				'Cart table does not exist, skipping cleanup',
				'queue',
				array()
			);
			return;
		}

		// Count expired carts.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE updated_at < %s AND status = %s",
				$expiry_date,
				'active'
			)
		);

		if ( ! $count ) {
			WCH_Logger::log(
				'info',
				'No expired carts to clean up',
				'queue',
				array()
			);
			return;
		}

		// Delete expired carts.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE updated_at < %s AND status = %s",
				$expiry_date,
				'active'
			)
		);

		if ( false === $deleted ) {
			WCH_Logger::log(
				'error',
				'Failed to delete expired carts',
				'queue',
				array(
					'error' => $wpdb->last_error,
				)
			);
			return;
		}

		WCH_Logger::log(
			'info',
			'Cart cleanup completed',
			'queue',
			array(
				'deleted_count' => $deleted,
				'expiry_date'   => $expiry_date,
			)
		);

		// Store cleanup result.
		self::store_cleanup_result( $deleted, $expiry_date );
	}

	/**
	 * Store cleanup result in transient.
	 *
	 * @param int    $deleted_count Number of carts deleted.
	 * @param string $expiry_date   Expiry cutoff date.
	 */
	private static function store_cleanup_result( $deleted_count, $expiry_date ) {
		$result = array(
			'deleted_count' => $deleted_count,
			'expiry_date'   => $expiry_date,
			'timestamp'     => current_time( 'mysql' ),
		);

		set_transient( 'wch_cart_cleanup_last_result', $result, DAY_IN_SECONDS );
	}

	/**
	 * Get last cleanup result.
	 *
	 * @return array|null Last cleanup result or null if not found.
	 */
	public static function get_last_cleanup_result() {
		return get_transient( 'wch_cart_cleanup_last_result' );
	}

	/**
	 * Get count of active carts.
	 *
	 * @return int Number of active carts.
	 */
	public static function get_active_carts_count() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_carts';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists !== $table_name ) {
			return 0;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
				'active'
			)
		);

		return (int) $count;
	}

	/**
	 * Get count of expired carts (not yet cleaned).
	 *
	 * @return int Number of expired carts.
	 */
	public static function get_expired_carts_count() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_carts';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists !== $table_name ) {
			return 0;
		}

		$expiry_timestamp = current_time( 'timestamp' ) - ( self::CART_EXPIRY_HOURS * HOUR_IN_SECONDS );
		$expiry_date      = gmdate( 'Y-m-d H:i:s', $expiry_timestamp );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE updated_at < %s AND status = %s",
				$expiry_date,
				'active'
			)
		);

		return (int) $count;
	}

	/**
	 * Manually trigger cleanup (for testing or admin action).
	 *
	 * @return array Cleanup result.
	 */
	public static function trigger_cleanup() {
		self::process( array() );

		return self::get_last_cleanup_result();
	}
}
