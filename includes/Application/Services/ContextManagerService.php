<?php
/**
 * Context Manager Service
 *
 * Manages conversation context persistence and caching.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\ContextManagerInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ContextManagerService
 *
 * Manages conversation context with caching and persistence.
 */
class ContextManagerService implements ContextManagerInterface {

	/**
	 * Context expiration time (24 hours in seconds).
	 */
	public const CONTEXT_EXPIRATION = 86400;

	/**
	 * Cache duration (5 minutes in seconds).
	 */
	public const CACHE_DURATION = 300;

	/**
	 * WordPress database.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * Logger service.
	 *
	 * @var LoggerInterface|null
	 */
	protected ?LoggerInterface $logger;

	/**
	 * Cache group name.
	 *
	 * @var string
	 */
	protected string $cacheGroup = 'wch_contexts';

	/**
	 * Preserved slots for returning customers.
	 *
	 * @var string[]
	 */
	protected array $preservedSlots = [ 'address', 'payment_method', 'preferred_category' ];

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null           $wpdb   WordPress database.
	 * @param LoggerInterface|null $logger Logger service.
	 */
	public function __construct( ?\wpdb $wpdb = null, ?LoggerInterface $logger = null ) {
		global $wpdb;
		$this->wpdb   = $wpdb ?? $wpdb;
		$this->logger = $logger;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getContext( int $conversationId ): ConversationContext {
		// Try cache first.
		$cacheKey = "context_{$conversationId}";
		$cached   = wp_cache_get( $cacheKey, $this->cacheGroup );

		if ( false !== $cached && $cached instanceof ConversationContext ) {
			return $cached;
		}

		// Load from database.
		$tableName = $this->wpdb->prefix . 'wch_conversations';
		$row       = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT context, customer_phone FROM {$tableName} WHERE id = %d",
				$conversationId
			)
		);

		if ( ! $row ) {
			return new ConversationContext();
		}

		// Parse context JSON.
		$contextData = [];
		if ( ! empty( $row->context ) ) {
			$decoded = json_decode( $row->context, true );
			if ( is_array( $decoded ) ) {
				$contextData = $decoded;
			}
		}

		// Add customer phone.
		if ( ! isset( $contextData['customer_phone'] ) && ! empty( $row->customer_phone ) ) {
			$contextData['customer_phone'] = $row->customer_phone;
		}

		$context = new ConversationContext( $contextData );

		// Cache the context.
		wp_cache_set( $cacheKey, $context, $this->cacheGroup, self::CACHE_DURATION );

		return $context;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getContextByPhone( string $phone ): ConversationContext {
		$tableName = $this->wpdb->prefix . 'wch_conversations';

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT id, context FROM {$tableName} WHERE customer_phone = %s AND status = 'active' ORDER BY updated_at DESC LIMIT 1",
				$phone
			)
		);

		if ( ! $row ) {
			return ConversationContext::forPhone( $phone );
		}

		$contextData = [];
		if ( ! empty( $row->context ) ) {
			$decoded = json_decode( $row->context, true );
			if ( is_array( $decoded ) ) {
				$contextData = $decoded;
			}
		}

		$contextData['customer_phone'] = $phone;

		return new ConversationContext( $contextData );
	}

	/**
	 * {@inheritdoc}
	 */
	public function saveContext( int $conversationId, ConversationContext $context ): bool {
		$tableName   = $this->wpdb->prefix . 'wch_conversations';
		$contextJson = $context->toJson();

		$result = $this->wpdb->update(
			$tableName,
			[
				'context'         => $contextJson,
				'last_message_at' => $context->getLastActivityAt(),
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $conversationId ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $result ) {
			$this->log(
				'error',
				'Failed to save context',
				[
					'conversation_id' => $conversationId,
					'error'           => $this->wpdb->last_error,
				]
			);
			return false;
		}

		// Update cache.
		$cacheKey = "context_{$conversationId}";
		wp_cache_set( $cacheKey, $context, $this->cacheGroup, self::CACHE_DURATION );

		// Check for expiration.
		$this->checkAndArchiveExpiredContext( $conversationId, $context );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function clearContext( int $conversationId ): bool {
		$tableName = $this->wpdb->prefix . 'wch_conversations';

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT customer_phone FROM {$tableName} WHERE id = %d",
				$conversationId
			)
		);

		if ( ! $row ) {
			return false;
		}

		// Create new context with customer phone preserved.
		$context = ConversationContext::forPhone( $row->customer_phone );

		// Save the cleared context.
		$result = $this->saveContext( $conversationId, $context );

		// Clear cache.
		$cacheKey = "context_{$conversationId}";
		wp_cache_delete( $cacheKey, $this->cacheGroup );

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function mergeContexts( ConversationContext $oldContext, array $newData ): ConversationContext {
		// Start with old context data.
		$mergedData = $oldContext->toArray();

		// Get old and new slots.
		$oldSlots = $oldContext->getAllSlots();
		$newSlots = $newData['slots'] ?? [];

		// Keep preserved slot values that aren't overridden.
		foreach ( $this->preservedSlots as $slotName ) {
			if ( isset( $oldSlots[ $slotName ] ) && ! isset( $newSlots[ $slotName ] ) ) {
				$newSlots[ $slotName ] = $oldSlots[ $slotName ];
			}
		}

		// Merge new data.
		$mergedData          = array_merge( $mergedData, $newData );
		$mergedData['slots'] = $newSlots;

		// Reset timestamps.
		$mergedData['started_at']       = gmdate( 'Y-m-d H:i:s' );
		$mergedData['last_activity_at'] = gmdate( 'Y-m-d H:i:s' );

		$mergedContext = new ConversationContext( $mergedData );

		$this->log(
			'info',
			'Contexts merged for returning customer',
			[
				'preserved_slots' => array_keys( $oldSlots ),
				'new_slots'       => array_keys( $newSlots ),
			]
		);

		return $mergedContext;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getExpiredConversations(): array {
		$tableName      = $this->wpdb->prefix . 'wch_conversations';
		$expirationTime = gmdate( 'Y-m-d H:i:s', time() - self::CONTEXT_EXPIRATION );

		$results = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$tableName} WHERE status = 'active' AND last_message_at < %s",
				$expirationTime
			)
		);

		return $results ? array_map( 'intval', $results ) : [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function archiveExpiredConversations(): int {
		$expiredIds    = $this->getExpiredConversations();
		$archivedCount = 0;

		foreach ( $expiredIds as $conversationId ) {
			$context = $this->getContext( $conversationId );
			$this->checkAndArchiveExpiredContext( $conversationId, $context );
			++$archivedCount;
		}

		return $archivedCount;
	}

	/**
	 * Check for expired context and archive conversation.
	 *
	 * @param int                 $conversationId Conversation ID.
	 * @param ConversationContext $context        Context object.
	 * @return void
	 */
	protected function checkAndArchiveExpiredContext( int $conversationId, ConversationContext $context ): void {
		$inactiveDuration = $context->getInactiveDuration();

		if ( $inactiveDuration < self::CONTEXT_EXPIRATION ) {
			return;
		}

		// Archive the conversation.
		$tableName = $this->wpdb->prefix . 'wch_conversations';
		$this->wpdb->update(
			$tableName,
			[ 'status' => 'closed' ],
			[ 'id' => $conversationId ],
			[ '%s' ],
			[ '%d' ]
		);

		// Clear the context.
		$this->clearContext( $conversationId );

		$this->log(
			'info',
			'Conversation archived due to inactivity',
			[
				'conversation_id'   => $conversationId,
				'inactive_duration' => $inactiveDuration,
			]
		);
	}

	/**
	 * Set preserved slots.
	 *
	 * @param string[] $slots Slot names to preserve.
	 * @return void
	 */
	public function setPreservedSlots( array $slots ): void {
		$this->preservedSlots = $slots;
	}

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function log( string $level, string $message, array $context = [] ): void {
		if ( null !== $this->logger ) {
			$this->logger->log( $level, $message, 'context_manager', $context );
			return;
		}

		// Fallback to legacy logger.
		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
