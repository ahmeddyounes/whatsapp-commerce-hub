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

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Payments\Contracts\RefundResult;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RefundService
 *
 * Processes refunds for orders and notifies customers.
 */
class RefundService {
	/**
	 * WhatsApp API client.
	 *
	 * @var \WCH_WhatsApp_API_Client
	 */
	private \WCH_WhatsApp_API_Client $apiClient;

	/**
	 * Constructor.
	 *
	 * @param \WCH_WhatsApp_API_Client|null $apiClient WhatsApp API client.
	 */
	public function __construct( ?\WCH_WhatsApp_API_Client $apiClient = null ) {
		$this->apiClient = $apiClient ?? new \WCH_WhatsApp_API_Client();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_order_refunded', array( $this, 'handleOrderRefund' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'notifyCustomerRefund' ), 10, 2 );
	}

	/**
	 * Handle order refund.
	 *
	 * Called when a refund is created for an order.
	 *
	 * @param int $orderId  Order ID.
	 * @param int $refundId Refund ID.
	 * @return void
	 */
	public function handleOrderRefund( int $orderId, int $refundId ): void {
		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return;
		}

		// Check if this is a WhatsApp order.
		if ( $order->get_meta( '_wch_channel' ) !== 'whatsapp' ) {
			return;
		}

		$refund = wc_get_order( $refundId );
		if ( ! $refund ) {
			return;
		}

		$refundAmount = abs( (float) $refund->get_amount() );
		$refundReason = $refund->get_reason();

		$this->log(
			'Processing refund for WhatsApp order',
			array(
				'order_id' => $orderId,
				'amount'   => $refundAmount,
				'reason'   => $refundReason,
			)
		);

		// Process refund through payment gateway.
		$result = $this->processGatewayRefund( $order, $refundAmount, $refundReason );

		if ( ! $result->isSuccess() && ! $result->isManual() ) {
			$this->log(
				'Refund processing failed',
				array(
					'order_id' => $orderId,
					'error'    => $result->getErrorMessage(),
				),
				'error'
			);

			$order->add_order_note(
				sprintf(
					/* translators: %s: Error message */
					__( 'Automatic refund failed: %s. Please process manually.', 'whatsapp-commerce-hub' ),
					$result->getErrorMessage()
				)
			);
		} else {
			$this->log(
				'Refund processed successfully',
				array(
					'order_id'       => $orderId,
					'transaction_id' => $result->getTransactionId(),
				)
			);
		}
	}

	/**
	 * Process refund through payment gateway.
	 *
	 * @param \WC_Order $order        Order object.
	 * @param float     $refundAmount Amount to refund.
	 * @param string    $refundReason Refund reason.
	 * @return RefundResult
	 */
	public function processGatewayRefund(
		\WC_Order $order,
		float $refundAmount,
		string $refundReason = ''
	): RefundResult {
		$paymentMethod = $order->get_payment_method();
		$transactionId = $order->get_transaction_id();

		// Get payment gateway from container or payment manager.
		$gateway = $this->getPaymentGateway( $paymentMethod );

		if ( ! $gateway ) {
			return RefundResult::manualRequired(
				__( 'Payment gateway not found. Manual refund required.', 'whatsapp-commerce-hub' )
			);
		}

		return $gateway->processRefund(
			$order->get_id(),
			$refundAmount,
			$refundReason,
			$transactionId
		);
	}

	/**
	 * Notify customer about refund.
	 *
	 * @param int             $orderId Order ID.
	 * @param \WC_Order|false $order   Order object.
	 * @return void
	 */
	public function notifyCustomerRefund( int $orderId, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $orderId );
		}

		if ( ! $order ) {
			return;
		}

		// Check if this is a WhatsApp order.
		if ( $order->get_meta( '_wch_channel' ) !== 'whatsapp' ) {
			return;
		}

		$customerPhone = $order->get_meta( '_wch_customer_phone' );
		if ( empty( $customerPhone ) ) {
			return;
		}

		// Check opt-out.
		if ( $this->isCustomerOptedOut( $customerPhone ) ) {
			return;
		}

		// Build refund notification message.
		$totalRefunded = $order->get_total_refunded();

		$message = sprintf(
			/* translators: 1: Order number, 2: Refund amount */
			__( "Refund Processed\n\nYour refund for order #%1\$s has been processed.\n\nRefund Amount: %2\$s\n\nThe amount will be credited to your original payment method within 5-7 business days.", 'whatsapp-commerce-hub' ),
			$order->get_order_number(),
			wc_price( $totalRefunded )
		);

		// Send message to customer.
		$this->apiClient->send_message(
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $customerPhone,
				'type'              => 'text',
				'text'              => array(
					'body' => $message,
				),
			)
		);

		$this->log(
			'Refund notification sent',
			array(
				'order_id' => $orderId,
				'phone'    => $this->maskPhone( $customerPhone ),
			)
		);
	}

	/**
	 * Process refund for a specific order.
	 *
	 * SECURITY: Uses locking to prevent race conditions where multiple
	 * refund requests could exceed the order total.
	 *
	 * @param int    $orderId Order ID.
	 * @param float  $amount  Amount to refund.
	 * @param string $reason  Refund reason.
	 * @return RefundResult
	 */
	public function refund( int $orderId, float $amount, string $reason = '' ): RefundResult {
		global $wpdb;

		// SECURITY: Acquire exclusive lock to prevent TOCTOU race condition.
		// This ensures only one refund can be processed at a time per order.
		$lockKey      = 'wch_refund_lock_' . $orderId;
		$lockAcquired = $wpdb->query(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, 5)',
				$lockKey
			)
		);

		if ( ! $lockAcquired ) {
			$this->log(
				'Failed to acquire refund lock',
				array( 'order_id' => $orderId ),
				'warning'
			);
			return RefundResult::failure(
				'lock_failed',
				__( 'Refund is being processed. Please wait.', 'whatsapp-commerce-hub' )
			);
		}

		try {
			$order = wc_get_order( $orderId );

			if ( ! $order ) {
				return RefundResult::failure(
					'order_not_found',
					__( 'Order not found.', 'whatsapp-commerce-hub' )
				);
			}

			// SECURITY: Re-fetch order data within the lock to get accurate refunded amount.
			// Clear WooCommerce cache to ensure we get fresh data.
			clean_post_cache( $orderId );
			$order = wc_get_order( $orderId );

			// Validate refund amount with fresh data.
			$maxRefundable = (float) $order->get_total() - (float) $order->get_total_refunded();

			if ( $amount > $maxRefundable + 0.01 ) { // Allow small floating point tolerance.
				return RefundResult::failure(
					'amount_exceeds_limit',
					sprintf(
						/* translators: %s: Maximum refundable amount */
						__( 'Refund amount exceeds maximum refundable amount: %s', 'whatsapp-commerce-hub' ),
						wc_price( $maxRefundable )
					)
				);
			}

			// Cap amount at max refundable to handle floating point edge cases.
			$amount = min( $amount, $maxRefundable );

			// Process through gateway.
			return $this->processGatewayRefund( $order, $amount, $reason );
		} finally {
			// SECURITY: Always release the lock.
			$wpdb->query(
				$wpdb->prepare(
					'SELECT RELEASE_LOCK(%s)',
					$lockKey
				)
			);
		}
	}

	/**
	 * Get payment gateway by ID.
	 *
	 * @param string $gatewayId Gateway ID.
	 * @return \WhatsAppCommerceHub\Payments\Contracts\PaymentGatewayInterface|null
	 */
	private function getPaymentGateway( string $gatewayId ) {
		// Try to get from container first.
		try {
			$container = \WhatsAppCommerceHub\Container\Container::getInstance();
			if ( $container->has( "payment.gateway.{$gatewayId}" ) ) {
				return $container->get( "payment.gateway.{$gatewayId}" );
			}
		} catch ( \Exception $e ) {
			// Fall back to payment manager.
		}

		// Fall back to legacy payment manager.
		if ( class_exists( 'WCH_Payment_Manager' ) ) {
			$manager = \WCH_Payment_Manager::instance();
			return $manager->get_gateway( $gatewayId );
		}

		return null;
	}

	/**
	 * Check if customer has opted out of notifications.
	 *
	 * @param string $phone Customer phone.
	 * @return bool
	 */
	private function isCustomerOptedOut( string $phone ): bool {
		global $wpdb;

		$tableName = $wpdb->prefix . 'wch_customer_profiles';

		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT notification_opt_out FROM {$tableName} WHERE phone = %s",
				$phone
			)
		);

		return $profile && ! empty( $profile->notification_opt_out );
	}

	/**
	 * Mask phone number for logging.
	 *
	 * @param string $phone Phone number.
	 * @return string
	 */
	private function maskPhone( string $phone ): string {
		$length = strlen( $phone );
		if ( $length <= 4 ) {
			return '****';
		}
		return substr( $phone, 0, 3 ) . str_repeat( '*', $length - 6 ) . substr( $phone, -3 );
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @param string $level   Log level.
	 * @return void
	 */
	private function log( string $message, array $context = array(), string $level = 'info' ): void {
		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
