<?php
/**
 * Checkout Orchestrator
 *
 * Coordinates the multi-step checkout flow.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Checkout;

use WhatsAppCommerceHub\Contracts\Checkout\CheckoutOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Checkout\StepInterface;
use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Application\Services\MessageBuilderFactory;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;
use WhatsAppCommerceHub\Checkout\Steps\AddressStep;
use WhatsAppCommerceHub\Checkout\Steps\ShippingStep;
use WhatsAppCommerceHub\Checkout\Steps\PaymentStep;
use WhatsAppCommerceHub\Checkout\Steps\ReviewStep;
use WhatsAppCommerceHub\Checkout\Steps\ConfirmationStep;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutOrchestrator
 *
 * Manages the checkout flow by coordinating step handlers.
 */
class CheckoutOrchestrator implements CheckoutOrchestratorInterface {

	/**
	 * Step handlers indexed by step ID.
	 *
	 * @var array<string, StepInterface>
	 */
	private array $steps = [];

	/**
	 * Constructor.
	 *
	 * @param CartServiceInterface     $cart_service       Cart service.
	 * @param CustomerServiceInterface $customer_service   Customer service.
	 * @param MessageBuilderFactory    $message_builder    Message builder factory.
	 * @param AddressServiceInterface  $address_service    Address service.
	 * @param mixed                    $cart_repository    Cart repository (optional).
	 * @param mixed                    $order_sync_service Order sync service (optional).
	 * @param LoggerInterface|null     $logger             Logger instance (optional).
	 */
	public function __construct(
		private CartServiceInterface $cart_service,
		private CustomerServiceInterface $customer_service,
		private MessageBuilderFactory $message_builder,
		private AddressServiceInterface $address_service,
		private mixed $cart_repository = null,
		private mixed $order_sync_service = null,
		private ?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? wch( LoggerInterface::class );
		$this->initializeSteps();
	}

	/**
	 * Initialize step handlers.
	 *
	 * @return void
	 */
	private function initializeSteps(): void {
		// Create step handlers.
		$address_step      = new AddressStep( $this->message_builder, $this->address_service );
		$shipping_step     = new ShippingStep( $this->message_builder, $this->address_service );
		$payment_step      = new PaymentStep( $this->message_builder, $this->address_service );
		$review_step       = new ReviewStep( $this->message_builder, $this->address_service );
		$confirmation_step = new ConfirmationStep(
			$this->message_builder,
			$this->address_service,
			$this->order_sync_service
		);

		// Register steps.
		$this->steps = [
			'address'  => $address_step,
			'shipping' => $shipping_step,
			'payment'  => $payment_step,
			'review'   => $review_step,
			'confirm'  => $confirmation_step,
		];
	}

	/**
	 * Start a new checkout process.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return CheckoutResponse
	 */
	public function startCheckout( string $customer_phone ): CheckoutResponse {
		try {
			$this->log( 'Starting checkout', [ 'phone' => $customer_phone ] );

			// Get and validate cart.
			$cart = $this->cart_service->getCart( $customer_phone );

			if ( $cart->isEmpty() ) {
				return CheckoutResponse::failure(
					'address',
					__( 'Cart is empty', 'whatsapp-commerce-hub' ),
					'empty_cart',
					[
						$this->message_builder->text(
							__( 'Your cart is empty. Please add items before checkout.', 'whatsapp-commerce-hub' )
						),
					]
				);
			}

			// Validate cart items.
			$validation = $this->cart_service->checkCartValidity( $customer_phone );

			if ( ! $validation['is_valid'] ) {
				$issues_text = '⚠️ ' . __( "There are issues with your cart:\n\n", 'whatsapp-commerce-hub' );
				foreach ( $validation['issues'] as $issue ) {
					$issues_text .= '• ' . $issue['message'] . "\n";
				}
				$issues_text .= "\n" . __( 'Please review your cart and try again.', 'whatsapp-commerce-hub' );

				return CheckoutResponse::failure(
					'address',
					__( 'Cart validation failed', 'whatsapp-commerce-hub' ),
					'cart_validation_failed',
					[ $this->message_builder->text( $issues_text ) ]
				);
			}

			// Build initial context.
			$context = $this->buildContext( $customer_phone, [] );

			// Execute first step.
			$first_step = $this->getStep( 'address' );
			if ( ! $first_step ) {
				return CheckoutResponse::failure(
					'address',
					__( 'Checkout configuration error', 'whatsapp-commerce-hub' ),
					'step_not_found'
				);
			}

			return $first_step->execute( $context );

		} catch ( \Throwable $e ) {
			$this->logError(
				'Error starting checkout',
				[
					'phone' => $customer_phone,
					'error' => $e->getMessage(),
				]
			);

			return CheckoutResponse::failure(
				'address',
				$e->getMessage(),
				'checkout_start_failed',
				[
					$this->message_builder->text(
						__( 'Sorry, we encountered an error starting checkout. Please try again.', 'whatsapp-commerce-hub' )
					),
				]
			);
		}
	}

	/**
	 * Process user input for the current checkout step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $input          User's input/selection.
	 * @param string $current_step   Current step identifier.
	 * @param array  $state_data     Current checkout state data.
	 * @return CheckoutResponse
	 */
	public function processInput( string $customer_phone, string $input, string $current_step, array $state_data ): CheckoutResponse {
		try {
			$this->log(
				'Processing input',
				[
					'phone'        => $customer_phone,
					'step'         => $current_step,
					'input_length' => strlen( $input ),
				]
			);

			$step = $this->getStep( $current_step );

			if ( ! $step ) {
				return CheckoutResponse::failure(
					$current_step,
					__( 'Invalid checkout step', 'whatsapp-commerce-hub' ),
					'invalid_step',
					[
						$this->message_builder->text(
							__( 'Something went wrong. Please start checkout again.', 'whatsapp-commerce-hub' )
						),
					]
				);
			}

			// Build context.
			$context = $this->buildContext( $customer_phone, $state_data );

			// Process input.
			$response = $step->processInput( $input, $context );

			// If successful and there's step data, merge it into state.
			if ( $response->success && ! empty( $response->step_data ) ) {
				$state_data['checkout_data'] = array_merge(
					$state_data['checkout_data'] ?? [],
					$response->step_data
				);

				// Update cart with new data if needed.
				$this->updateCartWithCheckoutData( $customer_phone, $response->step_data );
			}

			// If there's a next step, execute it.
			if ( $response->success && $response->next_step && $response->next_step !== $current_step ) {
				return $this->goToStep( $customer_phone, $response->next_step, $state_data );
			}

			return $response;

		} catch ( \Throwable $e ) {
			$this->logError(
				'Error processing input',
				[
					'phone' => $customer_phone,
					'step'  => $current_step,
					'error' => $e->getMessage(),
				]
			);

			return CheckoutResponse::failure(
				$current_step,
				$e->getMessage(),
				'input_processing_failed',
				[
					$this->message_builder->text(
						__( 'Sorry, an error occurred. Please try again.', 'whatsapp-commerce-hub' )
					),
				]
			);
		}
	}

	/**
	 * Get the current checkout step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return string|null
	 */
	public function getCurrentStep( string $customer_phone ): ?string {
		// This would typically be stored in conversation state.
		// Return null if not in checkout.
		return null;
	}

	/**
	 * Navigate to a specific step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $step_id        Target step identifier.
	 * @param array  $state_data     Current checkout state data.
	 * @return CheckoutResponse
	 */
	public function goToStep( string $customer_phone, string $step_id, array $state_data ): CheckoutResponse {
		try {
			$step = $this->getStep( $step_id );

			if ( ! $step ) {
				return CheckoutResponse::failure(
					$step_id,
					__( 'Step not found', 'whatsapp-commerce-hub' ),
					'step_not_found'
				);
			}

			// Check if step can be skipped.
			$context = $this->buildContext( $customer_phone, $state_data );

			if ( $step->canSkip( $context ) ) {
				$next_step = $step->getNextStep();
				if ( $next_step ) {
					return $this->goToStep( $customer_phone, $next_step, $state_data );
				}
			}

			return $step->execute( $context );

		} catch ( \Throwable $e ) {
			$this->logError(
				'Error going to step',
				[
					'phone' => $customer_phone,
					'step'  => $step_id,
					'error' => $e->getMessage(),
				]
			);

			return CheckoutResponse::failure(
				$step_id,
				$e->getMessage(),
				'navigation_failed'
			);
		}
	}

	/**
	 * Go back to the previous step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $current_step   Current step identifier.
	 * @param array  $state_data     Current checkout state data.
	 * @return CheckoutResponse
	 */
	public function goBack( string $customer_phone, string $current_step, array $state_data ): CheckoutResponse {
		$step = $this->getStep( $current_step );

		if ( ! $step ) {
			return CheckoutResponse::failure(
				$current_step,
				__( 'Current step not found', 'whatsapp-commerce-hub' ),
				'step_not_found'
			);
		}

		$previous_step = $step->getPreviousStep();

		if ( ! $previous_step ) {
			return CheckoutResponse::failure(
				$current_step,
				__( 'No previous step', 'whatsapp-commerce-hub' ),
				'no_previous_step',
				[
					$this->message_builder->text(
						__( 'You are at the first step. Type "cancel" to exit checkout.', 'whatsapp-commerce-hub' )
					),
				]
			);
		}

		return $this->goToStep( $customer_phone, $previous_step, $state_data );
	}

	/**
	 * Cancel the checkout process.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return CheckoutResponse
	 */
	public function cancelCheckout( string $customer_phone ): CheckoutResponse {
		$this->log( 'Checkout cancelled', [ 'phone' => $customer_phone ] );

		return CheckoutResponse::failure(
			'cancelled',
			__( 'Checkout cancelled', 'whatsapp-commerce-hub' ),
			'checkout_cancelled',
			[
				$this->message_builder->text(
					__(
						"Checkout cancelled. Your cart items are still saved.\n\n" .
						"Reply 'cart' to view your cart or 'menu' to browse products.",
						'whatsapp-commerce-hub'
					)
				),
			]
		);
	}

	/**
	 * Get all available steps.
	 *
	 * @return array<string, StepInterface>
	 */
	public function getSteps(): array {
		return $this->steps;
	}

	/**
	 * Get a specific step handler.
	 *
	 * @param string $step_id Step identifier.
	 * @return StepInterface|null
	 */
	public function getStep( string $step_id ): ?StepInterface {
		return $this->steps[ $step_id ] ?? null;
	}

	/**
	 * Build context array for step execution.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param array  $state_data     Current state data.
	 * @return array
	 */
	private function buildContext( string $customer_phone, array $state_data ): array {
		$cart     = $this->cart_service->getCart( $customer_phone );
		$customer = $this->customer_service->getOrCreateProfile( $customer_phone );

		return [
			'customer_phone' => $customer_phone,
			'cart'           => $cart->toArray(),
			'customer'       => $customer,
			'checkout_data'  => $state_data['checkout_data'] ?? [],
			'state_data'     => $state_data,
		];
	}

	/**
	 * Update cart with checkout data.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param array  $step_data      Step data to save.
	 * @return void
	 */
	private function updateCartWithCheckoutData( string $customer_phone, array $step_data ): void {
		try {
			if ( ! $this->cart_repository ) {
				return;
			}

			// Get cart.
			$cart = $this->cart_service->getCart( $customer_phone );

			// Update shipping address if present.
			if ( isset( $step_data['shipping_address'] ) ) {
				$this->cart_service->setShippingAddress( $customer_phone, $step_data['shipping_address'] );
			}
		} catch ( \Throwable $e ) {
			$this->logError( 'Error updating cart with checkout data', [ 'error' => $e->getMessage() ] );
		}
	}

	/**
	 * Log message.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Additional data.
	 * @return void
	 */
	private function log( string $message, array $data = [] ): void {
		$this->logger->info( $message, 'checkout', $data );
	}

	/**
	 * Log error.
	 *
	 * @param string $message Error message.
	 * @param array  $data    Additional data.
	 * @return void
	 */
	private function logError( string $message, array $data = [] ): void {
		$this->logger->error( $message, 'checkout', $data );
	}
}
