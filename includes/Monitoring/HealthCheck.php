<?php
/**
 * Health Check
 *
 * Provides system health monitoring endpoint.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Monitoring;

use WhatsAppCommerceHub\Queue\JobMonitor;
use WhatsAppCommerceHub\Resilience\CircuitBreakerRegistry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HealthCheck
 *
 * Aggregates health status from all system components.
 */
class HealthCheck {

	/**
	 * Component health checkers.
	 *
	 * @var array<string, callable>
	 */
	private array $checks = [];

	/**
	 * Container instance.
	 *
	 * @var mixed
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param mixed $container DI container.
	 */
	public function __construct( $container ) {
		$this->container = $container;
		$this->registerDefaultChecks();
	}

	/**
	 * Register default health checks.
	 *
	 * @return void
	 */
	private function registerDefaultChecks(): void {
		// Database connectivity check.
		$this->register(
			'database',
			function (): array {
				global $wpdb;

				$start   = microtime( true );
				$result  = $wpdb->get_var( 'SELECT 1' );
				$latency = ( microtime( true ) - $start ) * 1000;

				return [
					'status'  => '1' === $result ? 'healthy' : 'unhealthy',
					'latency' => round( $latency, 2 ),
				];
			}
		);

		// WooCommerce check.
		$this->register(
			'woocommerce',
			function (): array {
				return [
					'status'  => class_exists( 'WooCommerce' ) ? 'healthy' : 'unhealthy',
					'version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
				];
			}
		);

		// Action Scheduler check.
		$this->register(
			'action_scheduler',
			function (): array {
				if ( ! function_exists( 'as_has_scheduled_action' ) ) {
					return [ 'status' => 'unavailable' ];
				}

				global $wpdb;
				$table = $wpdb->prefix . 'actionscheduler_actions';

				$pending = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$table} WHERE status = 'pending'"
				);

				$failed = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"
				);

				$status = 'healthy';
				if ( $pending > 1000 ) {
					$status = 'warning';
				}
				if ( $failed > 100 ) {
					$status = 'degraded';
				}

				return [
					'status'  => $status,
					'pending' => $pending,
					'failed'  => $failed,
				];
			}
		);

		// Queue health check.
		$this->register(
			'queue',
			function (): array {
				try {
					$monitor = $this->container->get( JobMonitor::class );
					$health  = $monitor->getHealthStatus();

					return [
						'status'     => $health['status'],
						'pending'    => $health['queue']['totals']['pending'] ?? 0,
						'throughput' => $health['throughput']['jobs_per_minute'] ?? 0,
					];
				} catch ( \Throwable $e ) {
					return [
						'status' => 'error',
						'error'  => $e->getMessage(),
					];
				}
			}
		);

		// Circuit breakers check.
		$this->register(
			'circuit_breakers',
			function (): array {
				try {
					$registry = $this->container->get( CircuitBreakerRegistry::class );
					$summary  = $registry->getHealthSummary();

					return [
						'status' => $summary['status'],
						'open'   => $summary['open'],
						'total'  => $summary['total'],
					];
				} catch ( \Throwable $e ) {
					return [
						'status' => 'unknown',
					];
				}
			}
		);

		// Disk space check.
		$this->register(
			'disk',
			function (): array {
				$upload_dir = wp_upload_dir();
				$path       = $upload_dir['basedir'];

				if ( ! is_dir( $path ) ) {
					return [ 'status' => 'error' ];
				}

				$free  = disk_free_space( $path );
				$total = disk_total_space( $path );

				$free_percent = $total > 0 ? ( $free / $total ) * 100 : 0;

				$status = 'healthy';
				if ( $free_percent < 20 ) {
					$status = 'warning';
				}
				if ( $free_percent < 5 ) {
					$status = 'critical';
				}

				return [
					'status'       => $status,
					'free_percent' => round( $free_percent, 1 ),
					'free_gb'      => round( $free / ( 1024 * 1024 * 1024 ), 2 ),
				];
			}
		);

		// Memory check.
		$this->register(
			'memory',
			function (): array {
				$limit       = ini_get( 'memory_limit' );
				$limit_bytes = $this->parseMemoryLimit( $limit );
				$usage       = memory_get_usage( true );
				$peak        = memory_get_peak_usage( true );

				$usage_percent = $limit_bytes > 0 ? ( $usage / $limit_bytes ) * 100 : 0;

				$status = 'healthy';
				if ( $usage_percent > 70 ) {
					$status = 'warning';
				}
				if ( $usage_percent > 90 ) {
					$status = 'critical';
				}

				return [
					'status'        => $status,
					'limit'         => $limit,
					'usage_percent' => round( $usage_percent, 1 ),
					'usage_mb'      => round( $usage / ( 1024 * 1024 ), 2 ),
					'peak_mb'       => round( $peak / ( 1024 * 1024 ), 2 ),
				];
			}
		);
	}

	/**
	 * Register a custom health check.
	 *
	 * @param string   $name  Check name.
	 * @param callable $check Check function.
	 *
	 * @return self
	 */
	public function register( string $name, callable $check ): self {
		$this->checks[ $name ] = $check;
		return $this;
	}

	/**
	 * Run all health checks.
	 *
	 * @return array<string, mixed> Health status.
	 */
	public function check(): array {
		$results         = [];
		$overall_status  = 'healthy';
		$status_priority = [
			'healthy'   => 0,
			'unknown'   => 1,
			'warning'   => 2,
			'degraded'  => 3,
			'unhealthy' => 4,
			'critical'  => 5,
			'error'     => 6,
		];

		foreach ( $this->checks as $name => $check ) {
			try {
				$start    = microtime( true );
				$result   = $check();
				$duration = ( microtime( true ) - $start ) * 1000;

				$result['duration_ms'] = round( $duration, 2 );
				$results[ $name ]      = $result;

				// Update overall status.
				$check_status = $result['status'] ?? 'unknown';
				if ( ( $status_priority[ $check_status ] ?? 0 ) > ( $status_priority[ $overall_status ] ?? 0 ) ) {
					$overall_status = $check_status;
				}
			} catch ( \Throwable $e ) {
				$results[ $name ] = [
					'status' => 'error',
					'error'  => $e->getMessage(),
				];
				$overall_status   = 'error';
			}
		}

		return [
			'status'    => $overall_status,
			'timestamp' => gmdate( 'c' ),
			'version'   => defined( 'WCH_VERSION' ) ? WCH_VERSION : 'unknown',
			'checks'    => $results,
		];
	}

	/**
	 * Run a single health check.
	 *
	 * @param string $name Check name.
	 *
	 * @return array<string, mixed>|null Check result or null if not found.
	 */
	public function checkOne( string $name ): ?array {
		if ( ! isset( $this->checks[ $name ] ) ) {
			return null;
		}

		try {
			return ( $this->checks[ $name ] )();
		} catch ( \Throwable $e ) {
			return [
				'status' => 'error',
				'error'  => $e->getMessage(),
			];
		}
	}

	/**
	 * Parse memory limit string to bytes.
	 *
	 * @param string $limit Memory limit string.
	 *
	 * @return int Bytes.
	 */
	private function parseMemoryLimit( string $limit ): int {
		$limit = strtolower( trim( $limit ) );

		if ( '-1' === $limit ) {
			return PHP_INT_MAX;
		}

		$value = (int) $limit;
		$unit  = substr( $limit, -1 );

		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}

	/**
	 * Get liveness probe response.
	 *
	 * @return array Simple liveness response.
	 */
	public function liveness(): array {
		return [
			'status' => 'ok',
			'time'   => time(),
		];
	}

	/**
	 * Get readiness probe response.
	 *
	 * @return array Readiness response.
	 */
	public function readiness(): array {
		$db = $this->checkOne( 'database' );
		$wc = $this->checkOne( 'woocommerce' );

		$ready = 'healthy' === ( $db['status'] ?? '' ) && 'healthy' === ( $wc['status'] ?? '' );

		return [
			'ready'       => $ready,
			'database'    => $db['status'] ?? 'unknown',
			'woocommerce' => $wc['status'] ?? 'unknown',
		];
	}
}
