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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wch-database-manager.php';

// Create instance and run uninstall.
$db_manager = new WCH_Database_Manager();
$db_manager->uninstall();
