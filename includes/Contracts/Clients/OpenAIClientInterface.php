<?php
/**
 * OpenAI Client Interface
 *
 * Contract for AI/ChatGPT operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Clients;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface OpenAIClientInterface
 *
 * Defines the contract for AI-powered conversation operations.
 */
interface OpenAIClientInterface {

	/**
	 * Generate AI response for a user message.
	 *
	 * @param string $user_message User's message.
	 * @param array  $context      Conversation context including state, customer profile, cart, etc.
	 * @return array{text: string, actions: array, error: string|null}
	 */
	public function generateResponse( string $user_message, array $context = [] ): array;

	/**
	 * Process a function call triggered by AI.
	 *
	 * @param string $function_name Function name.
	 * @param array  $arguments     Function arguments.
	 * @param array  $context       Conversation context.
	 * @return array Function result.
	 */
	public function processFunctionCall( string $function_name, array $arguments, array $context = [] ): array;

	/**
	 * Get available AI functions.
	 *
	 * @return array Array of function definitions.
	 */
	public function getAvailableFunctions(): array;

	/**
	 * Register a custom AI function.
	 *
	 * @param string   $name        Function name.
	 * @param array    $definition  Function definition (parameters, description).
	 * @param callable $handler     Function handler callback.
	 * @return void
	 */
	public function registerFunction( string $name, array $definition, callable $handler ): void;

	/**
	 * Check if AI is enabled and configured.
	 *
	 * @return bool True if AI is enabled.
	 */
	public function isEnabled(): bool;

	/**
	 * Check rate limit for conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool True if within limit, false if exceeded.
	 */
	public function checkRateLimit( int $conversation_id ): bool;

	/**
	 * Get current month usage statistics.
	 *
	 * @return array{tokens: int, cost: float, calls: int}
	 */
	public function getMonthlyUsage(): array;

	/**
	 * Get remaining budget for current month.
	 *
	 * @return float|null Remaining budget or null if no cap set.
	 */
	public function getRemainingBudget(): ?float;

	/**
	 * Set system prompt.
	 *
	 * @param string $prompt System prompt text.
	 * @return void
	 */
	public function setSystemPrompt( string $prompt ): void;

	/**
	 * Set model parameters.
	 *
	 * @param string $model       Model name (e.g., 'gpt-4').
	 * @param float  $temperature Temperature for response generation.
	 * @param int    $max_tokens  Maximum tokens for response.
	 * @return void
	 */
	public function setModelParameters( string $model, float $temperature = 0.7, int $max_tokens = 500 ): void;

	/**
	 * Check if content is safe (content filtering).
	 *
	 * @param string $text Text to check.
	 * @return bool True if safe, false otherwise.
	 */
	public function isContentSafe( string $text ): bool;

	/**
	 * Check if client is available (circuit breaker state).
	 *
	 * @return bool True if available.
	 */
	public function isAvailable(): bool;

	/**
	 * Get API health status.
	 *
	 * @return array{healthy: bool, latency_ms: int|null, last_error: string|null}
	 */
	public function getHealthStatus(): array;
}
