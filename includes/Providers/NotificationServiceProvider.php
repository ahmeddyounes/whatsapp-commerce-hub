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
				$apiClient       = $c->has( WhatsAppApiClient::class ) ? $c->get( WhatsAppApiClient::class ) : null;
				$templateManager = $c->has( TemplateManager::class ) ? $c->get( TemplateManager::class ) : null;

				return new NotificationService( $apiClient, $templateManager );
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
		// Register WooCommerce order status hooks.
		$statusTransitions = [
			'pending',
			'processing',
			'on-hold',
			'completed',
			'cancelled',
			'refunded',
			'failed',
			'shipped',
		];

		foreach ( $statusTransitions as $status ) {
			add_action(
				'woocommerce_order_status_' . $status,
				function ( $orderId ) use ( $container, $status ) {
					$this->handleOrderStatusChange( $container, $orderId, $status );
				},
				10,
				1
			);
		}

		// Hook for new orders.
		add_action(
			'woocommerce_new_order',
			function ( $orderId ) use ( $container ) {
				$this->handleNewOrder( $container, $orderId );
			},
			10,
			1
		);
	}

	/**
	 * Handle order status change.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param int                $orderId   Order ID.
	 * @param string             $status    New status.
	 * @return void
	 */
	private function handleOrderStatusChange( ContainerInterface $container, int $orderId, string $status ): void {
		if ( ! $container->has( NotificationService::class ) ) {
			return;
		}

		$notificationService = $container->get( NotificationService::class );

		if ( method_exists( $notificationService, 'sendOrderStatusNotification' ) ) {
			$notificationService->sendOrderStatusNotification( $orderId, $status );
		}
	}

	/**
	 * Handle new order.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param int                $orderId   Order ID.
	 * @return void
	 */
	private function handleNewOrder( ContainerInterface $container, int $orderId ): void {
		if ( ! $container->has( NotificationService::class ) ) {
			return;
		}

		$notificationService = $container->get( NotificationService::class );

		if ( method_exists( $notificationService, 'sendOrderConfirmation' ) ) {
			$notificationService->sendOrderConfirmation( $orderId );
		}
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return [
			NotificationService::class,
			'wch.notification',
		];
	}
}
