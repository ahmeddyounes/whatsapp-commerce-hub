<?php
/**
 * Conversation Context Class
 *
 * Stores conversation state and temporary data for the FSM.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Conversation_Context
 */
class WCH_Conversation_Context {
	/**
	 * Current state.
	 *
	 * @var string
	 */
	public $current_state;

	/**
	 * State-specific data (e.g., selected_product_id, cart_items).
	 *
	 * @var array
	 */
	public $state_data;

	/**
	 * Conversation history (last 10 exchanges).
	 *
	 * @var array
	 */
	public $conversation_history;

	/**
	 * Extracted entity values (product_name, quantity, address, etc.).
	 *
	 * @var array
	 */
	public $slots;

	/**
	 * Customer phone number.
	 *
	 * @var string
	 */
	public $customer_phone;

	/**
	 * When the conversation started.
	 *
	 * @var string
	 */
	public $started_at;

	/**
	 * Last activity timestamp.
	 *
	 * @var string
	 */
	public $last_activity_at;

	/**
	 * When the context expires (24 hours from last activity).
	 *
	 * @var string
	 */
	public $expires_at;

	/**
	 * Constructor.
	 *
	 * @param array $data Context data.
	 */
	public function __construct( $data = array() ) {
		$this->current_state = $data['current_state'] ?? WCH_Conversation_FSM::STATE_IDLE;
		$this->state_data = $data['state_data'] ?? array();
		$this->conversation_history = $data['conversation_history'] ?? array();
		$this->slots = $data['slots'] ?? array();
		$this->customer_phone = $data['customer_phone'] ?? '';
		$this->started_at = $data['started_at'] ?? current_time( 'mysql' );
		$this->last_activity_at = $data['last_activity_at'] ?? current_time( 'mysql' );

		// Calculate expires_at (24 hours from last activity)
		if ( isset( $data['expires_at'] ) ) {
			$this->expires_at = $data['expires_at'];
		} else {
			$last_activity_timestamp = strtotime( $this->last_activity_at );
			$this->expires_at = gmdate( 'Y-m-d H:i:s', $last_activity_timestamp + ( 24 * 3600 ) );
		}
	}

	/**
	 * Convert context to array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'current_state'        => $this->current_state,
			'state_data'           => $this->state_data,
			'conversation_history' => $this->conversation_history,
			'slots'                => $this->slots,
			'customer_phone'       => $this->customer_phone,
			'started_at'           => $this->started_at,
			'last_activity_at'     => $this->last_activity_at,
			'expires_at'           => $this->expires_at,
		);
	}

	/**
	 * Convert context to JSON string.
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode( $this->to_array() );
	}

	/**
	 * Create context from JSON string.
	 *
	 * @param string $json JSON string.
	 * @return WCH_Conversation_Context
	 */
	public static function from_json( $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return new self( $data );
	}

	/**
	 * Update state data.
	 *
	 * @param array $data Data to merge into state_data.
	 */
	public function update_state_data( $data ) {
		$this->state_data = array_merge( $this->state_data, $data );
		$this->update_activity();
	}

	/**
	 * Update last activity and expires_at timestamps.
	 */
	private function update_activity() {
		$this->last_activity_at = current_time( 'mysql' );
		$last_activity_timestamp = strtotime( $this->last_activity_at );
		$this->expires_at = gmdate( 'Y-m-d H:i:s', $last_activity_timestamp + ( 24 * 3600 ) );
	}

	/**
	 * Get state data value.
	 *
	 * @param string $key Data key.
	 * @param mixed  $default Default value if key not found.
	 * @return mixed
	 */
	public function get_state_data( $key, $default = null ) {
		return $this->state_data[ $key ] ?? $default;
	}

	/**
	 * Set a slot value.
	 *
	 * @param string $name Slot name.
	 * @param mixed  $value Slot value.
	 */
	public function set_slot( $name, $value ) {
		$this->slots[ $name ] = $value;
		$this->update_activity();
	}

	/**
	 * Get a slot value.
	 *
	 * @param string $name Slot name.
	 * @param mixed  $default Default value if slot not found.
	 * @return mixed
	 */
	public function get_slot( $name, $default = null ) {
		return $this->slots[ $name ] ?? $default;
	}

	/**
	 * Check if a slot exists.
	 *
	 * @param string $name Slot name.
	 * @return bool
	 */
	public function has_slot( $name ) {
		return isset( $this->slots[ $name ] );
	}

	/**
	 * Clear a slot.
	 *
	 * @param string $name Slot name.
	 */
	public function clear_slot( $name ) {
		unset( $this->slots[ $name ] );
		$this->update_activity();
	}

	/**
	 * Get all slots.
	 *
	 * @return array
	 */
	public function get_all_slots() {
		return $this->slots;
	}

	/**
	 * Add entry to conversation history.
	 *
	 * @param string $event Event name.
	 * @param string $from_state From state.
	 * @param string $to_state To state.
	 * @param array  $payload Event payload.
	 */
	public function add_history_entry( $event, $from_state, $to_state, $payload = array() ) {
		$this->conversation_history[] = array(
			'timestamp'  => current_time( 'mysql' ),
			'event'      => $event,
			'from_state' => $from_state,
			'to_state'   => $to_state,
			'payload'    => $payload,
		);

		// Keep only last 10 entries.
		if ( count( $this->conversation_history ) > 10 ) {
			$this->conversation_history = array_slice( $this->conversation_history, -10 );
		}

		$this->update_activity();
	}

	/**
	 * Add message exchange to conversation history.
	 *
	 * @param string $user_message User message text.
	 * @param string $bot_response Bot response text.
	 */
	public function add_exchange( $user_message, $bot_response ) {
		$this->conversation_history[] = array(
			'timestamp'    => current_time( 'mysql' ),
			'user_message' => $user_message,
			'bot_response' => $bot_response,
		);

		// Keep only last 10 message pairs.
		if ( count( $this->conversation_history ) > 10 ) {
			$this->conversation_history = array_slice( $this->conversation_history, -10 );
		}

		$this->update_activity();
	}

	/**
	 * Get conversation history.
	 *
	 * @return array
	 */
	public function get_history() {
		return $this->conversation_history;
	}

	/**
	 * Get the last exchange from history.
	 *
	 * @return array|null
	 */
	public function get_last_exchange() {
		if ( empty( $this->conversation_history ) ) {
			return null;
		}
		return end( $this->conversation_history );
	}

	/**
	 * Get the last history entry.
	 *
	 * @return array|null
	 */
	public function get_last_history_entry() {
		if ( empty( $this->conversation_history ) ) {
			return null;
		}
		return end( $this->conversation_history );
	}

	/**
	 * Clear all state data.
	 */
	public function clear_state_data() {
		$this->state_data = array();
		$this->update_activity();
	}

	/**
	 * Reset context to initial state.
	 */
	public function reset() {
		$this->current_state = WCH_Conversation_FSM::STATE_IDLE;
		$this->state_data = array();
		$this->conversation_history = array();
		$this->started_at = current_time( 'mysql' );
		$this->last_activity_at = current_time( 'mysql' );
	}

	/**
	 * Check if context has timed out.
	 *
	 * @param int $timeout_seconds Timeout duration in seconds.
	 * @return bool
	 */
	public function is_timed_out( $timeout_seconds = WCH_Conversation_FSM::TIMEOUT_DURATION ) {
		$last_activity_timestamp = strtotime( $this->last_activity_at );
		$current_timestamp = current_time( 'timestamp' );
		return ( $current_timestamp - $last_activity_timestamp ) >= $timeout_seconds;
	}

	/**
	 * Get time since last activity in seconds.
	 *
	 * @return int
	 */
	public function get_inactive_duration() {
		$last_activity_timestamp = strtotime( $this->last_activity_at );
		$current_timestamp = current_time( 'timestamp' );
		return $current_timestamp - $last_activity_timestamp;
	}

	/**
	 * Build AI context string for prompts.
	 *
	 * @return string Formatted context for AI prompts.
	 */
	public function build_ai_context() {
		$context_parts = array();

		// Business information
		$business_name = get_bloginfo( 'name' );
		$context_parts[] = "Business: {$business_name}";

		// Current state
		$context_parts[] = "Current State: {$this->current_state}";

		// Filled slots
		if ( ! empty( $this->slots ) ) {
			$context_parts[] = "\nExtracted Information:";
			foreach ( $this->slots as $slot_name => $slot_value ) {
				if ( is_array( $slot_value ) ) {
					$slot_value = wp_json_encode( $slot_value );
				}
				$context_parts[] = "- {$slot_name}: {$slot_value}";
			}
		}

		// Recent conversation history
		if ( ! empty( $this->conversation_history ) ) {
			$context_parts[] = "\nRecent Conversation:";
			foreach ( $this->conversation_history as $exchange ) {
				if ( isset( $exchange['user_message'] ) && isset( $exchange['bot_response'] ) ) {
					$context_parts[] = "User: {$exchange['user_message']}";
					$context_parts[] = "Bot: {$exchange['bot_response']}";
				}
			}
		}

		// Available actions based on current state
		$context_parts[] = "\nAvailable Actions:";
		$available_actions = $this->get_available_actions_for_state();
		if ( ! empty( $available_actions ) ) {
			foreach ( $available_actions as $action ) {
				$context_parts[] = "- {$action}";
			}
		} else {
			$context_parts[] = "- Continue conversation based on context";
		}

		return implode( "\n", $context_parts );
	}

	/**
	 * Get available actions for current state.
	 *
	 * @return array Available actions.
	 */
	private function get_available_actions_for_state() {
		$actions = array();

		switch ( $this->current_state ) {
			case WCH_Conversation_FSM::STATE_IDLE:
				$actions = array( 'Start browsing', 'Search products', 'View cart' );
				break;

			case WCH_Conversation_FSM::STATE_BROWSING:
				$actions = array( 'View product details', 'Search products', 'View cart', 'Select category' );
				break;

			case WCH_Conversation_FSM::STATE_VIEWING_PRODUCT:
				$actions = array( 'Add to cart', 'Continue browsing', 'View cart' );
				break;

			case WCH_Conversation_FSM::STATE_CART_MANAGEMENT:
				$actions = array( 'Modify cart', 'Start checkout', 'Continue browsing' );
				break;

			case WCH_Conversation_FSM::STATE_CHECKOUT_ADDRESS:
				$actions = array( 'Enter address', 'Return to cart' );
				break;

			case WCH_Conversation_FSM::STATE_CHECKOUT_PAYMENT:
				$actions = array( 'Select payment method', 'Return to cart' );
				break;

			case WCH_Conversation_FSM::STATE_CHECKOUT_CONFIRM:
				$actions = array( 'Confirm order', 'Return to cart' );
				break;

			case WCH_Conversation_FSM::STATE_COMPLETED:
				$actions = array( 'Start new conversation', 'View order status' );
				break;

			case WCH_Conversation_FSM::STATE_AWAITING_HUMAN:
				$actions = array( 'Wait for agent' );
				break;

			default:
				$actions = array();
				break;
		}

		return $actions;
	}
}
