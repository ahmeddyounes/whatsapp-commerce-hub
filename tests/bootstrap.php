<?php
/**
 * PHPUnit Bootstrap File
 *
 * @package WhatsApp_Commerce_Hub
 */

// Define test environment constant.
define( 'WCH_TESTS', true );

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Detect WP_TESTS_DIR - can be set via environment variable or default to /tmp/wordpress-tests-lib.
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
	$wp_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
if ( ! file_exists( "$wp_tests_dir/includes/functions.php" ) ) {
	echo "Could not find $wp_tests_dir/includes/functions.php\n";
	echo "Please set WP_TESTS_DIR environment variable or install WordPress test suite.\n";
	echo "See: https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/\n";
	exit( 1 );
}

require_once "$wp_tests_dir/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	// Load WooCommerce.
	$wc_plugin = dirname( __DIR__ ) . '/../woocommerce/woocommerce.php';
	if ( file_exists( $wc_plugin ) ) {
		require_once $wc_plugin;
	} else {
		// Try alternative location.
		$wc_plugin = '/tmp/wordpress/wp-content/plugins/woocommerce/woocommerce.php';
		if ( file_exists( $wc_plugin ) ) {
			require_once $wc_plugin;
		}
	}

	// Load our plugin.
	require dirname( __DIR__ ) . '/whatsapp-commerce-hub.php';

	// Activate the plugin programmatically.
	activate_plugin( plugin_basename( dirname( __DIR__ ) . '/whatsapp-commerce-hub.php' ) );
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Load WooCommerce testing framework if available.
$wc_tests_framework = dirname( __DIR__ ) . '/../woocommerce/tests/legacy/framework/class-wc-unit-test-case.php';
if ( file_exists( $wc_tests_framework ) ) {
	require_once $wc_tests_framework;
}

// Start up the WP testing environment.
require "$wp_tests_dir/includes/bootstrap.php";

// Load Brain Monkey for mocking.
if ( class_exists( 'Brain\Monkey' ) ) {
	Brain\Monkey\setUp();
}

// Load base test case classes.
require_once __DIR__ . '/class-wch-unit-test-case.php';
require_once __DIR__ . '/class-wch-integration-test-case.php';
