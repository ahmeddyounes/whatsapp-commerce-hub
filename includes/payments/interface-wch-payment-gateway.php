<?php
/**
 * Payment Gateway Interface
 *
 * Defines the contract that all payment gateways must implement.
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WCH_Payment_Gateway {
	/**
	 * Get the unique identifier for this gateway.
	 *
	 * @return string Gateway ID (e.g., 'cod', 'stripe', 'razorpay').
	 */
	public function get_id();

	/**
	 * Get the display title for this gateway.
	 *
	 * @return string Gateway title (e.g., 'Cash on Delivery', 'Stripe').
	 */
	public function get_title();

	/**
	 * Check if this gateway is available for the given country.
	 *
	 * @param string $country Two-letter country code (ISO 3166-1 alpha-2).
	 * @return bool True if available, false otherwise.
	 */
	public function is_available( $country );

	/**
	 * Process a payment for an order.
	 *
	 * @param int   $order_id     WooCommerce order ID.
	 * @param array $conversation Conversation context with customer details.
	 * @return array {
	 *     Payment processing result.
	 *
	 *     @type bool   $success         Whether the payment was initiated successfully.
	 *     @type string $transaction_id  Transaction/payment ID from the gateway.
	 *     @type string $payment_url     URL for customer to complete payment (if applicable).
	 *     @type string $message         Message to send to customer.
	 *     @type array  $error           Error details if payment failed.
	 * }
	 */
	public function process_payment( $order_id, $conversation );

	/**
	 * Handle webhook callback from the payment gateway.
	 *
	 * @param array $data Webhook payload data.
	 * @return array {
	 *     Webhook processing result.
	 *
	 *     @type bool   $success     Whether the webhook was processed successfully.
	 *     @type int    $order_id    Associated WooCommerce order ID.
	 *     @type string $status      Payment status (e.g., 'completed', 'failed', 'pending').
	 *     @type string $message     Processing message or error description.
	 * }
	 */
	public function handle_callback( $data );

	/**
	 * Get the current payment status for a transaction.
	 *
	 * @param string $transaction_id Transaction ID from the gateway.
	 * @return array {
	 *     Payment status information.
	 *
	 *     @type string $status          Payment status (e.g., 'completed', 'pending', 'failed').
	 *     @type string $transaction_id  Transaction ID.
	 *     @type float  $amount          Payment amount.
	 *     @type string $currency        Payment currency code.
	 *     @type array  $metadata        Additional payment metadata.
	 * }
	 */
	public function get_payment_status( $transaction_id );
}
