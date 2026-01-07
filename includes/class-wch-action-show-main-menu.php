<?php
/**
 * WCH Action: Show Main Menu
 *
 * Display main menu with category options, search, cart, and support.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_ShowMainMenu class
 *
 * Shows an interactive list with main navigation options:
 * - Shop by Category
 * - Search Products
 * - View Cart
 * - Track Order
 * - Talk to Support
 */
class WCH_Action_ShowMainMenu extends WCH_Flow_Action {
	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			$this->log( 'Showing main menu', array( 'phone' => $conversation->customer_phone ) );

			// Get customer profile for personalization.
			$customer = $this->get_customer_profile( $conversation->customer_phone );

			// Create greeting message.
			$greeting = $this->build_greeting( $customer );

			// Build main menu.
			$menu_message = $this->build_menu();

			return WCH_Action_Result::success(
				array( $greeting, $menu_message ),
				null,
				array( 'last_menu' => 'main' )
			);

		} catch ( Exception $e ) {
			$this->log( 'Error showing main menu', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, something went wrong. Please try again.' );
		}
	}

	/**
	 * Build personalized greeting
	 *
	 * @param WCH_Customer_Profile|null $customer Customer profile.
	 * @return WCH_Message_Builder
	 */
	private function build_greeting( $customer ) {
		$message = new WCH_Message_Builder();

		if ( $customer && ! empty( $customer->name ) ) {
			// Returning customer.
			$greeting_text = sprintf(
				"Welcome back, %s! 👋\n\nHow can we help you today?",
				esc_html( $customer->name )
			);
		} else {
			// New customer.
			$greeting_text = "Welcome to our store! 👋\n\nHow can we help you today?";
		}

		return $message->text( $greeting_text );
	}

	/**
	 * Build main menu interactive list
	 *
	 * @return WCH_Message_Builder
	 */
	private function build_menu() {
		$message = new WCH_Message_Builder();

		$message->body( 'Please select an option from the menu below:' );

		// Add menu sections.
		$message->section(
			'Shopping',
			array(
				array(
					'id'          => 'menu_shop_category',
					'title'       => 'Shop by Category',
					'description' => 'Browse products by category',
				),
				array(
					'id'          => 'menu_search',
					'title'       => 'Search Products',
					'description' => 'Find what you are looking for',
				),
			)
		);

		$message->section(
			'Orders & Support',
			array(
				array(
					'id'          => 'menu_view_cart',
					'title'       => 'View Cart',
					'description' => 'See items in your cart',
				),
				array(
					'id'          => 'menu_track_order',
					'title'       => 'Track Order',
					'description' => 'Check your order status',
				),
				array(
					'id'          => 'menu_support',
					'title'       => 'Talk to Support',
					'description' => 'Chat with our team',
				),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'menu',
				'title' => 'Main Menu',
			)
		);

		return $message;
	}
}
