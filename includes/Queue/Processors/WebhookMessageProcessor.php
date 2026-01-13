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

use WhatsAppCommerceHub\Actions\ActionRegistry;
use WhatsAppCommerceHub\Application\Services\ContextManagerService;
use WhatsAppCommerceHub\Application\Services\IntentClassifierService;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser;
use WhatsAppCommerceHub\Entities\Message;
use WhatsAppCommerceHub\Events\EventBus;
use WhatsAppCommerceHub\Events\MessageReceivedEvent;
use WhatsAppCommerceHub\Events\MessageSentEvent;
use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\IdempotencyService;
use WhatsAppCommerceHub\Contracts\Repositories\ConversationRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\CustomerRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Repositories\MessageRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CartServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;
use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;
use WhatsAppCommerceHub\ValueObjects\Intent;

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
				[
					'message_id' => $messageId,
				]
			);
			return;
		}

		$this->logDebug(
			'Processing incoming message',
			[
				'message_id' => $messageId,
				'from'       => $from,
				'type'       => $type,
			]
		);

		// Find or create conversation for this phone number.
		$conversation = $this->conversationRepository->findOrCreate( $from );

		// Update conversation activity.
		$this->conversationRepository->touchMessage( $conversation->id );
		$this->conversationRepository->incrementUnread( $conversation->id );
		$this->touchCustomerInteraction( $from );

		// Store the message in the database.
		$messageEntity = $this->storeMessage( $conversation->id, $messageId, $from, $type, $data, $timestamp );

		if ( $messageEntity instanceof Message ) {
			$this->dispatchMessageReceivedEvent( $messageEntity, $from, (int) $conversation->id );
		}

		// Extract text content for processing.
		$textContent = $this->extractTextContent( $type, $data );

		// Classify intent if we have text content.
		$intent = null;
		if ( '' !== $textContent ) {
			$intent = $this->classifyIntent( $textContent, $conversation );
		}

		$actionResult = $this->handleActionRouting( $conversation, $textContent, $intent, $data );

		// Fire event for extensibility.
		/**
		 * Fires after a webhook message has been processed.
		 *
		 * @param array       $data         Original message data.
		 * @param object      $conversation Conversation entity.
		 * @param object|null $intent       Classified intent or null.
		 * @param mixed       $actionResult Action result payload.
		 */
		do_action( 'wch_webhook_message_processed', $data, $conversation, $intent, $actionResult );

		$this->logInfo(
			'Message processed successfully',
			[
				'message_id'      => $messageId,
				'conversation_id' => $conversation->id,
				'intent'          => $intent ? $intent->getName() : null,
				'action'          => $actionResult instanceof ActionResult ? $this->summarizeActionResult( $actionResult ) : null,
			]
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
			$messageData = [
				'conversation_id' => $conversationId,
				'wa_message_id'   => $waMessageId,
				'direction'       => 'inbound',
				'type'            => $type,
				'content'         => $this->buildContentPayload( $type, $data ),
				'raw_payload'     => $data,
				'status'          => Message::STATUS_DELIVERED,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $timestamp ),
			];

			$messageId = $this->messageRepository->create( $messageData );

			return $this->messageRepository->find( $messageId );
		} catch ( \Throwable $e ) {
			$this->logError(
				'Failed to store message',
				[
					'error'      => $e->getMessage(),
					'message_id' => $waMessageId,
				]
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
				$interactive = $data['interactive'] ?? [];
				if ( isset( $interactive['list_reply'] ) ) {
					return $interactive['list_reply']['id'] ?? $interactive['list_reply']['title'] ?? '';
				}
				if ( isset( $interactive['button_reply'] ) ) {
					return $interactive['button_reply']['id'] ?? $interactive['button_reply']['title'] ?? '';
				}
				return '';

			case 'location':
				// Return a structured location string for processing.
				$location = $data['location'] ?? [];
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
				$media = $data[ $type ] ?? [];
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
	 * @return Intent|null The classified intent or null.
	 */
	private function classifyIntent( string $text, object $conversation ): ?Intent {
		try {
			$classifier = wch( IntentClassifierService::class );
			$context    = is_array( $conversation->context ?? null ) ? $conversation->context : [];

			return $classifier->classify( $text, $context );
		} catch ( \Throwable $e ) {
			$this->logError(
				'Intent classification failed',
				[
					'error' => $e->getMessage(),
					'text'  => substr( $text, 0, 100 ),
				]
			);
			return null;
		}
	}

	/**
	 * Resolve intent and payload into action execution.
	 *
	 * @param object       $conversation The conversation entity.
	 * @param string       $textContent  Extracted text content.
	 * @param Intent|null  $intent       Classified intent.
	 * @param array        $data         Raw message payload.
	 * @return ActionResult|null
	 */
	private function handleActionRouting(
		object $conversation,
		string $textContent,
		?Intent $intent,
		array $data
	): ?ActionResult {
		$contextManager = wch( ContextManagerService::class );
		$context        = $contextManager->getContext( $conversation->id );

		if ( '' === $context->getCustomerPhone() && ! empty( $conversation->customer_phone ) ) {
			$context->setCustomerPhone( (string) $conversation->customer_phone );
		}

		$action = $this->resolveAction( $textContent, $intent, $context );
		if ( ! $action ) {
			return null;
		}

		if ( 'prompt_search' === $action['name'] ) {
			$result = $this->buildSearchPromptResult();
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'clear_cart' === $action['name'] ) {
			$result = $this->buildClearCartResult( (string) $conversation->customer_phone );
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'track_order_prompt' === $action['name'] ) {
			$result = $this->buildTrackOrderPromptResult();
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'track_order' === $action['name'] ) {
			$orderId = (int) ( $action['params']['order_id'] ?? 0 );
			$result  = $this->buildTrackOrderResult( (string) $conversation->customer_phone, $orderId );
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'prompt_new_address' === $action['name'] ) {
			$result = $this->buildNewAddressPromptResult();
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'select_saved_address' === $action['name'] ) {
			$index  = (int) ( $action['params']['index'] ?? -1 );
			$result = $this->buildSavedAddressResult( (string) $conversation->customer_phone, $index, $context );
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'capture_address' === $action['name'] ) {
			$addressText = (string) ( $action['params']['text'] ?? '' );
			$result      = $this->buildAddressCaptureResult( (string) $conversation->customer_phone, $addressText, $context );
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'prompt_cart_item_update' === $action['name'] ) {
			$productId   = (int) ( $action['params']['product_id'] ?? 0 );
			$variationId = isset( $action['params']['variation_id'] )
				? (int) $action['params']['variation_id']
				: null;
			$showError = ! empty( $action['params']['invalid'] );
			$result    = $this->buildCartItemUpdatePromptResult(
				(string) $conversation->customer_phone,
				$productId,
				$variationId,
				$showError
			);
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'update_cart_item' === $action['name'] ) {
			$productId   = (int) ( $action['params']['product_id'] ?? 0 );
			$variationId = isset( $action['params']['variation_id'] )
				? (int) $action['params']['variation_id']
				: null;
			$quantity = (int) ( $action['params']['quantity'] ?? 0 );
			$result   = $this->buildCartItemUpdateResult(
				(string) $conversation->customer_phone,
				$productId,
				$variationId,
				$quantity,
				$context
			);
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'cart_item_update_invalid' === $action['name'] ) {
			$productId   = (int) ( $action['params']['product_id'] ?? 0 );
			$variationId = isset( $action['params']['variation_id'] )
				? (int) $action['params']['variation_id']
				: null;
			$result = $this->buildCartItemUpdatePromptResult(
				(string) $conversation->customer_phone,
				$productId,
				$variationId,
				true
			);
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'support_contact' === $action['name'] ) {
			$result = $this->buildSupportContactResult();
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		if ( 'search_products' === $action['name'] ) {
			$query  = (string) ( $action['params']['query'] ?? '' );
			$result = $this->buildSearchResult( $conversation, $query );
			$this->applyActionResult( $conversation, $context, $result, $textContent );
			return $result;
		}

		$registry = wch( ActionRegistry::class );
		$result   = $registry->execute(
			$action['name'],
			(string) $conversation->customer_phone,
			$action['params'],
			$context
		);

		if ( ! $result instanceof ActionResult ) {
			$this->logWarning(
				'No action result returned',
				[ 'action' => $action['name'] ]
			);
			return null;
		}

		$this->applyActionResult( $conversation, $context, $result, $textContent );

		return $result;
	}

	/**
	 * Resolve action name and parameters from payload and intent.
	 *
	 * @param string             $textContent Extracted text content.
	 * @param Intent|null        $intent      Classified intent.
	 * @param ConversationContext $context    Conversation context.
	 * @return array{name: string, params: array}|null
	 */
	private function resolveAction( string $textContent, ?Intent $intent, ConversationContext $context ): ?array {
		$text = trim( $textContent );

		if ( '' !== $text ) {
			if ( $context->get( 'awaiting_address' ) ) {
				if ( 'new_address' === $text ) {
					return [
						'name'   => 'prompt_new_address',
						'params' => [],
					];
				}

				if ( preg_match( '/^saved_address_(\d+)$/', $text, $matches ) ) {
					return [
						'name'   => 'select_saved_address',
						'params' => [ 'index' => (int) $matches[1] ],
					];
				}

				return [
					'name'   => 'capture_address',
					'params' => [ 'text' => $text ],
				];
			}

			if ( $context->get( 'awaiting_cart_item_update' ) ) {
				$productId   = (int) $context->get( 'cart_item_product_id', 0 );
				$variationId = $context->get( 'cart_item_variation_id' );
				$variationId = null !== $variationId ? (int) $variationId : null;

				if ( 'remove' === strtolower( $text ) || 'delete' === strtolower( $text ) ) {
					return [
						'name'   => 'update_cart_item',
						'params' => [
							'product_id'   => $productId,
							'variation_id' => $variationId,
							'quantity'     => 0,
						],
					];
				}

				if ( ctype_digit( $text ) ) {
					return [
						'name'   => 'update_cart_item',
						'params' => [
							'product_id'   => $productId,
							'variation_id' => $variationId,
							'quantity'     => (int) $text,
						],
					];
				}

				return [
					'name'   => 'cart_item_update_invalid',
					'params' => [
						'product_id'   => $productId,
						'variation_id' => $variationId,
					],
				];
			}

			if ( $context->get( 'awaiting_order_id' ) && ctype_digit( $text ) ) {
				return [
					'name'   => 'track_order',
					'params' => [ 'order_id' => (int) $text ],
				];
			}

			if ( preg_match( '/^modify_item_(\d+)(?:_(\d+))?$/', $text, $matches ) ) {
				$variationId = isset( $matches[2] ) ? (int) $matches[2] : null;
				return [
					'name'   => 'prompt_cart_item_update',
					'params' => [
						'product_id'   => (int) $matches[1],
						'variation_id' => $variationId,
					],
				];
			}

			if ( preg_match( '/^product_(\d+)$/', $text, $matches ) ) {
				return [
					'name'   => 'show_product',
					'params' => [ 'product_id' => (int) $matches[1] ],
				];
			}

			if ( preg_match( '/^category_(\d+)$/', $text, $matches ) ) {
				return [
					'name'   => 'show_category',
					'params' => [ 'category_id' => (int) $matches[1] ],
				];
			}

			if ( preg_match( '/^add_to_cart_(\d+)$/', $text, $matches ) ) {
				return [
					'name'   => 'add_to_cart',
					'params' => [ 'product_id' => (int) $matches[1] ],
				];
			}

			if ( preg_match( '/^variant_(\d+)$/', $text, $matches ) ) {
				$productId = (int) $context->get( 'current_product', 0 );
				return [
					'name'   => 'add_to_cart',
					'params' => [
						'product_id' => $productId > 0 ? $productId : (int) $matches[1],
						'variant_id' => (int) $matches[1],
					],
				];
			}

			if ( preg_match( '/^payment_(.+)$/', $text, $matches ) ) {
				return [
					'name'   => 'process_payment',
					'params' => [ 'payment_method' => $matches[1] ],
				];
			}

			if ( preg_match( '/^prev_page_(\d+)$/', $text, $matches ) ) {
				$currentPage = (int) $context->get( 'current_page', 1 );
				return [
					'name'   => 'show_category',
					'params' => [
						'category_id' => (int) $matches[1],
						'page'        => max( 1, $currentPage - 1 ),
					],
				];
			}

			if ( preg_match( '/^next_page_(\d+)$/', $text, $matches ) ) {
				$currentPage = (int) $context->get( 'current_page', 1 );
				return [
					'name'   => 'show_category',
					'params' => [
						'category_id' => (int) $matches[1],
						'page'        => $currentPage + 1,
					],
				];
			}

			if ( preg_match( '/^track_order_(\d+)$/', $text, $matches ) ) {
				return [
					'name'   => 'track_order',
					'params' => [ 'order_id' => (int) $matches[1] ],
				];
			}
		}

		switch ( $text ) {
			case 'menu':
			case 'main_menu':
			case 'continue_shopping':
			case 'browse_products':
				return [ 'name' => 'show_main_menu', 'params' => [] ];

			case 'menu_search':
				return [ 'name' => 'prompt_search', 'params' => [] ];

			case 'menu_shop_category':
				return [ 'name' => 'show_category', 'params' => [] ];

			case 'menu_view_cart':
			case 'view_cart':
				return [ 'name' => 'show_cart', 'params' => [] ];

			case 'menu_track_order':
				return [ 'name' => 'track_order_prompt', 'params' => [] ];

			case 'menu_support':
				return [ 'name' => 'support_contact', 'params' => [] ];

			case 'clear_cart':
				return [ 'name' => 'clear_cart', 'params' => [] ];

			case 'checkout':
				return [ 'name' => 'process_payment', 'params' => [] ];

			case 'confirm_order':
				return [ 'name' => 'confirm_order', 'params' => [] ];

			case 'change_payment':
				return [ 'name' => 'process_payment', 'params' => [] ];

			case 'back_to_category':
				$categoryId = (int) $context->get( 'current_category', 0 );
				if ( $categoryId > 0 ) {
					return [
						'name'   => 'show_category',
						'params' => [ 'category_id' => $categoryId ],
					];
				}
				return [ 'name' => 'show_main_menu', 'params' => [] ];

			case 'back_to_menu':
				return [ 'name' => 'show_main_menu', 'params' => [] ];
		}

		if ( $intent ) {
			return match ( $intent->getName() ) {
				Intent::GREETING,
				Intent::BROWSE => [ 'name' => 'show_main_menu', 'params' => [] ],
				Intent::SEARCH => [ 'name' => 'search_products', 'params' => [ 'query' => $text ] ],
				Intent::VIEW_CART => [ 'name' => 'show_cart', 'params' => [] ],
				Intent::CHECKOUT => [ 'name' => 'show_cart', 'params' => [] ],
				Intent::ADD_TO_CART => $this->resolveAddToCartFromContext( $context ),
				default => null,
			};
		}

		return null;
	}

	/**
	 * Resolve add-to-cart action from current context.
	 *
	 * @param ConversationContext $context Conversation context.
	 * @return array{name: string, params: array}|null
	 */
	private function resolveAddToCartFromContext( ConversationContext $context ): ?array {
		$productId = (int) $context->get( 'current_product', 0 );
		if ( $productId <= 0 ) {
			return null;
		}

		return [
			'name'   => 'add_to_cart',
			'params' => [ 'product_id' => $productId ],
		];
	}

	/**
	 * Build a prompt asking the customer for a search term.
	 *
	 * @return ActionResult
	 */
	private function buildSearchPromptResult(): ActionResult {
		$message = new MessageBuilder();
		$message->text( __( 'What product are you looking for? Reply with a name or keyword.', 'whatsapp-commerce-hub' ) );

		return ActionResult::success( [ $message ] );
	}

	/**
	 * Build search results response using catalog browser.
	 *
	 * @param object $conversation Conversation entity.
	 * @param string $query Search query.
	 * @return ActionResult
	 */
	private function buildSearchResult( object $conversation, string $query ): ActionResult {
		$browser  = new CatalogBrowser();
		$messages = $browser->searchProducts( $query, 1, $conversation );

		if ( empty( $messages ) ) {
			$message = new MessageBuilder();
			$message->text( __( 'No products found for that search. Try another keyword.', 'whatsapp-commerce-hub' ) );
			return ActionResult::success( [ $message ] );
		}

		return ActionResult::success( $messages );
	}

	/**
	 * Clear the customer's cart and confirm.
	 *
	 * @param string $phone Customer phone number.
	 * @return ActionResult
	 */
	private function buildClearCartResult( string $phone ): ActionResult {
		try {
			$cartService = wch( CartServiceInterface::class );
			$cartService->clearCart( $phone );
		} catch ( \Throwable $e ) {
			$message = new MessageBuilder();
			$message->text( __( 'Sorry, we could not clear your cart. Please try again.', 'whatsapp-commerce-hub' ) );
			return ActionResult::failure( $e->getMessage(), [ $message ] );
		}

		$message = new MessageBuilder();
		$message->text( __( 'Your cart is now empty.', 'whatsapp-commerce-hub' ) );
		$message->button(
			'reply',
			[
				'id'    => 'menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success( [ $message ] );
	}

	/**
	 * Prompt the customer to enter a new shipping address.
	 *
	 * @return ActionResult
	 */
	private function buildNewAddressPromptResult(): ActionResult {
		$message = new MessageBuilder();
		$message->text(
			sprintf(
				"%s\n\n%s\n• %s\n• %s\n• %s\n• %s\n• %s",
				__( 'Please provide your shipping address.', 'whatsapp-commerce-hub' ),
				__( 'Include:', 'whatsapp-commerce-hub' ),
				__( 'Street address', 'whatsapp-commerce-hub' ),
				__( 'City', 'whatsapp-commerce-hub' ),
				__( 'State/Province', 'whatsapp-commerce-hub' ),
				__( 'Postal/ZIP code', 'whatsapp-commerce-hub' ),
				__( 'Country', 'whatsapp-commerce-hub' )
			)
		);

		return ActionResult::success(
			[ $message ],
			null,
			[ 'awaiting_address' => true ]
		);
	}

	/**
	 * Capture address input from free-form text.
	 *
	 * @param string             $phone   Customer phone number.
	 * @param string             $text    Address text.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	private function buildAddressCaptureResult(
		string $phone,
		string $text,
		ConversationContext $context
	): ActionResult {
		$addressService = wch( AddressServiceInterface::class );
		$address        = $addressService->fromText( $text );
		$address        = $addressService->normalize( $address );
		$validation     = $addressService->validate( $address );

		if ( empty( $validation['is_valid'] ) ) {
			$message = new MessageBuilder();
			$message->text(
				sprintf(
					"%s\n\n%s",
					__( 'Please include your street, city, and country in the address.', 'whatsapp-commerce-hub' ),
					__( 'You can reply with the full address in multiple lines.', 'whatsapp-commerce-hub' )
				)
			);

			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_address' => true ]
			);
		}

		try {
			$cartService = wch( CartServiceInterface::class );
			$cartService->setShippingAddress( $phone, $address );
		} catch ( \Throwable $e ) {
			$message = new MessageBuilder();
			$message->text( __( 'We could not save that address. Please try again.', 'whatsapp-commerce-hub' ) );
			return ActionResult::failure( $e->getMessage(), [ $message ] );
		}

		$context->updateStateData(
			[
				'shipping_address' => $address,
				'awaiting_address' => false,
			]
		);

		$registry = wch( ActionRegistry::class );
		$result   = $registry->execute( 'process_payment', $phone, [], $context );

		if ( $result instanceof ActionResult ) {
			return $result->withContext(
				[
					'shipping_address' => $address,
					'awaiting_address' => false,
				]
			);
		}

		$message = new MessageBuilder();
		$message->text( __( 'Address saved. Reply with "checkout" to continue.', 'whatsapp-commerce-hub' ) );

		return ActionResult::success(
			[ $message ],
			null,
			[
				'shipping_address' => $address,
				'awaiting_address' => false,
			]
		);
	}

	/**
	 * Handle saved address selection.
	 *
	 * @param string             $phone   Customer phone number.
	 * @param int                $index   Address index.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	private function buildSavedAddressResult(
		string $phone,
		int $index,
		ConversationContext $context
	): ActionResult {
		$customerService = wch( CustomerServiceInterface::class );
		$savedAddresses  = $customerService->getSavedAddresses( $phone );
		$savedAddress    = $savedAddresses[ $index ] ?? null;

		if ( ! is_array( $savedAddress ) ) {
			$message = new MessageBuilder();
			$message->text( __( 'Saved address not found. Please enter a new address.', 'whatsapp-commerce-hub' ) );
			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_address' => true ]
			);
		}

		$addressService = wch( AddressServiceInterface::class );
		$normalized     = $this->normalizeSavedAddress( $savedAddress );
		$normalized     = $addressService->normalize( $normalized );

		try {
			$cartService = wch( CartServiceInterface::class );
			$cartService->setShippingAddress( $phone, $normalized );
		} catch ( \Throwable $e ) {
			$message = new MessageBuilder();
			$message->text( __( 'We could not save that address. Please try again.', 'whatsapp-commerce-hub' ) );
			return ActionResult::failure( $e->getMessage(), [ $message ] );
		}

		$context->updateStateData(
			[
				'shipping_address' => $normalized,
				'awaiting_address' => false,
			]
		);

		$registry = wch( ActionRegistry::class );
		$result   = $registry->execute( 'process_payment', $phone, [], $context );

		if ( $result instanceof ActionResult ) {
			return $result->withContext(
				[
					'shipping_address' => $normalized,
					'awaiting_address' => false,
				]
			);
		}

		$message = new MessageBuilder();
		$message->text( __( 'Address saved. Reply with "checkout" to continue.', 'whatsapp-commerce-hub' ) );

		return ActionResult::success(
			[ $message ],
			null,
			[
				'shipping_address' => $normalized,
				'awaiting_address' => false,
			]
		);
	}

	/**
	 * Prompt for cart item update.
	 *
	 * @param string   $phone       Customer phone number.
	 * @param int      $productId   Product ID.
	 * @param int|null $variationId Variation ID.
	 * @param bool     $showError   Whether to show invalid input prompt.
	 * @return ActionResult
	 */
	private function buildCartItemUpdatePromptResult(
		string $phone,
		int $productId,
		?int $variationId,
		bool $showError = false
	): ActionResult {
		$cartService = wch( CartServiceInterface::class );
		$cart        = $cartService->getCart( $phone );
		$itemIndex   = $this->findCartItemIndex( $cart->items ?? [], $productId, $variationId );

		if ( null === $itemIndex ) {
			$message = new MessageBuilder();
			$message->text( __( 'Item not found in your cart. Reply with "view cart" to try again.', 'whatsapp-commerce-hub' ) );
			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_cart_item_update' => false ]
			);
		}

		$item        = $cart->items[ $itemIndex ];
		$productName = $item['product_name'] ?? '';
		if ( empty( $productName ) && isset( $item['product_id'] ) ) {
			$product     = wc_get_product( $item['variation_id'] ?? $item['product_id'] );
			$productName = $product ? $product->get_name() : '';
		}

		$message = new MessageBuilder();
		$intro   = $showError
			? __( 'Please reply with a number or type "remove".', 'whatsapp-commerce-hub' )
			: __( 'Reply with a new quantity or type "remove".', 'whatsapp-commerce-hub' );

		$message->text(
			sprintf(
				"%s\n\n%s: %s\n%s: %d",
				$intro,
				__( 'Item', 'whatsapp-commerce-hub' ),
				$productName ?: __( 'Selected item', 'whatsapp-commerce-hub' ),
				__( 'Current quantity', 'whatsapp-commerce-hub' ),
				(int) ( $item['quantity'] ?? 0 )
			)
		);

		return ActionResult::success(
			[ $message ],
			null,
			[
				'awaiting_cart_item_update' => true,
				'cart_item_product_id'      => $productId,
				'cart_item_variation_id'    => $variationId,
			]
		);
	}

	/**
	 * Apply cart item quantity update.
	 *
	 * @param string             $phone       Customer phone number.
	 * @param int                $productId   Product ID.
	 * @param int|null           $variationId Variation ID.
	 * @param int                $quantity    Desired quantity.
	 * @param ConversationContext $context     Conversation context.
	 * @return ActionResult
	 */
	private function buildCartItemUpdateResult(
		string $phone,
		int $productId,
		?int $variationId,
		int $quantity,
		ConversationContext $context
	): ActionResult {
		$cartService = wch( CartServiceInterface::class );
		$cart        = $cartService->getCart( $phone );
		$itemIndex   = $this->findCartItemIndex( $cart->items ?? [], $productId, $variationId );

		if ( null === $itemIndex ) {
			$message = new MessageBuilder();
			$message->text( __( 'Item not found in your cart. Reply with "view cart" to try again.', 'whatsapp-commerce-hub' ) );
			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_cart_item_update' => false ]
			);
		}

		try {
			$cartService->updateQuantity( $phone, $itemIndex, $quantity );
		} catch ( \Throwable $e ) {
			$message = new MessageBuilder();
			$message->text( __( 'Unable to update that item. Please try again.', 'whatsapp-commerce-hub' ) );
			return ActionResult::failure( $e->getMessage(), [ $message ] );
		}

		$registry = wch( ActionRegistry::class );
		$result   = $registry->execute( 'show_cart', $phone, [], $context );

		if ( $result instanceof ActionResult ) {
			return $result->withContext(
				[
					'awaiting_cart_item_update' => false,
					'cart_item_product_id'      => null,
					'cart_item_variation_id'    => null,
				]
			);
		}

		$message = new MessageBuilder();
		$message->text( __( 'Cart updated.', 'whatsapp-commerce-hub' ) );
		return ActionResult::success(
			[ $message ],
			null,
			[
				'awaiting_cart_item_update' => false,
				'cart_item_product_id'      => null,
				'cart_item_variation_id'    => null,
			]
		);
	}

	/**
	 * Find a cart item index by product and variation.
	 *
	 * @param array    $items       Cart items.
	 * @param int      $productId   Product ID.
	 * @param int|null $variationId Variation ID.
	 * @return int|null
	 */
	private function findCartItemIndex( array $items, int $productId, ?int $variationId ): ?int {
		foreach ( $items as $index => $item ) {
			$itemProductId   = (int) ( $item['product_id'] ?? 0 );
			$itemVariationId = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : null;

			if ( $itemProductId === $productId && $itemVariationId === $variationId ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Normalize saved address formats to the standard structure.
	 *
	 * @param array $address Raw address data.
	 * @return array
	 */
	private function normalizeSavedAddress( array $address ): array {
		$normalized = $address;

		if ( empty( $normalized['street'] ) && ! empty( $normalized['address_1'] ) ) {
			$normalized['street'] = $normalized['address_1'];
		}

		if ( empty( $normalized['street_2'] ) && ! empty( $normalized['address_2'] ) ) {
			$normalized['street_2'] = $normalized['address_2'];
		}

		if ( empty( $normalized['postal_code'] ) && ! empty( $normalized['postcode'] ) ) {
			$normalized['postal_code'] = $normalized['postcode'];
		}

		if ( empty( $normalized['name'] ) && ! empty( $normalized['first_name'] ) ) {
			$lastName           = $normalized['last_name'] ?? '';
			$normalized['name'] = trim( $normalized['first_name'] . ' ' . $lastName );
		}

		return $normalized;
	}

	/**
	 * Update last interaction timestamp for the customer.
	 *
	 * @param string $phone Customer phone number.
	 * @return void
	 */
	private function touchCustomerInteraction( string $phone ): void {
		try {
			$customerRepo = wch( CustomerRepositoryInterface::class );
			$customer     = $customerRepo->findByPhone( $phone );
			if ( $customer ) {
				$customerRepo->touchInteraction( $customer->id );
			}
		} catch ( \Throwable $e ) {
			$this->logWarning(
				'Failed to update customer interaction timestamp',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Prompt the customer to enter an order ID for tracking.
	 *
	 * @return ActionResult
	 */
	private function buildTrackOrderPromptResult(): ActionResult {
		$message = new MessageBuilder();
		$message->text( __( 'Please reply with your order ID to track your order status.', 'whatsapp-commerce-hub' ) );

		return ActionResult::success(
			[ $message ],
			null,
			[ 'awaiting_order_id' => true ]
		);
	}

	/**
	 * Build a response with order status details.
	 *
	 * @param string $phone   Customer phone number.
	 * @param int    $orderId WooCommerce order ID.
	 * @return ActionResult
	 */
	private function buildTrackOrderResult( string $phone, int $orderId ): ActionResult {
		if ( $orderId <= 0 ) {
			return $this->buildTrackOrderPromptResult();
		}

		$order = wc_get_order( $orderId );
		if ( ! $order ) {
			$message = new MessageBuilder();
			$message->text( __( 'We could not find that order. Please reply with a valid order ID.', 'whatsapp-commerce-hub' ) );
			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_order_id' => true ]
			);
		}

		$billingPhone  = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		$shippingPhone = preg_replace( '/\D+/', '', (string) $order->get_shipping_phone() );
		$customerPhone = preg_replace( '/\D+/', '', $phone );

		if ( '' !== $customerPhone && $customerPhone !== $billingPhone && $customerPhone !== $shippingPhone ) {
			$message = new MessageBuilder();
			$message->text( __( 'We could not find that order. Please reply with a valid order ID.', 'whatsapp-commerce-hub' ) );
			return ActionResult::success(
				[ $message ],
				null,
				[ 'awaiting_order_id' => true ]
			);
		}

		$statusLabel = function_exists( 'wc_get_order_status_name' )
			? wc_get_order_status_name( $order->get_status() )
			: ucfirst( $order->get_status() );

		$message = new MessageBuilder();
		$message->text(
			sprintf(
				/* translators: 1: order number, 2: order status */
				__( 'Order #%1$d is currently %2$s.', 'whatsapp-commerce-hub' ),
				$orderId,
				$statusLabel
			)
		);
		$message->button(
			'reply',
			[
				'id'    => 'menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success(
			[ $message ],
			null,
			[ 'awaiting_order_id' => false ]
		);
	}

	/**
	 * Build a response with support contact details.
	 *
	 * @return ActionResult
	 */
	private function buildSupportContactResult(): ActionResult {
		$adminEmail = (string) get_option( 'admin_email' );
		$message    = new MessageBuilder();
		$message->text(
			sprintf(
				/* translators: %s: support email address */
				__( 'Need help? Contact our team at %s and we will get back to you.', 'whatsapp-commerce-hub' ),
				$adminEmail
			)
		);
		$message->button(
			'reply',
			[
				'id'    => 'menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success( [ $message ] );
	}

	/**
	 * Apply action result to context, persist, and send messages.
	 *
	 * @param object             $conversation Conversation entity.
	 * @param ConversationContext $context     Conversation context.
	 * @param ActionResult       $result      Action result.
	 * @param string             $textContent Incoming text content.
	 * @return void
	 */
	private function applyActionResult(
		object $conversation,
		ConversationContext $context,
		ActionResult $result,
		string $textContent
	): void {
		if ( $result->hasStateTransition() ) {
			$nextState = (string) $result->getNextState();
			$context->setCurrentState( $nextState );
			$this->conversationRepository->updateState( $conversation->id, $nextState );
		}

		$updates = $result->getContextUpdates();
		if ( ! empty( $updates ) ) {
			$context->updateStateData( $updates );
		}

		$responseText = $this->extractResponseText( $result );
		if ( '' !== $textContent && '' !== $responseText ) {
			$context->addExchange( $textContent, $responseText );
		}

		wch( ContextManagerService::class )->saveContext( $conversation->id, $context );

		$this->sendActionMessages(
			(string) $conversation->customer_phone,
			(int) $conversation->id,
			$result
		);
	}

	/**
	 * Send action messages to WhatsApp and persist them.
	 *
	 * @param string       $phone          Customer phone number.
	 * @param int          $conversationId Conversation ID.
	 * @param ActionResult $result         Action result.
	 * @return void
	 */
	private function sendActionMessages( string $phone, int $conversationId, ActionResult $result ): void {
		$messages = $result->getBuiltMessages();
		if ( empty( $messages ) ) {
			return;
		}

		$apiClient = null;
		try {
			$apiClient = wch( WhatsAppApiClient::class );
		} catch ( \Throwable $e ) {
			$this->logWarning( 'WhatsApp API client unavailable for action response' );
			return;
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$payload = $this->buildOutboundPayload( $phone, $message );
			$messageId = null;

			try {
				$response  = $apiClient->sendMessage( $payload );
				$messageId = $response['message_id'] ?? $response['messages'][0]['id'] ?? null;
			} catch ( \Throwable $e ) {
				$this->logError(
					'Failed to send WhatsApp response',
					[ 'error' => $e->getMessage() ]
				);
			}

			$storedMessage = $this->persistOutboundMessage( $conversationId, $message, $messageId );

			if ( $storedMessage instanceof Message && Message::STATUS_SENT === $storedMessage->status ) {
				$this->dispatchMessageSentEvent( $storedMessage, $phone );
			}
		}
	}

	/**
	 * Build full WhatsApp API payload for outbound message.
	 *
	 * @param string $phone Customer phone number.
	 * @param array  $message Built message payload.
	 * @return array<string, mixed>
	 */
	private function buildOutboundPayload( string $phone, array $message ): array {
		return array_merge(
			[
				'messaging_product' => 'whatsapp',
				'recipient_type'    => 'individual',
				'to'                => $phone,
			],
			$message
		);
	}

	/**
	 * Persist outbound message in repository.
	 *
	 * @param int         $conversationId Conversation ID.
	 * @param array       $message       Message payload.
	 * @param string|null $messageId     WhatsApp message ID.
	 * @return Message|null
	 */
	private function persistOutboundMessage( int $conversationId, array $message, ?string $messageId ): ?Message {
		try {
			$waMessageId = $messageId;
			if ( ! $waMessageId ) {
				try {
					$waMessageId = 'outbound_' . bin2hex( random_bytes(8) );
				} catch ( \Throwable $e ) {
					$waMessageId = 'outbound_' . uniqid();
				}
			}

			$messageId = $this->messageRepository->create(
				[
					'conversation_id' => $conversationId,
					'wa_message_id'   => $waMessageId,
					'direction'       => 'outbound',
					'type'            => $message['type'] ?? 'text',
					'content'         => $message,
					'status'          => $messageId ? 'sent' : 'failed',
					'created_at'      => current_time( 'mysql' ),
				]
			);

			return $this->messageRepository->find( $messageId );
		} catch ( \Throwable $e ) {
			$this->logError(
				'Failed to persist outbound message',
				[ 'error' => $e->getMessage() ]
			);
			return null;
		}
	}

	/**
	 * Dispatch message received event.
	 *
	 * @param Message $message Message entity.
	 * @param string  $from Sender phone number.
	 * @param int     $conversationId Conversation ID.
	 * @return void
	 */
	private function dispatchMessageReceivedEvent( Message $message, string $from, int $conversationId ): void {
		try {
			$eventBus = wch( EventBus::class );
			$eventBus->dispatch( new MessageReceivedEvent( $message, $from, $conversationId ) );
		} catch ( \Throwable $e ) {
			$this->logWarning(
				'Failed to dispatch message received event',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Dispatch message sent event.
	 *
	 * @param Message $message Message entity.
	 * @param string  $to Recipient phone number.
	 * @return void
	 */
	private function dispatchMessageSentEvent( Message $message, string $to ): void {
		try {
			$eventBus = wch( EventBus::class );
			$eventBus->dispatch( new MessageSentEvent( $message, $to ) );
		} catch ( \Throwable $e ) {
			$this->logWarning(
				'Failed to dispatch message sent event',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Extract response text from action result messages.
	 *
	 * @param ActionResult $result Action result.
	 * @return string
	 */
	private function extractResponseText( ActionResult $result ): string {
		$messages = $result->getBuiltMessages();
		foreach ( $messages as $message ) {
			if ( isset( $message['text']['body'] ) ) {
				return (string) $message['text']['body'];
			}
		}

		return '';
	}

	/**
	 * Build normalized content payload for storage.
	 *
	 * @param string $type The message type.
	 * @param array  $data The raw message data.
	 * @return array Normalized message content.
	 */
	private function buildContentPayload( string $type, array $data ): array {
		switch ( $type ) {
			case 'text':
				return [ 'text' => $this->extractTextContent( $type, $data ) ];
			case 'interactive':
				return [ 'interactive' => $data['interactive'] ?? [] ];
			case 'button':
				return [
					'button' => [
						'text'    => $data['button']['text'] ?? '',
						'payload' => $data['button']['payload'] ?? '',
					],
				];
			default:
				if ( isset( $data[ $type ] ) && is_array( $data[ $type ] ) ) {
					return [ $type => $data[ $type ] ];
				}
				return [];
		}
	}

	/**
	 * Summarize action result for logging.
	 *
	 * @param ActionResult $result Action result.
	 * @return string
	 */
	private function summarizeActionResult( ActionResult $result ): string {
		$messages = $result->getBuiltMessages();
		$status   = $result->isSuccess() ? 'success' : 'failure';

		return $status . ':' . count( $messages );
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
		if ( str_contains( $exception->getMessage(), 'already processed' ) ) {
			return false;
		}

		// Retry on other errors (network, database, etc.).
		return parent::shouldRetry( $exception );
	}
}
