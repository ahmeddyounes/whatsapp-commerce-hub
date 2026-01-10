<?php
/**
 * Abstract Payment Gateway
 *
 * Base class for all payment gateway implementations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments\Gateways;

use WhatsAppCommerceHub\Payments\Contracts\PaymentGatewayInterface;
use WhatsAppCommerceHub\Payments\Contracts\PaymentResult;
use WhatsAppCommerceHub\Payments\Contracts\PaymentStatus;
use WhatsAppCommerceHub\Payments\Contracts\RefundResult;
use WhatsAppCommerceHub\Payments\Contracts\WebhookResult;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractGateway
 *
 * Base implementation for payment gateways.
 */
abstract class AbstractGateway implements PaymentGatewayInterface {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected string $id = '';

	/**
	 * Gateway title.
	 *
	 * @var string
	 */
	protected string $title = '';

	/**
	 * Gateway description.
	 *
	 * @var string
	 */
	protected string $description = '';

	/**
	 * Supported countries (empty = all countries).
	 *
	 * @var string[]
	 */
	protected array $supportedCountries = [];

	/**
	 * Get gateway ID.
	 *
	 * @return string
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * Get gateway title.
	 *
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * Get gateway description.
	 *
	 * @return string
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * Get supported countries.
	 *
	 * @return string[]
	 */
	public function getSupportedCountries(): array {
		return $this->supportedCountries;
	}

	/**
	 * Check if gateway is available for country.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function isAvailable( string $country ): bool {
		// Must be configured.
		if ( ! $this->isConfigured() ) {
			return false;
		}

		// If no country restrictions, available everywhere.
		if ( empty( $this->supportedCountries ) ) {
			return true;
		}

		return in_array( $country, $this->supportedCountries, true );
	}

	/**
	 * Check if gateway is configured.
	 *
	 * Override in subclasses to check specific credentials.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return true;
	}

	/**
	 * Default refund implementation (manual refund required).
	 *
	 * @param int    $orderId       Order ID.
	 * @param float  $amount        Refund amount.
	 * @param string $reason        Refund reason.
	 * @param string $transactionId Transaction ID.
	 * @return RefundResult
	 */
	public function processRefund( int $orderId, float $amount, string $reason, string $transactionId ): RefundResult {
		return RefundResult::manual(
			$amount,
			sprintf(
				/* translators: %s: Gateway title */
				__( 'Manual refund required for %s payment.', 'whatsapp-commerce-hub' ),
				$this->getTitle()
			)
		);
	}

	/**
	 * Default webhook signature verification (always true).
	 *
	 * Override in subclasses for actual verification.
	 *
	 * @param string $payload   Raw payload.
	 * @param string $signature Signature.
	 * @return bool
	 */
	public function verifyWebhookSignature( string $payload, string $signature ): bool {
		return true;
	}

	/**
	 * Get WooCommerce order.
	 *
	 * @param int $orderId Order ID.
	 * @return \WC_Order|null
	 */
	protected function getOrder( int $orderId ): ?\WC_Order {
		$order = wc_get_order( $orderId );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Set order payment method.
	 *
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	protected function setOrderPaymentMethod( \WC_Order $order ): void {
		$order->set_payment_method( $this->getId() );
		$order->set_payment_method_title( $this->getTitle() );
	}

	/**
	 * Store transaction metadata on order.
	 *
	 * @param \WC_Order $order         Order object.
	 * @param string    $transactionId Transaction ID.
	 * @param array     $metadata      Additional metadata.
	 * @return void
	 */
	protected function storeTransactionMeta( \WC_Order $order, string $transactionId, array $metadata = [] ): void {
		$order->update_meta_data( '_wch_transaction_id', $transactionId );
		$order->update_meta_data( '_wch_payment_method', $this->getId() );

		foreach ( $metadata as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
	}

	/**
	 * Log message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 * @param string $level   Log level (info, error, warning, debug).
	 * @return void
	 */
	protected function log( string $message, array $context = [], string $level = 'info' ): void {
		$context['gateway'] = $this->getId();
		\WCH_Logger::log( $message, $context, $level );
	}

	/**
	 * Make HTTP request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $args    Request arguments.
	 * @param string $method  HTTP method.
	 * @return array|null Response data or null on failure.
	 */
	protected function makeRequest( string $url, array $args = [], string $method = 'POST' ): ?array {
		$defaults = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		$args = wp_parse_args( $args, $defaults );

		// JSON encode body if array.
		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$args['body'] = wp_json_encode( $args['body'] );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log(
				'HTTP request failed',
				[
					'url'   => $url,
					'error' => $response->get_error_message(),
				],
				'error'
			);
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get customer phone from conversation context.
	 *
	 * @param array $conversation Conversation context.
	 * @return string
	 */
	protected function getCustomerPhone( array $conversation ): string {
		return $conversation['customer_phone'] ?? $conversation['id'] ?? '';
	}

	/**
	 * Check if order needs payment (security check).
	 *
	 * @param \WC_Order $order Order object.
	 * @return bool
	 */
	protected function orderNeedsPayment( \WC_Order $order ): bool {
		return $order->needs_payment();
	}

	/**
	 * Create a result for invalid order.
	 *
	 * @return PaymentResult
	 */
	protected function invalidOrderResult(): PaymentResult {
		return PaymentResult::failure(
			'invalid_order',
			__( 'Invalid order ID.', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Create a result for configuration error.
	 *
	 * @return PaymentResult
	 */
	protected function configurationErrorResult(): PaymentResult {
		return PaymentResult::failure(
			'configuration_error',
			sprintf(
				/* translators: %s: Gateway title */
				__( '%s is not configured properly.', 'whatsapp-commerce-hub' ),
				$this->getTitle()
			)
		);
	}
}
