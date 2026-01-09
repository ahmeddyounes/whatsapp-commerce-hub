<?php
/**
 * Intent Classifier Service
 *
 * NLP-based intent classification for free-text messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Services;

use WhatsAppCommerceHub\Contracts\Services\IntentClassifierInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\ValueObjects\Intent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntentClassifierService
 *
 * Classifies user messages into intents using rule-based and optional AI methods.
 */
class IntentClassifierService implements IntentClassifierInterface {

	/**
	 * Cache expiration time (1 hour in seconds).
	 */
	public const CACHE_EXPIRATION = 3600;

	/**
	 * Default confidence threshold.
	 */
	public const DEFAULT_CONFIDENCE_THRESHOLD = 0.7;

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	protected string $cacheGroup = 'wch_intent_classifier';

	/**
	 * Confidence threshold.
	 *
	 * @var float
	 */
	protected float $confidenceThreshold;

	/**
	 * Intent patterns for rule-based classification.
	 *
	 * @var array<string, array{regex: string, confidence: float}>
	 */
	protected array $patterns = array();

	/**
	 * Logger service.
	 *
	 * @var LoggerInterface|null
	 */
	protected ?LoggerInterface $logger;

	/**
	 * OpenAI API key.
	 *
	 * @var string
	 */
	protected string $openAiApiKey;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger      Logger service.
	 * @param string               $openAiApiKey OpenAI API key.
	 */
	public function __construct( ?LoggerInterface $logger = null, string $openAiApiKey = '' ) {
		$this->logger              = $logger;
		$this->openAiApiKey        = $openAiApiKey ?: get_option( 'wch_openai_api_key', '' );
		$this->confidenceThreshold = self::DEFAULT_CONFIDENCE_THRESHOLD;
		$this->initPatterns();
	}

	/**
	 * Initialize intent patterns.
	 *
	 * @return void
	 */
	protected function initPatterns(): void {
		$this->patterns = array(
			Intent::GREETING     => array(
				'regex'      => '/^(hi|hello|hey|good\s*(morning|afternoon|evening))/i',
				'confidence' => 0.95,
			),
			Intent::BROWSE       => array(
				'regex'      => '/(show|browse|see|view).*(products?|catalog|items?|collection)/i',
				'confidence' => 0.9,
			),
			Intent::SEARCH       => array(
				'regex'      => '/(search|find|looking for|want|need)\s+(.+)/i',
				'confidence' => 0.85,
			),
			Intent::VIEW_CART    => array(
				'regex'      => '/(my )?(cart|basket|bag)/i',
				'confidence' => 0.9,
			),
			Intent::CHECKOUT     => array(
				'regex'      => '/(checkout|buy|purchase|pay|order)/i',
				'confidence' => 0.9,
			),
			Intent::ORDER_STATUS => array(
				'regex'      => '/(order|track|where).*(status|order|package|delivery)/i',
				'confidence' => 0.85,
			),
			Intent::CANCEL       => array(
				'regex'      => '/(cancel|remove|delete)/i',
				'confidence' => 0.8,
			),
			Intent::HELP         => array(
				'regex'      => '/(help|support|assist|human|agent|person)/i',
				'confidence' => 0.9,
			),
			Intent::ADD_TO_CART  => array(
				'regex'      => '/(add|put).*(cart|basket|bag)/i',
				'confidence' => 0.9,
			),
			Intent::TRACK_ORDER  => array(
				'regex'      => '/(track|tracking|where.*(is|my).*order)/i',
				'confidence' => 0.85,
			),
			Intent::HUMAN_AGENT  => array(
				'regex'      => '/(human|agent|person|representative|speak.*to)/i',
				'confidence' => 0.9,
			),
		);

		/**
		 * Filter to register custom intents.
		 *
		 * @param array $patterns Pattern configuration.
		 */
		$customPatterns = apply_filters( 'wch_custom_intents', array() );
		if ( is_array( $customPatterns ) ) {
			$this->patterns = array_merge( $this->patterns, $customPatterns );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function classify( string $text, array $context = array() ): Intent {
		// Check cache first.
		$cacheKey     = $this->getCacheKey( $text, $context );
		$cachedResult = wp_cache_get( $cacheKey, $this->cacheGroup );

		if ( false !== $cachedResult && is_array( $cachedResult ) ) {
			$this->log( 'debug', 'Intent classification from cache', array(
				'text'   => $text,
				'intent' => $cachedResult['intent_name'] ?? 'unknown',
			) );

			return Intent::fromArray( $cachedResult );
		}

		// Rule-based classification first.
		$intent = $this->classifyWithRules( $text, $context );

		// If confidence is low and AI is available, try AI.
		if ( $intent->getConfidence() < $this->confidenceThreshold && $this->isAiAvailable() ) {
			$aiIntent = $this->classifyWithAi( $text, $context );
			if ( $aiIntent->getConfidence() > $intent->getConfidence() ) {
				$intent = $aiIntent;
			}
		}

		// Cache the result.
		wp_cache_set( $cacheKey, $intent->toArray(), $this->cacheGroup, self::CACHE_EXPIRATION );

		// Log the classification.
		$this->logClassification( $text, $intent, $context );

		return $intent;
	}

	/**
	 * {@inheritdoc}
	 */
	public function classifyWithAi( string $text, array $context = array() ): Intent {
		if ( '' === $this->openAiApiKey ) {
			return Intent::unknown();
		}

		// Build context string.
		$contextStr = '';
		if ( isset( $context['current_state'] ) ) {
			$contextStr = "\nCurrent conversation state: " . $context['current_state'];
		}

		// Prepare prompt.
		$validIntents = Intent::getValidIntents();
		$intentsList  = implode( ', ', $validIntents );
		$prompt       = sprintf(
			"Classify the following user message into one of these intents: %s.\n\nUser message: \"%s\"%s\n\nRespond with only the intent name and confidence (0-1) in JSON format: {\"intent\": \"INTENT_NAME\", \"confidence\": 0.9}",
			$intentsList,
			$text,
			$contextStr
		);

		// Make API request.
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->openAiApiKey,
				),
				'body'    => wp_json_encode( array(
					'model'       => 'gpt-3.5-turbo',
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'You are an intent classifier for a WhatsApp commerce chatbot. Respond only with valid JSON.',
						),
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'temperature' => 0.3,
					'max_tokens'  => 100,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'AI classification failed', array(
				'error' => $response->get_error_message(),
			) );
			return Intent::unknown();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			return Intent::unknown();
		}

		// Parse AI response.
		$aiResponse = json_decode( $body['choices'][0]['message']['content'], true );
		if ( ! is_array( $aiResponse ) || ! isset( $aiResponse['intent'], $aiResponse['confidence'] ) ) {
			return Intent::unknown();
		}

		// Validate intent.
		if ( ! Intent::isValid( $aiResponse['intent'] ) ) {
			return Intent::unknown();
		}

		// Extract entities using rule-based method.
		$entities = $this->extractEntities( $text );

		return new Intent(
			$aiResponse['intent'],
			(float) $aiResponse['confidence'],
			$entities
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function extractEntities( string $text ): array {
		$entities = array();

		// Extract quantity.
		$quantityPattern = '/(\d+)\s*(pieces?|items?|units?|pcs?|x)?/i';
		if ( preg_match_all( $quantityPattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$quantity = (int) $match[0];
				if ( $quantity > 0 && $quantity < 1000 ) {
					$entities[] = array(
						'type'     => 'QUANTITY',
						'value'    => $quantity,
						'position' => $match[1],
					);
				}
			}
		}

		// Extract phone number.
		$phonePattern = '/(\+?[\d\s\-\(\)]{10,})/';
		if ( preg_match( $phonePattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$phone = preg_replace( '/[^\d+]/', '', $matches[1][0] );
			if ( strlen( $phone ) >= 10 ) {
				$entities[] = array(
					'type'     => 'PHONE',
					'value'    => $phone,
					'position' => $matches[1][1],
				);
			}
		}

		// Extract email.
		$emailPattern = '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/';
		if ( preg_match( $emailPattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$entities[] = array(
				'type'     => 'EMAIL',
				'value'    => $matches[1][0],
				'position' => $matches[1][1],
			);
		}

		// Extract order number.
		if ( preg_match( '/#(\d{4,})/', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$entities[] = array(
				'type'     => 'ORDER_NUMBER',
				'value'    => $matches[1][0],
				'position' => $matches[0][1],
			);
		}

		// Extract address.
		$addressPattern = '/(street|st|avenue|ave|road|rd|boulevard|blvd|lane|ln|drive|dr|court|ct|way|place|pl)[\s,]+[a-zA-Z0-9\s,.-]+/i';
		if ( preg_match( $addressPattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$entities[] = array(
				'type'     => 'ADDRESS',
				'value'    => trim( $matches[0][0] ),
				'position' => $matches[0][1],
			);
		}

		return $entities;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAiAvailable(): bool {
		return '' !== $this->openAiApiKey;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getConfidenceThreshold(): float {
		return $this->confidenceThreshold;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setConfidenceThreshold( float $threshold ): void {
		$this->confidenceThreshold = max( 0.0, min( 1.0, $threshold ) );
	}

	/**
	 * Classify using rule-based patterns.
	 *
	 * @param string $text    User message text.
	 * @param array  $context Context data.
	 * @return Intent
	 */
	protected function classifyWithRules( string $text, array $context ): Intent {
		$text           = trim( $text );
		$matchedIntent  = null;
		$maxConfidence  = 0.0;
		$entities       = array();

		foreach ( $this->patterns as $intentName => $patternData ) {
			if ( preg_match( $patternData['regex'], $text, $matches ) ) {
				if ( $patternData['confidence'] > $maxConfidence ) {
					$maxConfidence = $patternData['confidence'];
					$matchedIntent = $intentName;

					// Extract entities for specific intents.
					if ( Intent::SEARCH === $intentName && isset( $matches[2] ) ) {
						$entities[] = array(
							'type'     => 'PRODUCT_NAME',
							'value'    => trim( $matches[2] ),
							'position' => strpos( $text, $matches[2] ),
						);
					}
				}
			}
		}

		if ( null === $matchedIntent ) {
			$matchedIntent = Intent::UNKNOWN;
			$maxConfidence = 0.3;
		}

		// Merge with common extracted entities.
		$entities = array_merge( $entities, $this->extractEntities( $text ) );

		return new Intent( $matchedIntent, $maxConfidence, $entities );
	}

	/**
	 * Get cache key for classification.
	 *
	 * @param string $text    User message text.
	 * @param array  $context Context data.
	 * @return string
	 */
	protected function getCacheKey( string $text, array $context ): string {
		$hashData = array(
			'text'    => strtolower( trim( $text ) ),
			'context' => $context['current_state'] ?? '',
		);
		return 'intent_' . md5( wp_json_encode( $hashData ) );
	}

	/**
	 * Log classification.
	 *
	 * @param string $text    Text classified.
	 * @param Intent $intent  Classified intent.
	 * @param array  $context Context.
	 * @return void
	 */
	protected function logClassification( string $text, Intent $intent, array $context ): void {
		$this->log( 'info', 'Intent classified', array(
			'text'       => $text,
			'intent'     => $intent->getName(),
			'confidence' => $intent->getConfidence(),
			'entities'   => $intent->getEntities(),
			'context'    => $context['current_state'] ?? null,
		) );
	}

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $data    Context data.
	 * @return void
	 */
	protected function log( string $level, string $message, array $data = array() ): void {
		if ( null !== $this->logger ) {
			$this->logger->log( $level, $message, 'intent_classifier', $data );
			return;
		}

		// Fallback to legacy logger.
		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $data, $level );
		}
	}

	/**
	 * Add custom pattern.
	 *
	 * @param string $intent     Intent name.
	 * @param string $regex      Regex pattern.
	 * @param float  $confidence Confidence score.
	 * @return void
	 */
	public function addPattern( string $intent, string $regex, float $confidence = 0.9 ): void {
		$this->patterns[ $intent ] = array(
			'regex'      => $regex,
			'confidence' => $confidence,
		);
	}

	/**
	 * Set OpenAI API key.
	 *
	 * @param string $apiKey API key.
	 * @return void
	 */
	public function setOpenAiApiKey( string $apiKey ): void {
		$this->openAiApiKey = $apiKey;
	}

	/**
	 * Clear classification cache.
	 *
	 * @return void
	 */
	public function clearCache(): void {
		wp_cache_flush_group( $this->cacheGroup );
	}

	/**
	 * Get statistics.
	 *
	 * @return array
	 */
	public function getStatistics(): array {
		return array(
			'patterns_count'   => count( $this->patterns ),
			'ai_enabled'       => $this->isAiAvailable(),
			'cache_expiration' => self::CACHE_EXPIRATION,
			'threshold'        => $this->confidenceThreshold,
		);
	}
}
