<?php
/**
 * Conversations REST Controller
 *
 * Handles REST API endpoints for conversation management.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationsController
 *
 * REST API controller for conversation endpoints.
 */
class ConversationsController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'conversations';

	/**
	 * Valid conversation statuses.
	 *
	 * @var array
	 */
	private const VALID_STATUSES = array( 'pending', 'active', 'closed' );

	/**
	 * SECURITY: Check if current user can access a specific conversation.
	 *
	 * This prevents IDOR vulnerabilities where an agent could access
	 * conversations assigned to other agents by guessing IDs.
	 *
	 * Access is granted if:
	 * - User is an administrator
	 * - User is the assigned agent for this conversation
	 * - Conversation is unassigned and restriction is disabled
	 * - Filter `wch_can_access_conversation` returns true
	 *
	 * @param int $conversationId Conversation ID.
	 * @return bool|WP_Error True if access granted, WP_Error otherwise.
	 */
	private function checkConversationAccess( int $conversationId ) {
		// Administrators can access all conversations.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$currentUserId = get_current_user_id();

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$assignedAgentId = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT assigned_agent_id FROM {$tableConversations} WHERE id = %d",
				$conversationId
			)
		);

		// Conversation doesn't exist - let the main handler return 404.
		if ( null === $assignedAgentId ) {
			return true;
		}

		$assignedAgentId = (int) $assignedAgentId;

		// User is the assigned agent.
		if ( $assignedAgentId === $currentUserId ) {
			return true;
		}

		// Check if unassigned conversations are accessible to all agents.
		// Default: unassigned conversations (agent_id = 0) are accessible to all.
		$allowUnassigned = apply_filters( 'wch_agents_can_access_unassigned', true );
		if ( 0 === $assignedAgentId && $allowUnassigned ) {
			return true;
		}

		// Allow plugins/themes to override access control.
		$canAccess = apply_filters( 'wch_can_access_conversation', false, $conversationId, $currentUserId, $assignedAgentId );
		if ( $canAccess ) {
			return true;
		}

		$this->log(
			'Conversation access denied (IDOR protection)',
			array(
				'conversation_id'   => $conversationId,
				'user_id'           => $currentUserId,
				'assigned_agent_id' => $assignedAgentId,
			),
			'warning'
		);

		return new WP_Error(
			'wch_rest_forbidden',
			__( 'You do not have permission to access this conversation.', 'whatsapp-commerce-hub' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Valid bulk actions.
	 *
	 * @var array
	 */
	private const VALID_BULK_ACTIONS = array( 'assign', 'close', 'export' );

	/**
	 * Maximum items per page.
	 *
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Maximum bulk operation items.
	 *
	 * @var int
	 */
	private const MAX_BULK_ITEMS = 100;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// List conversations.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getConversations' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getCollectionParams(),
				),
			)
		);

		// Single conversation.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getConversation' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'updateConversation' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getUpdateArgs(),
				),
			)
		);

		// Conversation messages.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getMessages' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getMessagesArgs(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sendMessage' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getSendMessageArgs(),
				),
			)
		);

		// Bulk operations.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'bulkUpdate' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getBulkArgs(),
				),
			)
		);

		// Suggest reply.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/suggest-reply',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'suggestReply' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'conversation_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Get conversations list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getConversations( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';
		$tableMessages      = $wpdb->prefix . 'wch_messages';
		$tableProfiles      = $wpdb->prefix . 'wch_customer_profiles';

		$search  = $request->get_param( 'search' );
		$status  = $request->get_param( 'status' );
		$agentId = $request->get_param( 'agent_id' );
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset  = ( $page - 1 ) * $perPage;

		$where       = array( '1=1' );
		$whereValues = array();

		if ( ! empty( $search ) ) {
			$where[]       = '(c.customer_phone LIKE %s OR p.name LIKE %s)';
			$searchTerm    = '%' . $wpdb->esc_like( $search ) . '%';
			$whereValues[] = $searchTerm;
			$whereValues[] = $searchTerm;
		}

		if ( ! empty( $status ) ) {
			$where[]       = 'c.status = %s';
			$whereValues[] = $status;
		}

		if ( ! empty( $agentId ) ) {
			$where[]       = 'c.assigned_agent_id = %d';
			$whereValues[] = $agentId;
		}

		$whereClause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = "
			SELECT
				c.id,
				c.customer_phone,
				c.wa_conversation_id,
				c.status,
				c.assigned_agent_id,
				c.last_message_at,
				c.created_at,
				c.updated_at,
				p.name as customer_name,
				p.wc_customer_id,
				u.display_name as agent_name,
				(SELECT COUNT(*) FROM {$tableMessages} WHERE conversation_id = c.id AND direction = 'inbound' AND status != 'read') as unread_count,
				(SELECT content FROM {$tableMessages} WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_content,
				(SELECT message_type FROM {$tableMessages} WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_type
			FROM {$tableConversations} c
			LEFT JOIN {$tableProfiles} p ON c.customer_phone = p.phone
			LEFT JOIN {$wpdb->users} u ON c.assigned_agent_id = u.ID
			WHERE {$whereClause}
			ORDER BY c.last_message_at DESC
			LIMIT %d OFFSET %d
		";

		$whereValues[] = $perPage;
		$whereValues[] = $offset;

		if ( ! empty( $whereValues ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$query = $wpdb->prepare( $query, $whereValues );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversations = $wpdb->get_results( $query, ARRAY_A );

		// Get total count.
		$countQuery = "SELECT COUNT(*) FROM {$tableConversations} c LEFT JOIN {$tableProfiles} p ON c.customer_phone = p.phone WHERE {$whereClause}";
		if ( count( $whereValues ) > 2 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$countQuery = $wpdb->prepare( $countQuery, array_slice( $whereValues, 0, -2 ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $countQuery );

		// Process conversations.
		foreach ( $conversations as &$conversation ) {
			if ( ! empty( $conversation['last_message_content'] ) ) {
				$content                              = json_decode( $conversation['last_message_content'], true );
				$conversation['last_message_preview'] = $this->getMessagePreview( $content, $conversation['last_message_type'] );
			}
			unset( $conversation['last_message_content'] );
		}

		$response = rest_ensure_response( $conversations );
		$response = $this->addPaginationHeaders( $response, $total, $perPage, $page );

		return $this->prepareResponse( $response, $request );
	}

	/**
	 * Get single conversation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getConversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$id = (int) $request['id'];

		// SECURITY: Check conversation access (IDOR protection).
		$accessResult = $this->checkConversationAccess( $id );
		if ( is_wp_error( $accessResult ) ) {
			return $accessResult;
		}

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';
		$tableProfiles      = $wpdb->prefix . 'wch_customer_profiles';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					c.*,
					p.name as customer_name,
					p.wc_customer_id,
					p.saved_addresses,
					p.preferences,
					u.display_name as agent_name
				FROM {$tableConversations} c
				LEFT JOIN {$tableProfiles} p ON c.customer_phone = p.phone
				LEFT JOIN {$wpdb->users} u ON c.assigned_agent_id = u.ID
				WHERE c.id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $conversation ) {
			return $this->prepareError(
				'conversation_not_found',
				__( 'Conversation not found', 'whatsapp-commerce-hub' ),
				array(),
				404
			);
		}

		$conversation['context']         = json_decode( $conversation['context'] ?? '{}', true );
		$conversation['saved_addresses'] = json_decode( $conversation['saved_addresses'] ?? '[]', true );
		$conversation['preferences']     = json_decode( $conversation['preferences'] ?? '{}', true );

		return $this->prepareResponse( $conversation, $request );
	}

	/**
	 * Get conversation messages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getMessages( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$conversationId = (int) $request['id'];

		// SECURITY: Check conversation access (IDOR protection).
		$accessResult = $this->checkConversationAccess( $conversationId );
		if ( is_wp_error( $accessResult ) ) {
			return $accessResult;
		}

		global $wpdb;
		$tableMessages = $wpdb->prefix . 'wch_messages';
		$page          = max( 1, (int) $request->get_param( 'page' ) );
		$perPage       = min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset        = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tableMessages} WHERE conversation_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
				$conversationId,
				$perPage,
				$offset
			),
			ARRAY_A
		);

		foreach ( $messages as &$message ) {
			$message['content'] = json_decode( $message['content'] ?? '{}', true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tableMessages} WHERE conversation_id = %d", $conversationId )
		);

		$response = rest_ensure_response( $messages );
		$response = $this->addPaginationHeaders( $response, $total, $perPage, $page );

		return $this->prepareResponse( $response, $request );
	}

	/**
	 * Send a message.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sendMessage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$conversationId = (int) $request['id'];

		// SECURITY: Check conversation access (IDOR protection).
		$accessResult = $this->checkConversationAccess( $conversationId );
		if ( is_wp_error( $accessResult ) ) {
			return $accessResult;
		}

		$messageText = $request['message'];

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';
		$tableMessages      = $wpdb->prefix . 'wch_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tableConversations} WHERE id = %d", $conversationId ),
			ARRAY_A
		);

		if ( ! $conversation ) {
			return $this->prepareError(
				'conversation_not_found',
				__( 'Conversation not found', 'whatsapp-commerce-hub' ),
				array(),
				404
			);
		}

		$whatsappApi = \WCH_WhatsApp_API::getInstance();
		$result      = $whatsappApi->send_text_message( $conversation['customer_phone'], $messageText );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$messageData = array(
			'conversation_id' => $conversationId,
			'direction'       => 'outbound',
			'message_type'    => 'text',
			'wa_message_id'   => $result['message_id'],
			'content'         => wp_json_encode( array( 'text' => $messageText ) ),
			'status'          => 'sent',
			'created_at'      => current_time( 'mysql' ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $tableMessages, $messageData );
		$messageData['id']      = $wpdb->insert_id;
		$messageData['content'] = json_decode( $messageData['content'], true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$tableConversations,
			array( 'last_message_at' => current_time( 'mysql' ) ),
			array( 'id' => $conversationId )
		);

		return $this->prepareResponse( $messageData, $request );
	}

	/**
	 * Update conversation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateConversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$id = (int) $request['id'];

		// SECURITY: Check conversation access (IDOR protection).
		$accessResult = $this->checkConversationAccess( $id );
		if ( is_wp_error( $accessResult ) ) {
			return $accessResult;
		}

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';

		$updateData = array();

		if ( $request->has_param( 'status' ) ) {
			$updateData['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}

		if ( $request->has_param( 'assigned_agent_id' ) ) {
			$agentId = absint( $request->get_param( 'assigned_agent_id' ) );
			if ( $agentId > 0 ) {
				$user = get_user_by( 'id', $agentId );
				if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
					return $this->prepareError(
						'invalid_agent',
						__( 'Invalid agent ID', 'whatsapp-commerce-hub' ),
						array(),
						400
					);
				}
			}
			$updateData['assigned_agent_id'] = $agentId;
		}

		if ( empty( $updateData ) ) {
			return $this->prepareError(
				'no_updates',
				__( 'No valid update fields provided', 'whatsapp-commerce-hub' ),
				array(),
				400
			);
		}

		$updateData['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$tableConversations,
			$updateData,
			array( 'id' => $id )
		);

		if ( false === $result ) {
			return $this->prepareError(
				'update_failed',
				__( 'Failed to update conversation', 'whatsapp-commerce-hub' ),
				array(),
				500
			);
		}

		return $this->getConversation( $request );
	}

	/**
	 * Bulk update conversations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulkUpdate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';

		$ids    = array_map( 'absint', $request->get_param( 'ids' ) );
		$action = $request->get_param( 'action' );

		if ( empty( $ids ) ) {
			return $this->prepareError(
				'no_ids',
				__( 'No conversation IDs provided', 'whatsapp-commerce-hub' ),
				array(),
				400
			);
		}

		// SECURITY: Check access to ALL conversations (IDOR protection for bulk operations).
		// Non-admins can only update conversations they have access to.
		if ( ! current_user_can( 'manage_options' ) ) {
			$unauthorizedIds = array();
			foreach ( $ids as $id ) {
				$accessResult = $this->checkConversationAccess( $id );
				if ( is_wp_error( $accessResult ) ) {
					$unauthorizedIds[] = $id;
				}
			}

			if ( ! empty( $unauthorizedIds ) ) {
				$this->log(
					'Bulk operation denied - unauthorized conversation IDs',
					array(
						'user_id'         => get_current_user_id(),
						'unauthorized'    => $unauthorizedIds,
						'total_requested' => count( $ids ),
					),
					'warning'
				);

				return new WP_Error(
					'wch_rest_forbidden',
					sprintf(
						/* translators: %d: count of unauthorized IDs */
						__( 'You do not have permission to modify %d of the selected conversations.', 'whatsapp-commerce-hub' ),
						count( $unauthorizedIds )
					),
					array( 'status' => 403 )
				);
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$updated      = 0;

		switch ( $action ) {
			case 'assign':
				$agentId = absint( $request->get_param( 'agent_id' ) );
				if ( $agentId > 0 ) {
					$user = get_user_by( 'id', $agentId );
					if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
						return $this->prepareError(
							'invalid_agent',
							__( 'Invalid agent ID', 'whatsapp-commerce-hub' ),
							array(),
							400
						);
					}
				}

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				// Placeholder count varies based on number of IDs. Table names from wpdb->prefix.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tableConversations} SET assigned_agent_id = %d, updated_at = %s WHERE id IN ({$placeholders})",
						array_merge( array( $agentId, current_time( 'mysql' ) ), $ids )
					)
				);
				// phpcs:enable
				break;

			case 'close':
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				// Placeholder count varies based on number of IDs. Table names from wpdb->prefix.
				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tableConversations} SET status = 'closed', updated_at = %s WHERE id IN ({$placeholders})",
						array_merge( array( current_time( 'mysql' ) ), $ids )
					)
				);
				break;

			case 'export':
				return $this->exportConversations( $ids, $request );

			default:
				return $this->prepareError(
					'invalid_action',
					__( 'Invalid bulk action', 'whatsapp-commerce-hub' ),
					array(),
					400
				);
		}

		return $this->prepareResponse(
			array(
				'success' => true,
				'updated' => $updated,
			),
			$request
		);
	}

	/**
	 * Suggest reply using AI.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggestReply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$conversationId = (int) $request->get_param( 'conversation_id' );

		// SECURITY: Check conversation access (IDOR protection).
		$accessResult = $this->checkConversationAccess( $conversationId );
		if ( is_wp_error( $accessResult ) ) {
			return $accessResult;
		}

		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';
		$tableMessages      = $wpdb->prefix . 'wch_messages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tableConversations} WHERE id = %d", $conversationId ),
			ARRAY_A
		);

		if ( ! $conversation ) {
			return $this->prepareError(
				'conversation_not_found',
				__( 'Conversation not found', 'whatsapp-commerce-hub' ),
				array(),
				404
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tableMessages} WHERE conversation_id = %d ORDER BY created_at DESC LIMIT 10",
				$conversationId
			),
			ARRAY_A
		);

		$context             = json_decode( $conversation['context'] ?? '{}', true );
		$conversationHistory = array();

		foreach ( array_reverse( $messages ) as $msg ) {
			$content               = json_decode( $msg['content'] ?? '{}', true );
			$conversationHistory[] = array(
				'role'      => 'inbound' === $msg['direction'] ? 'customer' : 'agent',
				'message'   => $this->getMessageText( $content, $msg['message_type'] ),
				'timestamp' => $msg['created_at'],
			);
		}

		$aiService      = \WCH_AI_Service::getInstance();
		$suggestedReply = $aiService->suggest_agent_reply( $conversationHistory, $context );

		if ( is_wp_error( $suggestedReply ) ) {
			return $suggestedReply;
		}

		return $this->prepareResponse(
			array( 'suggestion' => $suggestedReply ),
			$request
		);
	}

	/**
	 * Export conversations to CSV.
	 *
	 * @param array           $ids     Conversation IDs.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	private function exportConversations( array $ids, WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$tableConversations = $wpdb->prefix . 'wch_conversations';
		$tableProfiles      = $wpdb->prefix . 'wch_customer_profiles';

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// Placeholder count varies based on number of IDs. Table names from wpdb->prefix.
		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, p.name as customer_name FROM {$tableConversations} c
				 LEFT JOIN {$tableProfiles} p ON c.customer_phone = p.phone
				 WHERE c.id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$csvData   = array();
		$csvData[] = array( 'ID', 'Customer Phone', 'Customer Name', 'Status', 'Assigned Agent ID', 'Last Message At', 'Created At' );

		foreach ( $conversations as $conv ) {
			$csvData[] = array(
				$conv['id'],
				$conv['customer_phone'],
				$conv['customer_name'] ?? '',
				$conv['status'],
				$conv['assigned_agent_id'] ?? '',
				$conv['last_message_at'],
				$conv['created_at'],
			);
		}

		$csvContent = '';
		foreach ( $csvData as $row ) {
			$escapedRow  = array_map(
				function ( $field ) {
					return '"' . str_replace( '"', '""', (string) $field ) . '"';
				},
				$row
			);
			$csvContent .= implode( ',', $escapedRow ) . "\n";
		}

		return new WP_REST_Response(
			array(
				'csv'      => $csvContent,
				'filename' => 'conversations-' . gmdate( 'Y-m-d-His' ) . '.csv',
			),
			200
		);
	}

	/**
	 * Get message preview text.
	 *
	 * @param array|null $content Message content.
	 * @param string     $type    Message type.
	 * @return string
	 */
	private function getMessagePreview( ?array $content, string $type ): string {
		$text = $this->getMessageText( $content, $type );
		return mb_strlen( $text ) > 50 ? mb_substr( $text, 0, 47 ) . '...' : $text;
	}

	/**
	 * Get message text.
	 *
	 * @param array|string|null $content Message content.
	 * @param string            $type    Message type.
	 * @return string
	 */
	private function getMessageText( $content, string $type ): string {
		if ( is_string( $content ) ) {
			$content = json_decode( $content, true );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		switch ( $type ) {
			case 'text':
				return $content['text'] ?? '';
			case 'interactive':
				return $content['interactive']['body']['text'] ?? '[Interactive message]';
			case 'image':
				return '[Image]';
			case 'document':
				return '[Document]';
			case 'template':
				return '[Template message]';
			default:
				return '[Unknown message type]';
		}
	}

	/**
	 * Get collection params.
	 *
	 * @return array
	 */
	private function getCollectionParams(): array {
		return array(
			'search'   => array(
				'description'       => __( 'Search by phone number or customer name', 'whatsapp-commerce-hub' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'description'       => __( 'Filter by status', 'whatsapp-commerce-hub' ),
				'type'              => 'string',
				'enum'              => self::VALID_STATUSES,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'agent_id' => array(
				'description'       => __( 'Filter by assigned agent', 'whatsapp-commerce-hub' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'description'       => __( 'Current page', 'whatsapp-commerce-hub' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Results per page', 'whatsapp-commerce-hub' ),
				'type'              => 'integer',
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get update args.
	 *
	 * @return array
	 */
	private function getUpdateArgs(): array {
		return array(
			'id'                => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'status'            => array(
				'type'              => 'string',
				'enum'              => self::VALID_STATUSES,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'assigned_agent_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get messages args.
	 *
	 * @return array
	 */
	private function getMessagesArgs(): array {
		return array(
			'id'       => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'default'           => 50,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'page'     => array(
				'default'           => 1,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get send message args.
	 *
	 * @return array
	 */
	private function getSendMessageArgs(): array {
		return array(
			'id'      => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'message' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Get bulk operation args.
	 *
	 * @return array
	 */
	private function getBulkArgs(): array {
		return array(
			'ids'      => array(
				'required'          => true,
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'minItems'          => 1,
				'maxItems'          => self::MAX_BULK_ITEMS,
				'sanitize_callback' => array( $this, 'sanitizeIdsArray' ),
				'validate_callback' => array( $this, 'validateIdsArray' ),
			),
			'action'   => array(
				'required' => true,
				'type'     => 'string',
				'enum'     => self::VALID_BULK_ACTIONS,
			),
			'agent_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Sanitize IDs array.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return array
	 */
	public function sanitizeIdsArray( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $id ) {
			$intId = absint( $id );
			if ( $intId > 0 ) {
				$sanitized[] = $intId;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Validate IDs array.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validateIdsArray( $value, WP_REST_Request $request, string $param ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s must be an array.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s must contain at least one ID.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( count( $value ) > self::MAX_BULK_ITEMS ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s cannot contain more than 100 IDs.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		foreach ( $value as $index => $id ) {
			if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: 1: parameter name, 2: array index */
						__( '%1$s[%2$d] must be a positive integer.', 'whatsapp-commerce-hub' ),
						$param,
						$index
					),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Get item schema for conversations.
	 *
	 * @return array
	 */
	public function getItemSchema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'conversation',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Conversation ID', 'whatsapp-commerce-hub' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'customer_phone'  => array(
					'description' => __( 'Customer phone number', 'whatsapp-commerce-hub' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'last_message'    => array(
					'description' => __( 'Last message content', 'whatsapp-commerce-hub' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'last_message_at' => array(
					'description' => __( 'Last message timestamp', 'whatsapp-commerce-hub' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view' ),
				),
				'status'          => array(
					'description' => __( 'Conversation status', 'whatsapp-commerce-hub' ),
					'type'        => 'string',
					'enum'        => self::VALID_STATUSES,
					'context'     => array( 'view', 'edit' ),
				),
			),
		);
	}
}
