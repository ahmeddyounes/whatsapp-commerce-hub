<?php
/**
 * Reengagement Service
 *
 * Handles customer reengagement campaigns to win back inactive customers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\Reengagement;

use WhatsAppCommerceHub\Core\SettingsManager;
use WhatsAppCommerceHub\Support\Logger;
use WhatsAppCommerceHub\Presentation\Templates\TemplateManager;
use WhatsAppCommerceHub\Infrastructure\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reengagement Service
 *
 * Identifies inactive customers and sends targeted reengagement campaigns.
 */
class ReengagementService
{
    /**
     * Campaign types
     */
    private const CAMPAIGN_TYPES = [
        'we_miss_you' => 'Generic re-engagement',
        'new_arrivals' => 'New products since last visit',
        'back_in_stock' => 'Previously viewed items back in stock',
        'price_drop' => 'Price drops on viewed products',
        'loyalty_reward' => 'Discount based on lifetime value',
    ];

    /**
     * Inactivity threshold in days
     */
    private const INACTIVITY_THRESHOLD = 30;

    /**
     * Constructor
     *
     * @param SettingsManager $settings Settings manager
     * @param Logger $logger Logger instance
     * @param TemplateManager $templateManager Template manager
     * @param WhatsAppApiClient $apiClient WhatsApp API client
     * @param JobDispatcher $jobDispatcher Job dispatcher
     */
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly Logger $logger,
        private readonly TemplateManager $templateManager,
        private readonly WhatsAppApiClient $apiClient,
        private readonly JobDispatcher $jobDispatcher
    ) {
    }

    /**
     * Initialize reengagement campaigns
     *
     * Schedules recurring job to find and reactivate inactive customers.
     */
    public function init(): void
    {
        if (!as_next_scheduled_action('wch_process_reengagement', [], 'wch')) {
            as_schedule_recurring_action(
                time(),
                DAY_IN_SECONDS,
                'wch_process_reengagement',
                [],
                'wch'
            );
        }
    }

    /**
     * Process reengagement campaigns
     *
     * Finds inactive customers and sends appropriate reengagement messages.
     */
    public function processReengagement(): void
    {
        if (!$this->isReengagementEnabled()) {
            return;
        }

        $this->logger->info('Starting reengagement campaign processing');

        $inactiveCustomers = $this->findInactiveCustomers();

        foreach ($inactiveCustomers as $customer) {
            $this->processCustomer($customer);
        }

        $this->logger->info('Reengagement campaign processing complete', [
            'customers_processed' => count($inactiveCustomers),
        ]);
    }

    /**
     * Process individual customer reengagement
     *
     * @param array<string, mixed> $customer Customer data
     */
    private function processCustomer(array $customer): void
    {
        $phone = $customer['phone'];
        $customerId = (int) $customer['id'];

        // Check if already contacted recently
        if ($this->wasContactedRecently($phone)) {
            return;
        }

        // Determine best campaign type
        $campaignType = $this->determineCampaignType($customer);

        // Send reengagement message
        $this->sendReengagementMessage($phone, $customerId, $campaignType);
    }

    /**
     * Find inactive customers
     *
     * @return array<int, array<string, mixed>> Array of inactive customer data
     */
    private function findInactiveCustomers(): array
    {
        global $wpdb;
        $customersTable = $wpdb->prefix . 'wch_customers';

        $thresholdDate = gmdate('Y-m-d H:i:s', strtotime("-" . self::INACTIVITY_THRESHOLD . " days"));

        $customers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$customersTable}
                WHERE last_interaction_at < %s
                AND opted_out = 0
                AND phone IS NOT NULL
                ORDER BY last_interaction_at ASC
                LIMIT 50",
                $thresholdDate
            ),
            ARRAY_A
        );

        return $customers ?: [];
    }

    /**
     * Determine best campaign type for customer
     *
     * @param array<string, mixed> $customer Customer data
     * @return string Campaign type
     */
    private function determineCampaignType(array $customer): string
    {
        $customerId = (int) $customer['id'];
        $totalSpent = (float) ($customer['total_spent'] ?? 0);

        // High-value customers get loyalty rewards
        if ($totalSpent > 500) {
            return 'loyalty_reward';
        }

        // Check for viewed products with price drops
        if ($this->hasViewedProductsWithPriceDrops($customerId)) {
            return 'price_drop';
        }

        // Check for viewed products back in stock
        if ($this->hasViewedProductsBackInStock($customerId)) {
            return 'back_in_stock';
        }

        // Check if there are new products in their preferred categories
        if ($this->hasNewArrivalsInPreferredCategories($customerId)) {
            return 'new_arrivals';
        }

        // Default: generic re-engagement
        return 'we_miss_you';
    }

    /**
     * Send reengagement message
     *
     * @param string $phone Customer phone
     * @param int $customerId Customer ID
     * @param string $campaignType Campaign type
     * @return bool True on success
     */
    private function sendReengagementMessage(string $phone, int $customerId, string $campaignType): bool
    {
        try {
            $variables = $this->buildCampaignVariables($customerId, $campaignType);
            $templateName = "reengagement_{$campaignType}";

            $message = $this->templateManager->renderTemplate($templateName, $variables);

            $result = $this->apiClient->sendMessage($phone, $message);

            // Track campaign send
            $this->trackCampaignSend($customerId, $phone, $campaignType);

            $this->logger->info('Reengagement message sent', [
                'customer_id' => $customerId,
                'campaign_type' => $campaignType,
                'phone' => $phone,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send reengagement message', [
                'customer_id' => $customerId,
                'campaign_type' => $campaignType,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build campaign variables
     *
     * @param int $customerId Customer ID
     * @param string $campaignType Campaign type
     * @return array<string, string> Template variables
     */
    private function buildCampaignVariables(int $customerId, string $campaignType): array
    {
        global $wpdb;
        $customersTable = $wpdb->prefix . 'wch_customers';

        $customer = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$customersTable} WHERE id = %d", $customerId),
            ARRAY_A
        );

        $name = $customer['name'] ?? 'there';

        $variables = [
            '1' => $name,
        ];

        return match ($campaignType) {
            'loyalty_reward' => array_merge($variables, [
                '2' => $this->generateLoyaltyDiscount($customerId),
            ]),
            'price_drop' => array_merge($variables, [
                '2' => $this->getProductWithPriceDrop($customerId),
                '3' => '20%', // Discount percentage
            ]),
            'back_in_stock' => array_merge($variables, [
                '2' => $this->getBackInStockProduct($customerId),
            ]),
            'new_arrivals' => array_merge($variables, [
                '2' => $this->getNewArrivalsCount($customerId),
            ]),
            default => $variables,
        };
    }

    /**
     * Check if customer was contacted recently
     *
     * @param string $phone Customer phone
     * @return bool True if contacted recently
     */
    private function wasContactedRecently(string $phone): bool
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_reengagement_log';

        $recentContact = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tableName}
                WHERE customer_phone = %s
                AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $phone
            )
        );

        return (int) $recentContact > 0;
    }

    /**
     * Check if customer has viewed products with price drops
     *
     * @param int $customerId Customer ID
     * @return bool True if has price drops
     */
    private function hasViewedProductsWithPriceDrops(int $customerId): bool
    {
        // Implementation would check product view history and compare prices
        return false;
    }

    /**
     * Check if customer has viewed products back in stock
     *
     * @param int $customerId Customer ID
     * @return bool True if has back in stock products
     */
    private function hasViewedProductsBackInStock(int $customerId): bool
    {
        // Implementation would check product view history and stock status
        return false;
    }

    /**
     * Check if there are new arrivals in preferred categories
     *
     * @param int $customerId Customer ID
     * @return bool True if has new arrivals
     */
    private function hasNewArrivalsInPreferredCategories(int $customerId): bool
    {
        // Implementation would check product categories and recent additions
        return false;
    }

    /**
     * Generate loyalty discount code
     *
     * @param int $customerId Customer ID
     * @return string Discount code
     */
    private function generateLoyaltyDiscount(int $customerId): string
    {
        if (!function_exists('wc_create_coupon')) {
            return 'WELCOME10';
        }

        $code = 'LOYALTY' . strtoupper(substr(md5((string) $customerId . time()), 0, 6));

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_amount(15);
        $coupon->set_discount_type('percent');
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_date_expires(time() + (14 * DAY_IN_SECONDS));
        $coupon->save();

        return $code;
    }

    /**
     * Get product with price drop
     *
     * @param int $customerId Customer ID
     * @return string Product name
     */
    private function getProductWithPriceDrop(int $customerId): string
    {
        // Implementation would fetch from product views
        return 'Product Name';
    }

    /**
     * Get back in stock product
     *
     * @param int $customerId Customer ID
     * @return string Product name
     */
    private function getBackInStockProduct(int $customerId): string
    {
        // Implementation would fetch from product views
        return 'Product Name';
    }

    /**
     * Get new arrivals count
     *
     * @param int $customerId Customer ID
     * @return string Count display
     */
    private function getNewArrivalsCount(int $customerId): string
    {
        // Implementation would count new products in preferred categories
        return '5 new items';
    }

    /**
     * Track campaign send
     *
     * @param int $customerId Customer ID
     * @param string $phone Customer phone
     * @param string $campaignType Campaign type
     */
    private function trackCampaignSend(int $customerId, string $phone, string $campaignType): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_reengagement_log';

        $wpdb->insert(
            $tableName,
            [
                'customer_id' => $customerId,
                'customer_phone' => $phone,
                'campaign_type' => $campaignType,
                'sent_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * Check if reengagement is enabled
     *
     * @return bool True if enabled
     */
    private function isReengagementEnabled(): bool
    {
        return (bool) $this->settings->get('reengagement.enabled', false);
    }

    /**
     * Get campaign statistics
     *
     * @param int $days Number of days to look back
     * @return array<string, mixed> Statistics
     */
    public function getCampaignStats(int $days = 30): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_reengagement_log';

        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_sent,
                    COUNT(DISTINCT customer_id) as unique_customers,
                    campaign_type,
                    COUNT(CASE WHEN responded_at IS NOT NULL THEN 1 END) as responded_count
                FROM {$tableName}
                WHERE sent_at >= %s
                GROUP BY campaign_type",
                $since
            ),
            ARRAY_A
        );

        return $stats ?: [
            'total_sent' => 0,
            'unique_customers' => 0,
            'responded_count' => 0,
        ];
    }
}
