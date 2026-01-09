<?php
/**
 * Response Parser Interface
 *
 * Contract for parsing WhatsApp webhook messages.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

use WhatsAppCommerceHub\ValueObjects\ParsedResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ResponseParserInterface
 *
 * Defines the contract for parsing WhatsApp messages.
 */
interface ResponseParserInterface {

	/**
	 * Parse webhook message data.
	 *
	 * @param array $webhookMessageData Webhook message data from WhatsApp.
	 * @return ParsedResponse Parsed response object.
	 */
	public function parse( array $webhookMessageData ): ParsedResponse;

	/**
	 * Detect intent from text.
	 *
	 * @param string $text Text to analyze.
	 * @return string Detected intent constant.
	 */
	public function detectIntent( string $text ): string;

	/**
	 * Get all available intents.
	 *
	 * @return string[] Array of intent constants.
	 */
	public function getAvailableIntents(): array;
}
