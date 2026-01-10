<?php
/**
 * Retry Policy
 *
 * Configurable retry strategies with exponential backoff.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Resilience;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RetryPolicy
 *
 * Provides configurable retry behavior with various backoff strategies.
 */
class RetryPolicy {

	/**
	 * Backoff strategies.
	 */
	public const BACKOFF_LINEAR      = 'linear';
	public const BACKOFF_EXPONENTIAL = 'exponential';
	public const BACKOFF_FIBONACCI   = 'fibonacci';
	public const BACKOFF_CONSTANT    = 'constant';

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	private int $max_attempts;

	/**
	 * Base delay in milliseconds.
	 *
	 * @var int
	 */
	private int $base_delay;

	/**
	 * Maximum delay in milliseconds.
	 *
	 * @var int
	 */
	private int $max_delay;

	/**
	 * Backoff strategy.
	 *
	 * @var string
	 */
	private string $backoff_strategy;

	/**
	 * Jitter percentage (0-100).
	 *
	 * @var int
	 */
	private int $jitter;

	/**
	 * Retryable exception types.
	 *
	 * @var array<class-string>
	 */
	private array $retryable_exceptions = [];

	/**
	 * Non-retryable exception types.
	 *
	 * @var array<class-string>
	 */
	private array $non_retryable_exceptions = [];

	/**
	 * Custom retry condition callback.
	 *
	 * @var callable|null
	 */
	private $retry_condition = null;

	/**
	 * Callback for retry events.
	 *
	 * @var callable|null
	 */
	private $on_retry = null;

	/**
	 * Constructor.
	 *
	 * @param int    $max_attempts     Maximum retry attempts (default: 3).
	 * @param int    $base_delay       Base delay in ms (default: 1000).
	 * @param int    $max_delay        Maximum delay in ms (default: 30000).
	 * @param string $backoff_strategy Backoff strategy (default: exponential).
	 * @param int    $jitter           Jitter percentage (default: 25).
	 */
	public function __construct(
		int $max_attempts = 3,
		int $base_delay = 1000,
		int $max_delay = 30000,
		string $backoff_strategy = self::BACKOFF_EXPONENTIAL,
		int $jitter = 25
	) {
		$this->max_attempts     = $max_attempts;
		$this->base_delay       = $base_delay;
		$this->max_delay        = $max_delay;
		$this->backoff_strategy = $backoff_strategy;
		$this->jitter           = max( 0, min( 100, $jitter ) );
	}

	/**
	 * Execute an operation with retry logic.
	 *
	 * @param callable $operation The operation to execute.
	 *
	 * @return mixed Operation result.
	 *
	 * @throws \Throwable If all retries fail.
	 */
	public function execute( callable $operation ): mixed {
		$attempt        = 0;
		$last_exception = null;

		while ( $attempt < $this->max_attempts ) {
			++$attempt;

			try {
				return $operation();
			} catch ( \Throwable $e ) {
				$last_exception = $e;

				if ( ! $this->shouldRetry( $e, $attempt ) ) {
					throw $e;
				}

				if ( $attempt < $this->max_attempts ) {
					$delay = $this->getDelay( $attempt );

					if ( $this->on_retry ) {
						( $this->on_retry )( $attempt, $delay, $e );
					}

					$this->sleep( $delay );
				}
			}
		}

		throw $last_exception;
	}

	/**
	 * Execute an operation with async retry (for Action Scheduler).
	 *
	 * @param string   $hook       Action hook name.
	 * @param array    $args       Action arguments.
	 * @param int      $attempt    Current attempt number.
	 * @param callable $operation  The operation to execute.
	 *
	 * @return mixed Operation result.
	 *
	 * @throws \Throwable If operation fails and max retries reached.
	 */
	public function executeAsync(
		string $hook,
		array $args,
		int $attempt,
		callable $operation
	): mixed {
		try {
			return $operation();
		} catch ( \Throwable $e ) {
			if ( $attempt >= $this->max_attempts || ! $this->shouldRetry( $e, $attempt ) ) {
				throw $e;
			}

			// Schedule retry via Action Scheduler.
			$delay         = $this->getDelay( $attempt );
			$delay_seconds = (int) ceil( $delay / 1000 );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				$args['_retry_attempt'] = $attempt + 1;
				$args['_retry_reason']  = $e->getMessage();

				as_schedule_single_action(
					time() + $delay_seconds,
					$hook,
					[ $args ],
					'wch-normal'
				);
			}

			return null;
		}
	}

	/**
	 * Calculate delay for a given attempt.
	 *
	 * @param int $attempt Attempt number (1-based).
	 *
	 * @return int Delay in milliseconds.
	 */
	public function getDelay( int $attempt ): int {
		$delay = match ( $this->backoff_strategy ) {
			self::BACKOFF_CONSTANT    => $this->base_delay,
			self::BACKOFF_LINEAR      => $this->base_delay * $attempt,
			self::BACKOFF_FIBONACCI   => $this->base_delay * $this->fibonacci( $attempt ),
			self::BACKOFF_EXPONENTIAL => $this->base_delay * pow( 2, $attempt - 1 ),
			default                   => $this->base_delay * pow( 2, $attempt - 1 ),
		};

		// Apply jitter with bounds protection.
		if ( $this->jitter > 0 && $delay > 0 ) {
			$jitter_range = (int) ( $delay * $this->jitter / 100 );
			$jitter_value = random_int( -$jitter_range, $jitter_range );
			$delay       += $jitter_value;

			// Ensure delay doesn't go below half the base delay after jitter.
			// This prevents near-zero delays while still allowing meaningful variation.
			$minimum_delay = (int) ( $this->base_delay / 2 );
			$delay         = max( $minimum_delay, $delay );
		}

		// Ensure delay is within bounds.
		return min( $this->max_delay, $delay );
	}

	/**
	 * Check if an exception should trigger a retry.
	 *
	 * @param \Throwable $exception The exception.
	 * @param int        $attempt   Current attempt number.
	 *
	 * @return bool True if should retry.
	 */
	public function shouldRetry( \Throwable $exception, int $attempt ): bool {
		// Check if max attempts reached.
		if ( $attempt >= $this->max_attempts ) {
			return false;
		}

		// Check custom condition first.
		if ( $this->retry_condition ) {
			return ( $this->retry_condition )( $exception, $attempt );
		}

		// Check non-retryable exceptions.
		foreach ( $this->non_retryable_exceptions as $type ) {
			if ( $exception instanceof $type ) {
				return false;
			}
		}

		// If retryable list is specified, only retry those.
		if ( ! empty( $this->retryable_exceptions ) ) {
			foreach ( $this->retryable_exceptions as $type ) {
				if ( $exception instanceof $type ) {
					return true;
				}
			}
			return false;
		}

		// Default: retry all exceptions.
		return true;
	}

	/**
	 * Set retryable exception types.
	 *
	 * @param array<class-string> $exceptions Exception class names.
	 *
	 * @return self
	 */
	public function retryOn( array $exceptions ): self {
		$this->retryable_exceptions = $exceptions;
		return $this;
	}

	/**
	 * Set non-retryable exception types.
	 *
	 * @param array<class-string> $exceptions Exception class names.
	 *
	 * @return self
	 */
	public function dontRetryOn( array $exceptions ): self {
		$this->non_retryable_exceptions = $exceptions;
		return $this;
	}

	/**
	 * Set custom retry condition.
	 *
	 * @param callable $condition Function(Throwable, int): bool.
	 *
	 * @return self
	 */
	public function retryIf( callable $condition ): self {
		$this->retry_condition = $condition;
		return $this;
	}

	/**
	 * Set callback for retry events.
	 *
	 * @param callable $callback Function(int $attempt, int $delay, Throwable $e): void.
	 *
	 * @return self
	 */
	public function onRetry( callable $callback ): self {
		$this->on_retry = $callback;
		return $this;
	}

	/**
	 * Maximum safe n for fibonacci to prevent overflow.
	 *
	 * Fib(45) = 1,134,903,170 (safe for 32-bit signed).
	 * Fib(46) = 1,836,311,903 (exceeds 32-bit signed max on some systems).
	 */
	private const MAX_FIBONACCI_N = 45;

	/**
	 * Get the nth Fibonacci number.
	 *
	 * Capped at MAX_FIBONACCI_N to prevent integer overflow.
	 * For retry delays, this limit is more than sufficient as
	 * fib(45) * 1000ms base_delay = ~13 days.
	 *
	 * @param int $n Position in sequence.
	 *
	 * @return int Fibonacci number.
	 */
	private function fibonacci( int $n ): int {
		if ( $n <= 0 ) {
			return 0;
		}
		if ( $n <= 2 ) {
			return 1;
		}

		// Cap n to prevent overflow.
		$n = min( $n, self::MAX_FIBONACCI_N );

		$a = 1;
		$b = 1;

		for ( $i = 3; $i <= $n; $i++ ) {
			$temp = $a + $b;

			// Additional overflow check (defensive).
			if ( $temp < $b ) {
				// Overflow detected, return previous safe value.
				return $b;
			}

			$a = $b;
			$b = $temp;
		}

		return $b;
	}

	/**
	 * Sleep for specified milliseconds.
	 *
	 * @param int $milliseconds Sleep duration.
	 *
	 * @return void
	 */
	private function sleep( int $milliseconds ): void {
		usleep( $milliseconds * 1000 );
	}

	/**
	 * Create a policy for WhatsApp API calls.
	 *
	 * @return self Configured policy.
	 */
	public static function forWhatsApp(): self {
		return ( new self( 3, 2000, 30000, self::BACKOFF_EXPONENTIAL, 20 ) )
			->dontRetryOn( [ \InvalidArgumentException::class ] );
	}

	/**
	 * Create a policy for OpenAI API calls.
	 *
	 * @return self Configured policy.
	 */
	public static function forOpenAI(): self {
		return ( new self( 3, 5000, 60000, self::BACKOFF_EXPONENTIAL, 25 ) )
			->dontRetryOn( [ \InvalidArgumentException::class ] );
	}

	/**
	 * Create a policy for payment gateway calls.
	 *
	 * @return self Configured policy.
	 */
	public static function forPayment(): self {
		return ( new self( 2, 3000, 30000, self::BACKOFF_LINEAR, 10 ) )
			->dontRetryOn( [ \InvalidArgumentException::class ] );
	}

	/**
	 * Create a fast-fail policy (no retries).
	 *
	 * @return self Configured policy.
	 */
	public static function noRetry(): self {
		return new self( 1, 0, 0 );
	}
}
