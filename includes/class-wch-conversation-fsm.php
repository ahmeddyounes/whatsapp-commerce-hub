<?php
/**
 * Conversation Finite State Machine Class
 *
 * Manages conversation flows through a finite state machine.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Conversation_FSM
 */
class WCH_Conversation_FSM {
	/**
	 * Conversation states.
	 */
	const STATE_IDLE = 'IDLE';
	const STATE_BROWSING = 'BROWSING';
	const STATE_VIEWING_PRODUCT = 'VIEWING_PRODUCT';
	const STATE_CART_MANAGEMENT = 'CART_MANAGEMENT';
	const STATE_CHECKOUT_ADDRESS = 'CHECKOUT_ADDRESS';
	const STATE_CHECKOUT_PAYMENT = 'CHECKOUT_PAYMENT';
	const STATE_CHECKOUT_CONFIRM = 'CHECKOUT_CONFIRM';
	const STATE_AWAITING_HUMAN = 'AWAITING_HUMAN';
	const STATE_COMPLETED = 'COMPLETED';

	/**
	 * Conversation events.
	 */
	const EVENT_START = 'START';
	const EVENT_SELECT_CATEGORY = 'SELECT_CATEGORY';
	const EVENT_SEARCH = 'SEARCH';
	const EVENT_VIEW_PRODUCT = 'VIEW_PRODUCT';
	const EVENT_ADD_TO_CART = 'ADD_TO_CART';
	const EVENT_VIEW_CART = 'VIEW_CART';
	const EVENT_MODIFY_CART = 'MODIFY_CART';
	const EVENT_START_CHECKOUT = 'START_CHECKOUT';
	const EVENT_ENTER_ADDRESS = 'ENTER_ADDRESS';
	const EVENT_SELECT_PAYMENT = 'SELECT_PAYMENT';
	const EVENT_CONFIRM_ORDER = 'CONFIRM_ORDER';
	const EVENT_REQUEST_HUMAN = 'REQUEST_HUMAN';
	const EVENT_AGENT_TAKEOVER = 'AGENT_TAKEOVER';
	const EVENT_TIMEOUT = 'TIMEOUT';
	const EVENT_RESET = 'RESET';

	/**
	 * Timeout duration in seconds (30 minutes).
	 */
	const TIMEOUT_DURATION = 1800;

	/**
	 * Global wpdb instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Transitions array.
	 *
	 * @var array
	 */
	private $transitions = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->initialize_transitions();
	}

	/**
	 * Initialize state machine transitions.
	 */
	private function initialize_transitions() {
		$transitions = array(
			// From IDLE.
			array(
				'from_state'      => self::STATE_IDLE,
				'event'           => self::EVENT_START,
				'to_state'        => self::STATE_BROWSING,
				'guard_condition' => null,
				'action'          => 'show_main_menu',
			),

			// From BROWSING.
			array(
				'from_state'      => self::STATE_BROWSING,
				'event'           => self::EVENT_VIEW_PRODUCT,
				'to_state'        => self::STATE_VIEWING_PRODUCT,
				'guard_condition' => 'product_exists',
				'action'          => 'show_product_details',
			),
			array(
				'from_state'      => self::STATE_BROWSING,
				'event'           => self::EVENT_SELECT_CATEGORY,
				'to_state'        => self::STATE_BROWSING,
				'guard_condition' => null,
				'action'          => 'show_category_products',
			),
			array(
				'from_state'      => self::STATE_BROWSING,
				'event'           => self::EVENT_SEARCH,
				'to_state'        => self::STATE_BROWSING,
				'guard_condition' => null,
				'action'          => 'show_search_results',
			),
			array(
				'from_state'      => self::STATE_BROWSING,
				'event'           => self::EVENT_VIEW_CART,
				'to_state'        => self::STATE_CART_MANAGEMENT,
				'guard_condition' => null,
				'action'          => 'show_cart',
			),

			// From VIEWING_PRODUCT.
			array(
				'from_state'      => self::STATE_VIEWING_PRODUCT,
				'event'           => self::EVENT_ADD_TO_CART,
				'to_state'        => self::STATE_CART_MANAGEMENT,
				'guard_condition' => 'has_stock',
				'action'          => 'add_item_and_show_cart',
			),
			array(
				'from_state'      => self::STATE_VIEWING_PRODUCT,
				'event'           => self::EVENT_VIEW_CART,
				'to_state'        => self::STATE_CART_MANAGEMENT,
				'guard_condition' => null,
				'action'          => 'show_cart',
			),
			array(
				'from_state'      => self::STATE_VIEWING_PRODUCT,
				'event'           => self::EVENT_SEARCH,
				'to_state'        => self::STATE_BROWSING,
				'guard_condition' => null,
				'action'          => 'show_search_results',
			),

			// From CART_MANAGEMENT.
			array(
				'from_state'      => self::STATE_CART_MANAGEMENT,
				'event'           => self::EVENT_START_CHECKOUT,
				'to_state'        => self::STATE_CHECKOUT_ADDRESS,
				'guard_condition' => 'cart_not_empty',
				'action'          => 'request_address',
			),
			array(
				'from_state'      => self::STATE_CART_MANAGEMENT,
				'event'           => self::EVENT_MODIFY_CART,
				'to_state'        => self::STATE_CART_MANAGEMENT,
				'guard_condition' => null,
				'action'          => 'update_cart_and_show',
			),
			array(
				'from_state'      => self::STATE_CART_MANAGEMENT,
				'event'           => self::EVENT_VIEW_PRODUCT,
				'to_state'        => self::STATE_VIEWING_PRODUCT,
				'guard_condition' => 'product_exists',
				'action'          => 'show_product_details',
			),
			array(
				'from_state'      => self::STATE_CART_MANAGEMENT,
				'event'           => self::EVENT_SEARCH,
				'to_state'        => self::STATE_BROWSING,
				'guard_condition' => null,
				'action'          => 'show_search_results',
			),

			// From CHECKOUT_ADDRESS.
			array(
				'from_state'      => self::STATE_CHECKOUT_ADDRESS,
				'event'           => self::EVENT_ENTER_ADDRESS,
				'to_state'        => self::STATE_CHECKOUT_PAYMENT,
				'guard_condition' => 'address_valid',
				'action'          => 'save_address_and_request_payment',
			),

			// From CHECKOUT_PAYMENT.
			array(
				'from_state'      => self::STATE_CHECKOUT_PAYMENT,
				'event'           => self::EVENT_SELECT_PAYMENT,
				'to_state'        => self::STATE_CHECKOUT_CONFIRM,
				'guard_condition' => 'payment_method_valid',
				'action'          => 'show_order_summary',
			),

			// From CHECKOUT_CONFIRM.
			array(
				'from_state'      => self::STATE_CHECKOUT_CONFIRM,
				'event'           => self::EVENT_CONFIRM_ORDER,
				'to_state'        => self::STATE_COMPLETED,
				'guard_condition' => null,
				'action'          => 'create_order_and_confirm',
			),

			// From COMPLETED.
			array(
				'from_state'      => self::STATE_COMPLETED,
				'event'           => self::EVENT_START,
				'to_state'        => self::STATE_BROWSING,
				'guard_condition' => null,
				'action'          => 'show_main_menu',
			),

			// REQUEST_HUMAN from any state.
			array(
				'from_state'      => '*',
				'event'           => self::EVENT_REQUEST_HUMAN,
				'to_state'        => self::STATE_AWAITING_HUMAN,
				'guard_condition' => null,
				'action'          => 'notify_agent',
			),

			// AGENT_TAKEOVER from AWAITING_HUMAN.
			array(
				'from_state'      => self::STATE_AWAITING_HUMAN,
				'event'           => self::EVENT_AGENT_TAKEOVER,
				'to_state'        => self::STATE_IDLE,
				'guard_condition' => null,
				'action'          => 'transfer_to_agent',
			),

			// TIMEOUT from any active state.
			array(
				'from_state'      => '*',
				'event'           => self::EVENT_TIMEOUT,
				'to_state'        => self::STATE_IDLE,
				'guard_condition' => null,
				'action'          => 'preserve_cart',
			),

			// RESET from any state.
			array(
				'from_state'      => '*',
				'event'           => self::EVENT_RESET,
				'to_state'        => self::STATE_IDLE,
				'guard_condition' => null,
				'action'          => 'clear_context',
			),
		);

		/**
		 * Filter the FSM transitions to allow custom states and events.
		 *
		 * @param array $transitions Array of transition definitions.
		 */
		$this->transitions = apply_filters( 'wch_fsm_transitions', $transitions );
	}

	/**
	 * Transition to a new state.
	 *
	 * @param array  $conversation Conversation data array.
	 * @param string $event Event to trigger.
	 * @param array  $payload Additional event data.
	 * @return array Updated conversation data or WP_Error on failure.
	 */
	public function transition( $conversation, $event, $payload = array() ) {
		// Get current context.
		$context = isset( $conversation['context'] ) ? json_decode( $conversation['context'], true ) : array();
		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$current_state = $context['current_state'] ?? self::STATE_IDLE;

		// Find valid transition.
		$transition = $this->find_transition( $current_state, $event );

		if ( ! $transition ) {
			return new WP_Error(
				'invalid_transition',
				sprintf(
					'Invalid transition: no transition found from state %s with event %s',
					$current_state,
					$event
				)
			);
		}

		// Check guard condition.
		if ( $transition['guard_condition'] ) {
			$guard_result = $this->check_guard( $transition['guard_condition'], $conversation, $payload );
			if ( is_wp_error( $guard_result ) ) {
				return $guard_result;
			}
			if ( ! $guard_result ) {
				return new WP_Error(
					'guard_failed',
					sprintf(
						'Guard condition %s failed for transition from %s to %s',
						$transition['guard_condition'],
						$current_state,
						$transition['to_state']
					)
				);
			}
		}

		// Execute action.
		if ( $transition['action'] ) {
			$action_result = $this->execute_action( $transition['action'], $conversation, $payload );
			if ( is_wp_error( $action_result ) ) {
				return $action_result;
			}
			// Merge action result into payload for context update.
			if ( is_array( $action_result ) ) {
				$payload = array_merge( $payload, $action_result );
			}
		}

		// Update state and context.
		$new_state = $transition['to_state'];
		$context['current_state'] = $new_state;
		$context['last_activity_at'] = current_time( 'mysql' );

		// Update state_data with payload.
		if ( ! isset( $context['state_data'] ) ) {
			$context['state_data'] = array();
		}
		$context['state_data'] = array_merge( $context['state_data'], $payload );

		// Add to conversation history.
		if ( ! isset( $context['conversation_history'] ) ) {
			$context['conversation_history'] = array();
		}
		$context['conversation_history'][] = array(
			'timestamp'  => current_time( 'mysql' ),
			'event'      => $event,
			'from_state' => $current_state,
			'to_state'   => $new_state,
			'payload'    => $payload,
		);

		// Keep only last 10 history items.
		if ( count( $context['conversation_history'] ) > 10 ) {
			$context['conversation_history'] = array_slice( $context['conversation_history'], -10 );
		}

		// Persist to database.
		$conversation['context'] = wp_json_encode( $context );
		$conversation['updated_at'] = current_time( 'mysql' );

		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$this->wpdb->update(
			$table_name,
			array(
				'context'    => $conversation['context'],
				'updated_at' => $conversation['updated_at'],
			),
			array( 'id' => $conversation['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Log transition.
		$this->log_transition( $conversation['id'], $current_state, $new_state, $event, $payload );

		return $conversation;
	}

	/**
	 * Find a valid transition.
	 *
	 * @param string $current_state Current state.
	 * @param string $event Event to trigger.
	 * @return array|null Transition array or null if not found.
	 */
	private function find_transition( $current_state, $event ) {
		foreach ( $this->transitions as $transition ) {
			if ( $transition['event'] === $event ) {
				// Check exact state match.
				if ( $transition['from_state'] === $current_state ) {
					return $transition;
				}
				// Check wildcard match.
				if ( $transition['from_state'] === '*' ) {
					return $transition;
				}
			}
		}
		return null;
	}

	/**
	 * Check guard condition.
	 *
	 * @param string $guard_name Guard condition name.
	 * @param array  $conversation Conversation data.
	 * @param array  $payload Event payload.
	 * @return bool|WP_Error True if guard passes, false or WP_Error otherwise.
	 */
	private function check_guard( $guard_name, $conversation, $payload ) {
		/**
		 * Filter to check custom guard conditions.
		 *
		 * @param bool|null $result Guard result (null to use default logic).
		 * @param string    $guard_name Guard condition name.
		 * @param array     $conversation Conversation data.
		 * @param array     $payload Event payload.
		 */
		$custom_result = apply_filters( 'wch_fsm_guard_check', null, $guard_name, $conversation, $payload );
		if ( $custom_result !== null ) {
			return $custom_result;
		}

		// Default guard implementations.
		switch ( $guard_name ) {
			case 'product_exists':
				return ! empty( $payload['product_id'] );

			case 'has_stock':
				if ( empty( $payload['product_id'] ) ) {
					return false;
				}
				$product = wc_get_product( $payload['product_id'] );
				return $product && $product->is_in_stock();

			case 'cart_not_empty':
				$context = isset( $conversation['context'] ) ? json_decode( $conversation['context'], true ) : array();
				$cart_items = $context['state_data']['cart_items'] ?? array();
				return ! empty( $cart_items );

			case 'address_valid':
				return ! empty( $payload['address'] ) && is_array( $payload['address'] );

			case 'payment_method_valid':
				return ! empty( $payload['payment_method'] );

			default:
				return true;
		}
	}

	/**
	 * Execute action.
	 *
	 * @param string $action_name Action name.
	 * @param array  $conversation Conversation data.
	 * @param array  $payload Event payload.
	 * @return array|WP_Error Action result or WP_Error on failure.
	 */
	private function execute_action( $action_name, $conversation, $payload ) {
		/**
		 * Filter to execute custom actions.
		 *
		 * @param mixed|null $result Action result (null to use default logic).
		 * @param string     $action_name Action name.
		 * @param array      $conversation Conversation data.
		 * @param array      $payload Event payload.
		 */
		$custom_result = apply_filters( 'wch_fsm_action_execute', null, $action_name, $conversation, $payload );
		if ( $custom_result !== null ) {
			return $custom_result;
		}

		// Default action implementations (placeholder - to be implemented by message handlers).
		switch ( $action_name ) {
			case 'show_main_menu':
				return array( 'action_data' => 'main_menu_shown' );

			case 'show_product_details':
				return array( 'action_data' => 'product_details_shown' );

			case 'show_category_products':
				return array( 'action_data' => 'category_products_shown' );

			case 'show_search_results':
				return array( 'action_data' => 'search_results_shown' );

			case 'show_cart':
				return array( 'action_data' => 'cart_shown' );

			case 'add_item_and_show_cart':
				return array( 'action_data' => 'item_added_cart_shown' );

			case 'request_address':
				return array( 'action_data' => 'address_requested' );

			case 'update_cart_and_show':
				return array( 'action_data' => 'cart_updated_shown' );

			case 'save_address_and_request_payment':
				return array( 'action_data' => 'address_saved_payment_requested' );

			case 'show_order_summary':
				return array( 'action_data' => 'order_summary_shown' );

			case 'create_order_and_confirm':
				return array( 'action_data' => 'order_created_confirmed' );

			case 'notify_agent':
				return array( 'action_data' => 'agent_notified' );

			case 'transfer_to_agent':
				return array( 'action_data' => 'transferred_to_agent' );

			case 'preserve_cart':
				return array( 'action_data' => 'cart_preserved' );

			case 'clear_context':
				return array( 'action_data' => 'context_cleared' );

			default:
				return array();
		}
	}

	/**
	 * Get available events from current state.
	 *
	 * @param array $conversation Conversation data.
	 * @return array Available events.
	 */
	public function get_available_events( $conversation ) {
		$context = isset( $conversation['context'] ) ? json_decode( $conversation['context'], true ) : array();
		$current_state = $context['current_state'] ?? self::STATE_IDLE;

		$available_events = array();

		foreach ( $this->transitions as $transition ) {
			// Match current state or wildcard.
			if ( $transition['from_state'] === $current_state || $transition['from_state'] === '*' ) {
				if ( ! in_array( $transition['event'], $available_events, true ) ) {
					$available_events[] = $transition['event'];
				}
			}
		}

		return $available_events;
	}

	/**
	 * Check for timeout and transition if needed.
	 *
	 * @param array $conversation Conversation data.
	 * @return array|null Updated conversation or null if no timeout.
	 */
	public function check_timeout( $conversation ) {
		$context = isset( $conversation['context'] ) ? json_decode( $conversation['context'], true ) : array();
		$current_state = $context['current_state'] ?? self::STATE_IDLE;

		// Don't check timeout for IDLE, COMPLETED, or AWAITING_HUMAN states.
		if ( in_array( $current_state, array( self::STATE_IDLE, self::STATE_COMPLETED, self::STATE_AWAITING_HUMAN ), true ) ) {
			return null;
		}

		$last_activity_at = $context['last_activity_at'] ?? $conversation['updated_at'];
		$last_activity_timestamp = strtotime( $last_activity_at );
		$current_timestamp = current_time( 'timestamp' );

		if ( ( $current_timestamp - $last_activity_timestamp ) >= self::TIMEOUT_DURATION ) {
			return $this->transition( $conversation, self::EVENT_TIMEOUT );
		}

		return null;
	}

	/**
	 * Log state transition.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $from_state From state.
	 * @param string $to_state To state.
	 * @param string $event Event.
	 * @param array  $payload Payload.
	 */
	private function log_transition( $conversation_id, $from_state, $to_state, $event, $payload ) {
		if ( class_exists( 'WCH_Logger' ) ) {
			WCH_Logger::log(
				sprintf(
					'FSM Transition: Conversation %d - %s -> %s (Event: %s)',
					$conversation_id,
					$from_state,
					$to_state,
					$event
				),
				array(
					'conversation_id' => $conversation_id,
					'from_state'      => $from_state,
					'to_state'        => $to_state,
					'event'           => $event,
					'payload'         => $payload,
				),
				'info'
			);
		}
	}
}
