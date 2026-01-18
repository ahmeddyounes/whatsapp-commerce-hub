<?php
/**
 * Test script to verify database table creation for missing tables.
 *
 * This script tests that the four new tables can be created and dropped:
 * - wch_rate_limits
 * - wch_security_log
 * - wch_webhook_idempotency
 * - wch_webhook_events
 *
 * Run this from WordPress root or via WP-CLI:
 * wp eval-file test-db-tables.php
 */

// Bootstrap WordPress if not already loaded.
if ( ! defined( 'ABSPATH' ) ) {
	// Attempt to load WordPress from common locations.
	$wp_load_paths = [
		__DIR__ . '/wp-load.php',
		__DIR__ . '/../../../wp-load.php',
		__DIR__ . '/../../../../wp-load.php',
	];

	foreach ( $wp_load_paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			break;
		}
	}

	if ( ! defined( 'ABSPATH' ) ) {
		die( "Error: Could not load WordPress. Run this script from WordPress root or use WP-CLI.\n" );
	}
}

// Verify the DatabaseManager class is available.
if ( ! class_exists( 'WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager' ) ) {
	die( "Error: DatabaseManager class not found. Ensure the plugin is activated.\n" );
}

use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;

echo "=== Testing Database Table Creation ===\n\n";

global $wpdb;
$manager = new DatabaseManager( $wpdb );

// Tables to test
$tables_to_test = [
	'rate_limits',
	'security_log',
	'webhook_idempotency',
	'webhook_events',
];

echo "Testing table existence before install...\n";
foreach ( $tables_to_test as $table ) {
	$exists = $manager->tableExists( $table );
	echo "  - {$table}: " . ( $exists ? 'EXISTS' : 'NOT FOUND' ) . "\n";
}

echo "\nRunning install to create tables...\n";
try {
	$manager->install();
	echo "Install completed successfully.\n";
} catch ( Exception $e ) {
	die( "Error during install: " . $e->getMessage() . "\n" );
}

echo "\nVerifying tables were created...\n";
$all_exist = true;
foreach ( $tables_to_test as $table ) {
	$exists = $manager->tableExists( $table );
	echo "  - {$table}: " . ( $exists ? 'EXISTS ✓' : 'NOT FOUND ✗' ) . "\n";
	if ( ! $exists ) {
		$all_exist = false;
	}
}

if ( $all_exist ) {
	echo "\n✓ All tables created successfully!\n";
} else {
	echo "\n✗ Some tables were not created.\n";
	exit( 1 );
}

// Test table structure by describing each table
echo "\nVerifying table structures...\n";
foreach ( $tables_to_test as $table ) {
	$full_table = $wpdb->prefix . 'wch_' . $table;
	$columns = $wpdb->get_results( "DESCRIBE {$full_table}" );
	echo "  - {$table}: " . count( $columns ) . " columns\n";

	if ( empty( $columns ) ) {
		echo "    ✗ No columns found!\n";
		$all_exist = false;
	}
}

echo "\n=== Test Complete ===\n";
exit( $all_exist ? 0 : 1 );
