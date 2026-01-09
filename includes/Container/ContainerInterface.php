<?php
/**
 * Container Interface
 *
 * PSR-11 compatible container interface for dependency injection.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ContainerInterface
 *
 * Describes the interface of a container that exposes methods to read its entries.
 */
interface ContainerInterface {

	/**
	 * Finds an entry of the container by its identifier and returns it.
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @return mixed Entry.
	 * @throws NotFoundException  No entry was found for this identifier.
	 * @throws ContainerException Error while retrieving the entry.
	 */
	public function get( string $id ): mixed;

	/**
	 * Returns true if the container can return an entry for the given identifier.
	 *
	 * @param string $id Identifier of the entry to look for.
	 * @return bool
	 */
	public function has( string $id ): bool;

	/**
	 * Register a binding in the container.
	 *
	 * @param string               $abstract The abstract type.
	 * @param callable|string|null $concrete The concrete implementation.
	 * @param bool                 $shared   Whether to share the instance (singleton).
	 * @return void
	 */
	public function bind( string $abstract, callable|string|null $concrete = null, bool $shared = false ): void;

	/**
	 * Register a shared binding (singleton).
	 *
	 * @param string               $abstract The abstract type.
	 * @param callable|string|null $concrete The concrete implementation.
	 * @return void
	 */
	public function singleton( string $abstract, callable|string|null $concrete = null ): void;

	/**
	 * Register an existing instance as shared in the container.
	 *
	 * @param string $abstract The abstract type.
	 * @param mixed  $instance The instance.
	 * @return mixed The instance.
	 */
	public function instance( string $abstract, mixed $instance ): mixed;

	/**
	 * Register a service provider.
	 *
	 * @param ServiceProviderInterface $provider The service provider.
	 * @return void
	 */
	public function register( ServiceProviderInterface $provider ): void;

	/**
	 * Create an instance with automatic dependency resolution.
	 *
	 * @param string $concrete   Class name.
	 * @param array  $parameters Override parameters.
	 * @return mixed
	 */
	public function make( string $concrete, array $parameters = array() ): mixed;
}
