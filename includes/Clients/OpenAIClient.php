<?php
/**
 * OpenAI Client
 *
 * OpenAI API client with circuit breaker resilience.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Clients;

use WhatsAppCommerceHub\Contracts\Clients\OpenAIClientInterface;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OpenAIClient
 *
 * Implements OpenAI API operations with circuit breaker protection.
 */
class OpenAIClient implements OpenAIClientInterface {

	/**
	 * API endpoint for OpenAI.
	 */
	private const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Rate limit: max AI calls per conversation per hour.
	 */
	private const RATE_LIMIT_CALLS_PER_HOUR = 10;

	/**
	 * OpenAI API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Model to use.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Temperature for response generation.
	 *
	 * @var float
	 */
	private float $temperature;

	/**
	 * Maximum tokens for response.
	 *
	 * @var int
	 */
	private int $max_tokens;

	/**
	 * System prompt.
	 *
	 * @var string
	 */
	private string $system_prompt;

	/**
	 * Circuit breaker instance.
	 *
	 * @var CircuitBreaker
	 */
	private CircuitBreaker $circuit_breaker;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout = 15;

	/**
	 * Available functions for function calling.
	 *
	 * @var array
	 */
	private array $functions = array();

	/**
	 * Function handlers.
	 *
	 * @var array<string, callable>
	 */
	private array $function_handlers = array();

	/**
	 * Monthly budget cap (null = no cap).
	 *
	 * @var float|null
	 */
	private ?float $monthly_budget = null;

	/**
	 * Last request latency.
	 *
	 * @var int|null
	 */
	private ?int $last_latency_ms = null;

	/**
	 * Constructor.
	 *
	 * @param string         $api_key         OpenAI API key.
	 * @param CircuitBreaker $circuit_breaker Circuit breaker instance.
	 * @param string         $model           Model name (default: gpt-4).
	 * @param float          $temperature     Temperature (default: 0.7).
	 * @param int            $max_tokens      Max tokens (default: 500).
	 */
	public function __construct(
		string $api_key,
		CircuitBreaker $circuit_breaker,
		string $model = 'gpt-4',
		float $temperature = 0.7,
		int $max_tokens = 500
	) {
		$this->api_key         = $api_key;
		$this->circuit_breaker = $circuit_breaker;
		$this->model           = $model;
		$this->temperature     = $temperature;
		$this->max_tokens      = $max_tokens;
		$this->system_prompt   = '';

		$this->initializeDefaultFunctions();
	}

	/**
	 * {@inheritdoc}
	 */
	public function generateResponse( string $user_message, array $context = array() ): array {
		if ( ! $this->isEnabled() ) {
			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => 'AI assistant is not enabled',
			);
		}

		$conversation_id = $context['conversation_id'] ?? 0;
		if ( $conversation_id && ! $this->checkRateLimit( $conversation_id ) ) {
			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => 'Rate limit exceeded',
			);
		}

		try {
			$response = $this->circuit_breaker->call(
				function () use ( $user_message, $context ) {
					return $this->executeRequest( $user_message, $context );
				},
				function () {
					// Fallback: return a generic response when circuit is open.
					return array(
						'text'    => 'I apologize, but I am currently experiencing technical difficulties. Please try again in a few moments.',
						'actions' => array(),
						'error'   => 'AI service temporarily unavailable',
					);
				}
			);

			// Track usage.
			if ( $conversation_id ) {
				$this->trackUsage( $conversation_id, $response );
			}

			return $response;
		} catch ( \Throwable $e ) {
			do_action( 'wch_log_error', 'OpenAI API error: ' . $e->getMessage() );

			return array(
				'text'    => '',
				'actions' => array(),
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function processFunctionCall( string $function_name, array $arguments, array $context = array() ): array {
		if ( ! isset( $this->function_handlers[ $function_name ] ) ) {
			return array(
				'success' => false,
				'error'   => 'Unknown function: ' . $function_name,
			);
		}

		try {
			$handler = $this->function_handlers[ $function_name ];
			$result  = $handler( $arguments, $context );

			return array(
				'success' => true,
				'result'  => $result,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAvailableFunctions(): array {
		return $this->functions;
	}

	/**
	 * {@inheritdoc}
	 */
	public function registerFunction( string $name, array $definition, callable $handler ): void {
		$this->functions[] = array_merge( $definition, array( 'name' => $name ) );
		$this->function_handlers[ $name ] = $handler;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isEnabled(): bool {
		return ! empty( $this->api_key ) &&
		       get_option( 'wch_ai_enabled', false ) === true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function checkRateLimit( int $conversation_id ): bool {
		$key   = 'wch_ai_rate_' . $conversation_id;
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_CALLS_PER_HOUR ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMonthlyUsage(): array {
		$month_key = 'wch_ai_usage_' . gmdate( 'Y_m' );
		$usage     = get_option( $month_key, array(
			'tokens' => 0,
			'cost'   => 0.0,
			'calls'  => 0,
		) );

		return array(
			'tokens' => (int) ( $usage['tokens'] ?? 0 ),
			'cost'   => (float) ( $usage['cost'] ?? 0.0 ),
			'calls'  => (int) ( $usage['calls'] ?? 0 ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getRemainingBudget(): ?float {
		if ( null === $this->monthly_budget ) {
			return null;
		}

		$usage = $this->getMonthlyUsage();

		return max( 0.0, $this->monthly_budget - $usage['cost'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function setSystemPrompt( string $prompt ): void {
		$this->system_prompt = $prompt;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setModelParameters( string $model, float $temperature = 0.7, int $max_tokens = 500 ): void {
		$this->model       = $model;
		$this->temperature = $temperature;
		$this->max_tokens  = $max_tokens;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isContentSafe( string $text ): bool {
		// Basic content filtering - can be enhanced with OpenAI moderation API.
		$blocked_patterns = array(
			'/\b(hack|exploit|inject)\b/i',
			'/\b(password|credential|secret)\s+\b/i',
		);

		foreach ( $blocked_patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool {
		return $this->circuit_breaker->isAvailable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getHealthStatus(): array {
		$metrics = $this->circuit_breaker->getMetrics();

		return array(
			'healthy'    => $this->isAvailable(),
			'latency_ms' => $this->last_latency_ms,
			'last_error' => $metrics['last_failure']['message'] ?? null,
		);
	}

	/**
	 * Set monthly budget cap.
	 *
	 * @param float|null $budget Monthly budget in USD, or null to disable.
	 * @return void
	 */
	public function setMonthlyBudget( ?float $budget ): void {
		$this->monthly_budget = $budget;
	}

	/**
	 * Execute the actual OpenAI API request.
	 *
	 * @param string $user_message User message.
	 * @param array  $context      Conversation context.
	 * @return array Response data.
	 * @throws \RuntimeException If request fails.
	 */
	private function executeRequest( string $user_message, array $context ): array {
		$start = microtime( true );

		// Build system prompt with context.
		$system_prompt = $this->buildSystemPrompt( $context );

		// Build messages array.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
		);

		// Add conversation history if provided.
		if ( ! empty( $context['history'] ) ) {
			foreach ( $context['history'] as $msg ) {
				$messages[] = array(
					'role'    => $msg['role'] ?? 'user',
					'content' => $msg['content'] ?? '',
				);
			}
		}

		// Add current user message.
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		// Build request body.
		$request_body = array(
			'model'       => $this->model,
			'messages'    => $messages,
			'temperature' => $this->temperature,
			'max_tokens'  => $this->max_tokens,
		);

		// Add function calling if functions are defined.
		if ( ! empty( $this->functions ) ) {
			$request_body['functions']      = $this->functions;
			$request_body['function_call']  = 'auto';
		}

		// Make API request.
		$response = wp_remote_post( self::API_ENDPOINT, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $request_body ),
			'timeout' => $this->timeout,
		) );

		$this->last_latency_ms = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Network error: ' . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $raw_body, true );

		// Validate JSON decode succeeded.
		if ( ! is_array( $response_body ) ) {
			throw new \RuntimeException(
				sprintf(
					'Invalid JSON response from OpenAI API (HTTP %d): %s',
					$response_code,
					json_last_error_msg()
				)
			);
		}

		if ( $response_code >= 400 ) {
			$error_message = $response_body['error']['message'] ?? 'OpenAI API request failed';
			throw new \RuntimeException( $error_message );
		}

		// Validate expected response structure.
		if ( ! isset( $response_body['choices'] ) || ! is_array( $response_body['choices'] ) ) {
			throw new \RuntimeException( 'Unexpected response structure from OpenAI API: missing choices' );
		}

		// Parse response.
		$choice  = $response_body['choices'][0] ?? array();
		$message = $choice['message'] ?? array();
		$actions = array();

		// Handle function calls.
		if ( ! empty( $message['function_call'] ) ) {
			if ( ! isset( $message['function_call']['name'] ) ) {
				do_action( 'wch_log_warning', 'OpenAI function_call missing name field' );
			} else {
				$function_name    = $message['function_call']['name'];
				$raw_arguments    = $message['function_call']['arguments'] ?? '{}';
				$arguments        = json_decode( $raw_arguments, true );

				// Validate arguments JSON.
				if ( ! is_array( $arguments ) ) {
					do_action( 'wch_log_warning', 'Invalid function_call arguments JSON: ' . json_last_error_msg() );
					$arguments = array();
				}

				$actions[] = array(
					'type'      => 'function_call',
					'function'  => $function_name,
					'arguments' => $arguments,
				);

				// Execute function if handler exists.
				if ( isset( $this->function_handlers[ $function_name ] ) ) {
					$function_result = $this->processFunctionCall( $function_name, $arguments, $context );
					$actions[]       = array(
						'type'   => 'function_result',
						'result' => $function_result,
					);
				}
			}
		}

		return array(
			'text'    => $message['content'] ?? '',
			'actions' => $actions,
			'error'   => null,
		);
	}

	/**
	 * Build system prompt with context.
	 *
	 * @param array $context Conversation context.
	 * @return string System prompt.
	 */
	private function buildSystemPrompt( array $context ): string {
		$prompt = $this->system_prompt;

		if ( empty( $prompt ) ) {
			$prompt = 'You are a helpful shopping assistant for an e-commerce store. ' .
			          'Help customers find products, answer questions, and assist with their orders. ' .
			          'Be friendly, professional, and concise.';
		}

		// Add context to prompt.
		if ( ! empty( $context['customer_name'] ) ) {
			$prompt .= sprintf( "\n\nCustomer name: %s", $context['customer_name'] );
		}

		if ( ! empty( $context['cart_summary'] ) ) {
			$prompt .= sprintf( "\n\nCurrent cart: %s", $context['cart_summary'] );
		}

		if ( ! empty( $context['store_name'] ) ) {
			$prompt .= sprintf( "\n\nStore: %s", $context['store_name'] );
		}

		return $prompt;
	}

	/**
	 * Track usage for analytics.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $response        API response.
	 * @return void
	 */
	private function trackUsage( int $conversation_id, array $response ): void {
		$month_key = 'wch_ai_usage_' . gmdate( 'Y_m' );
		$usage     = get_option( $month_key, array(
			'tokens' => 0,
			'cost'   => 0.0,
			'calls'  => 0,
		) );

		// Estimate tokens (rough approximation).
		$text_length = strlen( $response['text'] ?? '' );
		$tokens      = (int) ceil( $text_length / 4 );

		// Estimate cost based on model.
		$cost_per_1k = $this->model === 'gpt-4' ? 0.03 : 0.002;
		$cost        = ( $tokens / 1000 ) * $cost_per_1k;

		$usage['tokens'] = ( $usage['tokens'] ?? 0 ) + $tokens;
		$usage['cost']   = ( $usage['cost'] ?? 0.0 ) + $cost;
		$usage['calls']  = ( $usage['calls'] ?? 0 ) + 1;

		update_option( $month_key, $usage, false );

		do_action( 'wch_ai_usage_tracked', array(
			'conversation_id' => $conversation_id,
			'tokens'          => $tokens,
			'cost'            => $cost,
		) );
	}

	/**
	 * Initialize default AI functions.
	 *
	 * @return void
	 */
	private function initializeDefaultFunctions(): void {
		// Product search function.
		$this->registerFunction(
			'suggest_products',
			array(
				'description' => 'Search for and suggest products based on customer query',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Search query for products',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of products to return',
							'default'     => 5,
						),
					),
					'required' => array( 'query' ),
				),
			),
			function ( array $args, array $context ) {
				return apply_filters( 'wch_ai_suggest_products', array(), $args, $context );
			}
		);

		// Product details function.
		$this->registerFunction(
			'get_product_details',
			array(
				'description' => 'Get detailed information about a specific product',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'The WooCommerce product ID',
						),
					),
					'required' => array( 'product_id' ),
				),
			),
			function ( array $args, array $context ) {
				return apply_filters( 'wch_ai_get_product_details', null, $args['product_id'] ?? 0 );
			}
		);

		// Add to cart function.
		$this->registerFunction(
			'add_to_cart',
			array(
				'description' => 'Add a product to the customer\'s cart',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'description' => 'The WooCommerce product ID',
						),
						'quantity' => array(
							'type'        => 'integer',
							'description' => 'Quantity to add',
							'default'     => 1,
						),
					),
					'required' => array( 'product_id' ),
				),
			),
			function ( array $args, array $context ) {
				return apply_filters( 'wch_ai_add_to_cart', false, $args, $context );
			}
		);

		// Escalate to human function.
		$this->registerFunction(
			'escalate_to_human',
			array(
				'description' => 'Escalate the conversation to a human agent',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason for escalation',
						),
					),
					'required' => array( 'reason' ),
				),
			),
			function ( array $args, array $context ) {
				do_action( 'wch_ai_escalate', $args['reason'] ?? '', $context );
				return array( 'escalated' => true );
			}
		);
	}
}
