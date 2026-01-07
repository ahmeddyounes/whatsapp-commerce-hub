<?php
/**
 * Cash on Delivery Payment Gateway
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Payment_COD implements WCH_Payment_Gateway {
	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	const GATEWAY_ID = 'cod';

	/**
	 * Get the gateway ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return self::GATEWAY_ID;
	}

	/**
	 * Get the gateway title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Cash on Delivery', 'whatsapp-commerce-hub' );
	}

	/**
	 * Check if COD is available for the country.
	 *
	 * COD is generally available everywhere unless specifically disabled.
	 *
	 * @param string $country Country code.
	 * @return bool
	 */
	public function is_available( $country ) {
		$disabled_countries = get_option( 'wch_cod_disabled_countries', array() );
		return ! in_array( $country, $disabled_countries, true );
	}

	/**
	 * Process COD payment.
	 *
	 * @param int   $order_id     Order ID.
	 * @param array $conversation Conversation context.
	 * @return array Payment result.
	 */
	public function process_payment( $order_id, $conversation ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'invalid_order',
					'message' => __( 'Invalid order ID.', 'whatsapp-commerce-hub' ),
				),
			);
		}

		// Add COD fee if configured.
		$cod_fee = floatval( get_option( 'wch_cod_fee_amount', 0 ) );
		if ( $cod_fee > 0 ) {
			$fee_item = new WC_Order_Item_Fee();
			$fee_item->set_name( __( 'COD Fee', 'whatsapp-commerce-hub' ) );
			$fee_item->set_amount( $cod_fee );
			$fee_item->set_tax_class( '' );
			$fee_item->set_tax_status( 'taxable' );
			$fee_item->set_total( $cod_fee );
			$order->add_item( $fee_item );
			$order->calculate_totals();
		}

		// Set payment method.
		$order->set_payment_method( self::GATEWAY_ID );
		$order->set_payment_method_title( $this->get_title() );

		// Order stays in pending status until delivery confirmation.
		$order->update_status( 'pending', __( 'Awaiting cash on delivery payment.', 'whatsapp-commerce-hub' ) );

		// Store transaction metadata.
		$transaction_id = 'cod_' . $order_id . '_' . time();
		$order->update_meta_data( '_wch_transaction_id', $transaction_id );
		$order->update_meta_data( '_wch_payment_method', self::GATEWAY_ID );
		$order->save();

		return array(
			'success'        => true,
			'transaction_id' => $transaction_id,
			'payment_url'    => '',
			'message'        => sprintf(
				/* translators: %s: Order total */
				__( 'Your order has been confirmed! Please keep %s ready for cash on delivery. You will receive updates about your order shortly.', 'whatsapp-commerce-hub' ),
				wc_price( $order->get_total() )
			),
		);
	}

	/**
	 * Handle callback (COD doesn't use callbacks).
	 *
	 * @param array $data Callback data.
	 * @return array
	 */
	public function handle_callback( $data ) {
		return array(
			'success' => false,
			'message' => __( 'COD does not support callbacks.', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Get payment status.
	 *
	 * @param string $transaction_id Transaction ID.
	 * @return array
	 */
	public function get_payment_status( $transaction_id ) {
		// Extract order ID from transaction ID.
		if ( preg_match( '/^cod_(\d+)_/', $transaction_id, $matches ) ) {
			$order_id = intval( $matches[1] );
			$order    = wc_get_order( $order_id );

			if ( $order ) {
				$status = $order->get_status();
				return array(
					'status'         => $status === 'completed' ? 'completed' : 'pending',
					'transaction_id' => $transaction_id,
					'amount'         => $order->get_total(),
					'currency'       => $order->get_currency(),
					'metadata'       => array(
						'order_id' => $order_id,
						'method'   => 'cod',
					),
				);
			}
		}

		return array(
			'status'         => 'unknown',
			'transaction_id' => $transaction_id,
			'amount'         => 0,
			'currency'       => '',
			'metadata'       => array(),
		);
	}
}
