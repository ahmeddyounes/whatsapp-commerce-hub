<?php
/**
 * Unit tests for WCH_Intent_Classifier
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Intent_Classifier class.
 */
class WCH_Intent_Classifier_Test extends WCH_Unit_Test_Case {

	/**
	 * Intent classifier instance.
	 *
	 * @var WCH_Intent_Classifier
	 */
	private $classifier;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->classifier = new WCH_Intent_Classifier();
	}

	/**
	 * Test classifying greeting intent.
	 */
	public function test_classifies_greeting_intent() {
		$intents = [ 'hi', 'hello', 'hey', 'good morning', 'good afternoon' ];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'GREETING', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying browse intent.
	 */
	public function test_classifies_browse_intent() {
		$intents = [
			'show products',
			'browse catalog',
			'what do you have',
			'show me items',
			'view products',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'BROWSE', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying search intent.
	 */
	public function test_classifies_search_intent() {
		$intents = [
			'search for phone',
			'find laptop',
			'looking for shoes',
			'do you have iPhone',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'SEARCH', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying add to cart intent.
	 */
	public function test_classifies_add_to_cart_intent() {
		$intents = [
			'add to cart',
			'I want this',
			'buy this',
			'add this product',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'ADD_TO_CART', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying view cart intent.
	 */
	public function test_classifies_view_cart_intent() {
		$intents = [
			'show my cart',
			'view cart',
			'what\'s in my cart',
			'check cart',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'VIEW_CART', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying checkout intent.
	 */
	public function test_classifies_checkout_intent() {
		$intents = [
			'checkout',
			'proceed to checkout',
			'complete order',
			'place order',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'CHECKOUT', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying track order intent.
	 */
	public function test_classifies_track_order_intent() {
		$intents = [
			'track my order',
			'order status',
			'where is my order',
			'check order',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'TRACK_ORDER', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying help intent.
	 */
	public function test_classifies_help_intent() {
		$intents = [
			'help',
			'I need help',
			'support',
			'customer service',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'HELP', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test classifying cancel intent.
	 */
	public function test_classifies_cancel_intent() {
		$intents = [
			'cancel',
			'go back',
			'nevermind',
			'stop',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'CANCEL', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test extracting search query.
	 */
	public function test_extracts_search_query() {
		$result = $this->classifier->classify( 'search for iPhone 15' );
		$this->assertEquals( 'SEARCH', $result->get_type() );
		$this->assertEquals( 'iPhone 15', $result->get_entities()['query'] ?? '' );
	}

	/**
	 * Test extracting product quantity.
	 */
	public function test_extracts_quantity() {
		$result = $this->classifier->classify( 'add 3 items to cart' );
		$this->assertEquals( 'ADD_TO_CART', $result->get_type() );
		$this->assertEquals( 3, $result->get_entities()['quantity'] ?? 1 );
	}

	/**
	 * Test case insensitive classification.
	 */
	public function test_case_insensitive_classification() {
		$variations = [ 'HELLO', 'Hello', 'hello', 'HeLLo' ];

		foreach ( $variations as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'GREETING', $result->get_type() );
		}
	}

	/**
	 * Test confidence scoring.
	 */
	public function test_provides_confidence_score() {
		$result = $this->classifier->classify( 'hello' );
		$this->assertIsFloat( $result->get_confidence() );
		$this->assertGreaterThan( 0, $result->get_confidence() );
		$this->assertLessThanOrEqual( 1, $result->get_confidence() );
	}

	/**
	 * Test fallback for unknown intent.
	 */
	public function test_fallback_for_unknown_intent() {
		$result = $this->classifier->classify( 'xyzabc random nonsense' );
		$this->assertEquals( 'UNKNOWN', $result->get_type() );
	}

	/**
	 * Test removing cart item intent.
	 */
	public function test_classifies_remove_from_cart_intent() {
		$intents = [
			'remove from cart',
			'delete this item',
			'remove item',
		];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'REMOVE_FROM_CART', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test update quantity intent.
	 */
	public function test_classifies_update_quantity_intent() {
		$result = $this->classifier->classify( 'change quantity to 5' );
		$this->assertEquals( 'UPDATE_QUANTITY', $result->get_type() );
	}

	/**
	 * Test affirmative response.
	 */
	public function test_classifies_affirmative_response() {
		$intents = [ 'yes', 'yeah', 'sure', 'ok', 'okay', 'correct' ];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'CONFIRM', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test negative response.
	 */
	public function test_classifies_negative_response() {
		$intents = [ 'no', 'nope', 'not really', 'nah' ];

		foreach ( $intents as $text ) {
			$result = $this->classifier->classify( $text );
			$this->assertEquals( 'DECLINE', $result->get_type(), "Failed for: $text" );
		}
	}

	/**
	 * Test with context awareness.
	 */
	public function test_classification_with_context() {
		$context = [ 'state' => 'AWAITING_PAYMENT' ];
		$result = $this->classifier->classify( 'pay now', $context );

		$this->assertEquals( 'CONFIRM_PAYMENT', $result->get_type() );
	}
}
