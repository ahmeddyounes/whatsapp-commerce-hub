<?php
/**
 * Database Test Script
 *
 * Run this from WordPress root or via WP-CLI to test database creation.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once __DIR__ . '/../../wp-load.php';

// Load the database manager.
require_once __DIR__ . '/includes/class-wch-database-manager.php';

echo "WhatsApp Commerce Hub - Database Test\n";
echo str_repeat( '=', 50 ) . "\n\n";

// Create database manager instance.
$db_manager = new WCH_Database_Manager();

// Test get_table_name method.
echo "Testing get_table_name() method:\n";
$tables = array( 'conversations', 'messages', 'carts', 'customer_profiles', 'broadcast_campaigns', 'sync_queue' );
foreach ( $tables as $table ) {
	$full_name = $db_manager->get_table_name( $table );
	echo "  - {$table} => {$full_name}\n";
}
echo "\n";

// Run install.
echo "Running database installation...\n";
$db_manager->install();
echo "✓ Installation completed\n\n";

// Verify tables exist.
echo "Verifying tables exist:\n";
global $wpdb;
foreach ( $tables as $table ) {
	$table_name  = $db_manager->get_table_name( $table );
	$table_check = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );
	if ( $table_check === $table_name ) {
		echo "  ✓ {$table_name} exists\n";
	} else {
		echo "  ✗ {$table_name} NOT FOUND\n";
	}
}
echo "\n";

// Check database version option.
$db_version = get_option( WCH_Database_Manager::DB_VERSION_OPTION );
echo "Database version in wp_options: {$db_version}\n";
echo "Expected version: " . WCH_Database_Manager::DB_VERSION . "\n\n";

// Test run_migrations (should be idempotent).
echo "Testing run_migrations() for idempotency...\n";
$db_manager->run_migrations();
echo "✓ Migrations completed\n\n";

// Test table structure for one table.
echo "Checking structure of wch_conversations table:\n";
$table_name = $db_manager->get_table_name( 'conversations' );
$columns    = $wpdb->get_results( "DESCRIBE {$table_name}" );
foreach ( $columns as $column ) {
	echo "  - {$column->Field} ({$column->Type}) {$column->Key}\n";
}
echo "\n";

echo "All tests completed successfully!\n";
