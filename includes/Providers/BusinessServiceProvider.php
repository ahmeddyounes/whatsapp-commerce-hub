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
use WhatsAppCommerceHub\Contracts\Repositories\CartRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Services\CartService;
use WhatsAppCommerceHub\Services\CustomerService;

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
		);
	}
}
