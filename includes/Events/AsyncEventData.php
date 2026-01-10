<?php
/**
 * Async Event Data Wrapper
 *
 * Wraps serialized event data for async processing.
 * Provides the same interface as Event for handler compatibility.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Events;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AsyncEventData
 *
 * Wraps array data from toArray() to provide Event-like interface.
 * Used by processAsyncEvent() to allow handlers to work with both
 * sync (Event objects) and async (array data) dispatches.
 *
 * Note: This class provides duck-typing compatibility with Event.
 * Handlers should check instanceof Event for type-specific behavior,
 * or use getPayload() which works for both Event and AsyncEventData.
 */
class AsyncEventData {

	/**
	 * Unique event ID.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Event timestamp.
	 *
	 * @var \DateTimeImmutable
	 */
	public readonly \DateTimeImmutable $occurred_at;

	/**
	 * Event metadata.
	 *
	 * @var array
	 */
	public readonly array $metadata;

	/**
	 * The event name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The event payload.
	 *
	 * @var array
	 */
	private array $payload;

	/**
	 * Create from serialized event data.
	 *
	 * @param array $data The serialized event data from Event::toArray().
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * Constructor.
	 *
	 * @param array $data The serialized event data.
	 */
	public function __construct( array $data ) {
		$this->name    = $data['name'] ?? 'unknown';
		$this->payload = $data['payload'] ?? [];
		$this->id      = $data['id'] ?? $this->generateFallbackId();

		try {
			$this->occurred_at = new \DateTimeImmutable( $data['occurred_at'] ?? 'now' );
		} catch ( \Exception $e ) {
			$this->occurred_at = new \DateTimeImmutable();
		}

		$this->metadata = $data['metadata'] ?? [
			'source'  => 'async',
			'version' => defined( 'WCH_VERSION' ) ? WCH_VERSION : '1.0.0',
		];
	}

	/**
	 * Get the event name.
	 *
	 * @return string The event name.
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Get the event payload.
	 *
	 * @return array The event data.
	 */
	public function getPayload(): array {
		return $this->payload;
	}

	/**
	 * Serialize the event for storage or transmission.
	 *
	 * @return array The serialized event.
	 */
	public function toArray(): array {
		return [
			'id'          => $this->id,
			'name'        => $this->name,
			'payload'     => $this->payload,
			'metadata'    => $this->metadata,
			'occurred_at' => $this->occurred_at->format( 'c' ),
		];
	}

	/**
	 * Generate a fallback ID if none provided.
	 *
	 * @return string
	 */
	private function generateFallbackId(): string {
		try {
			return 'async-' . bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			return 'async-' . uniqid( '', true );
		}
	}
}
