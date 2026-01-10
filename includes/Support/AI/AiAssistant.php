<?php
/**
 * AI Assistant
 *
 * Provides AI-powered assistance for customer interactions.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Support\AI;

use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Assistant Class
 *
 * Provides intelligent response generation and conversation management.
 */
class AiAssistant {

	/**
	 * AI providers
	 */
	private const PROVIDER_OPENAI    = 'openai';
	private const PROVIDER_ANTHROPIC = 'anthropic';
	private const PROVIDER_LOCAL     = 'local';

	/**
	 * Constructor
	 *
	 * @param SettingsManager $settings Settings manager
	 * @param Logger          $logger Logger instance
	 * @param ResponseParser  $parser Response parser
	 */
	public function __construct(
		private readonly SettingsManager $settings,
		private readonly Logger $logger,
		private readonly ResponseParser $parser
	) {
	}

	/**
	 * Generate response for customer message
	 *
	 * @param string               $message Customer message
	 * @param array<string, mixed> $context Conversation context
	 * @return string Generated response
	 */
	public function generateResponse( string $message, array $context = [] ): string {
		$provider = $this->settings->get( 'ai.provider', self::PROVIDER_LOCAL );

		if ( ! $this->isAiEnabled() ) {
			return $this->generateFallbackResponse( $message, $context );
		}

		try {
			return match ( $provider ) {
				self::PROVIDER_OPENAI => $this->generateOpenAiResponse( $message, $context ),
				self::PROVIDER_ANTHROPIC => $this->generateAnthropicResponse( $message, $context ),
				default => $this->generateLocalResponse( $message, $context ),
			};
		} catch ( \Exception $e ) {
			$this->logger->error(
				'AI response generation failed',
				[
					'error'    => $e->getMessage(),
					'provider' => $provider,
				]
			);

			return $this->generateFallbackResponse( $message, $context );
		}
	}

	/**
	 * Generate response using OpenAI
	 *
	 * @param string               $message Customer message
	 * @param array<string, mixed> $context Conversation context
	 * @return string Generated response
	 */
	private function generateOpenAiResponse( string $message, array $context ): string {
		$apiKey = $this->settings->get( 'ai.openai_key' );

		if ( ! $apiKey ) {
			return $this->generateLocalResponse( $message, $context );
		}

		$systemPrompt = $this->buildSystemPrompt( $context );

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'headers' => [
					'Authorization' => "Bearer {$apiKey}",
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model'       => 'gpt-3.5-turbo',
						'messages'    => [
							[
								'role'    => 'system',
								'content' => $systemPrompt,
							],
							[
								'role'    => 'user',
								'content' => $message,
							],
						],
						'max_tokens'  => 150,
						'temperature' => 0.7,
					]
				),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['choices'][0]['message']['content'] ?? $this->generateLocalResponse( $message, $context );
	}

	/**
	 * Generate response using Anthropic Claude
	 *
	 * @param string               $message Customer message
	 * @param array<string, mixed> $context Conversation context
	 * @return string Generated response
	 */
	private function generateAnthropicResponse( string $message, array $context ): string {
		$apiKey = $this->settings->get( 'ai.anthropic_key' );

		if ( ! $apiKey ) {
			return $this->generateLocalResponse( $message, $context );
		}

		$systemPrompt = $this->buildSystemPrompt( $context );

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'headers' => [
					'x-api-key'         => $apiKey,
					'Content-Type'      => 'application/json',
					'anthropic-version' => '2023-06-01',
				],
				'body'    => wp_json_encode(
					[
						'model'      => 'claude-3-sonnet-20240229',
						'max_tokens' => 150,
						'system'     => $systemPrompt,
						'messages'   => [
							[
								'role'    => 'user',
								'content' => $message,
							],
						],
					]
				),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return $body['content'][0]['text'] ?? $this->generateLocalResponse( $message, $context );
	}

	/**
	 * Generate response using local rules
	 *
	 * @param string               $message Customer message
	 * @param array<string, mixed> $context Conversation context
	 * @return string Generated response
	 */
	private function generateLocalResponse( string $message, array $context ): string {
		$parsed = $this->parser->parse( $message, $context );

		return match ( $parsed['intent'] ) {
			ResponseParser::INTENT_BROWSE_CATALOG => "I can help you browse our catalog! Type 'catalog' to see our products.",
			ResponseParser::INTENT_SEARCH_PRODUCT => 'What product are you looking for? Please describe what you need.',
			ResponseParser::INTENT_VIEW_CART => "Let me show you your cart. Type 'cart' to view items.",
			ResponseParser::INTENT_CHECKOUT => "Ready to checkout? Type 'checkout' to complete your order.",
			ResponseParser::INTENT_ORDER_STATUS => 'To check your order status, please provide your order number.',
			ResponseParser::INTENT_TRACK_SHIPPING => 'To track your shipment, please provide your order number.',
			ResponseParser::INTENT_REQUEST_SUPPORT => "I'm here to help! How can I assist you today?",
			default => "I'm here to help! You can browse products, check your cart, or track orders. What would you like to do?",
		};
	}

	/**
	 * Generate fallback response
	 *
	 * @param string               $message Customer message
	 * @param array<string, mixed> $context Conversation context
	 * @return string Fallback response
	 */
	private function generateFallbackResponse( string $message, array $context ): string {
		if ( $this->parser->isAffirmative( $message ) ) {
			return "Great! Let's proceed.";
		}

		if ( $this->parser->isNegative( $message ) ) {
			return 'No problem. Is there anything else I can help you with?';
		}

		return "I'm here to help! You can browse products, view your cart, or place an order. What would you like to do?";
	}

	/**
	 * Build system prompt for AI
	 *
	 * @param array<string, mixed> $context Conversation context
	 * @return string System prompt
	 */
	private function buildSystemPrompt( array $context ): string {
		$shopName = get_bloginfo( 'name' );

		$prompt  = "You are a helpful shopping assistant for {$shopName}, a WhatsApp commerce store. ";
		$prompt .= 'Be friendly, concise, and helpful. Focus on helping customers browse products, ';
		$prompt .= 'manage their cart, and complete orders. Keep responses under 160 characters when possible. ';

		if ( isset( $context['customer_name'] ) ) {
			$prompt .= "The customer's name is {$context['customer_name']}. ";
		}

		if ( isset( $context['cart_items'] ) && $context['cart_items'] > 0 ) {
			$prompt .= "The customer has {$context['cart_items']} items in their cart. ";
		}

		return $prompt;
	}

	/**
	 * Check if AI is enabled
	 *
	 * @return bool True if enabled
	 */
	private function isAiEnabled(): bool {
		return (bool) $this->settings->get( 'ai.enabled', false );
	}

	/**
	 * Summarize conversation
	 *
	 * @param array<int, array<string, string>> $messages Conversation messages
	 * @return string Conversation summary
	 */
	public function summarizeConversation( array $messages ): string {
		if ( empty( $messages ) ) {
			return 'No conversation yet.';
		}

		$intents = [];
		foreach ( $messages as $msg ) {
			if ( $msg['role'] === 'customer' ) {
				$parsed    = $this->parser->parse( $msg['content'] );
				$intents[] = $parsed['intent'];
			}
		}

		$uniqueIntents = array_unique( $intents );
		$intentSummary = implode( ', ', array_slice( $uniqueIntents, 0, 3 ) );

		return sprintf(
			'Conversation with %d messages. Topics: %s',
			count( $messages ),
			$intentSummary
		);
	}

	/**
	 * Extract action items from conversation
	 *
	 * @param array<int, array<string, string>> $messages Conversation messages
	 * @return array<int, string> Action items
	 */
	public function extractActionItems( array $messages ): array {
		$actions = [];

		foreach ( $messages as $msg ) {
			if ( $msg['role'] === 'customer' ) {
				$parsed = $this->parser->parse( $msg['content'] );

				if ( $parsed['intent'] === ResponseParser::INTENT_REQUEST_SUPPORT ) {
					$actions[] = 'Follow up with customer support';
				}

				if ( $parsed['intent'] === ResponseParser::INTENT_CANCEL_ORDER && ! empty( $parsed['entities']['order_id'] ) ) {
					$actions[] = 'Process order cancellation #' . $parsed['entities']['order_id'];
				}
			}
		}

		return array_unique( $actions );
	}
}
