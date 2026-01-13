<?php
/**
 * Inactive Customer Identifier
 *
 * Identifies inactive customers for re-engagement campaigns.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\InactiveCustomerIdentifierInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class InactiveCustomerIdentifier
 *
 * Finds customers who haven't ordered recently.
 */
class InactiveCustomerIdentifier implements InactiveCustomerIdentifierInterface {

	/**
	 * Default inactivity threshold in days.
	 */
	protected const DEFAULT_THRESHOLD_DAYS = 60;

	/**
	 * Days since last re-engagement message.
	 */
	protected const RECENT_MESSAGE_DAYS = 7;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	protected DatabaseManager $dbManager;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface $settings  Settings service.
	 * @param DatabaseManager   $dbManager Database manager.
	 */
	public function __construct( SettingsInterface $settings, DatabaseManager $dbManager ) {
		global $wpdb;
		$this->wpdb      = $wpdb;
		$this->settings  = $settings;
		$this->dbManager = $dbManager;
	}

	/**
	 * Identify inactive customers.
	 *
	 * @return array Array of customer data.
	 */
	public function identify(): array {
		$threshold         = $this->getInactivityThreshold();
		$thresholdDate     = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $threshold * DAY_IN_SECONDS ) );
		$recentMessageDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( self::RECENT_MESSAGE_DAYS * DAY_IN_SECONDS ) );

		$profilesTable     = $this->dbManager->getTableName( 'customer_profiles' );
		$reengagementTable = $this->dbManager->getTableName( 'reengagement_log' );

		$query = "
			SELECT p.*,
				(SELECT MAX(post_date)
				 FROM {$this->wpdb->posts} posts
				 INNER JOIN {$this->wpdb->postmeta} meta ON posts.ID = meta.post_id
				 WHERE posts.post_type = 'shop_order'
				 AND meta.meta_key = '_customer_user'
				 AND meta.meta_value = p.wc_customer_id
				 AND posts.post_status IN ('wc-completed', 'wc-processing')
				) as last_order_date,
				(SELECT COUNT(*)
				 FROM {$this->wpdb->posts} posts
				 INNER JOIN {$this->wpdb->postmeta} meta ON posts.ID = meta.post_id
				 WHERE posts.post_type = 'shop_order'
				 AND meta.meta_key = '_customer_user'
				 AND meta.meta_value = p.wc_customer_id
				 AND posts.post_status IN ('wc-completed', 'wc-processing')
				) as total_orders
			FROM {$profilesTable} p
			WHERE p.wc_customer_id IS NOT NULL
			AND p.opt_in_marketing = 1
			HAVING total_orders > 0
			AND last_order_date IS NOT NULL
			AND last_order_date < %s
			AND (
				p.phone NOT IN (
					SELECT customer_phone
					FROM {$reengagementTable}
					WHERE sent_at > %s
				)
			)
		";

		$customers = $this->wpdb->get_results(
			$this->wpdb->prepare( $query, $thresholdDate, $recentMessageDate ),
			ARRAY_A
		);

		return $customers ?: [];
	}

	/**
	 * Get the inactivity threshold in days.
	 *
	 * @return int Number of days.
	 */
	public function getInactivityThreshold(): int {
		return (int) $this->settings->get( 'reengagement.inactivity_threshold', self::DEFAULT_THRESHOLD_DAYS );
	}

	/**
	 * Check if a specific customer is inactive.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if inactive.
	 */
	public function isInactive( string $customerPhone ): bool {
		$profilesTable = $this->dbManager->getTableName( 'customer_profiles' );
		$threshold     = $this->getInactivityThreshold();
		$thresholdDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $threshold * DAY_IN_SECONDS ) );

		$wcCustomerId = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT wc_customer_id FROM {$profilesTable} WHERE phone = %s",
				$customerPhone
			)
		);

		if ( ! $wcCustomerId ) {
			return false;
		}

		$lastOrderDate = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT MAX(post_date)
				FROM {$this->wpdb->posts} posts
				INNER JOIN {$this->wpdb->postmeta} meta ON posts.ID = meta.post_id
				WHERE posts.post_type = 'shop_order'
				AND meta.meta_key = '_customer_user'
				AND meta.meta_value = %d
				AND posts.post_status IN ('wc-completed', 'wc-processing')",
				$wcCustomerId
			)
		);

		if ( ! $lastOrderDate ) {
			return false;
		}

		return $lastOrderDate < $thresholdDate;
	}

	/**
	 * Get customer purchase history summary.
	 *
	 * @param int $wcCustomerId WooCommerce customer ID.
	 * @return array Summary with last_order_date, total_orders.
	 */
	public function getCustomerPurchaseSummary( int $wcCustomerId ): array {
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT
					MAX(post_date) as last_order_date,
					COUNT(*) as total_orders
				FROM {$this->wpdb->posts} posts
				INNER JOIN {$this->wpdb->postmeta} meta ON posts.ID = meta.post_id
				WHERE posts.post_type = 'shop_order'
				AND meta.meta_key = '_customer_user'
				AND meta.meta_value = %d
				AND posts.post_status IN ('wc-completed', 'wc-processing')",
				$wcCustomerId
			),
			ARRAY_A
		);

		return $result ?: [
			'last_order_date' => null,
			'total_orders'    => 0,
		];
	}
}
