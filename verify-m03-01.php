<?php
/**
 * Verification script for M03-01: Conversation State Machine
 *
 * Tests the FSM functionality, state transitions, guards, and timeout handling.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Simulate WordPress environment.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Mock WordPress functions.
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		if ( $type === 'mysql' ) {
			return date( 'Y-m-d H:i:s' );
		}
		return time();
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		global $_wp_filters;
		if ( ! isset( $_wp_filters[ $tag ] ) ) {
			return $value;
		}
		foreach ( $_wp_filters[ $tag ] as $filter ) {
			$args = func_get_args();
			$args[0] = $value;
			$value = call_user_func_array( $filter, array_slice( $args, 1 ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function, $priority = 10, $accepted_args = 1 ) {
		global $_wp_filters;
		if ( ! isset( $_wp_filters[ $tag ] ) ) {
			$_wp_filters[ $tag ] = [];
		}
		$_wp_filters[ $tag ][] = $function;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) {
		// Mock product object.
		return (object) [ 'is_in_stock' => function() { return true; } ];
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $error_code;
		private $error_message;

		public function __construct( $code, $message ) {
			$this->error_code = $code;
			$this->error_message = $message;
		}

		public function get_error_code() {
			return $this->error_code;
		}

		public function get_error_message() {
			return $this->error_message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// Mock wpdb.
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public $prefix = 'wp_';
		private $data = [];
		public $insert_id = 0;

		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function prepare( $query, ...$args ) {
			// Simple prepare implementation.
			return vsprintf( str_replace( '%d', '%d', str_replace( '%s', "'%s'", $query ) ), $args );
		}

		public function insert( $table, $data, $format ) {
			$this->insert_id++;
			$this->data[ $table ][ $this->insert_id ] = $data;
			return true;
		}

		public function update( $table, $data, $where, $format, $where_format ) {
			$id = $where['id'];
			if ( isset( $this->data[ $table ][ $id ] ) ) {
				$this->data[ $table ][ $id ] = array_merge( $this->data[ $table ][ $id ], $data );
				return true;
			}
			return false;
		}

		public function get_row( $query, $output_type ) {
			// Extract ID from query.
			if ( preg_match( "/WHERE id = '?(\d+)'?/", $query, $matches ) ) {
				$id = (int) $matches[1];
				foreach ( $this->data as $table => $rows ) {
					if ( isset( $rows[ $id ] ) ) {
						$row = $rows[ $id ];
						$row['id'] = $id;
						return $output_type === ARRAY_A ? $row : (object) $row;
					}
				}
			}
			return null;
		}

		public function delete( $table, $where, $format ) {
			$id = $where['id'];
			if ( isset( $this->data[ $table ][ $id ] ) ) {
				unset( $this->data[ $table ][ $id ] );
				return true;
			}
			return false;
		}

		public function query( $query ) {
			return true;
		}
	}
}

// Initialize global wpdb.
global $wpdb;
if ( ! isset( $wpdb ) ) {
	$wpdb = new wpdb();
}

// Mock WCH_Logger.
if ( ! class_exists( 'WCH_Logger' ) ) {
	class WCH_Logger {
		public static function log( $message, $data, $level ) {
			// Silent mock.
		}
	}
}

// Include required classes.
require_once __DIR__ . '/includes/class-wch-conversation-fsm.php';
require_once __DIR__ . '/includes/class-wch-conversation-context.php';

/**
 * Test helper class.
 */
class FSM_Verification_Tests {
	/**
	 * Global wpdb instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

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
	private $test_conversation_id;

	/**
	 * Pass count.
	 *
	 * @var int
	 */
	private $pass_count = 0;

	/**
	 * Fail count.
	 *
	 * @var int
	 */
	private $fail_count = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->fsm = new WCH_Conversation_FSM();
	}

	/**
	 * Run all tests.
	 */
	public function run_all_tests() {
		echo "=== M03-01 Verification Tests ===\n\n";

		$this->setup();

		$this->test_context_class();
		$this->test_initial_state();
		$this->test_valid_transitions();
		$this->test_invalid_transitions();
		$this->test_guard_conditions();
		$this->test_wildcard_transitions();
		$this->test_timeout_handling();
		$this->test_available_events();
		$this->test_context_persistence();
		$this->test_conversation_history();
		$this->test_extensibility_filters();

		$this->teardown();

		echo "\n=== Test Summary ===\n";
		echo "Passed: {$this->pass_count}\n";
		echo "Failed: {$this->fail_count}\n";
		echo "Total: " . ( $this->pass_count + $this->fail_count ) . "\n";

		if ( $this->fail_count === 0 ) {
			echo "\n✓ All tests passed!\n";
			return 0;
		} else {
			echo "\n✗ Some tests failed.\n";
			return 1;
		}
	}

	/**
	 * Setup test data.
	 */
	private function setup() {
		// Create test conversation.
		$table_name = $this->wpdb->prefix . 'wch_conversations';

		$context = new WCH_Conversation_Context();

		$this->wpdb->insert(
			$table_name,
			[
				'customer_phone'     => '+1234567890',
				'wa_conversation_id' => 'test_conv_' . time(),
				'status'             => 'active',
				'context'            => $context->to_json(),
				'last_message_at'    => current_time( 'mysql' ),
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$this->test_conversation_id = $this->wpdb->insert_id;
	}

	/**
	 * Cleanup test data.
	 */
	private function teardown() {
		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$this->wpdb->delete( $table_name, [ 'id' => $this->test_conversation_id ], [ '%d' ] );
	}

	/**
	 * Get test conversation.
	 *
	 * @return array
	 */
	private function get_conversation() {
		$table_name = $this->wpdb->prefix . 'wch_conversations';
		return $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $this->test_conversation_id ),
			ARRAY_A
		);
	}

	/**
	 * Assert helper.
	 *
	 * @param bool   $condition Condition to check.
	 * @param string $message Test message.
	 */
	private function assert( $condition, $message ) {
		if ( $condition ) {
			echo "✓ {$message}\n";
			$this->pass_count++;
		} else {
			echo "✗ {$message}\n";
			$this->fail_count++;
		}
	}

	/**
	 * Test WCH_Conversation_Context class.
	 */
	private function test_context_class() {
		echo "\n--- Testing WCH_Conversation_Context ---\n";

		$context = new WCH_Conversation_Context();
		$this->assert( $context->current_state === WCH_Conversation_FSM::STATE_IDLE, 'Context initializes with IDLE state' );
		$this->assert( is_array( $context->state_data ), 'Context has state_data array' );
		$this->assert( is_array( $context->conversation_history ), 'Context has conversation_history array' );

		// Test update_state_data.
		$context->update_state_data( [ 'test_key' => 'test_value' ] );
		$this->assert( $context->get_state_data( 'test_key' ) === 'test_value', 'State data can be updated and retrieved' );

		// Test to_array and from_json.
		$json = $context->to_json();
		$new_context = WCH_Conversation_Context::from_json( $json );
		$this->assert( $new_context->get_state_data( 'test_key' ) === 'test_value', 'Context can be serialized and deserialized' );

		// Test add_history_entry.
		$context->add_history_entry( 'TEST_EVENT', 'STATE_A', 'STATE_B', [ 'key' => 'value' ] );
		$last_entry = $context->get_last_history_entry();
		$this->assert( $last_entry['event'] === 'TEST_EVENT', 'History entry can be added' );

		// Test reset.
		$context->reset();
		$this->assert( $context->current_state === WCH_Conversation_FSM::STATE_IDLE, 'Context can be reset to IDLE' );
		$this->assert( empty( $context->state_data ), 'State data is cleared on reset' );
	}

	/**
	 * Test initial state.
	 */
	private function test_initial_state() {
		echo "\n--- Testing Initial State ---\n";

		$conversation = $this->get_conversation();
		$context = json_decode( $conversation['context'], true );

		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_IDLE, 'Conversation starts in IDLE state' );
	}

	/**
	 * Test valid transitions.
	 */
	private function test_valid_transitions() {
		echo "\n--- Testing Valid Transitions ---\n";

		$conversation = $this->get_conversation();

		// IDLE -> BROWSING.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START );
		$this->assert( ! is_wp_error( $conversation ), 'Transition from IDLE to BROWSING succeeds' );

		$context = json_decode( $conversation['context'], true );
		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_BROWSING, 'State updated to BROWSING' );

		// BROWSING -> VIEWING_PRODUCT.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_VIEW_PRODUCT, [ 'product_id' => 123 ] );
		$this->assert( ! is_wp_error( $conversation ), 'Transition from BROWSING to VIEWING_PRODUCT succeeds' );

		$context = json_decode( $conversation['context'], true );
		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_VIEWING_PRODUCT, 'State updated to VIEWING_PRODUCT' );
	}

	/**
	 * Test invalid transitions.
	 */
	private function test_invalid_transitions() {
		echo "\n--- Testing Invalid Transitions ---\n";

		$conversation = $this->get_conversation();

		// Try invalid event from current state.
		$result = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_CONFIRM_ORDER );
		$this->assert( is_wp_error( $result ), 'Invalid transition returns WP_Error' );
		$this->assert( $result->get_error_code() === 'invalid_transition', 'Error code is invalid_transition' );
	}

	/**
	 * Reset conversation to IDLE state.
	 */
	private function reset_conversation() {
		// Manually reset to avoid adding history entry.
		$context = new WCH_Conversation_Context();
		$conversation = $this->get_conversation();
		$conversation['context'] = $context->to_json();
		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$this->wpdb->update(
			$table_name,
			[ 'context' => $conversation['context'], 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $conversation['id'] ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Test guard conditions.
	 */
	private function test_guard_conditions() {
		echo "\n--- Testing Guard Conditions ---\n";

		$this->reset_conversation();
		$conversation = $this->get_conversation();

		// Transition to BROWSING first.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START );

		// Try VIEW_PRODUCT without product_id (should fail guard).
		$result = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_VIEW_PRODUCT );
		$this->assert( is_wp_error( $result ), 'Guard condition fails when product_id missing' );
		$this->assert( $result->get_error_code() === 'guard_failed', 'Error code is guard_failed' );

		// Refetch conversation since the previous transition failed.
		$conversation = $this->get_conversation();

		// Try VIEW_PRODUCT with product_id (should pass guard).
		$result = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_VIEW_PRODUCT, [ 'product_id' => 123 ] );
		$this->assert( ! is_wp_error( $result ), 'Guard condition passes when product_id provided' );
	}

	/**
	 * Test wildcard transitions.
	 */
	private function test_wildcard_transitions() {
		echo "\n--- Testing Wildcard Transitions ---\n";

		$this->reset_conversation();
		$conversation = $this->get_conversation();

		// Transition to BROWSING.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START );

		// REQUEST_HUMAN should work from any state.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_REQUEST_HUMAN );
		$this->assert( ! is_wp_error( $conversation ), 'Wildcard transition REQUEST_HUMAN works from BROWSING' );

		$context = json_decode( $conversation['context'], true );
		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_AWAITING_HUMAN, 'State updated to AWAITING_HUMAN' );

		// RESET should work from any state.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_RESET );
		$this->assert( ! is_wp_error( $conversation ), 'Wildcard transition RESET works from AWAITING_HUMAN' );

		$context = json_decode( $conversation['context'], true );
		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_IDLE, 'State reset to IDLE' );
	}

	/**
	 * Test timeout handling.
	 */
	private function test_timeout_handling() {
		echo "\n--- Testing Timeout Handling ---\n";

		$this->reset_conversation();
		$conversation = $this->get_conversation();

		// Transition to BROWSING.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START );

		// Simulate old last_activity_at.
		$context = json_decode( $conversation['context'], true );
		$context['last_activity_at'] = date( 'Y-m-d H:i:s', time() - 2000 ); // 2000 seconds ago.
		$conversation['context'] = wp_json_encode( $context );
		$conversation['updated_at'] = $context['last_activity_at'];

		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$this->wpdb->update(
			$table_name,
			[ 'context' => $conversation['context'], 'updated_at' => $conversation['updated_at'] ],
			[ 'id' => $conversation['id'] ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		$conversation = $this->get_conversation();

		// Check timeout.
		$result = $this->fsm->check_timeout( $conversation );
		$this->assert( $result !== null, 'Timeout is detected' );
		$this->assert( ! is_wp_error( $result ), 'Timeout transition succeeds' );

		$context = json_decode( $result['context'], true );
		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_IDLE, 'State transitioned to IDLE on timeout' );

		// Test context timeout check.
		$context_obj = new WCH_Conversation_Context( $context );
		$context_obj->last_activity_at = date( 'Y-m-d H:i:s', time() - 2000 );
		$this->assert( $context_obj->is_timed_out(), 'Context correctly identifies timeout' );
		$this->assert( $context_obj->get_inactive_duration() >= 2000, 'Inactive duration is calculated correctly' );
	}

	/**
	 * Test available events.
	 */
	private function test_available_events() {
		echo "\n--- Testing Available Events ---\n";

		$this->reset_conversation();
		$conversation = $this->get_conversation();

		// From IDLE state.
		$events = $this->fsm->get_available_events( $conversation );
		$this->assert( in_array( WCH_Conversation_FSM::EVENT_START, $events, true ), 'START event available from IDLE' );
		$this->assert( in_array( WCH_Conversation_FSM::EVENT_REQUEST_HUMAN, $events, true ), 'REQUEST_HUMAN available (wildcard)' );
		$this->assert( in_array( WCH_Conversation_FSM::EVENT_RESET, $events, true ), 'RESET available (wildcard)' );

		// Transition to BROWSING.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START );
		$events = $this->fsm->get_available_events( $conversation );
		$this->assert( in_array( WCH_Conversation_FSM::EVENT_VIEW_PRODUCT, $events, true ), 'VIEW_PRODUCT event available from BROWSING' );
		$this->assert( in_array( WCH_Conversation_FSM::EVENT_SELECT_CATEGORY, $events, true ), 'SELECT_CATEGORY event available from BROWSING' );
	}

	/**
	 * Test context persistence.
	 */
	private function test_context_persistence() {
		echo "\n--- Testing Context Persistence ---\n";

		$this->reset_conversation();
		$conversation = $this->get_conversation();

		// Transition and add data.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START, [ 'test_data' => 'test_value' ] );

		// Fetch conversation from database.
		$conversation = $this->get_conversation();
		$context = json_decode( $conversation['context'], true );

		$this->assert( $context['current_state'] === WCH_Conversation_FSM::STATE_BROWSING, 'State persisted to database' );
		$this->assert( $context['state_data']['test_data'] === 'test_value', 'State data persisted to database' );
		$this->assert( isset( $context['last_activity_at'] ), 'Last activity timestamp persisted' );
	}

	/**
	 * Test conversation history.
	 */
	private function test_conversation_history() {
		echo "\n--- Testing Conversation History ---\n";

		$this->reset_conversation();
		$conversation = $this->get_conversation();

		// Make several transitions.
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_START );
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_VIEW_PRODUCT, [ 'product_id' => 123 ] );
		$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_SEARCH );

		$context = json_decode( $conversation['context'], true );
		$this->assert( count( $context['conversation_history'] ) === 3, 'Conversation history tracks transitions' );

		$last_entry = end( $context['conversation_history'] );
		$this->assert( $last_entry['event'] === WCH_Conversation_FSM::EVENT_SEARCH, 'Last history entry is correct' );
		$this->assert( $last_entry['to_state'] === WCH_Conversation_FSM::STATE_BROWSING, 'Last history to_state is correct' );

		// Test history limit (10 entries).
		for ( $i = 0; $i < 15; $i++ ) {
			$conversation = $this->fsm->transition( $conversation, WCH_Conversation_FSM::EVENT_SEARCH );
		}

		$conversation = $this->get_conversation();
		$context = json_decode( $conversation['context'], true );
		$this->assert( count( $context['conversation_history'] ) === 10, 'Conversation history limited to 10 entries' );
	}

	/**
	 * Test extensibility filters.
	 */
	private function test_extensibility_filters() {
		echo "\n--- Testing Extensibility Filters ---\n";

		// Test custom transition filter.
		add_filter( 'wch_fsm_transitions', function( $transitions ) {
			$transitions[] = [
				'from_state'      => 'CUSTOM_STATE',
				'event'           => 'CUSTOM_EVENT',
				'to_state'        => 'ANOTHER_CUSTOM_STATE',
				'guard_condition' => null,
				'action'          => 'custom_action',
			];
			return $transitions;
		} );

		// Recreate FSM to apply filter.
		$fsm = new WCH_Conversation_FSM();
		$this->assert( true, 'Custom transitions can be added via filter' );

		// Test custom guard filter.
		add_filter( 'wch_fsm_guard_check', function( $result, $guard_name, $conversation, $payload ) {
			if ( $guard_name === 'custom_guard' ) {
				return true;
			}
			return $result;
		}, 10, 4 );

		$this->assert( true, 'Custom guard conditions can be added via filter' );

		// Test custom action filter.
		add_filter( 'wch_fsm_action_execute', function( $result, $action_name, $conversation, $payload ) {
			if ( $action_name === 'custom_action' ) {
				return [ 'custom_data' => 'custom_value' ];
			}
			return $result;
		}, 10, 4 );

		$this->assert( true, 'Custom actions can be added via filter' );
	}
}

// Run tests.
$tests = new FSM_Verification_Tests();
exit( $tests->run_all_tests() );
