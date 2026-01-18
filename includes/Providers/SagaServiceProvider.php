<?php
/**
 * Saga Service Provider
 *
 * Registers saga orchestration services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Sagas\SagaOrchestrator;
use WhatsAppCommerceHub\Sagas\CheckoutSaga;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Clients\WhatsAppClientInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SagaServiceProvider
 *
 * Provides saga orchestration bindings.
 */
class SagaServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register Saga Orchestrator.
		$container->singleton(
			SagaOrchestrator::class,
			static fn( ContainerInterface $c ) => new SagaOrchestrator(
				$c->get( \wpdb::class )
			)
		);

		// Convenience alias.
		$container->singleton(
			'wch.saga',
			static fn( ContainerInterface $c ) => $c->get( SagaOrchestrator::class )
		);

		// Register Checkout Saga.
		$container->singleton(
			CheckoutSaga::class,
			static fn( ContainerInterface $c ) => new CheckoutSaga(
				$c->get( SagaOrchestrator::class ),
				$c->get( CartServiceInterface::class ),
				$c->get( WhatsAppClientInterface::class )
			)
		);

		// Convenience alias.
		$container->singleton(
			'wch.checkout.saga',
			static fn( ContainerInterface $c ) => $c->get( CheckoutSaga::class )
		);
	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// Schedule saga recovery check.
		if ( ! wp_next_scheduled( 'wch_recover_pending_sagas' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'wch_recover_pending_sagas' );
		}

		// Register custom interval.
		// phpcs:disable WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minutes is appropriate for saga cleanup.
		add_filter(
			'cron_schedules',
			function ( array $schedules ) {
				$schedules['every_five_minutes'] = [
					'interval' => 300,
					'display'  => __( 'Every 5 Minutes', 'whatsapp-commerce-hub' ),
				];
				return $schedules;
			}
		);
		// phpcs:enable WordPress.WP.CronInterval.CronSchedulesInterval

		// Handle saga recovery.
		add_action(
			'wch_recover_pending_sagas',
			function () use ( $container ) {
				try {
					$orchestrator = $container->get( SagaOrchestrator::class );
					$pending      = $orchestrator->getPendingSagas( 10 );

					foreach ( $pending as $saga_id ) {
						do_action(
							'wch_log_warning',
							sprintf(
								'Found stuck saga: %s - manual review required',
								$saga_id
							)
						);

						// Stuck sagas need manual intervention, just log them.
						do_action( 'wch_saga_stuck', $saga_id );
					}
				} catch ( \Throwable $e ) {
					do_action( 'wch_log_error', 'Failed to check pending sagas: ' . $e->getMessage() );
				}
			}
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
			\WhatsAppCommerceHub\Providers\BusinessServiceProvider::class,
			\WhatsAppCommerceHub\Providers\ApiClientServiceProvider::class,
		];
	}

	public function provides(): array {
		return [
			SagaOrchestrator::class,
			'wch.saga',
			CheckoutSaga::class,
			'wch.checkout.saga',
		];
	}
}
