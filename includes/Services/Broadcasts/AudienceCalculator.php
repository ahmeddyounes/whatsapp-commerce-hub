<?php
/**
 * Audience Calculator Service
 *
 * Handles audience count calculations for broadcast campaigns.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Services\Broadcasts;

use WhatsAppCommerceHub\Contracts\Services\Broadcasts\AudienceCalculatorInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AudienceCalculator
 *
 * Calculates broadcast audience based on criteria.
 */
class AudienceCalculator implements AudienceCalculatorInterface {

	/**
	 * Maximum recipients safety limit.
	 */
	protected const MAX_RECIPIENTS = 100000;

	/**
	 * Batch size for recipient fetching.
	 */
	protected const BATCH_SIZE = 1000;

	/**
	 * {@inheritdoc}
	 */
	public function calculateCount( array $criteria ): int {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_customer_profiles';

		// Build parameterized query parts.
		$whereClauses = array( 'opt_in_marketing = %d' );
		$whereValues  = array( 1 );

		// Apply audience filters.
		$this->applyFilters( $criteria, $whereClauses, $whereValues );

		$whereSql = implode( ' AND ', $whereClauses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from wpdb->prefix.
		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT phone) FROM {$tableName} WHERE {$whereSql}",
			$whereValues
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
		$count = (int) $wpdb->get_var( $query );

		// Apply exclusions.
		$count = $this->applyExclusions( $criteria, $count );

		return max( 0, $count );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getRecipients( array $criteria, int $limit = 0 ): array {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_customer_profiles';

		// Build parameterized query parts.
		$whereClauses = array( 'opt_in_marketing = %d' );
		$whereValues  = array( 1 );

		// Apply audience filters.
		$this->applyFilters( $criteria, $whereClauses, $whereValues );

		$whereSql = implode( ' AND ', $whereClauses );

		// Use pagination to fetch recipients in batches.
		$allRecipients = array();
		$offset        = 0;
		$perPage       = self::BATCH_SIZE;
		$maxRecipients = $limit > 0 ? min( $limit, self::MAX_RECIPIENTS ) : self::MAX_RECIPIENTS;

		do {
			$batchValues = array_merge( $whereValues, array( $perPage, $offset ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from wpdb->prefix.
			$batch = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT phone FROM {$tableName} WHERE {$whereSql} ORDER BY id ASC LIMIT %d OFFSET %d",
					$batchValues
				)
			);

			if ( empty( $batch ) ) {
				break;
			}

			$allRecipients = array_merge( $allRecipients, $batch );
			$offset       += $perPage;

			// Safety limit to prevent memory exhaustion.
			if ( count( $allRecipients ) >= $maxRecipients ) {
				$this->logWarning(
					'Broadcast recipients hit safety limit',
					array(
						'fetched' => count( $allRecipients ),
						'limit'   => $maxRecipients,
					)
				);
				break;
			}
		} while ( count( $batch ) === $perPage );

		// Apply exclusions if needed.
		$allRecipients = $this->applyRecipientExclusions( $criteria, $allRecipients );

		return $allRecipients;
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateCriteria( array $criteria ): array {
		$errors = array();

		// Check if at least one audience selection is made.
		$hasSelection = ! empty( $criteria['audience_all'] )
			|| ! empty( $criteria['audience_recent_orders'] )
			|| ! empty( $criteria['audience_category'] )
			|| ! empty( $criteria['audience_cart_abandoners'] );

		if ( ! $hasSelection ) {
			$errors[] = __( 'Please select at least one audience criteria', 'whatsapp-commerce-hub' );
		}

		// Validate days ranges.
		if ( ! empty( $criteria['audience_recent_orders'] ) ) {
			$days = absint( $criteria['recent_orders_days'] ?? 0 );
			if ( $days < 1 || $days > 365 ) {
				$errors[] = __( 'Recent orders days must be between 1 and 365', 'whatsapp-commerce-hub' );
			}
		}

		if ( ! empty( $criteria['exclude_recent_broadcast'] ) ) {
			$days = absint( $criteria['exclude_broadcast_days'] ?? 0 );
			if ( $days < 1 || $days > 30 ) {
				$errors[] = __( 'Exclude broadcast days must be between 1 and 30', 'whatsapp-commerce-hub' );
			}
		}

		// Validate category selection.
		if ( ! empty( $criteria['audience_category'] ) && empty( $criteria['category_id'] ) ) {
			$errors[] = __( 'Please select a category', 'whatsapp-commerce-hub' );
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAvailableSegments(): array {
		$segments = array(
			array(
				'id'          => 'all_opted_in',
				'name'        => __( 'All opted-in customers', 'whatsapp-commerce-hub' ),
				'description' => __( 'All customers who have opted in to marketing messages', 'whatsapp-commerce-hub' ),
			),
			array(
				'id'          => 'recent_orders',
				'name'        => __( 'Recent customers', 'whatsapp-commerce-hub' ),
				'description' => __( 'Customers who placed orders within a specified time period', 'whatsapp-commerce-hub' ),
				'has_params'  => true,
			),
			array(
				'id'          => 'category_buyers',
				'name'        => __( 'Category buyers', 'whatsapp-commerce-hub' ),
				'description' => __( 'Customers who purchased from a specific category', 'whatsapp-commerce-hub' ),
				'has_params'  => true,
			),
			array(
				'id'          => 'cart_abandoners',
				'name'        => __( 'Cart abandoners', 'whatsapp-commerce-hub' ),
				'description' => __( 'Customers who abandoned their cart in the last 7 days', 'whatsapp-commerce-hub' ),
			),
		);

		return $segments;
	}

	/**
	 * Apply audience filters to query.
	 *
	 * @param array $criteria      Audience criteria.
	 * @param array &$whereClauses WHERE clause parts.
	 * @param array &$whereValues  Prepared statement values.
	 * @return void
	 */
	protected function applyFilters( array $criteria, array &$whereClauses, array &$whereValues ): void {
		global $wpdb;

		// Recent orders filter.
		if ( ! empty( $criteria['audience_recent_orders'] ) && ! empty( $criteria['recent_orders_days'] ) ) {
			$days           = absint( $criteria['recent_orders_days'] );
			$dateThreshold  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

			$whereClauses[] = 'last_order_date >= %s';
			$whereValues[]  = $dateThreshold;
		}

		// Cart abandoners filter.
		if ( ! empty( $criteria['audience_cart_abandoners'] ) ) {
			$cartsTable     = $wpdb->prefix . 'wch_carts';
			$whereClauses[] = "phone IN (SELECT customer_phone FROM {$cartsTable} WHERE status = %s)";
			$whereValues[]  = 'abandoned';
		}

		// Category filter would require joining with order items.
		// For now, this is a placeholder for future implementation.
	}

	/**
	 * Apply exclusions to count.
	 *
	 * @param array $criteria Audience criteria.
	 * @param int   $count    Current count.
	 * @return int Adjusted count.
	 */
	protected function applyExclusions( array $criteria, int $count ): int {
		global $wpdb;

		if ( empty( $criteria['exclude_recent_broadcast'] ) || empty( $criteria['exclude_broadcast_days'] ) ) {
			return $count;
		}

		$days             = absint( $criteria['exclude_broadcast_days'] );
		$broadcastCutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$broadcastsTable  = $wpdb->prefix . 'wch_broadcast_recipients';
		$profilesTable    = $wpdb->prefix . 'wch_customer_profiles';

		// Check if tracking table exists.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tableExists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $broadcastsTable )
		);

		if ( ! $tableExists ) {
			return $count;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$excludedCount = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT cp.phone) FROM {$profilesTable} cp
				INNER JOIN {$broadcastsTable} br ON cp.phone = br.phone
				WHERE cp.opt_in_marketing = %d AND br.sent_at >= %s",
				1,
				$broadcastCutoff
			)
		);

		return max( 0, $count - $excludedCount );
	}

	/**
	 * Apply exclusions to recipient list.
	 *
	 * @param array $criteria   Audience criteria.
	 * @param array $recipients Current recipients.
	 * @return array Filtered recipients.
	 */
	protected function applyRecipientExclusions( array $criteria, array $recipients ): array {
		global $wpdb;

		if ( empty( $criteria['exclude_recent_broadcast'] ) || empty( $criteria['exclude_broadcast_days'] ) ) {
			return $recipients;
		}

		$days             = absint( $criteria['exclude_broadcast_days'] );
		$broadcastCutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$broadcastsTable  = $wpdb->prefix . 'wch_broadcast_recipients';

		// Check if tracking table exists.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tableExists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $broadcastsTable )
		);

		if ( ! $tableExists ) {
			return $recipients;
		}

		// Get recently contacted phones.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$excludedPhones = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT phone FROM {$broadcastsTable} WHERE sent_at >= %s",
				$broadcastCutoff
			)
		);

		if ( empty( $excludedPhones ) ) {
			return $recipients;
		}

		// Filter out excluded phones.
		return array_diff( $recipients, $excludedPhones );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function logWarning( string $message, array $context = array() ): void {
		$context['category'] = 'broadcasts';

		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::warning( $message, $context );
		}
	}
}
