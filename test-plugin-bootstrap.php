#!/usr/bin/env php
<?php
/**
 * Simple test script to verify plugin bootstrap functionality.
 * This is a standalone test and does not require WordPress.
 *
 * @package WhatsApp_Commerce_Hub
 */

echo "=== WhatsApp Commerce Hub Bootstrap Test ===\n\n";

// Simulate WordPress environment constants.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Mock WordPress functions for testing.
if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $function ) {
		// Mock function for testing.
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $function ) {
		// Mock function for testing.
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $function, $priority = 10 ) {
		// Mock function for testing.
	}
}

// Load the plugin file.
require_once __DIR__ . '/whatsapp-commerce-hub.php';

// Test 1: Verify constants are defined.
echo "Test 1: Checking if constants are defined...\n";
$constants = [ 'WCH_VERSION', 'WCH_PLUGIN_DIR', 'WCH_PLUGIN_URL', 'WCH_PLUGIN_BASENAME' ];
$constants_ok = true;

foreach ( $constants as $constant ) {
	if ( defined( $constant ) ) {
		echo "  ✓ $constant is defined: " . constant( $constant ) . "\n";
	} else {
		echo "  ✗ $constant is NOT defined\n";
		$constants_ok = false;
	}
}

if ( $constants_ok ) {
	echo "  Result: PASS\n\n";
} else {
	echo "  Result: FAIL\n\n";
	exit( 1 );
}

// Test 2: Verify autoloader is registered.
echo "Test 2: Checking if autoloader is registered...\n";
$autoload_functions = spl_autoload_functions();
$autoloader_found = false;

foreach ( $autoload_functions as $function ) {
	if ( $function === 'wch_autoloader' ) {
		$autoloader_found = true;
		break;
	}
}

if ( $autoloader_found ) {
	echo "  ✓ wch_autoloader is registered\n";
	echo "  Result: PASS\n\n";
} else {
	echo "  ✗ wch_autoloader is NOT registered\n";
	echo "  Result: FAIL\n\n";
	exit( 1 );
}

// Test 3: Verify autoloader can load classes.
echo "Test 3: Testing autoloader with WCH_Test class...\n";
try {
	// This should trigger the autoloader.
	if ( class_exists( 'WCH_Test' ) ) {
		$test = new WCH_Test();
		$result = $test->test_autoloader();
		echo "  ✓ WCH_Test class loaded successfully\n";
		echo "  ✓ Test method result: $result\n";
		echo "  Result: PASS\n\n";
	} else {
		echo "  ✗ WCH_Test class could not be loaded\n";
		echo "  Result: FAIL\n\n";
		exit( 1 );
	}
} catch ( Exception $e ) {
	echo "  ✗ Exception: " . $e->getMessage() . "\n";
	echo "  Result: FAIL\n\n";
	exit( 1 );
}

// Test 4: Verify WCH_Plugin singleton.
echo "Test 4: Testing WCH_Plugin singleton pattern...\n";
try {
	// Note: We can't fully test this without WordPress functions,
	// but we can verify the class exists and getInstance is callable.
	if ( class_exists( 'WCH_Plugin' ) ) {
		echo "  ✓ WCH_Plugin class exists\n";
		if ( method_exists( 'WCH_Plugin', 'getInstance' ) ) {
			echo "  ✓ getInstance method exists\n";
			echo "  Result: PASS\n\n";
		} else {
			echo "  ✗ getInstance method not found\n";
			echo "  Result: FAIL\n\n";
			exit( 1 );
		}
	} else {
		echo "  ✗ WCH_Plugin class not found\n";
		echo "  Result: FAIL\n\n";
		exit( 1 );
	}
} catch ( Exception $e ) {
	echo "  ✗ Exception: " . $e->getMessage() . "\n";
	echo "  Result: FAIL\n\n";
	exit( 1 );
}

// Test 5: Verify activation/deactivation hooks are registered.
echo "Test 5: Checking if activation/deactivation functions exist...\n";
$functions_ok = true;

if ( function_exists( 'wch_activate_plugin' ) ) {
	echo "  ✓ wch_activate_plugin function exists\n";
} else {
	echo "  ✗ wch_activate_plugin function not found\n";
	$functions_ok = false;
}

if ( function_exists( 'wch_deactivate_plugin' ) ) {
	echo "  ✓ wch_deactivate_plugin function exists\n";
} else {
	echo "  ✗ wch_deactivate_plugin function not found\n";
	$functions_ok = false;
}

if ( function_exists( 'wch_check_requirements' ) ) {
	echo "  ✓ wch_check_requirements function exists\n";
} else {
	echo "  ✗ wch_check_requirements function not found\n";
	$functions_ok = false;
}

if ( $functions_ok ) {
	echo "  Result: PASS\n\n";
} else {
	echo "  Result: FAIL\n\n";
	exit( 1 );
}

echo "=== All Tests Passed! ===\n";
echo "The plugin bootstrap is working correctly.\n";
exit( 0 );
