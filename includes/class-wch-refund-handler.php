<?php
/**
 * Refund Handler
 *
 * Handles refunds for WhatsApp orders through payment gateways.
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Refund_Handler {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Refund_Handler
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Refund_Handler
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Hook into WooCommerce refund creation.
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refund' ), 10, 2 );

		// Hook into order status changes to refunded.
		add_action( 'woocommerce_order_status_refunded', array( $this, 'notify_customer_refund' ), 10, 2 );
	}

	/**
	 * Handle order refund.
	 *
	 * Called when a refund is created for an order.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function handle_order_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if this is a WhatsApp order.
		$is_wch_order = $order->get_meta( '_wch_channel' ) === 'whatsapp';
		if ( ! $is_wch_order ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}

		$refund_amount = abs( $refund->get_amount() );
		$refund_reason = $refund->get_reason();

		WCH_Logger::log(
			sprintf(
				'Processing refund for WhatsApp order #%d. Amount: %s, Reason: %s',
				$order_id,
				$refund_amount,
				$refund_reason
			),
			'info'
		);

		// Process refund through payment gateway.
		$payment_manager = WCH_Payment_Manager::instance();
		$result          = $payment_manager->process_refund( $order_id, $refund_amount, $refund_reason );

		if ( is_wp_error( $result ) ) {
			WCH_Logger::log(
				sprintf(
					'Refund processing failed for order #%d: %s',
					$order_id,
					$result->get_error_message()
				),
				'error'
			);

			// Add note to order.
			$order->add_order_note(
				sprintf(
					/* translators: %s: Error message */
					__( 'Automatic refund failed: %s. Please process manually.', 'whatsapp-commerce-hub' ),
					$result->get_error_message()
				)
			);
		} else {
			WCH_Logger::log(
				sprintf( 'Refund processed successfully for order #%d', $order_id ),
				'info'
			);
		}
	}

	/**
	 * Notify customer about refund.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public function notify_customer_refund( $order_id, $order ) {
		// Check if this is a WhatsApp order.
		$is_wch_order = $order->get_meta( '_wch_channel' ) === 'whatsapp';
		if ( ! $is_wch_order ) {
			return;
		}

		$customer_phone = $order->get_meta( '_wch_customer_phone' );
		if ( empty( $customer_phone ) ) {
			return;
		}

		// Build refund notification message.
		$total_refunded = $order->get_total_refunded();

		$message = sprintf(
			/* translators: 1: Order number, 2: Refund amount */
			__( "💰 Refund Processed\n\nYour refund for order #%1\$s has been processed.\n\nRefund Amount: %2\$s\n\nThe amount will be credited to your original payment method within 5-7 business days.", 'whatsapp-commerce-hub' ),
			$order->get_order_number(),
			wc_price( $total_refunded )
		);

		// Send message to customer.
		$whatsapp_client = new WCH_WhatsApp_API_Client();
		$whatsapp_client->send_message(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $customer_phone,
				'type'              => 'text',
				'text'              => array(
					'body' => $message,
				),
			)
		);

		WCH_Logger::log(
			sprintf( 'Refund notification sent to customer for order #%d', $order_id ),
			'info'
		);
	}
}
