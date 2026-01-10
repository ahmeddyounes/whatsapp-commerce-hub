<?php
/**
 * WhatsApp Pay Payment Gateway
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Payments\Gateways;

use WhatsAppCommerceHub\Payments\Contracts\PaymentResult;
use WhatsAppCommerceHub\Payments\Contracts\PaymentStatus;
use WhatsAppCommerceHub\Payments\Contracts\WebhookResult;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WhatsAppPayGateway
 *
 * WhatsApp Pay native payment gateway.
 */
class WhatsAppPayGateway extends AbstractGateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected string $id = 'whatsapppay';

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
	 * Supported countries.
	 *
	 * @var string[]
	 */
	protected array $supportedCountries = [ 'BR', 'IN', 'SG' ];

	/**
	 * WhatsApp API client.
	 *
	 * @var \WCH_WhatsApp_API_Client
	 */
	private \WCH_WhatsApp_API_Client $apiClient;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->title       = __( 'WhatsApp Pay', 'whatsapp-commerce-hub' );
		$this->description = __( 'Pay directly in WhatsApp', 'whatsapp-commerce-hub' );
		$this->apiClient   = new \WCH_WhatsApp_API_Client();
	}

	/**
	 * Process WhatsApp Pay payment.
	 *
	 * @param int   $orderId      Order ID.
	 * @param array $conversation Conversation context.
	 * @return PaymentResult
	 */
	public function processPayment( int $orderId, array $conversation ): PaymentResult {
		$order = $this->getOrder( $orderId );

		if ( ! $order ) {
			return $this->invalidOrderResult();
		}

		try {
			// Create WhatsApp payment request.
			$paymentRequest = $this->createPaymentRequest( $order, $conversation );

			if ( ! $paymentRequest ) {
				throw new \Exception( __( 'Failed to create WhatsApp payment request.', 'whatsapp-commerce-hub' ) );
			}

			// Set payment method.
			$this->setOrderPaymentMethod( $order );
			$order->update_status( 'pending', __( 'Awaiting WhatsApp Pay payment.', 'whatsapp-commerce-hub' ) );

			// Generate transaction ID.
			$transactionId = 'wp_' . $orderId . '_' . time();

			// Store transaction metadata.
			$this->storeTransactionMeta(
				$order,
				$transactionId,
				[ '_whatsapp_pay_reference' => $paymentRequest['reference_id'] ?? $transactionId ]
			);
			$order->save();

			$this->log( 'WhatsApp Pay request created', [ 'order_id' => $orderId ] );

			return PaymentResult::success(
				$transactionId,
				__( 'Please approve the payment request in WhatsApp to complete your order.', 'whatsapp-commerce-hub' )
			);

		} catch ( \Exception $e ) {
			$this->log( 'WhatsApp Pay payment error', [ 'error' => $e->getMessage() ], 'error' );

			return PaymentResult::failure( 'whatsapppay_error', $e->getMessage() );
		}
	}

	/**
	 * Create WhatsApp payment request.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param array     $conversation Conversation context.
	 * @return array|null
	 */
	private function createPaymentRequest( \WC_Order $order, array $conversation ): ?array {
		$customerPhone = $this->getCustomerPhone( $conversation );
		if ( empty( $customerPhone ) ) {
			return null;
		}

		// Prepare order items for payment request.
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = [
				'name'     => $item->get_name(),
				'amount'   => [
					'value'  => $item->get_total(),
					'offset' => 100,
				],
				'quantity' => $item->get_quantity(),
			];
		}

		// Build payment configuration.
		$paymentConfig = [
			'type'             => 'payment',
			'payment_settings' => [
				[
					'type'            => 'payment_gateway',
					'payment_gateway' => [
						'type'               => 'razorpay', // WhatsApp Pay uses underlying payment processors.
						'configuration_name' => get_option( 'wch_whatsapppay_config_name', 'default' ),
					],
				],
			],
		];

		$referenceId = 'wp_' . $order->get_id() . '_' . time();

		// Create order object for WhatsApp.
		$orderData = [
			'reference_id' => $referenceId,
			'type'         => 'digital-goods',
			'payment'      => $paymentConfig,
			'total_amount' => [
				'value'  => intval( $order->get_total() * 100 ),
				'offset' => 100,
			],
			'order'        => [
				'items' => $items,
			],
		];

		// Send interactive payment message.
		$message = [
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $customerPhone,
			'type'              => 'interactive',
			'interactive'       => [
				'type'   => 'order_details',
				'body'   => [
					'text' => sprintf(
						/* translators: %s: Order number */
						__( 'Please review and confirm your payment for order #%s', 'whatsapp-commerce-hub' ),
						$order->get_order_number()
					),
				],
				'action' => [
					'name'       => 'review_and_pay',
					'parameters' => $orderData,
				],
			],
		];

		$response = $this->apiClient->send_message( $message );

		if ( $response && isset( $response['messages'][0]['id'] ) ) {
			return [
				'success'      => true,
				'reference_id' => $referenceId,
				'message_id'   => $response['messages'][0]['id'],
			];
		}

		return null;
	}

	/**
	 * Handle WhatsApp Pay webhook.
	 *
	 * @param array  $data      Webhook payload.
	 * @param string $signature Request signature.
	 * @return WebhookResult
	 */
	public function handleWebhook( array $data, string $signature = '' ): WebhookResult {
		$entry    = $data['entry'][0] ?? [];
		$changes  = $entry['changes'][0] ?? [];
		$value    = $changes['value'] ?? [];
		$messages = $value['messages'] ?? [];

		foreach ( $messages as $message ) {
			if ( isset( $message['type'] ) && $message['type'] === 'order' ) {
				$orderInfo   = $message['order'] ?? [];
				$referenceId = $orderInfo['reference_id'] ?? '';

				// Extract order ID from reference.
				if ( preg_match( '/^wp_(\d+)_/', $referenceId, $matches ) ) {
					$orderId = intval( $matches[1] );
					$order   = $this->getOrder( $orderId );

					if ( ! $order ) {
						continue;
					}

					$paymentStatus = $orderInfo['payment_status'] ?? '';

					if ( in_array( $paymentStatus, [ 'captured', 'completed' ], true ) ) {
						// Security check: prevent double-spend.
						if ( ! $this->orderNeedsPayment( $order ) ) {
							$this->log(
								'WhatsApp Pay webhook skipped - order already paid',
								[
									'order_id'  => $orderId,
									'reference' => $referenceId,
								]
							);
							return WebhookResult::alreadyCompleted( $orderId, $referenceId );
						}

						$order->payment_complete( $referenceId );
						$order->add_order_note(
							sprintf(
								/* translators: %s: Transaction ID */
								__( 'WhatsApp Pay payment completed. Transaction ID: %s', 'whatsapp-commerce-hub' ),
								$referenceId
							)
						);

						$this->log( 'WhatsApp Pay payment completed', [ 'order_id' => $orderId ] );

						return WebhookResult::success(
							$orderId,
							WebhookResult::STATUS_COMPLETED,
							__( 'Payment completed successfully.', 'whatsapp-commerce-hub' ),
							$referenceId
						);
					}

					if ( $paymentStatus === 'failed' ) {
						// Only fail orders that are still pending.
						if ( $this->orderNeedsPayment( $order ) ) {
							$order->update_status( 'failed', __( 'WhatsApp Pay payment failed.', 'whatsapp-commerce-hub' ) );
						}

						return WebhookResult::success(
							$orderId,
							WebhookResult::STATUS_FAILED,
							__( 'Payment failed.', 'whatsapp-commerce-hub' ),
							$referenceId
						);
					}
				}
			}
		}

		return WebhookResult::failure( __( 'No payment information found in webhook.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @return PaymentStatus
	 */
	public function getPaymentStatus( string $transactionId ): PaymentStatus {
		// Extract order ID from transaction ID.
		if ( preg_match( '/^wp_(\d+)_/', $transactionId, $matches ) ) {
			$orderId = intval( $matches[1] );
			$order   = $this->getOrder( $orderId );

			if ( $order ) {
				$status      = $order->get_status();
				$isCompleted = in_array( $status, [ 'completed', 'processing' ], true );

				return new PaymentStatus(
					$isCompleted ? PaymentStatus::COMPLETED : PaymentStatus::PENDING,
					$transactionId,
					(float) $order->get_total(),
					$order->get_currency(),
					[
						'order_id' => $orderId,
						'method'   => 'whatsapppay',
					]
				);
			}
		}

		return PaymentStatus::unknown( $transactionId );
	}
}
