<?php
/**
 * Analytics Data Service
 *
 * Handles all analytics data aggregation and queries.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics Data Class
 *
 * Provides analytics data for WhatsApp commerce operations.
 */
class AnalyticsData {

	/**
	 * Cache expiry time (15 minutes)
	 */
	private const CACHE_EXPIRY = 900;

	/**
	 * Get analytics summary data
	 *
	 * @param string $period Period: 'today', 'week', 'month', 'year'
	 * @return array<string, mixed> Summary data
	 */
	public function getSummary( string $period = 'today' ): array {
		$cacheKey = "wch_analytics_summary_{$period}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$dateRange = $this->getDateRange( $period );

		$data = [
			'total_orders'         => $this->getWhatsAppOrdersCount( $dateRange['start'], $dateRange['end'] ),
			'total_revenue'        => $this->getWhatsAppRevenue( $dateRange['start'], $dateRange['end'] ),
			'active_conversations' => $this->getActiveConversationsCount(),
			'conversion_rate'      => $this->getConversionRate( $dateRange['start'], $dateRange['end'] ),
			'period'               => $period,
		];

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get orders over time
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, int> Orders data indexed by date
	 */
	public function getOrdersOverTime( int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_orders_time_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$endDate   = gmdate( 'Y-m-d' );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(post_date) as order_date, COUNT(*) as count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
                AND pm.meta_key = '_wch_whatsapp_order'
                AND pm.meta_value = '1'
                AND DATE(p.post_date) BETWEEN %s AND %s
                GROUP BY DATE(p.post_date)
                ORDER BY order_date ASC",
				$startDate,
				$endDate
			)
		);

		// Initialize all dates with 0
		$data = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date          = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$data[ $date ] = 0;
		}

		// Fill in actual counts
		foreach ( $results as $row ) {
			$data[ $row->order_date ] = (int) $row->count;
		}

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get revenue over time
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, float> Revenue data indexed by date
	 */
	public function getRevenueOverTime( int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_revenue_time_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(p.post_date) as order_date, SUM(pm_total.meta_value) as total
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_wch ON p.ID = pm_wch.post_id
                INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND pm_total.meta_key = '_order_total'
                AND DATE(p.post_date) >= %s
                GROUP BY DATE(p.post_date)
                ORDER BY order_date ASC",
				$startDate
			)
		);

		$data = [];
		foreach ( $results as $row ) {
			$data[ $row->order_date ] = (float) $row->total;
		}

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get revenue by day (alias for revenue over time).
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, float> Revenue data indexed by date
	 */
	public function getRevenueByDay( int $days = 30 ): array {
		return $this->getRevenueOverTime( $days );
	}

	/**
	 * Get top products
	 *
	 * @param int $limit Number of products to return
	 * @param int $days Number of days to look back
	 * @return array<int, array<string, mixed>> Top products data
	 */
	public function getTopProducts( int $limit = 10, int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_top_products_{$limit}_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.order_item_name as product_name,
                    SUM(oim_qty.meta_value) as quantity,
                    SUM(oim_total.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty 
                    ON oi.order_item_id = oim_qty.order_item_id
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total 
                    ON oi.order_item_id = oim_total.order_item_id
                INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE oi.order_item_type = 'line_item'
                AND oim_qty.meta_key = '_qty'
                AND oim_total.meta_key = '_line_total'
                AND pm.meta_key = '_wch_whatsapp_order'
                AND pm.meta_value = '1'
                AND DATE(p.post_date) >= %s
                GROUP BY product_name
                ORDER BY quantity DESC
                LIMIT %d",
				$startDate,
				$limit
			)
		);

		$data = [];
		foreach ( $results as $row ) {
			$data[] = [
				'product_name' => $row->product_name,
				'quantity'     => (int) $row->quantity,
				'revenue'      => (float) $row->total,
			];
		}

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get conversation metrics
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, mixed> Conversation metrics
	 */
	public function getConversationMetrics( int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_conversations_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$tableName = $wpdb->prefix . 'wch_conversations';

		$metrics = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                    COUNT(DISTINCT id) as total_conversations,
                    COUNT(DISTINCT customer_phone) as unique_customers,
                    AVG(message_count) as avg_messages_per_conversation,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count
                FROM {$tableName}
                WHERE created_at >= %s",
				$startDate
			),
			ARRAY_A
		);

		$data = [
			'total_conversations'  => (int) ( $metrics['total_conversations'] ?? 0 ),
			'unique_customers'     => (int) ( $metrics['unique_customers'] ?? 0 ),
			'avg_messages'         => round( (float) ( $metrics['avg_messages_per_conversation'] ?? 0 ), 1 ),
			'active_conversations' => (int) ( $metrics['active_count'] ?? 0 ),
		];

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get conversation heatmap data (day-of-week x hour).
	 *
	 * @param int $days Number of days to look back
	 * @return array<int, array<int, int>> Heatmap data indexed by day (1-7) and hour (0-23)
	 */
	public function getConversationHeatmap( int $days = 7 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_conversation_heatmap_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		// Initialize 1..7 (Sun..Sat) with 0 counts for 0..23 hours.
		$data = [];
		for ( $day = 1; $day <= 7; $day++ ) {
			$data[ $day ] = array_fill( 0, 24, 0 );
		}

		$startDate = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$tableName = $wpdb->prefix . 'wch_messages';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DAYOFWEEK(created_at) as day, HOUR(created_at) as hour, COUNT(*) as count
                FROM {$tableName}
                WHERE created_at >= %s
                GROUP BY day, hour",
				$startDate
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$day  = (int) ( $row['day'] ?? 0 );
			$hour = (int) ( $row['hour'] ?? 0 );
			if ( $day >= 1 && $day <= 7 && $hour >= 0 && $hour <= 23 ) {
				$data[ $day ][ $hour ] = (int) $row['count'];
			}
		}

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get detailed operational metrics.
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, float|int> Metrics data
	 */
	public function getDetailedMetrics( int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_metrics_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$ordersTable  = $wpdb->posts;
		$postmetaTable = $wpdb->postmeta;

		$whatsappStats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as order_count, SUM(pm_total.meta_value) as total
                FROM {$ordersTable} p
                INNER JOIN {$postmetaTable} pm_wch ON p.ID = pm_wch.post_id
                INNER JOIN {$postmetaTable} pm_total ON p.ID = pm_total.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND pm_total.meta_key = '_order_total'
                AND DATE(p.post_date) >= %s",
				$startDate
			),
			ARRAY_A
		);

		$webStats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) as order_count, SUM(pm_total.meta_value) as total
                FROM {$ordersTable} p
                INNER JOIN {$postmetaTable} pm_total ON p.ID = pm_total.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_total.meta_key = '_order_total'
                AND DATE(p.post_date) >= %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$postmetaTable} pm_wch
                    WHERE pm_wch.post_id = p.ID
                    AND pm_wch.meta_key = '_wch_whatsapp_order'
                    AND pm_wch.meta_value = '1'
                )",
				$startDate
			),
			ARRAY_A
		);

		$whatsappCount = (int) ( $whatsappStats['order_count'] ?? 0 );
		$whatsappTotal = (float) ( $whatsappStats['total'] ?? 0 );
		$webCount      = (int) ( $webStats['order_count'] ?? 0 );
		$webTotal      = (float) ( $webStats['total'] ?? 0 );

		$avgOrderValue     = $whatsappCount > 0 ? $whatsappTotal / $whatsappCount : 0.0;
		$avgOrderValueWeb  = $webCount > 0 ? $webTotal / $webCount : 0.0;

		$messagesTable = $wpdb->prefix . 'wch_messages';
		$volume        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                    SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound,
                    SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound
                FROM {$messagesTable}
                WHERE created_at >= %s",
				gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
			),
			ARRAY_A
		);

		$cartAbandonmentRate = 0.0;
		$cartsTable          = $wpdb->prefix . 'wch_carts';
		$hasCartsTable       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cartsTable ) );
		if ( $hasCartsTable === $cartsTable ) {
			$cartStats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
                        SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned,
                        COUNT(*) as total
                    FROM {$cartsTable}
                    WHERE created_at >= %s",
					gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
				),
				ARRAY_A
			);
			$totalCarts     = (int) ( $cartStats['total'] ?? 0 );
			$abandonedCarts = (int) ( $cartStats['abandoned'] ?? 0 );
			$cartAbandonmentRate = $totalCarts > 0 ? ( $abandonedCarts / $totalCarts ) * 100 : 0.0;
		}

		$avgResponseTime = $this->getAverageResponseTime( $days );

		$data = [
			'avg_order_value'       => round( $avgOrderValue, 2 ),
			'avg_order_value_web'   => round( $avgOrderValueWeb, 2 ),
			'cart_abandonment_rate' => round( $cartAbandonmentRate, 2 ),
			'avg_response_time'     => (int) $avgResponseTime,
			'message_volume_inbound'  => (int) ( $volume['inbound'] ?? 0 ),
			'message_volume_outbound' => (int) ( $volume['outbound'] ?? 0 ),
		];

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get funnel data for conversations to order completion.
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, int> Funnel metrics
	 */
	public function getFunnelData( int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_funnel_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$conversationsTable = $wpdb->prefix . 'wch_conversations';
		$messagesTable      = $wpdb->prefix . 'wch_messages';
		$ordersTable        = $wpdb->posts;
		$postmetaTable      = $wpdb->postmeta;

		$conversationsStarted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$conversationsTable} WHERE created_at >= %s",
				$startDate
			)
		);

		$productViewed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messagesTable}
                WHERE direction = 'inbound'
                AND created_at >= %s
                AND CAST(content AS CHAR) LIKE %s",
				$startDate,
				'%product_%'
			)
		);

		$addedToCart = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messagesTable}
                WHERE direction = 'inbound'
                AND created_at >= %s
                AND CAST(content AS CHAR) LIKE %s",
				$startDate,
				'%add_to_cart_%'
			)
		);

		$checkoutStarted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messagesTable}
                WHERE direction = 'inbound'
                AND created_at >= %s
                AND CAST(content AS CHAR) LIKE %s",
				$startDate,
				'%checkout%'
			)
		);

		$orderCompleted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$ordersTable} p
                INNER JOIN {$postmetaTable} pm_wch ON p.ID = pm_wch.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND DATE(p.post_date) >= %s",
				gmdate( 'Y-m-d', strtotime( "-{$days} days" ) )
			)
		);

		$data = [
			'conversations_started' => $conversationsStarted,
			'product_viewed'        => $productViewed,
			'added_to_cart'         => $addedToCart,
			'checkout_started'      => $checkoutStarted,
			'order_completed'       => $orderCompleted,
		];

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get WhatsApp orders count
	 *
	 * @param string $startDate Start date (Y-m-d)
	 * @param string $endDate End date (Y-m-d)
	 * @return int Orders count
	 */
	private function getWhatsAppOrdersCount( string $startDate, string $endDate ): int {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
                AND pm.meta_key = '_wch_whatsapp_order'
                AND pm.meta_value = '1'
                AND DATE(p.post_date) BETWEEN %s AND %s",
				$startDate,
				$endDate
			)
		);

		return (int) $count;
	}

	/**
	 * Get WhatsApp revenue
	 *
	 * @param string $startDate Start date (Y-m-d)
	 * @param string $endDate End date (Y-m-d)
	 * @return float Total revenue
	 */
	private function getWhatsAppRevenue( string $startDate, string $endDate ): float {
		global $wpdb;

		$revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pm_total.meta_value)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_wch ON p.ID = pm_wch.post_id
                INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND pm_total.meta_key = '_order_total'
                AND DATE(p.post_date) BETWEEN %s AND %s",
				$startDate,
				$endDate
			)
		);

		return (float) ( $revenue ?? 0 );
	}

	/**
	 * Get active conversations count
	 *
	 * @return int Active conversations count
	 */
	private function getActiveConversationsCount(): int {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_conversations';

		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$tableName} WHERE status = 'active'"
		);

		return (int) $count;
	}

	/**
	 * Get conversion rate
	 *
	 * @param string $startDate Start date (Y-m-d)
	 * @param string $endDate End date (Y-m-d)
	 * @return float Conversion rate percentage
	 */
	private function getConversionRate( string $startDate, string $endDate ): float {
		global $wpdb;
		$conversationsTable = $wpdb->prefix . 'wch_conversations';

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                    COUNT(DISTINCT c.id) as total_conversations,
                    COUNT(DISTINCT CASE WHEN pm.meta_value IS NOT NULL THEN c.customer_phone END) as converted
                FROM {$conversationsTable} c
                LEFT JOIN {$wpdb->posts} p ON c.customer_phone = (
                    SELECT meta_value FROM {$wpdb->postmeta} 
                    WHERE post_id = p.ID AND meta_key = '_wch_customer_phone' LIMIT 1
                )
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    AND pm.meta_key = '_wch_whatsapp_order' 
                    AND pm.meta_value = '1'
                WHERE DATE(c.created_at) BETWEEN %s AND %s",
				$startDate,
				$endDate
			),
			ARRAY_A
		);

		$total     = (int) ( $stats['total_conversations'] ?? 0 );
		$converted = (int) ( $stats['converted'] ?? 0 );

		if ( $total === 0 ) {
			return 0.0;
		}

		return round( ( $converted / $total ) * 100, 2 );
	}

	/**
	 * Get date range for period
	 *
	 * @param string $period Period: 'today', 'week', 'month', 'year'
	 * @return array<string, string> Array with 'start' and 'end' dates
	 */
	private function getDateRange( string $period ): array {
		$endDate = gmdate( 'Y-m-d' );

		$startDate = match ( $period ) {
			'today' => gmdate( 'Y-m-d' ),
			'week' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			'month' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'year' => gmdate( 'Y-m-d', strtotime( '-365 days' ) ),
			default => gmdate( 'Y-m-d' ),
		};

		return [
			'start' => $startDate,
			'end'   => $endDate,
		];
	}

	/**
	 * Clear analytics cache
	 */
	public function clearCache(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_wch_analytics_%' 
            OR option_name LIKE '_transient_timeout_wch_analytics_%'"
		);
	}

	/**
	 * Get customer insights
	 *
	 * @param int $days Number of days to look back
	 * @return array<string, mixed> Customer insights
	 */
	public function getCustomerInsights( int $days = 30 ): array {
		global $wpdb;

		$cacheKey = "wch_analytics_customer_insights_{$days}";
		$cached   = get_transient( $cacheKey );

		if ( $cached !== false ) {
			return $cached;
		}

		$startDate = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
                    COUNT(DISTINCT pm_phone.meta_value) as total_customers,
                    COUNT(p.ID) as total_orders,
                    AVG(pm_total.meta_value) as avg_order_value
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_wch ON p.ID = pm_wch.post_id
                INNER JOIN {$wpdb->postmeta} pm_phone ON p.ID = pm_phone.post_id
                INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND pm_phone.meta_key = '_wch_customer_phone'
                AND pm_total.meta_key = '_order_total'
                AND DATE(p.post_date) >= %s",
				$startDate
			),
			ARRAY_A
		);

		$totalCustomers = (int) ( $stats['total_customers'] ?? 0 );
		$totalOrders    = (int) ( $stats['total_orders'] ?? 0 );

		$newCustomers = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm_phone.meta_value)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_wch ON p.ID = pm_wch.post_id
                INNER JOIN {$wpdb->postmeta} pm_phone ON p.ID = pm_phone.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND pm_phone.meta_key = '_wch_customer_phone'
                AND DATE(p.post_date) >= %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->posts} p2
                    INNER JOIN {$wpdb->postmeta} pm2_wch ON p2.ID = pm2_wch.post_id
                    INNER JOIN {$wpdb->postmeta} pm2_phone ON p2.ID = pm2_phone.post_id
                    WHERE p2.post_type = 'shop_order'
                    AND p2.post_status IN ('wc-processing', 'wc-completed')
                    AND pm2_wch.meta_key = '_wch_whatsapp_order'
                    AND pm2_wch.meta_value = '1'
                    AND pm2_phone.meta_key = '_wch_customer_phone'
                    AND pm2_phone.meta_value = pm_phone.meta_value
                    AND DATE(p2.post_date) < %s
                )",
				$startDate,
				$startDate
			)
		);

		$returningCustomers = max( 0, $totalCustomers - $newCustomers );

		$profilesTable   = $wpdb->prefix . 'wch_customer_profiles';
		$profilesJoin    = '';
		$profilesSelect  = "'' as name";
		$profilesGroupBy = 'pm_phone.meta_value';
		$hasProfilesTable = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profilesTable ) );
		if ( $hasProfilesTable === $profilesTable ) {
			$profilesJoin    = "LEFT JOIN {$profilesTable} cp ON cp.phone = pm_phone.meta_value";
			$profilesSelect  = 'cp.name as name';
			$profilesGroupBy = 'pm_phone.meta_value, cp.name';
		}

		$topCustomers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm_phone.meta_value as phone, {$profilesSelect},
                    COUNT(p.ID) as order_count,
                    SUM(pm_total.meta_value) as total_value
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_wch ON p.ID = pm_wch.post_id
                INNER JOIN {$wpdb->postmeta} pm_phone ON p.ID = pm_phone.post_id
                INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
                {$profilesJoin}
                WHERE p.post_type = 'shop_order'
                AND p.post_status IN ('wc-processing', 'wc-completed')
                AND pm_wch.meta_key = '_wch_whatsapp_order'
                AND pm_wch.meta_value = '1'
                AND pm_phone.meta_key = '_wch_customer_phone'
                AND pm_total.meta_key = '_order_total'
                AND DATE(p.post_date) >= %s
                GROUP BY {$profilesGroupBy}
                ORDER BY total_value DESC
                LIMIT 10",
				$startDate
			),
			ARRAY_A
		);

		$topCustomers = array_map(
			static function ( array $row ): array {
				return [
					'name'        => $row['name'] ?? '',
					'phone'       => (string) ( $row['phone'] ?? '' ),
					'order_count' => (int) ( $row['order_count'] ?? 0 ),
					'total_value' => (float) ( $row['total_value'] ?? 0 ),
				];
			},
			$topCustomers
		);

		$data = [
			'total_customers'      => $totalCustomers,
			'total_orders'         => $totalOrders,
			'avg_order_value'      => round( (float) ( $stats['avg_order_value'] ?? 0 ), 2 ),
			'orders_per_customer'  => $totalCustomers > 0 ? round( $totalOrders / $totalCustomers, 2 ) : 0,
			'new_customers'        => $newCustomers,
			'returning_customers'  => $returningCustomers,
			'top_customers'        => $topCustomers,
		];

		set_transient( $cacheKey, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Export analytics data to CSV in uploads directory.
	 *
	 * @param array  $data     Data to export.
	 * @param string $filename Filename to write.
	 * @return void
	 */
	public function exportToCsv( array $data, string $filename ): void {
		$upload = wp_upload_dir();
		$path   = trailingslashit( $upload['basedir'] ) . $filename;

		$handle = fopen( $path, 'w' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'Unable to create CSV file' );
		}

		if ( empty( $data ) ) {
			fclose( $handle );
			return;
		}

		$isAssoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );

		if ( $isAssoc ) {
			fputcsv( $handle, [ 'key', 'value' ] );
			foreach ( $data as $key => $value ) {
				fputcsv( $handle, [ (string) $key, $value ] );
			}
			fclose( $handle );
			return;
		}

		$firstRow = $data[0];
		if ( is_array( $firstRow ) ) {
			fputcsv( $handle, array_keys( $firstRow ) );
			foreach ( $data as $row ) {
				if ( is_array( $row ) ) {
					fputcsv( $handle, $row );
				}
			}
		} else {
			foreach ( $data as $row ) {
				fputcsv( $handle, [ $row ] );
			}
		}

		fclose( $handle );
	}

	/**
	 * Calculate average response time between inbound and next outbound messages.
	 *
	 * @param int $days Number of days to look back
	 * @return float Average response time in seconds
	 */
	private function getAverageResponseTime( int $days ): float {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_messages';
		$startDate = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(SECOND, m_in.created_at, m_out.created_at))
                FROM {$tableName} m_in
                INNER JOIN {$tableName} m_out
                    ON m_out.conversation_id = m_in.conversation_id
                    AND m_out.direction = 'outbound'
                    AND m_out.created_at = (
                        SELECT MIN(m2.created_at)
                        FROM {$tableName} m2
                        WHERE m2.conversation_id = m_in.conversation_id
                        AND m2.direction = 'outbound'
                        AND m2.created_at > m_in.created_at
                    )
                WHERE m_in.direction = 'inbound'
                AND m_in.created_at >= %s",
				$startDate
			)
		);

		return (float) ( $result ?? 0 );
	}
}
