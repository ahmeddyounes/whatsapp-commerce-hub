<?php
/**
 * Cash on Delivery Payment Gateway
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
 * Class CodGateway
 *
 * Cash on Delivery payment gateway implementation.
 */
class CodGateway extends AbstractGateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected string $id = 'cod';

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
	 * Constructor.
	 */
	public function __construct() {
		$this->title       = __( 'Cash on Delivery', 'whatsapp-commerce-hub' );
		$this->description = __( 'Pay when you receive the order', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if COD is available for the country.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function isAvailable( string $country ): bool {
		$disabledCountries = get_option( 'wch_cod_disabled_countries', array() );
		return ! in_array( $country, $disabledCountries, true );
	}

	/**
	 * Process COD payment.
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

		// Add COD fee if configured.
		$codFee = floatval( get_option( 'wch_cod_fee_amount', 0 ) );
		if ( $codFee > 0 ) {
			$feeItem = new \WC_Order_Item_Fee();
			$feeItem->set_name( __( 'COD Fee', 'whatsapp-commerce-hub' ) );
			$feeItem->set_amount( $codFee );
			$feeItem->set_tax_class( '' );
			$feeItem->set_tax_status( 'taxable' );
			$feeItem->set_total( $codFee );
			$order->add_item( $feeItem );
			$order->calculate_totals();
		}

		// Set payment method.
		$this->setOrderPaymentMethod( $order );

		// Order stays in pending status until delivery confirmation.
		$order->update_status( 'pending', __( 'Awaiting cash on delivery payment.', 'whatsapp-commerce-hub' ) );

		// Generate transaction ID.
		$transactionId = 'cod_' . $orderId . '_' . time();

		// Store transaction metadata.
		$this->storeTransactionMeta( $order, $transactionId );
		$order->save();

		$this->log( 'COD payment processed', array( 'order_id' => $orderId ) );

		return PaymentResult::success(
			$transactionId,
			sprintf(
				/* translators: %s: Order total */
				__( 'Your order has been confirmed! Please keep %s ready for cash on delivery. You will receive updates about your order shortly.', 'whatsapp-commerce-hub' ),
				wc_price( $order->get_total() )
			)
		);
	}

	/**
	 * Handle callback (COD doesn't use callbacks).
	 *
	 * @param array  $data      Webhook payload.
	 * @param string $signature Request signature.
	 * @return WebhookResult
	 */
	public function handleWebhook( array $data, string $signature = '' ): WebhookResult {
		return WebhookResult::failure( __( 'COD does not support callbacks.', 'whatsapp-commerce-hub' ) );
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transactionId Transaction ID.
	 * @return PaymentStatus
	 */
	public function getPaymentStatus( string $transactionId ): PaymentStatus {
		// Extract order ID from transaction ID.
		if ( preg_match( '/^cod_(\d+)_/', $transactionId, $matches ) ) {
			$orderId = intval( $matches[1] );
			$order   = $this->getOrder( $orderId );

			if ( $order ) {
				$status      = $order->get_status();
				$isCompleted = $status === 'completed';

				return new PaymentStatus(
					$isCompleted ? PaymentStatus::COMPLETED : PaymentStatus::PENDING,
					$transactionId,
					(float) $order->get_total(),
					$order->get_currency(),
					array(
						'order_id' => $orderId,
						'method'   => 'cod',
					)
				);
			}
		}

		return PaymentStatus::unknown( $transactionId );
	}
}
