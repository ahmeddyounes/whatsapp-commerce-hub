<?php
/**
 * Resilience Service Provider
 *
 * Registers circuit breaker and resilience services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;
use WhatsAppCommerceHub\Resilience\CircuitBreakerRegistry;
use WhatsAppCommerceHub\Resilience\FallbackStrategy;
use WhatsAppCommerceHub\Resilience\RetryPolicy;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ResilienceServiceProvider
 *
 * Provides circuit breaker and resilience patterns.
 */
class ResilienceServiceProvider implements ServiceProviderInterface {

	/**
	 * {@inheritdoc}
	 */
	public function register( ContainerInterface $container ): void {
		// Register circuit breaker registry as singleton.
		$container->singleton(
			CircuitBreakerRegistry::class,
			static function (): CircuitBreakerRegistry {
				return new CircuitBreakerRegistry();
			}
		);

		// Register fallback strategy with default handlers.
		$container->singleton(
			FallbackStrategy::class,
			static function (): FallbackStrategy {
				return FallbackStrategy::createDefault();
			}
		);

		// Register preconfigured retry policies.
		$container->bind(
			'wch.retry.whatsapp',
			static function (): RetryPolicy {
				return RetryPolicy::forWhatsApp();
			}
		);

		$container->bind(
			'wch.retry.openai',
			static function (): RetryPolicy {
				return RetryPolicy::forOpenAI();
			}
		);

		$container->bind(
			'wch.retry.payment',
			static function (): RetryPolicy {
				return RetryPolicy::forPayment();
			}
		);

		// Convenience aliases.
		$container->singleton( 'wch.circuits', fn( $c ) => $c->get( CircuitBreakerRegistry::class ) );
		$container->singleton( 'wch.fallback', fn( $c ) => $c->get( FallbackStrategy::class ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ContainerInterface $container ): void {
		// Register health check endpoint.
		add_action(
			'rest_api_init',
			function () use ( $container ) {
				register_rest_route(
					'wch/v1',
					'/health/circuits',
					array(
						'methods'             => 'GET',
						'callback'            => function () use ( $container ) {
							$registry = $container->get( CircuitBreakerRegistry::class );
							return rest_ensure_response( $registry->getHealthSummary() );
						},
						'permission_callback' => function () {
							return current_user_can( 'manage_woocommerce' );
						},
					)
				);
			}
		);

		// Log circuit state changes.
		add_action(
			'wch_circuit_state_changed',
			function ( $service, $old_state, $new_state ) {
				do_action(
					'wch_log_warning',
					sprintf(
						'Circuit breaker state change: %s transitioned from %s to %s',
						$service,
						$old_state,
						$new_state
					)
				);

				// Trigger alert for critical services opening.
				if ( CircuitBreaker::STATE_OPEN === $new_state ) {
					$critical_services = array( 'whatsapp', 'payment' );

					if ( in_array( $service, $critical_services, true ) ) {
						do_action( 'wch_critical_service_unavailable', $service );
					}
				}
			},
			10,
			3
		);

		// Track fallback usage for metrics.
		add_action(
			'wch_fallback_executed',
			function ( $service, $status, $context ) {
				// Increment fallback counter (for metrics/monitoring).
				$key   = 'wch_fallback_count_' . $service;
				$count = get_transient( $key ) ?: 0;
				set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			},
			10,
			3
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			CircuitBreakerRegistry::class,
			FallbackStrategy::class,
			'wch.retry.whatsapp',
			'wch.retry.openai',
			'wch.retry.payment',
			'wch.circuits',
			'wch.fallback',
		);
	}
}
