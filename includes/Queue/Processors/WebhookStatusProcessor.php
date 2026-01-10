<?php
/**
 * Webhook Status Processor
 *
 * Processes message delivery status updates from WhatsApp webhooks.
 * Updates message status in the database (sent, delivered, read, failed).
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue\Processors;

use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\IdempotencyService;
use WhatsAppCommerceHub\Contracts\Repositories\MessageRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookStatusProcessor
 *
 * Processes delivery status updates for WhatsApp messages:
 * - sent: Message was sent to WhatsApp servers
 * - delivered: Message was delivered to the recipient's device
 * - read: Message was read by the recipient
 * - failed: Message delivery failed
 */
class WebhookStatusProcessor extends AbstractQueueProcessor {

	/**
	 * Processor name.
	 */
	private const NAME = 'webhook_status';

	/**
	 * Action Scheduler hook name.
	 */
	private const HOOK_NAME = 'wch_process_webhook_statuses';

	/**
	 * Valid status values.
	 */
	private const VALID_STATUSES = array(
		'sent',
		'delivered',
		'read',
		'failed',
	);

	/**
	 * Idempotency service.
	 *
	 * @var IdempotencyService
	 */
	private IdempotencyService $idempotencyService;

	/**
	 * Message repository.
	 *
	 * @var MessageRepositoryInterface
	 */
	private MessageRepositoryInterface $messageRepository;

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue              $priorityQueue      Priority queue for retries.
	 * @param DeadLetterQueue            $deadLetterQueue    Dead letter queue for failures.
	 * @param IdempotencyService         $idempotencyService Idempotency service for deduplication.
	 * @param MessageRepositoryInterface $messageRepository  Message repository.
	 */
	public function __construct(
		PriorityQueue $priorityQueue,
		DeadLetterQueue $deadLetterQueue,
		IdempotencyService $idempotencyService,
		MessageRepositoryInterface $messageRepository
	) {
		parent::__construct( $priorityQueue, $deadLetterQueue );

		$this->idempotencyService = $idempotencyService;
		$this->messageRepository  = $messageRepository;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return self::NAME;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getHookName(): string {
		return self::HOOK_NAME;
	}

	/**
	 * {@inheritdoc}
	 */
	public function process( array $payload ): void {
		$data = $payload['data'] ?? $payload;

		// Extract status details.
		$messageId = $data['message_id'] ?? $data['id'] ?? '';
		$status    = $data['status'] ?? '';
		$timestamp = $data['timestamp'] ?? time();
		$errors    = $data['errors'] ?? array();

		// Validate required fields.
		if ( empty( $messageId ) ) {
			throw new \InvalidArgumentException( 'Missing required field: message_id' );
		}

		if ( empty( $status ) ) {
			throw new \InvalidArgumentException( 'Missing required field: status' );
		}

		// Normalize status.
		$status = strtolower( $status );

		// Validate status value.
		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid status value: %s. Valid values: %s', $status, implode( ', ', self::VALID_STATUSES ) )
			);
		}

		// Generate idempotency key from message_id + status to allow status progression.
		$idempotencyKey = IdempotencyService::generateKey( $messageId, $status );

		// Attempt to claim this status update for processing.
		if ( ! $this->idempotencyService->claim( $idempotencyKey, IdempotencyService::SCOPE_NOTIFICATION ) ) {
			$this->logInfo(
				'Status update already processed, skipping',
				array(
					'message_id' => $messageId,
					'status'     => $status,
				)
			);
			return;
		}

		$this->logDebug(
			'Processing status update',
			array(
				'message_id' => $messageId,
				'status'     => $status,
				'timestamp'  => $timestamp,
			)
		);

		// Find the message in our database.
		$message = $this->messageRepository->findByWhatsAppMessageId( $messageId );

		if ( ! $message ) {
			$this->logWarning(
				'Message not found for status update',
				array(
					'message_id' => $messageId,
					'status'     => $status,
				)
			);

			// Don't throw - the message might not exist in our system.
			// This can happen for messages sent through other channels.
			return;
		}

		// Check if this is a valid status progression.
		if ( ! $this->isValidStatusProgression( $message->status ?? '', $status ) ) {
			$this->logDebug(
				'Ignoring out-of-order status update',
				array(
					'message_id'     => $messageId,
					'current_status' => $message->status ?? 'unknown',
					'new_status'     => $status,
				)
			);
			return;
		}

		// Update the message status.
		$updateSuccess = $this->messageRepository->updateStatus( $messageId, $status );

		if ( ! $updateSuccess ) {
			throw new \RuntimeException(
				sprintf( 'Failed to update message status for message_id: %s', $messageId )
			);
		}

		// Handle failed status specially.
		if ( 'failed' === $status ) {
			$this->handleFailedStatus( $message, $errors );
		}

		// Fire events for extensibility.
		$this->fireStatusEvents( $messageId, $status, $message, $data );

		$this->logInfo(
			'Status update processed successfully',
			array(
				'message_id' => $messageId,
				'status'     => $status,
			)
		);
	}

	/**
	 * Check if the status progression is valid.
	 *
	 * Status progression: pending -> sent -> delivered -> read
	 * Failed can come from any state.
	 *
	 * @param string $currentStatus The current message status.
	 * @param string $newStatus     The new status to set.
	 * @return bool True if the progression is valid.
	 */
	private function isValidStatusProgression( string $currentStatus, string $newStatus ): bool {
		// Failed status can always be set.
		if ( 'failed' === $newStatus ) {
			return true;
		}

		// Define status order for progression.
		$statusOrder = array(
			'pending'   => 0,
			'queued'    => 1,
			'sent'      => 2,
			'delivered' => 3,
			'read'      => 4,
		);

		$currentOrder = $statusOrder[ $currentStatus ] ?? -1;
		$newOrder     = $statusOrder[ $newStatus ] ?? -1;

		// New status should be equal or higher in the progression.
		return $newOrder >= $currentOrder;
	}

	/**
	 * Handle a failed message status.
	 *
	 * @param object $message The message entity.
	 * @param array  $errors  Error details from the webhook.
	 * @return void
	 */
	private function handleFailedStatus( object $message, array $errors ): void {
		// Log the failure details.
		$errorMessage = '';
		if ( ! empty( $errors ) ) {
			$firstError   = $errors[0] ?? array();
			$errorMessage = sprintf(
				'%s: %s',
				$firstError['code'] ?? 'unknown',
				$firstError['title'] ?? $firstError['message'] ?? 'Unknown error'
			);
		}

		$this->logWarning(
			'Message delivery failed',
			array(
				'message_id' => $message->wa_message_id ?? 'unknown',
				'error'      => $errorMessage,
				'errors'     => $errors,
			)
		);

		// Increment retry count if applicable.
		if ( method_exists( $this->messageRepository, 'incrementRetryCount' ) ) {
			$this->messageRepository->incrementRetryCount(
				$message->id,
				$errorMessage
			);
		}

		/**
		 * Fires when a message delivery fails.
		 *
		 * @param object $message      The message entity.
		 * @param array  $errors       Error details from the webhook.
		 * @param string $errorMessage Formatted error message.
		 */
		do_action( 'wch_message_delivery_failed', $message, $errors, $errorMessage );
	}

	/**
	 * Fire status-related events.
	 *
	 * @param string $messageId The WhatsApp message ID.
	 * @param string $status    The new status.
	 * @param object $message   The message entity.
	 * @param array  $data      The original webhook data.
	 * @return void
	 */
	private function fireStatusEvents( string $messageId, string $status, object $message, array $data ): void {
		/**
		 * Fires after a message status has been updated.
		 *
		 * @param string $messageId The WhatsApp message ID.
		 * @param string $status    The new status.
		 * @param object $message   The message entity.
		 * @param array  $data      The original webhook data.
		 */
		do_action( 'wch_message_status_updated', $messageId, $status, $message, $data );

		// Fire status-specific events.
		switch ( $status ) {
			case 'sent':
				/**
				 * Fires when a message is sent successfully.
				 *
				 * @param object $message The message entity.
				 * @param array  $data    The original webhook data.
				 */
				do_action( 'wch_message_sent', $message, $data );
				break;

			case 'delivered':
				/**
				 * Fires when a message is delivered to the recipient.
				 *
				 * @param object $message The message entity.
				 * @param array  $data    The original webhook data.
				 */
				do_action( 'wch_message_delivered', $message, $data );
				break;

			case 'read':
				/**
				 * Fires when a message is read by the recipient.
				 *
				 * @param object $message The message entity.
				 * @param array  $data    The original webhook data.
				 */
				do_action( 'wch_message_read', $message, $data );
				break;
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * Status updates should be retried on transient errors.
	 */
	public function shouldRetry( \Throwable $exception ): bool {
		// Don't retry validation errors.
		if ( $exception instanceof \InvalidArgumentException ) {
			return false;
		}

		// Don't retry if message not found (it won't suddenly appear).
		if ( strpos( $exception->getMessage(), 'not found' ) !== false ) {
			return false;
		}

		return parent::shouldRetry( $exception );
	}
}
