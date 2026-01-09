<?php
/**
 * Checkout Service
 *
 * Handles checkout flow management for WhatsApp Commerce.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Services;

use WhatsAppCommerceHub\Contracts\Services\CheckoutServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\OrderSyncServiceInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Sagas\CheckoutSaga;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutService
 *
 * Implements checkout flow operations.
 */
class CheckoutService implements CheckoutServiceInterface {

	/**
	 * Checkout state transient prefix.
	 */
	private const STATE_PREFIX = 'wch_checkout_';

	/**
	 * Default checkout timeout (15 minutes).
	 */
	private const DEFAULT_TIMEOUT = 900;

	/**
	 * Step order for navigation.
	 *
	 * @var array<string, int>
	 */
	private const STEP_ORDER = array(
		self::STEP_ADDRESS         => 1,
		self::STEP_SHIPPING_METHOD => 2,
		self::STEP_PAYMENT_METHOD  => 3,
		self::STEP_REVIEW          => 4,
		self::STEP_CONFIRM         => 5,
	);

	/**
	 * Cart service.
	 *
	 * @var CartServiceInterface|null
	 */
	private ?CartServiceInterface $cart_service;

	/**
	 * Order sync service.
	 *
	 * @var OrderSyncServiceInterface|null
	 */
	private ?OrderSyncServiceInterface $order_sync_service;

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepositoryInterface|null
	 */
	private ?CustomerRepositoryInterface $customer_repository;

	/**
	 * Checkout saga.
	 *
	 * @var CheckoutSaga|null
	 */
	private ?CheckoutSaga $checkout_saga;

	/**
	 * Constructor.
	 *
	 * @param CartServiceInterface|null       $cart_service        Cart service.
	 * @param OrderSyncServiceInterface|null  $order_sync_service  Order sync service.
	 * @param CustomerRepositoryInterface|null $customer_repository Customer repository.
	 * @param CheckoutSaga|null               $checkout_saga       Checkout saga.
	 */
	public function __construct(
		?CartServiceInterface $cart_service = null,
		?OrderSyncServiceInterface $order_sync_service = null,
		?CustomerRepositoryInterface $customer_repository = null,
		?CheckoutSaga $checkout_saga = null
	) {
		$this->cart_service        = $cart_service;
		$this->order_sync_service  = $order_sync_service;
		$this->customer_repository = $customer_repository;
		$this->checkout_saga       = $checkout_saga;
	}

	/**
	 * Start checkout process.
	 *
	 * @param string $phone Customer phone number.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 * @throws \RuntimeException If cart is empty or invalid.
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
		$state = array(
			'step'            => self::STEP_ADDRESS,
			'phone'           => $phone,
			'address'         => null,
			'shipping_method' => null,
			'payment_method'  => null,
			'coupon_code'     => null,
			'started_at'      => time(),
			'updated_at'      => time(),
		);

		$this->saveState( $phone, $state );

		do_action( 'wch_checkout_started', $phone );

		return array(
			'success' => true,
			'step'    => self::STEP_ADDRESS,
			'data'    => $this->getStepData( $phone, self::STEP_ADDRESS ),
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
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return array( 'step' => null, 'data' => array() );
		}

		// Check timeout.
		if ( $this->hasTimedOut( $phone ) ) {
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
	 * @throws \InvalidArgumentException If address is invalid.
	 */
	public function processAddress( string $phone, array|string $address ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return $this->errorResponse( 'No active checkout session' );
		}

		// Handle saved address ID.
		if ( is_string( $address ) && ! empty( $address ) ) {
			$address = $this->getSavedAddress( $phone, $address );
			if ( ! $address ) {
				return $this->errorResponse( 'Saved address not found' );
			}
		}

		// Validate address.
		$validation = $this->validateAddress( $address );
		if ( ! $validation['valid'] ) {
			return $this->errorResponse( $validation['error'] );
		}

		// Update state.
		$state['address']    = $address;
		$state['step']       = self::STEP_SHIPPING_METHOD;
		$state['updated_at'] = time();
		$this->saveState( $phone, $state );

		return array(
			'success' => true,
			'step'    => self::STEP_SHIPPING_METHOD,
			'data'    => $this->getStepData( $phone, self::STEP_SHIPPING_METHOD ),
			'error'   => null,
		);
	}

	/**
	 * Get available shipping methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of shipping methods.
	 */
	public function getShippingMethods( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state || empty( $state['address'] ) ) {
			return array();
		}

		// Get WooCommerce shipping zones.
		$shipping_zones = \WC_Shipping_Zones::get_zones();
		$methods        = array();

		// Build package for shipping calculation.
		$package = $this->buildShippingPackage( $phone, $state['address'] );

		// Check each zone.
		$matched_zone = null;
		foreach ( $shipping_zones as $zone_data ) {
			$zone = new \WC_Shipping_Zone( $zone_data['id'] );
			if ( $this->zoneMatchesAddress( $zone, $state['address'] ) ) {
				$matched_zone = $zone;
				break;
			}
		}

		// Fallback to "rest of world" zone.
		if ( ! $matched_zone ) {
			$matched_zone = new \WC_Shipping_Zone( 0 );
		}

		// Get methods from matched zone.
		$zone_methods = $matched_zone->get_shipping_methods( true );

		foreach ( $zone_methods as $method ) {
			if ( ! $method->is_enabled() ) {
				continue;
			}

			// Calculate rate.
			$rate = $this->calculateShippingRate( $method, $package );

			$methods[] = array(
				'id'          => $method->id . ':' . $method->instance_id,
				'label'       => $method->get_title(),
				'cost'        => $rate['cost'],
				'cost_html'   => wc_price( $rate['cost'] ),
				'description' => $method->get_method_description(),
			);
		}

		// Allow filtering.
		return apply_filters( 'wch_shipping_methods', $methods, $phone, $state['address'] );
	}

	/**
	 * Process shipping method selection.
	 *
	 * @param string $phone     Customer phone number.
	 * @param string $method_id Shipping method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processShippingMethod( string $phone, string $method_id ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return $this->errorResponse( 'No active checkout session' );
		}

		// Validate method exists.
		$methods = $this->getShippingMethods( $phone );
		$selected = null;

		foreach ( $methods as $method ) {
			if ( $method['id'] === $method_id ) {
				$selected = $method;
				break;
			}
		}

		if ( ! $selected ) {
			return $this->errorResponse( 'Invalid shipping method' );
		}

		// Update state.
		$state['shipping_method'] = $selected;
		$state['step']            = self::STEP_PAYMENT_METHOD;
		$state['updated_at']      = time();
		$this->saveState( $phone, $state );

		return array(
			'success' => true,
			'step'    => self::STEP_PAYMENT_METHOD,
			'data'    => $this->getStepData( $phone, self::STEP_PAYMENT_METHOD ),
			'error'   => null,
		);
	}

	/**
	 * Get available payment methods.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of payment methods.
	 */
	public function getPaymentMethods( string $phone ): array {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$methods  = array();

		// Get enabled WhatsApp payment methods.
		$enabled_methods = get_option( 'wch_enabled_payment_methods', array( 'cod' ) );
		if ( ! is_array( $enabled_methods ) ) {
			$enabled_methods = array( 'cod' );
		}

		foreach ( $gateways as $gateway ) {
			if ( ! in_array( $gateway->id, $enabled_methods, true ) ) {
				continue;
			}

			$methods[] = array(
				'id'          => $gateway->id,
				'label'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
				'icon'        => $gateway->get_icon(),
				'fee'         => $this->getPaymentFee( $gateway ),
			);
		}

		return apply_filters( 'wch_payment_methods', $methods, $phone );
	}

	/**
	 * Process payment method selection.
	 *
	 * @param string $phone     Customer phone number.
	 * @param string $method_id Payment method ID.
	 * @return array{success: bool, step: string, data: array, error: string|null}
	 */
	public function processPaymentMethod( string $phone, string $method_id ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return $this->errorResponse( 'No active checkout session' );
		}

		// Validate method.
		$methods  = $this->getPaymentMethods( $phone );
		$selected = null;

		foreach ( $methods as $method ) {
			if ( $method['id'] === $method_id ) {
				$selected = $method;
				break;
			}
		}

		if ( ! $selected ) {
			return $this->errorResponse( 'Invalid payment method' );
		}

		// Update state.
		$state['payment_method'] = $selected;
		$state['step']           = self::STEP_REVIEW;
		$state['updated_at']     = time();
		$this->saveState( $phone, $state );

		return array(
			'success' => true,
			'step'    => self::STEP_REVIEW,
			'data'    => $this->getStepData( $phone, self::STEP_REVIEW ),
			'error'   => null,
		);
	}

	/**
	 * Get order review data.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Order review data.
	 */
	public function getOrderReview( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return array();
		}

		// Get cart items.
		$items = array();
		if ( $this->cart_service ) {
			$cart = $this->cart_service->getCart( $phone );
			if ( $cart ) {
				foreach ( $cart->items as $item ) {
					$product = wc_get_product( $item['product_id'] );
					$items[] = array(
						'name'     => $product ? $product->get_name() : 'Unknown Product',
						'quantity' => $item['quantity'],
						'price'    => $item['price'] ?? 0,
						'total'    => ( $item['price'] ?? 0 ) * $item['quantity'],
					);
				}
			}
		}

		$totals = $this->calculateTotals( $phone );

		return array(
			'items'    => $items,
			'address'  => $state['address'] ?? array(),
			'shipping' => $state['shipping_method'] ?? array(),
			'payment'  => $state['payment_method']['label'] ?? '',
			'totals'   => $totals,
		);
	}

	/**
	 * Calculate final totals.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Totals breakdown.
	 */
	public function calculateTotals( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		$subtotal    = 0.0;
		$discount    = 0.0;
		$shipping    = 0.0;
		$tax         = 0.0;
		$payment_fee = 0.0;

		// Get cart subtotal.
		if ( $this->cart_service ) {
			$cart = $this->cart_service->getCart( $phone );
			if ( $cart ) {
				$subtotal = $cart->total;

				// Get discount if coupon applied.
				if ( ! empty( $state['coupon_code'] ) ) {
					$coupon = new \WC_Coupon( $state['coupon_code'] );
					if ( $coupon->get_id() ) {
						$discount = $coupon->get_amount();
						if ( $coupon->is_type( 'percent' ) ) {
							$discount = $subtotal * ( $discount / 100 );
						}
					}
				}
			}
		}

		// Get shipping cost.
		if ( ! empty( $state['shipping_method'] ) ) {
			$shipping = (float) $state['shipping_method']['cost'];
		}

		// Calculate tax.
		$taxable = $subtotal - $discount + $shipping;
		if ( wc_tax_enabled() ) {
			$tax_class = apply_filters( 'wch_checkout_tax_class', '' );
			$tax_rates = \WC_Tax::get_rates( $tax_class );
			if ( ! empty( $tax_rates ) ) {
				$taxes = \WC_Tax::calc_tax( $taxable, $tax_rates );
				$tax   = array_sum( $taxes );
			}
		}

		// Payment fee.
		if ( ! empty( $state['payment_method'] ) ) {
			$payment_fee = (float) ( $state['payment_method']['fee'] ?? 0 );
		}

		$total = $subtotal - $discount + $shipping + $tax + $payment_fee;

		return array(
			'subtotal'    => round( $subtotal, 2 ),
			'discount'    => round( $discount, 2 ),
			'shipping'    => round( $shipping, 2 ),
			'tax'         => round( $tax, 2 ),
			'payment_fee' => round( $payment_fee, 2 ),
			'total'       => round( max( 0, $total ), 2 ),
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
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return array(
				'success'      => false,
				'order_id'     => null,
				'order_number' => null,
				'error'        => 'No active checkout session',
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
			if ( $this->checkout_saga ) {
				$checkout_data = array(
					'shipping_address' => $state['address'],
					'shipping_method'  => $state['shipping_method']['id'] ?? '',
					'payment_method'   => $state['payment_method']['id'] ?? 'cod',
					'coupon_code'      => $state['coupon_code'],
				);

				$result = $this->checkout_saga->execute( $phone, $checkout_data );

				if ( $result->isSuccess() ) {
					$order_data = $result->getStepResult( 'create_order' );
					$this->clearState( $phone );

					return array(
						'success'      => true,
						'order_id'     => $order_data['order_id'] ?? null,
						'order_number' => $order_data['order_number'] ?? null,
						'error'        => null,
					);
				} else {
					return array(
						'success'      => false,
						'order_id'     => null,
						'order_number' => null,
						'error'        => $result->getError() ?? 'Order creation failed',
					);
				}
			}

			// Fallback: Direct order creation via OrderSyncService.
			if ( $this->order_sync_service && $this->cart_service ) {
				$cart = $this->cart_service->getCart( $phone );

				$cart_data = array(
					'items'            => $cart->items,
					'billing_address'  => $state['address'],
					'shipping_address' => $state['address'],
					'shipping_method'  => $state['shipping_method']['id'] ?? '',
					'shipping_cost'    => $state['shipping_method']['cost'] ?? 0,
					'shipping_title'   => $state['shipping_method']['label'] ?? '',
					'payment_method'   => $state['payment_method']['id'] ?? 'cod',
					'coupon_code'      => $state['coupon_code'],
				);

				$order_id = $this->order_sync_service->createOrderFromCart( $cart_data, $phone );

				// Clear cart and checkout state.
				$this->cart_service->clearCart( $phone );
				$this->clearState( $phone );

				$order = wc_get_order( $order_id );

				return array(
					'success'      => true,
					'order_id'     => $order_id,
					'order_number' => $order ? $order->get_order_number() : (string) $order_id,
					'error'        => null,
				);
			}

			throw new \RuntimeException( 'No order service available' );

		} catch ( \Exception $e ) {
			do_action( 'wch_log_error', 'CheckoutService: Order confirmation failed', array(
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
		$this->clearState( $phone );

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
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return array(
				'success' => false,
				'step'    => '',
				'data'    => array(),
			);
		}

		$current_step  = $state['step'];
		$current_order = self::STEP_ORDER[ $current_step ] ?? 1;

		// Find previous step.
		$previous_step = self::STEP_ADDRESS;
		foreach ( self::STEP_ORDER as $step => $order ) {
			if ( $order < $current_order ) {
				$previous_step = $step;
			}
		}

		// Update state.
		$state['step']       = $previous_step;
		$state['updated_at'] = time();
		$this->saveState( $phone, $state );

		return array(
			'success' => true,
			'step'    => $previous_step,
			'data'    => $this->getStepData( $phone, $previous_step ),
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
		if ( ! $this->cart_service ) {
			$issues[] = array( 'type' => 'service', 'message' => 'Cart service unavailable' );
			return array( 'valid' => false, 'issues' => $issues );
		}

		// Check cart exists and is valid.
		$cart = $this->cart_service->getCart( $phone );
		if ( ! $cart || $cart->isEmpty() ) {
			$issues[] = array( 'type' => 'cart', 'message' => 'Cart is empty' );
			return array( 'valid' => false, 'issues' => $issues );
		}

		// Check cart validity (stock, etc.).
		$cart_validation = $this->cart_service->checkCartValidity( $phone );
		if ( ! $cart_validation['is_valid'] ) {
			foreach ( $cart_validation['issues'] as $issue ) {
				$issues[] = array(
					'type'    => 'item',
					'message' => $issue['message'] ?? 'Item validation failed',
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
	 * @param string $phone       Customer phone number.
	 * @param string $coupon_code Coupon code.
	 * @return array{success: bool, discount: float, error: string|null}
	 */
	public function applyCoupon( string $phone, string $coupon_code ): array {
		$phone       = $this->sanitizePhone( $phone );
		$coupon_code = wc_sanitize_coupon_code( $coupon_code );
		$state       = $this->loadState( $phone );

		if ( ! $state ) {
			return array( 'success' => false, 'discount' => 0, 'error' => 'No active checkout' );
		}

		$coupon = new \WC_Coupon( $coupon_code );

		if ( ! $coupon->get_id() ) {
			return array( 'success' => false, 'discount' => 0, 'error' => 'Invalid coupon code' );
		}

		// Check coupon validity.
		$discounts = new \WC_Discounts();
		$valid     = $discounts->is_coupon_valid( $coupon );

		if ( is_wp_error( $valid ) ) {
			return array( 'success' => false, 'discount' => 0, 'error' => $valid->get_error_message() );
		}

		// Calculate discount.
		$subtotal = 0;
		if ( $this->cart_service ) {
			$cart     = $this->cart_service->getCart( $phone );
			$subtotal = $cart ? $cart->total : 0;
		}

		$discount = $coupon->get_amount();
		if ( $coupon->is_type( 'percent' ) ) {
			$discount = $subtotal * ( $discount / 100 );
		}

		// Update state.
		$state['coupon_code'] = $coupon_code;
		$state['updated_at']  = time();
		$this->saveState( $phone, $state );

		return array(
			'success'  => true,
			'discount' => round( $discount, 2 ),
			'error'    => null,
		);
	}

	/**
	 * Remove coupon during checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function removeCoupon( string $phone ): bool {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return false;
		}

		$state['coupon_code'] = null;
		$state['updated_at']  = time();
		$this->saveState( $phone, $state );

		return true;
	}

	/**
	 * Get checkout timeout in seconds.
	 *
	 * @return int Timeout in seconds.
	 */
	public function getCheckoutTimeout(): int {
		return (int) get_option( 'wch_checkout_timeout', self::DEFAULT_TIMEOUT );
	}

	/**
	 * Check if checkout has timed out.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if timed out.
	 */
	public function hasTimedOut( string $phone ): bool {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return false;
		}

		$timeout    = $this->getCheckoutTimeout();
		$started_at = $state['started_at'] ?? 0;

		return ( time() - $started_at ) > $timeout;
	}

	/**
	 * Extend checkout timeout.
	 *
	 * @param string $phone   Customer phone number.
	 * @param int    $seconds Additional seconds.
	 * @return bool Success status.
	 */
	public function extendTimeout( string $phone, int $seconds = 900 ): bool {
		$phone = $this->sanitizePhone( $phone );
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return false;
		}

		// Extend by adjusting started_at.
		$state['started_at'] = time();
		$state['updated_at'] = time();
		$this->saveState( $phone, $state );

		return true;
	}

	/**
	 * Save checkout state.
	 *
	 * @param string $phone Customer phone.
	 * @param array  $state State data.
	 */
	private function saveState( string $phone, array $state ): void {
		$key     = self::STATE_PREFIX . md5( $phone );
		$timeout = $this->getCheckoutTimeout() + 300; // Extra buffer.
		set_transient( $key, $state, $timeout );
	}

	/**
	 * Load checkout state.
	 *
	 * @param string $phone Customer phone.
	 * @return array|null State data or null.
	 */
	private function loadState( string $phone ): ?array {
		$key   = self::STATE_PREFIX . md5( $phone );
		$state = get_transient( $key );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Clear checkout state.
	 *
	 * @param string $phone Customer phone.
	 */
	private function clearState( string $phone ): void {
		$key = self::STATE_PREFIX . md5( $phone );
		delete_transient( $key );
	}

	/**
	 * Get step-specific data.
	 *
	 * @param string $phone Customer phone.
	 * @param string $step  Current step.
	 * @return array Step data.
	 */
	private function getStepData( string $phone, string $step ): array {
		$state = $this->loadState( $phone );

		switch ( $step ) {
			case self::STEP_ADDRESS:
				return array(
					'saved_addresses' => $this->getSavedAddresses( $phone ),
					'current_address' => $state['address'] ?? null,
				);

			case self::STEP_SHIPPING_METHOD:
				return array(
					'methods'  => $this->getShippingMethods( $phone ),
					'selected' => $state['shipping_method'] ?? null,
				);

			case self::STEP_PAYMENT_METHOD:
				return array(
					'methods'  => $this->getPaymentMethods( $phone ),
					'selected' => $state['payment_method'] ?? null,
				);

			case self::STEP_REVIEW:
				return $this->getOrderReview( $phone );

			case self::STEP_CONFIRM:
				return array(
					'totals' => $this->calculateTotals( $phone ),
				);

			default:
				return array();
		}
	}

	/**
	 * Get saved addresses for customer.
	 *
	 * @param string $phone Customer phone.
	 * @return array Saved addresses.
	 */
	private function getSavedAddresses( string $phone ): array {
		if ( ! $this->customer_repository ) {
			return array();
		}

		try {
			$customer = $this->customer_repository->findByPhone( $phone );
			if ( $customer && $customer->last_known_address ) {
				return array(
					array(
						'id'      => 'last',
						'label'   => 'Last used address',
						'address' => $customer->last_known_address,
					),
				);
			}
		} catch ( \Exception $e ) {
			// Ignore.
		}

		return array();
	}

	/**
	 * Get a saved address by ID.
	 *
	 * @param string $phone      Customer phone.
	 * @param string $address_id Address ID.
	 * @return array|null Address data or null.
	 */
	private function getSavedAddress( string $phone, string $address_id ): ?array {
		$addresses = $this->getSavedAddresses( $phone );

		foreach ( $addresses as $addr ) {
			if ( $addr['id'] === $address_id ) {
				return $addr['address'];
			}
		}

		return null;
	}

	/**
	 * Validate address data.
	 *
	 * @param array $address Address data.
	 * @return array{valid: bool, error: string|null}
	 */
	private function validateAddress( array $address ): array {
		$required = array( 'address_1', 'city', 'country' );

		foreach ( $required as $field ) {
			if ( empty( $address[ $field ] ) ) {
				return array(
					'valid' => false,
					'error' => sprintf( 'Missing required field: %s', $field ),
				);
			}
		}

		// Validate country code.
		$countries = WC()->countries->get_countries();
		if ( ! isset( $countries[ $address['country'] ] ) ) {
			return array( 'valid' => false, 'error' => 'Invalid country' );
		}

		return array( 'valid' => true, 'error' => null );
	}

	/**
	 * Build shipping package for rate calculation.
	 *
	 * @param string $phone   Customer phone.
	 * @param array  $address Shipping address.
	 * @return array Package data.
	 */
	private function buildShippingPackage( string $phone, array $address ): array {
		$contents = array();
		$total    = 0;

		if ( $this->cart_service ) {
			$cart = $this->cart_service->getCart( $phone );
			if ( $cart ) {
				foreach ( $cart->items as $index => $item ) {
					$product = wc_get_product( $item['product_id'] );
					if ( $product ) {
						$contents[ $index ] = array(
							'product_id' => $item['product_id'],
							'quantity'   => $item['quantity'],
							'data'       => $product,
							'line_total' => $item['price'] * $item['quantity'],
						);
						$total += $item['price'] * $item['quantity'];
					}
				}
			}
		}

		return array(
			'contents'        => $contents,
			'contents_cost'   => $total,
			'applied_coupons' => array(),
			'destination'     => array(
				'country'  => $address['country'] ?? '',
				'state'    => $address['state'] ?? '',
				'postcode' => $address['postcode'] ?? '',
				'city'     => $address['city'] ?? '',
				'address'  => $address['address_1'] ?? '',
			),
		);
	}

	/**
	 * Check if shipping zone matches address.
	 *
	 * @param \WC_Shipping_Zone $zone    Shipping zone.
	 * @param array             $address Address data.
	 * @return bool True if matches.
	 */
	private function zoneMatchesAddress( \WC_Shipping_Zone $zone, array $address ): bool {
		$locations = $zone->get_zone_locations();

		foreach ( $locations as $location ) {
			if ( 'country' === $location->type && $location->code === $address['country'] ) {
				return true;
			}
			if ( 'state' === $location->type ) {
				$parts = explode( ':', $location->code );
				if ( count( $parts ) === 2 &&
					$parts[0] === $address['country'] &&
					$parts[1] === $address['state'] ) {
					return true;
				}
			}
			if ( 'postcode' === $location->type && $location->code === $address['postcode'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calculate shipping rate for a method.
	 *
	 * @param \WC_Shipping_Method $method  Shipping method.
	 * @param array               $package Shipping package.
	 * @return array Rate data.
	 */
	private function calculateShippingRate( \WC_Shipping_Method $method, array $package ): array {
		$method->calculate_shipping( $package );
		$rates = $method->rates;

		if ( ! empty( $rates ) ) {
			$rate = reset( $rates );
			return array( 'cost' => (float) $rate->get_cost() );
		}

		return array( 'cost' => 0 );
	}

	/**
	 * Get payment gateway fee.
	 *
	 * @param \WC_Payment_Gateway $gateway Payment gateway.
	 * @return float Fee amount.
	 */
	private function getPaymentFee( \WC_Payment_Gateway $gateway ): float {
		// Custom fee support.
		$fee = apply_filters( 'wch_payment_gateway_fee', 0, $gateway );
		return (float) $fee;
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
