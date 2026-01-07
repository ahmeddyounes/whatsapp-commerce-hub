<?php
/**
 * WCH Checkout Controller Class
 *
 * Manages multi-step checkout process within WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Checkout_Controller
 *
 * Handles the complete checkout flow:
 * - ADDRESS: Collect and validate shipping address
 * - SHIPPING_METHOD: Select shipping method
 * - PAYMENT_METHOD: Choose payment method
 * - REVIEW: Review order details
 * - CONFIRM: Final confirmation and order creation
 */
class WCH_Checkout_Controller {
	/**
	 * Checkout steps
	 */
	const STEP_ADDRESS = 'ADDRESS';
	const STEP_SHIPPING_METHOD = 'SHIPPING_METHOD';
	const STEP_PAYMENT_METHOD = 'PAYMENT_METHOD';
	const STEP_REVIEW = 'REVIEW';
	const STEP_CONFIRM = 'CONFIRM';

	/**
	 * Singleton instance.
	 *
	 * @var WCH_Checkout_Controller
	 */
	private static $instance = null;

	/**
	 * Cart manager instance.
	 *
	 * @var WCH_Cart_Manager
	 */
	private $cart_manager;

	/**
	 * Customer service instance.
	 *
	 * @var WCH_Customer_Service
	 */
	private $customer_service;

	/**
	 * Order sync service instance.
	 *
	 * @var WCH_Order_Sync_Service
	 */
	private $order_sync_service;

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Checkout_Controller
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->cart_manager = WCH_Cart_Manager::instance();
		$this->customer_service = WCH_Customer_Service::instance();
		$this->order_sync_service = WCH_Order_Sync_Service::instance();
	}

	/**
	 * Start checkout process
	 *
	 * Validates cart is not empty and has valid items, then transitions to address collection.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Result with success status and messages.
	 */
	public function start_checkout( $conversation ) {
		try {
			WCH_Logger::info(
				'Starting checkout',
				'checkout',
				array( 'phone' => $conversation->customer_phone )
			);

			// Get cart.
			$cart = $this->cart_manager->get_cart( $conversation->customer_phone );

			// Check cart not empty.
			if ( empty( $cart['items'] ) ) {
				return array(
					'success' => false,
					'messages' => array(
						( new WCH_Message_Builder() )->text(
							"Your cart is empty. Please add items before checkout."
						),
					),
				);
			}

			// Validate cart items are still valid.
			$validation = $this->cart_manager->check_cart_validity( $conversation->customer_phone );

			if ( ! $validation['is_valid'] ) {
				$issues_text = "⚠️ There are issues with your cart:\n\n";
				foreach ( $validation['issues'] as $issue ) {
					$issues_text .= "• " . $issue['message'] . "\n";
				}
				$issues_text .= "\nPlease review your cart and try again.";

				return array(
					'success' => false,
					'messages' => array(
						( new WCH_Message_Builder() )->text( $issues_text ),
					),
				);
			}

			// Store checkout context.
			$conversation->state_data['checkout_step'] = self::STEP_ADDRESS;
			$conversation->state_data['checkout_data'] = array(
				'cart_id' => $cart['id'],
			);

			// Transition to CHECKOUT_ADDRESS state.
			$conversation->current_state = WCH_Conversation_FSM::STATE_CHECKOUT_ADDRESS;

			// Request address.
			return $this->request_address( $conversation );

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error starting checkout',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we encountered an error starting checkout. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Request shipping address
	 *
	 * Shows saved addresses if available, otherwise prompts for new address.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Result with success status and messages.
	 */
	public function request_address( $conversation ) {
		try {
			WCH_Logger::info(
				'Requesting address',
				'checkout',
				array( 'phone' => $conversation->customer_phone )
			);

			// Get customer profile.
			$customer = $this->customer_service->get_or_create_profile( $conversation->customer_phone );

			// Check for saved addresses.
			$saved_addresses = array();
			if ( $customer && ! empty( $customer->saved_addresses ) ) {
				$decoded = json_decode( $customer->saved_addresses, true );
				if ( is_array( $decoded ) ) {
					$saved_addresses = $decoded;
				}
			}

			// Build message based on saved addresses.
			if ( ! empty( $saved_addresses ) ) {
				$message = $this->build_saved_addresses_message( $saved_addresses );
			} else {
				$message = $this->build_new_address_prompt();
			}

			return array(
				'success' => true,
				'messages' => array( $message ),
			);

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error requesting address',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not process your address request. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Process address input
	 *
	 * Handles both saved address selection and new address entry.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @param string                   $input User input (list selection ID or address text).
	 * @return array Result with success status and messages.
	 */
	public function process_address_input( $conversation, $input ) {
		try {
			WCH_Logger::info(
				'Processing address input',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'input_length' => strlen( $input ),
				)
			);

			$address = null;

			// Check if this is a saved address selection.
			if ( preg_match( '/^saved_address_(\d+)$/', $input, $matches ) ) {
				// Load saved address.
				$index = intval( $matches[1] );
				$customer = $this->customer_service->get_or_create_profile( $conversation->customer_phone );

				if ( $customer && ! empty( $customer->saved_addresses ) ) {
					$saved_addresses = json_decode( $customer->saved_addresses, true );
					if ( isset( $saved_addresses[ $index ] ) ) {
						$address = $saved_addresses[ $index ];
						WCH_Logger::info(
							'Using saved address',
							'checkout',
							array( 'phone' => $conversation->customer_phone, 'index' => $index )
						);
					}
				}

				if ( ! $address ) {
					return array(
						'success' => false,
						'messages' => array(
							( new WCH_Message_Builder() )->text(
								'Sorry, we could not load that saved address. Please try again.'
							),
						),
					);
				}
			} elseif ( 'new_address' === $input ) {
				// Prompt for new address entry.
				return array(
					'success' => true,
					'messages' => array( $this->build_new_address_prompt() ),
				);
			} else {
				// Parse address from text input.
				$address = WCH_Address_Parser::parse( $input );

				WCH_Logger::info(
					'Parsed address from text',
					'checkout',
					array(
						'phone' => $conversation->customer_phone,
						'address' => $address,
					)
				);
			}

			// Validate address completeness.
			$validation = WCH_Address_Parser::validate( $address );

			if ( ! $validation['valid'] ) {
				return array(
					'success' => false,
					'messages' => array(
						( new WCH_Message_Builder() )->text(
							"⚠️ Address incomplete:\n\n" . $validation['message'] . "\n\nPlease provide a complete address."
						),
					),
				);
			}

			// Store address in checkout context.
			$conversation->state_data['checkout_data']['shipping_address'] = $address;

			// Update cart with shipping address.
			$cart = $this->cart_manager->get_cart( $conversation->customer_phone );
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'wch_carts',
				array(
					'shipping_address' => wp_json_encode( $address ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $cart['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			// Move to shipping method selection.
			$conversation->state_data['checkout_step'] = self::STEP_SHIPPING_METHOD;

			return $this->show_shipping_methods( $conversation );

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error processing address input',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not process your address. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Show available shipping methods
	 *
	 * Calculates and displays WooCommerce shipping methods for the address and cart.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Result with success status and messages.
	 */
	public function show_shipping_methods( $conversation ) {
		try {
			WCH_Logger::info(
				'Showing shipping methods',
				'checkout',
				array( 'phone' => $conversation->customer_phone )
			);

			// Get cart and address.
			$cart = $this->cart_manager->get_cart( $conversation->customer_phone );
			$address = $conversation->state_data['checkout_data']['shipping_address'] ?? null;

			if ( ! $address ) {
				return array(
					'success' => false,
					'messages' => array(
						( new WCH_Message_Builder() )->text(
							'Address not found. Please start checkout again.'
						),
					),
				);
			}

			// Get available shipping methods.
			$shipping_methods = $this->calculate_shipping_methods( $cart, $address );

			if ( empty( $shipping_methods ) ) {
				// No shipping available - offer free shipping or local pickup.
				$shipping_methods = array(
					array(
						'id' => 'free_shipping',
						'label' => 'Free Shipping',
						'cost' => 0.00,
					),
				);
			}

			// Build message with shipping options.
			$message = new WCH_Message_Builder();
			$message->header( 'Select Shipping Method' );
			$message->body( 'Choose your preferred shipping method:' );

			$rows = array();
			foreach ( $shipping_methods as $method ) {
				$cost_display = $method['cost'] > 0 ? wc_price( $method['cost'] ) : 'Free';
				$rows[] = array(
					'id' => 'shipping_' . $method['id'],
					'title' => $method['label'],
					'description' => 'Cost: ' . $cost_display,
				);
			}

			$message->section( 'Shipping Methods', $rows );

			return array(
				'success' => true,
				'messages' => array( $message ),
			);

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error showing shipping methods',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not load shipping methods. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Process shipping method selection
	 *
	 * Stores shipping method and moves to payment method selection.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @param string                   $method Shipping method ID.
	 * @return array Result with success status and messages.
	 */
	public function process_shipping_selection( $conversation, $method ) {
		try {
			WCH_Logger::info(
				'Processing shipping selection',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'method' => $method,
				)
			);

			// Extract shipping method ID.
			$shipping_id = str_replace( 'shipping_', '', $method );

			// Get cart and address.
			$cart = $this->cart_manager->get_cart( $conversation->customer_phone );
			$address = $conversation->state_data['checkout_data']['shipping_address'] ?? null;

			// Find the selected shipping method.
			$shipping_methods = $this->calculate_shipping_methods( $cart, $address );
			$selected_method = null;

			foreach ( $shipping_methods as $m ) {
				if ( $m['id'] === $shipping_id ) {
					$selected_method = $m;
					break;
				}
			}

			if ( ! $selected_method ) {
				return array(
					'success' => false,
					'messages' => array(
						( new WCH_Message_Builder() )->text(
							'Invalid shipping method selected. Please try again.'
						),
					),
				);
			}

			// Store shipping method in checkout context.
			$conversation->state_data['checkout_data']['shipping_method'] = $selected_method;

			// Move to payment method selection.
			$conversation->state_data['checkout_step'] = self::STEP_PAYMENT_METHOD;

			// Show payment methods.
			return $this->show_payment_methods( $conversation );

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error processing shipping selection',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not process your shipping selection. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Show available payment methods
	 *
	 * Displays payment methods based on settings and region.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Result with success status and messages.
	 */
	public function show_payment_methods( $conversation ) {
		try {
			WCH_Logger::info(
				'Showing payment methods',
				'checkout',
				array( 'phone' => $conversation->customer_phone )
			);

			// Get available payment methods based on region.
			$address = $conversation->state_data['checkout_data']['shipping_address'] ?? null;
			$country = $address['country'] ?? '';

			$payment_methods = $this->get_available_payment_methods( $country );

			// Build message with payment options.
			$message = new WCH_Message_Builder();
			$message->header( 'Select Payment Method' );
			$message->body( 'How would you like to pay?' );

			$rows = array();
			foreach ( $payment_methods as $method ) {
				$description = $method['description'];
				if ( ! empty( $method['fee'] ) && $method['fee'] > 0 ) {
					$description .= ' (+ ' . wc_price( $method['fee'] ) . ' fee)';
				}

				$rows[] = array(
					'id' => 'payment_' . $method['id'],
					'title' => $method['label'],
					'description' => $description,
				);
			}

			$message->section( 'Payment Options', $rows );

			return array(
				'success' => true,
				'messages' => array( $message ),
			);

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error showing payment methods',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not load payment methods. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Process payment method selection
	 *
	 * Stores payment selection, calculates final totals, and shows order review.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @param string                   $method Payment method ID.
	 * @return array Result with success status and messages.
	 */
	public function process_payment_selection( $conversation, $method ) {
		try {
			WCH_Logger::info(
				'Processing payment selection',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'method' => $method,
				)
			);

			// Extract payment method ID.
			$payment_id = str_replace( 'payment_', '', $method );

			// Store payment method in checkout context.
			$conversation->state_data['checkout_data']['payment_method'] = $payment_id;

			// Calculate final totals including payment fees.
			$totals = $this->calculate_final_totals( $conversation );
			$conversation->state_data['checkout_data']['totals'] = $totals;

			// Move to review step.
			$conversation->state_data['checkout_step'] = self::STEP_REVIEW;

			// Show order review.
			return $this->show_order_review( $conversation );

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error processing payment selection',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not process your payment selection. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Show order review
	 *
	 * Displays detailed summary with items, address, shipping, payment, and totals.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Result with success status and messages.
	 */
	public function show_order_review( $conversation ) {
		try {
			WCH_Logger::info(
				'Showing order review',
				'checkout',
				array( 'phone' => $conversation->customer_phone )
			);

			// Get cart and checkout data.
			$cart = $this->cart_manager->get_cart( $conversation->customer_phone );
			$checkout_data = $conversation->state_data['checkout_data'] ?? array();

			$address = $checkout_data['shipping_address'] ?? null;
			$shipping_method = $checkout_data['shipping_method'] ?? array();
			$payment_method = $checkout_data['payment_method'] ?? '';
			$totals = $checkout_data['totals'] ?? array();

			// Build order review message.
			$review_text = "📋 *Order Review*\n\n";

			// Items.
			$review_text .= "*Items:*\n";
			foreach ( $cart['items'] as $index => $item ) {
				$product = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
				if ( $product ) {
					$review_text .= sprintf(
						"%d. %s × %d = %s\n",
						$index + 1,
						$product->get_name(),
						$item['quantity'],
						wc_price( $product->get_price() * $item['quantity'] )
					);
				}
			}

			// Shipping Address.
			$review_text .= "\n*Shipping Address:*\n";
			if ( $address ) {
				$review_text .= $this->format_address_display( $address ) . "\n";
			}

			// Shipping Method.
			$review_text .= "\n*Shipping:*\n";
			if ( ! empty( $shipping_method['label'] ) ) {
				$review_text .= sprintf(
					"%s - %s\n",
					$shipping_method['label'],
					$shipping_method['cost'] > 0 ? wc_price( $shipping_method['cost'] ) : 'Free'
				);
			} else {
				$review_text .= "Free Shipping\n";
			}

			// Payment Method.
			$review_text .= "\n*Payment Method:*\n";
			$payment_label = $this->get_payment_method_label( $payment_method );
			$review_text .= $payment_label;
			if ( ! empty( $totals['payment_fee'] ) && $totals['payment_fee'] > 0 ) {
				$review_text .= sprintf( " (+ %s fee)", wc_price( $totals['payment_fee'] ) );
			}
			$review_text .= "\n";

			// Totals.
			$review_text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
			$review_text .= sprintf( "Subtotal: %s\n", wc_price( $totals['subtotal'] ?? 0 ) );

			if ( ! empty( $totals['discount'] ) && $totals['discount'] > 0 ) {
				$review_text .= sprintf( "Discount: -%s\n", wc_price( $totals['discount'] ) );
			}

			if ( ! empty( $totals['shipping'] ) && $totals['shipping'] > 0 ) {
				$review_text .= sprintf( "Shipping: %s\n", wc_price( $totals['shipping'] ) );
			}

			if ( ! empty( $totals['tax'] ) && $totals['tax'] > 0 ) {
				$review_text .= sprintf( "Tax: %s\n", wc_price( $totals['tax'] ) );
			}

			if ( ! empty( $totals['payment_fee'] ) && $totals['payment_fee'] > 0 ) {
				$review_text .= sprintf( "Payment Fee: %s\n", wc_price( $totals['payment_fee'] ) );
			}

			$review_text .= "━━━━━━━━━━━━━━━━━━━━\n";
			$review_text .= sprintf( "*Total: %s*", wc_price( $totals['total'] ?? 0 ) );

			// Build message with buttons.
			$message = new WCH_Message_Builder();
			$message->text( $review_text );

			$message->button(
				'reply',
				array(
					'id' => 'confirm_order',
					'title' => 'Confirm Order',
				)
			);

			$message->button(
				'reply',
				array(
					'id' => 'edit_address',
					'title' => 'Edit Address',
				)
			);

			$message->button(
				'reply',
				array(
					'id' => 'cancel_checkout',
					'title' => 'Cancel',
				)
			);

			return array(
				'success' => true,
				'messages' => array( $message ),
			);

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error showing order review',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text(
						'Sorry, we could not generate your order review. Please try again.'
					),
				),
			);
		}
	}

	/**
	 * Confirm and create order
	 *
	 * Performs final stock check, creates WooCommerce order, processes payment if applicable.
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Result with success status and messages.
	 */
	public function confirm_order( $conversation ) {
		try {
			WCH_Logger::info(
				'Confirming order',
				'checkout',
				array( 'phone' => $conversation->customer_phone )
			);

			// Get cart and checkout data.
			$cart = $this->cart_manager->get_cart( $conversation->customer_phone );
			$checkout_data = $conversation->state_data['checkout_data'] ?? array();

			// Final stock check.
			$validation = $this->cart_manager->check_cart_validity( $conversation->customer_phone );

			if ( ! $validation['is_valid'] ) {
				// Handle out of stock during checkout.
				$issues_text = "⚠️ *Stock Update*\n\n";
				$issues_text .= "Some items in your cart are no longer available:\n\n";
				foreach ( $validation['issues'] as $issue ) {
					$issues_text .= "• " . $issue['message'] . "\n";
				}
				$issues_text .= "\nPlease review your cart and checkout again.";

				// Return to cart.
				$conversation->current_state = WCH_Conversation_FSM::STATE_CART_MANAGEMENT;

				return array(
					'success' => false,
					'messages' => array(
						( new WCH_Message_Builder() )->text( $issues_text ),
					),
				);
			}

			// Prepare order data.
			$order_data = array(
				'items' => $cart['items'],
				'shipping_address' => $this->convert_address_to_wc_format( $checkout_data['shipping_address'] ?? array() ),
				'payment_method' => $checkout_data['payment_method'] ?? 'cod',
				'coupon_code' => $cart['coupon_code'] ?? null,
				'conversation_id' => $conversation->id ?? null,
			);

			// Create order via Order Sync Service.
			$order_id = $this->order_sync_service->create_order_from_cart(
				$order_data,
				$conversation->customer_phone
			);

			if ( ! $order_id ) {
				throw new Exception( 'Failed to create order' );
			}

			// Clear cart.
			$this->cart_manager->clear_cart( $conversation->customer_phone );

			// Mark cart as completed.
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'wch_carts',
				array(
					'status' => 'completed',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $cart['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			// Get order for confirmation details.
			$order = wc_get_order( $order_id );

			// Build confirmation message.
			$confirmation_text = "✅ *Order Confirmed!*\n\n";
			$confirmation_text .= sprintf( "Order Number: *#%s*\n\n", $order->get_order_number() );
			$confirmation_text .= sprintf( "Total: *%s*\n\n", wc_price( $order->get_total() ) );
			$confirmation_text .= "We'll send you updates about your order status.\n\n";
			$confirmation_text .= "Thank you for shopping with us! 🎉";

			// Transition to completed state.
			$conversation->current_state = WCH_Conversation_FSM::STATE_COMPLETED;
			$conversation->state_data = array();

			return array(
				'success' => true,
				'messages' => array(
					( new WCH_Message_Builder() )->text( $confirmation_text ),
				),
				'order_id' => $order_id,
			);

		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Error confirming order',
				'checkout',
				array(
					'phone' => $conversation->customer_phone,
					'error' => $e->getMessage(),
				)
			);

			// Handle payment failure or other errors.
			$error_text = "❌ Order Failed\n\n";
			$error_text .= "We encountered an error processing your order.\n\n";
			$error_text .= "Error: " . $e->getMessage() . "\n\n";
			$error_text .= "Please try again or contact support.";

			return array(
				'success' => false,
				'messages' => array(
					( new WCH_Message_Builder() )->text( $error_text ),
				),
			);
		}
	}

	/**
	 * Build saved addresses message
	 *
	 * @param array $addresses Saved addresses.
	 * @return WCH_Message_Builder
	 */
	private function build_saved_addresses_message( $addresses ) {
		$message = new WCH_Message_Builder();

		$message->header( 'Shipping Address' );
		$message->body( 'Please select a saved address or enter a new one:' );

		// Build address rows.
		$rows = array();

		foreach ( $addresses as $index => $address ) {
			$address_text = $this->format_address_summary( $address );

			$rows[] = array(
				'id' => 'saved_address_' . $index,
				'title' => ! empty( $address['label'] ) ? $address['label'] : 'Address ' . ( $index + 1 ),
				'description' => wp_trim_words( $address_text, 10, '...' ),
			);

			// Limit to 10 addresses.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		// Add "Enter New Address" option.
		$rows[] = array(
			'id' => 'new_address',
			'title' => 'Enter New Address',
			'description' => 'Provide a different address',
		);

		$message->section( 'Select Address', $rows );

		return $message;
	}

	/**
	 * Build new address prompt
	 *
	 * @return WCH_Message_Builder
	 */
	private function build_new_address_prompt() {
		$message = new WCH_Message_Builder();

		$text = "📍 *Shipping Address*\n\n"
			. "Please provide your shipping address.\n\n"
			. "Include:\n"
			. "• Street address\n"
			. "• City\n"
			. "• State/Province\n"
			. "• Postal/ZIP code\n"
			. "• Country\n\n"
			. "*Example:*\n"
			. "123 Main Street\nApt 4B\nNew York, NY 10001\nUSA";

		$message->text( $text );

		return $message;
	}

	/**
	 * Format address summary
	 *
	 * @param array $address Address data.
	 * @return string Formatted address.
	 */
	private function format_address_summary( $address ) {
		$parts = array();

		if ( ! empty( $address['street'] ) ) {
			$parts[] = $address['street'];
		}

		if ( ! empty( $address['city'] ) ) {
			$parts[] = $address['city'];
		}

		if ( ! empty( $address['state'] ) ) {
			$parts[] = $address['state'];
		}

		if ( ! empty( $address['postal_code'] ) ) {
			$parts[] = $address['postal_code'];
		}

		if ( ! empty( $address['country'] ) ) {
			$parts[] = $address['country'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Format address for display
	 *
	 * @param array $address Address data.
	 * @return string Formatted address.
	 */
	private function format_address_display( $address ) {
		$parts = array();

		if ( ! empty( $address['name'] ) ) {
			$parts[] = $address['name'];
		}

		if ( ! empty( $address['street'] ) ) {
			$parts[] = $address['street'];
		}

		$city_line = array();
		if ( ! empty( $address['city'] ) ) {
			$city_line[] = $address['city'];
		}
		if ( ! empty( $address['state'] ) ) {
			$city_line[] = $address['state'];
		}
		if ( ! empty( $address['postal_code'] ) ) {
			$city_line[] = $address['postal_code'];
		}
		if ( ! empty( $city_line ) ) {
			$parts[] = implode( ', ', $city_line );
		}

		if ( ! empty( $address['country'] ) ) {
			$parts[] = $address['country'];
		}

		return implode( "\n", $parts );
	}

	/**
	 * Calculate available shipping methods
	 *
	 * @param array $cart Cart data.
	 * @param array $address Shipping address.
	 * @return array Shipping methods with id, label, and cost.
	 */
	private function calculate_shipping_methods( $cart, $address ) {
		$methods = array();

		// Get WooCommerce shipping zones.
		$shipping_zones = WC_Shipping_Zones::get_zones();

		// Add methods from zones.
		foreach ( $shipping_zones as $zone ) {
			if ( isset( $zone['shipping_methods'] ) ) {
				foreach ( $zone['shipping_methods'] as $method ) {
					if ( 'yes' === $method->enabled ) {
						$cost = 0.00;

						if ( 'flat_rate' === $method->id && isset( $method->cost ) ) {
							$cost = floatval( $method->cost );
						}

						$methods[] = array(
							'id' => $method->id . '_' . $method->instance_id,
							'label' => $method->get_title(),
							'cost' => $cost,
						);
					}
				}
			}
		}

		// If no methods found, add default free shipping.
		if ( empty( $methods ) ) {
			$methods[] = array(
				'id' => 'free_shipping',
				'label' => 'Free Shipping',
				'cost' => 0.00,
			);
		}

		return $methods;
	}

	/**
	 * Get available payment methods based on region
	 *
	 * @param string $country Country code.
	 * @return array Payment methods with id, label, description, and fee.
	 */
	private function get_available_payment_methods( $country ) {
		$methods = array();

		// COD - Available everywhere.
		$methods[] = array(
			'id' => 'cod',
			'label' => 'Cash on Delivery',
			'description' => 'Pay when you receive',
			'fee' => 0,
		);

		// UPI - Available in India.
		if ( 'IN' === $country ) {
			$methods[] = array(
				'id' => 'upi',
				'label' => 'UPI Payment',
				'description' => 'Google Pay, PhonePe, Paytm',
				'fee' => 0,
			);
		}

		// PIX - Available in Brazil.
		if ( 'BR' === $country ) {
			$methods[] = array(
				'id' => 'pix',
				'label' => 'PIX',
				'description' => 'Instant bank transfer',
				'fee' => 0,
			);
		}

		// Card/Online - Available everywhere.
		$methods[] = array(
			'id' => 'card',
			'label' => 'Credit/Debit Card',
			'description' => 'Secure online payment',
			'fee' => 0,
		);

		// WhatsApp Pay - If available.
		$settings = WCH_Settings::getInstance();
		if ( $settings->get( 'payment.whatsapp_pay_enabled', false ) ) {
			$methods[] = array(
				'id' => 'whatsapp_pay',
				'label' => 'WhatsApp Pay',
				'description' => 'Pay within WhatsApp',
				'fee' => 0,
			);
		}

		return $methods;
	}

	/**
	 * Get payment method label
	 *
	 * @param string $method_id Payment method ID.
	 * @return string Payment method label.
	 */
	private function get_payment_method_label( $method_id ) {
		$labels = array(
			'cod' => 'Cash on Delivery',
			'upi' => 'UPI Payment',
			'pix' => 'PIX',
			'card' => 'Credit/Debit Card',
			'whatsapp_pay' => 'WhatsApp Pay',
		);

		return $labels[ $method_id ] ?? ucfirst( str_replace( '_', ' ', $method_id ) );
	}

	/**
	 * Calculate final totals including all fees
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation context.
	 * @return array Totals breakdown.
	 */
	private function calculate_final_totals( $conversation ) {
		$cart = $this->cart_manager->get_cart( $conversation->customer_phone );
		$checkout_data = $conversation->state_data['checkout_data'] ?? array();

		// Get base totals from cart.
		$base_totals = $this->cart_manager->calculate_totals( $cart );

		// Add shipping cost.
		$shipping_cost = $checkout_data['shipping_method']['cost'] ?? 0.00;

		// Calculate payment fee if applicable.
		$payment_fee = 0.00;
		$payment_method = $checkout_data['payment_method'] ?? '';

		// Add payment fees based on method (can be configured).
		if ( 'cod' === $payment_method ) {
			// COD might have a fee.
			$settings = WCH_Settings::getInstance();
			$payment_fee = floatval( $settings->get( 'payment.cod_fee', 0 ) );
		}

		// Calculate final total.
		$total = $base_totals['subtotal']
			- $base_totals['discount']
			+ $shipping_cost
			+ $base_totals['tax']
			+ $payment_fee;

		return array(
			'subtotal' => $base_totals['subtotal'],
			'discount' => $base_totals['discount'],
			'shipping' => $shipping_cost,
			'tax' => $base_totals['tax'],
			'payment_fee' => $payment_fee,
			'total' => round( $total, 2 ),
		);
	}

	/**
	 * Convert internal address format to WooCommerce format
	 *
	 * @param array $address Internal address format.
	 * @return array WooCommerce address format.
	 */
	private function convert_address_to_wc_format( $address ) {
		$name_parts = array();
		if ( ! empty( $address['name'] ) ) {
			$name_parts = explode( ' ', $address['name'], 2 );
		}

		return array(
			'first_name' => $name_parts[0] ?? '',
			'last_name' => $name_parts[1] ?? '',
			'company' => '',
			'address_1' => $address['street'] ?? '',
			'address_2' => '',
			'city' => $address['city'] ?? '',
			'state' => $address['state'] ?? '',
			'postcode' => $address['postal_code'] ?? '',
			'country' => $address['country'] ?? '',
		);
	}
}
