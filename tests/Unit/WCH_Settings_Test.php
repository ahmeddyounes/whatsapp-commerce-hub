<?php
/**
 * Unit tests for WCH_Settings
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Settings class.
 */
class WCH_Settings_Test extends WCH_Unit_Test_Case {

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Clear settings.
		delete_option( 'wch_settings' );

		// Get fresh instance.
		$this->settings = WCH_Settings::getInstance();
	}

	/**
	 * Test getting default value.
	 */
	public function test_get_returns_default_value() {
		$result = $this->settings->get( 'api.phone_number_id', 'default_value' );
		$this->assertEquals( 'default_value', $result );
	}

	/**
	 * Test setting and getting a value.
	 */
	public function test_set_and_get_value() {
		$this->settings->set( 'api.phone_number_id', '123456789' );
		$result = $this->settings->get( 'api.phone_number_id' );
		$this->assertEquals( '123456789', $result );
	}

	/**
	 * Test setting multiple values.
	 */
	public function test_set_multiple_values() {
		$this->settings->set( 'api.phone_number_id', '123456789' );
		$this->settings->set( 'api.business_account_id', '987654321' );

		$this->assertEquals( '123456789', $this->settings->get( 'api.phone_number_id' ) );
		$this->assertEquals( '987654321', $this->settings->get( 'api.business_account_id' ) );
	}

	/**
	 * Test encryption of sensitive fields.
	 */
	public function test_encrypts_sensitive_fields() {
		$sensitive_value = 'super_secret_token';
		$this->settings->set( 'api.access_token', $sensitive_value );

		// Get raw option from database.
		$raw_settings = get_option( 'wch_settings' );

		// The stored value should be different (encrypted).
		$this->assertNotEquals( $sensitive_value, $raw_settings['api']['access_token'] );

		// But getting it through the settings class should decrypt it.
		$this->assertEquals( $sensitive_value, $this->settings->get( 'api.access_token' ) );
	}

	/**
	 * Test encryption of webhook secret.
	 */
	public function test_encrypts_webhook_secret() {
		$secret = 'my_webhook_secret_12345';
		$this->settings->set( 'api.webhook_secret', $secret );

		// Verify it's encrypted in storage.
		$raw_settings = get_option( 'wch_settings' );
		$this->assertNotEquals( $secret, $raw_settings['api']['webhook_secret'] );

		// Verify it decrypts correctly.
		$this->assertEquals( $secret, $this->settings->get( 'api.webhook_secret' ) );
	}

	/**
	 * Test getting all settings.
	 */
	public function test_get_all_settings() {
		$this->settings->set( 'api.phone_number_id', '123456789' );
		$this->settings->set( 'cart.session_timeout', '3600' );

		$all = $this->settings->get_all();

		$this->assertIsArray( $all );
		$this->assertArrayHasKey( 'api', $all );
		$this->assertArrayHasKey( 'cart', $all );
		$this->assertEquals( '123456789', $all['api']['phone_number_id'] );
		$this->assertEquals( '3600', $all['cart']['session_timeout'] );
	}

	/**
	 * Test deleting a setting.
	 */
	public function test_delete_setting() {
		$this->settings->set( 'api.phone_number_id', '123456789' );
		$this->assertEquals( '123456789', $this->settings->get( 'api.phone_number_id' ) );

		$this->settings->delete( 'api.phone_number_id' );
		$this->assertNull( $this->settings->get( 'api.phone_number_id' ) );
	}

	/**
	 * Test bulk update.
	 */
	public function test_bulk_update() {
		$updates = [
			'api' => array(
				'phone_number_id' => '123456789',
				'business_account_id' => '987654321',
			),
			'cart' => array(
				'session_timeout' => '7200',
			),
		];

		$this->settings->update( $updates );

		$this->assertEquals( '123456789', $this->settings->get( 'api.phone_number_id' ) );
		$this->assertEquals( '987654321', $this->settings->get( 'api.business_account_id' ) );
		$this->assertEquals( '7200', $this->settings->get( 'cart.session_timeout' ) );
	}

	/**
	 * Test invalid key format.
	 */
	public function test_invalid_key_format_returns_default() {
		$result = $this->settings->get( 'invalid_key_without_dot', 'default' );
		$this->assertEquals( 'default', $result );
	}

	/**
	 * Test setting persistence.
	 */
	public function test_settings_persist_across_instances() {
		$this->settings->set( 'api.phone_number_id', '123456789' );

		// Clear cache by creating new instance.
		$new_instance = WCH_Settings::getInstance();
		$result = $new_instance->get( 'api.phone_number_id' );

		$this->assertEquals( '123456789', $result );
	}

	/**
	 * Test OpenAI API key encryption.
	 */
	public function test_encrypts_openai_api_key() {
		$api_key = 'sk-test-api-key-12345';
		$this->settings->set( 'ai.openai_api_key', $api_key );

		// Verify encryption.
		$raw_settings = get_option( 'wch_settings' );
		$this->assertNotEquals( $api_key, $raw_settings['ai']['openai_api_key'] );

		// Verify decryption.
		$this->assertEquals( $api_key, $this->settings->get( 'ai.openai_api_key' ) );
	}

	/**
	 * Test webhook verify token encryption.
	 */
	public function test_encrypts_webhook_verify_token() {
		$token = 'verify_token_12345';
		$this->settings->set( 'api.webhook_verify_token', $token );

		// Verify encryption.
		$raw_settings = get_option( 'wch_settings' );
		$this->assertNotEquals( $token, $raw_settings['api']['webhook_verify_token'] );

		// Verify decryption.
		$this->assertEquals( $token, $this->settings->get( 'api.webhook_verify_token' ) );
	}
}
