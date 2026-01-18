<?php
/**
 * Integration tests for Catalog Browsing Actions
 *
 * @package WhatsApp_Commerce_Hub
 */

use WhatsAppCommerceHub\Actions\ShowMainMenuAction;
use WhatsAppCommerceHub\Actions\ShowCategoryAction;
use WhatsAppCommerceHub\Actions\ShowProductAction;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;

/**
 * Test catalog browsing action handlers.
 */
class CatalogBrowsingActionsTest extends WCH_Integration_Test_Case {

	/**
	 * Test ShowMainMenuAction returns messages with proper structure.
	 */
	public function test_show_main_menu_action_returns_valid_messages() {
		// Arrange.
		$action  = new ShowMainMenuAction();
		$phone   = '+1234567890';
		$params  = [];
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertTrue( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertIsArray( $messages );
		$this->assertGreaterThan( 0, count( $messages ) );

		// Verify first message is a MessageBuilder.
		$this->assertInstanceOf( MessageBuilder::class, $messages[0] );

		// Build and verify message structure.
		$built = $messages[0]->build();
		$this->assertIsArray( $built );
		$this->assertArrayHasKey( 'type', $built );
	}

	/**
	 * Test ShowCategoryAction with no category_id shows category list.
	 */
	public function test_show_category_action_without_id_shows_list() {
		// Arrange - Create a test category.
		$categoryId = $this->factory()->term->create(
			[
				'name'     => 'Electronics',
				'taxonomy' => 'product_cat',
			]
		);

		// Create a product in the category.
		$productId = $this->create_test_product(
			[
				'name'     => 'Test Product',
				'price'    => '29.99',
				'category' => [ $categoryId ],
			]
		);

		$action  = new ShowCategoryAction();
		$phone   = '+1234567890';
		$params  = []; // No category_id.
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertTrue( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertIsArray( $messages );
		$this->assertGreaterThan( 0, count( $messages ) );

		// Verify message contains category selection.
		$built = $messages[0]->build();
		$this->assertArrayHasKey( 'type', $built );
		$this->assertEquals( 'interactive', $built['type'] );
	}

	/**
	 * Test ShowCategoryAction with valid category shows products.
	 */
	public function test_show_category_action_with_valid_category() {
		// Arrange - Create category and products.
		$categoryId = $this->factory()->term->create(
			[
				'name'     => 'Clothing',
				'taxonomy' => 'product_cat',
			]
		);

		$product1 = $this->create_test_product(
			[
				'name'     => 'T-Shirt',
				'price'    => '19.99',
				'category' => [ $categoryId ],
			]
		);

		$product2 = $this->create_test_product(
			[
				'name'     => 'Jeans',
				'price'    => '49.99',
				'category' => [ $categoryId ],
			]
		);

		$action  = new ShowCategoryAction();
		$phone   = '+1234567890';
		$params  = [
			'category_id' => $categoryId,
			'page'        => 1,
		];
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertTrue( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertGreaterThan( 0, count( $messages ) );

		// Verify context updates.
		$updatedContext = $result->getContextUpdates();
		$this->assertArrayHasKey( 'current_category', $updatedContext );
		$this->assertEquals( $categoryId, $updatedContext['current_category'] );
		$this->assertArrayHasKey( 'current_page', $updatedContext );
		$this->assertEquals( 1, $updatedContext['current_page'] );
	}

	/**
	 * Test ShowCategoryAction with invalid category returns error.
	 */
	public function test_show_category_action_with_invalid_category() {
		// Arrange.
		$action  = new ShowCategoryAction();
		$phone   = '+1234567890';
		$params  = [
			'category_id' => 99999, // Non-existent category.
			'page'        => 1,
		];
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertFalse( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertGreaterThan( 0, count( $messages ) );

		// Verify error message.
		$built = $messages[0]->build();
		$this->assertStringContainsString( 'not found', $built['text']['body'] );
	}

	/**
	 * Test ShowProductAction with valid simple product.
	 */
	public function test_show_product_action_with_simple_product() {
		// Arrange.
		$productId = $this->create_test_product(
			[
				'name'        => 'Simple Product',
				'price'       => '39.99',
				'description' => 'A great product for testing.',
				'stock'       => 100,
			]
		);

		$action  = new ShowProductAction();
		$phone   = '+1234567890';
		$params  = [ 'product_id' => $productId ];
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertTrue( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertGreaterThan( 0, count( $messages ) );

		// Verify context contains product info.
		$updatedContext = $result->getContextUpdates();
		$this->assertArrayHasKey( 'current_product', $updatedContext );
		$this->assertEquals( $productId, $updatedContext['current_product'] );
		$this->assertArrayHasKey( 'product_name', $updatedContext );
		$this->assertEquals( 'Simple Product', $updatedContext['product_name'] );
	}

	/**
	 * Test ShowProductAction with variable product includes variant selector.
	 */
	public function test_show_product_action_with_variable_product() {
		// Arrange - Create a variable product.
		$variableProduct = $this->create_variable_product();

		$action  = new ShowProductAction();
		$phone   = '+1234567890';
		$params  = [ 'product_id' => $variableProduct->get_id() ];
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertTrue( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertGreaterThan( 1, count( $messages ) ); // Should have detail + variant selector.

		// Verify context indicates variable product.
		$updatedContext = $result->getContextUpdates();
		$this->assertArrayHasKey( 'has_variations', $updatedContext );
		$this->assertTrue( $updatedContext['has_variations'] );
	}

	/**
	 * Test ShowProductAction with invalid product returns error.
	 */
	public function test_show_product_action_with_invalid_product() {
		// Arrange.
		$action  = new ShowProductAction();
		$phone   = '+1234567890';
		$params  = [ 'product_id' => 99999 ]; // Non-existent product.
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertFalse( $result->isSuccess() );

		$messages = $result->getMessages();
		$this->assertGreaterThan( 0, count( $messages ) );

		// Verify error message.
		$built = $messages[0]->build();
		$this->assertStringContainsString( 'not found', $built['text']['body'] );
	}

	/**
	 * Test ShowProductAction without product_id returns error.
	 */
	public function test_show_product_action_without_product_id() {
		// Arrange.
		$action  = new ShowProductAction();
		$phone   = '+1234567890';
		$params  = []; // Missing product_id.
		$context = new ConversationContext( [] );

		// Act.
		$result = $action->handle( $phone, $params, $context );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertFalse( $result->isSuccess() );
	}

	/**
	 * Test pagination in category browsing.
	 */
	public function test_category_pagination_works() {
		// Arrange - Create a category with 15 products (more than 10 per page).
		$categoryId = $this->factory()->term->create(
			[
				'name'     => 'Test Category',
				'taxonomy' => 'product_cat',
			]
		);

		for ( $i = 1; $i <= 15; $i++ ) {
			$this->create_test_product(
				[
					'name'     => 'Product ' . $i,
					'price'    => ( 10 + $i ) . '.99',
					'category' => [ $categoryId ],
				]
			);
		}

		$action  = new ShowCategoryAction();
		$phone   = '+1234567890';
		$context = new ConversationContext( [] );

		// Act - Get page 1.
		$result1 = $action->handle(
			$phone,
			[
				'category_id' => $categoryId,
				'page'        => 1,
			],
			$context
		);

		// Act - Get page 2.
		$result2 = $action->handle(
			$phone,
			[
				'category_id' => $categoryId,
				'page'        => 2,
			],
			$context
		);

		// Assert.
		$this->assertTrue( $result1->isSuccess() );
		$this->assertTrue( $result2->isSuccess() );

		// Both pages should have messages.
		$this->assertGreaterThan( 0, count( $result1->getMessages() ) );
		$this->assertGreaterThan( 0, count( $result2->getMessages() ) );

		// Verify pagination in footer.
		$built1 = $result1->getMessages()[0]->build();
		$this->assertArrayHasKey( 'interactive', $built1 );
		if ( isset( $built1['interactive']['footer'] ) ) {
			$this->assertStringContainsString( 'Page', $built1['interactive']['footer']['text'] );
		}
	}

	/**
	 * Helper to create a variable product with variations.
	 *
	 * @return WC_Product_Variable
	 */
	private function create_variable_product() {
		// Create a variable product.
		$product = new WC_Product_Variable();
		$product->set_name( 'Variable Product' );
		$product->set_status( 'publish' );
		$product->save();

		// Create an attribute.
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'Small', 'Medium', 'Large' ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );
		$product->save();

		// Create variations.
		foreach ( [ 'Small', 'Medium', 'Large' ] as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( [ 'size' => $size ] );
			$variation->set_regular_price( '29.99' );
			$variation->set_status( 'publish' );
			$variation->save();
		}

		// Sync the product.
		WC_Product_Variable::sync( $product );

		return $product;
	}
}
