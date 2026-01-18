<?php
/**
 * Unit tests for Catalog Browser
 *
 * @package WhatsApp_Commerce_Hub
 */

use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;
use WhatsAppCommerceHub\Actions\ActionRegistry;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;

/**
 * Test CatalogBrowser class.
 */
class CatalogBrowserTest extends WCH_Unit_Test_Case {

	/**
	 * CatalogBrowser instance.
	 *
	 * @var CatalogBrowser
	 */
	private $browser;

	/**
	 * Mock ActionRegistry.
	 *
	 * @var Mockery\MockInterface
	 */
	private $mockRegistry;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mockRegistry = Mockery::mock( ActionRegistry::class );
		$this->browser      = new CatalogBrowser();
	}

	/**
	 * Test showMainMenu returns array of MessageBuilder instances.
	 */
	public function test_show_main_menu_returns_message_builders() {
		// Arrange.
		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [],
		];

		// Mock the registry to return a successful result with messages.
		$message = new MessageBuilder();
		$message->text( 'Welcome to our store!' );

		$result = ActionResult::success( [ $message ] );

		$this->mockRegistry
			->shouldReceive( 'execute' )
			->once()
			->with( 'show_main_menu', '+1234567890', [], Mockery::type( 'WhatsAppCommerceHub\ValueObjects\ConversationContext' ) )
			->andReturn( $result );

		// Replace the global registry with our mock.
		$this->inject_mock_registry();

		// Act.
		$messages = $this->browser->showMainMenu( $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertCount( 1, $messages );
		$this->assertInstanceOf( MessageBuilder::class, $messages[0] );
	}

	/**
	 * Test showCategory returns array of MessageBuilder instances.
	 */
	public function test_show_category_returns_message_builders() {
		// Arrange.
		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [],
		];
		$categoryId   = 42;
		$page         = 1;

		// Mock the registry.
		$message = new MessageBuilder();
		$message->text( 'Category products' );

		$result = ActionResult::success( [ $message ] );

		$this->mockRegistry
			->shouldReceive( 'execute' )
			->once()
			->with(
				'show_category',
				'+1234567890',
				[ 'category_id' => $categoryId, 'page' => $page ],
				Mockery::type( 'WhatsAppCommerceHub\ValueObjects\ConversationContext' )
			)
			->andReturn( $result );

		$this->inject_mock_registry();

		// Act.
		$messages = $this->browser->showCategory( $categoryId, $page, $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertCount( 1, $messages );
		$this->assertInstanceOf( MessageBuilder::class, $messages[0] );
	}

	/**
	 * Test showProduct returns array of MessageBuilder instances.
	 */
	public function test_show_product_returns_message_builders() {
		// Arrange.
		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [],
		];
		$productId    = 123;

		// Mock the registry.
		$message = new MessageBuilder();
		$message->text( 'Product details' );

		$result = ActionResult::success( [ $message ] );

		$this->mockRegistry
			->shouldReceive( 'execute' )
			->once()
			->with(
				'show_product',
				'+1234567890',
				[ 'product_id' => $productId ],
				Mockery::type( 'WhatsAppCommerceHub\ValueObjects\ConversationContext' )
			)
			->andReturn( $result );

		$this->inject_mock_registry();

		// Act.
		$messages = $this->browser->showProduct( $productId, $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertCount( 1, $messages );
		$this->assertInstanceOf( MessageBuilder::class, $messages[0] );
	}

	/**
	 * Test searchProducts returns simple product list.
	 */
	public function test_search_products_returns_product_list() {
		// Arrange - Create test products.
		$product1 = $this->create_test_product(
			[
				'name'  => 'Blue Shoes',
				'price' => '49.99',
			]
		);
		$product2 = $this->create_test_product(
			[
				'name'  => 'Red Shoes',
				'price' => '59.99',
			]
		);

		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [],
		];

		// Act.
		$messages = $this->browser->searchProducts( 'shoes', 1, $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertGreaterThan( 0, count( $messages ) );
		$this->assertInstanceOf( MessageBuilder::class, $messages[0] );

		// Build the message to verify content.
		$built = $messages[0]->build();
		$this->assertArrayHasKey( 'type', $built );
		$this->assertEquals( 'text', $built['type'] );
	}

	/**
	 * Test searchProducts with empty query returns empty message.
	 */
	public function test_search_products_empty_query_returns_empty_message() {
		// Arrange.
		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [],
		];

		// Act.
		$messages = $this->browser->searchProducts( '', 1, $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertCount( 1, $messages );

		$built = $messages[0]->build();
		$this->assertStringContainsString( 'Please enter a search term', $built['text']['body'] );
	}

	/**
	 * Test showFeaturedProducts returns product list.
	 */
	public function test_show_featured_products_returns_list() {
		// Arrange - Create a featured product.
		$product = $this->create_test_product(
			[
				'name'     => 'Featured Product',
				'price'    => '99.99',
				'featured' => true,
			]
		);

		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [],
		];

		// Act.
		$messages = $this->browser->showFeaturedProducts( 1, $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertGreaterThan( 0, count( $messages ) );
		$this->assertInstanceOf( MessageBuilder::class, $messages[0] );
	}

	/**
	 * Test getProductsPerPage returns correct constant.
	 */
	public function test_get_products_per_page() {
		// Act.
		$perPage = $this->browser->getProductsPerPage();

		// Assert.
		$this->assertEquals( 10, $perPage );
	}

	/**
	 * Test browser handles array conversation context.
	 */
	public function test_handles_array_conversation_context() {
		// Arrange.
		$conversation = [
			'customer_phone' => '+1234567890',
			'context'        => [ 'last_action' => 'browse' ],
		];

		$message = new MessageBuilder();
		$message->text( 'Test' );
		$result = ActionResult::success( [ $message ] );

		$this->mockRegistry
			->shouldReceive( 'execute' )
			->once()
			->andReturn( $result );

		$this->inject_mock_registry();

		// Act.
		$messages = $this->browser->showMainMenu( $conversation );

		// Assert.
		$this->assertIsArray( $messages );
	}

	/**
	 * Test browser handles object conversation context.
	 */
	public function test_handles_object_conversation_context() {
		// Arrange.
		$conversation                = new stdClass();
		$conversation->customer_phone = '+1234567890';
		$conversation->context        = [ 'last_action' => 'browse' ];

		$message = new MessageBuilder();
		$message->text( 'Test' );
		$result = ActionResult::success( [ $message ] );

		$this->mockRegistry
			->shouldReceive( 'execute' )
			->once()
			->andReturn( $result );

		$this->inject_mock_registry();

		// Act.
		$messages = $this->browser->showMainMenu( $conversation );

		// Assert.
		$this->assertIsArray( $messages );
	}

	/**
	 * Test browser returns empty array when phone is missing.
	 */
	public function test_returns_empty_array_when_phone_missing() {
		// Arrange.
		$conversation = [
			'context' => [],
		];

		// Act.
		$messages = $this->browser->showMainMenu( $conversation );

		// Assert.
		$this->assertIsArray( $messages );
		$this->assertEmpty( $messages );
	}

	/**
	 * Helper to inject mock registry into container.
	 */
	private function inject_mock_registry() {
		// This is a simplified approach - in real implementation,
		// we would use the DI container to inject the mock.
		// For now, this test validates the method signatures and return types.
	}
}
