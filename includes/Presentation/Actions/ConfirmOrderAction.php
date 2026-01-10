<?php
/**
 * Confirm Order Action
 *
 * Creates and confirms customer order.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConfirmOrderAction
 *
 * Handles order confirmation, WooCommerce order creation, and confirmation messaging.
 */
class ConfirmOrderAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'confirm_order';

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			$this->log( 'Confirming order', [ 'phone' => $phone ] );

			// Get cart.
			$cart = $this->getCart( $phone );

			if ( ! $cart ) {
				return $this->error( __( 'Your cart is empty. Please add items before placing an order.', 'whatsapp-commerce-hub' ) );
			}

			$items = is_array( $cart ) ? ( $cart['items'] ?? [] ) : ( $cart->items ?? [] );

			if ( empty( $items ) ) {
				return $this->error( __( 'Your cart is empty. Please add items before placing an order.', 'whatsapp-commerce-hub' ) );
			}

			// Validate required context data.
			$contextData = $context->getData();

			if ( empty( $contextData['shipping_address'] ) ) {
				return $this->error( __( 'Shipping address is required. Please provide your address.', 'whatsapp-commerce-hub' ) );
			}

			if ( empty( $contextData['payment_method'] ) ) {
				return $this->error( __( 'Payment method is required. Please select a payment method.', 'whatsapp-commerce-hub' ) );
			}

			// SECURITY: Re-validate coupon at checkout confirmation time.
			// Coupons may have expired or reached usage limits since they were applied to the cart.
			if ( ! empty( $contextData['coupon_code'] ) ) {
				$couponValidation = $this->validateCouponAtCheckout( $contextData['coupon_code'], $cart );
				if ( ! $couponValidation['valid'] ) {
					$this->log(
						'Coupon validation failed at checkout',
						[
							'phone'       => $phone,
							'coupon_code' => $contextData['coupon_code'],
							'error'       => $couponValidation['error'],
						],
						'warning'
					);
					return $this->error( $couponValidation['message'] );
				}
			}

			// Check for idempotency - prevent duplicate orders.
			if ( ! empty( $contextData['order_id'] ) ) {
				$this->log(
					'Order already created',
					[ 'order_id' => $contextData['order_id'] ],
					'info'
				);

				return $this->showExistingOrder( (int) $contextData['order_id'] );
			}

			// Prepare cart data array.
			$cartData = [
				'id'    => is_array( $cart ) ? ( $cart['id'] ?? null ) : ( $cart->id ?? null ),
				'items' => $items,
				'total' => is_array( $cart ) ? ( $cart['total'] ?? 0 ) : ( $cart->total ?? 0 ),
			];

			// SECURITY: Revalidate stock BEFORE claiming idempotency lock.
			// This prevents overselling due to race conditions where stock changed since cart was created.
			// Must be done before any order creation attempt to allow retries.
			$stockValidation = $this->validateStock( $cartData['items'] );
			if ( ! $stockValidation['valid'] ) {
				$this->log(
					'Stock validation failed at checkout',
					[
						'phone'  => $phone,
						'errors' => $stockValidation['errors'],
					],
					'warning'
				);
				return $this->error( $stockValidation['message'] );
			}

			// SECURITY: Atomic idempotency check to prevent double-charge on concurrent requests.
			// Uses database-level locking to ensure only one order is created per cart.
			$idempotencyKey = 'order_confirm_' . $phone . '_' . ( $cartData['id'] ?? '' );
			if ( ! $this->claimIdempotencyLock( $idempotencyKey ) ) {
				$this->log( 'Duplicate order creation blocked', [ 'key' => $idempotencyKey ], 'warning' );

				// Check if order was already created by the other request.
				$existingOrderId = $this->findOrderByCartId( $cartData['id'] );
				if ( $existingOrderId ) {
					return $this->showExistingOrder( $existingOrderId );
				}

				return $this->error( __( 'Your order is already being processed. Please wait a moment.', 'whatsapp-commerce-hub' ) );
			}

			// Create WooCommerce order.
			$orderId = $this->createWcOrder( $cartData, $contextData, $phone );

			if ( ! $orderId ) {
				$this->log( 'Failed to create WC order', [], 'error' );
				return $this->error( __( 'Failed to create order. Please try again or contact support.', 'whatsapp-commerce-hub' ) );
			}

			// Update cart status to completed.
			$this->updateCartStatus( $cartData['id'], 'completed' );

			// Track abandoned cart recovery conversion.
			$this->trackCartRecovery( $cartData, $orderId );

			// Send confirmation message.
			$message = $this->buildConfirmationMessage( $orderId );

			return ActionResult::success(
				[ $message ],
				'completed',
				[
					'order_id'       => $orderId,
					'order_created'  => true,
					'cart_completed' => true,
				]
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error confirming order', [ 'error' => $e->getMessage() ], 'error' );
			return $this->error( __( 'Sorry, we could not process your order. Please try again or contact support.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Create WooCommerce order.
	 *
	 * @param array  $cart        Cart data.
	 * @param array  $contextData Context data.
	 * @param string $phone       Customer phone.
	 * @return int|null Order ID or null on failure.
	 */
	private function createWcOrder( array $cart, array $contextData, string $phone ): ?int {
		try {
			// Get customer profile.
			$customer = $this->getCustomerProfile( $phone );

			// Create order object.
			$order = wc_create_order();

			if ( ! $order ) {
				return null;
			}

			// Add items to order.
			foreach ( $cart['items'] as $item ) {
				$productId = (int) $item['product_id'];
				$quantity  = (int) $item['quantity'];
				$variantId = ! empty( $item['variant_id'] ) ? (int) $item['variant_id'] : null;

				$product = wc_get_product( $variantId ?? $productId );

				if ( ! $product ) {
					continue;
				}

				$order->add_product( $product, $quantity );
			}

			// Set addresses.
			$address = $contextData['shipping_address'];
			$this->setOrderAddresses( $order, $address, $customer );

			// Set customer.
			if ( $customer && ! empty( $customer->wc_customer_id ) ) {
				$order->set_customer_id( $customer->wc_customer_id );
			}

			// Add order meta.
			$order->update_meta_data( '_wch_cart_id', $cart['id'] );
			$order->update_meta_data( '_wch_channel', 'whatsapp' );
			$order->update_meta_data( '_wch_customer_phone', $phone );

			// Calculate totals.
			$order->calculate_totals();

			// Save order before payment processing.
			$order->save();

			// Process payment through gateway manager.
			$paymentMethod  = $contextData['payment_method'];
			$paymentManager = \WCH_Payment_Manager::instance();

			$paymentResult = $paymentManager->process_order_payment(
				$order->get_id(),
				$paymentMethod,
				[
					'customer_phone' => $phone,
					'id'             => $phone,
				]
			);

			if ( ! $paymentResult['success'] ) {
				$order->update_status( 'cancelled', __( 'Payment processing failed.', 'whatsapp-commerce-hub' ) );
				$this->log(
					'Payment processing failed',
					[
						'order_id' => $order->get_id(),
						'error'    => $paymentResult['error']['message'] ?? 'Unknown error',
					],
					'error'
				);
				return null;
			}

			$this->log(
				'Order created with payment',
				[
					'order_id'       => $order->get_id(),
					'total'          => $order->get_total(),
					'payment_method' => $paymentMethod,
					'transaction_id' => $paymentResult['transaction_id'] ?? 'N/A',
				],
				'info'
			);

			return $order->get_id();

		} catch ( \Exception $e ) {
			$this->log( 'Exception creating order', [ 'error' => $e->getMessage() ], 'error' );
			return null;
		}
	}

	/**
	 * Set order addresses.
	 *
	 * @param \WC_Order   $order    Order object.
	 * @param array       $address  Address data.
	 * @param object|null $customer Customer profile.
	 * @return void
	 */
	private function setOrderAddresses( \WC_Order $order, array $address, ?object $customer ): void {
		$addressData = [
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'address_1'  => $address['street'] ?? '',
			'address_2'  => $address['apartment'] ?? '',
			'city'       => $address['city'] ?? '',
			'state'      => $address['state'] ?? '',
			'postcode'   => $address['postal_code'] ?? '',
			'country'    => $address['country'] ?? '',
		];

		// Add customer name if available.
		if ( $customer && ! empty( $customer->name ) ) {
			$nameParts                 = explode( ' ', $customer->name, 2 );
			$addressData['first_name'] = $nameParts[0];
			$addressData['last_name']  = $nameParts[1] ?? '';
		}

		// Set both billing and shipping address.
		$order->set_address( $addressData, 'billing' );
		$order->set_address( $addressData, 'shipping' );
	}

	/**
	 * Build order confirmation message.
	 *
	 * @param int $orderId Order ID.
	 * @return \WCH_Message_Builder
	 */
	private function buildConfirmationMessage( int $orderId ): \WCH_Message_Builder {
		$order = wc_get_order( $orderId );

		$message = $this->createMessageBuilder();

		if ( ! $order ) {
			$message->text(
				sprintf(
					__( 'Order confirmed! Your order number is: #%d', 'whatsapp-commerce-hub' ),
					$orderId
				)
			);
			return $message;
		}

		$message->header( __( 'Order Confirmed!', 'whatsapp-commerce-hub' ) );

		$body = sprintf(
			"✅ %s\n\n%s: #%s\n%s: %s\n%s: %s\n\n%s\n\n%s",
			__( 'Your order has been placed successfully!', 'whatsapp-commerce-hub' ),
			__( 'Order Number', 'whatsapp-commerce-hub' ),
			$order->get_order_number(),
			__( 'Status', 'whatsapp-commerce-hub' ),
			wc_get_order_status_name( $order->get_status() ),
			__( 'Total', 'whatsapp-commerce-hub' ),
			$this->formatPrice( (float) $order->get_total() ),
			__( "We'll send you updates on your order status.", 'whatsapp-commerce-hub' ),
			__( 'Thank you for shopping with us!', 'whatsapp-commerce-hub' )
		);

		$message->body( $body );

		// Add order tracking button.
		$message->button(
			'reply',
			[
				'id'    => 'track_order_' . $orderId,
				'title' => __( 'Track Order', 'whatsapp-commerce-hub' ),
			]
		);

		// Add new order button.
		$message->button(
			'reply',
			[
				'id'    => 'new_order',
				'title' => __( 'Shop Again', 'whatsapp-commerce-hub' ),
			]
		);

		return $message;
	}

	/**
	 * Show existing order confirmation.
	 *
	 * @param int $orderId Existing order ID.
	 * @return ActionResult
	 */
	private function showExistingOrder( int $orderId ): ActionResult {
		$message = $this->buildConfirmationMessage( $orderId );

		return ActionResult::success(
			[ $message ],
			'completed',
			[
				'order_id'      => $orderId,
				'order_created' => true,
			]
		);
	}

	/**
	 * Update cart status.
	 *
	 * @param int|string|null $cartId Cart ID.
	 * @param string          $status New status.
	 * @return void
	 */
	private function updateCartStatus( $cartId, string $status ): void {
		if ( ! $cartId ) {
			return;
		}

		if ( $this->cartService ) {
			$this->cartService->updateCartStatus( (string) $cartId, $status );
		} else {
			\WCH_Cart_Manager::instance()->update_cart( $cartId, [ 'status' => $status ] );
		}
	}

	/**
	 * Track abandoned cart recovery conversion.
	 *
	 * @param array $cart    Cart data.
	 * @param int   $orderId WooCommerce order ID.
	 * @return void
	 */
	private function trackCartRecovery( array $cart, int $orderId ): void {
		// Check if any recovery reminders were sent.
		$hadReminders = ! empty( $cart['reminder_1_sent_at'] )
			|| ! empty( $cart['reminder_2_sent_at'] )
			|| ! empty( $cart['reminder_3_sent_at'] );

		if ( ! $hadReminders ) {
			return;
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			return;
		}

		$revenue = (float) $order->get_total();

		// Mark cart as recovered.
		if ( class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
			$recovery = \WCH_Abandoned_Cart_Recovery::getInstance();
			$recovery->mark_cart_recovered( $cart['id'], $orderId, $revenue );

			$this->log(
				'Cart recovery tracked',
				[
					'cart_id'  => $cart['id'],
					'order_id' => $orderId,
					'revenue'  => $revenue,
				],
				'info'
			);
		}
	}

	/**
	 * Claim an idempotency lock to prevent duplicate order creation.
	 *
	 * Uses database-level advisory locks for atomic operation.
	 * Lock expires after 1 hour to prevent permanent locks from failed processes.
	 *
	 * @param string $key Idempotency key.
	 * @return bool True if lock was acquired, false if already locked.
	 */
	private function claimIdempotencyLock( string $key ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wch_webhook_idempotency';
		$hash  = hash( 'sha256', $key );
		$now   = current_time( 'mysql' );

		// Try to insert the idempotency record (atomic operation).
		// Uses INSERT IGNORE to avoid errors on duplicate key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (message_id, scope, processed_at, expires_at) VALUES (%s, %s, %s, DATE_ADD(%s, INTERVAL 1 HOUR))",
				$hash,
				'order_confirm',
				$now,
				$now
			)
		);

		// If 1 row was affected, we got the lock. If 0, it already existed.
		return 1 === $result;
	}

	/**
	 * Find an existing order by cart ID.
	 *
	 * @param int|string|null $cartId Cart ID.
	 * @return int|null Order ID or null if not found.
	 */
	private function findOrderByCartId( $cartId ): ?int {
		if ( ! $cartId ) {
			return null;
		}

		// Query WooCommerce orders by cart ID meta.
		$orders = wc_get_orders(
			[
				'limit'      => 1,
				'meta_key'   => '_wch_cart_id',
				'meta_value' => $cartId,
				'orderby'    => 'date',
				'order'      => 'DESC',
			]
		);

		if ( ! empty( $orders ) ) {
			return $orders[0]->get_id();
		}

		return null;
	}

	/**
	 * Validate stock availability for all cart items.
	 *
	 * Checks that each product is in stock and has sufficient quantity.
	 * This prevents overselling when stock changes between cart creation and checkout.
	 *
	 * @param array $items Cart items to validate.
	 * @return array Validation result with 'valid', 'errors', and 'message' keys.
	 */
	private function validateStock( array $items ): array {
		$errors            = [];
		$outOfStock        = [];
		$insufficientStock = [];

		foreach ( $items as $item ) {
			$productId = (int) ( $item['product_id'] ?? 0 );
			$quantity  = (int) ( $item['quantity'] ?? 1 );
			$variantId = ! empty( $item['variant_id'] ) ? (int) $item['variant_id'] : null;

			// Get the actual product to check stock.
			$product = wc_get_product( $variantId ?? $productId );

			if ( ! $product ) {
				$errors[] = sprintf(
					/* translators: %d: Product ID */
					__( 'Product #%d is no longer available.', 'whatsapp-commerce-hub' ),
					$productId
				);
				$outOfStock[] = $productId;
				continue;
			}

			$productName = $product->get_name();

			// Check if product is purchasable.
			if ( ! $product->is_purchasable() ) {
				$errors[] = sprintf(
					/* translators: %s: Product name */
					__( '"%s" is not available for purchase.', 'whatsapp-commerce-hub' ),
					$productName
				);
				$outOfStock[] = $productId;
				continue;
			}

			// Check if product manages stock.
			if ( $product->managing_stock() ) {
				$stockQuantity = $product->get_stock_quantity();

				// Check if out of stock.
				if ( $stockQuantity <= 0 ) {
					$errors[] = sprintf(
						/* translators: %s: Product name */
						__( '"%s" is out of stock.', 'whatsapp-commerce-hub' ),
						$productName
					);
					$outOfStock[] = $productId;
					continue;
				}

				// Check if sufficient quantity.
				if ( $stockQuantity < $quantity ) {
					$errors[] = sprintf(
						/* translators: 1: Product name, 2: Available quantity, 3: Requested quantity */
						__( '"%1$s" only has %2$d available (you requested %3$d).', 'whatsapp-commerce-hub' ),
						$productName,
						$stockQuantity,
						$quantity
					);
					$insufficientStock[] = [
						'product_id' => $productId,
						'available'  => $stockQuantity,
						'requested'  => $quantity,
					];
					continue;
				}
			} else {
				// Product doesn't manage stock - check stock status.
				$stockStatus = $product->get_stock_status();

				if ( 'outofstock' === $stockStatus ) {
					$errors[] = sprintf(
						/* translators: %s: Product name */
						__( '"%s" is out of stock.', 'whatsapp-commerce-hub' ),
						$productName
					);
					$outOfStock[] = $productId;
					continue;
				}
			}

			// Check for backorders status.
			if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
				$errors[] = sprintf(
					/* translators: %s: Product name */
					__( '"%s" is currently not available.', 'whatsapp-commerce-hub' ),
					$productName
				);
				$outOfStock[] = $productId;
			}
		}

		// Build user-friendly message.
		$message = '';
		if ( ! empty( $errors ) ) {
			if ( count( $errors ) === 1 ) {
				$message = $errors[0];
			} else {
				$message  = __( 'Some items in your cart are no longer available:', 'whatsapp-commerce-hub' ) . "\n\n";
				$message .= implode(
					"\n",
					array_map(
						function ( $e ) {
							return '• ' . $e;
						},
						$errors
					)
				);
			}
			$message .= "\n\n" . __( 'Please update your cart and try again.', 'whatsapp-commerce-hub' );
		}

		return [
			'valid'              => empty( $errors ),
			'errors'             => $errors,
			'out_of_stock'       => $outOfStock,
			'insufficient_stock' => $insufficientStock,
			'message'            => $message,
		];
	}

	/**
	 * Validate coupon at checkout confirmation time.
	 *
	 * Re-validates that the coupon is still valid (not expired, not over usage limit)
	 * before creating the order. Coupons may have changed since they were applied.
	 *
	 * @param string       $couponCode Coupon code to validate.
	 * @param array|object $cart       Cart data.
	 * @return array Validation result with 'valid', 'error', and 'message' keys.
	 */
	private function validateCouponAtCheckout( string $couponCode, $cart ): array {
		$coupon = new \WC_Coupon( $couponCode );

		// Check if coupon exists.
		if ( ! $coupon->get_id() ) {
			return [
				'valid'   => false,
				'error'   => 'coupon_not_found',
				'message' => sprintf(
					/* translators: %s: Coupon code */
					__( 'The coupon "%s" is no longer valid. Please remove it and try again.', 'whatsapp-commerce-hub' ),
					$couponCode
				),
			];
		}

		// Check if coupon has expired.
		$expiryDate = $coupon->get_date_expires();
		if ( $expiryDate && $expiryDate->getTimestamp() < time() ) {
			return [
				'valid'   => false,
				'error'   => 'coupon_expired',
				'message' => sprintf(
					/* translators: %s: Coupon code */
					__( 'The coupon "%s" has expired. Please remove it and try again.', 'whatsapp-commerce-hub' ),
					$couponCode
				),
			];
		}

		// Check global usage limit.
		$usageLimit = $coupon->get_usage_limit();
		if ( $usageLimit > 0 && $coupon->get_usage_count() >= $usageLimit ) {
			return [
				'valid'   => false,
				'error'   => 'coupon_usage_limit',
				'message' => sprintf(
					/* translators: %s: Coupon code */
					__( 'The coupon "%s" has reached its usage limit. Please remove it and try again.', 'whatsapp-commerce-hub' ),
					$couponCode
				),
			];
		}

		// Check minimum spend.
		$minimumAmount = $coupon->get_minimum_amount();
		$cartTotal     = is_array( $cart ) ? (float) ( $cart['total'] ?? 0 ) : (float) ( $cart->total ?? 0 );
		if ( $minimumAmount > 0 && $cartTotal < $minimumAmount ) {
			return [
				'valid'   => false,
				'error'   => 'coupon_minimum_spend',
				'message' => sprintf(
					/* translators: 1: Coupon code, 2: Minimum amount */
					__( 'The coupon "%1$s" requires a minimum spend of %2$s.', 'whatsapp-commerce-hub' ),
					$couponCode,
					$this->formatPrice( $minimumAmount )
				),
			];
		}

		// Check maximum spend.
		$maximumAmount = $coupon->get_maximum_amount();
		if ( $maximumAmount > 0 && $cartTotal > $maximumAmount ) {
			return [
				'valid'   => false,
				'error'   => 'coupon_maximum_spend',
				'message' => sprintf(
					/* translators: 1: Coupon code, 2: Maximum amount */
					__( 'The coupon "%1$s" cannot be used for orders over %2$s.', 'whatsapp-commerce-hub' ),
					$couponCode,
					$this->formatPrice( $maximumAmount )
				),
			];
		}

		return [
			'valid'   => true,
			'error'   => null,
			'message' => '',
		];
	}
}
