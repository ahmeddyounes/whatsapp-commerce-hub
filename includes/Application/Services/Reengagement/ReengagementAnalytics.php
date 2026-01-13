<?php
/**
 * Reengagement Analytics
 *
 * Tracks and reports re-engagement campaign analytics.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementAnalyticsInterface;
use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReengagementAnalytics
 *
 * Tracks campaign performance and conversions.
 */
class ReengagementAnalytics implements ReengagementAnalyticsInterface {

	/**
	 * Attribution window in days.
	 */
	protected const ATTRIBUTION_WINDOW_DAYS = 30;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	protected DatabaseManager $dbManager;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $dbManager Database manager.
	 */
	public function __construct( DatabaseManager $dbManager ) {
		global $wpdb;
		$this->wpdb      = $wpdb;
		$this->dbManager = $dbManager;
	}

	/**
	 * Get analytics by campaign type.
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Analytics data by campaign type.
	 */
	public function getAnalytics( int $days = 30 ): array {
		$tableName = $this->dbManager->getTableName( 'reengagement_log' );
		$sinceDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT campaign_type,
					COUNT(*) as sent,
					SUM(CASE WHEN status = 'delivered' OR status = 'read' THEN 1 ELSE 0 END) as delivered,
					SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as opened,
					SUM(converted) as converted
				FROM {$tableName}
				WHERE sent_at >= %s
				GROUP BY campaign_type",
				$sinceDate
			),
			ARRAY_A
		);

		$analytics = [];

		foreach ( $results as $row ) {
			$sent                               = intval( $row['sent'] );
			$analytics[ $row['campaign_type'] ] = [
				'sent'            => $sent,
				'delivered'       => intval( $row['delivered'] ),
				'opened'          => intval( $row['opened'] ),
				'converted'       => intval( $row['converted'] ),
				'conversion_rate' => $sent > 0 ? round( ( intval( $row['converted'] ) / $sent ) * 100, 2 ) : 0,
			];
		}

		return $analytics;
	}

	/**
	 * Track a campaign conversion.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param int    $orderId Order ID.
	 * @return bool True if tracked.
	 */
	public function trackConversion( string $customerPhone, int $orderId ): bool {
		$tableName         = $this->dbManager->getTableName( 'reengagement_log' );
		$attributionWindow = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( self::ATTRIBUTION_WINDOW_DAYS * DAY_IN_SECONDS ) );

		// Find the most recent re-engagement message sent to this customer.
		$logId = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$tableName}
				WHERE customer_phone = %s
				AND sent_at > %s
				ORDER BY sent_at DESC
				LIMIT 1",
				$customerPhone,
				$attributionWindow
			)
		);

		if ( ! $logId ) {
			return false;
		}

		$result = $this->wpdb->update(
			$tableName,
			[
				'converted'    => 1,
				'order_id'     => $orderId,
				'converted_at' => current_time( 'mysql' ),
			],
			[ 'id' => $logId ],
			[ '%d', '%d', '%s' ],
			[ '%d' ]
		);

		if ( false !== $result ) {
			$logger = null;
			try {
				$logger = wch( LoggerInterface::class );
			} catch ( \Throwable $loggerError ) {
				$logger = null;
			}

			if ( $logger ) {
				$logger->info(
					'Re-engagement conversion tracked',
					'reengagement',
					[
						'phone'    => $customerPhone,
						'order_id' => $orderId,
						'log_id'   => $logId,
					]
				);
			}
			return true;
		}

		return false;
	}

	/**
	 * Update message delivery status.
	 *
	 * @param string $messageId WhatsApp message ID.
	 * @param string $status New status (delivered, read).
	 * @return bool True if updated.
	 */
	public function updateMessageStatus( string $messageId, string $status ): bool {
		$tableName = $this->dbManager->getTableName( 'reengagement_log' );

		$result = $this->wpdb->update(
			$tableName,
			[ 'status' => $status ],
			[ 'message_id' => $messageId ],
			[ '%s' ],
			[ '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Get conversion rate for a campaign type.
	 *
	 * @param string $campaignType Campaign type.
	 * @param int    $days Number of days.
	 * @return float Conversion rate percentage.
	 */
	public function getConversionRate( string $campaignType, int $days = 30 ): float {
		$tableName = $this->dbManager->getTableName( 'reengagement_log' );
		$sinceDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT COUNT(*) as sent, SUM(converted) as converted
				FROM {$tableName}
				WHERE campaign_type = %s
				AND sent_at >= %s",
				$campaignType,
				$sinceDate
			),
			ARRAY_A
		);

		if ( ! $result || ! $result['sent'] ) {
			return 0.0;
		}

		return round( ( floatval( $result['converted'] ) / floatval( $result['sent'] ) ) * 100, 2 );
	}

	/**
	 * Get total revenue attributed to re-engagement.
	 *
	 * @param int $days Number of days.
	 * @return float Total revenue.
	 */
	public function getAttributedRevenue( int $days = 30 ): float {
		$tableName = $this->dbManager->getTableName( 'reengagement_log' );
		$sinceDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$orderIds = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT order_id FROM {$tableName}
				WHERE converted = 1
				AND order_id IS NOT NULL
				AND sent_at >= %s",
				$sinceDate
			)
		);

		$totalRevenue = 0.0;

		foreach ( $orderIds as $orderId ) {
			$order = wc_get_order( $orderId );
			if ( $order ) {
				$totalRevenue += floatval( $order->get_total() );
			}
		}

		return $totalRevenue;
	}
}
