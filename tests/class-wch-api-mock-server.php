<?php
/**
 * WCH API Mock Server
 *
 * Mock server for external API responses using Brain\Monkey.
 *
 * @package WhatsApp_Commerce_Hub
 */

use Brain\Monkey\Functions;

/**
 * Class WCH_API_Mock_Server
 *
 * Provides HTTP mocking for external API calls including WhatsApp Cloud API
 * and WooCommerce REST API.
 */
class WCH_API_Mock_Server {

	/**
	 * Mock responses registered via add_http_request filter
	 *
	 * @var array
	 */
	private static $mock_responses = array();

	/**
	 * Initialize the mock server
	 */
	public static function init() {
		self::$mock_responses = array();
		add_filter( 'pre_http_request', array( __CLASS__, 'intercept_http_request' ), 10, 3 );
	}

	/**
	 * Reset all mock responses
	 */
	public static function reset() {
		self::$mock_responses = array();
		remove_filter( 'pre_http_request', array( __CLASS__, 'intercept_http_request' ), 10 );
	}

	/**
	 * Add a mock response for a specific URL pattern
	 *
	 * @param string $pattern URL pattern (regex).
	 * @param array  $response Mock response.
	 */
	public static function add_mock( string $pattern, array $response ) {
		self::$mock_responses[] = array(
			'pattern'  => $pattern,
			'response' => $response,
		);
	}

	/**
	 * Intercept HTTP requests and return mock responses
	 *
	 * @param false|array|WP_Error $preempt Whether to preempt an HTTP request's return value.
	 * @param array                $args HTTP request arguments.
	 * @param string               $url The request URL.
	 * @return false|array|WP_Error
	 */
	public static function intercept_http_request( $preempt, $args, $url ) {
		foreach ( self::$mock_responses as $mock ) {
			if ( preg_match( $mock['pattern'], $url ) ) {
				return $mock['response'];
			}
		}
		return $preempt;
	}

	/**
	 * Mock WhatsApp send message success
	 *
	 * @param string $message_id Message ID to return.
	 * @return array Mock response.
	 */
	public static function mock_whatsapp_send_message_success( string $message_id = 'wamid.test123' ): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'messages' => array(
						array( 'id' => $message_id ),
					),
				)
			),
		);
	}

	/**
	 * Mock WhatsApp rate limit error (429)
	 *
	 * @param int $retry_after Seconds to wait before retry.
	 * @return array Mock response.
	 */
	public static function mock_whatsapp_rate_limit( int $retry_after = 60 ): array {
		return array(
			'response' => array(
				'code'    => 429,
				'message' => 'Too Many Requests',
			),
			'headers'  => array(
				'Retry-After' => (string) $retry_after,
			),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'message' => 'Rate limit exceeded',
						'type'    => 'OAuthException',
						'code'    => 4,
					),
				)
			),
		);
	}

	/**
	 * Mock WhatsApp invalid recipient error
	 *
	 * @return array Mock response.
	 */
	public static function mock_whatsapp_invalid_recipient(): array {
		return array(
			'response' => array(
				'code'    => 400,
				'message' => 'Bad Request',
			),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 131026,
						'message' => 'Recipient not valid',
					),
				)
			),
		);
	}

	/**
	 * Mock WhatsApp media upload success
	 *
	 * @param string $media_id Media ID to return.
	 * @return array Mock response.
	 */
	public static function mock_whatsapp_media_upload_success( string $media_id = 'media_id_123' ): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'id' => $media_id,
				)
			),
		);
	}

	/**
	 * Mock WhatsApp media download success
	 *
	 * @param string $binary_data Binary data to return.
	 * @return array Mock response.
	 */
	public static function mock_whatsapp_media_download_success( string $binary_data = 'binary_image_data' ): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(
				'Content-Type' => 'image/jpeg',
			),
			'body'     => $binary_data,
		);
	}

	/**
	 * Mock WhatsApp catalog product creation success
	 *
	 * @param string $product_id Product ID to return.
	 * @return array Mock response.
	 */
	public static function mock_whatsapp_catalog_product_success( string $product_id = 'catalog_item_123' ): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'id' => $product_id,
				)
			),
		);
	}

	/**
	 * Mock WooCommerce REST API get products success
	 *
	 * @param array $products Products array.
	 * @return array Mock response.
	 */
	public static function mock_woocommerce_get_products_success( array $products = array() ): array {
		if ( empty( $products ) ) {
			$products = array(
				array(
					'id'          => 1,
					'name'        => 'Test Product',
					'price'       => '10.00',
					'description' => 'Test description',
					'images'      => array(
						array( 'src' => 'https://example.com/image.jpg' ),
					),
				),
			);
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $products ),
		);
	}

	/**
	 * Mock WooCommerce REST API create order success
	 *
	 * @param array $order_data Order data.
	 * @return array Mock response.
	 */
	public static function mock_woocommerce_create_order_success( array $order_data = array() ): array {
		if ( empty( $order_data ) ) {
			$order_data = array(
				'id'     => 100,
				'status' => 'pending',
				'total'  => '10.00',
			);
		}

		return array(
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
			'body'     => wp_json_encode( $order_data ),
		);
	}

	/**
	 * Validate WooCommerce webhook signature
	 *
	 * @param string $payload Webhook payload.
	 * @param string $signature Signature header.
	 * @param string $secret Webhook secret.
	 * @return bool Whether signature is valid.
	 */
	public static function validate_woocommerce_webhook_signature( string $payload, string $signature, string $secret ): bool {
		$expected_signature = base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );
		return hash_equals( $expected_signature, $signature );
	}

	/**
	 * Generate WooCommerce webhook signature
	 *
	 * @param string $payload Webhook payload.
	 * @param string $secret Webhook secret.
	 * @return string Generated signature.
	 */
	public static function generate_woocommerce_webhook_signature( string $payload, string $secret ): string {
		return base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );
	}
}
