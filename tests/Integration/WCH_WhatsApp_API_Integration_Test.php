<?php
/**
 * WhatsApp API Integration Tests
 *
 * Tests WhatsApp Business Cloud API interactions with mock server.
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Class WCH_WhatsApp_API_Integration_Test
 *
 * Integration tests for WhatsApp API client.
 */
class WCH_WhatsApp_API_Integration_Test extends WCH_Integration_Test_Case {

	/**
	 * WhatsApp API client instance.
	 *
	 * @var WCH_WhatsApp_API_Client
	 */
	private $api_client;

	/**
	 * Test phone number.
	 *
	 * @var string
	 */
	private $test_phone = '+1234567890';

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		WCH_API_Mock_Server::init();

		$this->api_client = new WCH_WhatsApp_API_Client(
			[
				'phone_number_id' => 'test_phone_number_id',
				'access_token'    => 'test_access_token',
				'api_version'     => 'v18.0',
			]
		);
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		WCH_API_Mock_Server::reset();
		parent::tearDown();
	}

	/**
	 * Test sending a text message successfully.
	 */
	public function test_send_text_message_success() {
		// Arrange.
		$message_id = 'wamid.' . wp_generate_uuid4();
		WCH_API_Mock_Server::add_mock(
			'/graph\.facebook\.com.*\/messages/',
			WCH_API_Mock_Server::mock_whatsapp_send_message_success( $message_id )
		);

		// Act.
		$result = $this->api_client->send_text_message(
			$this->test_phone,
			'Hello from integration test!'
		);

		// Assert.
		$this->assertIsArray( $result );
		$this->assertEquals( $message_id, $result['message_id'] );
		$this->assertEquals( 'sent', $result['status'] );
	}

	/**
	 * Test sending an interactive list message successfully.
	 */
	public function test_send_interactive_list_success() {
		// Arrange.
		$message_id = 'wamid.' . wp_generate_uuid4();
		WCH_API_Mock_Server::add_mock(
			'/graph\.facebook\.com.*\/messages/',
			WCH_API_Mock_Server::mock_whatsapp_send_message_success( $message_id )
		);

		$sections = [
			array(
				'title' => 'Products',
				'rows'  => array(
					array(
						'id'          => 'product_1',
						'title'       => 'Product 1',
						'description' => 'Description 1',
					),
					array(
						'id'          => 'product_2',
						'title'       => 'Product 2',
						'description' => 'Description 2',
					),
				),
			),
		];

		// Act.
		$result = $this->api_client->send_interactive_list(
			$this->test_phone,
			'Browse Products',
			'Select a product to view details',
			'Powered by WhatsApp Commerce Hub',
			'View Products',
			$sections
		);

		// Assert.
		$this->assertIsArray( $result );
		$this->assertEquals( $message_id, $result['message_id'] );
		$this->assertEquals( 'sent', $result['status'] );
	}

	/**
	 * Test sending a template message successfully.
	 */
	public function test_send_template_message_success() {
		// Arrange.
		$message_id = 'wamid.' . wp_generate_uuid4();
		WCH_API_Mock_Server::add_mock(
			'/graph\.facebook\.com.*\/messages/',
			WCH_API_Mock_Server::mock_whatsapp_send_message_success( $message_id )
		);

		$components = [
			array(
				'type'       => 'body',
				'parameters' => array(
					array(
						'type' => 'text',
						'text' => 'John Doe',
					),
				),
			),
		];

		// Act.
		$result = $this->api_client->send_template(
			$this->test_phone,
			'order_confirmation',
			'en_US',
			$components
		);

		// Assert.
		$this->assertIsArray( $result );
		$this->assertEquals( $message_id, $result['message_id'] );
		$this->assertEquals( 'sent', $result['status'] );
	}

	/**
	 * Test rate limit retry succeeds.
	 */
	public function test_rate_limit_retry_succeeds() {
		// Arrange - First attempt returns rate limit, second succeeds.
		static $attempt = 0;
		$message_id     = 'wamid.' . wp_generate_uuid4();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$attempt, $message_id ) {
				if ( strpos( $url, '/messages' ) !== false ) {
					++$attempt;
					if ( 1 === $attempt ) {
						// First attempt - rate limit.
						return WCH_API_Mock_Server::mock_whatsapp_rate_limit( 1 );
					} else {
						// Second attempt - success.
						return WCH_API_Mock_Server::mock_whatsapp_send_message_success( $message_id );
					}
				}
				return $preempt;
			},
			10,
			3
		);

		// Act.
		$start_time = microtime( true );
		$result     = $this->api_client->send_text_message(
			$this->test_phone,
			'Test rate limit retry'
		);
		$elapsed    = microtime( true ) - $start_time;

		// Assert.
		$this->assertEquals( 2, $attempt, 'Should retry after rate limit' );
		$this->assertEquals( $message_id, $result['message_id'] );
		$this->assertGreaterThanOrEqual( 1, $elapsed, 'Should wait at least 1 second for backoff' );
	}

	/**
	 * Test invalid recipient throws exception.
	 */
	public function test_invalid_recipient_throws_exception() {
		// Arrange.
		WCH_API_Mock_Server::add_mock(
			'/graph\.facebook\.com.*\/messages/',
			WCH_API_Mock_Server::mock_whatsapp_invalid_recipient()
		);

		// Act & Assert.
		$this->expectException( WCH_API_Exception::class );
		$this->expectExceptionMessage( 'Recipient not valid' );

		$this->api_client->send_text_message(
			$this->test_phone,
			'Test invalid recipient'
		);
	}

	/**
	 * Test media upload success.
	 */
	public function test_media_upload_success() {
		// Arrange - Create a temporary test file.
		$temp_file = wp_tempnam( 'test_image.jpg' );
		file_put_contents( $temp_file, 'fake_image_data' );

		$media_id = 'media_' . wp_generate_uuid4();
		WCH_API_Mock_Server::add_mock(
			'/graph\.facebook\.com.*\/media/',
			WCH_API_Mock_Server::mock_whatsapp_media_upload_success( $media_id )
		);

		// Act.
		$result = $this->api_client->upload_media( $temp_file, 'image/jpeg' );

		// Assert.
		$this->assertEquals( $media_id, $result );

		// Clean up.
		unlink( $temp_file );
	}

	/**
	 * Test catalog sync success.
	 */
	public function test_catalog_sync_success() {
		// Arrange.
		$catalog_id = 'catalog_123';
		$product_id = 'catalog_item_' . wp_generate_uuid4();

		WCH_API_Mock_Server::add_mock(
			'/graph\.facebook\.com.*\/products/',
			WCH_API_Mock_Server::mock_whatsapp_catalog_product_success( $product_id )
		);

		$product_data = [
			'retailer_id'       => 'wc_product_100',
			'name'              => 'Test Product',
			'description'       => 'Test Description',
			'price'             => 1000,
			'currency'          => 'USD',
			'availability'      => 'in stock',
			'condition'         => 'new',
			'image_url'         => 'https://example.com/image.jpg',
			'url'               => 'https://example.com/product',
		];

		// Act.
		$result = $this->api_client->create_catalog_product( $catalog_id, $product_data );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertEquals( $product_id, $result['id'] );
	}
}
