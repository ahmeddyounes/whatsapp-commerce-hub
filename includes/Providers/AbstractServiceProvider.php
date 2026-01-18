<?php
/**
 * Abstract Service Provider
 *
 * Base class for service providers with common functionality.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractServiceProvider
 *
 * Provides common functionality for service providers.
 */
abstract class AbstractServiceProvider implements ServiceProviderInterface {

	/**
	 * The DI container.
	 *
	 * @var ContainerInterface
	 */
	protected ContainerInterface $container;

	/**
	 * List of services provided by this provider.
	 *
	 * @var array<string>
	 */
	protected array $provides = [];

	/**
	 * Register services.
	 *
	 * This method receives the container and stores it for use by child classes.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		$this->container = $container;
		$this->doRegister();
	}

	/**
	 * Perform registration of services.
	 *
	 * Override this method in child classes instead of register().
	 *
	 * @return void
	 */
	protected function doRegister(): void {
		// Default: no services to register
	}

	/**
	 * Boot services.
	 *
	 * This method receives the container and stores it for use by child classes.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		$this->container = $container;
		$this->doBoot();
	}

	/**
	 * Perform boot-time initialization.
	 *
	 * Override this method in child classes instead of boot().
	 *
	 * @return void
	 */
	protected function doBoot(): void {
		// Default: no boot-time initialization
	}

	/**
	 * Determine if this provider should boot in the current context.
	 *
	 * Override this method in child classes to implement context-aware boot gating.
	 * By default, all providers boot in all contexts.
	 *
	 * @return bool True if the provider should boot, false otherwise.
	 */
	public function shouldBoot(): bool {
		// Default: boot in all contexts
		return true;
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return $this->provides;
	}

	/**
	 * Check if the current context is the WordPress admin area.
	 *
	 * @return bool True if in admin context.
	 */
	protected function isAdmin(): bool {
		return is_admin() && ! $this->isAjax() && ! $this->isCron();
	}

	/**
	 * Check if the current context is a REST API request.
	 *
	 * @return bool True if REST API request.
	 */
	protected function isRest(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Check if the current context is an AJAX request.
	 *
	 * @return bool True if AJAX request.
	 */
	protected function isAjax(): bool {
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	/**
	 * Check if the current context is a cron job.
	 *
	 * @return bool True if cron context.
	 */
	protected function isCron(): bool {
		return defined( 'DOING_CRON' ) && DOING_CRON;
	}

	/**
	 * Check if the current context is a frontend request.
	 *
	 * @return bool True if frontend context.
	 */
	protected function isFrontend(): bool {
		return ! $this->isAdmin() && ! $this->isRest() && ! $this->isCron();
	}
}
