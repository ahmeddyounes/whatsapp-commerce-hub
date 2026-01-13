<?php
/**
 * Uninstall handler
 *
 * Runs when the plugin is uninstalled via WordPress admin.
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
