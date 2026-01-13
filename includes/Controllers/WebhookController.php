<?php
/**
 * Webhook Controller
 *
 * Handles WhatsApp webhook verification and message ingestion.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WhatsAppCommerceHub\Application\Services\SettingsService;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Security\RateLimiter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookController
 *
 * Receives and validates incoming WhatsApp webhooks and
 * schedules processing via queue processors.
 */
class WebhookController extends AbstractController {

	/**
	 * Queue for async processing.
	 *
	 * @var PriorityQueue
	 */
	private PriorityQueue $priorityQueue;

	/**
	 * Constructor.
	 *
	 * @param SettingsService|null $settings    Settings service.
	 * @param RateLimiter          $rateLimiter Rate limiter.
	 * @param PriorityQueue        $priorityQueue Priority queue.
	 */
	public function __construct(
		?SettingsService $settings,
		RateLimiter $rateLimiter,
		PriorityQueue $priorityQueue
	) {
		parent::__construct( $settings, $rateLimiter );
		$this->priorityQueue = $priorityQueue;
	}

	/**
	 * {@inheritdoc}
	 */
	public function registerRoutes(): void {
		register_rest_route(
			$this->apiNamespace,
			'/webhook',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'verifyWebhook' ],
					'permission_callback' => [ $this, 'verifyChallengePermission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handleWebhook' ],
					'permission_callback' => [ $this, 'verifyWebhookPermission' ],
				],
			]
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getItemSchema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'whatsapp_webhook',
			'type'       => 'object',
			'properties' => [
				'object' => [
					'description' => 'Webhook object type',
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'entry'  => [
					'description' => 'Webhook entries',
					'type'        => 'array',
					'context'     => [ 'view' ],
				],
			],
		];
	}

	/**
	 * Permission callback for webhook verification (GET).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function verifyChallengePermission( WP_REST_Request $request ): bool|WP_Error {
		return $this->checkRateLimit( 'webhook' );
	}

	/**
	 * Permission callback for webhook ingestion (POST).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function verifyWebhookPermission( WP_REST_Request $request ): bool|WP_Error {
		$rateLimit = $this->checkRateLimit( 'webhook' );
		if ( $rateLimit instanceof WP_Error ) {
			return $rateLimit;
		}

		return $this->checkWebhookSignature( $request );
	}

	/**
	 * Handle Meta webhook verification challenge.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function verifyWebhook( WP_REST_Request $request ): WP_REST_Response {
		$mode      = (string) $request->get_param( 'hub.mode' );
		$token     = (string) $request->get_param( 'hub.verify_token' );
		$challenge = (string) $request->get_param( 'hub.challenge' );

		if ( 'subscribe' !== $mode ) {
			return new WP_REST_Response(
				[
					'code'    => 'invalid_mode',
					'message' => __( 'Invalid webhook mode.', 'whatsapp-commerce-hub' ),
				],
				400
			);
		}

		if ( ! $this->settings ) {
			return new WP_REST_Response(
				[
					'code'    => 'webhook_not_configured',
					'message' => __( 'Webhook verification is not configured.', 'whatsapp-commerce-hub' ),
				],
				500
			);
		}

		$expectedToken = (string) $this->settings->get( 'api.webhook_verify_token', '' );

		if ( '' === $expectedToken || $token !== $expectedToken ) {
			return new WP_REST_Response(
				[
					'code'    => 'invalid_verify_token',
					'message' => __( 'Invalid webhook verify token.', 'whatsapp-commerce-hub' ),
				],
				403
			);
		}

		return new WP_REST_Response( $challenge, 200 );
	}

	/**
	 * Handle incoming webhook payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handleWebhook( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = json_decode( $request->get_body(), true );
		}

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				[
					'code'    => 'invalid_payload',
					'message' => __( 'Invalid webhook payload.', 'whatsapp-commerce-hub' ),
				],
				400
			);
		}

		$counts = [
			'messages' => 0,
			'statuses' => 0,
			'errors'   => 0,
		];

		foreach ( $payload['entry'] ?? [] as $entry ) {
			$changes = $entry['changes'] ?? [];

			foreach ( $changes as $change ) {
				$value    = $change['value'] ?? [];
				$metadata = $value['metadata'] ?? [];
				$contacts = $value['contacts'] ?? [];

				foreach ( $value['messages'] ?? [] as $message ) {
					$this->enqueueMessage( $message, $metadata, $contacts );
					++$counts['messages'];
				}

				foreach ( $value['statuses'] ?? [] as $status ) {
					$this->enqueueStatus( $status, $metadata );
					++$counts['statuses'];
				}

				foreach ( $value['errors'] ?? [] as $error ) {
					$this->enqueueError( $error, $metadata );
					++$counts['errors'];
				}
			}
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'received' => $counts,
			],
			200
		);
	}

	/**
	 * Enqueue an inbound message for processing.
	 *
	 * @param array $message  Message payload.
	 * @param array $metadata Webhook metadata.
	 * @param array $contacts Webhook contacts.
	 * @return void
	 */
	private function enqueueMessage( array $message, array $metadata, array $contacts ): void {
		$payload = array_merge(
			$message,
			[
				'message_id' => $message['id'] ?? '',
				'timestamp'  => isset( $message['timestamp'] ) ? (int) $message['timestamp'] : time(),
				'metadata'   => $metadata,
				'contacts'   => $contacts,
			]
		);

		if ( empty( $payload['message_id'] ) || empty( $payload['from'] ) ) {
			return;
		}

		$this->priorityQueue->schedule(
			'wch_process_webhook_messages',
			$payload,
			PriorityQueue::PRIORITY_URGENT,
			0
		);
	}

	/**
	 * Enqueue a status update for processing.
	 *
	 * @param array $status   Status payload.
	 * @param array $metadata Webhook metadata.
	 * @return void
	 */
	private function enqueueStatus( array $status, array $metadata ): void {
		$payload = array_merge(
			$status,
			[
				'message_id' => $status['id'] ?? '',
				'timestamp'  => isset( $status['timestamp'] ) ? (int) $status['timestamp'] : time(),
				'metadata'   => $metadata,
			]
		);

		if ( empty( $payload['message_id'] ) || empty( $payload['status'] ) ) {
			return;
		}

		$this->priorityQueue->schedule(
			'wch_process_webhook_statuses',
			$payload,
			PriorityQueue::PRIORITY_NORMAL,
			0
		);
	}

	/**
	 * Enqueue an error payload for processing.
	 *
	 * @param array $error    Error payload.
	 * @param array $metadata Webhook metadata.
	 * @return void
	 */
	private function enqueueError( array $error, array $metadata ): void {
		$payload = array_merge(
			$error,
			[
				'timestamp' => $error['timestamp'] ?? time(),
				'metadata'  => $metadata,
			]
		);

		$this->priorityQueue->schedule(
			'wch_process_webhook_errors',
			$payload,
			PriorityQueue::PRIORITY_NORMAL,
			0
		);
	}
}
