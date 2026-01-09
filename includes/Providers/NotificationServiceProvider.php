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
use WhatsAppCommerceHub\Services\NotificationService;

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
				$apiClient       = $c->has( \WCH_WhatsApp_API_Client::class ) ? $c->get( \WCH_WhatsApp_API_Client::class ) : null;
				$templateManager = $c->has( \WCH_Template_Manager::class ) ? $c->get( \WCH_Template_Manager::class ) : null;

				return new NotificationService( $apiClient, $templateManager );
			}
		);

		// Convenience alias.
		$container->singleton(
			'wch.notification',
			static fn( ContainerInterface $c ) => $c->get( NotificationService::class )
		);

		// Register legacy notification handler for backward compatibility.
		$container->singleton(
			\WCH_Order_Notifications::class,
			static function ( ContainerInterface $c ) {
				if ( class_exists( 'WCH_Order_Notifications' ) ) {
					return \WCH_Order_Notifications::getInstance();
				}
				return null;
			}
		);

		// Convenience alias for legacy handler.
		$container->singleton(
			'wch.order_notifications',
			static fn( ContainerInterface $c ) => $c->get( \WCH_Order_Notifications::class )
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
		$statusTransitions = array(
			'pending',
			'processing',
			'on-hold',
			'completed',
			'cancelled',
			'refunded',
			'failed',
			'shipped',
		);

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
		return array(
			NotificationService::class,
			'wch.notification',
			\WCH_Order_Notifications::class,
			'wch.order_notifications',
		);
	}
}
