<?php
/**
 * Show Main Menu Action
 *
 * Displays main navigation menu.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShowMainMenuAction
 *
 * Shows interactive list with main navigation options.
 */
class ShowMainMenuAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'show_main_menu';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			$this->log( 'Showing main menu', array( 'phone' => $phone ) );

			// Get customer profile for personalization.
			$customer = $this->getCustomerProfile( $phone );

			// Create greeting message.
			$greeting = $this->buildGreeting( $customer );

			// Build main menu.
			$menuMessage = $this->buildMenu();

			return ActionResult::success(
				array( $greeting, $menuMessage ),
				null,
				array( 'last_menu' => 'main' )
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error showing main menu', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( __( 'Sorry, something went wrong. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Build personalized greeting.
	 *
	 * @param object|null $customer Customer profile.
	 * @return \WCH_Message_Builder
	 */
	private function buildGreeting( ?object $customer ): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		if ( $customer && ! empty( $customer->name ) ) {
			// Returning customer.
			$greetingText = sprintf(
				"%s\n\n%s",
				sprintf( __( 'Welcome back, %s! 👋', 'whatsapp-commerce-hub' ), esc_html( $customer->name ) ),
				__( 'How can we help you today?', 'whatsapp-commerce-hub' )
			);
		} else {
			// New customer.
			$greetingText = sprintf(
				"%s\n\n%s",
				__( 'Welcome to our store! 👋', 'whatsapp-commerce-hub' ),
				__( 'How can we help you today?', 'whatsapp-commerce-hub' )
			);
		}

		return $message->text( $greetingText );
	}

	/**
	 * Build main menu interactive list.
	 *
	 * @return \WCH_Message_Builder
	 */
	private function buildMenu(): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		$message->body( __( 'Please select an option from the menu below:', 'whatsapp-commerce-hub' ) );

		// Shopping section.
		$message->section(
			__( 'Shopping', 'whatsapp-commerce-hub' ),
			array(
				array(
					'id'          => 'menu_shop_category',
					'title'       => __( 'Shop by Category', 'whatsapp-commerce-hub' ),
					'description' => __( 'Browse products by category', 'whatsapp-commerce-hub' ),
				),
				array(
					'id'          => 'menu_search',
					'title'       => __( 'Search Products', 'whatsapp-commerce-hub' ),
					'description' => __( 'Find what you are looking for', 'whatsapp-commerce-hub' ),
				),
			)
		);

		// Orders & Support section.
		$message->section(
			__( 'Orders & Support', 'whatsapp-commerce-hub' ),
			array(
				array(
					'id'          => 'menu_view_cart',
					'title'       => __( 'View Cart', 'whatsapp-commerce-hub' ),
					'description' => __( 'See items in your cart', 'whatsapp-commerce-hub' ),
				),
				array(
					'id'          => 'menu_track_order',
					'title'       => __( 'Track Order', 'whatsapp-commerce-hub' ),
					'description' => __( 'Check your order status', 'whatsapp-commerce-hub' ),
				),
				array(
					'id'          => 'menu_support',
					'title'       => __( 'Talk to Support', 'whatsapp-commerce-hub' ),
					'description' => __( 'Chat with our team', 'whatsapp-commerce-hub' ),
				),
			)
		);

		$message->button(
			'reply',
			array(
				'id'    => 'menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			)
		);

		return $message;
	}
}
