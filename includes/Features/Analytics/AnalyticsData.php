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

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Analytics Data Class
 *
 * Provides analytics data for WhatsApp commerce operations.
 */
class AnalyticsData
{
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
    public function getSummary(string $period = 'today'): array
    {
        $cacheKey = "wch_analytics_summary_{$period}";
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $dateRange = $this->getDateRange($period);

        $data = [
            'total_orders' => $this->getWhatsAppOrdersCount($dateRange['start'], $dateRange['end']),
            'total_revenue' => $this->getWhatsAppRevenue($dateRange['start'], $dateRange['end']),
            'active_conversations' => $this->getActiveConversationsCount(),
            'conversion_rate' => $this->getConversionRate($dateRange['start'], $dateRange['end']),
            'period' => $period,
        ];

        set_transient($cacheKey, $data, self::CACHE_EXPIRY);

        return $data;
    }

    /**
     * Get orders over time
     *
     * @param int $days Number of days to look back
     * @return array<string, int> Orders data indexed by date
     */
    public function getOrdersOverTime(int $days = 30): array
    {
        global $wpdb;

        $cacheKey = "wch_analytics_orders_time_{$days}";
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $startDate = gmdate('Y-m-d', strtotime("-{$days} days"));
        $endDate = gmdate('Y-m-d');

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
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', strtotime("-{$i} days"));
            $data[$date] = 0;
        }

        // Fill in actual counts
        foreach ($results as $row) {
            $data[$row->order_date] = (int) $row->count;
        }

        set_transient($cacheKey, $data, self::CACHE_EXPIRY);

        return $data;
    }

    /**
     * Get revenue over time
     *
     * @param int $days Number of days to look back
     * @return array<string, float> Revenue data indexed by date
     */
    public function getRevenueOverTime(int $days = 30): array
    {
        global $wpdb;

        $cacheKey = "wch_analytics_revenue_time_{$days}";
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $startDate = gmdate('Y-m-d', strtotime("-{$days} days"));

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
        foreach ($results as $row) {
            $data[$row->order_date] = (float) $row->total;
        }

        set_transient($cacheKey, $data, self::CACHE_EXPIRY);

        return $data;
    }

    /**
     * Get top products
     *
     * @param int $limit Number of products to return
     * @param int $days Number of days to look back
     * @return array<int, array<string, mixed>> Top products data
     */
    public function getTopProducts(int $limit = 10, int $days = 30): array
    {
        global $wpdb;

        $cacheKey = "wch_analytics_top_products_{$limit}_{$days}";
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $startDate = gmdate('Y-m-d', strtotime("-{$days} days"));

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
        foreach ($results as $row) {
            $data[] = [
                'product_name' => $row->product_name,
                'quantity' => (int) $row->quantity,
                'revenue' => (float) $row->total,
            ];
        }

        set_transient($cacheKey, $data, self::CACHE_EXPIRY);

        return $data;
    }

    /**
     * Get conversation metrics
     *
     * @param int $days Number of days to look back
     * @return array<string, mixed> Conversation metrics
     */
    public function getConversationMetrics(int $days = 30): array
    {
        global $wpdb;

        $cacheKey = "wch_analytics_conversations_{$days}";
        $cached = get_transient($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $startDate = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
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
            'total_conversations' => (int) ($metrics['total_conversations'] ?? 0),
            'unique_customers' => (int) ($metrics['unique_customers'] ?? 0),
            'avg_messages' => round((float) ($metrics['avg_messages_per_conversation'] ?? 0), 1),
            'active_conversations' => (int) ($metrics['active_count'] ?? 0),
        ];

        set_transient($cacheKey, $data, self::CACHE_EXPIRY);

        return $data;
    }

    /**
     * Get WhatsApp orders count
     *
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @return int Orders count
     */
    private function getWhatsAppOrdersCount(string $startDate, string $endDate): int
    {
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
    private function getWhatsAppRevenue(string $startDate, string $endDate): float
    {
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

        return (float) ($revenue ?? 0);
    }

    /**
     * Get active conversations count
     *
     * @return int Active conversations count
     */
    private function getActiveConversationsCount(): int
    {
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
    private function getConversionRate(string $startDate, string $endDate): float
    {
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

        $total = (int) ($stats['total_conversations'] ?? 0);
        $converted = (int) ($stats['converted'] ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        return round(($converted / $total) * 100, 2);
    }

    /**
     * Get date range for period
     *
     * @param string $period Period: 'today', 'week', 'month', 'year'
     * @return array<string, string> Array with 'start' and 'end' dates
     */
    private function getDateRange(string $period): array
    {
        $endDate = gmdate('Y-m-d');

        $startDate = match ($period) {
            'today' => gmdate('Y-m-d'),
            'week' => gmdate('Y-m-d', strtotime('-7 days')),
            'month' => gmdate('Y-m-d', strtotime('-30 days')),
            'year' => gmdate('Y-m-d', strtotime('-365 days')),
            default => gmdate('Y-m-d'),
        };

        return [
            'start' => $startDate,
            'end' => $endDate,
        ];
    }

    /**
     * Clear analytics cache
     */
    public function clearCache(): void
    {
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
    public function getCustomerInsights(int $days = 30): array
    {
        global $wpdb;

        $startDate = gmdate('Y-m-d', strtotime("-{$days} days"));

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

        $totalCustomers = (int) ($stats['total_customers'] ?? 0);
        $totalOrders = (int) ($stats['total_orders'] ?? 0);

        return [
            'total_customers' => $totalCustomers,
            'total_orders' => $totalOrders,
            'avg_order_value' => round((float) ($stats['avg_order_value'] ?? 0), 2),
            'orders_per_customer' => $totalCustomers > 0 ? round($totalOrders / $totalCustomers, 2) : 0,
        ];
    }
}
