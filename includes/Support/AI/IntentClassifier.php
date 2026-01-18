<?php
/**
 * Intent Classifier
 *
 * AI service for classifying user intent from messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Support\AI;

use WhatsAppCommerceHub\ValueObjects\Intent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntentClassifier
 *
 * Classifies user messages into intents using pattern matching.
 * Uses canonical ValueObjects\Intent for all intent representations.
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
		Intent::GREETING     => [ '/^(hi|hello|hey|good\s+(morning|afternoon|evening))/i' ],
		Intent::BROWSE       => [ '/^(browse|show|list|catalog|products)/i' ],
		Intent::SEARCH       => [ '/^(search|find|looking for)/i' ],
		Intent::VIEW_CART    => [ '/^(cart|basket|my cart)/i' ],
		Intent::CHECKOUT     => [ '/^(checkout|buy|purchase|order)/i' ],
		Intent::ORDER_STATUS => [ '/^(order|status|where|track)/i' ],
		Intent::HELP         => [ '/^(help|support|assist)/i' ],
		Intent::CANCEL       => [ '/^(cancel|stop|nevermind)/i' ],
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
						$this->convertEntitiesToStructuredFormat( $this->extractEntities( $message ) )
					);
				}
			}
		}

		// Fallback to unknown intent.
		return new Intent(
			Intent::UNKNOWN,
			0.5,
			[]
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
	 * Convert entities from associative array to structured format.
	 *
	 * @param array $entities Entities as associative array.
	 * @return array<int, array{type: string, value: mixed, position?: int}>
	 */
	private function convertEntitiesToStructuredFormat( array $entities ): array {
		$structured = [];

		foreach ( $entities as $type => $value ) {
			if ( is_array( $value ) ) {
				// Handle multiple values (e.g., numbers).
				foreach ( $value as $val ) {
					$structured[] = [
						'type'  => $type,
						'value' => $val,
					];
				}
			} else {
				// Handle single value.
				$structured[] = [
					'type'  => $type,
					'value' => $value,
				];
			}
		}

		return $structured;
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
