<?php
/**
 * Intent Classifier Interface
 *
 * Contract for classifying user intents.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

use WhatsAppCommerceHub\ValueObjects\Intent;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface IntentClassifierInterface
 *
 * Defines the contract for intent classification.
 */
interface IntentClassifierInterface {

	/**
	 * Classify text input into an intent.
	 *
	 * @param string $text    User input text.
	 * @param array  $context Optional conversation context.
	 * @return Intent Classified intent.
	 */
	public function classify( string $text, array $context = array() ): Intent;

	/**
	 * Classify with AI enhancement.
	 *
	 * @param string $text    User input text.
	 * @param array  $context Conversation context.
	 * @return Intent Classified intent.
	 */
	public function classifyWithAi( string $text, array $context = array() ): Intent;

	/**
	 * Extract entities from text.
	 *
	 * @param string $text User input text.
	 * @return array Extracted entities.
	 */
	public function extractEntities( string $text ): array;

	/**
	 * Check if AI classification is available.
	 *
	 * @return bool
	 */
	public function isAiAvailable(): bool;

	/**
	 * Get confidence threshold.
	 *
	 * @return float
	 */
	public function getConfidenceThreshold(): float;

	/**
	 * Set confidence threshold.
	 *
	 * @param float $threshold Threshold value (0-1).
	 * @return void
	 */
	public function setConfidenceThreshold( float $threshold ): void;
}
