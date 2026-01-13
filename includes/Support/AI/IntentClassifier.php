<?php
/**
 * Intent Classifier
 *
 * AI service for classifying user intent from messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Support\AI;

use WhatsAppCommerceHub\Domain\Conversation\Intent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntentClassifier
 *
 * Classifies user messages into intents using pattern matching and AI.
 *
 * Note: This is a transitional class. Full migration will integrate
 * with proper AI services in a future phase.
 */
class IntentClassifier {
	/**
	 * Confidence threshold for intent classification.
	 */
	private const CONFIDENCE_THRESHOLD = 0.7;

	/**
	 * Pattern-based intent rules.
	 */
	private array $patterns = [
		Intent::INTENT_GREETING     => [ '/^(hi|hello|hey|good\s+(morning|afternoon|evening))/i' ],
		Intent::INTENT_BROWSE       => [ '/^(browse|show|list|catalog|products)/i' ],
		Intent::INTENT_SEARCH       => [ '/^(search|find|looking for)/i' ],
		Intent::INTENT_VIEW_CART    => [ '/^(cart|basket|my cart)/i' ],
		Intent::INTENT_CHECKOUT     => [ '/^(checkout|buy|purchase|order)/i' ],
		Intent::INTENT_ORDER_STATUS => [ '/^(order|status|where|track)/i' ],
		Intent::INTENT_HELP         => [ '/^(help|support|assist)/i' ],
		Intent::INTENT_CANCEL       => [ '/^(cancel|stop|nevermind)/i' ],
	];

	/**
	 * Classify message into intent.
	 *
	 * @param string $message User message.
	 * @param array  $context Optional conversation context.
	 * @return Intent
	 */
	public function classify( string $message, array $context = [] ): Intent {
		$message = trim( $message );

		// Try pattern matching first.
		foreach ( $this->patterns as $intentName => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $message ) ) {
					return new Intent(
						$intentName,
						0.9,
						$this->extractEntities( $message ),
						[ 'method' => 'pattern_matching' ]
					);
				}
			}
		}

		// Fallback to unknown intent.
		return new Intent(
			Intent::INTENT_UNKNOWN,
			0.5,
			[],
			[ 'method' => 'fallback' ]
		);
	}

	/**
	 * Extract entities from message.
	 *
	 * @param string $message User message.
	 * @return array
	 */
	private function extractEntities( string $message ): array {
		$entities = [];

		// Extract numbers (potential product IDs, quantities).
		if ( preg_match_all( '/\b\d+\b/', $message, $matches ) ) {
			$entities['numbers'] = $matches[0];
		}

		// Extract email addresses.
		if ( preg_match( '/[\w\.-]+@[\w\.-]+\.\w+/', $message, $matches ) ) {
			$entities['email'] = $matches[0];
		}

		// Extract phone numbers (basic pattern).
		if ( preg_match( '/\b\d{10,}\b/', $message, $matches ) ) {
			$entities['phone'] = $matches[0];
		}

		return $entities;
	}

	/**
	 * Get confidence threshold.
	 *
	 * @return float
	 */
	public function getConfidenceThreshold(): float {
		return self::CONFIDENCE_THRESHOLD;
	}
}
