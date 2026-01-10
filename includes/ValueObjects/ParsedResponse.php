<?php
/**
 * Parsed Response Value Object
 *
 * Represents a parsed WhatsApp message response with extracted content and metadata.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\ValueObjects;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ParsedResponse
 *
 * Container for parsed message data with type, content, and detected intent.
 */
class ParsedResponse {

	/**
	 * Message type constants.
	 */
	public const TYPE_TEXT            = 'text';
	public const TYPE_BUTTON_REPLY    = 'button_reply';
	public const TYPE_LIST_REPLY      = 'list_reply';
	public const TYPE_PRODUCT_INQUIRY = 'product_inquiry';
	public const TYPE_LOCATION        = 'location';
	public const TYPE_IMAGE           = 'image';
	public const TYPE_DOCUMENT        = 'document';
	public const TYPE_AUDIO           = 'audio';
	public const TYPE_VIDEO           = 'video';
	public const TYPE_STICKER         = 'sticker';
	public const TYPE_CONTACTS        = 'contacts';
	public const TYPE_ORDER           = 'order';
	public const TYPE_UNKNOWN         = 'unknown';

	/**
	 * Message type.
	 *
	 * @var string
	 */
	protected readonly string $type;

	/**
	 * Raw content from the message.
	 *
	 * @var mixed
	 */
	protected readonly mixed $rawContent;

	/**
	 * Parsed data extracted from the message.
	 *
	 * @var array<string, mixed>
	 */
	protected readonly array $parsedData;

	/**
	 * Detected intent (if applicable).
	 *
	 * @var string|null
	 */
	protected readonly ?string $intent;

	/**
	 * Message timestamp.
	 *
	 * @var int|null
	 */
	protected readonly ?int $timestamp;

	/**
	 * Constructor.
	 *
	 * @param string      $type       Message type.
	 * @param mixed       $rawContent Raw message content.
	 * @param array       $parsedData Parsed data array.
	 * @param string|null $intent     Detected intent (optional).
	 * @param int|null    $timestamp  Message timestamp.
	 */
	public function __construct(
		string $type,
		mixed $rawContent,
		array $parsedData = array(),
		?string $intent = null,
		?int $timestamp = null
	) {
		$this->type       = $type;
		$this->rawContent = $rawContent;
		$this->parsedData = $parsedData;
		$this->intent     = $intent;
		$this->timestamp  = $timestamp ?? time();
	}

	/**
	 * Get message type.
	 *
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * Get raw content.
	 *
	 * @return mixed
	 */
	public function getRawContent(): mixed {
		return $this->rawContent;
	}

	/**
	 * Get parsed data.
	 *
	 * @return array
	 */
	public function getParsedData(): array {
		return $this->parsedData;
	}

	/**
	 * Get a specific parsed data value.
	 *
	 * @param string $key     Key to retrieve.
	 * @param mixed  $default Default value if not found.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return $this->parsedData[ $key ] ?? $default;
	}

	/**
	 * Get detected intent.
	 *
	 * @return string|null
	 */
	public function getIntent(): ?string {
		return $this->intent;
	}

	/**
	 * Get timestamp.
	 *
	 * @return int|null
	 */
	public function getTimestamp(): ?int {
		return $this->timestamp;
	}

	/**
	 * Check if message has a specific intent.
	 *
	 * @param string $intent Intent to check.
	 * @return bool
	 */
	public function hasIntent( string $intent ): bool {
		return $this->intent === $intent;
	}

	/**
	 * Check if message is of a specific type.
	 *
	 * @param string $type Type to check.
	 * @return bool
	 */
	public function isType( string $type ): bool {
		return $this->type === $type;
	}

	/**
	 * Check if message is a text message.
	 *
	 * @return bool
	 */
	public function isText(): bool {
		return $this->type === self::TYPE_TEXT;
	}

	/**
	 * Check if message is a button reply.
	 *
	 * @return bool
	 */
	public function isButtonReply(): bool {
		return $this->type === self::TYPE_BUTTON_REPLY;
	}

	/**
	 * Check if message is a list reply.
	 *
	 * @return bool
	 */
	public function isListReply(): bool {
		return $this->type === self::TYPE_LIST_REPLY;
	}

	/**
	 * Check if message is an interactive reply (button or list).
	 *
	 * @return bool
	 */
	public function isInteractiveReply(): bool {
		return $this->isButtonReply() || $this->isListReply();
	}

	/**
	 * Check if message is media type.
	 *
	 * @return bool
	 */
	public function isMedia(): bool {
		return in_array(
			$this->type,
			array( self::TYPE_IMAGE, self::TYPE_DOCUMENT, self::TYPE_AUDIO, self::TYPE_VIDEO, self::TYPE_STICKER ),
			true
		);
	}

	/**
	 * Check if message is a location.
	 *
	 * @return bool
	 */
	public function isLocation(): bool {
		return $this->type === self::TYPE_LOCATION;
	}

	/**
	 * Get text content (if applicable).
	 *
	 * @return string
	 */
	public function getText(): string {
		if ( $this->isText() ) {
			return is_string( $this->rawContent ) ? $this->rawContent : '';
		}

		return $this->get( 'text', '' );
	}

	/**
	 * Get button/list ID (for interactive replies).
	 *
	 * @return string|null
	 */
	public function getReplyId(): ?string {
		return $this->get( 'id' ) ?? $this->get( 'button_id' ) ?? $this->get( 'list_id' );
	}

	/**
	 * Get button/list title (for interactive replies).
	 *
	 * @return string|null
	 */
	public function getReplyTitle(): ?string {
		return $this->get( 'title' ) ?? $this->get( 'button_text' ) ?? $this->get( 'list_title' );
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return array(
			'type'        => $this->type,
			'raw_content' => $this->rawContent,
			'parsed_data' => $this->parsedData,
			'intent'      => $this->intent,
			'timestamp'   => $this->timestamp,
		);
	}

	/**
	 * Convert to JSON string.
	 *
	 * @return string
	 */
	public function toJson(): string {
		return (string) wp_json_encode( $this->toArray() );
	}

	/**
	 * Create from array.
	 *
	 * @param array $data Response data.
	 * @return static
	 */
	public static function fromArray( array $data ): static {
		return new static(
			$data['type'] ?? self::TYPE_UNKNOWN,
			$data['raw_content'] ?? null,
			$data['parsed_data'] ?? array(),
			$data['intent'] ?? null,
			$data['timestamp'] ?? null
		);
	}

	/**
	 * Create a text response.
	 *
	 * @param string      $text   Text content.
	 * @param string|null $intent Detected intent.
	 * @return static
	 */
	public static function text( string $text, ?string $intent = null ): static {
		return new static( self::TYPE_TEXT, $text, array( 'text' => $text ), $intent );
	}

	/**
	 * Create a button reply response.
	 *
	 * @param string $buttonId    Button ID.
	 * @param string $buttonTitle Button title/text.
	 * @return static
	 */
	public static function buttonReply( string $buttonId, string $buttonTitle ): static {
		return new static(
			self::TYPE_BUTTON_REPLY,
			$buttonId,
			array(
				'id'    => $buttonId,
				'title' => $buttonTitle,
			)
		);
	}

	/**
	 * Create a list reply response.
	 *
	 * @param string      $listId          List item ID.
	 * @param string      $listTitle       List item title.
	 * @param string|null $listDescription List item description.
	 * @return static
	 */
	public static function listReply( string $listId, string $listTitle, ?string $listDescription = null ): static {
		return new static(
			self::TYPE_LIST_REPLY,
			$listId,
			array(
				'id'          => $listId,
				'title'       => $listTitle,
				'description' => $listDescription,
			)
		);
	}

	/**
	 * Create a location response.
	 *
	 * @param float       $latitude  Latitude.
	 * @param float       $longitude Longitude.
	 * @param string|null $name      Location name.
	 * @param string|null $address   Location address.
	 * @return static
	 */
	public static function location( float $latitude, float $longitude, ?string $name = null, ?string $address = null ): static {
		return new static(
			self::TYPE_LOCATION,
			array(
				'latitude'  => $latitude,
				'longitude' => $longitude,
			),
			array(
				'latitude'  => $latitude,
				'longitude' => $longitude,
				'name'      => $name,
				'address'   => $address,
			)
		);
	}

	/**
	 * Create an unknown type response.
	 *
	 * @param mixed $content Raw content.
	 * @return static
	 */
	public static function unknown( mixed $content ): static {
		return new static( self::TYPE_UNKNOWN, $content );
	}
}
