<?php
/**
 * Checkout Saga
 *
 * Orchestrates the checkout flow with compensating transactions.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Sagas;

use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Clients\WhatsAppClientInterface;
use WhatsAppCommerceHub\Sagas\SagaOrchestrator;
use WhatsAppCommerceHub\Sagas\SagaResult;
use WhatsAppCommerceHub\Sagas\SagaStep;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutSaga
 *
 * Manages the complete checkout flow with automatic rollback on failure.
 *
 * Steps:
 * 1. Validate cart
 * 2. Reserve inventory
 * 3. Create order
 * 4. Process payment
 * 5. Confirm order
 */
class CheckoutSaga {

	/**
	 * Saga orchestrator.
	 *
	 * @var SagaOrchestrator
	 */
	private SagaOrchestrator $orchestrator;

	/**
	 * Cart service.
	 *
	 * @var CartServiceInterface
	 */
	private CartServiceInterface $cart_service;

	/**
	 * WhatsApp client.
	 *
	 * @var WhatsAppClientInterface
	 */
	private WhatsAppClientInterface $whatsapp_client;

	/**
	 * Constructor.
	 *
	 * @param SagaOrchestrator        $orchestrator    Saga orchestrator.
	 * @param CartServiceInterface    $cart_service    Cart service.
	 * @param WhatsAppClientInterface $whatsapp_client WhatsApp client.
	 */
	public function __construct(
		SagaOrchestrator $orchestrator,
		CartServiceInterface $cart_service,
		WhatsAppClientInterface $whatsapp_client
	) {
		$this->orchestrator    = $orchestrator;
		$this->cart_service    = $cart_service;
		$this->whatsapp_client = $whatsapp_client;
	}

	/**
	 * Execute checkout saga.
	 *
	 * @param string $phone          Customer phone.
	 * @param array  $checkout_data  Checkout data (shipping, payment method, etc.).
	 * @return SagaResult Checkout result.
	 */
	public function execute( string $phone, array $checkout_data ): SagaResult {
		$saga_id = $this->generateSagaId( $phone );

		$context = array(
			'phone'          => $phone,
			'checkout_data'  => $checkout_data,
			'order_id'       => null,
			'payment_id'     => null,
			'inventory_held' => array(),
		);

		$steps = $this->buildSteps();

		return $this->orchestrator->execute( $saga_id, 'checkout', $context, $steps );
	}

	/**
	 * Build checkout steps.
	 *
	 * @return SagaStep[]
	 */
	private function buildSteps(): array {
		return array(
			$this->createValidateCartStep(),
			$this->createReserveInventoryStep(),
			$this->createOrderStep(),
			$this->createPaymentStep(),
			$this->createConfirmationStep(),
		);
	}

	/**
	 * Create validate cart step.
	 *
	 * @return SagaStep
	 */
	private function createValidateCartStep(): SagaStep {
		return new SagaStep(
			'validate_cart',
			function ( array $context ): array {
				$phone = $context['phone'];
				$cart  = $this->cart_service->getCart( $phone );

				if ( $cart->isEmpty() ) {
					throw new \RuntimeException( 'Cart is empty' );
				}

				if ( $cart->isExpired() ) {
					throw new \RuntimeException( 'Cart has expired' );
				}

				// Validate each item is still available.
				$validation = $this->cart_service->checkCartValidity( $phone );
				if ( ! $validation['is_valid'] ) {
					$error_messages = array_map(
						fn( $issue ) => $issue['message'] ?? 'Unknown issue',
						$validation['issues']
					);
					throw new \RuntimeException(
						'Cart validation failed: ' . implode( ', ', $error_messages )
					);
				}

				return array(
					'cart'   => $cart,
					'totals' => $this->cart_service->calculateTotals( $cart ),
				);
			},
			null, // No compensation needed - validation only reads data.
			30,   // 30 second timeout.
			3,    // 3 retries.
			true  // Critical step.
		);
	}

	/**
	 * Create reserve inventory step.
	 *
	 * @return SagaStep
	 */
	private function createReserveInventoryStep(): SagaStep {
		return new SagaStep(
			'reserve_inventory',
			function ( array $context ): array {
				$cart = $context['step_results']['validate_cart']['cart'];
				$held = array();

				foreach ( $cart->items as $item ) {
					$product_id = $item['product_id'];
					$quantity   = $item['quantity'];

					$reserved = $this->reserveStock( $product_id, $quantity );
					if ( ! $reserved ) {
						throw new \RuntimeException(
							sprintf( 'Failed to reserve stock for product %d', $product_id )
						);
					}

					$held[] = array(
						'product_id' => $product_id,
						'quantity'   => $quantity,
					);
				}

				return array( 'inventory_held' => $held );
			},
			function ( array $context ): void {
				// Compensation: release held inventory.
				$result = $context['step_result'] ?? array();
				$held   = $result['inventory_held'] ?? array();

				foreach ( $held as $item ) {
					$this->releaseStock( $item['product_id'], $item['quantity'] );
				}

				do_action( 'wch_log_info', sprintf(
					'[CheckoutSaga] Released inventory for %d items',
					count( $held )
				) );
			},
			30,
			2,
			true
		);
	}

	/**
	 * Create order step.
	 *
	 * @return SagaStep
	 */
	private function createOrderStep(): SagaStep {
		return new SagaStep(
			'create_order',
			function ( array $context ): array {
				$phone         = $context['phone'];
				$checkout_data = $context['checkout_data'];
				$cart_result   = $context['step_results']['validate_cart'];
				$cart          = $cart_result['cart'];
				$totals        = $cart_result['totals'];

				// Create WooCommerce order.
				$order = wc_create_order();

				// Validate order creation.
				if ( is_wp_error( $order ) ) {
					throw new \RuntimeException( 'Failed to create order: ' . $order->get_error_message() );
				}

				if ( ! $order instanceof \WC_Order ) {
					throw new \RuntimeException( 'Failed to create order: unexpected return type' );
				}

				// Add customer details.
				$order->set_billing_phone( $phone );

				if ( ! empty( $checkout_data['email'] ) ) {
					$order->set_billing_email( $checkout_data['email'] );
				}

				if ( ! empty( $checkout_data['name'] ) ) {
					$parts = explode( ' ', $checkout_data['name'], 2 );
					$order->set_billing_first_name( $parts[0] ?? '' );
					$order->set_billing_last_name( $parts[1] ?? '' );
				}

				// Set shipping address.
				if ( ! empty( $checkout_data['shipping_address'] ) ) {
					$address = $checkout_data['shipping_address'];
					$order->set_shipping_address_1( $address['address_1'] ?? '' );
					$order->set_shipping_address_2( $address['address_2'] ?? '' );
					$order->set_shipping_city( $address['city'] ?? '' );
					$order->set_shipping_state( $address['state'] ?? '' );
					$order->set_shipping_postcode( $address['postcode'] ?? '' );
					$order->set_shipping_country( $address['country'] ?? '' );
				}

				// Add cart items.
				foreach ( $cart->items as $item ) {
					$product = wc_get_product( $item['product_id'] );

					// Product must exist - fail the saga if not.
					// Products should have been validated in validate_cart step,
					// but may become unavailable between steps.
					if ( ! $product ) {
						throw new \RuntimeException(
							sprintf( 'Product %d is no longer available', $item['product_id'] )
						);
					}

					$order->add_product( $product, $item['quantity'] );
				}

				// Apply coupon if present.
				if ( ! empty( $cart->coupon_code ) ) {
					$order->apply_coupon( $cart->coupon_code );
				}

				// Set payment method.
				$payment_method = $checkout_data['payment_method'] ?? 'cod';
				$order->set_payment_method( $payment_method );

				// Calculate totals.
				$order->calculate_totals();

				// Set status to pending.
				$order->set_status( 'pending', 'Order created via WhatsApp Commerce Hub' );

				// Add meta for tracking.
				$order->update_meta_data( '_wch_order', true );
				$order->update_meta_data( '_wch_phone', $phone );
				$order->update_meta_data( '_wch_saga_id', $context['saga_id'] ?? '' );

				$order->save();

				return array(
					'order_id'     => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'total'        => $order->get_total(),
				);
			},
			function ( array $context ): void {
				// Compensation: cancel and delete order.
				$result   = $context['step_result'] ?? array();
				$order_id = $result['order_id'] ?? null;

				if ( $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order ) {
						$order->set_status( 'cancelled', 'Checkout saga compensation' );
						$order->save();

						do_action( 'wch_log_info', sprintf(
							'[CheckoutSaga] Cancelled order #%d',
							$order_id
						) );
					}
				}
			},
			60,
			2,
			true
		);
	}

	/**
	 * Create payment step.
	 *
	 * @return SagaStep
	 */
	private function createPaymentStep(): SagaStep {
		return new SagaStep(
			'process_payment',
			function ( array $context ): array {
				$checkout_data  = $context['checkout_data'];
				$order_result   = $context['step_results']['create_order'];
				$order_id       = $order_result['order_id'];
				$payment_method = $checkout_data['payment_method'] ?? 'cod';

				$order = wc_get_order( $order_id );

				// Validate order exists (it should, since previous step created it).
				if ( ! $order instanceof \WC_Order ) {
					throw new \RuntimeException(
						sprintf( 'Order %d not found for payment processing', $order_id )
					);
				}

				// Handle different payment methods.
				if ( 'cod' === $payment_method ) {
					// Cash on delivery - no payment processing needed.
					$order->set_status( 'processing', 'COD order - awaiting delivery' );
					$order->save();

					return array(
						'payment_status' => 'pending_cod',
						'payment_id'     => null,
					);
				}

				// For other payment methods, create payment intent.
				$payment_result = apply_filters( 'wch_process_payment', array(
					'success' => false,
					'error'   => 'Payment method not supported',
				), $order, $checkout_data );

				if ( empty( $payment_result['success'] ) ) {
					throw new \RuntimeException(
						$payment_result['error'] ?? 'Payment processing failed'
					);
				}

				return array(
					'payment_status' => 'completed',
					'payment_id'     => $payment_result['payment_id'] ?? null,
					'transaction_id' => $payment_result['transaction_id'] ?? null,
				);
			},
			function ( array $context ): void {
				// Compensation: refund payment if processed.
				$result     = $context['step_result'] ?? array();
				$payment_id = $result['payment_id'] ?? null;
				$order_id   = $context['step_results']['create_order']['order_id'] ?? null;

				if ( $payment_id && 'completed' === ( $result['payment_status'] ?? '' ) ) {
					// Attempt refund and verify it was processed.
					$refund_result = apply_filters( 'wch_refund_payment', array(
						'success' => false,
						'error'   => 'No refund handler configured',
					), $payment_id, $order_id );

					if ( ! empty( $refund_result['success'] ) ) {
						do_action( 'wch_log_info', sprintf(
							'[CheckoutSaga] Refund completed for payment %s (refund_id: %s)',
							$payment_id,
							$refund_result['refund_id'] ?? 'N/A'
						) );
					} else {
						// Refund failed - log critical error for manual intervention.
						do_action( 'wch_log_critical', sprintf(
							'[CheckoutSaga] REFUND FAILED for payment %s - manual intervention required: %s',
							$payment_id,
							$refund_result['error'] ?? 'Unknown error'
						), array(
							'payment_id' => $payment_id,
							'order_id'   => $order_id,
							'saga_id'    => $context['saga_id'] ?? null,
						) );

						// Store failed refund for later processing.
						update_option( 'wch_failed_refund_' . $payment_id, array(
							'payment_id'   => $payment_id,
							'order_id'     => $order_id,
							'saga_id'      => $context['saga_id'] ?? null,
							'error'        => $refund_result['error'] ?? 'Unknown error',
							'failed_at'    => current_time( 'mysql' ),
							'retry_count'  => 0,
						) );
					}
				}
			},
			120, // 2 minute timeout for payment.
			1,   // Only 1 attempt - payment should not be retried automatically.
			true
		);
	}

	/**
	 * Create confirmation step.
	 *
	 * @return SagaStep
	 */
	private function createConfirmationStep(): SagaStep {
		return new SagaStep(
			'confirm_order',
			function ( array $context ): array {
				$phone        = $context['phone'];
				$order_result = $context['step_results']['create_order'];
				$order_id     = $order_result['order_id'];
				$order_number = $order_result['order_number'];
				$total        = $order_result['total'];

				// Send confirmation message via WhatsApp FIRST.
				// Cart clearing happens after to ensure message is sent successfully.
				$message = sprintf(
					"🎉 Order Confirmed!\n\n" .
					"Order #%s\n" .
					"Total: %s\n\n" .
					"Thank you for your order! We'll notify you when it's shipped.",
					$order_number,
					wc_price( $total )
				);

				$send_result = $this->whatsapp_client->sendTextMessage( $phone, $message );

				// Clear the cart AFTER confirmation message is sent.
				// This ensures if message fails, cart is preserved for retry.
				$cart_cleared = false;
				try {
					$this->cart_service->clearCart( $phone );
					$cart_cleared = true;
				} catch ( \Throwable $e ) {
					// Cart clearing failure is non-critical - log and continue.
					do_action( 'wch_log_warning', sprintf(
						'[CheckoutSaga] Failed to clear cart for %s: %s',
						$phone,
						$e->getMessage()
					) );
				}

				// Fire order completed event.
				do_action( 'wch_checkout_completed', $order_id, $phone );

				return array(
					'confirmed'    => true,
					'message_id'   => $send_result['message_id'] ?? null,
					'cart_cleared' => $cart_cleared,
				);
			},
			function ( array $context ): void {
				// Compensation: notify customer of partial success.
				// At this point, order is created and paid - just confirmation failed.
				$phone        = $context['phone'];
				$order_result = $context['step_results']['create_order'] ?? array();
				$order_number = $order_result['order_number'] ?? 'N/A';

				try {
					$message = sprintf(
						"Your order #%s has been placed successfully! " .
						"We had trouble sending confirmation but your order is safe. " .
						"We'll notify you when it ships.",
						$order_number
					);

					$this->whatsapp_client->sendTextMessage( $phone, $message );
				} catch ( \Throwable $e ) {
					// Log but don't fail - notification is best effort.
					do_action( 'wch_log_warning', sprintf(
						'[CheckoutSaga] Failed to send recovery notification: %s',
						$e->getMessage()
					) );
				}
			},
			30,
			3,
			false // Non-critical - order is already created.
		);
	}

	/**
	 * Reserve stock for a product.
	 *
	 * Actually reduces stock to prevent overselling race conditions.
	 * Stock is restored by releaseStock() if saga fails.
	 *
	 * @param int $product_id Product ID.
	 * @param int $quantity   Quantity to reserve.
	 * @return bool True if reserved successfully.
	 */
	private function reserveStock( int $product_id, int $quantity ): bool {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( ! $product->managing_stock() ) {
			return true; // Not managing stock - always available.
		}

		$stock = $product->get_stock_quantity();

		// get_stock_quantity() can return null even when managing stock
		// (e.g., data corruption). Treat null as unavailable to be safe.
		// Also validate quantity is positive.
		if ( null === $stock || $quantity <= 0 || $stock < $quantity ) {
			return false;
		}

		// Actually reduce stock to prevent race conditions.
		// Use wc_update_product_stock with 'decrease' operation.
		$new_stock = wc_update_product_stock( $product, $quantity, 'decrease' );

		if ( false === $new_stock || $new_stock < 0 ) {
			// Reservation failed or resulted in negative stock (race condition).
			// Restore the stock we just decremented.
			if ( false !== $new_stock && $new_stock < 0 ) {
				wc_update_product_stock( $product, $quantity, 'increase' );
			}
			return false;
		}

		do_action( 'wch_inventory_reserved', $product_id, $quantity, $new_stock );

		return true;
	}

	/**
	 * Release reserved stock.
	 *
	 * Restores stock that was reduced during reservation.
	 *
	 * @param int $product_id Product ID.
	 * @param int $quantity   Quantity to release.
	 * @return void
	 */
	private function releaseStock( int $product_id, int $quantity ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->managing_stock() ) {
			return;
		}

		// Restore the reserved stock.
		$new_stock = wc_update_product_stock( $product, $quantity, 'increase' );

		do_action( 'wch_inventory_released', $product_id, $quantity, $new_stock );
	}

	/**
	 * Generate unique saga ID.
	 *
	 * @param string $phone Customer phone.
	 * @return string Saga ID.
	 */
	private function generateSagaId( string $phone ): string {
		return 'checkout_' . md5( $phone . '_' . microtime( true ) . '_' . wp_rand() );
	}

	/**
	 * Get order status for a checkout saga.
	 *
	 * @param string $saga_id Saga ID.
	 * @return array|null Order status or null if not found.
	 */
	public function getOrderStatus( string $saga_id ): ?array {
		$state = $this->orchestrator->getSagaState( $saga_id );

		if ( ! $state ) {
			return null;
		}

		$context = $state['context'] ?? array();
		$order_id = $context['step_results']['create_order']['order_id'] ?? null;

		$result = array(
			'saga_id'    => $saga_id,
			'saga_state' => $state['state'],
			'order_id'   => $order_id,
		);

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$result['order_status'] = $order->get_status();
				$result['order_total']  = $order->get_total();
			}
		}

		return $result;
	}
}
