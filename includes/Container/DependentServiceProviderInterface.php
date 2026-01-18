<?php
/**
 * Dependent Service Provider Interface
 *
 * Optional extension to ServiceProviderInterface allowing a provider to declare
 * other providers it depends on.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface DependentServiceProviderInterface
 */
interface DependentServiceProviderInterface extends ServiceProviderInterface {

	/**
	 * Get provider class dependencies.
	 *
	 * Return an array of provider class names that must be registered/booted
	 * before this provider.
	 *
	 * @return array<class-string<ServiceProviderInterface>>
	 */
	public function dependsOn(): array;
}
