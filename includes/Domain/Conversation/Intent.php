<?php
/**
 * Intent Value Object
 *
 * Represents a classified intent from user input.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Domain\Conversation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Class Intent
 *
 * Immutable value object holding the results of intent classification.
 */
final class Intent {
/**
 * Intent name constants.
 */
public const INTENT_GREETING     = 'GREETING';
public const INTENT_BROWSE       = 'BROWSE';
public const INTENT_SEARCH       = 'SEARCH';
public const INTENT_VIEW_CART    = 'VIEW_CART';
public const INTENT_CHECKOUT     = 'CHECKOUT';
public const INTENT_ORDER_STATUS = 'ORDER_STATUS';
public const INTENT_CANCEL       = 'CANCEL';
public const INTENT_HELP         = 'HELP';
public const INTENT_COMPLAINT    = 'COMPLAINT';
public const INTENT_UNKNOWN      = 'UNKNOWN';

/**
 * Constructor.
 *
 * @param string $name       Intent name.
 * @param float  $confidence Confidence score (0-1).
 * @param array  $entities   Extracted entities.
 * @param array  $metadata   Additional metadata.
 */
public function __construct(
public readonly string $name,
public readonly float $confidence,
public readonly array $entities = array(),
public readonly array $metadata = array()
) {}

/**
 * Check if intent matches a specific name.
 *
 * @param string $intentName Intent name to check.
 * @return bool
 */
public function is( string $intentName ): bool {
return $this->name === $intentName;
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
 * Get entity value by name.
 *
 * @param string $entityName Entity name.
 * @param mixed  $default    Default value if not found.
 * @return mixed
 */
public function getEntity( string $entityName, $default = null ) {
return $this->entities[ $entityName ] ?? $default;
}

/**
 * Check if entity exists.
 *
 * @param string $entityName Entity name.
 * @return bool
 */
public function hasEntity( string $entityName ): bool {
return isset( $this->entities[ $entityName ] );
}

/**
 * Get metadata value.
 *
 * @param string $key     Metadata key.
 * @param mixed  $default Default value if not found.
 * @return mixed
 */
public function getMetadata( string $key, $default = null ) {
return $this->metadata[ $key ] ?? $default;
}

/**
 * Convert to array.
 *
 * @return array
 */
public function toArray(): array {
return array(
'name'       => $this->name,
'confidence' => $this->confidence,
'entities'   => $this->entities,
'metadata'   => $this->metadata,
);
}
}
