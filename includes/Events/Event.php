<?php
/**
 * Base Event Class
 *
 * Base class for all domain events in the system.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Events;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event
 *
 * Immutable base class for domain events.
 */
abstract class Event {

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
	 * Constructor.
	 *
	 * @param array $metadata Optional event metadata.
	 */
	public function __construct( array $metadata = array() ) {
		$this->id          = $this->generateId();
		$this->occurred_at = new \DateTimeImmutable();
		$this->metadata    = array_merge(
			array(
				'source'     => 'wch',
				'version'    => WCH_VERSION,
				'request_id' => $this->getRequestId(),
			),
			$metadata
		);
	}

	/**
	 * Get the event name.
	 *
	 * @return string The event name (e.g., 'wch.message.received').
	 */
	abstract public function getName(): string;

	/**
	 * Get the event payload.
	 *
	 * @return array The event data.
	 */
	abstract public function getPayload(): array;

	/**
	 * Serialize the event for storage or transmission.
	 *
	 * @return array The serialized event.
	 */
	public function toArray(): array {
		return array(
			'id'          => $this->id,
			'name'        => $this->getName(),
			'payload'     => $this->getPayload(),
			'metadata'    => $this->metadata,
			'occurred_at' => $this->occurred_at->format( 'c' ),
		);
	}

	/**
	 * Generate a unique event ID.
	 *
	 * Uses cryptographically secure random_bytes() when available.
	 * Falls back to a pseudo-random ID if entropy is unavailable
	 * (extremely rare on modern systems).
	 *
	 * @return string The event ID (UUID v4 format).
	 */
	private function generateId(): string {
		try {
			$data = random_bytes( 16 );
		} catch ( \Exception $e ) {
			// Fallback for when entropy is unavailable (extremely rare).
			// This is NOT cryptographically secure but ensures system continuity.
			// Log the event for monitoring - this should never happen in production.
			if ( function_exists( 'do_action' ) ) {
				do_action(
					'wch_log_warning',
					'Event ID generated without secure entropy',
					array(
						'error' => $e->getMessage(),
					)
				);
			}

			// Generate pseudo-random data using available sources.
			$data = $this->generateFallbackEntropy();
		}

		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // Version 4.
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // Variant.

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Generate fallback entropy when random_bytes() fails.
	 *
	 * This is NOT cryptographically secure and should only be used
	 * as a last resort to prevent system failure.
	 *
	 * @return string 16 bytes of pseudo-random data.
	 */
	private function generateFallbackEntropy(): string {
		// Combine multiple entropy sources.
		$sources = array(
			microtime( true ),
			getmypid(),
			memory_get_usage( true ),
			spl_object_id( $this ),
			mt_rand(),
			mt_rand(),
		);

		// Add server-specific entropy if available.
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$sources[] = $_SERVER['REQUEST_TIME_FLOAT'];
		}
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$sources[] = $_SERVER['REMOTE_ADDR'];
		}

		// Create a hash and take first 16 bytes.
		$hash = hash( 'sha256', implode( '|', $sources ) . uniqid( '', true ), true );

		return substr( $hash, 0, 16 );
	}

	/**
	 * Get current request ID for tracing.
	 *
	 * Validates the X-Request-ID header to prevent header injection attacks.
	 * Only accepts valid UUID-like formats or alphanumeric strings with
	 * reasonable length (max 64 chars).
	 *
	 * @return string The request ID.
	 */
	private function getRequestId(): string {
		static $request_id = null;

		if ( null === $request_id ) {
			$header_value = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

			// Validate format and length to prevent injection attacks.
			// Accept: UUIDs, alphanumeric strings with hyphens/underscores, max 64 chars.
			if (
				! empty( $header_value ) &&
				strlen( $header_value ) <= 64 &&
				preg_match( '/^[a-zA-Z0-9\-_]+$/', $header_value )
			) {
				$request_id = $header_value;
			} else {
				$request_id = $this->generateId();
			}
		}

		return $request_id;
	}
}
