<?php
/**
 * Payment Service Provider
 *
 * Registers payment gateways and services with the DI container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Payments\Contracts\PaymentGatewayInterface;
use WhatsAppCommerceHub\Payments\Gateways\CodGateway;
use WhatsAppCommerceHub\Payments\Gateways\StripeGateway;
use WhatsAppCommerceHub\Payments\Gateways\RazorpayGateway;
use WhatsAppCommerceHub\Payments\Gateways\PixGateway;
use WhatsAppCommerceHub\Payments\Gateways\WhatsAppPayGateway;
use WhatsAppCommerceHub\Controllers\PaymentWebhookController;
use WhatsAppCommerceHub\Services\RefundService;
use WhatsAppCommerceHub\Services\NotificationService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentServiceProvider
 *
 * Registers and configures payment-related services.
 */
class PaymentServiceProvider implements ServiceProviderInterface {

	/**
	 * Payment gateway classes.
	 *
	 * @var array<string, class-string<PaymentGatewayInterface>>
	 */
	private array $gatewayClasses = array(
		'cod'         => CodGateway::class,
		'stripe'      => StripeGateway::class,
		'razorpay'    => RazorpayGateway::class,
		'pix'         => PixGateway::class,
		'whatsapppay' => WhatsAppPayGateway::class,
	);

	/**
	 * Register services with the container.
	 *
	 * @param ContainerInterface $container DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		$this->registerGateways( $container );
		$this->registerServices( $container );
		$this->registerController( $container );
	}

	/**
	 * Register payment gateways.
	 *
	 * @param ContainerInterface $container DI container.
	 * @return void
	 */
	private function registerGateways( ContainerInterface $container ): void {
		// Register individual gateways.
		foreach ( $this->gatewayClasses as $id => $class ) {
			$container->singleton(
				$class,
				static fn() => new $class()
			);

			// Register alias for easy access.
			$container->singleton(
				"payment.gateway.{$id}",
				static fn( ContainerInterface $c ) => $c->get( $class )
			);
		}

		// Register gateway collection.
		$container->singleton(
			'payment.gateways',
			function ( ContainerInterface $c ) {
				$gateways = array();
				foreach ( $this->gatewayClasses as $id => $class ) {
					$gateways[ $id ] = $c->get( $class );
				}
				return $gateways;
			}
		);

		// Register interface alias for default gateway.
		$container->singleton(
			PaymentGatewayInterface::class,
			static function ( ContainerInterface $c ) {
				$defaultGateway = get_option( 'wch_default_payment_gateway', 'stripe' );
				$gateways       = $c->get( 'payment.gateways' );
				return $gateways[ $defaultGateway ] ?? $gateways['stripe'] ?? reset( $gateways );
			}
		);
	}

	/**
	 * Register payment services.
	 *
	 * @param ContainerInterface $container DI container.
	 * @return void
	 */
	private function registerServices( ContainerInterface $container ): void {
		// Register RefundService.
		$container->singleton(
			RefundService::class,
			static function ( ContainerInterface $c ) {
				$apiClient = null;
				if ( class_exists( 'WCH_WhatsApp_API_Client' ) ) {
					$apiClient = new \WCH_WhatsApp_API_Client();
				}
				return new RefundService( $apiClient );
			}
		);

		// Alias for RefundService.
		$container->singleton(
			'payment.refund',
			static fn( ContainerInterface $c ) => $c->get( RefundService::class )
		);

		// Register NotificationService.
		$container->singleton(
			NotificationService::class,
			static function ( ContainerInterface $c ) {
				$apiClient       = null;
				$templateManager = null;

				if ( class_exists( 'WCH_WhatsApp_API_Client' ) ) {
					$apiClient = new \WCH_WhatsApp_API_Client();
				}

				if ( class_exists( 'WCH_Template_Manager' ) ) {
					$templateManager = \WCH_Template_Manager::getInstance();
				}

				return new NotificationService( $apiClient, $templateManager );
			}
		);

		// Alias for NotificationService.
		$container->singleton(
			'payment.notifications',
			static fn( ContainerInterface $c ) => $c->get( NotificationService::class )
		);
	}

	/**
	 * Register payment webhook controller.
	 *
	 * @param ContainerInterface $container DI container.
	 * @return void
	 */
	private function registerController( ContainerInterface $container ): void {
		$container->singleton(
			PaymentWebhookController::class,
			static function ( ContainerInterface $c ) {
				$gateways   = $c->get( 'payment.gateways' );
				$controller = new PaymentWebhookController( $gateways );
				return $controller;
			}
		);

		// Alias for controller.
		$container->singleton(
			'payment.webhook.controller',
			static fn( ContainerInterface $c ) => $c->get( PaymentWebhookController::class )
		);
	}

	/**
	 * Boot services after all providers are registered.
	 *
	 * @param ContainerInterface $container DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Initialize RefundService hooks.
		$refundService = $container->get( RefundService::class );
		$refundService->init();

		// Initialize NotificationService hooks.
		$notificationService = $container->get( NotificationService::class );
		$notificationService->init();

		// Register REST routes.
		add_action(
			'rest_api_init',
			static function () use ( $container ) {
				$controller = $container->get( PaymentWebhookController::class );
				$controller->registerRoutes();
			}
		);

		// Register notification job processor.
		add_action(
			'wch_send_order_notification',
			static function ( $args ) use ( $container ) {
				$notificationService = $container->get( NotificationService::class );
				$notificationService->processNotificationJob( $args );
			}
		);

		// Allow external gateways to register.
		do_action( 'wch_register_payment_gateways', $container );

		// Log registration.
		$this->logRegistration( $container );
	}

	/**
	 * Get services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		$provides = array(
			PaymentGatewayInterface::class,
			PaymentWebhookController::class,
			RefundService::class,
			NotificationService::class,
			'payment.gateways',
			'payment.refund',
			'payment.notifications',
			'payment.webhook.controller',
		);

		// Add gateway classes and aliases.
		foreach ( $this->gatewayClasses as $id => $class ) {
			$provides[] = $class;
			$provides[] = "payment.gateway.{$id}";
		}

		return $provides;
	}

	/**
	 * Add custom payment gateway.
	 *
	 * @param string $id    Gateway ID.
	 * @param string $class Gateway class name.
	 * @return void
	 */
	public function addGateway( string $id, string $class ): void {
		if ( ! isset( $this->gatewayClasses[ $id ] ) ) {
			$this->gatewayClasses[ $id ] = $class;
		}
	}

	/**
	 * Get available gateway IDs.
	 *
	 * @return array<string>
	 */
	public function getAvailableGateways(): array {
		return array_keys( $this->gatewayClasses );
	}

	/**
	 * Log payment service registration.
	 *
	 * @param ContainerInterface $container DI container.
	 * @return void
	 */
	private function logRegistration( ContainerInterface $container ): void {
		if ( ! class_exists( 'WCH_Logger' ) ) {
			return;
		}

		$gateways    = $container->get( 'payment.gateways' );
		$gatewayIds  = array_keys( $gateways );
		$configuredGateways = array();

		foreach ( $gateways as $id => $gateway ) {
			if ( $gateway->isConfigured() ) {
				$configuredGateways[] = $id;
			}
		}

		\WCH_Logger::log(
			'Payment services registered',
			array(
				'total_gateways'      => count( $gatewayIds ),
				'available_gateways'  => $gatewayIds,
				'configured_gateways' => $configuredGateways,
			),
			'debug'
		);
	}
}
