<?php
/**
 * Intent Value Object
 *
 * Represents a classified intent from user input.
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
 * Class Intent
 *
 * Immutable value object holding the results of intent classification.
 */
class Intent {

	/**
	 * Intent name constants.
	 */
	public const GREETING     = 'GREETING';
	public const BROWSE       = 'BROWSE';
	public const SEARCH       = 'SEARCH';
	public const VIEW_CART    = 'VIEW_CART';
	public const CHECKOUT     = 'CHECKOUT';
	public const ORDER_STATUS = 'ORDER_STATUS';
	public const CANCEL       = 'CANCEL';
	public const HELP         = 'HELP';
	public const UNKNOWN      = 'UNKNOWN';
	public const ADD_TO_CART  = 'ADD_TO_CART';
	public const REMOVE_ITEM  = 'REMOVE_ITEM';
	public const TRACK_ORDER  = 'TRACK_ORDER';
	public const FEEDBACK     = 'FEEDBACK';
	public const HUMAN_AGENT  = 'HUMAN_AGENT';

	/**
	 * Intent name.
	 *
	 * @var string
	 */
	protected readonly string $name;

	/**
	 * Confidence score (0-1).
	 *
	 * @var float
	 */
	protected readonly float $confidence;

	/**
	 * Extracted entities.
	 *
	 * @var array<int, array{type: string, value: mixed, position?: int}>
	 */
	protected readonly array $entities;

	/**
	 * Constructor.
	 *
	 * @param string $name       Intent name from constants.
	 * @param float  $confidence Confidence score (0-1).
	 * @param array  $entities   Array of entities.
	 */
	public function __construct( string $name, float $confidence, array $entities = [] ) {
		$this->name       = self::isValid( $name ) ? $name : self::UNKNOWN;
		$this->confidence = max( 0.0, min( 1.0, $confidence ) ); // Clamp between 0 and 1.
		$this->entities   = $entities;
	}

	/**
	 * Get intent name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Get confidence score.
	 *
	 * @return float
	 */
	public function getConfidence(): float {
		return $this->confidence;
	}

	/**
	 * Get entities.
	 *
	 * @return array
	 */
	public function getEntities(): array {
		return $this->entities;
	}

	/**
	 * Check if confidence is above threshold.
	 *
	 * @param float $threshold Minimum confidence threshold.
	 * @return bool
	 */
	public function isConfident( float $threshold = 0.7 ): bool {
		return $this->confidence >= $threshold;
	}

	/**
	 * Get all valid intent names.
	 *
	 * @return string[] Valid intent names.
	 */
	public static function getValidIntents(): array {
		return [
			self::GREETING,
			self::BROWSE,
			self::SEARCH,
			self::VIEW_CART,
			self::CHECKOUT,
			self::ORDER_STATUS,
			self::CANCEL,
			self::HELP,
			self::UNKNOWN,
			self::ADD_TO_CART,
			self::REMOVE_ITEM,
			self::TRACK_ORDER,
			self::FEEDBACK,
			self::HUMAN_AGENT,
		];
	}

	/**
	 * Check if intent name is valid.
	 *
	 * @param string $name Intent name to check.
	 * @return bool True if valid.
	 */
	public static function isValid( string $name ): bool {
		return in_array( $name, self::getValidIntents(), true );
	}

	/**
	 * Convert to array.
	 *
	 * @return array{intent_name: string, confidence: float, entities: array}
	 */
	public function toArray(): array {
		return [
			'intent_name' => $this->name,
			'confidence'  => $this->confidence,
			'entities'    => $this->entities,
		];
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
	 * @param array $data Intent data.
	 * @return static
	 */
	public static function fromArray( array $data ): static {
		return new static(
			$data['intent_name'] ?? $data['name'] ?? self::UNKNOWN,
			(float) ( $data['confidence'] ?? 0.0 ),
			$data['entities'] ?? []
		);
	}

	/**
	 * Create from JSON string.
	 *
	 * @param string $json JSON string.
	 * @return static
	 */
	public static function fromJson( string $json ): static {
		$data = json_decode( $json, true );
		return static::fromArray( is_array( $data ) ? $data : [] );
	}

	/**
	 * Get entity by type.
	 *
	 * @param string $type Entity type.
	 * @return array|null Entity or null if not found.
	 */
	public function getEntity( string $type ): ?array {
		foreach ( $this->entities as $entity ) {
			if ( isset( $entity['type'] ) && $entity['type'] === $type ) {
				return $entity;
			}
		}
		return null;
	}

	/**
	 * Get entity value by type.
	 *
	 * @param string $type Entity type.
	 * @return mixed|null Entity value or null if not found.
	 */
	public function getEntityValue( string $type ): mixed {
		$entity = $this->getEntity( $type );
		return $entity['value'] ?? null;
	}

	/**
	 * Get all entities of a specific type.
	 *
	 * @param string $type Entity type.
	 * @return array Array of entities.
	 */
	public function getEntitiesByType( string $type ): array {
		return array_filter(
			$this->entities,
			fn( array $entity ): bool => isset( $entity['type'] ) && $entity['type'] === $type
		);
	}

	/**
	 * Check if has entity of type.
	 *
	 * @param string $type Entity type.
	 * @return bool True if has entity.
	 */
	public function hasEntity( string $type ): bool {
		return null !== $this->getEntity( $type );
	}

	/**
	 * Check if this intent matches a given intent name.
	 *
	 * @param string $intent Intent name to match.
	 * @return bool
	 */
	public function is( string $intent ): bool {
		return $this->name === $intent;
	}

	/**
	 * Create an unknown intent.
	 *
	 * @return static
	 */
	public static function unknown(): static {
		return new static( self::UNKNOWN, 0.0 );
	}
}
