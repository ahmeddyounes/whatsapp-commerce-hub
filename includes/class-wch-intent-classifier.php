<?php
/**
 * Intent Classifier Class
 *
 * NLP-based intent classification for free-text messages.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Intent_Classifier
 *
 * Classifies user messages into intents using rule-based and optional AI methods.
 */
class WCH_Intent_Classifier {
	/**
	 * Cache expiration time (1 hour in seconds).
	 */
	const CACHE_EXPIRATION = 3600;

	/**
	 * AI confidence threshold for fallback.
	 */
	const AI_CONFIDENCE_THRESHOLD = 0.7;

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	private $cache_group = 'wch_intent_classifier';

	/**
	 * Intent patterns for rule-based classification.
	 *
	 * @var array
	 */
	private $patterns;

	/**
	 * Custom intents from filter.
	 *
	 * @var array
	 */
	private $custom_intents = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_patterns();
		$this->load_custom_intents();
	}

	/**
	 * Initialize intent patterns.
	 */
	private function init_patterns() {
		$this->patterns = array(
			WCH_Intent::INTENT_GREETING     => array(
				'regex'      => '/^(hi|hello|hey|good\s*(morning|afternoon|evening))/i',
				'confidence' => 0.95,
			),
			WCH_Intent::INTENT_BROWSE       => array(
				'regex'      => '/(show|browse|see|view).*(products?|catalog|items?|collection)/i',
				'confidence' => 0.9,
			),
			WCH_Intent::INTENT_SEARCH       => array(
				'regex'      => '/(search|find|looking for|want|need)\s+(.+)/i',
				'confidence' => 0.85,
			),
			WCH_Intent::INTENT_VIEW_CART    => array(
				'regex'      => '/(my )?(cart|basket|bag)/i',
				'confidence' => 0.9,
			),
			WCH_Intent::INTENT_CHECKOUT     => array(
				'regex'      => '/(checkout|buy|purchase|pay|order)/i',
				'confidence' => 0.9,
			),
			WCH_Intent::INTENT_ORDER_STATUS => array(
				'regex'      => '/(order|track|where).*(status|order|package|delivery)/i',
				'confidence' => 0.85,
			),
			WCH_Intent::INTENT_CANCEL       => array(
				'regex'      => '/(cancel|remove|delete)/i',
				'confidence' => 0.8,
			),
			WCH_Intent::INTENT_HELP         => array(
				'regex'      => '/(help|support|assist|human|agent|person)/i',
				'confidence' => 0.9,
			),
		);
	}

	/**
	 * Load custom intents from filter.
	 */
	private function load_custom_intents() {
		/**
		 * Filter to register business-specific intents.
		 *
		 * @param array $custom_intents Array of custom intents with pattern and confidence.
		 *
		 * Example:
		 * array(
		 *     'CUSTOM_REFUND' => array(
		 *         'regex'      => '/(refund|money back)/i',
		 *         'confidence' => 0.9,
		 *     ),
		 * )
		 */
		$this->custom_intents = apply_filters( 'wch_custom_intents', array() );

		// Merge custom intents with default patterns
		if ( ! empty( $this->custom_intents ) && is_array( $this->custom_intents ) ) {
			$this->patterns = array_merge( $this->patterns, $this->custom_intents );
		}
	}

	/**
	 * Classify intent from text.
	 *
	 * @param string $text    User message text.
	 * @param array  $context Optional context for classification.
	 * @return WCH_Intent Intent object.
	 */
	public function classify( $text, $context = array() ) {
		// Check cache first
		$cache_key     = $this->get_cache_key( $text, $context );
		$cached_result = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached_result ) {
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::log(
					'Intent classification from cache',
					array(
						'text'   => $text,
						'intent' => $cached_result['intent_name'],
					),
					'debug'
				);
			}
			return new WCH_Intent(
				$cached_result['intent_name'],
				$cached_result['confidence'],
				$cached_result['entities']
			);
		}

		// Try rule-based classification first
		$intent = $this->classify_with_rules( $text, $context );

		// If confidence is low and AI is enabled, try AI classification
		if ( $intent->confidence < self::AI_CONFIDENCE_THRESHOLD && $this->is_ai_enabled() ) {
			$ai_intent = $this->classify_with_ai( $text, $context );
			if ( $ai_intent && $ai_intent->confidence > $intent->confidence ) {
				$intent = $ai_intent;
			}
		}

		// Cache the result
		wp_cache_set(
			$cache_key,
			$intent->to_array(),
			$this->cache_group,
			self::CACHE_EXPIRATION
		);

		// Log the classification
		$this->log_classification( $text, $intent, $context );

		return $intent;
	}

	/**
	 * Classify using rule-based patterns.
	 *
	 * @param string $text    User message text.
	 * @param array  $context Context data.
	 * @return WCH_Intent Intent object.
	 */
	private function classify_with_rules( $text, $context ) {
		$text           = trim( $text );
		$matched_intent = null;
		$max_confidence = 0.0;
		$entities       = array();

		// Try to match each pattern
		foreach ( $this->patterns as $intent_name => $pattern_data ) {
			if ( preg_match( $pattern_data['regex'], $text, $matches ) ) {
				if ( $pattern_data['confidence'] > $max_confidence ) {
					$max_confidence = $pattern_data['confidence'];
					$matched_intent = $intent_name;

					// Extract entities for specific intents
					switch ( $intent_name ) {
						case WCH_Intent::INTENT_SEARCH:
							// Extract search term from match
							if ( isset( $matches[2] ) ) {
								$entities[] = array(
									'type'     => 'PRODUCT_NAME',
									'value'    => trim( $matches[2] ),
									'position' => strpos( $text, $matches[2] ),
								);
							}
							break;

						case WCH_Intent::INTENT_ORDER_STATUS:
							// Try to extract order number
							if ( preg_match( '/#(\d{4,})/', $text, $order_matches ) ) {
								$entities[] = array(
									'type'     => 'ORDER_NUMBER',
									'value'    => $order_matches[1],
									'position' => strpos( $text, $order_matches[0] ),
								);
							}
							break;
					}
				}
			}
		}

		// If no intent matched, return UNKNOWN
		if ( null === $matched_intent ) {
			$matched_intent = WCH_Intent::INTENT_UNKNOWN;
			$max_confidence = 0.3; // Low confidence for unknown
		}

		// Extract common entities from text
		$common_entities = $this->extract_entities( $text );
		$entities        = array_merge( $entities, $common_entities );

		return new WCH_Intent( $matched_intent, $max_confidence, $entities );
	}

	/**
	 * Extract entities from text.
	 *
	 * @param string $text User message text.
	 * @return array Array of entities.
	 */
	private function extract_entities( $text ) {
		$entities = array();

		// Extract quantity
		$quantity_pattern = '/(\d+)\s*(pieces?|items?|units?|pcs?|x)?/i';
		if ( preg_match_all( $quantity_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$quantity = (int) $match[0];
				if ( $quantity > 0 && $quantity < 1000 ) { // Reasonable quantity range
					$entities[] = array(
						'type'     => 'QUANTITY',
						'value'    => $quantity,
						'position' => $match[1],
					);
				}
			}
		}

		// Extract phone number
		$phone_pattern = '/(\+?[\d\s\-\(\)]{10,})/';
		if ( preg_match( $phone_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$phone = preg_replace( '/[^\d+]/', '', $matches[1][0] );
			if ( strlen( $phone ) >= 10 ) {
				$entities[] = array(
					'type'     => 'PHONE',
					'value'    => $phone,
					'position' => $matches[1][1],
				);
			}
		}

		// Extract email
		$email_pattern = '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/';
		if ( preg_match( $email_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$entities[] = array(
				'type'     => 'EMAIL',
				'value'    => $matches[1][0],
				'position' => $matches[1][1],
			);
		}

		// Extract address (heuristic - look for common address keywords)
		$address_pattern = '/(street|st|avenue|ave|road|rd|boulevard|blvd|lane|ln|drive|dr|court|ct|way|place|pl)[\s,]+[a-zA-Z0-9\s,.-]+/i';
		if ( preg_match( $address_pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$entities[] = array(
				'type'     => 'ADDRESS',
				'value'    => trim( $matches[0][0] ),
				'position' => $matches[0][1],
			);
		}

		return $entities;
	}

	/**
	 * Check if AI classification is enabled.
	 *
	 * @return bool True if AI is enabled.
	 */
	private function is_ai_enabled() {
		// Check if OpenAI API key is configured
		$api_key = get_option( 'wch_openai_api_key', '' );
		return ! empty( $api_key );
	}

	/**
	 * Classify using AI (OpenAI).
	 *
	 * @param string $text    User message text.
	 * @param array  $context Context data.
	 * @return WCH_Intent|null Intent object or null on failure.
	 */
	private function classify_with_ai( $text, $context ) {
		$api_key = get_option( 'wch_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			return null;
		}

		// Build context string
		$context_str = '';
		if ( ! empty( $context ) && isset( $context['current_state'] ) ) {
			$context_str = "\nCurrent conversation state: " . $context['current_state'];
		}

		// Prepare the prompt
		$valid_intents = WCH_Intent::get_valid_intents();
		$intents_list  = implode( ', ', $valid_intents );
		$prompt        = sprintf(
			"Classify the following user message into one of these intents: %s.\n\nUser message: \"%s\"%s\n\nRespond with only the intent name and confidence (0-1) in JSON format: {\"intent\": \"INTENT_NAME\", \"confidence\": 0.9}",
			$intents_list,
			$text,
			$context_str
		);

		// Make API request
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
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
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::log(
					'AI classification failed',
					array(
						'error' => $response->get_error_message(),
					),
					'error'
				);
			}
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			return null;
		}

		// Parse AI response
		$ai_response = json_decode( $body['choices'][0]['message']['content'], true );
		if ( ! isset( $ai_response['intent'] ) || ! isset( $ai_response['confidence'] ) ) {
			return null;
		}

		// Validate intent
		if ( ! WCH_Intent::is_valid_intent( $ai_response['intent'] ) ) {
			return null;
		}

		// Extract entities using rule-based method
		$entities = $this->extract_entities( $text );

		return new WCH_Intent(
			$ai_response['intent'],
			(float) $ai_response['confidence'],
			$entities
		);
	}

	/**
	 * Get cache key for classification.
	 *
	 * @param string $text    User message text.
	 * @param array  $context Context data.
	 * @return string Cache key.
	 */
	private function get_cache_key( $text, $context ) {
		$hash_data = array(
			'text'    => strtolower( trim( $text ) ),
			'context' => isset( $context['current_state'] ) ? $context['current_state'] : '',
		);
		return 'intent_' . md5( wp_json_encode( $hash_data ) );
	}

	/**
	 * Log classification for training data collection.
	 *
	 * @param string     $text    User message text.
	 * @param WCH_Intent $intent  Classified intent.
	 * @param array      $context Context data.
	 */
	private function log_classification( $text, $intent, $context ) {
		if ( ! class_exists( 'WCH_Logger' ) ) {
			return;
		}

		WCH_Logger::log(
			'Intent classified',
			array(
				'text'       => $text,
				'intent'     => $intent->intent_name,
				'confidence' => $intent->confidence,
				'entities'   => $intent->entities,
				'context'    => isset( $context['current_state'] ) ? $context['current_state'] : null,
				'timestamp'  => current_time( 'mysql' ),
			),
			'info'
		);
	}

	/**
	 * Clear classification cache.
	 */
	public function clear_cache() {
		wp_cache_flush_group( $this->cache_group );
	}

	/**
	 * Get classification statistics.
	 *
	 * Can be used for monitoring and improving accuracy.
	 *
	 * @return array Statistics.
	 */
	public function get_statistics() {
		// This would require persistent storage of classification results
		// For now, return basic info
		return array(
			'patterns_count'       => count( $this->patterns ),
			'custom_intents_count' => count( $this->custom_intents ),
			'ai_enabled'           => $this->is_ai_enabled(),
			'cache_expiration'     => self::CACHE_EXPIRATION,
		);
	}
}
