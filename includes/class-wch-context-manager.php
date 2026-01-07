<?php
/**
 * Context Manager Class
 *
 * Manages conversation context persistence and caching.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Context_Manager
 */
class WCH_Context_Manager {
	/**
	 * Context expiration time (24 hours in seconds).
	 */
	const CONTEXT_EXPIRATION = 86400;

	/**
	 * Global wpdb instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Cache group name for object cache.
	 *
	 * @var string
	 */
	private $cache_group = 'wch_contexts';

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Get context for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return WCH_Conversation_Context Context object.
	 */
	public function get_context( $conversation_id ) {
		// Try to get from cache first.
		$cache_key      = 'context_' . $conversation_id;
		$cached_context = wp_cache_get( $cache_key, $this->cache_group );

		if ( false !== $cached_context ) {
			return $cached_context;
		}

		// Load from database.
		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$row        = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT context, customer_phone FROM {$table_name} WHERE id = %d",
				$conversation_id
			)
		);

		if ( ! $row ) {
			// Return new context if conversation not found.
			return new WCH_Conversation_Context();
		}

		// Parse context JSON.
		$context_data = array();
		if ( ! empty( $row->context ) ) {
			$context_data = json_decode( $row->context, true );
			if ( ! is_array( $context_data ) ) {
				$context_data = array();
			}
		}

		// Add customer phone to context data.
		if ( ! isset( $context_data['customer_phone'] ) && ! empty( $row->customer_phone ) ) {
			$context_data['customer_phone'] = $row->customer_phone;
		}

		// Create context object.
		$context = new WCH_Conversation_Context( $context_data );

		// Cache the context.
		wp_cache_set( $cache_key, $context, $this->cache_group, 300 ); // Cache for 5 minutes.

		return $context;
	}

	/**
	 * Save context for a conversation.
	 *
	 * @param int                      $conversation_id Conversation ID.
	 * @param WCH_Conversation_Context $context Context object.
	 * @return bool Success status.
	 */
	public function save_context( $conversation_id, $context ) {
		$table_name = $this->wpdb->prefix . 'wch_conversations';

		// Convert context to JSON.
		$context_json = $context->to_json();

		// Update database.
		$result = $this->wpdb->update(
			$table_name,
			array(
				'context'         => $context_json,
				'last_message_at' => $context->last_activity_at,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			if ( class_exists( 'WCH_Logger' ) ) {
				WCH_Logger::log(
					'Failed to save context',
					array(
						'conversation_id' => $conversation_id,
						'error'           => $this->wpdb->last_error,
					),
					'error'
				);
			}
			return false;
		}

		// Update cache.
		$cache_key = 'context_' . $conversation_id;
		wp_cache_set( $cache_key, $context, $this->cache_group, 300 );

		// Check for expiration and archive if needed.
		$this->check_and_archive_expired_context( $conversation_id, $context );

		return true;
	}

	/**
	 * Clear context for a conversation.
	 *
	 * Resets to initial state but preserves customer linkage.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool Success status.
	 */
	public function clear_context( $conversation_id ) {
		// Get conversation to preserve customer phone.
		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$row        = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT customer_phone FROM {$table_name} WHERE id = %d",
				$conversation_id
			)
		);

		if ( ! $row ) {
			return false;
		}

		// Create new context with customer phone preserved.
		$context = new WCH_Conversation_Context(
			array( 'customer_phone' => $row->customer_phone )
		);

		// Save the cleared context.
		$result = $this->save_context( $conversation_id, $context );

		// Clear cache.
		$cache_key = 'context_' . $conversation_id;
		wp_cache_delete( $cache_key, $this->cache_group );

		return $result;
	}

	/**
	 * Check for expired context and archive conversation.
	 *
	 * @param int                      $conversation_id Conversation ID.
	 * @param WCH_Conversation_Context $context Context object.
	 */
	private function check_and_archive_expired_context( $conversation_id, $context ) {
		// Check if context has expired (24 hours of inactivity).
		$last_activity_timestamp = strtotime( $context->last_activity_at );
		$current_timestamp       = current_time( 'timestamp' );
		$inactive_duration       = $current_timestamp - $last_activity_timestamp;

		if ( $inactive_duration < self::CONTEXT_EXPIRATION ) {
			return; // Not expired yet.
		}

		// Archive the conversation.
		$table_name = $this->wpdb->prefix . 'wch_conversations';
		$this->wpdb->update(
			$table_name,
			array( 'status' => 'closed' ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Clear the context to start fresh.
		$this->clear_context( $conversation_id );

		if ( class_exists( 'WCH_Logger' ) ) {
			WCH_Logger::log(
				'Conversation archived due to inactivity',
				array(
					'conversation_id'   => $conversation_id,
					'inactive_duration' => $inactive_duration,
				),
				'info'
			);
		}
	}

	/**
	 * Merge old context with new session data.
	 *
	 * Intelligently merges returning customer context.
	 *
	 * @param WCH_Conversation_Context $old_context Old context object.
	 * @param array                    $new_data New session data.
	 * @return WCH_Conversation_Context Merged context.
	 */
	public function merge_contexts( $old_context, $new_data ) {
		// Start with old context data.
		$merged_data = $old_context->to_array();

		// Preserve valuable slots from old context.
		$preserved_slots = array( 'address', 'payment_method', 'preferred_category' );
		$old_slots       = $old_context->get_all_slots();
		$new_slots       = $new_data['slots'] ?? array();

		// Keep old slot values that aren't overridden by new data.
		foreach ( $preserved_slots as $slot_name ) {
			if ( isset( $old_slots[ $slot_name ] ) && ! isset( $new_slots[ $slot_name ] ) ) {
				$new_slots[ $slot_name ] = $old_slots[ $slot_name ];
			}
		}

		// Merge new data (overrides old).
		$merged_data = array_merge( $merged_data, $new_data );

		// Always use merged slots.
		$merged_data['slots'] = $new_slots;

		// Reset timestamps for new session.
		$merged_data['started_at']       = current_time( 'mysql' );
		$merged_data['last_activity_at'] = current_time( 'mysql' );

		// Create new context with merged data.
		$merged_context = new WCH_Conversation_Context( $merged_data );

		if ( class_exists( 'WCH_Logger' ) ) {
			WCH_Logger::log(
				'Contexts merged for returning customer',
				array(
					'preserved_slots' => array_keys( $old_slots ),
					'new_slots'       => array_keys( $new_slots ),
					'merged_slots'    => array_keys( $merged_context->get_all_slots() ),
				),
				'info'
			);
		}

		return $merged_context;
	}

	/**
	 * Get all active conversations with expired contexts.
	 *
	 * @return array Array of conversation IDs.
	 */
	public function get_expired_conversations() {
		$table_name      = $this->wpdb->prefix . 'wch_conversations';
		$expiration_time = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::CONTEXT_EXPIRATION );

		$results = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM {$table_name}
				WHERE status = 'active'
				AND last_message_at < %s",
				$expiration_time
			)
		);

		return $results ? $results : array();
	}

	/**
	 * Batch archive expired conversations.
	 *
	 * @return int Number of conversations archived.
	 */
	public function archive_expired_conversations() {
		$expired_ids    = $this->get_expired_conversations();
		$archived_count = 0;

		foreach ( $expired_ids as $conversation_id ) {
			$context = $this->get_context( $conversation_id );
			$this->check_and_archive_expired_context( $conversation_id, $context );
			++$archived_count;
		}

		return $archived_count;
	}
}
