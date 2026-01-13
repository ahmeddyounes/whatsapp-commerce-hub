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
use WhatsAppCommerceHub\Controllers\WebhookController;
use WhatsAppCommerceHub\Application\Services\SettingsService;
use WhatsAppCommerceHub\Queue\PriorityQueue;
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
				$rateLimiter = $c->get( RateLimiter::class );

				return new AnalyticsController( $settings, $rateLimiter );
			}
		);

		// Register Conversations Controller.
		$container->singleton(
			ConversationsController::class,
			static function ( ContainerInterface $c ) {
				$settings    = $c->has( SettingsService::class ) ? $c->get( SettingsService::class ) : null;
				$rateLimiter = $c->get( RateLimiter::class );

				return new ConversationsController( $settings, $rateLimiter );
			}
		);

		// Register Webhook Controller.
		$container->singleton(
			WebhookController::class,
			static function ( ContainerInterface $c ) {
				$settings      = $c->has( SettingsService::class ) ? $c->get( SettingsService::class ) : null;
				$rateLimiter   = $c->get( RateLimiter::class );
				$priorityQueue = $c->get( PriorityQueue::class );

				return new WebhookController( $settings, $rateLimiter, $priorityQueue );
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

		$container->singleton(
			'wch.controller.webhook',
			static fn( ContainerInterface $c ) => $c->get( WebhookController::class )
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
				$container->get( WebhookController::class )->registerRoutes();
			}
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return [
			AnalyticsController::class,
			ConversationsController::class,
			WebhookController::class,
			'wch.controller.analytics',
			'wch.controller.conversations',
			'wch.controller.webhook',
		];
	}
}
