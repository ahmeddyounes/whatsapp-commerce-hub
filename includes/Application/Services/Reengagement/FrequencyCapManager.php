<?php
/**
 * Frequency Cap Manager
 *
 * Manages re-engagement message frequency limits.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\FrequencyCapManagerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FrequencyCapManager
 *
 * Enforces messaging frequency limits per customer.
 */
class FrequencyCapManager implements FrequencyCapManagerInterface {

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Database manager.
	 *
	 * @var \WCH_Database_Manager
	 */
	protected \WCH_Database_Manager $dbManager;

	/**
	 * Constructor.
	 *
	 * @param \WCH_Database_Manager $dbManager Database manager.
	 */
	public function __construct( \WCH_Database_Manager $dbManager ) {
		global $wpdb;
		$this->wpdb      = $wpdb;
		$this->dbManager = $dbManager;
	}

	/**
	 * Check if customer can receive a message.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if can send.
	 */
	public function canSend( string $customerPhone ): bool {
		// Check weekly cap (max 1 per 7 days).
		$weeklyCount = $this->getMessageCount( $customerPhone, 7 );
		if ( $weeklyCount >= self::DEFAULT_WEEKLY_CAP ) {
			return false;
		}

		// Check monthly cap (max 4 per 30 days).
		$monthlyCount = $this->getMessageCount( $customerPhone, 30 );
		if ( $monthlyCount >= self::DEFAULT_MONTHLY_CAP ) {
			return false;
		}

		return true;
	}

	/**
	 * Log a sent re-engagement message.
	 *
	 * @param string      $customerPhone Customer phone number.
	 * @param string      $campaignType Campaign type.
	 * @param string|null $messageId WhatsApp message ID.
	 * @return bool True if logged.
	 */
	public function logMessage( string $customerPhone, string $campaignType, ?string $messageId = null ): bool {
		$tableName = $this->dbManager->get_table_name( 'reengagement_log' );

		$result = $this->wpdb->insert(
			$tableName,
			array(
				'customer_phone' => $customerPhone,
				'campaign_type'  => $campaignType,
				'message_id'     => $messageId,
				'status'         => 'sent',
				'sent_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Get message count for a period.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param int    $days Number of days to look back.
	 * @return int Message count.
	 */
	public function getMessageCount( string $customerPhone, int $days ): int {
		$tableName = $this->dbManager->get_table_name( 'reengagement_log' );
		$sinceDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$tableName}
				WHERE customer_phone = %s
				AND sent_at > %s",
				$customerPhone,
				$sinceDate
			)
		);

		return (int) $count;
	}

	/**
	 * Get the last message sent to a customer.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return array|null Message data or null.
	 */
	public function getLastMessage( string $customerPhone ): ?array {
		$tableName = $this->dbManager->get_table_name( 'reengagement_log' );

		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$tableName}
				WHERE customer_phone = %s
				ORDER BY sent_at DESC
				LIMIT 1",
				$customerPhone
			),
			ARRAY_A
		);

		return $result ?: null;
	}
}
