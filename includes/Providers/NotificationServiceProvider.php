<?php
/**
 * Notification Service Provider
 *
 * Registers notification services for order lifecycle events.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Application\Services\NotificationService;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Presentation\Templates\TemplateManager;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationServiceProvider
 *
 * Provides notification services for the plugin.
 */
class NotificationServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register Notification Service.
		$container->singleton(
			NotificationService::class,
			static function ( ContainerInterface $c ) {
				$apiClient       = null;
				$templateManager = null;
				$logger          = null;

				try {
					$apiClient = $c->get( WhatsAppApiClient::class );
				} catch ( \Throwable ) {
					// Leave null if API client is not configured.
				}

				try {
					$templateManager = $c->get( TemplateManager::class );
				} catch ( \Throwable ) {
					// Leave null if templates are not available.
				}

				try {
					$logger = $c->get( LoggerInterface::class );
				} catch ( \Throwable ) {
					// Leave null if logger is not available.
				}

				return new NotificationService( $apiClient, $templateManager, $logger );
			}
		);

		// Convenience alias.
		$container->singleton(
			'wch.notification',
			static fn( ContainerInterface $c ) => $c->get( NotificationService::class )
		);

	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		$notificationService = $container->get( NotificationService::class );
		$notificationService->init();

		add_action(
			'wch_send_order_notification',
			static function ( $args ) use ( $container ) {
				$service = $container->get( NotificationService::class );
				$service->processNotificationJob( $args );
			},
			10,
			1
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	/**
	 * @return array<class-string<\WhatsAppCommerceHub\Container\ServiceProviderInterface>>
	 */
	public function dependsOn(): array {
		return [
			\WhatsAppCommerceHub\Providers\CoreServiceProvider::class,
			\WhatsAppCommerceHub\Providers\QueueServiceProvider::class,
			\WhatsAppCommerceHub\Providers\ApiClientServiceProvider::class,
		];
	}

	public function provides(): array {
		return [
			NotificationService::class,
			'wch.notification',
		];
	}
}
