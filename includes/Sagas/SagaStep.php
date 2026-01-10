<?php
/**
 * Saga Step
 *
 * Represents a single step in a saga with execute and compensate operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Sagas;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SagaStep
 *
 * Encapsulates a saga step with forward and compensating actions.
 */
class SagaStep {

	/**
	 * Step metadata.
	 *
	 * @var array
	 */
	private array $metadata = array();

	/**
	 * Constructor.
	 *
	 * @param string        $name       Step name.
	 * @param callable      $execute    Execute callback.
	 * @param callable|null $compensate Compensate callback (null = no compensation needed).
	 * @param int           $timeout    Step timeout in seconds.
	 * @param int           $max_retries Maximum retry attempts.
	 * @param bool          $critical   Whether step is critical.
	 */
	public function __construct(
		private string $name,
		private $execute,
		private $compensate = null,
		private int $timeout = 30,
		private int $max_retries = 3,
		private bool $critical = true
	) {
	}

	/**
	 * Get step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Get timeout.
	 *
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->timeout;
	}

	/**
	 * Get max retries.
	 *
	 * @return int
	 */
	public function getMaxRetries(): int {
		return $this->max_retries;
	}

	/**
	 * Check if step is critical.
	 *
	 * @return bool
	 */
	public function isCritical(): bool {
		return $this->critical;
	}

	/**
	 * Check if step has compensation.
	 *
	 * @return bool
	 */
	public function hasCompensation(): bool {
		return null !== $this->compensate;
	}

	/**
	 * Execute the step.
	 *
	 * @param array $context Saga context.
	 * @return mixed Step result.
	 * @throws \Throwable If execution fails.
	 */
	public function execute( array $context ): mixed {
		return ( $this->execute )( $context );
	}

	/**
	 * Execute compensation (rollback).
	 *
	 * @param array $context Saga context including step result.
	 * @return void
	 * @throws \Throwable If compensation fails.
	 */
	public function compensate( array $context ): void {
		if ( null === $this->compensate ) {
			return;
		}

		( $this->compensate )( $context );
	}

	/**
	 * Set metadata.
	 *
	 * @param string $key   Metadata key.
	 * @param mixed  $value Metadata value.
	 * @return self
	 */
	public function withMetadata( string $key, mixed $value ): self {
		$clone                   = clone $this;
		$clone->metadata[ $key ] = $value;

		return $clone;
	}

	/**
	 * Get metadata.
	 *
	 * @param string $key     Metadata key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function getMetadata( string $key, mixed $default = null ): mixed {
		return $this->metadata[ $key ] ?? $default;
	}

	/**
	 * Get all metadata.
	 *
	 * @return array
	 */
	public function getAllMetadata(): array {
		return $this->metadata;
	}

	/**
	 * Create step with modified timeout.
	 *
	 * @param int $timeout New timeout.
	 * @return self
	 */
	public function withTimeout( int $timeout ): self {
		$clone          = clone $this;
		$clone->timeout = $timeout;

		return $clone;
	}

	/**
	 * Create step with modified retry count.
	 *
	 * @param int $retries New retry count.
	 * @return self
	 */
	public function withRetries( int $retries ): self {
		$clone              = clone $this;
		$clone->max_retries = $retries;

		return $clone;
	}

	/**
	 * Create a non-critical step.
	 *
	 * @return self
	 */
	public function asOptional(): self {
		$clone           = clone $this;
		$clone->critical = false;

		return $clone;
	}
}
