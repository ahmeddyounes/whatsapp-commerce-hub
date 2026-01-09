<?php
/**
 * Circuit Breaker Registry
 *
 * Manages circuit breakers for multiple services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Resilience;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CircuitBreakerRegistry
 *
 * Central registry for all circuit breakers.
 */
class CircuitBreakerRegistry {

	/**
	 * Registered circuit breakers.
	 *
	 * @var array<string, CircuitBreaker>
	 */
	private array $breakers = array();

	/**
	 * Default configurations per service type.
	 *
	 * @var array<string, array>
	 */
	private array $default_configs = array(
		'whatsapp' => array(
			'failure_threshold' => 5,
			'success_threshold' => 2,
			'timeout'           => 30,
		),
		'openai' => array(
			'failure_threshold' => 3,
			'success_threshold' => 2,
			'timeout'           => 60,
		),
		'payment' => array(
			'failure_threshold' => 3,
			'success_threshold' => 1,
			'timeout'           => 120,
		),
		'default' => array(
			'failure_threshold' => 5,
			'success_threshold' => 2,
			'timeout'           => 60,
		),
	);

	/**
	 * Get or create a circuit breaker for a service.
	 *
	 * @param string     $service Service identifier.
	 * @param array|null $config  Optional custom configuration.
	 *
	 * @return CircuitBreaker The circuit breaker instance.
	 */
	public function get( string $service, ?array $config = null ): CircuitBreaker {
		if ( ! isset( $this->breakers[ $service ] ) ) {
			$this->breakers[ $service ] = $this->create( $service, $config );
		}

		return $this->breakers[ $service ];
	}

	/**
	 * Create a new circuit breaker.
	 *
	 * @param string     $service Service identifier.
	 * @param array|null $config  Optional custom configuration.
	 *
	 * @return CircuitBreaker New circuit breaker.
	 */
	private function create( string $service, ?array $config = null ): CircuitBreaker {
		// Use service-specific defaults or fall back to general defaults.
		$defaults = $this->default_configs[ $service ] ?? $this->default_configs['default'];
		$config = array_merge( $defaults, $config ?? array() );

		return new CircuitBreaker(
			$service,
			$config['failure_threshold'],
			$config['success_threshold'],
			$config['timeout']
		);
	}

	/**
	 * Execute a protected call for a service.
	 *
	 * @param string        $service    Service identifier.
	 * @param callable      $operation  The operation to execute.
	 * @param callable|null $fallback   Optional fallback.
	 *
	 * @return mixed Operation result.
	 */
	public function call(
		string $service,
		callable $operation,
		?callable $fallback = null
	): mixed {
		return $this->get( $service )->call( $operation, $fallback );
	}

	/**
	 * Check if a service is available.
	 *
	 * @param string $service Service identifier.
	 *
	 * @return bool True if available.
	 */
	public function isAvailable( string $service ): bool {
		if ( ! isset( $this->breakers[ $service ] ) ) {
			return true; // Assume available if not tracked.
		}

		return $this->breakers[ $service ]->isAvailable();
	}

	/**
	 * Get status of all circuit breakers.
	 *
	 * @return array<string, array> Status per service.
	 */
	public function getAllStatus(): array {
		$status = array();

		foreach ( $this->breakers as $service => $breaker ) {
			$status[ $service ] = $breaker->getMetrics();
		}

		return $status;
	}

	/**
	 * Reset a circuit breaker.
	 *
	 * @param string $service Service identifier.
	 *
	 * @return void
	 */
	public function reset( string $service ): void {
		if ( isset( $this->breakers[ $service ] ) ) {
			$this->breakers[ $service ]->close();
		}
	}

	/**
	 * Reset all circuit breakers.
	 *
	 * @return void
	 */
	public function resetAll(): void {
		foreach ( $this->breakers as $breaker ) {
			$breaker->close();
		}
	}

	/**
	 * Set default configuration for a service type.
	 *
	 * @param string $service Service identifier.
	 * @param array  $config  Configuration array.
	 *
	 * @return void
	 */
	public function setDefaultConfig( string $service, array $config ): void {
		$this->default_configs[ $service ] = array_merge(
			$this->default_configs['default'],
			$config
		);
	}

	/**
	 * Get health summary.
	 *
	 * @return array<string, mixed> Health summary.
	 */
	public function getHealthSummary(): array {
		$total = count( $this->breakers );
		$open = 0;
		$half_open = 0;
		$closed = 0;

		foreach ( $this->breakers as $breaker ) {
			switch ( $breaker->getState() ) {
				case CircuitBreaker::STATE_OPEN:
					$open++;
					break;
				case CircuitBreaker::STATE_HALF_OPEN:
					$half_open++;
					break;
				case CircuitBreaker::STATE_CLOSED:
					$closed++;
					break;
			}
		}

		$health = 'healthy';
		if ( $open > 0 ) {
			$health = 'degraded';
		}
		if ( $open === $total && $total > 0 ) {
			$health = 'critical';
		}

		return array(
			'status'    => $health,
			'total'     => $total,
			'open'      => $open,
			'half_open' => $half_open,
			'closed'    => $closed,
			'services'  => $this->getAllStatus(),
		);
	}
}
