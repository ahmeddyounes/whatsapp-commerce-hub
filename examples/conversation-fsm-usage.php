<?php
/**
 * Conversation FSM Usage Example
 *
 * Demonstrates how to use the WCH_Conversation_FSM class.
 *
 * @package WhatsApp_Commerce_Hub
 */

// This is an example file - do not execute directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example: Initialize the FSM
 */
function example_initialize_fsm() {
	$fsm = new WCH_Conversation_FSM();
	return $fsm;
}

/**
 * Example: Start a new conversation
 */
function example_start_conversation( $conversation ) {
	$fsm = new WCH_Conversation_FSM();

	// Transition from IDLE to BROWSING.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_START
	);

	if ( is_wp_error( $conversation ) ) {
		// Handle error.
		return $conversation->get_error_message();
	}

	return $conversation;
}

/**
 * Example: View a product
 */
function example_view_product( $conversation, $product_id ) {
	$fsm = new WCH_Conversation_FSM();

	// Transition to VIEWING_PRODUCT state.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_VIEW_PRODUCT,
		[ 'product_id' => $product_id ]
	);

	if ( is_wp_error( $conversation ) ) {
		return $conversation->get_error_message();
	}

	return $conversation;
}

/**
 * Example: Add item to cart
 */
function example_add_to_cart( $conversation, $product_id, $quantity = 1 ) {
	$fsm = new WCH_Conversation_FSM();

	// Transition to CART_MANAGEMENT state.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_ADD_TO_CART,
		[
			'product_id' => $product_id,
			'quantity'   => $quantity,
		]
	);

	if ( is_wp_error( $conversation ) ) {
		return $conversation->get_error_message();
	}

	return $conversation;
}

/**
 * Example: Get available events for current state
 */
function example_get_available_events( $conversation ) {
	$fsm = new WCH_Conversation_FSM();

	$events = $fsm->get_available_events( $conversation );

	// Returns array of event constants, e.g.:
	// [ 'START', 'REQUEST_HUMAN', 'RESET' ]

	return $events;
}

/**
 * Example: Check for timeout
 */
function example_check_timeout( $conversation ) {
	$fsm = new WCH_Conversation_FSM();

	$result = $fsm->check_timeout( $conversation );

	if ( $result !== null ) {
		// Conversation has timed out and been reset to IDLE.
		return $result;
	}

	// No timeout.
	return $conversation;
}

/**
 * Example: Working with conversation context
 */
function example_use_context( $conversation ) {
	// Parse context from conversation.
	$context = WCH_Conversation_Context::from_json( $conversation['context'] );

	// Get current state.
	$current_state = $context->current_state;

	// Get state data.
	$product_id = $context->get_state_data( 'product_id' );

	// Update state data.
	$context->update_state_data( [ 'selected_category' => 'electronics' ] );

	// Get last history entry.
	$last_entry = $context->get_last_history_entry();

	// Check if timed out.
	if ( $context->is_timed_out() ) {
		// Handle timeout.
	}

	// Convert back to JSON for storage.
	$conversation['context'] = $context->to_json();

	return $conversation;
}

/**
 * Example: Add custom transition
 */
function example_add_custom_transition() {
	add_filter( 'wch_fsm_transitions', function( $transitions ) {
		// Add a custom transition.
		$transitions[] = [
			'from_state'      => 'BROWSING',
			'event'           => 'CUSTOM_EVENT',
			'to_state'        => 'CUSTOM_STATE',
			'guard_condition' => 'custom_guard',
			'action'          => 'custom_action',
		];

		return $transitions;
	} );
}

/**
 * Example: Add custom guard condition
 */
function example_add_custom_guard() {
	add_filter( 'wch_fsm_guard_check', function( $result, $guard_name, $conversation, $payload ) {
		if ( $guard_name === 'custom_guard' ) {
			// Implement your custom guard logic.
			return ! empty( $payload['custom_field'] );
		}

		return $result;
	}, 10, 4 );
}

/**
 * Example: Add custom action
 */
function example_add_custom_action() {
	add_filter( 'wch_fsm_action_execute', function( $result, $action_name, $conversation, $payload ) {
		if ( $action_name === 'custom_action' ) {
			// Implement your custom action logic.
			// Return data to be merged into state_data.
			return [ 'custom_result' => 'success' ];
		}

		return $result;
	}, 10, 4 );
}

/**
 * Example: Complete checkout flow
 */
function example_checkout_flow( $conversation ) {
	$fsm = new WCH_Conversation_FSM();

	// Start checkout.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_START_CHECKOUT
	);

	if ( is_wp_error( $conversation ) ) {
		return $conversation;
	}

	// Enter address.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_ENTER_ADDRESS,
		[
			'address' => [
				'street'  => '123 Main St',
				'city'    => 'New York',
				'zip'     => '10001',
				'country' => 'US',
			],
		]
	);

	if ( is_wp_error( $conversation ) ) {
		return $conversation;
	}

	// Select payment method.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_SELECT_PAYMENT,
		[ 'payment_method' => 'cod' ]
	);

	if ( is_wp_error( $conversation ) ) {
		return $conversation;
	}

	// Confirm order.
	$conversation = $fsm->transition(
		$conversation,
		WCH_Conversation_FSM::EVENT_CONFIRM_ORDER
	);

	if ( is_wp_error( $conversation ) ) {
		return $conversation;
	}

	// Order completed!
	return $conversation;
}
