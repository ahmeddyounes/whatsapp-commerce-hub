<?php
/**
 * Checkout Orchestrator
 *
 * Coordinates the checkout flow using focused services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutStateManagerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\AddressHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\ShippingCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\PaymentHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutTotalsCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CouponHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\OrderSyncServiceInterface;
use WhatsAppCommerceHub\Sagas\CheckoutSaga;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutOrchestrator
 *
 * Coordinates all checkout services.
 */
class CheckoutOrchestrator implements CheckoutOrchestratorInterface {

	/**
	 * State manager.
	 *
	 * @var CheckoutStateManagerInterface
	 */
	protected CheckoutStateManagerInterface $stateManager;

	/**
	 * Address handler.
	 *
	 * @var AddressHandlerInterface
	 */
	protected AddressHandlerInterface $addressHandler;

	/**
	 * Shipping calculator.
	 *
	 * @var ShippingCalculatorInterface
	 */
	protected ShippingCalculatorInterface $shippingCalculator;

	/**
	 * Payment handler.
	 *
	 * @var PaymentHandlerInterface
	 */
	protected PaymentHandlerInterface $paymentHandler;

	/**
	 * Totals calculator.
	 *
	 * @var CheckoutTotalsCalculatorInterface
	 */
	protected CheckoutTotalsCalculatorInterface $totalsCalculator;

	/**
	 * Coupon handler.
	 *
	 * @var CouponHandlerInterface
	 */
	protected CouponHandlerInterface $couponHandler;

	/**
	 * Cart service.
	 *
	 * @var CartServiceInterface|null
	 */
	protected ?CartServiceInterface $cartService;

	/**
	 * Order sync service.
	 *
	 * @var OrderSyncServiceInterface|null
	 */
	protected ?OrderSyncServiceInterface $orderSyncService;

	/**
	 * Checkout saga.
	 *
	 * @var CheckoutSaga|null
	 */
	protected ?CheckoutSaga $checkoutSaga;

	/**
	 * Constructor.
	 *
	 * @param CheckoutStateManagerInterface     $stateManager       State manager.
	 * @param AddressHandlerInterface           $addressHandler     Address handler.
	 * @param ShippingCalculatorInterface       $shippingCalculator Shipping calculator.
	 * @param PaymentHandlerInterface           $paymentHandler     Payment handler.
	 * @param CheckoutTotalsCalculatorInterface $totalsCalculator   Totals calculator.
	 * @param CouponHandlerInterface            $couponHandler      Coupon handler.
	 * @param CartServiceInterface|null         $cartService        Cart service.
	 * @param OrderSyncServiceInterface|null    $orderSyncService   Order sync service.
	 * @param CheckoutSaga|null                 $checkoutSaga       Checkout saga.
	 */
	public function __construct(
		CheckoutStateManagerInterface $stateManager,
		AddressHandlerInterface $addressHandler,
		ShippingCalculatorInterface $shippingCalculator,
		PaymentHandlerInterface $paymentHandler,
		CheckoutTotalsCalculatorInterface $totalsCalculator,
		CouponHandlerInterface $couponHandler,
		?CartServiceInterface $cartService = null,
		?OrderSyncServiceInterface $orderSyncService = null,
		?CheckoutSaga $checkoutSaga = null
	) {
		$this->stateManager       = $stateManager;
		$this->addressHandler     = $addressHandler;
		$this->shippingCalculator = $shippingCalculator;
		$this->paymentHandler     = $paymentHandler;
		$this->totalsCalculator   = $totalsCalculator;
		$this->couponHandler      = $couponHandler;
		$this->cartService        = $cartService;
		$this->orderSyncService   = $orderSyncService;
		$this->checkoutSaga       = $checkoutSaga;
	}

	/**
	 * Start checkout process.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function startCheckout( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );

		// Validate cart.
		$validation = $this->validateCheckout( $phone );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'step'    => '',
				'data'    => array(),
				'error'   => implode( ', ', array_column( $validation['issues'], 'message' ) ),
			);
		}

		// Initialize checkout state.
		$this->stateManager->initializeState( $phone );

		do_action( 'wch_checkout_started', $phone );

		return array(
			'success' => true,
			'step'    => CheckoutStateManagerInterface::STEP_ADDRESS,
			'data'    => $this->getStepData( $phone, CheckoutStateManagerInterface::STEP_ADDRESS ),
			'error'   => null,
		);
	}

	/**
	 * Get current checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{step: string|null, data: array}
	 */
	public function getCheckoutState( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return array( 'step' => null, 'data' => array() );
		}

		// Check timeout.
		if ( $this->stateManager->hasTimedOut( $phone ) ) {
			$this->cancelCheckout( $phone );
			return array( 'step' => null, 'data' => array() );
		}

		return array(
			'step' => $state['step'],
			'data' => $this->getStepData( $phone, $state['step'] ),
		);
	}

	/**
	 * Process address input.
	 *
	 * @param string       $phone   Customer phone number.
	 * @param array|string $address Address data or saved address ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processAddress( string $phone, array|string $address ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return $this->errorResponse( __( 'No active checkout session', 'whatsapp-commerce-hub' ) );
		}

		// Handle saved address ID.
		if ( is_string( $address ) && ! empty( $address ) ) {
			$address = $this->addressHandler->getSavedAddress( $phone, $address );
			if ( ! $address ) {
				return $this->errorResponse( __( 'Saved address not found', 'whatsapp-commerce-hub' ) );
			}
		}

		// Validate address.
		$validation = $this->addressHandler->validateAddress( $address );
		if ( ! $validation['valid'] ) {
			return $this->errorResponse( $validation['error'] );
		}

		// Save address to customer profile.
		$this->addressHandler->saveAddress( $phone, $address );

		// Update state.
		$this->stateManager->updateState( $phone, array(
			'address' => $address,
			'step'    => CheckoutStateManagerInterface::STEP_SHIPPING_METHOD,
		) );

		return array(
			'success' => true,
			'step'    => CheckoutStateManagerInterface::STEP_SHIPPING_METHOD,
			'data'    => $this->getStepData( $phone, CheckoutStateManagerInterface::STEP_SHIPPING_METHOD ),
			'error'   => null,
		);
	}

	/**
	 * Process shipping method selection.
	 *
	 * @param string $phone    Customer phone number.
	 * @param string $methodId Shipping method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processShippingMethod( string $phone, string $methodId ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return $this->errorResponse( __( 'No active checkout session', 'whatsapp-commerce-hub' ) );
		}

		// Validate method exists.
		$methods = $this->getShippingMethods( $phone );
		$selected = null;

		foreach ( $methods as $method ) {
			if ( $method['id'] === $methodId ) {
				$selected = $method;
				break;
			}
		}

		if ( ! $selected ) {
			return $this->errorResponse( __( 'Invalid shipping method', 'whatsapp-commerce-hub' ) );
		}

		// Update state.
		$this->stateManager->updateState( $phone, array(
			'shipping_method' => $selected,
			'step'            => CheckoutStateManagerInterface::STEP_PAYMENT_METHOD,
		) );

		return array(
			'success' => true,
			'step'    => CheckoutStateManagerInterface::STEP_PAYMENT_METHOD,
			'data'    => $this->getStepData( $phone, CheckoutStateManagerInterface::STEP_PAYMENT_METHOD ),
			'error'   => null,
		);
	}

	/**
	 * Process payment method selection.
	 *
	 * @param string $phone    Customer phone number.
	 * @param string $methodId Payment method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processPaymentMethod( string $phone, string $methodId ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return $this->errorResponse( __( 'No active checkout session', 'whatsapp-commerce-hub' ) );
		}

		// Validate method.
		if ( ! $this->paymentHandler->validateMethod( $methodId ) ) {
			return $this->errorResponse( __( 'Invalid payment method', 'whatsapp-commerce-hub' ) );
		}

		$methodDetails = $this->paymentHandler->getMethodDetails( $methodId );

		// Update state.
		$this->stateManager->updateState( $phone, array(
			'payment_method' => $methodDetails,
			'step'           => CheckoutStateManagerInterface::STEP_REVIEW,
		) );

		return array(
			'success' => true,
			'step'    => CheckoutStateManagerInterface::STEP_REVIEW,
			'data'    => $this->getStepData( $phone, CheckoutStateManagerInterface::STEP_REVIEW ),
			'error'   => null,
		);
	}

	/**
	 * Confirm and create order.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, order_id: int|null, order_number: string|null, error: string|null}
	 */
	public function confirmOrder( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return array(
				'success'      => false,
				'order_id'     => null,
				'order_number' => null,
				'error'        => __( 'No active checkout session', 'whatsapp-commerce-hub' ),
			);
		}

		// Final validation.
		$validation = $this->validateCheckout( $phone );
		if ( ! $validation['valid'] ) {
			return array(
				'success'      => false,
				'order_id'     => null,
				'order_number' => null,
				'error'        => implode( ', ', array_column( $validation['issues'], 'message' ) ),
			);
		}

		try {
			// Use saga if available.
			if ( $this->checkoutSaga ) {
				$checkoutData = array(
					'shipping_address' => $state['address'],
					'shipping_method'  => $state['shipping_method']['id'] ?? '',
					'payment_method'   => $state['payment_method']['id'] ?? 'cod',
					'coupon_code'      => $state['coupon_code'],
				);

				$result = $this->checkoutSaga->execute( $phone, $checkoutData );

				if ( $result->isSuccess() ) {
					$orderData = $result->getStepResult( 'create_order' );
					$this->stateManager->clearState( $phone );

					return array(
						'success'      => true,
						'order_id'     => $orderData['order_id'] ?? null,
						'order_number' => $orderData['order_number'] ?? null,
						'error'        => null,
					);
				} else {
					return array(
						'success'      => false,
						'order_id'     => null,
						'order_number' => null,
						'error'        => $result->getError() ?? __( 'Order creation failed', 'whatsapp-commerce-hub' ),
					);
				}
			}

			// Fallback: Direct order creation via OrderSyncService.
			if ( $this->orderSyncService && $this->cartService ) {
				$cart = $this->cartService->getCart( $phone );

				$cartData = array(
					'items'            => $cart->items,
					'billing_address'  => $state['address'],
					'shipping_address' => $state['address'],
					'shipping_method'  => $state['shipping_method']['id'] ?? '',
					'shipping_cost'    => $state['shipping_method']['cost'] ?? 0,
					'shipping_title'   => $state['shipping_method']['label'] ?? '',
					'payment_method'   => $state['payment_method']['id'] ?? 'cod',
					'coupon_code'      => $state['coupon_code'],
				);

				$orderId = $this->orderSyncService->createOrderFromCart( $cartData, $phone );

				// Clear cart and checkout state.
				$this->cartService->clearCart( $phone );
				$this->stateManager->clearState( $phone );

				$order = wc_get_order( $orderId );

				return array(
					'success'      => true,
					'order_id'     => $orderId,
					'order_number' => $order ? $order->get_order_number() : (string) $orderId,
					'error'        => null,
				);
			}

			throw new \RuntimeException( __( 'No order service available', 'whatsapp-commerce-hub' ) );

		} catch ( \Exception $e ) {
			do_action( 'wch_log_error', 'CheckoutOrchestrator: Order confirmation failed', array(
				'phone' => $phone,
				'error' => $e->getMessage(),
			) );

			return array(
				'success'      => false,
				'order_id'     => null,
				'order_number' => null,
				'error'        => $e->getMessage(),
			);
		}
	}

	/**
	 * Cancel checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function cancelCheckout( string $phone ): bool {
		$phone = $this->sanitizePhone( $phone );
		$this->stateManager->clearState( $phone );

		do_action( 'wch_checkout_cancelled', $phone );

		return true;
	}

	/**
	 * Go back to previous step.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, step: string, data: array}
	 */
	public function goBack( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return array(
				'success' => false,
				'step'    => '',
				'data'    => array(),
			);
		}

		$previousStep = $this->stateManager->getPreviousStep( $state['step'] );
		$this->stateManager->advanceToStep( $phone, $previousStep );

		return array(
			'success' => true,
			'step'    => $previousStep,
			'data'    => $this->getStepData( $phone, $previousStep ),
		);
	}

	/**
	 * Validate checkout can proceed.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{valid: bool, issues: array}
	 */
	public function validateCheckout( string $phone ): array {
		$phone  = $this->sanitizePhone( $phone );
		$issues = array();

		// Check cart service.
		if ( ! $this->cartService ) {
			$issues[] = array( 'type' => 'service', 'message' => __( 'Cart service unavailable', 'whatsapp-commerce-hub' ) );
			return array( 'valid' => false, 'issues' => $issues );
		}

		// Check cart exists and is valid.
		$cart = $this->cartService->getCart( $phone );
		if ( ! $cart || $cart->isEmpty() ) {
			$issues[] = array( 'type' => 'cart', 'message' => __( 'Cart is empty', 'whatsapp-commerce-hub' ) );
			return array( 'valid' => false, 'issues' => $issues );
		}

		// Check cart validity (stock, etc.).
		$cartValidation = $this->cartService->checkCartValidity( $phone );
		if ( ! $cartValidation['is_valid'] ) {
			foreach ( $cartValidation['issues'] as $issue ) {
				$issues[] = array(
					'type'    => 'item',
					'message' => $issue['message'] ?? __( 'Item validation failed', 'whatsapp-commerce-hub' ),
				);
			}
		}

		return array(
			'valid'  => empty( $issues ),
			'issues' => $issues,
		);
	}

	/**
	 * Apply coupon during checkout.
	 *
	 * @param string $phone      Customer phone number.
	 * @param string $couponCode Coupon code.
	 * @return array{success: bool, discount: float, error: string|null}
	 */
	public function applyCoupon( string $phone, string $couponCode ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return array( 'success' => false, 'discount' => 0, 'error' => __( 'No active checkout', 'whatsapp-commerce-hub' ) );
		}

		// Get cart total.
		$cartTotal = 0;
		if ( $this->cartService ) {
			$cart      = $this->cartService->getCart( $phone );
			$cartTotal = $cart ? $cart->total : 0;
		}

		$result = $this->couponHandler->applyCoupon( $couponCode, $cartTotal );

		if ( $result['success'] ) {
			$this->stateManager->updateState( $phone, array(
				'coupon_code' => $this->couponHandler->sanitizeCouponCode( $couponCode ),
			) );
		}

		return $result;
	}

	/**
	 * Remove coupon during checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function removeCoupon( string $phone ): bool {
		$phone = $this->sanitizePhone( $phone );

		return $this->stateManager->updateState( $phone, array( 'coupon_code' => null ) );
	}

	/**
	 * Get available shipping methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of shipping methods.
	 */
	public function getShippingMethods( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state || empty( $state['address'] ) ) {
			return array();
		}

		$items = $this->getCartItems( $phone );

		return $this->shippingCalculator->getAvailableMethods( $phone, $state['address'], $items );
	}

	/**
	 * Get available payment methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of payment methods.
	 */
	public function getPaymentMethods( string $phone ): array {
		return $this->paymentHandler->getAvailableMethods( $phone );
	}

	/**
	 * Get order review data.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Order review data.
	 */
	public function getOrderReview( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );

		if ( ! $state ) {
			return array();
		}

		$items = $this->getCartItems( $phone );

		return $this->totalsCalculator->getOrderReview( $phone, $state, $items );
	}

	/**
	 * Calculate final totals.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Totals breakdown.
	 */
	public function calculateTotals( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->stateManager->loadState( $phone );
		$items = $this->getCartItems( $phone );

		return $this->totalsCalculator->calculateTotals( array(
			'items'         => $items,
			'coupon_code'   => $state['coupon_code'] ?? '',
			'shipping_cost' => $state['shipping_method']['cost'] ?? 0,
			'payment_fee'   => $state['payment_method']['fee'] ?? 0,
			'address'       => $state['address'] ?? array(),
		) );
	}

	/**
	 * Get step-specific data.
	 *
	 * @param string $phone Customer phone.
	 * @param string $step  Current step.
	 * @return array Step data.
	 */
	private function getStepData( string $phone, string $step ): array {
		$state = $this->stateManager->loadState( $phone );

		switch ( $step ) {
			case CheckoutStateManagerInterface::STEP_ADDRESS:
				return array(
					'saved_addresses' => $this->addressHandler->getSavedAddresses( $phone ),
					'current_address' => $state['address'] ?? null,
				);

			case CheckoutStateManagerInterface::STEP_SHIPPING_METHOD:
				return array(
					'methods'  => $this->getShippingMethods( $phone ),
					'selected' => $state['shipping_method'] ?? null,
				);

			case CheckoutStateManagerInterface::STEP_PAYMENT_METHOD:
				return array(
					'methods'  => $this->getPaymentMethods( $phone ),
					'selected' => $state['payment_method'] ?? null,
				);

			case CheckoutStateManagerInterface::STEP_REVIEW:
				return $this->getOrderReview( $phone );

			case CheckoutStateManagerInterface::STEP_CONFIRM:
				return array(
					'totals' => $this->calculateTotals( $phone ),
				);

			default:
				return array();
		}
	}

	/**
	 * Get cart items for a customer.
	 *
	 * @param string $phone Customer phone.
	 * @return array Cart items.
	 */
	private function getCartItems( string $phone ): array {
		if ( ! $this->cartService ) {
			return array();
		}

		$cart = $this->cartService->getCart( $phone );

		return $cart ? $cart->items : array();
	}

	/**
	 * Create error response.
	 *
	 * @param string $error Error message.
	 * @return array Error response.
	 */
	private function errorResponse( string $error ): array {
		return array(
			'success' => false,
			'step'    => '',
			'data'    => array(),
			'error'   => $error,
		);
	}

	/**
	 * Sanitize phone number.
	 *
	 * @param string $phone Phone number.
	 * @return string Sanitized phone.
	 */
	private function sanitizePhone( string $phone ): string {
		return preg_replace( '/[^0-9+]/', '', $phone );
	}
}
