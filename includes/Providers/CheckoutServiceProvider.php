<?php
/**
 * Checkout Service Provider
 *
 * Registers checkout services with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\OrderSyncServiceInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutStateManagerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\AddressHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\ShippingCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\PaymentHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutTotalsCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CouponHandlerInterface;
use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutOrchestratorInterface;
use WhatsAppCommerceHub\Application\Services\Checkout\CheckoutStateManager;
use WhatsAppCommerceHub\Application\Services\Checkout\AddressHandler;
use WhatsAppCommerceHub\Application\Services\Checkout\ShippingCalculator;
use WhatsAppCommerceHub\Application\Services\Checkout\PaymentHandler;
use WhatsAppCommerceHub\Application\Services\Checkout\CheckoutTotalsCalculator;
use WhatsAppCommerceHub\Application\Services\Checkout\CouponHandler;
use WhatsAppCommerceHub\Application\Services\Checkout\CheckoutOrchestrator;
use WhatsAppCommerceHub\Sagas\CheckoutSaga;
use WhatsAppCommerceHub\Core\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutServiceProvider
 *
 * Registers and configures checkout services.
 */
class CheckoutServiceProvider extends AbstractServiceProvider {

	/**
	 * Register services with the container.
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Register State Manager (no dependencies on other checkout services).
		$this->container->singleton(
			CheckoutStateManagerInterface::class,
			function () {
				return new CheckoutStateManager();
			}
		);

		// Register Address Handler.
		$this->container->singleton(
			AddressHandlerInterface::class,
			function ( $container ) {
				$customerRepository = $container->has( CustomerRepositoryInterface::class )
					? $container->get( CustomerRepositoryInterface::class )
					: null;

				return new AddressHandler( $customerRepository );
			}
		);

		// Register Shipping Calculator.
		$this->container->singleton(
			ShippingCalculatorInterface::class,
			function () {
				return new ShippingCalculator();
			}
		);

		// Register Payment Handler.
		$this->container->singleton(
			PaymentHandlerInterface::class,
			function () {
				return new PaymentHandler();
			}
		);

		// Register Totals Calculator.
		$this->container->singleton(
			CheckoutTotalsCalculatorInterface::class,
			function () {
				return new CheckoutTotalsCalculator();
			}
		);

		// Register Coupon Handler.
		$this->container->singleton(
			CouponHandlerInterface::class,
			function () {
				return new CouponHandler();
			}
		);

		// Register Checkout Orchestrator.
		$this->container->singleton(
			CheckoutOrchestratorInterface::class,
			function ( $container ) {
				$cartService = $container->has( CartServiceInterface::class )
					? $container->get( CartServiceInterface::class )
					: null;

				$orderSyncService = $container->has( OrderSyncServiceInterface::class )
					? $container->get( OrderSyncServiceInterface::class )
					: null;

				$checkoutSaga = $container->has( CheckoutSaga::class )
					? $container->get( CheckoutSaga::class )
					: null;

				return new CheckoutOrchestrator(
					$container->get( CheckoutStateManagerInterface::class ),
					$container->get( AddressHandlerInterface::class ),
					$container->get( ShippingCalculatorInterface::class ),
					$container->get( PaymentHandlerInterface::class ),
					$container->get( CheckoutTotalsCalculatorInterface::class ),
					$container->get( CouponHandlerInterface::class ),
					$cartService,
					$orderSyncService,
					$checkoutSaga
				);
			}
		);

	}

	/**
	 * Bootstrap services after all providers are registered.
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Register checkout-related hooks.
		add_action( 'wch_checkout_started', [ $this, 'onCheckoutStarted' ] );
		add_action( 'wch_checkout_cancelled', [ $this, 'onCheckoutCancelled' ] );
	}

	/**
	 * Get services provided by this provider.
	 *
	 * @return array
	 */
	public function provides(): array {
		return [
			CheckoutStateManagerInterface::class,
			AddressHandlerInterface::class,
			ShippingCalculatorInterface::class,
			PaymentHandlerInterface::class,
			CheckoutTotalsCalculatorInterface::class,
			CouponHandlerInterface::class,
			CheckoutOrchestratorInterface::class,
		];
	}

	/**
	 * Handle checkout started event.
	 *
	 * @param string $phone Customer phone number.
	 * @return void
	 */
	public function onCheckoutStarted( string $phone ): void {
		Logger::instance()->info(
			'Checkout started',
			'checkout',
			[ 'phone' => $phone ]
		);
	}

	/**
	 * Handle checkout cancelled event.
	 *
	 * @param string $phone Customer phone number.
	 * @return void
	 */
	public function onCheckoutCancelled( string $phone ): void {
		Logger::instance()->info(
			'Checkout cancelled',
			'checkout',
			[ 'phone' => $phone ]
		);
	}
}
