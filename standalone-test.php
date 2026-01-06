<?php
/**
 * Standalone Test (No WordPress Required)
 *
 * Tests the basic structure and syntax of the implementation.
 *
 * @package WhatsApp_Commerce_Hub
 */

echo "=== M00-03 Standalone Verification ===\n\n";

$all_pass = true;

// Test 1: Check files exist.
echo "1. Checking files exist:\n";
$files = array(
	'includes/class-wch-encryption.php',
	'includes/class-wch-settings.php',
	'includes/class-wch-settings-test.php',
);

foreach ( $files as $file ) {
	if ( file_exists( $file ) ) {
		echo "   ✓ $file\n";
	} else {
		echo "   ✗ $file NOT FOUND\n";
		$all_pass = false;
	}
}

// Test 2: Check PHP syntax.
echo "\n2. Checking PHP syntax:\n";
foreach ( $files as $file ) {
	$output = array();
	$return = 0;
	exec( "php -l $file 2>&1", $output, $return );
	if ( 0 === $return ) {
		echo "   ✓ $file\n";
	} else {
		echo "   ✗ $file - " . implode( "\n", $output ) . "\n";
		$all_pass = false;
	}
}

// Test 3: Check class definitions.
echo "\n3. Checking class definitions:\n";
$encryption_content = file_get_contents( 'includes/class-wch-encryption.php' );
$settings_content   = file_get_contents( 'includes/class-wch-settings.php' );

if ( strpos( $encryption_content, 'class WCH_Encryption' ) !== false ) {
	echo "   ✓ WCH_Encryption class defined\n";
} else {
	echo "   ✗ WCH_Encryption class not found\n";
	$all_pass = false;
}

if ( strpos( $settings_content, 'class WCH_Settings' ) !== false ) {
	echo "   ✓ WCH_Settings class defined\n";
} else {
	echo "   ✗ WCH_Settings class not found\n";
	$all_pass = false;
}

// Test 4: Check required methods exist.
echo "\n4. Checking required methods:\n";
$required_methods = array(
	'WCH_Settings' => array( 'get', 'set', 'get_all', 'delete', 'get_section' ),
	'WCH_Encryption' => array( 'encrypt', 'decrypt' ),
);

foreach ( $required_methods as $class => $methods ) {
	foreach ( $methods as $method ) {
		if ( preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/', $settings_content ) ||
		     preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/', $encryption_content ) ) {
			echo "   ✓ $class::$method()\n";
		} else {
			echo "   ✗ $class::$method() not found\n";
			$all_pass = false;
		}
	}
}

// Test 5: Check encryption method.
echo "\n5. Checking encryption implementation:\n";
if ( strpos( $encryption_content, 'aes-256-cbc' ) !== false ) {
	echo "   ✓ Uses AES-256-CBC encryption\n";
} else {
	echo "   ✗ AES-256-CBC not found\n";
	$all_pass = false;
}

if ( strpos( $encryption_content, "wp_salt( 'auth' )" ) !== false ) {
	echo "   ✓ Uses wp_salt('auth') as key\n";
} else {
	echo "   ✗ wp_salt('auth') not found\n";
	$all_pass = false;
}

// Test 6: Check settings storage.
echo "\n6. Checking settings storage:\n";
if ( strpos( $settings_content, 'wch_settings' ) !== false ) {
	echo "   ✓ Uses 'wch_settings' option name\n";
} else {
	echo "   ✗ 'wch_settings' option name not found\n";
	$all_pass = false;
}

// Test 7: Check encrypted fields.
echo "\n7. Checking encrypted fields:\n";
$encrypted_fields = array( 'api.access_token', 'ai.openai_api_key' );
foreach ( $encrypted_fields as $field ) {
	if ( strpos( $settings_content, $field ) !== false ) {
		echo "   ✓ $field marked for encryption\n";
	} else {
		echo "   ✗ $field not found\n";
		$all_pass = false;
	}
}

// Test 8: Check filter support.
echo "\n8. Checking filter support:\n";
if ( strpos( $settings_content, "apply_filters( 'wch_settings_defaults'" ) !== false ) {
	echo "   ✓ 'wch_settings_defaults' filter implemented\n";
} else {
	echo "   ✗ 'wch_settings_defaults' filter not found\n";
	$all_pass = false;
}

// Test 9: Check singleton pattern.
echo "\n9. Checking singleton pattern:\n";
if ( strpos( $settings_content, 'getInstance()' ) !== false &&
     strpos( $settings_content, 'private function __construct()' ) !== false ) {
	echo "   ✓ Singleton pattern implemented\n";
} else {
	echo "   ✗ Singleton pattern not complete\n";
	$all_pass = false;
}

// Test 10: Check all sections exist.
echo "\n10. Checking all required sections:\n";
$required_sections = array( 'api', 'general', 'catalog', 'checkout', 'notifications', 'ai' );
foreach ( $required_sections as $section ) {
	if ( preg_match( "/'" . preg_quote( $section, '/' ) . "'\s*=>/", $settings_content ) ) {
		echo "   ✓ Section '$section' defined\n";
	} else {
		echo "   ✗ Section '$section' not found\n";
		$all_pass = false;
	}
}

// Summary.
echo "\n" . str_repeat( '=', 50 ) . "\n";
if ( $all_pass ) {
	echo "✓ ALL CHECKS PASSED\n";
	echo "Status: DONE\n";
} else {
	echo "✗ SOME CHECKS FAILED\n";
	echo "Status: NEEDS REVIEW\n";
}
echo str_repeat( '=', 50 ) . "\n";
