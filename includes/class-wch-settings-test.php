<?php
/**
 * Settings Test Class
 *
 * Tests for WCH_Settings and WCH_Encryption classes.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Settings_Test
 */
class WCH_Settings_Test {
	/**
	 * Run all tests.
	 *
	 * @return array Test results.
	 */
	public static function run_tests() {
		$results = array();

		// Test 1: Encryption and Decryption.
		$results['test_encryption'] = self::test_encryption();

		// Test 2: Settings persistence.
		$results['test_settings_persistence'] = self::test_settings_persistence();

		// Test 3: Encrypted fields cannot be read directly.
		$results['test_encrypted_fields_protected'] = self::test_encrypted_fields_protected();

		// Test 4: Default values.
		$results['test_default_values'] = self::test_default_values();

		// Test 5: Type validation.
		$results['test_type_validation'] = self::test_type_validation();

		// Test 6: Section operations.
		$results['test_section_operations'] = self::test_section_operations();

		// Test 7: Delete operations.
		$results['test_delete_operations'] = self::test_delete_operations();

		return $results;
	}

	/**
	 * Test encryption and decryption.
	 *
	 * @return array Test result.
	 */
	private static function test_encryption() {
		$encryption = new WCH_Encryption();
		$test_value = 'my_secret_api_key_12345';

		$encrypted = $encryption->encrypt( $test_value );
		if ( false === $encrypted || empty( $encrypted ) ) {
			return array(
				'status'  => 'fail',
				'message' => 'Encryption failed',
			);
		}

		if ( $encrypted === $test_value ) {
			return array(
				'status'  => 'fail',
				'message' => 'Encrypted value should differ from original',
			);
		}

		$decrypted = $encryption->decrypt( $encrypted );
		if ( $decrypted !== $test_value ) {
			return array(
				'status'  => 'fail',
				'message' => 'Decryption failed or value mismatch',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Encryption and decryption working correctly',
		);
	}

	/**
	 * Test settings persistence across requests.
	 *
	 * @return array Test result.
	 */
	private static function test_settings_persistence() {
		$settings = WCH_Settings::getInstance();

		// Set a value.
		$settings->set( 'general.business_name', 'Test Store' );

		// Clear cache to simulate new request.
		$settings_new = WCH_Settings::getInstance();

		// Get the value.
		$value = $settings_new->get( 'general.business_name' );

		if ( $value !== 'Test Store' ) {
			return array(
				'status'  => 'fail',
				'message' => 'Settings do not persist across requests',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Settings persist correctly',
		);
	}

	/**
	 * Test that encrypted fields cannot be read directly from database.
	 *
	 * @return array Test result.
	 */
	private static function test_encrypted_fields_protected() {
		$settings = WCH_Settings::getInstance();

		// Set an encrypted field.
		$test_token = 'secret_access_token_12345';
		$settings->set( 'api.access_token', $test_token );

		// Get raw value from database.
		$raw_settings = get_option( 'wch_settings', array() );

		if ( ! isset( $raw_settings['api']['access_token'] ) ) {
			return array(
				'status'  => 'fail',
				'message' => 'Setting not saved to database',
			);
		}

		$raw_value = $raw_settings['api']['access_token'];

		// Raw value should be encrypted (not equal to original).
		if ( $raw_value === $test_token ) {
			return array(
				'status'  => 'fail',
				'message' => 'Encrypted field stored in plain text',
			);
		}

		// Getting through settings class should decrypt it.
		$decrypted_value = $settings->get( 'api.access_token' );
		if ( $decrypted_value !== $test_token ) {
			return array(
				'status'  => 'fail',
				'message' => 'Decryption through settings class failed',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Encrypted fields are protected in database',
		);
	}

	/**
	 * Test default values.
	 *
	 * @return array Test result.
	 */
	private static function test_default_values() {
		$settings = WCH_Settings::getInstance();

		// Delete the setting if it exists.
		$settings->delete( 'api.api_version' );

		// Get unset value - should return default.
		$value = $settings->get( 'api.api_version' );

		if ( $value !== 'v18.0' ) {
			return array(
				'status'  => 'fail',
				'message' => 'Default value not returned for unset key',
			);
		}

		// Test with custom default.
		$value = $settings->get( 'api.nonexistent_key', 'custom_default' );
		if ( $value !== 'custom_default' ) {
			return array(
				'status'  => 'fail',
				'message' => 'Custom default not returned',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Default values working correctly',
		);
	}

	/**
	 * Test type validation.
	 *
	 * @return array Test result.
	 */
	private static function test_type_validation() {
		$settings = WCH_Settings::getInstance();

		// Try to set a boolean field with wrong type.
		$result = $settings->set( 'general.enable_bot', 'not_a_boolean' );
		if ( true === $result ) {
			return array(
				'status'  => 'fail',
				'message' => 'Type validation not working - accepted wrong type',
			);
		}

		// Set with correct type.
		$result = $settings->set( 'general.enable_bot', true );
		if ( false === $result ) {
			return array(
				'status'  => 'fail',
				'message' => 'Type validation rejected correct type',
			);
		}

		// Test integer validation.
		$result = $settings->set( 'notifications.abandoned_cart_delay_hours', 'not_an_int' );
		if ( true === $result ) {
			return array(
				'status'  => 'fail',
				'message' => 'Integer validation failed',
			);
		}

		$result = $settings->set( 'notifications.abandoned_cart_delay_hours', 48 );
		if ( false === $result ) {
			return array(
				'status'  => 'fail',
				'message' => 'Integer validation rejected valid value',
			);
		}

		// Test float validation.
		$result = $settings->set( 'checkout.cod_extra_charge', 5.99 );
		if ( false === $result ) {
			return array(
				'status'  => 'fail',
				'message' => 'Float validation rejected valid value',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Type validation working correctly',
		);
	}

	/**
	 * Test section operations.
	 *
	 * @return array Test result.
	 */
	private static function test_section_operations() {
		$settings = WCH_Settings::getInstance();

		// Set multiple values in a section.
		$settings->set( 'catalog.sync_enabled', true );
		$settings->set( 'catalog.include_out_of_stock', false );
		$settings->set( 'catalog.price_format', '%1$s%2$s' );

		// Get the entire section.
		$section = $settings->get_section( 'catalog' );

		if ( ! is_array( $section ) ) {
			return array(
				'status'  => 'fail',
				'message' => 'get_section did not return an array',
			);
		}

		if ( ! isset( $section['sync_enabled'] ) || $section['sync_enabled'] !== true ) {
			return array(
				'status'  => 'fail',
				'message' => 'Section value incorrect',
			);
		}

		// Test getting non-existent section (should return defaults).
		$settings->delete( 'notifications.order_confirmation' );
		$section = $settings->get_section( 'notifications' );

		if ( ! isset( $section['order_confirmation'] ) ) {
			return array(
				'status'  => 'fail',
				'message' => 'Section did not return defaults',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Section operations working correctly',
		);
	}

	/**
	 * Test delete operations.
	 *
	 * @return array Test result.
	 */
	private static function test_delete_operations() {
		$settings = WCH_Settings::getInstance();

		// Set a value.
		$settings->set( 'general.business_name', 'Test Store' );

		// Verify it's set.
		$value = $settings->get( 'general.business_name' );
		if ( $value !== 'Test Store' ) {
			return array(
				'status'  => 'fail',
				'message' => 'Setting not saved before delete',
			);
		}

		// Delete the value.
		$result = $settings->delete( 'general.business_name' );
		if ( false === $result ) {
			return array(
				'status'  => 'fail',
				'message' => 'Delete operation failed',
			);
		}

		// Verify it's deleted (should return default).
		$value = $settings->get( 'general.business_name' );
		if ( $value === 'Test Store' ) {
			return array(
				'status'  => 'fail',
				'message' => 'Setting not deleted',
			);
		}

		return array(
			'status'  => 'pass',
			'message' => 'Delete operations working correctly',
		);
	}

	/**
	 * Display test results.
	 *
	 * @param array $results Test results.
	 */
	public static function display_results( $results ) {
		echo '<div style="font-family: monospace; padding: 20px;">';
		echo '<h2>WCH Settings Test Results</h2>';

		$passed = 0;
		$failed = 0;

		foreach ( $results as $test_name => $result ) {
			$color = 'pass' === $result['status'] ? 'green' : 'red';
			$icon  = 'pass' === $result['status'] ? '✓' : '✗';

			if ( 'pass' === $result['status'] ) {
				$passed++;
			} else {
				$failed++;
			}

			echo sprintf(
				'<div style="color: %s; margin: 10px 0;"><strong>%s %s:</strong> %s</div>',
				esc_attr( $color ),
				esc_html( $icon ),
				esc_html( $test_name ),
				esc_html( $result['message'] )
			);
		}

		echo '<hr>';
		echo sprintf(
			'<div><strong>Total:</strong> %d tests | <span style="color: green;">%d passed</span> | <span style="color: red;">%d failed</span></div>',
			count( $results ),
			$passed,
			$failed
		);
		echo '</div>';
	}
}
