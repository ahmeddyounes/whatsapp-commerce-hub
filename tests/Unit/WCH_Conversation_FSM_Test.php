<?php
/**
 * Unit tests for WCH_Conversation_FSM
 *
 * @package WhatsApp_Commerce_Hub
 */

/**
 * Test WCH_Conversation_FSM class.
 */
class WCH_Conversation_FSM_Test extends WCH_Unit_Test_Case {

	/**
	 * FSM instance.
	 *
	 * @var WCH_Conversation_FSM
	 */
	private $fsm;

	/**
	 * Test conversation ID.
	 *
	 * @var int
	 */
	private $conversation_id;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Ensure database tables exist.
		$db_manager = new WCH_Database_Manager();
		$db_manager->install();

		// Create test conversation.
		$this->conversation_id = $this->create_test_conversation( [
			'customer_phone' => '+1234567890',
			'state' => 'IDLE',
		] );

		$this->fsm = new WCH_Conversation_FSM( $this->conversation_id );
	}

	/**
	 * Test getting current state.
	 */
	public function test_get_current_state() {
		$state = $this->fsm->get_state();
		$this->assertEquals( 'IDLE', $state );
	}

	/**
	 * Test transitioning from IDLE to BROWSING.
	 */
	public function test_transition_idle_to_browsing() {
		$result = $this->fsm->transition( 'BROWSING' );
		$this->assertTrue( $result );
		$this->assertEquals( 'BROWSING', $this->fsm->get_state() );
	}

	/**
	 * Test transitioning from BROWSING to VIEWING_PRODUCT.
	 */
	public function test_transition_browsing_to_viewing_product() {
		$this->fsm->transition( 'BROWSING' );
		$result = $this->fsm->transition( 'VIEWING_PRODUCT' );

		$this->assertTrue( $result );
		$this->assertEquals( 'VIEWING_PRODUCT', $this->fsm->get_state() );
	}

	/**
	 * Test transitioning to CART_REVIEW.
	 */
	public function test_transition_to_cart_review() {
		$this->fsm->transition( 'BROWSING' );
		$result = $this->fsm->transition( 'CART_REVIEW' );

		$this->assertTrue( $result );
		$this->assertEquals( 'CART_REVIEW', $this->fsm->get_state() );
	}

	/**
	 * Test transitioning to CHECKOUT.
	 */
	public function test_transition_to_checkout() {
		$this->fsm->transition( 'CART_REVIEW' );
		$result = $this->fsm->transition( 'CHECKOUT' );

		$this->assertTrue( $result );
		$this->assertEquals( 'CHECKOUT', $this->fsm->get_state() );
	}

	/**
	 * Test transitioning to AWAITING_PAYMENT.
	 */
	public function test_transition_to_awaiting_payment() {
		$this->fsm->transition( 'CHECKOUT' );
		$result = $this->fsm->transition( 'AWAITING_PAYMENT' );

		$this->assertTrue( $result );
		$this->assertEquals( 'AWAITING_PAYMENT', $this->fsm->get_state() );
	}

	/**
	 * Test completing order.
	 */
	public function test_transition_to_completed() {
		$this->fsm->transition( 'AWAITING_PAYMENT' );
		$result = $this->fsm->transition( 'COMPLETED' );

		$this->assertTrue( $result );
		$this->assertEquals( 'COMPLETED', $this->fsm->get_state() );
	}

	/**
	 * Test invalid state transition.
	 */
	public function test_invalid_state_transition() {
		// Cannot go directly from IDLE to AWAITING_PAYMENT.
		$result = $this->fsm->transition( 'AWAITING_PAYMENT' );
		$this->assertFalse( $result );
		$this->assertEquals( 'IDLE', $this->fsm->get_state() ); // State unchanged
	}

	/**
	 * Test allowed transitions.
	 */
	public function test_get_allowed_transitions() {
		$this->fsm->transition( 'BROWSING' );
		$allowed = $this->fsm->get_allowed_transitions();

		$this->assertIsArray( $allowed );
		$this->assertContains( 'VIEWING_PRODUCT', $allowed );
		$this->assertContains( 'CART_REVIEW', $allowed );
	}

	/**
	 * Test can transition method.
	 */
	public function test_can_transition() {
		$this->fsm->transition( 'BROWSING' );

		$this->assertTrue( $this->fsm->can_transition( 'VIEWING_PRODUCT' ) );
		$this->assertFalse( $this->fsm->can_transition( 'COMPLETED' ) );
	}

	/**
	 * Test resetting to IDLE.
	 */
	public function test_reset_to_idle() {
		$this->fsm->transition( 'BROWSING' );
		$this->fsm->transition( 'VIEWING_PRODUCT' );

		$this->fsm->reset();

		$this->assertEquals( 'IDLE', $this->fsm->get_state() );
	}

	/**
	 * Test state persistence.
	 */
	public function test_state_persists_in_database() {
		$this->fsm->transition( 'BROWSING' );

		// Create new FSM instance for same conversation.
		$new_fsm = new WCH_Conversation_FSM( $this->conversation_id );

		$this->assertEquals( 'BROWSING', $new_fsm->get_state() );
	}

	/**
	 * Test transition hooks.
	 */
	public function test_transition_triggers_hooks() {
		$hook_called = false;

		add_action( 'wch_conversation_state_changed', function( $conversation_id, $old_state, $new_state ) use ( &$hook_called ) {
			$hook_called = true;
			$this->assertEquals( 'IDLE', $old_state );
			$this->assertEquals( 'BROWSING', $new_state );
		}, 10, 3 );

		$this->fsm->transition( 'BROWSING' );

		$this->assertTrue( $hook_called );
	}

	/**
	 * Test cancelling from any state.
	 */
	public function test_cancel_from_any_state() {
		$this->fsm->transition( 'CHECKOUT' );
		$result = $this->fsm->transition( 'CANCELLED' );

		$this->assertTrue( $result );
		$this->assertEquals( 'CANCELLED', $this->fsm->get_state() );
	}

	/**
	 * Test getting state history.
	 */
	public function test_get_state_history() {
		$this->fsm->transition( 'BROWSING' );
		$this->fsm->transition( 'VIEWING_PRODUCT' );
		$this->fsm->transition( 'CART_REVIEW' );

		$history = $this->fsm->get_history();

		$this->assertIsArray( $history );
		$this->assertGreaterThanOrEqual( 3, count( $history ) );
	}
}
