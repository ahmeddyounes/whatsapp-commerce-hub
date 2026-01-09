<?php
/**
 * Fallback Strategy
 *
 * Provides graceful degradation when services are unavailable.
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
 * Class FallbackStrategy
 *
 * Manages fallback behaviors for various failure scenarios.
 */
class FallbackStrategy {

	/**
	 * Registered fallback handlers.
	 *
	 * @var array<string, callable>
	 */
	private array $handlers = array();

	/**
	 * Cached fallback values.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = array();

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	private int $cache_ttl = 300;

	/**
	 * Register a fallback handler.
	 *
	 * @param string   $service  Service identifier.
	 * @param callable $handler  Fallback handler function.
	 *
	 * @return self
	 */
	public function register( string $service, callable $handler ): self {
		$this->handlers[ $service ] = $handler;
		return $this;
	}

	/**
	 * Execute a fallback for a service.
	 *
	 * @param string       $service Service identifier.
	 * @param array        $context Additional context for the fallback.
	 * @param mixed        $default Default value if no handler exists.
	 *
	 * @return mixed Fallback result.
	 */
	public function execute( string $service, array $context = array(), mixed $default = null ): mixed {
		if ( ! isset( $this->handlers[ $service ] ) ) {
			$this->logFallback( $service, 'no_handler', $context );
			return $default;
		}

		try {
			$result = ( $this->handlers[ $service ] )( $context );
			$this->logFallback( $service, 'executed', $context );
			return $result;
		} catch ( \Throwable $e ) {
			$this->logFallback( $service, 'failed', array_merge( $context, array(
				'error' => $e->getMessage(),
			) ) );
			return $default;
		}
	}

	/**
	 * Get a cached value or compute it.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $compute  Function to compute value if not cached.
	 * @param int|null $ttl      Cache TTL override.
	 *
	 * @return mixed Cached or computed value.
	 */
	public function cached( string $key, callable $compute, ?int $ttl = null ): mixed {
		$cached = get_transient( 'wch_fallback_' . $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $compute();

		set_transient( 'wch_fallback_' . $key, $value, $ttl ?? $this->cache_ttl );

		return $value;
	}

	/**
	 * Log a fallback event.
	 *
	 * @param string $service Service identifier.
	 * @param string $status  Fallback status.
	 * @param array  $context Event context.
	 *
	 * @return void
	 */
	private function logFallback( string $service, string $status, array $context ): void {
		do_action( 'wch_log_info', sprintf(
			'[Fallback:%s] Status: %s',
			$service,
			$status
		) );

		do_action( 'wch_fallback_executed', $service, $status, $context );
	}

	/**
	 * Create default fallback strategies for WCH services.
	 *
	 * @return self Configured strategy.
	 */
	public static function createDefault(): self {
		$strategy = new self();

		// WhatsApp API fallback: Queue for later.
		$strategy->register( 'whatsapp', function ( array $context ): array {
			$message_data = $context['message'] ?? array();

			// Store in outbox for later sending.
			if ( ! empty( $message_data ) && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 300, // Retry in 5 minutes.
					'wch_retry_whatsapp_message',
					array( $message_data ),
					'wch-urgent'
				);
			}

			return array(
				'status'  => 'queued',
				'message' => 'Message queued for later delivery',
			);
		} );

		// OpenAI fallback: Rule-based intent detection.
		$strategy->register( 'openai', function ( array $context ): array {
			$message = strtolower( $context['message'] ?? '' );

			// Simple keyword-based intent detection.
			$intents = array(
				'order'    => array( 'order', 'buy', 'purchase', 'checkout' ),
				'support'  => array( 'help', 'support', 'problem', 'issue' ),
				'status'   => array( 'status', 'where', 'track', 'shipping' ),
				'catalog'  => array( 'products', 'catalog', 'show', 'list' ),
				'greeting' => array( 'hi', 'hello', 'hey', 'good' ),
			);

			$detected_intent = 'unknown';
			$confidence = 0.3; // Low confidence for rule-based.

			foreach ( $intents as $intent => $keywords ) {
				foreach ( $keywords as $keyword ) {
					if ( str_contains( $message, $keyword ) ) {
						$detected_intent = $intent;
						$confidence = 0.6;
						break 2;
					}
				}
			}

			return array(
				'intent'     => $detected_intent,
				'confidence' => $confidence,
				'fallback'   => true,
				'message'    => 'Rule-based detection (AI unavailable)',
			);
		} );

		// Payment gateway fallback: Offer COD.
		$strategy->register( 'payment', function ( array $context ): array {
			return array(
				'status'           => 'gateway_unavailable',
				'alternative'      => 'cod',
				'message'          => 'Online payment is temporarily unavailable. Would you like to pay Cash on Delivery?',
				'show_cod_option'  => true,
			);
		} );

		// Product catalog fallback: Cached catalog.
		$strategy->register( 'catalog', function ( array $context ) use ( $strategy ): array {
			return $strategy->cached( 'product_catalog', function (): array {
				// Return cached catalog summary.
				$products = wc_get_products( array(
					'status'  => 'publish',
					'limit'   => 20,
					'orderby' => 'popularity',
				) );

				return array_map( function ( $product ) {
					return array(
						'id'    => $product->get_id(),
						'name'  => $product->get_name(),
						'price' => $product->get_price(),
					);
				}, $products );
			}, 3600 ); // Cache for 1 hour.
		} );

		// Analytics fallback: Return empty/cached data.
		$strategy->register( 'analytics', function ( array $context ) use ( $strategy ): array {
			$metric = $context['metric'] ?? 'unknown';

			return $strategy->cached( 'analytics_' . $metric, function (): array {
				return array(
					'status'  => 'cached',
					'data'    => array(),
					'message' => 'Live analytics temporarily unavailable',
				);
			}, 600 ); // Cache for 10 minutes.
		} );

		return $strategy;
	}
}
