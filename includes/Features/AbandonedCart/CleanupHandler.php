<?php
/**
 * Cart Cleanup Handler
 *
 * Handles cleanup of expired carts (older than 72 hours).
 * Runs hourly via scheduled action.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\AbandonedCart;

use WhatsAppCommerceHub\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cleanup Handler Class
 *
 * Removes expired and stale carts from the database.
 */
class CleanupHandler
{
    /**
     * Cart expiry time in hours
     */
    private const CART_EXPIRY_HOURS = 72;

    /**
     * Constructor
     *
     * @param Logger $logger Logger instance
     */
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * Process cart cleanup job
     *
     * Deletes carts that have been inactive for more than 72 hours.
     *
     * @param array<string, mixed> $args Job arguments (not used)
     */
    public function process(array $args = []): void
    {
        global $wpdb;

        $this->logger->info('Starting cart cleanup job');

        $tableName = $wpdb->prefix . 'wch_carts';

        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) !== $tableName) {
            $this->logger->warning('Cart table does not exist, skipping cleanup');
            return;
        }

        $expiryTimestamp = time() - (self::CART_EXPIRY_HOURS * HOUR_IN_SECONDS);
        $expiryDate = gmdate('Y-m-d H:i:s', $expiryTimestamp);

        // Count expired carts
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tableName} WHERE updated_at < %s AND status = %s",
                $expiryDate,
                'active'
            )
        );

        if ($count === 0) {
            $this->logger->info('No expired carts to clean up');
            return;
        }

        // Delete expired carts
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tableName} WHERE updated_at < %s AND status = %s",
                $expiryDate,
                'active'
            )
        );

        if ($deleted === false) {
            $this->logger->error('Failed to delete expired carts', [
                'error' => $wpdb->last_error,
            ]);
            return;
        }

        $this->logger->info('Cart cleanup completed', [
            'expired_count' => $count,
            'deleted_count' => $deleted,
        ]);

        // Also clean up abandoned carts older than 30 days
        $this->cleanupOldAbandonedCarts();
    }

    /**
     * Clean up old abandoned carts
     *
     * Removes abandoned carts older than 30 days to prevent table bloat.
     */
    private function cleanupOldAbandonedCarts(): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_carts';

        $threshold = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tableName} WHERE abandoned_at < %s AND status = %s",
                $threshold,
                'abandoned'
            )
        );

        if ($deleted && $deleted > 0) {
            $this->logger->info('Cleaned up old abandoned carts', [
                'deleted_count' => $deleted,
            ]);
        }
    }

    /**
     * Clean up recovered carts
     *
     * Archives or removes recovered carts older than 90 days.
     */
    public function cleanupRecoveredCarts(): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_carts';

        $threshold = gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS));

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tableName} WHERE recovered_at < %s AND status = %s",
                $threshold,
                'recovered'
            )
        );

        if ($deleted && $deleted > 0) {
            $this->logger->info('Cleaned up old recovered carts', [
                'deleted_count' => $deleted,
            ]);
        }
    }

    /**
     * Get cleanup statistics
     *
     * @return array<string, int> Statistics about cart cleanup
     */
    public function getCleanupStats(): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_carts';

        $expiryDate = gmdate('Y-m-d H:i:s', time() - (self::CART_EXPIRY_HOURS * HOUR_IN_SECONDS));
        $abandonedThreshold = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $recoveredThreshold = gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS));

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    SUM(CASE WHEN updated_at < %s AND status = 'active' THEN 1 ELSE 0 END) as expired_active,
                    SUM(CASE WHEN abandoned_at < %s AND status = 'abandoned' THEN 1 ELSE 0 END) as old_abandoned,
                    SUM(CASE WHEN recovered_at < %s AND status = 'recovered' THEN 1 ELSE 0 END) as old_recovered
                FROM {$tableName}",
                $expiryDate,
                $abandonedThreshold,
                $recoveredThreshold
            ),
            ARRAY_A
        );

        return [
            'expired_active' => (int) ($stats['expired_active'] ?? 0),
            'old_abandoned' => (int) ($stats['old_abandoned'] ?? 0),
            'old_recovered' => (int) ($stats['old_recovered'] ?? 0),
        ];
    }
}
