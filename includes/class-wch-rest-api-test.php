<?php
/**
 * REST API Test Class
 *
 * Tests for the REST API infrastructure.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_REST_API_Test
 */
class WCH_REST_API_Test extends WCH_Test {
	/**
	 * REST API instance.
	 *
	 * @var WCH_REST_API
	 */
	private $api;

	/**
	 * Test controller instance.
	 *
	 * @var WCH_Test_REST_Controller
	 */
	private $controller;

	/**
	 * Set up test.
	 */
	public function setup() {
		parent::setup();

		$this->api        = WCH_REST_API::getInstance();
		$this->controller = new WCH_Test_REST_Controller();
	}

	/**
	 * Test REST API initialization.
	 */
	public function test_api_initialization() {
		$this->assert_not_null( $this->api, 'REST API should be initialized' );
		$this->assert_instance_of( 'WCH_REST_API', $this->api, 'Should be instance of WCH_REST_API' );
	}

	/**
	 * Test namespace constant.
	 */
	public function test_namespace() {
		$this->assert_equals( 'wch/v1', WCH_REST_API::NAMESPACE, 'Namespace should be wch/v1' );
	}

	/**
	 * Test API info endpoint.
	 */
	public function test_api_info() {
		$info = $this->api->get_api_info();

		$this->assert_not_null( $info, 'API info should not be null' );
		$this->assert_true( is_array( $info ), 'API info should be an array' );
		$this->assert_equals( 'WhatsApp Commerce Hub API', $info['name'], 'API name should match' );
		$this->assert_equals( 'v1', $info['version'], 'API version should be v1' );
		$this->assert_equals( 'wch/v1', $info['namespace'], 'API namespace should be wch/v1' );
	}

	/**
	 * Test admin permission check - with permission.
	 */
	public function test_check_admin_permission_with_permission() {
		// Mock current_user_can to return true.
		add_filter( 'user_has_cap', array( $this, 'mock_user_can_manage_woocommerce' ), 10, 3 );

		$result = $this->controller->check_admin_permission();
		$this->assert_true( $result, 'Should return true for user with manage_woocommerce capability' );

		remove_filter( 'user_has_cap', array( $this, 'mock_user_can_manage_woocommerce' ), 10 );
	}

	/**
	 * Test admin permission check - without permission.
	 */
	public function test_check_admin_permission_without_permission() {
		$result = $this->controller->check_admin_permission();
		$this->assert_instance_of( 'WP_Error', $result, 'Should return WP_Error for user without permission' );
		$this->assert_equals( 'wch_rest_forbidden', $result->get_error_code(), 'Error code should be wch_rest_forbidden' );
	}

	/**
	 * Test phone validation - valid phone.
	 */
	public function test_validate_phone_valid() {
		$valid_phones = array(
			'1234567890'     => '+1234567890',
			'+1234567890'    => '+1234567890',
			'12345678901234' => '+12345678901234',
			'(123) 456-7890' => '+1234567890',
		);

		foreach ( $valid_phones as $input => $expected ) {
			$result = $this->controller->validate_phone( $input );
			$this->assert_equals( $expected, $result, "Phone {$input} should be validated to {$expected}" );
		}
	}

	/**
	 * Test phone validation - invalid phone.
	 */
	public function test_validate_phone_invalid() {
		$invalid_phones = array(
			'',           // Empty.
			'123',        // Too short.
			'1234567890123456', // Too long.
		);

		foreach ( $invalid_phones as $phone ) {
			$result = $this->controller->validate_phone( $phone );
			$this->assert_instance_of( 'WP_Error', $result, "Phone {$phone} should be invalid" );
			$this->assert_equals( 'wch_rest_invalid_phone', $result->get_error_code(), 'Error code should be wch_rest_invalid_phone' );
		}
	}

	/**
	 * Test API key permission check - missing key.
	 */
	public function test_check_api_key_permission_missing() {
		$request = new WP_REST_Request( 'GET', '/wch/v1/test' );
		$result  = $this->controller->check_api_key_permission( $request );

		$this->assert_instance_of( 'WP_Error', $result, 'Should return WP_Error for missing API key' );
		$this->assert_equals( 'wch_rest_missing_api_key', $result->get_error_code(), 'Error code should be wch_rest_missing_api_key' );
	}

	/**
	 * Test API key permission check - invalid key.
	 */
	public function test_check_api_key_permission_invalid() {
		// Set a test API key hash.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'api.api_key_hash', wp_hash_password( 'test-api-key' ) );

		$request = new WP_REST_Request( 'GET', '/wch/v1/test' );
		$request->set_header( 'X-WCH-API-Key', 'wrong-api-key' );
		$result = $this->controller->check_api_key_permission( $request );

		$this->assert_instance_of( 'WP_Error', $result, 'Should return WP_Error for invalid API key' );
		$this->assert_equals( 'wch_rest_invalid_api_key', $result->get_error_code(), 'Error code should be wch_rest_invalid_api_key' );

		// Clean up.
		$settings->delete( 'api.api_key_hash' );
	}

	/**
	 * Test API key permission check - valid key.
	 */
	public function test_check_api_key_permission_valid() {
		// Set a test API key hash.
		$settings = WCH_Settings::getInstance();
		$api_key  = 'test-api-key';
		$settings->set( 'api.api_key_hash', wp_hash_password( $api_key ) );

		$request = new WP_REST_Request( 'GET', '/wch/v1/test' );
		$request->set_header( 'X-WCH-API-Key', $api_key );
		$result = $this->controller->check_api_key_permission( $request );

		$this->assert_true( $result, 'Should return true for valid API key' );

		// Clean up.
		$settings->delete( 'api.api_key_hash' );
	}

	/**
	 * Test webhook signature check - missing signature.
	 */
	public function test_check_webhook_signature_missing() {
		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$result  = $this->controller->check_webhook_signature( $request );

		$this->assert_instance_of( 'WP_Error', $result, 'Should return WP_Error for missing signature' );
		$this->assert_equals( 'wch_rest_missing_signature', $result->get_error_code(), 'Error code should be wch_rest_missing_signature' );
	}

	/**
	 * Test webhook signature check - invalid signature.
	 */
	public function test_check_webhook_signature_invalid() {
		// Set a test webhook secret.
		$settings = WCH_Settings::getInstance();
		$settings->set( 'api.webhook_secret', 'test-secret' );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( '{"test": "data"}' );
		$request->set_header( 'X-Hub-Signature-256', 'sha256=invalid' );
		$result = $this->controller->check_webhook_signature( $request );

		$this->assert_instance_of( 'WP_Error', $result, 'Should return WP_Error for invalid signature' );
		$this->assert_equals( 'wch_rest_invalid_signature', $result->get_error_code(), 'Error code should be wch_rest_invalid_signature' );

		// Clean up.
		$settings->delete( 'api.webhook_secret' );
	}

	/**
	 * Test webhook signature check - valid signature.
	 */
	public function test_check_webhook_signature_valid() {
		// Set a test webhook secret.
		$settings = WCH_Settings::getInstance();
		$secret   = 'test-secret';
		$settings->set( 'api.webhook_secret', $secret );

		$body      = '{"test": "data"}';
		$signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$request = new WP_REST_Request( 'POST', '/wch/v1/webhook' );
		$request->set_body( $body );
		$request->set_header( 'X-Hub-Signature-256', $signature );
		$result = $this->controller->check_webhook_signature( $request );

		$this->assert_true( $result, 'Should return true for valid signature' );

		// Clean up.
		$settings->delete( 'api.webhook_secret' );
	}

	/**
	 * Test rate limiting.
	 */
	public function test_rate_limiting() {
		// Test admin rate limit (100 requests/minute).
		for ( $i = 0; $i < 100; $i++ ) {
			$result = $this->controller->check_rate_limit( 'admin' );
			$this->assert_true( $result, "Request {$i} should be allowed" );
		}

		// Next request should be rate limited.
		$result = $this->controller->check_rate_limit( 'admin' );
		$this->assert_instance_of( 'WP_Error', $result, 'Request 101 should be rate limited' );
		$this->assert_equals( 'wch_rest_rate_limit_exceeded', $result->get_error_code(), 'Error code should be wch_rest_rate_limit_exceeded' );

		// Clean up transients.
		$this->cleanup_transients();
	}

	/**
	 * Test prepare response.
	 */
	public function test_prepare_response() {
		$data     = array( 'test' => 'data' );
		$request  = new WP_REST_Request( 'GET', '/wch/v1/test' );
		$response = $this->controller->prepare_response( $data, $request );

		$this->assert_instance_of( 'WP_REST_Response', $response, 'Should return WP_REST_Response' );
		$this->assert_equals( $data, $response->get_data(), 'Response data should match input' );
	}

	/**
	 * Test pagination headers.
	 */
	public function test_add_pagination_headers() {
		$response = new WP_REST_Response();
		$response = $this->controller->add_pagination_headers( $response, 100, 10, 2 );

		$this->assert_equals( 100, $response->get_headers()['X-WP-Total'], 'Total header should be 100' );
		$this->assert_equals( 10, $response->get_headers()['X-WP-TotalPages'], 'Total pages header should be 10' );
	}

	/**
	 * Mock user_has_cap filter to grant manage_woocommerce capability.
	 *
	 * @param array $allcaps All capabilities.
	 * @param array $caps    Requested capabilities.
	 * @param array $args    Arguments.
	 * @return array
	 */
	public function mock_user_can_manage_woocommerce( $allcaps, $caps, $args ) {
		if ( in_array( 'manage_woocommerce', $caps, true ) ) {
			$allcaps['manage_woocommerce'] = true;
		}
		return $allcaps;
	}

	/**
	 * Clean up rate limit transients.
	 */
	private function cleanup_transients() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wch_rate_limit_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wch_rate_limit_%'" );
	}
}

/**
 * Test REST Controller for testing base functionality.
 */
class WCH_Test_REST_Controller extends WCH_REST_Controller {
	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'test';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// No routes needed for testing.
	}

	/**
	 * Get item schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'test',
			'type'       => 'object',
			'properties' => array(),
		);
	}
}
