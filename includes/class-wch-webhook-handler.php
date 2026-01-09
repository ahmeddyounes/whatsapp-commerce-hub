<?php
/**
 * WhatsApp Webhook Handler
 *
 * Handles incoming WhatsApp webhook events with signature validation,
 * idempotency, and async processing.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Webhook_Handler
 */
class WCH_Webhook_Handler extends WCH_REST_Controller {
	/**
	 * REST base for this controller.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhook';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Register routes for this controller.
	 */
	public function register_routes() {
		// GET /webhook - Webhook verification endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		// POST /webhook - Webhook event receiver.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Get the item schema for this controller.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'webhook',
			'type'       => 'object',
			'properties' => array(
				'object' => array(
					'description' => __( 'Webhook object type.', 'whatsapp-commerce-hub' ),
					'type'        => 'string',
				),
				'entry'  => array(
					'description' => __( 'Webhook event entries.', 'whatsapp-commerce-hub' ),
					'type'        => 'array',
				),
			),
		);
	}

	/**
	 * Verify webhook for Meta setup.
	 *
	 * Responds to GET requests with hub.challenge if hub.verify_token matches.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_webhook( $request ) {
		// Get query parameters.
		$mode      = $request->get_param( 'hub_mode' );
		$token     = $request->get_param( 'hub_verify_token' );
		$challenge = $request->get_param( 'hub_challenge' );

		// Get stored verify token.
		$stored_token = $this->settings->get( 'api.webhook_verify_token', '' );

		// Validate mode and token.
		if ( 'subscribe' === $mode && ! empty( $token ) && ! empty( $stored_token ) && hash_equals( $stored_token, $token ) ) {
			WCH_Logger::log(
				'info',
				'Webhook verification successful',
				'webhook',
				array(
					'mode'  => $mode,
					'token' => substr( $token, 0, 10 ) . '...',
				)
			);

			// Return challenge as plain text.
			return new WP_REST_Response( (int) $challenge, 200 );
		}

		WCH_Logger::log(
			'warning',
			'Webhook verification failed',
			'webhook',
			array(
				'mode'         => $mode,
				'token_match'  => ! empty( $token ) && ! empty( $stored_token ) && hash_equals( $stored_token, $token ),
				'stored_token' => ! empty( $stored_token ),
			)
		);

		return new WP_Error(
			'wch_webhook_verification_failed',
			__( 'Webhook verification failed.', 'whatsapp-commerce-hub' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle incoming webhook events.
	 *
	 * Validates signature, checks idempotency, and processes events asynchronously.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_webhook( $request ) {
		// SECURITY: Validate webhook signature FIRST before any other processing.
		// This prevents attackers from consuming rate limit resources with unsigned requests.
		$signature_check = $this->check_webhook_signature( $request );
		if ( is_wp_error( $signature_check ) ) {
			WCH_Logger::log(
				'error',
				'Webhook signature validation failed',
				'webhook',
				array(
					'error' => $signature_check->get_error_message(),
				)
			);
			return $signature_check;
		}

		// Check rate limit AFTER signature validation.
		// Only authenticated requests count against the rate limit.
		$rate_limit_check = $this->check_rate_limit( 'webhook' );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Get request body and parse JSON.
		$body    = $request->get_body();
		$payload = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WCH_Logger::log(
				'error',
				'Webhook payload JSON decode error',
				'webhook',
				array(
					'error' => json_last_error_msg(),
				)
			);
			return new WP_Error(
				'wch_webhook_invalid_json',
				__( 'Invalid JSON payload.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		// Validate payload structure.
		if ( ! isset( $payload['object'] ) || ! isset( $payload['entry'] ) ) {
			WCH_Logger::log(
				'error',
				'Webhook payload missing required fields',
				'webhook',
				array(
					'payload' => $payload,
				)
			);
			return new WP_Error(
				'wch_webhook_invalid_payload',
				__( 'Invalid webhook payload structure.', 'whatsapp-commerce-hub' ),
				array( 'status' => 400 )
			);
		}

		// Log received webhook.
		WCH_Logger::log(
			'info',
			'Webhook event received',
			'webhook',
			array(
				'object'      => $payload['object'],
				'entry_count' => count( $payload['entry'] ),
			)
		);

		// Process each entry.
		foreach ( $payload['entry'] as $entry ) {
			if ( ! isset( $entry['changes'] ) ) {
				continue;
			}

			foreach ( $entry['changes'] as $change ) {
				if ( ! isset( $change['field'] ) || ! isset( $change['value'] ) ) {
					continue;
				}

				$field = $change['field'];
				$value = $change['value'];

				// Process based on field type.
				switch ( $field ) {
					case 'messages':
						$this->process_messages_event( $value, $body );
						break;
					case 'message_status':
					case 'statuses':
						$this->process_status_event( $value, $body );
						break;
					case 'errors':
						$this->process_error_event( $value, $body );
						break;
					default:
						WCH_Logger::log(
							'debug',
							'Unknown webhook field type',
							'webhook',
							array(
								'field' => $field,
							)
						);
						break;
				}
			}
		}

		// Return 200 OK immediately.
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Webhook received.', 'whatsapp-commerce-hub' ),
			),
			200
		);
	}

	/**
	 * Process messages event.
	 *
	 * Extracts message data and dispatches to async handler.
	 *
	 * @param array  $value       Event value.
	 * @param string $raw_payload Raw webhook payload for storage.
	 */
	private function process_messages_event( $value, $raw_payload ) {
		if ( ! isset( $value['messages'] ) || ! is_array( $value['messages'] ) ) {
			return;
		}

		// Use the DataValidator for input validation.
		$validator_class = '\\WhatsAppCommerceHub\\Validation\\DataValidator';

		foreach ( $value['messages'] as $message ) {
			// Extract and validate message ID for idempotency.
			$raw_message_id = $message['id'] ?? '';
			if ( empty( $raw_message_id ) ) {
				continue;
			}

			// Validate message ID format.
			$message_id = class_exists( $validator_class )
				? $validator_class::validateMessageId( $raw_message_id )
				: $raw_message_id;

			if ( null === $message_id ) {
				WCH_Logger::log(
					'warning',
					'Invalid message ID format rejected',
					'webhook',
					array(
						'raw_message_id' => substr( $raw_message_id, 0, 50 ),
					)
				);
				continue;
			}

			// Atomic idempotency check using database INSERT IGNORE.
			// This prevents TOCTOU race conditions that can occur with transients.
			if ( ! $this->claim_message_processing( $message_id ) ) {
				WCH_Logger::log(
					'debug',
					'Duplicate message ignored (atomic check)',
					'webhook',
					array(
						'message_id' => $message_id,
					)
				);
				continue;
			}

			// Validate phone number (from field).
			$raw_phone = $message['from'] ?? '';
			$phone = '';
			if ( class_exists( $validator_class ) ) {
				$phone = $validator_class::validatePhone( $raw_phone );
				if ( null === $phone ) {
					WCH_Logger::log(
						'warning',
						'Invalid phone number format in message',
						'webhook',
						array(
							'message_id' => $message_id,
							'raw_phone'  => substr( $raw_phone, 0, 20 ),
						)
					);
					// Continue processing but use sanitized version.
					$phone = $validator_class::sanitizePhone( $raw_phone );
				}
			} else {
				$phone = $raw_phone;
			}

			// Validate timestamp.
			$raw_timestamp = $message['timestamp'] ?? time();
			$timestamp = time();
			if ( class_exists( $validator_class ) ) {
				$validated_ts = $validator_class::validateTimestamp( $raw_timestamp );
				if ( null === $validated_ts ) {
					WCH_Logger::log(
						'warning',
						'Invalid timestamp in message, using current time',
						'webhook',
						array(
							'message_id'    => $message_id,
							'raw_timestamp' => $raw_timestamp,
						)
					);
					$timestamp = time();
				} else {
					$timestamp = $validated_ts;
				}
			} else {
				$timestamp = is_numeric( $raw_timestamp ) ? (int) $raw_timestamp : time();
			}

			// Extract message data with validated fields.
			$message_data = array(
				'message_id' => $message_id,
				'from'       => $phone,
				'timestamp'  => $timestamp,
				'type'       => $message['type'] ?? 'text',
				'content'    => $this->extract_message_content( $message ),
				'context'    => isset( $message['context']['id'] ) ? $message['context']['id'] : null,
			);

			// Store raw payload in database.
			$this->store_raw_webhook( $message_id, $raw_payload, 'message' );

			// Dispatch event using WordPress action hook.
			$this->dispatch_event( 'messages', $message_data );

			WCH_Logger::log(
				'info',
				'Message event processed',
				'webhook',
				array(
					'message_id' => $message_id,
					'from'       => $message_data['from'],
					'type'       => $message_data['type'],
				)
			);
		}
	}

	/**
	 * Process status event.
	 *
	 * Extracts status update data and dispatches to async handler.
	 *
	 * @param array  $value       Event value.
	 * @param string $raw_payload Raw webhook payload for storage.
	 */
	private function process_status_event( $value, $raw_payload ) {
		if ( ! isset( $value['statuses'] ) || ! is_array( $value['statuses'] ) ) {
			return;
		}

		// Use the DataValidator for input validation.
		$validator_class = '\\WhatsAppCommerceHub\\Validation\\DataValidator';

		foreach ( $value['statuses'] as $status ) {
			// Extract and validate message ID.
			$raw_message_id = $status['id'] ?? '';
			if ( empty( $raw_message_id ) ) {
				continue;
			}

			// Validate message ID format.
			$message_id = class_exists( $validator_class )
				? $validator_class::validateMessageId( $raw_message_id )
				: $raw_message_id;

			if ( null === $message_id ) {
				WCH_Logger::log(
					'warning',
					'Invalid message ID format in status event',
					'webhook',
					array(
						'raw_message_id' => substr( $raw_message_id, 0, 50 ),
					)
				);
				continue;
			}

			// Validate timestamp.
			$raw_timestamp = $status['timestamp'] ?? time();
			$timestamp = time();
			if ( class_exists( $validator_class ) ) {
				$validated_ts = $validator_class::validateTimestamp( $raw_timestamp );
				$timestamp = $validated_ts ?? time();
			} else {
				$timestamp = is_numeric( $raw_timestamp ) ? (int) $raw_timestamp : time();
			}

			// Validate recipient_id (phone number).
			$raw_recipient = $status['recipient_id'] ?? '';
			$recipient_id = '';
			if ( class_exists( $validator_class ) && '' !== $raw_recipient ) {
				$recipient_id = $validator_class::validatePhone( $raw_recipient ) ?? $validator_class::sanitizePhone( $raw_recipient );
			} else {
				$recipient_id = $raw_recipient;
			}

			$status_data = array(
				'message_id'   => $message_id,
				'status'       => $status['status'] ?? 'unknown',
				'timestamp'    => $timestamp,
				'recipient_id' => $recipient_id,
				'errors'       => isset( $status['errors'] ) ? $status['errors'] : array(),
			);

			// Store raw payload in database.
			$this->store_raw_webhook( $message_id, $raw_payload, 'status' );

			// Dispatch event using WordPress action hook.
			$this->dispatch_event( 'statuses', $status_data );

			WCH_Logger::log(
				'info',
				'Status event processed',
				'webhook',
				array(
					'message_id' => $message_id,
					'status'     => $status_data['status'],
				)
			);
		}
	}

	/**
	 * Process error event.
	 *
	 * Extracts error data and dispatches to async handler.
	 *
	 * @param array  $value       Event value.
	 * @param string $raw_payload Raw webhook payload for storage.
	 */
	private function process_error_event( $value, $raw_payload ) {
		$error_data = array(
			'code'    => $value['code'] ?? '',
			'title'   => $value['title'] ?? '',
			'message' => $value['message'] ?? '',
			'details' => $value['error_data']['details'] ?? '',
		);

		// Store raw payload in database.
		$this->store_raw_webhook( 'error_' . time(), $raw_payload, 'error' );

		// Dispatch event using WordPress action hook.
		$this->dispatch_event( 'errors', $error_data );

		WCH_Logger::log(
			'error',
			'Error event received from WhatsApp',
			'webhook',
			$error_data
		);
	}

	/**
	 * Extract message content based on type.
	 *
	 * @param array $message Message data.
	 * @return array Extracted content.
	 */
	private function extract_message_content( $message ) {
		$type = $message['type'] ?? 'text';

		switch ( $type ) {
			case 'text':
				return array(
					'body' => $message['text']['body'] ?? '',
				);

			case 'interactive':
				// Interactive button or list response.
				$interactive = $message['interactive'] ?? array();
				if ( isset( $interactive['button_reply'] ) ) {
					return array(
						'type'  => 'button_reply',
						'id'    => $interactive['button_reply']['id'] ?? '',
						'title' => $interactive['button_reply']['title'] ?? '',
					);
				} elseif ( isset( $interactive['list_reply'] ) ) {
					return array(
						'type'        => 'list_reply',
						'id'          => $interactive['list_reply']['id'] ?? '',
						'title'       => $interactive['list_reply']['title'] ?? '',
						'description' => $interactive['list_reply']['description'] ?? '',
					);
				}
				return $interactive;

			case 'image':
				return array(
					'id'        => $message['image']['id'] ?? '',
					'mime_type' => $message['image']['mime_type'] ?? '',
					'sha256'    => $message['image']['sha256'] ?? '',
					'caption'   => $message['image']['caption'] ?? '',
				);

			case 'document':
				return array(
					'id'        => $message['document']['id'] ?? '',
					'filename'  => $message['document']['filename'] ?? '',
					'mime_type' => $message['document']['mime_type'] ?? '',
					'sha256'    => $message['document']['sha256'] ?? '',
					'caption'   => $message['document']['caption'] ?? '',
				);

			case 'button':
				return array(
					'payload' => $message['button']['payload'] ?? '',
					'text'    => $message['button']['text'] ?? '',
				);

			case 'location':
				return array(
					'latitude'  => $message['location']['latitude'] ?? '',
					'longitude' => $message['location']['longitude'] ?? '',
					'name'      => $message['location']['name'] ?? '',
					'address'   => $message['location']['address'] ?? '',
				);

			default:
				return $message[ $type ] ?? array();
		}
	}

	/**
	 * Store raw webhook payload in database for debugging.
	 *
	 * @param string $reference_id Reference ID (message ID or error ID).
	 * @param string $raw_payload  Raw webhook payload.
	 * @param string $event_type   Event type (message, status, error).
	 */
	private function store_raw_webhook( $reference_id, $raw_payload, $event_type ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_messages';

		// Store as a special record for debugging.
		// We'll use direction='inbound' for webhook raw data.
		// Note: This will insert a raw webhook data record, not a regular message.
		// For production, you might want a separate table or field for raw webhooks.

		// For now, we'll log it instead of storing in messages table
		// to avoid schema conflicts.
		WCH_Logger::log(
			'debug',
			'Raw webhook payload stored',
			'webhook',
			array(
				'reference_id' => $reference_id,
				'event_type'   => $event_type,
				'payload_size' => strlen( $raw_payload ),
				'payload'      => $raw_payload,
			)
		);
	}

	/**
	 * Atomically claim exclusive processing rights for a message.
	 *
	 * Uses database INSERT IGNORE with unique constraint to prevent TOCTOU race conditions.
	 * Only one process can successfully claim a message ID due to atomic insert behavior.
	 *
	 * @param string $message_id WhatsApp message ID.
	 * @return bool True if claim successful (first processor), false if already claimed.
	 */
	private function claim_message_processing( $message_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_webhook_idempotency';

		// Use INSERT IGNORE for atomic claim - only succeeds if no duplicate exists.
		// The UNIQUE KEY on message_id ensures atomicity.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table_name} (message_id, processed_at)
				VALUES (%s, %s)",
				$message_id,
				current_time( 'mysql' )
			)
		);

		// INSERT IGNORE returns 1 if inserted (we got the claim), 0 if duplicate exists.
		return 1 === $result;
	}

	/**
	 * Dispatch event to WordPress action hooks with async processing.
	 *
	 * Triggers do_action hook and queues async job if handlers are registered.
	 *
	 * @param string $type Event type (messages, statuses, errors).
	 * @param array  $data Event data.
	 */
	private function dispatch_event( $type, $data ) {
		// Fire WordPress action hook for immediate processing.
		// Other plugins/code can hook into this action.
		do_action( "wch_webhook_{$type}", $data );

		// Queue async processing using Action Scheduler.
		// This allows background processing without blocking the webhook response.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				"wch_process_webhook_{$type}",
				array( 'data' => $data ),
				'wch'
			);

			WCH_Logger::log(
				'debug',
				'Webhook event queued for async processing',
				'webhook',
				array(
					'type' => $type,
				)
			);
		}
	}
}
