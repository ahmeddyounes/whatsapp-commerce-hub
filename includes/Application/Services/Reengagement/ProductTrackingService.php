<?php
/**
 * Product Tracking Service
 *
 * Tracks product views for back-in-stock and price drop notifications.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\ProductTrackingServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\FrequencyCapManagerInterface;
use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductTrackingService
 *
 * Tracks product views and monitors stock/price changes.
 */
class ProductTrackingService implements ProductTrackingServiceInterface {

	/**
	 * View tracking window in days.
	 */
	protected const VIEW_WINDOW_DAYS = 30;

	/**
	 * Minimum time between tracking same product (seconds).
	 */
	protected const MIN_TRACK_INTERVAL = HOUR_IN_SECONDS;

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
	 * Frequency cap manager.
	 *
	 * @var FrequencyCapManagerInterface|null
	 */
	protected ?FrequencyCapManagerInterface $frequencyCap;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager                   $dbManager    Database manager.
	 * @param FrequencyCapManagerInterface|null $frequencyCap Frequency cap manager.
	 */
	public function __construct(
		DatabaseManager $dbManager,
		?FrequencyCapManagerInterface $frequencyCap = null
	) {
		global $wpdb;
		$this->wpdb         = $wpdb;
		$this->dbManager    = $dbManager;
		$this->frequencyCap = $frequencyCap;
	}

	/**
	 * Set the frequency cap manager (for deferred injection).
	 *
	 * @param FrequencyCapManagerInterface $frequencyCap Frequency cap manager.
	 * @return void
	 */
	public function setFrequencyCapManager( FrequencyCapManagerInterface $frequencyCap ): void {
		$this->frequencyCap = $frequencyCap;
	}

	/**
	 * Track a product view.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param int    $productId Product ID.
	 * @return bool True if tracked.
	 */
	public function trackView( string $customerPhone, int $productId ): bool {
		$product = wc_get_product( $productId );
		if ( ! $product ) {
			return false;
		}

		$tableName = $this->dbManager->getTableName( 'product_views' );

		// Check if already tracked recently.
		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$tableName}
				WHERE customer_phone = %s
				AND product_id = %d
				AND viewed_at > %s",
				$customerPhone,
				$productId,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::MIN_TRACK_INTERVAL )
			)
		);

		if ( $existing ) {
			return false;
		}

		$result = $this->wpdb->insert(
			$tableName,
			[
				'customer_phone' => $customerPhone,
				'product_id'     => $productId,
				'price_at_view'  => $product->get_price(),
				'in_stock'       => $product->is_in_stock() ? 1 : 0,
				'viewed_at'      => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%f', '%d', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Get products that are back in stock for a customer.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return array Array of product data.
	 */
	public function getBackInStockProducts( string $customerPhone ): array {
		$tableName = $this->dbManager->getTableName( 'product_views' );
		$sinceDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( self::VIEW_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DISTINCT product_id
				FROM {$tableName}
				WHERE customer_phone = %s
				AND in_stock = 0
				AND viewed_at > %s",
				$customerPhone,
				$sinceDate
			),
			ARRAY_A
		);

		$products = [];

		foreach ( $results as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( $product && $product->is_in_stock() ) {
				$products[] = [
					'id'    => $product->get_id(),
					'name'  => $product->get_name(),
					'price' => $product->get_price(),
					'url'   => $product->get_permalink(),
				];
			}
		}

		return $products;
	}

	/**
	 * Get products with price drops for a customer.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @param float  $minDropPercent Minimum drop percentage.
	 * @return array Array of product data with price info.
	 */
	public function getPriceDropProducts( string $customerPhone, float $minDropPercent = 10.0 ): array {
		$tableName = $this->dbManager->getTableName( 'product_views' );
		$sinceDate = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( self::VIEW_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT product_id, price_at_view
				FROM {$tableName}
				WHERE customer_phone = %s
				AND viewed_at > %s",
				$customerPhone,
				$sinceDate
			),
			ARRAY_A
		);

		$products = [];

		foreach ( $results as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$currentPrice = floatval( $product->get_price() );
			$oldPrice     = floatval( $row['price_at_view'] );

			if ( $oldPrice > 0 && $currentPrice > 0 ) {
				$dropPercent = ( ( $oldPrice - $currentPrice ) / $oldPrice ) * 100;

				if ( $dropPercent >= $minDropPercent ) {
					$products[] = [
						'id'        => $product->get_id(),
						'name'      => $product->get_name(),
						'old_price' => $oldPrice,
						'price'     => $currentPrice,
						'drop'      => round( $dropPercent, 0 ),
						'url'       => $product->get_permalink(),
					];
				}
			}
		}

		return $products;
	}

	/**
	 * Check if customer has back-in-stock items.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if has items.
	 */
	public function hasBackInStockItems( string $customerPhone ): bool {
		$products = $this->getBackInStockProducts( $customerPhone );
		return ! empty( $products );
	}

	/**
	 * Check if customer has price drop items.
	 *
	 * @param string $customerPhone Customer phone number.
	 * @return bool True if has items.
	 */
	public function hasPriceDrops( string $customerPhone ): bool {
		$products = $this->getPriceDropProducts( $customerPhone );
		return ! empty( $products );
	}

	/**
	 * Process back-in-stock notifications.
	 *
	 * @return int Number of notifications queued.
	 */
	public function processBackInStockNotifications(): int {
		$tableName = $this->dbManager->getTableName( 'product_views' );
		$queued    = 0;

		// Get distinct product IDs that were out of stock.
		$products = $this->wpdb->get_results(
			"SELECT DISTINCT product_id
			FROM {$tableName}
			WHERE in_stock = 0",
			ARRAY_A
		);

		foreach ( $products as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( $product && $product->is_in_stock() ) {
				$queued += $this->notifyBackInStock( (int) $row['product_id'] );
			}
		}

		return $queued;
	}

	/**
	 * Notify customers interested in a product that's back in stock.
	 *
	 * @param int $productId Product ID.
	 * @return int Number of notifications queued.
	 */
	protected function notifyBackInStock( int $productId ): int {
		$tableName = $this->dbManager->getTableName( 'product_views' );
		$queued    = 0;

		$customers = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DISTINCT customer_phone
				FROM {$tableName}
				WHERE product_id = %d
				AND in_stock = 0",
				$productId
			),
			ARRAY_A
		);

		foreach ( $customers as $row ) {
			// Check frequency cap if available.
			if ( $this->frequencyCap && ! $this->frequencyCap->canSend( $row['customer_phone'] ) ) {
				continue;
			}

			// Queue back-in-stock notification.
			wch( JobDispatcher::class )->dispatch(
				'wch_send_reengagement_message',
				[
					'customer_phone' => $row['customer_phone'],
					'campaign_type'  => 'back_in_stock',
				],
				0
			);

			++$queued;
		}

		// Update the view records.
		$this->updateStockStatus( $productId, true );

		return $queued;
	}

	/**
	 * Update stock status for a product.
	 *
	 * @param int  $productId Product ID.
	 * @param bool $inStock Whether in stock.
	 * @return bool True if updated.
	 */
	public function updateStockStatus( int $productId, bool $inStock ): bool {
		$tableName = $this->dbManager->getTableName( 'product_views' );

		$result = $this->wpdb->update(
			$tableName,
			[ 'in_stock' => $inStock ? 1 : 0 ],
			[ 'product_id' => $productId ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $result;
	}
}
