<?php
/**
 * Controller Service Provider
 *
 * Registers REST API controllers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Controllers\AnalyticsController;
use WhatsAppCommerceHub\Controllers\ConversationsController;
use WhatsAppCommerceHub\Services\SettingsService;
use WhatsAppCommerceHub\Security\RateLimiter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ControllerServiceProvider
 *
 * Provides REST API controllers for the plugin.
 */
class ControllerServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register Analytics Controller.
		$container->singleton(
			AnalyticsController::class,
			static function ( ContainerInterface $c ) {
				$settings    = $c->has( SettingsService::class ) ? $c->get( SettingsService::class ) : null;
				$rateLimiter = $c->has( RateLimiter::class ) ? $c->get( RateLimiter::class ) : null;

				return new AnalyticsController( $settings, $rateLimiter );
			}
		);

		// Register Conversations Controller.
		$container->singleton(
			ConversationsController::class,
			static function ( ContainerInterface $c ) {
				$settings    = $c->has( SettingsService::class ) ? $c->get( SettingsService::class ) : null;
				$rateLimiter = $c->has( RateLimiter::class ) ? $c->get( RateLimiter::class ) : null;

				return new ConversationsController( $settings, $rateLimiter );
			}
		);

		// Convenience aliases.
		$container->singleton(
			'wch.controller.analytics',
			static fn( ContainerInterface $c ) => $c->get( AnalyticsController::class )
		);

		$container->singleton(
			'wch.controller.conversations',
			static fn( ContainerInterface $c ) => $c->get( ConversationsController::class )
		);
	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Register REST routes when rest_api_init fires.
		add_action(
			'rest_api_init',
			function () use ( $container ) {
				$container->get( AnalyticsController::class )->registerRoutes();
				$container->get( ConversationsController::class )->registerRoutes();
			}
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			AnalyticsController::class,
			ConversationsController::class,
			'wch.controller.analytics',
			'wch.controller.conversations',
		);
	}
}
