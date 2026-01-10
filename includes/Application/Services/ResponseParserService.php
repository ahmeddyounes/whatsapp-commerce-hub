<?php
/**
 * Response Parser Service
 *
 * Parses incoming WhatsApp message responses and extracts structured data.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\ResponseParserInterface;
use WhatsAppCommerceHub\ValueObjects\ParsedResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ResponseParserService
 *
 * Handles parsing of WhatsApp webhook messages and detecting user intents.
 */
class ResponseParserService implements ResponseParserInterface {

	/**
	 * Intent constants.
	 */
	public const INTENT_GREETING       = 'GREETING';
	public const INTENT_BROWSE_CATALOG = 'BROWSE_CATALOG';
	public const INTENT_VIEW_CATEGORY  = 'VIEW_CATEGORY';
	public const INTENT_SEARCH_PRODUCT = 'SEARCH_PRODUCT';
	public const INTENT_VIEW_PRODUCT   = 'VIEW_PRODUCT';
	public const INTENT_ADD_TO_CART    = 'ADD_TO_CART';
	public const INTENT_VIEW_CART      = 'VIEW_CART';
	public const INTENT_MODIFY_CART    = 'MODIFY_CART';
	public const INTENT_CHECKOUT       = 'CHECKOUT';
	public const INTENT_APPLY_COUPON   = 'APPLY_COUPON';
	public const INTENT_ORDER_STATUS   = 'ORDER_STATUS';
	public const INTENT_TRACK_SHIPPING = 'TRACK_SHIPPING';
	public const INTENT_HELP           = 'HELP';
	public const INTENT_TALK_TO_HUMAN  = 'TALK_TO_HUMAN';
	public const INTENT_UNKNOWN        = 'UNKNOWN';

	/**
	 * Intent keyword mappings.
	 *
	 * @var array<string, string[]>
	 */
	protected array $intentKeywords = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->initIntentKeywords();
	}

	/**
	 * Initialize intent keyword mappings.
	 *
	 * @return void
	 */
	protected function initIntentKeywords(): void {
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
			self::INTENT_ORDER_STATUS   => [
				'order status',
				'my order',
				'where is my order',
				'check order',
				'order',
			],
			self::INTENT_CHECKOUT       => [
				'checkout',
				'complete order',
				'place order',
				'proceed to checkout',
				'pay now',
				'payment',
				'pay',
			],
			self::INTENT_VIEW_CART      => [
				'view cart',
				'show cart',
				'my cart',
				'shopping cart',
				'cart',
				'basket',
				'bag',
			],
			self::INTENT_MODIFY_CART    => [
				'remove from cart',
				'delete from cart',
				'update cart',
				'change quantity',
				'modify cart',
			],
			self::INTENT_ADD_TO_CART    => [
				'add to cart',
				'add this',
				'buy this',
				'purchase this',
				'want this',
			],
			self::INTENT_APPLY_COUPON   => [
				'apply coupon',
				'use coupon',
				'promo code',
				'discount code',
				'coupon',
				'voucher',
			],
			self::INTENT_BROWSE_CATALOG => [
				'browse catalog',
				'show products',
				'view products',
				'what do you have',
				'what do you sell',
				'catalog',
				'catalogue',
			],
			self::INTENT_VIEW_CATEGORY  => [
				'show category',
				'view category',
				'categories',
			],
			self::INTENT_SEARCH_PRODUCT => [
				'search for',
				'find product',
				'looking for',
				'search',
			],
			self::INTENT_VIEW_PRODUCT   => [
				'view product',
				'product details',
				'more info',
				'tell me more',
			],
			self::INTENT_HELP           => [
				'need help',
				'help me',
				'i need assistance',
				'help',
				'support',
				'assistance',
			],
			self::INTENT_TALK_TO_HUMAN  => [
				'talk to human',
				'speak to agent',
				'talk to someone',
				'human agent',
				'representative',
			],
			self::INTENT_GREETING       => [
				'good morning',
				'good afternoon',
				'good evening',
				'hello',
				'hi there',
				'hey there',
				'greetings',
				'howdy',
				'hi',
				'hey',
			],
		];

		/**
		 * Filter intent keywords.
		 *
		 * @param array $keywords Intent keyword mappings.
		 */
		$this->intentKeywords = apply_filters( 'wch_intent_keywords', $this->intentKeywords );
	}

	/**
	 * {@inheritdoc}
	 */
	public function parse( array $webhookMessageData ): ParsedResponse {
		$type    = $webhookMessageData['type'] ?? 'unknown';
		$content = $webhookMessageData['content'] ?? [];

		$parsedResponse = match ( $type ) {
			'text'        => $this->parseTextMessage( $content ),
			'interactive' => $this->parseInteractiveMessage( $content ),
			'location'    => $this->parseLocationMessage( $content ),
			'image'       => $this->parseImageMessage( $content ),
			'document'    => $this->parseDocumentMessage( $content ),
			'order'       => $this->parseProductInquiry( $content ),
			default       => ParsedResponse::unknown( $content ),
		};

		/**
		 * Filter parsed response.
		 *
		 * @param ParsedResponse $parsedResponse Parsed response.
		 * @param array          $webhookMessageData Original webhook data.
		 */
		return apply_filters( 'wch_parse_response', $parsedResponse, $webhookMessageData );
	}

	/**
	 * {@inheritdoc}
	 */
	public function detectIntent( string $text ): string {
		if ( '' === $text ) {
			return self::INTENT_UNKNOWN;
		}

		$textLower = strtolower( trim( $text ) );

		// Find all keyword matches.
		$matches = [];
		foreach ( $this->intentKeywords as $intent => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( str_contains( $textLower, strtolower( $keyword ) ) ) {
					$matches[] = [
						'intent'  => $intent,
						'keyword' => $keyword,
						'length'  => strlen( $keyword ),
					];
				}
			}
		}

		// Return the most specific match (longest keyword).
		if ( ! empty( $matches ) ) {
			usort( $matches, fn( array $a, array $b ): int => $b['length'] - $a['length'] );
			$bestMatch = $matches[0];

			/**
			 * Filter detected intent.
			 *
			 * @param string $intent  Detected intent.
			 * @param string $text    Original text.
			 * @param string $keyword Matched keyword.
			 */
			return apply_filters( 'wch_detected_intent', $bestMatch['intent'], $text, $bestMatch['keyword'] );
		}

		return self::INTENT_UNKNOWN;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAvailableIntents(): array {
		return [
			self::INTENT_GREETING,
			self::INTENT_BROWSE_CATALOG,
			self::INTENT_VIEW_CATEGORY,
			self::INTENT_SEARCH_PRODUCT,
			self::INTENT_VIEW_PRODUCT,
			self::INTENT_ADD_TO_CART,
			self::INTENT_VIEW_CART,
			self::INTENT_MODIFY_CART,
			self::INTENT_CHECKOUT,
			self::INTENT_APPLY_COUPON,
			self::INTENT_ORDER_STATUS,
			self::INTENT_TRACK_SHIPPING,
			self::INTENT_HELP,
			self::INTENT_TALK_TO_HUMAN,
			self::INTENT_UNKNOWN,
		];
	}

	/**
	 * Parse text message.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseTextMessage( array $content ): ParsedResponse {
		$text   = $content['body'] ?? '';
		$intent = $this->detectIntent( $text );

		return ParsedResponse::text( $text, $intent );
	}

	/**
	 * Parse interactive message (button or list reply).
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseInteractiveMessage( array $content ): ParsedResponse {
		$interactiveType = $content['type'] ?? '';

		return match ( $interactiveType ) {
			'button_reply' => $this->parseButtonReply( $content ),
			'list_reply'   => $this->parseListReply( $content ),
			'nfm_reply'    => $this->parseProductInquiry( $content ),
			default        => ParsedResponse::unknown( $content ),
		};
	}

	/**
	 * Parse button reply.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseButtonReply( array $content ): ParsedResponse {
		$buttonId    = $content['id'] ?? '';
		$buttonTitle = $content['title'] ?? '';

		return ParsedResponse::buttonReply(
			$buttonId,
			$buttonTitle
		);
	}

	/**
	 * Parse list reply.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseListReply( array $content ): ParsedResponse {
		$listId      = $content['id'] ?? '';
		$title       = $content['title'] ?? '';
		$description = $content['description'] ?? null;

		return ParsedResponse::listReply( $listId, $title, $description );
	}

	/**
	 * Parse product inquiry.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseProductInquiry( array $content ): ParsedResponse {
		return new ParsedResponse(
			ParsedResponse::TYPE_PRODUCT_INQUIRY,
			$content,
			[
				'product_retailer_id' => $content['product_retailer_id'] ?? '',
				'catalog_id'          => $content['catalog_id'] ?? '',
			],
			self::INTENT_VIEW_PRODUCT
		);
	}

	/**
	 * Parse location message.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseLocationMessage( array $content ): ParsedResponse {
		$latitude  = (float) ( $content['latitude'] ?? 0 );
		$longitude = (float) ( $content['longitude'] ?? 0 );
		$name      = $content['name'] ?? null;
		$address   = $content['address'] ?? null;

		return ParsedResponse::location( $latitude, $longitude, $name, $address );
	}

	/**
	 * Parse image message.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseImageMessage( array $content ): ParsedResponse {
		$caption = $content['caption'] ?? '';
		$intent  = '' !== $caption ? $this->detectIntent( $caption ) : self::INTENT_UNKNOWN;

		return new ParsedResponse(
			ParsedResponse::TYPE_IMAGE,
			$content,
			[
				'media_id'  => $content['id'] ?? '',
				'mime_type' => $content['mime_type'] ?? '',
				'sha256'    => $content['sha256'] ?? '',
				'caption'   => $caption,
			],
			$intent
		);
	}

	/**
	 * Parse document message.
	 *
	 * @param array $content Message content.
	 * @return ParsedResponse
	 */
	protected function parseDocumentMessage( array $content ): ParsedResponse {
		$caption = $content['caption'] ?? '';
		$intent  = '' !== $caption ? $this->detectIntent( $caption ) : self::INTENT_UNKNOWN;

		return new ParsedResponse(
			ParsedResponse::TYPE_DOCUMENT,
			$content,
			[
				'media_id'  => $content['id'] ?? '',
				'mime_type' => $content['mime_type'] ?? '',
				'sha256'    => $content['sha256'] ?? '',
				'caption'   => $caption,
				'filename'  => $content['filename'] ?? '',
			],
			$intent
		);
	}

	/**
	 * Add custom intent keywords.
	 *
	 * @param string   $intent   Intent constant.
	 * @param string[] $keywords Keywords to add.
	 * @return void
	 */
	public function addIntentKeywords( string $intent, array $keywords ): void {
		if ( ! isset( $this->intentKeywords[ $intent ] ) ) {
			$this->intentKeywords[ $intent ] = [];
		}

		$this->intentKeywords[ $intent ] = array_merge(
			$this->intentKeywords[ $intent ],
			$keywords
		);
	}

	/**
	 * Set all intent keywords.
	 *
	 * @param array<string, string[]> $keywords Intent keyword mappings.
	 * @return void
	 */
	public function setIntentKeywords( array $keywords ): void {
		$this->intentKeywords = $keywords;
	}
}
