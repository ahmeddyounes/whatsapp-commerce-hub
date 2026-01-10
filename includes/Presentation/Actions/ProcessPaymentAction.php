<?php
/**
 * Process Payment Action
 *
 * Handles payment method selection and processing.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProcessPaymentAction
 *
 * Handles payment method selection and payment link generation.
 */
class ProcessPaymentAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'process_payment';

	/**
	 * Payment method constants.
	 */
	public const METHOD_COD    = 'cod';
	public const METHOD_CARD   = 'card';
	public const METHOD_UPI    = 'upi';
	public const METHOD_ONLINE = 'online';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters with optional payment_method.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			$paymentMethod = $params['payment_method'] ?? null;

			// If no payment method provided, show selection.
			if ( ! $paymentMethod ) {
				return $this->showPaymentMethods();
			}

			$this->log(
				'Processing payment',
				[
					'phone'          => $phone,
					'payment_method' => $paymentMethod,
				]
			);

			// Get cart for amount.
			$cart = $this->getCart( $phone );

			if ( ! $cart ) {
				return $this->error( __( 'Your cart is empty. Please add items before checkout.', 'whatsapp-commerce-hub' ) );
			}

			$items = is_array( $cart ) ? ( $cart['items'] ?? [] ) : ( $cart->items ?? [] );

			if ( empty( $items ) ) {
				return $this->error( __( 'Your cart is empty. Please add items before checkout.', 'whatsapp-commerce-hub' ) );
			}

			$cartData = [
				'id'    => is_array( $cart ) ? ( $cart['id'] ?? null ) : ( $cart->id ?? null ),
				'items' => $items,
				'total' => is_array( $cart ) ? ( $cart['total'] ?? 0 ) : ( $cart->total ?? 0 ),
			];

			// Process based on payment method.
			switch ( $paymentMethod ) {
				case self::METHOD_COD:
					return $this->processCod( $cartData );

				case self::METHOD_CARD:
				case self::METHOD_ONLINE:
					return $this->processOnlinePayment( $cartData, $paymentMethod );

				case self::METHOD_UPI:
					return $this->processUpi( $cartData );

				default:
					return $this->error( __( 'Invalid payment method. Please select a valid option.', 'whatsapp-commerce-hub' ) );
			}
		} catch ( \Exception $e ) {
			$this->log( 'Error processing payment', [ 'error' => $e->getMessage() ], 'error' );
			return $this->error( __( 'Sorry, we could not process your payment. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Show payment method selection.
	 *
	 * @return ActionResult
	 */
	private function showPaymentMethods(): ActionResult {
		$message = $this->createMessageBuilder();

		$message->header( __( 'Select Payment Method', 'whatsapp-commerce-hub' ) );
		$message->body( __( 'How would you like to pay?', 'whatsapp-commerce-hub' ) );

		// Get available payment gateways.
		$paymentManager = \WCH_Payment_Manager::instance();
		$country        = WC()->countries->get_base_country();
		$gateways       = $paymentManager->get_available_gateways( $country );

		// Build payment options.
		$options = [];
		foreach ( $gateways as $gatewayId => $gateway ) {
			$description = $this->getGatewayDescription( $gatewayId );

			$options[] = [
				'id'          => 'payment_' . $gatewayId,
				'title'       => $gateway->get_title(),
				'description' => $description,
			];
		}

		if ( empty( $options ) ) {
			return $this->error( __( 'No payment methods are currently available. Please contact support.', 'whatsapp-commerce-hub' ) );
		}

		$message->section( __( 'Payment Options', 'whatsapp-commerce-hub' ), $options );

		return ActionResult::success( [ $message ] );
	}

	/**
	 * Get gateway description.
	 *
	 * @param string $gatewayId Gateway ID.
	 * @return string Description.
	 */
	private function getGatewayDescription( string $gatewayId ): string {
		$descriptions = [
			'cod'         => __( 'Pay when you receive the order', 'whatsapp-commerce-hub' ),
			'stripe'      => __( 'Secure card and local payments', 'whatsapp-commerce-hub' ),
			'razorpay'    => __( 'UPI, Cards, Net Banking, Wallets', 'whatsapp-commerce-hub' ),
			'whatsapppay' => __( 'Pay directly in WhatsApp', 'whatsapp-commerce-hub' ),
			'pix'         => __( 'Instant payment via PIX', 'whatsapp-commerce-hub' ),
		];

		return $descriptions[ $gatewayId ] ?? '';
	}

	/**
	 * Process COD payment.
	 *
	 * @param array $cart Cart data.
	 * @return ActionResult
	 */
	private function processCod( array $cart ): ActionResult {
		$message = $this->createMessageBuilder();

		$total = $this->formatPrice( (float) $cart['total'] );

		$text = sprintf(
			"✅ %s\n\n%s: %s\n\n%s\n\n%s",
			__( 'Cash on Delivery Selected', 'whatsapp-commerce-hub' ),
			__( 'Amount to pay', 'whatsapp-commerce-hub' ),
			$total,
			__( 'You will pay this amount when your order is delivered.', 'whatsapp-commerce-hub' ),
			__( 'Please confirm your order to proceed.', 'whatsapp-commerce-hub' )
		);

		$message->text( $text );

		$message->button(
			'reply',
			[
				'id'    => 'confirm_order',
				'title' => __( 'Confirm Order', 'whatsapp-commerce-hub' ),
			]
		);

		$message->button(
			'reply',
			[
				'id'    => 'change_payment',
				'title' => __( 'Change Payment', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success(
			[ $message ],
			null,
			[
				'payment_method' => self::METHOD_COD,
				'payment_status' => 'ready',
			]
		);
	}

	/**
	 * Process online/card payment.
	 *
	 * @param array  $cart   Cart data.
	 * @param string $method Payment method.
	 * @return ActionResult
	 */
	private function processOnlinePayment( array $cart, string $method ): ActionResult {
		$paymentLink = $this->generatePaymentLink( $cart, $method );

		if ( ! $paymentLink ) {
			return $this->error(
				__( 'Failed to generate payment link. Please try again or select a different payment method.', 'whatsapp-commerce-hub' )
			);
		}

		$message = $this->createMessageBuilder();

		$total = $this->formatPrice( (float) $cart['total'] );

		$text = sprintf(
			"💳 %s\n\n%s: %s\n\n%s\n\n%s",
			__( 'Online Payment', 'whatsapp-commerce-hub' ),
			__( 'Amount', 'whatsapp-commerce-hub' ),
			$total,
			__( 'Click the button below to complete your payment securely.', 'whatsapp-commerce-hub' ),
			__( 'Your order will be confirmed once payment is received.', 'whatsapp-commerce-hub' )
		);

		$message->text( $text );

		$message->button(
			'url',
			[
				'title' => __( 'Pay Now', 'whatsapp-commerce-hub' ),
				'url'   => $paymentLink,
			]
		);

		$message->button(
			'reply',
			[
				'id'    => 'change_payment',
				'title' => __( 'Change Payment', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success(
			[ $message ],
			null,
			[
				'payment_method' => $method,
				'payment_link'   => $paymentLink,
				'payment_status' => 'pending',
			]
		);
	}

	/**
	 * Process UPI payment.
	 *
	 * @param array $cart Cart data.
	 * @return ActionResult
	 */
	private function processUpi( array $cart ): ActionResult {
		$upiLink = $this->generateUpiLink( $cart );

		if ( ! $upiLink ) {
			return $this->error(
				__( 'Failed to generate UPI payment link. Please try again or select a different payment method.', 'whatsapp-commerce-hub' )
			);
		}

		$message = $this->createMessageBuilder();

		$total = $this->formatPrice( (float) $cart['total'] );

		$text = sprintf(
			"📱 %s\n\n%s: %s\n\n%s\n\n%s",
			__( 'UPI Payment', 'whatsapp-commerce-hub' ),
			__( 'Amount', 'whatsapp-commerce-hub' ),
			$total,
			__( 'Click the button below to pay using your preferred UPI app (Google Pay, PhonePe, Paytm, etc.).', 'whatsapp-commerce-hub' ),
			__( 'Your order will be confirmed once payment is received.', 'whatsapp-commerce-hub' )
		);

		$message->text( $text );

		$message->button(
			'url',
			[
				'title' => __( 'Pay via UPI', 'whatsapp-commerce-hub' ),
				'url'   => $upiLink,
			]
		);

		$message->button(
			'reply',
			[
				'id'    => 'change_payment',
				'title' => __( 'Change Payment', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success(
			[ $message ],
			null,
			[
				'payment_method' => self::METHOD_UPI,
				'payment_link'   => $upiLink,
				'payment_status' => 'pending',
			]
		);
	}

	/**
	 * Generate payment link.
	 *
	 * @param array  $cart   Cart data.
	 * @param string $method Payment method.
	 * @return string|null Payment link or null on failure.
	 */
	private function generatePaymentLink( array $cart, string $method ): ?string {
		$settings = \WCH_Settings::getInstance();
		$baseUrl  = $settings->get( 'payment.gateway_url', site_url( '/payment' ) );

		$paymentData = [
			'amount'   => $cart['total'],
			'currency' => get_woocommerce_currency(),
			'cart_id'  => $cart['id'],
			'method'   => $method,
		];

		// Generate unique payment ID.
		$paymentId = 'wch_' . wp_generate_uuid4();

		// Store payment intent.
		update_option( 'wch_payment_' . $paymentId, $paymentData, false );

		return add_query_arg(
			[
				'payment_id' => $paymentId,
				'method'     => $method,
			],
			$baseUrl
		);
	}

	/**
	 * Generate UPI payment link.
	 *
	 * @param array $cart Cart data.
	 * @return string|null UPI link or null on failure.
	 */
	private function generateUpiLink( array $cart ): ?string {
		$settings = \WCH_Settings::getInstance();
		$upiId    = $settings->get( 'payment.upi_id', 'merchant@upi' );

		$params = [
			'pa' => $upiId,
			'pn' => get_bloginfo( 'name' ),
			'am' => number_format( (float) $cart['total'], 2, '.', '' ),
			'cu' => get_woocommerce_currency(),
			'tn' => __( 'Order payment', 'whatsapp-commerce-hub' ),
		];

		return 'upi://pay?' . http_build_query( $params );
	}
}
