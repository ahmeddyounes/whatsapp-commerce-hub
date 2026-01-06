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
	 * Constructor.
	 *
	 * @param array $data Context data.
	 */
	public function __construct( $data = array() ) {
		$this->current_state = $data['current_state'] ?? WCH_Conversation_FSM::STATE_IDLE;
		$this->state_data = $data['state_data'] ?? array();
		$this->conversation_history = $data['conversation_history'] ?? array();
		$this->started_at = $data['started_at'] ?? current_time( 'mysql' );
		$this->last_activity_at = $data['last_activity_at'] ?? current_time( 'mysql' );
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
			'started_at'           => $this->started_at,
			'last_activity_at'     => $this->last_activity_at,
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
		$this->last_activity_at = current_time( 'mysql' );
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

		$this->last_activity_at = current_time( 'mysql' );
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
		$this->last_activity_at = current_time( 'mysql' );
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
}
