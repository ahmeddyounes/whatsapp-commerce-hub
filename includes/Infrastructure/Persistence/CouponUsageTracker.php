<?php
/**
 * Coupon Usage Tracker
 *
 * Infrastructure service for tracking coupon usage by phone number.
 * Moved from Domain layer to maintain architectural purity.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Persistence;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CouponUsageTracker
 *
 * Tracks coupon usage by phone number to prevent abuse.
 */
class CouponUsageTracker {

	/**
	 * Get coupon usage count for a specific phone number.
	 *
	 * SECURITY: This is the primary defense against coupon usage limit bypass.
	 *
	 * @param int    $coupon_id Coupon ID.
	 * @param string $phone     Phone number.
	 * @return int Usage count.
	 */
	public function getUsageCount( int $coupon_id, string $phone ): int {
		global $wpdb;

		$table            = $wpdb->prefix . 'wch_coupon_phone_usage';
		$normalized_phone = preg_replace( '/[^0-9+]/', '', $phone );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND phone = %s",
				$coupon_id,
				$normalized_phone
			)
		);

		return (int) $count;
	}

	/**
	 * Record coupon usage for a phone number.
	 *
	 * SECURITY: This should be called when an order containing a coupon is completed.
	 * Uses INSERT IGNORE to handle potential duplicates (idempotent).
	 *
	 * @param int    $coupon_id Coupon ID.
	 * @param string $phone     Phone number.
	 * @param int    $order_id  Order ID.
	 * @return bool True if recorded, false on failure.
	 */
	public function recordUsage( int $coupon_id, string $phone, int $order_id ): bool {
		global $wpdb;

		$table            = $wpdb->prefix . 'wch_coupon_phone_usage';
		$normalized_phone = preg_replace( '/[^0-9+]/', '', $phone );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (coupon_id, phone, order_id, used_at) VALUES (%d, %s, %d, %s)",
				$coupon_id,
				$normalized_phone,
				$order_id,
				current_time( 'mysql' )
			)
		);

		if ( false === $result ) {
			do_action(
				'wch_log_error',
				'Failed to record coupon phone usage',
				[
					'coupon_id' => $coupon_id,
					'phone'     => $normalized_phone,
					'order_id'  => $order_id,
					'error'     => $wpdb->last_error,
				]
			);
			return false;
		}

		return true;
	}

	/**
	 * Find WooCommerce customer by phone number.
	 *
	 * Searches both billing phone and custom phone meta fields.
	 *
	 * @param string $phone Phone number to search.
	 * @return \WC_Customer|null Customer object or null if not found.
	 */
	public function findCustomerByPhone( string $phone ): ?\WC_Customer {
		global $wpdb;

		// Normalize phone number for search.
		$normalized_phone = preg_replace( '/[^0-9+]/', '', $phone );

		// Try to find customer profile with linked WC customer ID.
		$table = $wpdb->prefix . 'wch_customer_profiles';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wc_customer_id FROM {$table} WHERE phone = %s AND wc_customer_id IS NOT NULL LIMIT 1",
				$normalized_phone
			)
		);

		if ( $profile && $profile->wc_customer_id ) {
			$customer = new \WC_Customer( (int) $profile->wc_customer_id );
			if ( $customer->get_id() ) {
				return $customer;
			}
		}

		// Fallback: Search WooCommerce customers by billing phone.
		$customer_query = new \WC_Customer_Query(
			[
				'meta_key'   => 'billing_phone',
				'meta_value' => $normalized_phone,
				'number'     => 1,
			]
		);

		$customers = $customer_query->get_customers();
		if ( ! empty( $customers ) ) {
			return $customers[0];
		}

		return null;
	}
}
