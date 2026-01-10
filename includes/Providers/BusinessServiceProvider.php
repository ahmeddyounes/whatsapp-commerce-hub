<?php
/**
 * Business Service Provider
 *
 * Registers business logic services (Cart, Customer, etc.).
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\BroadcastServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CatalogSyncServiceInterface;
use WhatsAppCommerceHub\Contracts\Checkout\CheckoutOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Payments\PaymentGatewayRegistryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CartRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Domain\Cart\CartService;
use WhatsAppCommerceHub\Domain\Customer\CustomerService;
use WhatsAppCommerceHub\Services\AddressService;
use WhatsAppCommerceHub\Services\BroadcastService;
use WhatsAppCommerceHub\Services\CatalogSyncService;
use WhatsAppCommerceHub\Services\MessageBuilderFactory;
use WhatsAppCommerceHub\Payments\PaymentGatewayRegistry;
use WhatsAppCommerceHub\Checkout\CheckoutOrchestrator;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BusinessServiceProvider
 *
 * Provides business logic service bindings.
 */
class BusinessServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register Cart Service.
		$container->singleton(
			CartServiceInterface::class,
			static fn( ContainerInterface $c ) => new CartService(
				$c->get( CartRepositoryInterface::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			CartService::class,
			static fn( ContainerInterface $c ) => $c->get( CartServiceInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.cart',
			static fn( ContainerInterface $c ) => $c->get( CartServiceInterface::class )
		);

		// Register Customer Service.
		$container->singleton(
			CustomerServiceInterface::class,
			static fn( ContainerInterface $c ) => new CustomerService(
				$c->get( CustomerRepositoryInterface::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			CustomerService::class,
			static fn( ContainerInterface $c ) => $c->get( CustomerServiceInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.customer',
			static fn( ContainerInterface $c ) => $c->get( CustomerServiceInterface::class )
		);

		// Register Address Service.
		$container->singleton(
			AddressServiceInterface::class,
			static fn( ContainerInterface $c ) => new AddressService()
		);

		// Alias for convenience.
		$container->singleton(
			AddressService::class,
			static fn( ContainerInterface $c ) => $c->get( AddressServiceInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.address',
			static fn( ContainerInterface $c ) => $c->get( AddressServiceInterface::class )
		);

		// Register Message Builder Factory.
		$container->singleton(
			MessageBuilderFactory::class,
			static fn( ContainerInterface $c ) => new MessageBuilderFactory()
		);

		// Convenience alias.
		$container->singleton(
			'wch.message_builder',
			static fn( ContainerInterface $c ) => $c->get( MessageBuilderFactory::class )
		);

		// Register Checkout Orchestrator.
		$container->singleton(
			CheckoutOrchestratorInterface::class,
			static fn( ContainerInterface $c ) => new CheckoutOrchestrator(
				$c->get( CartServiceInterface::class ),
				$c->get( CustomerServiceInterface::class ),
				$c->get( MessageBuilderFactory::class ),
				$c->get( AddressServiceInterface::class ),
				$c->has( CartRepositoryInterface::class ) ? $c->get( CartRepositoryInterface::class ) : null,
				$c->has( 'wch.order_sync' ) ? $c->get( 'wch.order_sync' ) : null
			)
		);

		// Alias for convenience.
		$container->singleton(
			CheckoutOrchestrator::class,
			static fn( ContainerInterface $c ) => $c->get( CheckoutOrchestratorInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.checkout',
			static fn( ContainerInterface $c ) => $c->get( CheckoutOrchestratorInterface::class )
		);

		// Register Broadcast Service.
		$container->singleton(
			BroadcastServiceInterface::class,
			static fn( ContainerInterface $c ) => new BroadcastService(
				$c->get( \wpdb::class ),
				$c->has( \WCH_Template_Manager::class ) ? $c->get( \WCH_Template_Manager::class ) : null
			)
		);

		// Alias for convenience.
		$container->singleton(
			BroadcastService::class,
			static fn( ContainerInterface $c ) => $c->get( BroadcastServiceInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.broadcasts',
			static fn( ContainerInterface $c ) => $c->get( BroadcastServiceInterface::class )
		);

		// Register Catalog Sync Service.
		$container->singleton(
			CatalogSyncServiceInterface::class,
			static fn( ContainerInterface $c ) => new CatalogSyncService(
				$c->get( \wpdb::class ),
				$c->has( \WCH_Settings::class ) ? $c->get( \WCH_Settings::class ) : null,
				$c->has( \WCH_Queue::class ) ? $c->get( \WCH_Queue::class ) : null
			)
		);

		// Alias for convenience.
		$container->singleton(
			CatalogSyncService::class,
			static fn( ContainerInterface $c ) => $c->get( CatalogSyncServiceInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.catalog_sync',
			static fn( ContainerInterface $c ) => $c->get( CatalogSyncServiceInterface::class )
		);

		// Register Payment Gateway Registry.
		$container->singleton(
			PaymentGatewayRegistryInterface::class,
			static fn( ContainerInterface $c ) => new PaymentGatewayRegistry()
		);

		// Alias for convenience.
		$container->singleton(
			PaymentGatewayRegistry::class,
			static fn( ContainerInterface $c ) => $c->get( PaymentGatewayRegistryInterface::class )
		);

		// Convenience alias.
		$container->singleton(
			'wch.payment_gateways',
			static fn( ContainerInterface $c ) => $c->get( PaymentGatewayRegistryInterface::class )
		);
	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Schedule cart cleanup.
		if ( ! wp_next_scheduled( 'wch_cleanup_expired_carts' ) ) {
			wp_schedule_event( time(), 'hourly', 'wch_cleanup_expired_carts' );
		}

		add_action( 'wch_cleanup_expired_carts', function () use ( $container ) {
			try {
				$cart_service = $container->get( CartServiceInterface::class );
				$count = $cart_service->cleanupExpiredCarts();

				if ( $count > 0 ) {
					do_action( 'wch_log_info', sprintf(
						'Cleaned up %d expired carts',
						$count
					) );
				}
			} catch ( \Throwable $e ) {
				do_action( 'wch_log_error', 'Failed to cleanup carts: ' . $e->getMessage() );
			}
		} );
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			CartServiceInterface::class,
			CartService::class,
			'wch.cart',
			CustomerServiceInterface::class,
			CustomerService::class,
			'wch.customer',
			AddressServiceInterface::class,
			AddressService::class,
			'wch.address',
			MessageBuilderFactory::class,
			'wch.message_builder',
			CheckoutOrchestratorInterface::class,
			CheckoutOrchestrator::class,
			'wch.checkout',
			BroadcastServiceInterface::class,
			BroadcastService::class,
			'wch.broadcasts',
			CatalogSyncServiceInterface::class,
			CatalogSyncService::class,
			'wch.catalog_sync',
			PaymentGatewayRegistryInterface::class,
			PaymentGatewayRegistry::class,
			'wch.payment_gateways',
		);
	}
}
