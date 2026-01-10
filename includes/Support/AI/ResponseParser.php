<?php
/**
 * Response Parser
 *
 * Parses customer responses and extracts intent, entities, and context.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Support\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Response Parser Class
 *
 * Natural language processing for customer messages.
 */
class ResponseParser
{
    /**
     * Intent constants
     */
    public const INTENT_UNKNOWN = 'unknown';
    public const INTENT_BROWSE_CATALOG = 'browse_catalog';
    public const INTENT_SEARCH_PRODUCT = 'search_product';
    public const INTENT_VIEW_PRODUCT = 'view_product';
    public const INTENT_ADD_TO_CART = 'add_to_cart';
    public const INTENT_VIEW_CART = 'view_cart';
    public const INTENT_MODIFY_CART = 'modify_cart';
    public const INTENT_CHECKOUT = 'checkout';
    public const INTENT_ORDER_STATUS = 'order_status';
    public const INTENT_TRACK_SHIPPING = 'track_shipping';
    public const INTENT_REQUEST_SUPPORT = 'request_support';
    public const INTENT_CANCEL_ORDER = 'cancel_order';

    /**
     * Intent keyword mappings
     *
     * @var array<string, array<int, string>>
     */
    private array $intentKeywords = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->initIntentKeywords();
    }

    /**
     * Initialize intent keyword mappings
     */
    private function initIntentKeywords(): void
    {
        $this->intentKeywords = [
            self::INTENT_TRACK_SHIPPING => [
                'track my shipment',
                'track my order',
                'where is my shipment',
                'track',
                'tracking',
                'shipment',
                'delivery status',
                'shipping status',
            ],
            self::INTENT_ORDER_STATUS => [
                'order status',
                'my order',
                'where is my order',
                'check order',
                'order',
            ],
            self::INTENT_CHECKOUT => [
                'checkout',
                'complete order',
                'place order',
                'proceed to checkout',
                'pay now',
                'payment',
                'pay',
            ],
            self::INTENT_VIEW_CART => [
                'view cart',
                'show cart',
                'my cart',
                'shopping cart',
                'cart',
                'basket',
                'bag',
            ],
            self::INTENT_MODIFY_CART => [
                'remove from cart',
                'delete from cart',
                'update cart',
                'change quantity',
                'clear cart',
                'empty cart',
            ],
            self::INTENT_ADD_TO_CART => [
                'add to cart',
                'buy',
                'purchase',
                'i want',
                'get me',
            ],
            self::INTENT_SEARCH_PRODUCT => [
                'search',
                'find',
                'looking for',
                'show me',
                'do you have',
            ],
            self::INTENT_BROWSE_CATALOG => [
                'browse',
                'catalog',
                'products',
                'shop',
                'categories',
                'what do you sell',
            ],
            self::INTENT_CANCEL_ORDER => [
                'cancel order',
                'cancel my order',
                'dont want',
                'refund',
            ],
            self::INTENT_REQUEST_SUPPORT => [
                'help',
                'support',
                'assistance',
                'problem',
                'issue',
                'speak to agent',
                'human',
            ],
        ];
    }

    /**
     * Parse customer message
     *
     * @param string $message Customer message
     * @param array<string, mixed> $context Conversation context
     * @return array<string, mixed> Parsed response with intent and entities
     */
    public function parse(string $message, array $context = []): array
    {
        $normalized = $this->normalizeText($message);

        return [
            'intent' => $this->detectIntent($normalized, $context),
            'entities' => $this->extractEntities($normalized),
            'confidence' => $this->calculateConfidence($normalized),
            'original_message' => $message,
        ];
    }

    /**
     * Detect intent from message
     *
     * @param string $normalized Normalized message
     * @param array<string, mixed> $context Conversation context
     * @return string Detected intent
     */
    private function detectIntent(string $normalized, array $context): string
    {
        // Check for exact keyword matches
        foreach ($this->intentKeywords as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, strtolower($keyword))) {
                    return $intent;
                }
            }
        }

        // Use context to infer intent
        if (isset($context['awaiting_response'])) {
            return $context['awaiting_response'];
        }

        return self::INTENT_UNKNOWN;
    }

    /**
     * Extract entities from message
     *
     * @param string $normalized Normalized message
     * @return array<string, mixed> Extracted entities
     */
    private function extractEntities(string $normalized): array
    {
        $entities = [];

        // Extract product ID
        if (preg_match('/product[:\s#]*(\d+)/i', $normalized, $matches)) {
            $entities['product_id'] = (int) $matches[1];
        }

        // Extract order number
        if (preg_match('/order[:\s#]*(\d+)/i', $normalized, $matches)) {
            $entities['order_id'] = (int) $matches[1];
        }

        // Extract quantity
        if (preg_match('/(\d+)\s*(piece|item|unit|qty)/i', $normalized, $matches)) {
            $entities['quantity'] = (int) $matches[1];
        }

        // Extract phone number
        if (preg_match('/\+?\d{10,15}/', $normalized, $matches)) {
            $entities['phone'] = $matches[0];
        }

        // Extract email
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $normalized, $matches)) {
            $entities['email'] = $matches[0];
        }

        return $entities;
    }

    /**
     * Calculate confidence score
     *
     * @param string $normalized Normalized message
     * @return float Confidence score (0-1)
     */
    private function calculateConfidence(string $normalized): float
    {
        // Simple confidence based on message length and clarity
        $wordCount = str_word_count($normalized);

        if ($wordCount < 2) {
            return 0.5;
        }

        if ($wordCount > 10) {
            return 0.7;
        }

        return 0.8;
    }

    /**
     * Normalize text for processing
     *
     * @param string $text Input text
     * @return string Normalized text
     */
    private function normalizeText(string $text): string
    {
        $text = strtolower($text);
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }

    /**
     * Check if message is affirmative (yes/confirm)
     *
     * @param string $message Message to check
     * @return bool True if affirmative
     */
    public function isAffirmative(string $message): bool
    {
        $affirmative = ['yes', 'yeah', 'yep', 'sure', 'ok', 'okay', 'confirm', 'correct', 'right', 'yup'];
        $normalized = $this->normalizeText($message);

        foreach ($affirmative as $word) {
            if ($word === $normalized || str_starts_with($normalized, $word . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is negative (no/cancel)
     *
     * @param string $message Message to check
     * @return bool True if negative
     */
    public function isNegative(string $message): bool
    {
        $negative = ['no', 'nope', 'nah', 'cancel', 'stop', 'quit', 'exit', 'never mind', 'wrong'];
        $normalized = $this->normalizeText($message);

        foreach ($negative as $word) {
            if ($word === $normalized || str_starts_with($normalized, $word . ' ')) {
                return true;
            }
        }

        return false;
    }
}
