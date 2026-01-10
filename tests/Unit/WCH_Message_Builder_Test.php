<?php
/**
 * Unit tests for WCH_Message_Builder
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Message_Builder class.
 */
class WCH_Message_Builder_Test extends WCH_Unit_Test_Case {

	/**
	 * Test building a simple text message.
	 */
	public function test_build_text_message() {
		$builder = new WCH_Message_Builder();
		$message = $builder->text( 'Hello, World!' )->build();

		$this->assertIsArray( $message );
		$this->assertEquals( 'text', $message['type'] );
		$this->assertEquals( 'Hello, World!', $message['text']['body'] );
	}

	/**
	 * Test text message validation for max length.
	 */
	public function test_text_message_validates_max_length() {
		$this->expectException( WCH_Exception::class );
		$this->expectExceptionMessage( 'Text message exceeds maximum length' );

		$long_text = str_repeat( 'a', 5000 );
		$builder = new WCH_Message_Builder();
		$builder->text( $long_text )->build();
	}

	/**
	 * Test building interactive message with body.
	 */
	public function test_build_interactive_message_with_body() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'Please choose an option' )
			->button( 'reply', [ 'id' => 'btn1', 'title' => 'Option 1' ] )
			->build();

		$this->assertEquals( 'interactive', $message['type'] );
		$this->assertEquals( 'Please choose an option', $message['interactive']['body']['text'] );
	}

	/**
	 * Test building message with header.
	 */
	public function test_build_message_with_header() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->header( 'text', 'Welcome' )
			->body( 'Choose a category' )
			->button( 'reply', [ 'id' => 'cat1', 'title' => 'Electronics' ] )
			->build();

		$this->assertArrayHasKey( 'header', $message['interactive'] );
		$this->assertEquals( 'text', $message['interactive']['header']['type'] );
		$this->assertEquals( 'Welcome', $message['interactive']['header']['text'] );
	}

	/**
	 * Test building message with footer.
	 */
	public function test_build_message_with_footer() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'Select an option' )
			->footer( 'Powered by WhatsApp Commerce Hub' )
			->button( 'reply', [ 'id' => 'opt1', 'title' => 'Continue' ] )
			->build();

		$this->assertArrayHasKey( 'footer', $message['interactive'] );
		$this->assertEquals( 'Powered by WhatsApp Commerce Hub', $message['interactive']['footer']['text'] );
	}

	/**
	 * Test adding multiple buttons.
	 */
	public function test_build_message_with_multiple_buttons() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'What would you like to do?' )
			->button( 'reply', [ 'id' => 'shop', 'title' => 'Shop Now' ] )
			->button( 'reply', [ 'id' => 'track', 'title' => 'Track Order' ] )
			->button( 'reply', [ 'id' => 'help', 'title' => 'Get Help' ] )
			->build();

		$this->assertCount( 3, $message['interactive']['action']['buttons'] );
		$this->assertEquals( 'Shop Now', $message['interactive']['action']['buttons'][0]['reply']['title'] );
	}

	/**
	 * Test button limit validation.
	 */
	public function test_validates_max_buttons() {
		$this->expectException( WCH_Exception::class );
		$this->expectExceptionMessage( 'Maximum 3 buttons allowed' );

		$builder = new WCH_Message_Builder();
		$builder
			->body( 'Too many buttons' )
			->button( 'reply', [ 'id' => 'btn1', 'title' => 'Button 1' ] )
			->button( 'reply', [ 'id' => 'btn2', 'title' => 'Button 2' ] )
			->button( 'reply', [ 'id' => 'btn3', 'title' => 'Button 3' ] )
			->button( 'reply', [ 'id' => 'btn4', 'title' => 'Button 4' ] )
			->build();
	}

	/**
	 * Test building list message.
	 */
	public function test_build_list_message() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'Browse our categories' )
			->section( 'Categories', [
				array( 'id' => 'cat1', 'title' => 'Electronics', 'description' => 'Phones, Laptops, etc.' ),
				array( 'id' => 'cat2', 'title' => 'Fashion', 'description' => 'Clothing and Accessories' ),
			] )
			->build();

		$this->assertEquals( 'interactive', $message['type'] );
		$this->assertEquals( 'list', $message['interactive']['type'] );
		$this->assertCount( 1, $message['interactive']['action']['sections'] );
	}

	/**
	 * Test list with multiple sections.
	 */
	public function test_build_list_with_multiple_sections() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'Browse products' )
			->section( 'Featured', [
				array( 'id' => 'p1', 'title' => 'Product 1' ),
			] )
			->section( 'New Arrivals', [
				array( 'id' => 'p2', 'title' => 'Product 2' ),
			] )
			->build();

		$this->assertCount( 2, $message['interactive']['action']['sections'] );
		$this->assertEquals( 'Featured', $message['interactive']['action']['sections'][0]['title'] );
		$this->assertEquals( 'New Arrivals', $message['interactive']['action']['sections'][1]['title'] );
	}

	/**
	 * Test body text length validation.
	 */
	public function test_validates_body_length() {
		$this->expectException( WCH_Exception::class );
		$this->expectExceptionMessage( 'Body text exceeds maximum length' );

		$long_body = str_repeat( 'a', 1500 );
		$builder = new WCH_Message_Builder();
		$builder->body( $long_body )->button( 'reply', [ 'id' => 'btn1', 'title' => 'OK' ] )->build();
	}

	/**
	 * Test footer text length validation.
	 */
	public function test_validates_footer_length() {
		$this->expectException( WCH_Exception::class );
		$this->expectExceptionMessage( 'Footer text exceeds maximum length' );

		$long_footer = str_repeat( 'a', 100 );
		$builder = new WCH_Message_Builder();
		$builder
			->body( 'Test' )
			->footer( $long_footer )
			->button( 'reply', [ 'id' => 'btn1', 'title' => 'OK' ] )
			->build();
	}

	/**
	 * Test header text length validation.
	 */
	public function test_validates_header_text_length() {
		$this->expectException( WCH_Exception::class );
		$this->expectExceptionMessage( 'Header text exceeds maximum length' );

		$long_header = str_repeat( 'a', 100 );
		$builder = new WCH_Message_Builder();
		$builder
			->header( 'text', $long_header )
			->body( 'Test' )
			->button( 'reply', [ 'id' => 'btn1', 'title' => 'OK' ] )
			->build();
	}

	/**
	 * Test building product message.
	 */
	public function test_build_product_message() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->product( 'catalog_id', [ 'product_123', 'product_456' ] )
			->build();

		$this->assertEquals( 'interactive', $message['type'] );
		$this->assertEquals( 'product_list', $message['interactive']['type'] );
	}

	/**
	 * Test message builder is chainable.
	 */
	public function test_builder_is_chainable() {
		$builder = new WCH_Message_Builder();
		$result = $builder
			->body( 'Test' )
			->footer( 'Footer' )
			->button( 'reply', [ 'id' => 'btn1', 'title' => 'Button' ] );

		$this->assertInstanceOf( WCH_Message_Builder::class, $result );
	}

	/**
	 * Test empty message validation.
	 */
	public function test_validates_empty_message() {
		$this->expectException( WCH_Exception::class );

		$builder = new WCH_Message_Builder();
		$builder->build();
	}

	/**
	 * Test URL button type.
	 */
	public function test_build_message_with_url_button() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'Visit our website' )
			->button( 'url', [ 'title' => 'Visit', 'url' => 'https://example.com' ] )
			->build();

		$this->assertEquals( 'url', $message['interactive']['action']['buttons'][0]['type'] );
	}

	/**
	 * Test phone button type.
	 */
	public function test_build_message_with_phone_button() {
		$builder = new WCH_Message_Builder();
		$message = $builder
			->body( 'Call us' )
			->button( 'phone', [ 'title' => 'Call Now', 'phone' => '+1234567890' ] )
			->build();

		$this->assertEquals( 'phone_number', $message['interactive']['action']['buttons'][0]['type'] );
	}
}
