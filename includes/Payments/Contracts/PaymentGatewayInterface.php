<?php
/**
 * Payment Gateway Interface
 *
 * Defines the contract that all payment gateways must implement.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments\Contracts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface PaymentGatewayInterface
 *
 * Contract for payment gateway implementations.
 */
interface PaymentGatewayInterface {
	/**
	 * Get the unique identifier for this gateway.
	 *
	 * @return string Gateway ID (e.g., 'cod', 'stripe', 'razorpay').
	 */
	public function getId(): string;

	/**
	 * Get the display title for this gateway.
	 *
	 * @return string Gateway title (e.g., 'Cash on Delivery', 'Stripe').
	 */
	public function getTitle(): string;

	/**
	 * Get the gateway description.
	 *
	 * @return string Gateway description.
	 */
	public function getDescription(): string;

	/**
	 * Check if this gateway is available for the given country.
	 *
	 * @param string $country Two-letter country code (ISO 3166-1 alpha-2).
	 * @return bool True if available, false otherwise.
	 */
	public function isAvailable( string $country ): bool;

	/**
	 * Check if gateway is properly configured.
	 *
	 * @return bool True if configured, false otherwise.
	 */
	public function isConfigured(): bool;

	/**
	 * Get supported countries for this gateway.
	 *
	 * @return string[] Array of country codes.
	 */
	public function getSupportedCountries(): array;

	/**
	 * Process a payment for an order.
	 *
	 * @param int   $orderId      WooCommerce order ID.
	 * @param array $conversation Conversation context with customer details.
	 * @return PaymentResult Payment processing result.
	 */
	public function processPayment( int $orderId, array $conversation ): PaymentResult;

	/**
	 * Handle webhook callback from the payment gateway.
	 *
	 * @param array  $data      Webhook payload data.
	 * @param string $signature Request signature for verification.
	 * @return WebhookResult Webhook processing result.
	 */
	public function handleWebhook( array $data, string $signature = '' ): WebhookResult;

	/**
	 * Get the current payment status for a transaction.
	 *
	 * @param string $transactionId Transaction ID from the gateway.
	 * @return PaymentStatus Payment status information.
	 */
	public function getPaymentStatus( string $transactionId ): PaymentStatus;

	/**
	 * Process a refund for an order.
	 *
	 * @param int    $orderId       WooCommerce order ID.
	 * @param float  $amount        Refund amount.
	 * @param string $reason        Refund reason.
	 * @param string $transactionId Original transaction ID.
	 * @return RefundResult Refund processing result.
	 */
	public function processRefund( int $orderId, float $amount, string $reason, string $transactionId ): RefundResult;

	/**
	 * Verify webhook signature.
	 *
	 * @param string $payload   Raw request payload.
	 * @param string $signature Request signature.
	 * @return bool True if valid, false otherwise.
	 */
	public function verifyWebhookSignature( string $payload, string $signature ): bool;
}
