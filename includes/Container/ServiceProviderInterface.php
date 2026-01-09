<?php
/**
 * Service Provider Interface
 *
 * Defines the contract for service providers that register bindings in the container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ServiceProviderInterface
 *
 * Service providers are responsible for registering bindings in the container.
 */
interface ServiceProviderInterface {

	/**
	 * Register bindings in the container.
	 *
	 * This method is called when the provider is registered with the container.
	 * Use this to bind interfaces to implementations.
	 *
	 * @param ContainerInterface $container The container instance.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void;

	/**
	 * Boot the service provider.
	 *
	 * This method is called after all providers have been registered.
	 * Use this for any initialization that depends on other services.
	 *
	 * @param ContainerInterface $container The container instance.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void;

	/**
	 * Get the services provided by this provider.
	 *
	 * This is used for deferred loading of service providers.
	 * Return an array of service identifiers this provider provides.
	 *
	 * @return array<string> Array of service identifiers.
	 */
	public function provides(): array;
}
