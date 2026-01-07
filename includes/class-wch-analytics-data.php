<?php
/**
 * Analytics Data Handler
 *
 * Handles all analytics data aggregation and queries.
 *
 * @package WhatsApp_Commerce_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * WCH_Analytics_Data class.
 */
class WCH_Analytics_Data {

	/**
	 * Cache expiry time (15 minutes)
	 */
	const CACHE_EXPIRY = 900;

	/**
	 * Get analytics summary data
	 *
	 * @param string $period Period: 'today', 'week', 'month'
	 * @return array Summary data
	 */
	public static function get_summary( $period = 'today' ) {
		$cache_key = 'wch_analytics_summary_' . $period;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$date_range = self::get_date_range( $period );

		$data = array(
			'total_orders'         => self::get_whatsapp_orders_count( $date_range['start'], $date_range['end'] ),
			'total_revenue'        => self::get_whatsapp_revenue( $date_range['start'], $date_range['end'] ),
			'active_conversations' => self::get_active_conversations_count(),
			'conversion_rate'      => self::get_conversion_rate( $date_range['start'], $date_range['end'] ),
			'period'               => $period,
		);

		set_transient( $cache_key, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get orders over time
	 *
	 * @param int $days Number of days to look back
	 * @return array Orders data
	 */
	public static function get_orders_over_time( $days = 30 ) {
		global $wpdb;

		$cache_key = 'wch_analytics_orders_time_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		$query = $wpdb->prepare(
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
			$start_date,
			$end_date
		);

		$results = $wpdb->get_results( $query );

		$data = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date          = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$data[ $date ] = 0;
		}

		foreach ( $results as $row ) {
			$data[ $row->order_date ] = (int) $row->count;
		}

		set_transient( $cache_key, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get revenue by day
	 *
	 * @param int $days Number of days to look back
	 * @return array Revenue data
	 */
	public static function get_revenue_by_day( $days = 30 ) {
		global $wpdb;

		$cache_key = 'wch_analytics_revenue_day_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		$query = $wpdb->prepare(
			"SELECT DATE(p.post_date) as order_date,
			       SUM(pm_total.meta_value) as total
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing', 'wc-completed')
			AND pm.meta_key = '_wch_whatsapp_order'
			AND pm.meta_value = '1'
			AND pm_total.meta_key = '_order_total'
			AND DATE(p.post_date) BETWEEN %s AND %s
			GROUP BY DATE(p.post_date)
			ORDER BY order_date ASC",
			$start_date,
			$end_date
		);

		$results = $wpdb->get_results( $query );

		$data = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date          = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$data[ $date ] = 0;
		}

		foreach ( $results as $row ) {
			$data[ $row->order_date ] = (float) $row->total;
		}

		set_transient( $cache_key, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get top products sold via WhatsApp
	 *
	 * @param int $limit Number of products to return
	 * @param int $days Number of days to look back
	 * @return array Top products
	 */
	public static function get_top_products( $limit = 10, $days = 30 ) {
		global $wpdb;

		$cache_key = 'wch_analytics_top_products_' . $limit . '_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$query = $wpdb->prepare(
			"SELECT oi.order_item_name as product_name,
			       SUM(oim_qty.meta_value) as quantity
			FROM {$wpdb->prefix}woocommerce_order_items oi
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
			    ON oi.order_item_id = oim_qty.order_item_id
			INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE oi.order_item_type = 'line_item'
			AND oim_qty.meta_key = '_qty'
			AND p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing', 'wc-completed')
			AND pm.meta_key = '_wch_whatsapp_order'
			AND pm.meta_value = '1'
			AND DATE(p.post_date) >= %s
			GROUP BY product_name
			ORDER BY quantity DESC
			LIMIT %d",
			$start_date,
			$limit
		);

		$results = $wpdb->get_results( $query );

		set_transient( $cache_key, $results, self::CACHE_EXPIRY );

		return $results;
	}

	/**
	 * Get conversation volume heatmap
	 *
	 * @param int $days Number of days to look back
	 * @return array Heatmap data
	 */
	public static function get_conversation_heatmap( $days = 7 ) {
		global $wpdb;

		$cache_key = 'wch_analytics_conv_heatmap_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$table_name = $wpdb->prefix . 'wch_conversations';

		$query = $wpdb->prepare(
			"SELECT DAYOFWEEK(created_at) as day_of_week,
			       HOUR(created_at) as hour_of_day,
			       COUNT(*) as count
			FROM {$table_name}
			WHERE DATE(created_at) >= %s
			GROUP BY day_of_week, hour_of_day
			ORDER BY day_of_week, hour_of_day",
			$start_date
		);

		$results = $wpdb->get_results( $query );

		$heatmap = array();
		for ( $day = 1; $day <= 7; $day++ ) {
			for ( $hour = 0; $hour < 24; $hour++ ) {
				if ( ! isset( $heatmap[ $day ] ) ) {
					$heatmap[ $day ] = array();
				}
				$heatmap[ $day ][ $hour ] = 0;
			}
		}

		foreach ( $results as $row ) {
			$heatmap[ (int) $row->day_of_week ][ (int) $row->hour_of_day ] = (int) $row->count;
		}

		set_transient( $cache_key, $heatmap, self::CACHE_EXPIRY );

		return $heatmap;
	}

	/**
	 * Get detailed metrics
	 *
	 * @param int $days Number of days to look back
	 * @return array Metrics
	 */
	public static function get_detailed_metrics( $days = 30 ) {
		$cache_key = 'wch_analytics_detailed_metrics_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$end_date   = gmdate( 'Y-m-d' );

		$data = array(
			'avg_order_value'         => self::get_average_order_value( $start_date, $end_date ),
			'avg_order_value_web'     => self::get_average_order_value_web( $start_date, $end_date ),
			'cart_abandonment_rate'   => self::get_cart_abandonment_rate( $start_date, $end_date ),
			'avg_response_time'       => self::get_average_response_time( $start_date, $end_date ),
			'message_volume_inbound'  => self::get_message_volume( 'inbound', $start_date, $end_date ),
			'message_volume_outbound' => self::get_message_volume( 'outbound', $start_date, $end_date ),
		);

		set_transient( $cache_key, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get customer insights
	 *
	 * @param int $days Number of days to look back
	 * @return array Customer insights
	 */
	public static function get_customer_insights( $days = 30 ) {
		global $wpdb;

		$cache_key = 'wch_analytics_customer_insights_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$profiles_table = $wpdb->prefix . 'wch_customer_profiles';

		$new_customers = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$profiles_table}
				WHERE DATE(created_at) >= %s",
				$start_date
			)
		);

		$returning_customers = self::get_returning_customers_count( $start_date );

		$top_customers = self::get_top_customers( 10, $days );

		$data = array(
			'new_customers'       => (int) $new_customers,
			'returning_customers' => (int) $returning_customers,
			'top_customers'       => $top_customers,
		);

		set_transient( $cache_key, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get funnel data
	 *
	 * @param int $days Number of days to look back
	 * @return array Funnel data
	 */
	public static function get_funnel_data( $days = 30 ) {
		global $wpdb;

		$cache_key = 'wch_analytics_funnel_' . $days;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$conversations_table = $wpdb->prefix . 'wch_conversations';
		$messages_table      = $wpdb->prefix . 'wch_messages';
		$carts_table         = $wpdb->prefix . 'wch_carts';

		$conversations_started = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$conversations_table}
				WHERE DATE(created_at) >= %s",
				$start_date
			)
		);

		$carts_created = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$carts_table}
				WHERE DATE(created_at) >= %s",
				$start_date
			)
		);

		$checkouts_started = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$carts_table}
				WHERE status IN ('completed', 'abandoned')
				AND DATE(created_at) >= %s",
				$start_date
			)
		);

		$orders_completed = self::get_whatsapp_orders_count( $start_date, gmdate( 'Y-m-d' ) );

		$data = array(
			'conversations_started' => (int) $conversations_started,
			'product_viewed'        => (int) $carts_created,
			'added_to_cart'         => (int) $carts_created,
			'checkout_started'      => (int) $checkouts_started,
			'order_completed'       => (int) $orders_completed,
		);

		set_transient( $cache_key, $data, self::CACHE_EXPIRY );

		return $data;
	}

	/**
	 * Get WhatsApp orders count
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return int Orders count
	 */
	private static function get_whatsapp_orders_count( $start_date, $end_date ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
				AND pm.meta_key = '_wch_whatsapp_order'
				AND pm.meta_value = '1'
				AND DATE(p.post_date) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);
	}

	/**
	 * Get WhatsApp revenue
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return float Revenue
	 */
	private static function get_whatsapp_revenue( $start_date, $end_date ) {
		global $wpdb;

		$revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(pm_total.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-processing', 'wc-completed')
				AND pm.meta_key = '_wch_whatsapp_order'
				AND pm.meta_value = '1'
				AND pm_total.meta_key = '_order_total'
				AND DATE(p.post_date) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		return $revenue ? (float) $revenue : 0.0;
	}

	/**
	 * Get active conversations count
	 *
	 * @return int Active conversations
	 */
	private static function get_active_conversations_count() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_conversations';

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$table_name}
			WHERE status = 'active'"
		);
	}

	/**
	 * Get conversion rate
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return float Conversion rate percentage
	 */
	private static function get_conversion_rate( $start_date, $end_date ) {
		global $wpdb;

		$conversations_table = $wpdb->prefix . 'wch_conversations';

		$total_conversations = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$conversations_table}
				WHERE DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		if ( 0 === (int) $total_conversations ) {
			return 0.0;
		}

		$orders = self::get_whatsapp_orders_count( $start_date, $end_date );

		return ( $orders / $total_conversations ) * 100;
	}

	/**
	 * Get average order value for WhatsApp orders
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return float Average order value
	 */
	private static function get_average_order_value( $start_date, $end_date ) {
		global $wpdb;

		$avg = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(pm_total.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-processing', 'wc-completed')
				AND pm.meta_key = '_wch_whatsapp_order'
				AND pm.meta_value = '1'
				AND pm_total.meta_key = '_order_total'
				AND DATE(p.post_date) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		return $avg ? (float) $avg : 0.0;
	}

	/**
	 * Get average order value for web orders
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return float Average order value
	 */
	private static function get_average_order_value_web( $start_date, $end_date ) {
		global $wpdb;

		$avg = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(pm_total.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
				LEFT JOIN {$wpdb->postmeta} pm_wa ON p.ID = pm_wa.post_id AND pm_wa.meta_key = '_wch_whatsapp_order'
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-processing', 'wc-completed')
				AND pm_total.meta_key = '_order_total'
				AND (pm_wa.meta_value IS NULL OR pm_wa.meta_value != '1')
				AND DATE(p.post_date) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		return $avg ? (float) $avg : 0.0;
	}

	/**
	 * Get cart abandonment rate
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return float Abandonment rate percentage
	 */
	private static function get_cart_abandonment_rate( $start_date, $end_date ) {
		global $wpdb;

		$carts_table = $wpdb->prefix . 'wch_carts';

		$total_carts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$carts_table}
				WHERE DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		if ( 0 === (int) $total_carts ) {
			return 0.0;
		}

		$abandoned_carts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$carts_table}
				WHERE status = 'abandoned'
				AND DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		return ( $abandoned_carts / $total_carts ) * 100;
	}

	/**
	 * Get average response time
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return float Average response time in seconds
	 */
	private static function get_average_response_time( $start_date, $end_date ) {
		global $wpdb;

		$messages_table = $wpdb->prefix . 'wch_messages';

		$query = $wpdb->prepare(
			"SELECT m1.conversation_id,
			       TIMESTAMPDIFF(SECOND, m1.created_at, m2.created_at) as response_time
			FROM {$messages_table} m1
			INNER JOIN {$messages_table} m2
			    ON m1.conversation_id = m2.conversation_id
			WHERE m1.direction = 'inbound'
			AND m2.direction = 'outbound'
			AND m2.created_at > m1.created_at
			AND DATE(m1.created_at) BETWEEN %s AND %s
			AND m2.id = (
			    SELECT MIN(id)
			    FROM {$messages_table}
			    WHERE conversation_id = m1.conversation_id
			    AND direction = 'outbound'
			    AND created_at > m1.created_at
			)
			GROUP BY m1.id",
			$start_date,
			$end_date
		);

		$results = $wpdb->get_results( $query );

		if ( empty( $results ) ) {
			return 0.0;
		}

		$total_time = 0;
		foreach ( $results as $row ) {
			$total_time += (float) $row->response_time;
		}

		return $total_time / count( $results );
	}

	/**
	 * Get message volume
	 *
	 * @param string $direction Direction: 'inbound' or 'outbound'
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return int Message count
	 */
	private static function get_message_volume( $direction, $start_date, $end_date ) {
		global $wpdb;

		$messages_table = $wpdb->prefix . 'wch_messages';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$messages_table}
				WHERE direction = %s
				AND DATE(created_at) BETWEEN %s AND %s",
				$direction,
				$start_date,
				$end_date
			)
		);
	}

	/**
	 * Get returning customers count
	 *
	 * @param string $start_date Start date
	 * @return int Returning customers
	 */
	private static function get_returning_customers_count( $start_date ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} pm_wa ON p.ID = pm_wa.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-processing', 'wc-completed')
				AND pm.meta_key = '_billing_phone'
				AND pm_wa.meta_key = '_wch_whatsapp_order'
				AND pm_wa.meta_value = '1'
				AND DATE(p.post_date) >= %s
				AND pm.meta_value IN (
				    SELECT pm2.meta_value
				    FROM {$wpdb->posts} p2
				    INNER JOIN {$wpdb->postmeta} pm2 ON p2.ID = pm2.post_id
				    INNER JOIN {$wpdb->postmeta} pm2_wa ON p2.ID = pm2_wa.post_id
				    WHERE p2.post_type = 'shop_order'
				    AND p2.post_status IN ('wc-processing', 'wc-completed')
				    AND pm2.meta_key = '_billing_phone'
				    AND pm2_wa.meta_key = '_wch_whatsapp_order'
				    AND pm2_wa.meta_value = '1'
				    AND DATE(p2.post_date) < %s
				)",
				$start_date,
				$start_date
			)
		);
	}

	/**
	 * Get top customers
	 *
	 * @param int $limit Number of customers to return
	 * @param int $days Number of days to look back
	 * @return array Top customers
	 */
	private static function get_top_customers( $limit = 10, $days = 30 ) {
		global $wpdb;

		$start_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$query = $wpdb->prepare(
			"SELECT pm_phone.meta_value as phone,
			       pm_name.meta_value as name,
			       COUNT(p.ID) as order_count,
			       SUM(pm_total.meta_value) as total_value
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			INNER JOIN {$wpdb->postmeta} pm_phone ON p.ID = pm_phone.post_id
			LEFT JOIN {$wpdb->postmeta} pm_name ON p.ID = pm_name.post_id AND pm_name.meta_key = '_billing_first_name'
			INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing', 'wc-completed')
			AND pm.meta_key = '_wch_whatsapp_order'
			AND pm.meta_value = '1'
			AND pm_phone.meta_key = '_billing_phone'
			AND pm_total.meta_key = '_order_total'
			AND DATE(p.post_date) >= %s
			GROUP BY pm_phone.meta_value
			ORDER BY total_value DESC
			LIMIT %d",
			$start_date,
			$limit
		);

		return $wpdb->get_results( $query );
	}

	/**
	 * Get date range for period
	 *
	 * @param string $period Period: 'today', 'week', 'month'
	 * @return array Start and end dates
	 */
	private static function get_date_range( $period ) {
		$end = gmdate( 'Y-m-d' );

		switch ( $period ) {
			case 'today':
				$start = gmdate( 'Y-m-d' );
				break;
			case 'week':
				$start = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
				break;
			case 'month':
				$start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
				break;
			default:
				$start = gmdate( 'Y-m-d' );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Export analytics data to CSV
	 *
	 * @param array  $data Data to export
	 * @param string $filename Filename
	 * @return string File path
	 */
	public static function export_to_csv( $data, $filename = 'analytics-export.csv' ) {
		$upload_dir = wp_upload_dir();
		$file_path  = trailingslashit( $upload_dir['path'] ) . $filename;

		$fp = fopen( $file_path, 'w' );

		if ( ! empty( $data ) ) {
			fputcsv( $fp, array_keys( reset( $data ) ) );

			foreach ( $data as $row ) {
				fputcsv( $fp, $row );
			}
		}

		fclose( $fp );

		return $file_path;
	}

	/**
	 * Clear all analytics caches
	 */
	public static function clear_caches() {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_wch_analytics_%'
			OR option_name LIKE '_transient_timeout_wch_analytics_%'"
		);
	}
}
