<?php
/**
 * Refund Service
 *
 * Handles refunds for WhatsApp orders through payment gateways.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\Payments;

use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WC_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refund Service Class
 *
 * Processes refunds and notifies customers via WhatsApp.
 */
class RefundService {

	/**
	 * Constructor
	 *
	 * @param Logger            $logger Logger instance
	 * @param WhatsAppApiClient $apiClient WhatsApp API client
	 */
	public function __construct(
		private readonly Logger $logger,
		private readonly WhatsAppApiClient $apiClient
	) {
	}

	/**
	 * Initialize hooks
	 */
	public function initHooks(): void {
		add_action( 'woocommerce_order_refunded', [ $this, 'handleOrderRefund' ], 10, 2 );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'notifyCustomerRefund' ], 10, 2 );
	}

	/**
	 * Handle order refund
	 *
	 * Called when a refund is created for an order.
	 *
	 * @param int $orderId Order ID
	 * @param int $refundId Refund ID
	 */
	public function handleOrderRefund( int $orderId, int $refundId ): void {
		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return;
		}

		// Check if this is a WhatsApp order
		if ( $order->get_meta( '_wch_channel' ) !== 'whatsapp' ) {
			return;
		}

		$refund = wc_get_order( $refundId );
		if ( ! $refund ) {
			return;
		}

		$refundAmount = abs( $refund->get_amount() );
		$refundReason = $refund->get_reason();

		$this->logger->info(
			'Processing refund for WhatsApp order',
			[
				'order_id' => $orderId,
				'amount'   => $refundAmount,
				'reason'   => $refundReason,
			]
		);

		// Process refund through payment gateway
		$result = $this->processRefundThroughGateway( $order, $refundAmount, $refundReason );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Refund processing failed',
				[
					'order_id' => $orderId,
					'error'    => $result->get_error_message(),
				]
			);

			$order->add_order_note(
				sprintf(
					__( 'Automatic refund failed: %s. Please process manually.', 'whatsapp-commerce-hub' ),
					$result->get_error_message()
				)
			);
		} else {
			$this->logger->info( 'Refund processed successfully', [ 'order_id' => $orderId ] );
		}
	}

	/**
	 * Process refund through payment gateway
	 *
	 * @param WC_Order $order Order object
	 * @param float    $amount Refund amount
	 * @param string   $reason Refund reason
	 * @return bool|WP_Error True on success, WP_Error on failure
	 */
	private function processRefundThroughGateway( WC_Order $order, float $amount, string $reason ): bool|WP_Error {
		$paymentMethod = $order->get_payment_method();

		// Get payment gateway
		$gateway = WC()->payment_gateways->payment_gateways()[ $paymentMethod ] ?? null;

		if ( ! $gateway ) {
			return new WP_Error(
				'gateway_not_found',
				sprintf( __( 'Payment gateway "%s" not found', 'whatsapp-commerce-hub' ), $paymentMethod )
			);
		}

		// Check if gateway supports refunds
		if ( ! $gateway->supports( 'refunds' ) ) {
			return new WP_Error(
				'refunds_not_supported',
				sprintf( __( 'Payment gateway "%s" does not support refunds', 'whatsapp-commerce-hub' ), $paymentMethod )
			);
		}

		// Process refund
		try {
			$result = $gateway->process_refund( $order->get_id(), $amount, $reason );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result === false ) {
				return new WP_Error( 'refund_failed', __( 'Refund processing failed', 'whatsapp-commerce-hub' ) );
			}

			return true;
		} catch ( \Exception $e ) {
			return new WP_Error( 'refund_exception', $e->getMessage() );
		}
	}

	/**
	 * Notify customer about refund
	 *
	 * @param int      $orderId Order ID
	 * @param WC_Order $order Order object
	 */
	public function notifyCustomerRefund( int $orderId, WC_Order $order ): void {
		// Check if this is a WhatsApp order
		if ( $order->get_meta( '_wch_channel' ) !== 'whatsapp' ) {
			return;
		}

		$customerPhone = $order->get_meta( '_wch_customer_phone' );
		if ( empty( $customerPhone ) ) {
			return;
		}

		$totalRefunded = $order->get_total_refunded();

		$message = sprintf(
			"💰 Refund Processed\n\n" .
			"Your refund for order #%s has been processed.\n\n" .
			"Refund Amount: %s\n\n" .
			'The amount will be credited to your original payment method within 5-7 business days.',
			$order->get_order_number(),
			wc_price( $totalRefunded )
		);

		try {
			$this->apiClient->sendMessage( $customerPhone, strip_tags( $message ) );

			$this->logger->info(
				'Refund notification sent to customer',
				[
					'order_id' => $orderId,
					'phone'    => $customerPhone,
				]
			);
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to send refund notification',
				[
					'order_id' => $orderId,
					'error'    => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Get refund history for an order
	 *
	 * @param int $orderId Order ID
	 * @return array<int, array<string, mixed>> Array of refund data
	 */
	public function getRefundHistory( int $orderId ): array {
		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return [];
		}

		$refunds = $order->get_refunds();
		$history = [];

		foreach ( $refunds as $refund ) {
			$history[] = [
				'id'          => $refund->get_id(),
				'amount'      => abs( $refund->get_amount() ),
				'reason'      => $refund->get_reason(),
				'date'        => $refund->get_date_created()->date( 'Y-m-d H:i:s' ),
				'refunded_by' => $refund->get_refunded_by(),
			];
		}

		return $history;
	}
}
