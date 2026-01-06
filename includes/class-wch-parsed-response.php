<?php
/**
 * Parsed Response Class
 *
 * Represents a parsed WhatsApp message response with extracted content and metadata.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCH_Parsed_Response
 *
 * Container for parsed message data with type, content, and detected intent.
 */
class WCH_Parsed_Response {

	/**
	 * Message type
	 *
	 * @var string One of: text, button_reply, list_reply, product_inquiry, location, image, document, unknown
	 */
	public $type;

	/**
	 * Raw content from the message
	 *
	 * @var mixed
	 */
	public $raw_content;

	/**
	 * Parsed data extracted from the message
	 *
	 * @var array
	 */
	public $parsed_data;

	/**
	 * Detected intent (if applicable)
	 *
	 * @var string|null
	 */
	public $intent;

	/**
	 * Constructor
	 *
	 * @param string $type        Message type.
	 * @param mixed  $raw_content Raw message content.
	 * @param array  $parsed_data Parsed data array.
	 * @param string $intent      Detected intent (optional).
	 */
	public function __construct( $type, $raw_content, $parsed_data = array(), $intent = null ) {
		$this->type        = $type;
		$this->raw_content = $raw_content;
		$this->parsed_data = $parsed_data;
		$this->intent      = $intent;
	}

	/**
	 * Get message type
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Get raw content
	 *
	 * @return mixed
	 */
	public function get_raw_content() {
		return $this->raw_content;
	}

	/**
	 * Get parsed data
	 *
	 * @return array
	 */
	public function get_parsed_data() {
		return $this->parsed_data;
	}

	/**
	 * Get detected intent
	 *
	 * @return string|null
	 */
	public function get_intent() {
		return $this->intent;
	}

	/**
	 * Check if message has a specific intent
	 *
	 * @param string $intent Intent to check.
	 * @return bool
	 */
	public function has_intent( $intent ) {
		return $this->intent === $intent;
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'type'        => $this->type,
			'raw_content' => $this->raw_content,
			'parsed_data' => $this->parsed_data,
			'intent'      => $this->intent,
		);
	}
}
