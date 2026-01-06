<?php
/**
 * WCH Action Result
 *
 * Represents the result of executing a flow action.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_Result class
 *
 * Contains the result of a flow action execution, including success status,
 * response messages, optional state override, and updated context data.
 */
class WCH_Action_Result {
	/**
	 * Whether the action executed successfully
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * Array of WCH_Message_Builder instances to send to the customer
	 *
	 * @var array
	 */
	public $response_messages;

	/**
	 * Optional state override - if set, FSM will transition to this state
	 *
	 * @var string|null
	 */
	public $next_state;

	/**
	 * Updated context data to merge into conversation context
	 *
	 * @var array
	 */
	public $updated_context;

	/**
	 * Constructor
	 *
	 * @param bool   $success Whether the action succeeded.
	 * @param array  $response_messages Array of WCH_Message_Builder instances.
	 * @param string $next_state Optional state override.
	 * @param array  $updated_context Context updates.
	 */
	public function __construct( $success = true, $response_messages = array(), $next_state = null, $updated_context = array() ) {
		$this->success           = $success;
		$this->response_messages = $response_messages;
		$this->next_state        = $next_state;
		$this->updated_context   = $updated_context;
	}

	/**
	 * Create a successful result
	 *
	 * @param array  $messages Response messages.
	 * @param string $next_state Optional state override.
	 * @param array  $context Context updates.
	 * @return WCH_Action_Result
	 */
	public static function success( $messages = array(), $next_state = null, $context = array() ) {
		return new self( true, $messages, $next_state, $context );
	}

	/**
	 * Create a failure result
	 *
	 * @param array  $messages Error messages.
	 * @param string $next_state Optional state override.
	 * @param array  $context Context updates.
	 * @return WCH_Action_Result
	 */
	public static function failure( $messages = array(), $next_state = null, $context = array() ) {
		return new self( false, $messages, $next_state, $context );
	}

	/**
	 * Check if action was successful
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->success === true;
	}

	/**
	 * Get all response messages
	 *
	 * @return array
	 */
	public function get_messages() {
		return $this->response_messages;
	}

	/**
	 * Get built messages ready for WhatsApp API
	 *
	 * @return array Array of built message arrays.
	 */
	public function get_built_messages() {
		$built = array();
		foreach ( $this->response_messages as $message ) {
			if ( $message instanceof WCH_Message_Builder ) {
				$built[] = $message->build();
			}
		}
		return $built;
	}

	/**
	 * Add a response message
	 *
	 * @param WCH_Message_Builder $message Message to add.
	 * @return WCH_Action_Result
	 */
	public function add_message( $message ) {
		$this->response_messages[] = $message;
		return $this;
	}

	/**
	 * Set next state
	 *
	 * @param string $state State to transition to.
	 * @return WCH_Action_Result
	 */
	public function set_next_state( $state ) {
		$this->next_state = $state;
		return $this;
	}

	/**
	 * Update context data
	 *
	 * @param array $context Context data to merge.
	 * @return WCH_Action_Result
	 */
	public function update_context( $context ) {
		$this->updated_context = array_merge( $this->updated_context, $context );
		return $this;
	}

	/**
	 * Get next state if set
	 *
	 * @return string|null
	 */
	public function get_next_state() {
		return $this->next_state;
	}

	/**
	 * Get updated context
	 *
	 * @return array
	 */
	public function get_context() {
		return $this->updated_context;
	}
}
