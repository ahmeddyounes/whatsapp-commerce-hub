<?php
/**
 * REST API Controller for Conversations
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCH_Conversations_Controller extends WCH_REST_Controller {

	protected $namespace = 'wch/v1';
	protected $rest_base = 'conversations';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversations' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
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
					'callback'            => array( $this, 'update_conversation' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_messages' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
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
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_message' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
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
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'bulk_update' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
					'args'                => array(
						'ids'      => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array(
								'type' => 'integer',
							),
							'minItems'          => 1,
							'maxItems'          => 100,
							'sanitize_callback' => array( $this, 'sanitize_ids_array' ),
							'validate_callback' => array( $this, 'validate_ids_array' ),
						),
						'action'   => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( 'assign', 'close', 'export' ),
						),
						'agent_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/suggest-reply',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'suggest_reply' ),
					'permission_callback' => array( $this, 'check_admin_permission' ),
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

	public function get_conversations( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		// The previous `! $this->check_rate_limit()` pattern was always false.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		global $wpdb;
		$table_conversations = $wpdb->prefix . 'wch_conversations';
		$table_messages      = $wpdb->prefix . 'wch_messages';
		$table_profiles      = $wpdb->prefix . 'wch_customer_profiles';

		$search   = $request->get_param( 'search' );
		$status   = $request->get_param( 'status' );
		$agent_id = $request->get_param( 'agent_id' );
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where        = array( '1=1' );
		$where_values = array();

		if ( ! empty( $search ) ) {
			$where[]        = '(c.customer_phone LIKE %s OR p.name LIKE %s)';
			$search_term    = '%' . $wpdb->esc_like( $search ) . '%';
			$where_values[] = $search_term;
			$where_values[] = $search_term;
		}

		if ( ! empty( $status ) ) {
			$where[]        = 'c.status = %s';
			$where_values[] = $status;
		}

		if ( ! empty( $agent_id ) ) {
			$where[]        = 'c.assigned_agent_id = %d';
			$where_values[] = $agent_id;
		}

		$where_clause = implode( ' AND ', $where );

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
                (SELECT COUNT(*) FROM $table_messages WHERE conversation_id = c.id AND direction = 'inbound' AND status != 'read') as unread_count,
                (SELECT content FROM $table_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_content,
                (SELECT message_type FROM $table_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_type
            FROM $table_conversations c
            LEFT JOIN $table_profiles p ON c.customer_phone = p.phone
            LEFT JOIN {$wpdb->users} u ON c.assigned_agent_id = u.ID
            WHERE $where_clause
            ORDER BY c.last_message_at DESC
            LIMIT %d OFFSET %d
        ";

		$where_values[] = $per_page;
		$where_values[] = $offset;

		if ( ! empty( $where_values ) ) {
			$query = $wpdb->prepare( $query, $where_values );
		}

		$conversations = $wpdb->get_results( $query, ARRAY_A );

		$count_query = "SELECT COUNT(*) FROM $table_conversations c LEFT JOIN $table_profiles p ON c.customer_phone = p.phone WHERE $where_clause";
		if ( count( $where_values ) > 2 ) {
			$count_query = $wpdb->prepare( $count_query, array_slice( $where_values, 0, -2 ) );
		}
		$total = (int) $wpdb->get_var( $count_query );

		foreach ( $conversations as &$conversation ) {
			if ( ! empty( $conversation['last_message_content'] ) ) {
				$content                              = json_decode( $conversation['last_message_content'], true );
				$conversation['last_message_preview'] = $this->get_message_preview( $content, $conversation['last_message_type'] );
			}
			unset( $conversation['last_message_content'] );
		}

		$response = rest_ensure_response( $conversations );
		$response = $this->add_pagination_headers( $response, $total, $per_page, $page );

		return $this->prepare_response( $response, $request );
	}

	public function get_conversation( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		global $wpdb;
		$table_conversations = $wpdb->prefix . 'wch_conversations';
		$table_profiles      = $wpdb->prefix . 'wch_customer_profiles';

		$id = $request['id'];

		$query = $wpdb->prepare(
			"
            SELECT
                c.*,
                p.name as customer_name,
                p.wc_customer_id,
                p.saved_addresses,
                p.preferences,
                u.display_name as agent_name
            FROM $table_conversations c
            LEFT JOIN $table_profiles p ON c.customer_phone = p.phone
            LEFT JOIN {$wpdb->users} u ON c.assigned_agent_id = u.ID
            WHERE c.id = %d
        ",
			$id
		);

		$conversation = $wpdb->get_row( $query, ARRAY_A );

		if ( ! $conversation ) {
			return new WP_Error( 'conversation_not_found', __( 'Conversation not found', 'whatsapp-commerce-hub' ), array( 'status' => 404 ) );
		}

		$conversation['context']         = json_decode( $conversation['context'], true );
		$conversation['saved_addresses'] = json_decode( $conversation['saved_addresses'], true );
		$conversation['preferences']     = json_decode( $conversation['preferences'], true );

		return $this->prepare_response( $conversation, $request );
	}

	public function get_messages( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		global $wpdb;
		$table_messages = $wpdb->prefix . 'wch_messages';

		$conversation_id = $request['id'];
		$page            = max( 1, $request->get_param( 'page' ) );
		$per_page        = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset          = ( $page - 1 ) * $per_page;

		$query = $wpdb->prepare(
			"
            SELECT *
            FROM $table_messages
            WHERE conversation_id = %d
            ORDER BY created_at ASC
            LIMIT %d OFFSET %d
        ",
			$conversation_id,
			$per_page,
			$offset
		);

		$messages = $wpdb->get_results( $query, ARRAY_A );

		foreach ( $messages as &$message ) {
			$message['content'] = json_decode( $message['content'], true );
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_messages WHERE conversation_id = %d", $conversation_id ) );

		$response = rest_ensure_response( $messages );
		$response = $this->add_pagination_headers( $response, $total, $per_page, $page );

		return $this->prepare_response( $response, $request );
	}

	public function send_message( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		$conversation_id = $request['id'];
		$message_text    = $request['message'];

		global $wpdb;
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_conversations WHERE id = %d",
				$conversation_id
			),
			ARRAY_A
		);

		if ( ! $conversation ) {
			return new WP_Error( 'conversation_not_found', __( 'Conversation not found', 'whatsapp-commerce-hub' ), array( 'status' => 404 ) );
		}

		$whatsapp_api = WCH_WhatsApp_API::getInstance();
		$result       = $whatsapp_api->send_text_message( $conversation['customer_phone'], $message_text );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$message_data = array(
			'conversation_id' => $conversation_id,
			'direction'       => 'outbound',
			'message_type'    => 'text',
			'wa_message_id'   => $result['message_id'],
			'content'         => json_encode( array( 'text' => $message_text ) ),
			'status'          => 'sent',
			'created_at'      => current_time( 'mysql' ),
		);

		$wpdb->insert( $wpdb->prefix . 'wch_messages', $message_data );
		$message_data['id']      = $wpdb->insert_id;
		$message_data['content'] = json_decode( $message_data['content'], true );

		$wpdb->update(
			$wpdb->prefix . 'wch_conversations',
			array( 'last_message_at' => current_time( 'mysql' ) ),
			array( 'id' => $conversation_id )
		);

		return $this->prepare_response( $message_data, $request );
	}

	public function update_conversation( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		global $wpdb;
		$id          = $request['id'];
		$update_data = array();

		if ( $request->has_param( 'status' ) ) {
			$update_data['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}

		if ( $request->has_param( 'assigned_agent_id' ) ) {
			$agent_id = absint( $request->get_param( 'assigned_agent_id' ) );
			if ( $agent_id > 0 ) {
				$user = get_user_by( 'id', $agent_id );
				if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
					return new WP_Error( 'invalid_agent', __( 'Invalid agent ID', 'whatsapp-commerce-hub' ), array( 'status' => 400 ) );
				}
			}
			$update_data['assigned_agent_id'] = $agent_id;
		}

		if ( empty( $update_data ) ) {
			return new WP_Error( 'no_updates', __( 'No valid update fields provided', 'whatsapp-commerce-hub' ), array( 'status' => 400 ) );
		}

		$update_data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			$wpdb->prefix . 'wch_conversations',
			$update_data,
			array( 'id' => $id )
		);

		if ( $result === false ) {
			return new WP_Error( 'update_failed', __( 'Failed to update conversation', 'whatsapp-commerce-hub' ), array( 'status' => 500 ) );
		}

		return $this->get_conversation( $request );
	}

	public function bulk_update( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		global $wpdb;
		$ids    = array_map( 'absint', $request->get_param( 'ids' ) );
		$action = $request->get_param( 'action' );

		if ( empty( $ids ) ) {
			return new WP_Error( 'no_ids', __( 'No conversation IDs provided', 'whatsapp-commerce-hub' ), array( 'status' => 400 ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$updated      = 0;

		switch ( $action ) {
			case 'assign':
				$agent_id = absint( $request->get_param( 'agent_id' ) );
				if ( $agent_id > 0 ) {
					$user = get_user_by( 'id', $agent_id );
					if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
						return new WP_Error( 'invalid_agent', __( 'Invalid agent ID', 'whatsapp-commerce-hub' ), array( 'status' => 400 ) );
					}
				}

				$query   = $wpdb->prepare(
					"UPDATE {$wpdb->prefix}wch_conversations SET assigned_agent_id = %d, updated_at = %s WHERE id IN ($placeholders)",
					array_merge( array( $agent_id, current_time( 'mysql' ) ), $ids )
				);
				$updated = $wpdb->query( $query );
				break;

			case 'close':
				$query   = $wpdb->prepare(
					"UPDATE {$wpdb->prefix}wch_conversations SET status = 'closed', updated_at = %s WHERE id IN ($placeholders)",
					array_merge( array( current_time( 'mysql' ) ), $ids )
				);
				$updated = $wpdb->query( $query );
				break;

			case 'export':
				return $this->export_conversations( $ids );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid bulk action', 'whatsapp-commerce-hub' ), array( 'status' => 400 ) );
		}

		return $this->prepare_response(
			array(
				'success' => true,
				'updated' => $updated,
			),
			$request
		);
	}

	public function suggest_reply( $request ) {
		// SECURITY FIX: check_rate_limit returns true|WP_Error, not bool.
		$rate_limit_result = $this->check_rate_limit( 'admin' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		$conversation_id = $request->get_param( 'conversation_id' );

		global $wpdb;
		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_conversations WHERE id = %d",
				$conversation_id
			),
			ARRAY_A
		);

		if ( ! $conversation ) {
			return new WP_Error( 'conversation_not_found', __( 'Conversation not found', 'whatsapp-commerce-hub' ), array( 'status' => 404 ) );
		}

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wch_messages WHERE conversation_id = %d ORDER BY created_at DESC LIMIT 10",
				$conversation_id
			),
			ARRAY_A
		);

		$context              = json_decode( $conversation['context'], true );
		$conversation_history = array();

		foreach ( array_reverse( $messages ) as $msg ) {
			$content                = json_decode( $msg['content'], true );
			$conversation_history[] = array(
				'role'      => $msg['direction'] === 'inbound' ? 'customer' : 'agent',
				'message'   => $this->get_message_text( $content, $msg['message_type'] ),
				'timestamp' => $msg['created_at'],
			);
		}

		$ai_service      = WCH_AI_Service::getInstance();
		$suggested_reply = $ai_service->suggest_agent_reply( $conversation_history, $context );

		if ( is_wp_error( $suggested_reply ) ) {
			return $suggested_reply;
		}

		return $this->prepare_response(
			array(
				'suggestion' => $suggested_reply,
			),
			$request
		);
	}

	private function export_conversations( $ids ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, p.name as customer_name FROM {$wpdb->prefix}wch_conversations c
             LEFT JOIN {$wpdb->prefix}wch_customer_profiles p ON c.customer_phone = p.phone
             WHERE c.id IN ($placeholders)",
				$ids
			),
			ARRAY_A
		);

		$csv_data   = array();
		$csv_data[] = array( 'ID', 'Customer Phone', 'Customer Name', 'Status', 'Assigned Agent ID', 'Last Message At', 'Created At' );

		foreach ( $conversations as $conv ) {
			$csv_data[] = array(
				$conv['id'],
				$conv['customer_phone'],
				$conv['customer_name'] ?: '',
				$conv['status'],
				$conv['assigned_agent_id'] ?: '',
				$conv['last_message_at'],
				$conv['created_at'],
			);
		}

		$csv_content = '';
		foreach ( $csv_data as $row ) {
			$csv_content .= implode(
				',',
				array_map(
					function ( $field ) {
						return '"' . str_replace( '"', '""', $field ) . '"';
					},
					$row
				)
			) . "\n";
		}

		return new WP_REST_Response(
			array(
				'csv'      => $csv_content,
				'filename' => 'conversations-' . date( 'Y-m-d-His' ) . '.csv',
			),
			200
		);
	}

	private function get_message_preview( $content, $type ) {
		$text = $this->get_message_text( $content, $type );
		return mb_strlen( $text ) > 50 ? mb_substr( $text, 0, 47 ) . '...' : $text;
	}

	private function get_message_text( $content, $type ) {
		if ( is_string( $content ) ) {
			$content = json_decode( $content, true );
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

	public function get_collection_params() {
		return array(
			'search'   => array(
				'description'       => __( 'Search by phone number or customer name', 'whatsapp-commerce-hub' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status'   => array(
				'description'       => __( 'Filter by status', 'whatsapp-commerce-hub' ),
				'type'              => 'string',
				'enum'              => array( 'pending', 'active', 'closed' ),
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
	 * Get the schema for conversation items.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'conversation',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => 'Conversation ID',
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'customer_phone'  => array(
					'description' => 'Customer phone number',
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'last_message'    => array(
					'description' => 'Last message content',
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'last_message_at' => array(
					'description' => 'Last message timestamp',
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view' ),
				),
			),
		);
	}

	/**
	 * Sanitize an array of IDs for bulk operations.
	 *
	 * Converts all values to positive integers and filters out invalid values.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return array Sanitized array of integer IDs.
	 */
	public function sanitize_ids_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $id ) {
			$int_id = absint( $id );
			if ( $int_id > 0 ) {
				$sanitized[] = $int_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Validate an array of IDs for bulk operations.
	 *
	 * Ensures the array is valid, not empty, and within size limits.
	 *
	 * @param mixed            $value   The value to validate.
	 * @param WP_REST_Request  $request The request object.
	 * @param string           $param   The parameter name.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_ids_array( $value, $request, $param ) {
		// Must be an array.
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

		// Must not be empty.
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

		// Enforce maximum items limit (DoS prevention).
		if ( count( $value ) > 100 ) {
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

		// Validate each item is a valid ID.
		foreach ( $value as $index => $id ) {
			if ( ! is_numeric( $id ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: 1: parameter name, 2: array index */
						__( '%1$s[%2$d] must be a numeric ID.', 'whatsapp-commerce-hub' ),
						$param,
						$index
					),
					array( 'status' => 400 )
				);
			}

			$int_id = (int) $id;
			if ( $int_id <= 0 ) {
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
}
