<?php
/**
 * Phase 2 Verification Script
 *
 * Verifies all Phase 2 core infrastructure migrations:
 * - Logger
 * - ErrorHandler
 * - Encryption
 * - DatabaseManager
 * - SettingsManager
 *
 * @package WhatsApp_Commerce_Hub
 */

// Simulate WordPress environment minimally.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'WCH_PLUGIN_DIR' ) ) {
	define( 'WCH_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'WCH_PLUGIN_FILE' ) ) {
	define( 'WCH_PLUGIN_FILE', __FILE__ );
}

// Load Composer autoloader.
require_once __DIR__ . '/vendor/autoload.php';

// Mock WordPress functions needed by our classes.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
		// No-op for testing.
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'Test Site';
	}
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() {
		return 'UTC';
	}
}
if ( ! function_exists( 'get_woocommerce_price_format' ) ) {
	function get_woocommerce_price_format() {
		return '%1$s%2$s';
	}
}
if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
	function get_woocommerce_currency_symbol( $currency = '' ) {
		return '$';
	}
}

// Colors for terminal output.
function green( $text ) {
	return "\033[32m" . $text . "\033[0m";
}

function red( $text ) {
	return "\033[31m" . $text . "\033[0m";
}

function yellow( $text ) {
	return "\033[33m" . $text . "\033[0m";
}

function blue( $text ) {
	return "\033[34m" . $text . "\033[0m";
}

echo blue( "=== Phase 2 Core Infrastructure Verification ===\n\n" );

$results = [];

// Test 1: Logger class exists and is properly namespaced.
echo "Testing Logger migration...\n";
try {
	$loggerClass = 'WhatsAppCommerceHub\Core\Logger';
	if ( ! class_exists( $loggerClass ) ) {
		throw new Exception( "Class $loggerClass does not exist" );
	}

	$reflection = new ReflectionClass( $loggerClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Core/Logger.php' ) === false ) {
		throw new Exception( "Logger is not in correct location: $filename" );
	}

	// Check for strict types.
	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'Logger does not declare strict types' );
	}

	echo green( "✓ Logger: Properly migrated\n" );
	$results['logger'] = true;
} catch ( Exception $e ) {
	echo red( "✗ Logger: " . $e->getMessage() . "\n" );
	$results['logger'] = false;
}

// Test 2: ErrorHandler class exists and is properly namespaced.
echo "Testing ErrorHandler migration...\n";
try {
	$errorHandlerClass = 'WhatsAppCommerceHub\Core\ErrorHandler';
	if ( ! class_exists( $errorHandlerClass ) ) {
		throw new Exception( "Class $errorHandlerClass does not exist" );
	}

	$reflection = new ReflectionClass( $errorHandlerClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Core/ErrorHandler.php' ) === false ) {
		throw new Exception( "ErrorHandler is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'ErrorHandler does not declare strict types' );
	}

	echo green( "✓ ErrorHandler: Properly migrated\n" );
	$results['error_handler'] = true;
} catch ( Exception $e ) {
	echo red( "✗ ErrorHandler: " . $e->getMessage() . "\n" );
	$results['error_handler'] = false;
}

// Test 3: Encryption class exists and is properly namespaced.
echo "Testing Encryption migration...\n";
try {
	$encryptionClass = 'WhatsAppCommerceHub\Infrastructure\Security\Encryption';
	if ( ! class_exists( $encryptionClass ) ) {
		throw new Exception( "Class $encryptionClass does not exist" );
	}

	$reflection = new ReflectionClass( $encryptionClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Infrastructure/Security/Encryption.php' ) === false ) {
		throw new Exception( "Encryption is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'Encryption does not declare strict types' );
	}

	// Check for new methods.
	if ( ! $reflection->hasMethod( 'encryptArray' ) ) {
		throw new Exception( 'Encryption missing encryptArray method' );
	}
	if ( ! $reflection->hasMethod( 'rotateKey' ) ) {
		throw new Exception( 'Encryption missing rotateKey method' );
	}

	echo green( "✓ Encryption: Properly migrated with enhancements\n" );
	$results['encryption'] = true;
} catch ( Exception $e ) {
	echo red( "✗ Encryption: " . $e->getMessage() . "\n" );
	$results['encryption'] = false;
}

// Test 4: DatabaseManager class exists and is properly namespaced.
echo "Testing DatabaseManager migration...\n";
try {
	$dbManagerClass = 'WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager';
	if ( ! class_exists( $dbManagerClass ) ) {
		throw new Exception( "Class $dbManagerClass does not exist" );
	}

	$reflection = new ReflectionClass( $dbManagerClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Infrastructure/Database/DatabaseManager.php' ) === false ) {
		throw new Exception( "DatabaseManager is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'DatabaseManager does not declare strict types' );
	}

	// Check for camelCase methods.
	if ( ! $reflection->hasMethod( 'install' ) ) {
		throw new Exception( 'DatabaseManager missing install method' );
	}
	if ( ! $reflection->hasMethod( 'getTableName' ) ) {
		throw new Exception( 'DatabaseManager missing getTableName method' );
	}

	echo green( "✓ DatabaseManager: Properly migrated\n" );
	$results['database_manager'] = true;
} catch ( Exception $e ) {
	echo red( "✗ DatabaseManager: " . $e->getMessage() . "\n" );
	$results['database_manager'] = false;
}

// Test 5: SettingsManager class exists and is properly namespaced.
echo "Testing SettingsManager migration...\n";
try {
	$settingsManagerClass = 'WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager';
	if ( ! class_exists( $settingsManagerClass ) ) {
		throw new Exception( "Class $settingsManagerClass does not exist" );
	}

	$reflection = new ReflectionClass( $settingsManagerClass );
	$filename   = $reflection->getFileName();

	if ( strpos( $filename, 'includes/Infrastructure/Configuration/SettingsManager.php' ) === false ) {
		throw new Exception( "SettingsManager is not in correct location: $filename" );
	}

	$contents = file_get_contents( $filename );
	if ( strpos( $contents, 'declare(strict_types=1)' ) === false ) {
		throw new Exception( 'SettingsManager does not declare strict types' );
	}

	// Check for consolidated methods.
	if ( ! $reflection->hasMethod( 'get' ) ) {
		throw new Exception( 'SettingsManager missing get method' );
	}
	if ( ! $reflection->hasMethod( 'getAll' ) ) {
		throw new Exception( 'SettingsManager missing getAll method' );
	}
	if ( ! $reflection->hasMethod( 'isConfigured' ) ) {
		throw new Exception( 'SettingsManager missing isConfigured method' );
	}

	echo green( "✓ SettingsManager: Properly migrated\n" );
	$results['settings_manager'] = true;
} catch ( Exception $e ) {
	echo red( "✗ SettingsManager: " . $e->getMessage() . "\n" );
	$results['settings_manager'] = false;
}

// Test 6: Legacy class mapper has correct mappings.
echo "\nTesting LegacyClassMapper...\n";
try {
	$mapperClass = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
	if ( ! class_exists( $mapperClass ) ) {
		throw new Exception( "Class $mapperClass does not exist" );
	}

	$mapping = $mapperClass::getMapping();

	$expectedMappings = [
		'WCH_Logger'           => 'WhatsAppCommerceHub\Core\Logger',
		'WCH_Error_Handler'    => 'WhatsAppCommerceHub\Core\ErrorHandler',
		'WCH_Encryption'       => 'WhatsAppCommerceHub\Infrastructure\Security\Encryption',
		'WCH_Database_Manager' => 'WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager',
		'WCH_Settings'         => 'WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager',
	];

	foreach ( $expectedMappings as $legacy => $modern ) {
		if ( ! isset( $mapping[ $legacy ] ) ) {
			throw new Exception( "Missing mapping for $legacy" );
		}
		if ( $mapping[ $legacy ] !== $modern ) {
			throw new Exception( "Incorrect mapping for $legacy: expected $modern, got {$mapping[$legacy]}" );
		}
	}

	echo green( "✓ LegacyClassMapper: All Phase 2 mappings correct\n" );
	$results['legacy_mapper'] = true;
} catch ( Exception $e ) {
	echo red( "✗ LegacyClassMapper: " . $e->getMessage() . "\n" );
	$results['legacy_mapper'] = false;
}

// Summary.
echo "\n" . blue( "=== Summary ===\n" );
$passed = count( array_filter( $results ) );
$total  = count( $results );

if ( $passed === $total ) {
	echo green( "All tests passed! ($passed/$total)\n" );
	echo green( "Phase 2 migration is complete and verified.\n" );
	exit( 0 );
} else {
	echo red( "Some tests failed. ($passed/$total passed)\n" );
	echo yellow( "Please review the errors above.\n" );
	exit( 1 );
}
