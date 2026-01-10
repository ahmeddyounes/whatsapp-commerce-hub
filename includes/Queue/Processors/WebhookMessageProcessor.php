<?php
/**
 * Webhook Message Processor
 *
 * Processes incoming WhatsApp messages from webhooks.
 * Handles message storage, conversation management, and FSM routing.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue\Processors;

use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\IdempotencyService;
use WhatsAppCommerceHub\Contracts\Repositories\ConversationRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\MessageRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookMessageProcessor
 *
 * Processes incoming WhatsApp messages:
 * - Deduplication using IdempotencyService
 * - Conversation lookup/creation
 * - Message persistence
 * - Intent classification and FSM routing
 * - Action execution and response generation
 */
class WebhookMessageProcessor extends AbstractQueueProcessor {

	/**
	 * Processor name.
	 */
	private const NAME = 'webhook_message';

	/**
	 * Action Scheduler hook name.
	 */
	private const HOOK_NAME = 'wch_process_webhook_messages';

	/**
	 * Idempotency service.
	 *
	 * @var IdempotencyService
	 */
	private IdempotencyService $idempotencyService;

	/**
	 * Conversation repository.
	 *
	 * @var ConversationRepositoryInterface
	 */
	private ConversationRepositoryInterface $conversationRepository;

	/**
	 * Message repository.
	 *
	 * @var MessageRepositoryInterface
	 */
	private MessageRepositoryInterface $messageRepository;

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue                   $priorityQueue          Priority queue for retries.
	 * @param DeadLetterQueue                 $deadLetterQueue        Dead letter queue for failures.
	 * @param IdempotencyService              $idempotencyService     Idempotency service for deduplication.
	 * @param ConversationRepositoryInterface $conversationRepository Conversation repository.
	 * @param MessageRepositoryInterface      $messageRepository      Message repository.
	 */
	public function __construct(
		PriorityQueue $priorityQueue,
		DeadLetterQueue $deadLetterQueue,
		IdempotencyService $idempotencyService,
		ConversationRepositoryInterface $conversationRepository,
		MessageRepositoryInterface $messageRepository
	) {
		parent::__construct( $priorityQueue, $deadLetterQueue );

		$this->idempotencyService     = $idempotencyService;
		$this->conversationRepository = $conversationRepository;
		$this->messageRepository      = $messageRepository;
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

		// Extract message details.
		$messageId = $data['message_id'] ?? '';
		$from      = $data['from'] ?? '';
		$type      = $data['type'] ?? 'text';
		$timestamp = $data['timestamp'] ?? time();

		// Validate required fields.
		if ( empty( $messageId ) || empty( $from ) ) {
			throw new \InvalidArgumentException(
				'Missing required fields: message_id and from are required'
			);
		}

		// Attempt to claim this message for processing (idempotency check).
		if ( ! $this->idempotencyService->claim( $messageId, IdempotencyService::SCOPE_WEBHOOK ) ) {
			$this->logInfo(
				'Message already processed, skipping',
				array(
					'message_id' => $messageId,
				)
			);
			return;
		}

		$this->logDebug(
			'Processing incoming message',
			array(
				'message_id' => $messageId,
				'from'       => $from,
				'type'       => $type,
			)
		);

		// Find or create conversation for this phone number.
		$conversation = $this->conversationRepository->findOrCreate( $from );

		// Update conversation activity.
		$this->conversationRepository->touchMessage( $conversation->id );
		$this->conversationRepository->incrementUnread( $conversation->id );

		// Store the message in the database.
		$messageEntity = $this->storeMessage( $conversation->id, $messageId, $from, $type, $data, $timestamp );

		// Extract text content for processing.
		$textContent = $this->extractTextContent( $type, $data );

		// Classify intent if we have text content.
		$intent = null;
		if ( ! empty( $textContent ) ) {
			$intent = $this->classifyIntent( $textContent, $conversation );
		}

		// Route through FSM if we have an intent.
		$fsmResult = null;
		if ( $intent ) {
			$fsmResult = $this->routeToFSM( $conversation, $intent, $data );
		}

		// Fire event for extensibility.
		/**
		 * Fires after a webhook message has been processed.
		 *
		 * @param array       $data         Original message data.
		 * @param object      $conversation Conversation entity.
		 * @param object|null $intent       Classified intent or null.
		 * @param mixed       $fsmResult    FSM transition result.
		 */
		do_action( 'wch_webhook_message_processed', $data, $conversation, $intent, $fsmResult );

		$this->logInfo(
			'Message processed successfully',
			array(
				'message_id'      => $messageId,
				'conversation_id' => $conversation->id,
				'intent'          => $intent ? $intent->getIntentName() : null,
			)
		);
	}

	/**
	 * Store a message in the database.
	 *
	 * @param int    $conversationId The conversation ID.
	 * @param string $waMessageId    The WhatsApp message ID.
	 * @param string $from           The sender phone number.
	 * @param string $type           The message type.
	 * @param array  $data           The full message data.
	 * @param int    $timestamp      The message timestamp.
	 * @return object|null The created message entity or null.
	 */
	private function storeMessage(
		int $conversationId,
		string $waMessageId,
		string $from,
		string $type,
		array $data,
		int $timestamp
	): ?object {
		try {
			$messageData = array(
				'conversation_id' => $conversationId,
				'wa_message_id'   => $waMessageId,
				'direction'       => 'inbound',
				'type'            => $type,
				'content'         => $this->extractTextContent( $type, $data ),
				'raw_payload'     => $data,
				'status'          => 'received',
				'created_at'      => gmdate( 'Y-m-d H:i:s', $timestamp ),
			);

			$messageId = $this->messageRepository->create( $messageData );

			return $this->messageRepository->find( $messageId );
		} catch ( \Throwable $e ) {
			$this->logError(
				'Failed to store message',
				array(
					'error'      => $e->getMessage(),
					'message_id' => $waMessageId,
				)
			);
			return null;
		}
	}

	/**
	 * Extract text content from message data based on type.
	 *
	 * @param string $type The message type.
	 * @param array  $data The message data.
	 * @return string The extracted text content.
	 */
	private function extractTextContent( string $type, array $data ): string {
		switch ( $type ) {
			case 'text':
				return $data['text']['body'] ?? $data['body'] ?? '';

			case 'button':
				return $data['button']['text'] ?? $data['button']['payload'] ?? '';

			case 'interactive':
				// Handle list/button replies.
				$interactive = $data['interactive'] ?? array();
				if ( isset( $interactive['list_reply'] ) ) {
					return $interactive['list_reply']['title'] ?? $interactive['list_reply']['id'] ?? '';
				}
				if ( isset( $interactive['button_reply'] ) ) {
					return $interactive['button_reply']['title'] ?? $interactive['button_reply']['id'] ?? '';
				}
				return '';

			case 'location':
				// Return a structured location string for processing.
				$location = $data['location'] ?? array();
				return sprintf(
					'Location: %s, %s',
					$location['latitude'] ?? '',
					$location['longitude'] ?? ''
				);

			case 'image':
			case 'video':
			case 'audio':
			case 'document':
				// Media messages may have captions.
				$media = $data[ $type ] ?? array();
				return $media['caption'] ?? '';

			default:
				return '';
		}
	}

	/**
	 * Classify intent from text content.
	 *
	 * @param string $text         The text to classify.
	 * @param object $conversation The conversation entity.
	 * @return object|null The classified intent or null.
	 */
	private function classifyIntent( string $text, object $conversation ): ?object {
		if ( ! class_exists( 'WCH_Intent_Classifier' ) ) {
			$this->logWarning( 'Intent classifier not available' );
			return null;
		}

		try {
			$classifier = new \WCH_Intent_Classifier();
			$context    = $conversation->context ?? array();

			return $classifier->classify( $text, $context );
		} catch ( \Throwable $e ) {
			$this->logError(
				'Intent classification failed',
				array(
					'error' => $e->getMessage(),
					'text'  => substr( $text, 0, 100 ),
				)
			);
			return null;
		}
	}

	/**
	 * Route the message through the conversation FSM.
	 *
	 * @param object $conversation The conversation entity.
	 * @param object $intent       The classified intent.
	 * @param array  $data         The original message data.
	 * @return mixed The FSM transition result.
	 */
	private function routeToFSM( object $conversation, object $intent, array $data ) {
		if ( ! class_exists( 'WCH_Conversation_FSM' ) ) {
			$this->logWarning( 'Conversation FSM not available' );
			return null;
		}

		try {
			$fsm = new \WCH_Conversation_FSM();

			// Map intent to FSM event.
			$event = $this->mapIntentToEvent( $intent );
			if ( ! $event ) {
				$this->logDebug(
					'No FSM event mapping for intent',
					array(
						'intent' => $intent->getIntentName(),
					)
				);
				return null;
			}

			// Prepare payload for FSM.
			$payload = array_merge(
				$data,
				array(
					'intent'     => $intent->getIntentName(),
					'confidence' => $intent->getConfidence(),
					'entities'   => $intent->getEntities(),
				)
			);

			// Convert conversation entity to array for FSM.
			$conversationArray = $conversation->toArray();

			// Execute the transition.
			$result = $fsm->transition( $conversationArray, $event, $payload );

			if ( is_wp_error( $result ) ) {
				$this->logWarning(
					'FSM transition failed',
					array(
						'error' => $result->get_error_message(),
						'event' => $event,
					)
				);
				return $result;
			}

			return $result;
		} catch ( \Throwable $e ) {
			$this->logError(
				'FSM routing failed',
				array(
					'error'  => $e->getMessage(),
					'intent' => $intent->getIntentName(),
				)
			);
			return null;
		}
	}

	/**
	 * Map intent name to FSM event.
	 *
	 * @param object $intent The classified intent.
	 * @return string|null The FSM event or null if no mapping.
	 */
	private function mapIntentToEvent( object $intent ): ?string {
		$intentName = $intent->getIntentName();

		// Intent to FSM event mapping.
		$mapping = array(
			'GREETING'      => \WCH_Conversation_FSM::EVENT_START,
			'START'         => \WCH_Conversation_FSM::EVENT_START,
			'BROWSE'        => \WCH_Conversation_FSM::EVENT_START,
			'SEARCH'        => \WCH_Conversation_FSM::EVENT_SEARCH,
			'VIEW_CATEGORY' => \WCH_Conversation_FSM::EVENT_SELECT_CATEGORY,
			'VIEW_PRODUCT'  => \WCH_Conversation_FSM::EVENT_VIEW_PRODUCT,
			'ADD_TO_CART'   => \WCH_Conversation_FSM::EVENT_ADD_TO_CART,
			'VIEW_CART'     => \WCH_Conversation_FSM::EVENT_VIEW_CART,
			'CHECKOUT'      => \WCH_Conversation_FSM::EVENT_START_CHECKOUT,
			'CONFIRM_ORDER' => \WCH_Conversation_FSM::EVENT_CONFIRM_ORDER,
			'HUMAN_SUPPORT' => \WCH_Conversation_FSM::EVENT_REQUEST_HUMAN,
			'HELP'          => \WCH_Conversation_FSM::EVENT_REQUEST_HUMAN,
		);

		/**
		 * Filter the intent to FSM event mapping.
		 *
		 * @param array  $mapping    The mapping array.
		 * @param object $intent     The classified intent.
		 */
		$mapping = apply_filters( 'wch_intent_to_fsm_event_mapping', $mapping, $intent );

		return $mapping[ $intentName ] ?? null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Messages should be retried on transient errors but not on validation errors.
	 */
	public function shouldRetry( \Throwable $exception ): bool {
		// Don't retry validation/argument errors.
		if ( $exception instanceof \InvalidArgumentException ) {
			return false;
		}

		// Don't retry if message was already processed (idempotency).
		if ( strpos( $exception->getMessage(), 'already processed' ) !== false ) {
			return false;
		}

		// Retry on other errors (network, database, etc.).
		return parent::shouldRetry( $exception );
	}
}
