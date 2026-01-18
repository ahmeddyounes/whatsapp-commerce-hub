<?php
/**
 * Monitoring Service Provider
 *
 * Registers health check and monitoring services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Monitoring\HealthCheck;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MonitoringServiceProvider
 *
 * Provides health check and monitoring capabilities.
 */
class MonitoringServiceProvider implements ServiceProviderInterface {

	/**
	 * {@inheritdoc}
	 */
	public function register( ContainerInterface $container ): void {
		// Register health check service.
		$container->singleton(
			HealthCheck::class,
			static function ( ContainerInterface $c ): HealthCheck {
				return new HealthCheck( $c );
			}
		);

		// Convenience alias.
		$container->singleton( 'wch.health', fn( $c ) => $c->get( HealthCheck::class ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ContainerInterface $container ): void {
		// Register health check REST endpoints.
		add_action(
			'rest_api_init',
			function () use ( $container ) {
				// Full health check (authenticated).
				register_rest_route(
					'wch/v1',
					'/health',
					[
						'methods'             => 'GET',
						'callback'            => function () use ( $container ) {
							$health = $container->get( HealthCheck::class );
							return rest_ensure_response( $health->check() );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_woocommerce' );
						},
					]
				);

				// Liveness probe (public, for load balancers).
				register_rest_route(
					'wch/v1',
					'/health/live',
					[
						'methods'             => 'GET',
						'callback'            => function () use ( $container ) {
							$health = $container->get( HealthCheck::class );
							return rest_ensure_response( $health->liveness() );
						},
						'permission_callback' => '__return_true',
					]
				);

				// Readiness probe (public, for load balancers).
				register_rest_route(
					'wch/v1',
					'/health/ready',
					[
						'methods'             => 'GET',
						'callback'            => function () use ( $container ) {
							$health = $container->get( HealthCheck::class );
							$result = $health->readiness();

							if ( ! $result['ready'] ) {
								return new \WP_REST_Response( $result, 503 );
							}

							return rest_ensure_response( $result );
						},
						'permission_callback' => '__return_true',
					]
				);

				// Individual component check.
				register_rest_route(
					'wch/v1',
					'/health/(?P<component>[a-z_]+)',
					[
						'methods'             => 'GET',
						'callback'            => function ( $request ) use ( $container ) {
							$health = $container->get( HealthCheck::class );
							$result = $health->checkOne( $request['component'] );

							if ( null === $result ) {
								return new \WP_REST_Response(
									[ 'error' => 'Component not found' ],
									404
								);
							}

							return rest_ensure_response( $result );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_woocommerce' );
						},
						'args'                => [
							'component' => [
								'required'          => true,
								'sanitize_callback' => 'sanitize_key',
							],
						],
					]
				);

				// List available health checks.
				register_rest_route(
					'wch/v1',
					'/health/checks',
					[
						'methods'             => 'GET',
						'callback'            => function () use ( $container ) {
							$health = $container->get( HealthCheck::class );
							return rest_ensure_response(
								[
									'checks' => $health->getAvailableChecks(),
									'count'  => count( $health->getAvailableChecks() ),
								]
							);
						},
						'permission_callback' => '__return_true',
					]
				);
			}
		);

		// Add admin dashboard widget for health status.
		add_action(
			'wp_dashboard_setup',
			function () use ( $container ) {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					return;
				}

				wp_add_dashboard_widget(
					'wch_health_widget',
					__( 'WhatsApp Commerce Hub - System Health', 'whatsapp-commerce-hub' ),
					function () use ( $container ) {
						$health = $container->get( HealthCheck::class );
						$status = $health->check();

						$status_class = match ( $status['status'] ) {
							'healthy'   => 'success',
							'warning'   => 'warning',
							'degraded'  => 'warning',
							default     => 'error',
						};

						echo '<div class="wch-health-widget">';
						echo '<p><strong>' . esc_html__( 'Overall Status:', 'whatsapp-commerce-hub' ) . '</strong> ';
						echo '<span class="wch-status-' . esc_attr( $status_class ) . '">';
						echo esc_html( ucfirst( $status['status'] ) );
						echo '</span></p>';

						echo '<ul>';
						foreach ( $status['checks'] as $name => $check ) {
							$icon = 'healthy' === ( $check['status'] ?? '' ) ? '✓' : '✗';
							echo '<li>' . esc_html( $icon . ' ' . ucfirst( $name ) . ': ' . ( $check['status'] ?? 'unknown' ) ) . '</li>';
						}
						echo '</ul>';

						echo '<p><a href="' . esc_url( rest_url( 'wch/v1/health' ) ) . '" target="_blank">';
						echo esc_html__( 'View Full Health Report', 'whatsapp-commerce-hub' );
						echo '</a></p>';
						echo '</div>';
					}
				);
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
			HealthCheck::class,
			'wch.health',
		];
	}
}
