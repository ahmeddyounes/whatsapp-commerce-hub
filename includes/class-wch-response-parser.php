<?php
/**
 * Response Parser Class
 *
 * Parses incoming WhatsApp message responses and extracts structured data with intent detection.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCH_Response_Parser
 *
 * Handles parsing of WhatsApp webhook messages and detecting user intents.
 */
class WCH_Response_Parser {

	/**
	 * Intent constants
	 */
	const INTENT_GREETING       = 'GREETING';
	const INTENT_BROWSE_CATALOG = 'BROWSE_CATALOG';
	const INTENT_VIEW_CATEGORY  = 'VIEW_CATEGORY';
	const INTENT_SEARCH_PRODUCT = 'SEARCH_PRODUCT';
	const INTENT_VIEW_PRODUCT   = 'VIEW_PRODUCT';
	const INTENT_ADD_TO_CART    = 'ADD_TO_CART';
	const INTENT_VIEW_CART      = 'VIEW_CART';
	const INTENT_MODIFY_CART    = 'MODIFY_CART';
	const INTENT_CHECKOUT       = 'CHECKOUT';
	const INTENT_APPLY_COUPON   = 'APPLY_COUPON';
	const INTENT_ORDER_STATUS   = 'ORDER_STATUS';
	const INTENT_TRACK_SHIPPING = 'TRACK_SHIPPING';
	const INTENT_HELP           = 'HELP';
	const INTENT_TALK_TO_HUMAN  = 'TALK_TO_HUMAN';
	const INTENT_UNKNOWN        = 'UNKNOWN';

	/**
	 * Intent keyword mappings
	 *
	 * @var array
	 */
	private $intent_keywords = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_intent_keywords();
	}

	/**
	 * Initialize intent keyword mappings
	 *
	 * Keywords are ordered by priority - more specific phrases first.
	 */
	private function init_intent_keywords() {
		$this->intent_keywords = array(
			// Most specific intents first to avoid false matches
			self::INTENT_TRACK_SHIPPING => array(
				'track my shipment',
				'track my order',
				'where is my shipment',
				'track',
				'tracking',
				'shipment',
				'delivery status',
				'shipping status',
			),
			self::INTENT_ORDER_STATUS   => array(
				'order status',
				'my order',
				'where is my order',
				'check order',
				'order',
			),
			self::INTENT_CHECKOUT       => array(
				'checkout',
				'complete order',
				'place order',
				'proceed to checkout',
				'pay now',
				'payment',
				'pay',
			),
			self::INTENT_VIEW_CART      => array(
				'view cart',
				'show cart',
				'my cart',
				'shopping cart',
				'cart',
				'basket',
				'bag',
			),
			self::INTENT_MODIFY_CART    => array(
				'remove from cart',
				'delete from cart',
				'update cart',
				'change quantity',
				'modify cart',
			),
			self::INTENT_ADD_TO_CART    => array(
				'add to cart',
				'add this',
				'buy this',
				'purchase this',
				'want this',
			),
			self::INTENT_APPLY_COUPON   => array(
				'apply coupon',
				'use coupon',
				'promo code',
				'discount code',
				'coupon',
				'voucher',
			),
			self::INTENT_BROWSE_CATALOG => array(
				'browse catalog',
				'show products',
				'view products',
				'what do you have',
				'what do you sell',
				'catalog',
				'catalogue',
			),
			self::INTENT_VIEW_CATEGORY  => array(
				'show category',
				'view category',
				'categories',
			),
			self::INTENT_SEARCH_PRODUCT => array(
				'search for',
				'find product',
				'looking for',
				'search',
			),
			self::INTENT_VIEW_PRODUCT   => array(
				'view product',
				'product details',
				'more info',
				'tell me more',
			),
			self::INTENT_HELP           => array(
				'need help',
				'help me',
				'i need assistance',
				'help',
				'support',
				'assistance',
			),
			self::INTENT_TALK_TO_HUMAN  => array(
				'talk to human',
				'speak to agent',
				'talk to someone',
				'human agent',
				'representative',
			),
			// Generic greetings last as they should have lower priority
			self::INTENT_GREETING       => array(
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
			),
		);

		// Allow filtering of intent keywords.
		$this->intent_keywords = apply_filters( 'wch_intent_keywords', $this->intent_keywords );
	}

	/**
	 * Parse webhook message data
	 *
	 * @param array $webhook_message_data Webhook message data from WhatsApp.
	 * @return WCH_Parsed_Response Parsed response object.
	 */
	public function parse( $webhook_message_data ) {
		if ( ! is_array( $webhook_message_data ) ) {
			return new WCH_Parsed_Response(
				'unknown',
				$webhook_message_data,
				array(),
				self::INTENT_UNKNOWN
			);
		}

		$type    = isset( $webhook_message_data['type'] ) ? $webhook_message_data['type'] : 'unknown';
		$content = isset( $webhook_message_data['content'] ) ? $webhook_message_data['content'] : array();

		$parsed_response = null;

		switch ( $type ) {
			case 'text':
				$parsed_response = $this->parse_text_message( $content );
				break;

			case 'interactive':
				$parsed_response = $this->parse_interactive_message( $content );
				break;

			case 'location':
				$parsed_response = $this->parse_location_message( $content );
				break;

			case 'image':
				$parsed_response = $this->parse_image_message( $content );
				break;

			case 'document':
				$parsed_response = $this->parse_document_message( $content );
				break;

			case 'order':
				$parsed_response = $this->parse_product_inquiry( $content );
				break;

			default:
				$parsed_response = new WCH_Parsed_Response(
					'unknown',
					$content,
					array(),
					self::INTENT_UNKNOWN
				);
				break;
		}

		// Apply filter for custom parsing extensions.
		$parsed_response = apply_filters( 'wch_parse_response', $parsed_response, $webhook_message_data );

		return $parsed_response;
	}

	/**
	 * Parse text message
	 *
	 * @param array $content Message content.
	 * @return WCH_Parsed_Response
	 */
	private function parse_text_message( $content ) {
		$text = isset( $content['body'] ) ? $content['body'] : '';

		$parsed_data = array(
			'text' => $text,
		);

		$intent = $this->detect_intent( $text );

		return new WCH_Parsed_Response(
			'text',
			$content,
			$parsed_data,
			$intent
		);
	}

	/**
	 * Parse interactive message (button or list reply)
	 *
	 * @param array $content Message content.
	 * @return WCH_Parsed_Response
	 */
	private function parse_interactive_message( $content ) {
		$interactive_type = isset( $content['type'] ) ? $content['type'] : '';

		if ( 'button_reply' === $interactive_type ) {
			$parsed_data = array(
				'button_id'    => isset( $content['id'] ) ? $content['id'] : '',
				'button_title' => isset( $content['title'] ) ? $content['title'] : '',
			);

			return new WCH_Parsed_Response(
				'button_reply',
				$content,
				$parsed_data,
				$this->detect_intent( $parsed_data['button_title'] )
			);
		} elseif ( 'list_reply' === $interactive_type ) {
			$parsed_data = array(
				'list_id'     => isset( $content['id'] ) ? $content['id'] : '',
				'title'       => isset( $content['title'] ) ? $content['title'] : '',
				'description' => isset( $content['description'] ) ? $content['description'] : '',
			);

			return new WCH_Parsed_Response(
				'list_reply',
				$content,
				$parsed_data,
				$this->detect_intent( $parsed_data['title'] )
			);
		} elseif ( 'nfm_reply' === $interactive_type ) {
			return $this->parse_product_inquiry( $content );
		}

		return new WCH_Parsed_Response(
			'unknown',
			$content,
			array(),
			self::INTENT_UNKNOWN
		);
	}

	/**
	 * Parse product inquiry (nfm_reply)
	 *
	 * @param array $content Message content.
	 * @return WCH_Parsed_Response
	 */
	private function parse_product_inquiry( $content ) {
		$parsed_data = array(
			'product_retailer_id' => isset( $content['product_retailer_id'] ) ? $content['product_retailer_id'] : '',
			'catalog_id'          => isset( $content['catalog_id'] ) ? $content['catalog_id'] : '',
		);

		return new WCH_Parsed_Response(
			'product_inquiry',
			$content,
			$parsed_data,
			self::INTENT_VIEW_PRODUCT
		);
	}

	/**
	 * Parse location message
	 *
	 * @param array $content Message content.
	 * @return WCH_Parsed_Response
	 */
	private function parse_location_message( $content ) {
		$parsed_data = array(
			'latitude'  => isset( $content['latitude'] ) ? $content['latitude'] : '',
			'longitude' => isset( $content['longitude'] ) ? $content['longitude'] : '',
			'name'      => isset( $content['name'] ) ? $content['name'] : '',
			'address'   => isset( $content['address'] ) ? $content['address'] : '',
		);

		return new WCH_Parsed_Response(
			'location',
			$content,
			$parsed_data,
			self::INTENT_UNKNOWN
		);
	}

	/**
	 * Parse image message
	 *
	 * @param array $content Message content.
	 * @return WCH_Parsed_Response
	 */
	private function parse_image_message( $content ) {
		$parsed_data = array(
			'media_id'  => isset( $content['id'] ) ? $content['id'] : '',
			'mime_type' => isset( $content['mime_type'] ) ? $content['mime_type'] : '',
			'sha256'    => isset( $content['sha256'] ) ? $content['sha256'] : '',
			'caption'   => isset( $content['caption'] ) ? $content['caption'] : '',
		);

		$intent = ! empty( $parsed_data['caption'] ) ? $this->detect_intent( $parsed_data['caption'] ) : self::INTENT_UNKNOWN;

		return new WCH_Parsed_Response(
			'image',
			$content,
			$parsed_data,
			$intent
		);
	}

	/**
	 * Parse document message
	 *
	 * @param array $content Message content.
	 * @return WCH_Parsed_Response
	 */
	private function parse_document_message( $content ) {
		$parsed_data = array(
			'media_id'  => isset( $content['id'] ) ? $content['id'] : '',
			'mime_type' => isset( $content['mime_type'] ) ? $content['mime_type'] : '',
			'sha256'    => isset( $content['sha256'] ) ? $content['sha256'] : '',
			'caption'   => isset( $content['caption'] ) ? $content['caption'] : '',
			'filename'  => isset( $content['filename'] ) ? $content['filename'] : '',
		);

		$intent = ! empty( $parsed_data['caption'] ) ? $this->detect_intent( $parsed_data['caption'] ) : self::INTENT_UNKNOWN;

		return new WCH_Parsed_Response(
			'document',
			$content,
			$parsed_data,
			$intent
		);
	}

	/**
	 * Detect intent from text using keyword matching
	 *
	 * @param string $text Text to analyze.
	 * @return string Detected intent constant.
	 */
	public function detect_intent( $text ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return self::INTENT_UNKNOWN;
		}

		$text_lower = strtolower( trim( $text ) );

		// Sort keywords by length (longest first) for better matching.
		$sorted_matches = array();
		foreach ( $this->intent_keywords as $intent => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( strpos( $text_lower, strtolower( $keyword ) ) !== false ) {
					$sorted_matches[] = array(
						'intent'  => $intent,
						'keyword' => $keyword,
						'length'  => strlen( $keyword ),
					);
				}
			}
		}

		// If we have matches, return the one with the longest keyword (most specific).
		if ( ! empty( $sorted_matches ) ) {
			usort(
				$sorted_matches,
				function ( $a, $b ) {
					return $b['length'] - $a['length'];
				}
			);

			$best_match = $sorted_matches[0];
			// Apply filter to allow AI enhancement or custom logic.
			$detected_intent = apply_filters( 'wch_detected_intent', $best_match['intent'], $text, $best_match['keyword'] );
			return $detected_intent;
		}

		return self::INTENT_UNKNOWN;
	}

	/**
	 * Get all available intents
	 *
	 * @return array
	 */
	public static function get_available_intents() {
		return array(
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
		);
	}

	/**
	 * Store parsed response in conversation context
	 *
	 * This method stores the parsed response in the conversation context
	 * for multi-turn conversation handling.
	 *
	 * @param string              $conversation_id Conversation ID (phone number).
	 * @param WCH_Parsed_Response $parsed_response Parsed response object.
	 * @return bool Success status.
	 */
	public function store_in_context( $conversation_id, $parsed_response ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_conversations';

		// Get existing context.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT context FROM {$table_name} WHERE phone_number = %s",
				$conversation_id
			),
			ARRAY_A
		);

		$context = array();
		if ( $existing && ! empty( $existing['context'] ) ) {
			$context = json_decode( $existing['context'], true );
			if ( ! is_array( $context ) ) {
				$context = array();
			}
		}

		// Add parsed response to context history.
		if ( ! isset( $context['parsed_responses'] ) ) {
			$context['parsed_responses'] = array();
		}

		$context['parsed_responses'][] = array(
			'timestamp'   => time(),
			'type'        => $parsed_response->get_type(),
			'intent'      => $parsed_response->get_intent(),
			'parsed_data' => $parsed_response->get_parsed_data(),
		);

		// Keep only last 10 parsed responses to avoid bloat.
		$context['parsed_responses'] = array_slice( $context['parsed_responses'], -10 );

		// Store last intent for quick access.
		$context['last_intent'] = $parsed_response->get_intent();

		// Update or insert context.
		$result = $wpdb->replace(
			$table_name,
			array(
				'phone_number' => $conversation_id,
				'context'      => wp_json_encode( $context ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Get conversation context
	 *
	 * @param string $conversation_id Conversation ID (phone number).
	 * @return array|null Context data or null if not found.
	 */
	public function get_context( $conversation_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_conversations';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT context FROM {$table_name} WHERE phone_number = %s",
				$conversation_id
			),
			ARRAY_A
		);

		if ( ! $row || empty( $row['context'] ) ) {
			return null;
		}

		$context = json_decode( $row['context'], true );
		return is_array( $context ) ? $context : null;
	}
}
