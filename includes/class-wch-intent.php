<?php
/**
 * Intent Class
 *
 * Represents a classified intent from user input.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Intent
 *
 * Holds the results of intent classification.
 */
class WCH_Intent {
	/**
	 * Intent name constants.
	 */
	const INTENT_GREETING     = 'GREETING';
	const INTENT_BROWSE       = 'BROWSE';
	const INTENT_SEARCH       = 'SEARCH';
	const INTENT_VIEW_CART    = 'VIEW_CART';
	const INTENT_CHECKOUT     = 'CHECKOUT';
	const INTENT_ORDER_STATUS = 'ORDER_STATUS';
	const INTENT_CANCEL       = 'CANCEL';
	const INTENT_HELP         = 'HELP';
	const INTENT_UNKNOWN      = 'UNKNOWN';

	/**
	 * Intent name from enum.
	 *
	 * @var string
	 */
	public $intent_name;

	/**
	 * Confidence score (0-1).
	 *
	 * @var float
	 */
	public $confidence;

	/**
	 * Extracted entities.
	 *
	 * @var array Array of {type, value, position}
	 */
	public $entities;

	/**
	 * Constructor.
	 *
	 * @param string $intent_name Intent name from enum.
	 * @param float  $confidence  Confidence score (0-1).
	 * @param array  $entities    Array of entities.
	 */
	public function __construct( $intent_name, $confidence, $entities = array() ) {
		$this->intent_name = $intent_name;
		$this->confidence  = max( 0.0, min( 1.0, (float) $confidence ) ); // Clamp between 0 and 1
		$this->entities    = $entities;
	}

	/**
	 * Get all valid intent names.
	 *
	 * @return array Valid intent names.
	 */
	public static function get_valid_intents() {
		return array(
			self::INTENT_GREETING,
			self::INTENT_BROWSE,
			self::INTENT_SEARCH,
			self::INTENT_VIEW_CART,
			self::INTENT_CHECKOUT,
			self::INTENT_ORDER_STATUS,
			self::INTENT_CANCEL,
			self::INTENT_HELP,
			self::INTENT_UNKNOWN,
		);
	}

	/**
	 * Check if intent name is valid.
	 *
	 * @param string $intent_name Intent name to check.
	 * @return bool True if valid.
	 */
	public static function is_valid_intent( $intent_name ) {
		return in_array( $intent_name, self::get_valid_intents(), true );
	}

	/**
	 * Convert to array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'intent_name' => $this->intent_name,
			'confidence'  => $this->confidence,
			'entities'    => $this->entities,
		);
	}

	/**
	 * Convert to JSON string.
	 *
	 * @return string
	 */
	public function to_json() {
		return wp_json_encode( $this->to_array() );
	}

	/**
	 * Get entity by type.
	 *
	 * @param string $type Entity type.
	 * @return array|null Entity or null if not found.
	 */
	public function get_entity( $type ) {
		foreach ( $this->entities as $entity ) {
			if ( isset( $entity['type'] ) && $entity['type'] === $type ) {
				return $entity;
			}
		}
		return null;
	}

	/**
	 * Get all entities of a specific type.
	 *
	 * @param string $type Entity type.
	 * @return array Array of entities.
	 */
	public function get_entities_by_type( $type ) {
		$result = array();
		foreach ( $this->entities as $entity ) {
			if ( isset( $entity['type'] ) && $entity['type'] === $type ) {
				$result[] = $entity;
			}
		}
		return $result;
	}

	/**
	 * Check if has entity of type.
	 *
	 * @param string $type Entity type.
	 * @return bool True if has entity.
	 */
	public function has_entity( $type ) {
		return null !== $this->get_entity( $type );
	}
}
