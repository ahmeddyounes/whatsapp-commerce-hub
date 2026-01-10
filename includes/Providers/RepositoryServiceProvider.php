<?php
/**
 * Repository Service Provider
 *
 * Registers all repository services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CartRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\ConversationRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\MessageRepositoryInterface;
use WhatsAppCommerceHub\Repositories\CartRepository;
use WhatsAppCommerceHub\Repositories\ConversationRepository;
use WhatsAppCommerceHub\Repositories\CustomerRepository;
use WhatsAppCommerceHub\Repositories\MessageRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RepositoryServiceProvider
 *
 * Provides all repository bindings.
 */
class RepositoryServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register Cart Repository.
		$container->singleton(
			CartRepositoryInterface::class,
			static fn( ContainerInterface $c ) => new CartRepository(
				$c->get( \wpdb::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			CartRepository::class,
			static fn( ContainerInterface $c ) => $c->get( CartRepositoryInterface::class )
		);

		// Register Conversation Repository.
		$container->singleton(
			ConversationRepositoryInterface::class,
			static fn( ContainerInterface $c ) => new ConversationRepository(
				$c->get( \wpdb::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			ConversationRepository::class,
			static fn( ContainerInterface $c ) => $c->get( ConversationRepositoryInterface::class )
		);

		// Register Customer Repository.
		$container->singleton(
			CustomerRepositoryInterface::class,
			static fn( ContainerInterface $c ) => new CustomerRepository(
				$c->get( \wpdb::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			CustomerRepository::class,
			static fn( ContainerInterface $c ) => $c->get( CustomerRepositoryInterface::class )
		);

		// Register Message Repository.
		$container->singleton(
			MessageRepositoryInterface::class,
			static fn( ContainerInterface $c ) => new MessageRepository(
				$c->get( \wpdb::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			MessageRepository::class,
			static fn( ContainerInterface $c ) => $c->get( MessageRepositoryInterface::class )
		);
	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Repositories don't need initialization.
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			CartRepositoryInterface::class,
			CartRepository::class,
			ConversationRepositoryInterface::class,
			ConversationRepository::class,
			CustomerRepositoryInterface::class,
			CustomerRepository::class,
			MessageRepositoryInterface::class,
			MessageRepository::class,
		);
	}
}
