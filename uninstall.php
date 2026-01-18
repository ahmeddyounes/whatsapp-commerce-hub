<?php
/**
 * Uninstall handler
 *
 * Runs when the plugin is uninstalled via WordPress admin.
 *
 * This file is executed when a user deletes the plugin from the WordPress admin.
 * It performs a complete cleanup of all plugin data including:
 *
 * - All database tables (conversations, messages, carts, customer_profiles, etc.)
 * - All plugin options (settings, API keys, configuration)
 * - All transients (temporary cache data)
 * - All post meta fields (product sync metadata)
 * - All scheduled cron jobs
 *
 * WARNING: This action is irreversible. All plugin data will be permanently deleted.
 *
 * Data Removed:
 * - Tables: wch_conversations, wch_messages, wch_carts, wch_customer_profiles,
 *   wch_broadcast_recipients, wch_sync_queue, wch_notification_log,
 *   wch_product_views, wch_reengagement, wch_rate_limits, wch_security_log,
 *   wch_webhook_idempotency, wch_webhook_events
 * - Options: All options prefixed with 'wch_' including settings, API keys,
 *   encryption keys, and payment gateway configurations
 * - Transients: All temporary data prefixed with 'wch_'
 * - Post Meta: All product metadata prefixed with '_wch_' (sync status, timestamps)
 * - Scheduled Events: All cron jobs for cleanup, sync, and recovery tasks
 *
 * Data NOT Removed:
 * - WooCommerce orders created through WhatsApp (preserved as regular orders)
 * - WooCommerce customers (preserved as regular customers)
 * - WooCommerce products (only WhatsApp catalog sync metadata is removed)
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly or not uninstalling.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the database manager class.
require_once plugin_dir_path( __FILE__ ) . 'includes/Infrastructure/Database/DatabaseManager.php';

// Create instance and run uninstall.
$db_manager = new \WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager();
$db_manager->uninstall();
