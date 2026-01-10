<?php
/**
 * Webhook Error Processor
 *
 * Processes error events from WhatsApp webhooks.
 * Integrates with circuit breaker for automatic failure handling.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue\Processors;

use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\IdempotencyService;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookErrorProcessor
 *
 * Processes API errors from WhatsApp webhooks:
 * - Logs error details for debugging
 * - Records failures to the circuit breaker
 * - Triggers alerts for critical errors
 * - Updates affected message/conversation states
 */
class WebhookErrorProcessor extends AbstractQueueProcessor {

	/**
	 * Processor name.
	 */
	private const NAME = 'webhook_error';

	/**
	 * Action Scheduler hook name.
	 */
	private const HOOK_NAME = 'wch_process_webhook_errors';

	/**
	 * Circuit breaker service name.
	 */
	private const CIRCUIT_SERVICE = 'whatsapp_api';

	/**
	 * Error codes that indicate rate limiting.
	 */
	private const RATE_LIMIT_CODES = [
		130429, // Rate limit hit.
		131048, // Spam rate limit.
		131056, // Too many messages sent.
	];

	/**
	 * Error codes that indicate authentication issues.
	 */
	private const AUTH_ERROR_CODES = [
		190,    // Access token has expired.
		200,    // Permission denied.
		10,     // Application does not have permission.
		100,    // Invalid parameter.
	];

	/**
	 * Error codes that indicate template issues.
	 */
	private const TEMPLATE_ERROR_CODES = [
		132000, // Template param count mismatch.
		132001, // Template does not exist.
		132005, // Template paused.
		132007, // Template disabled.
		132012, // Template not found.
		132015, // Template character policy violated.
	];

	/**
	 * Idempotency service.
	 *
	 * @var IdempotencyService
	 */
	private IdempotencyService $idempotencyService;

	/**
	 * Circuit breaker.
	 *
	 * @var CircuitBreaker|null
	 */
	private ?CircuitBreaker $circuitBreaker;

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue      $priorityQueue      Priority queue for retries.
	 * @param DeadLetterQueue    $deadLetterQueue    Dead letter queue for failures.
	 * @param IdempotencyService $idempotencyService Idempotency service for deduplication.
	 * @param CircuitBreaker     $circuitBreaker     Circuit breaker for API protection.
	 */
	public function __construct(
		PriorityQueue $priorityQueue,
		DeadLetterQueue $deadLetterQueue,
		IdempotencyService $idempotencyService,
		?CircuitBreaker $circuitBreaker = null
	) {
		parent::__construct( $priorityQueue, $deadLetterQueue );

		$this->idempotencyService = $idempotencyService;
		$this->circuitBreaker     = $circuitBreaker;
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

		// Extract error details.
		$errorCode    = $data['code'] ?? $data['error_code'] ?? 0;
		$errorMessage = $data['message'] ?? $data['error_message'] ?? 'Unknown error';
		$errorTitle   = $data['title'] ?? $data['error_title'] ?? '';
		$errorDetails = $data['error_data'] ?? $data['details'] ?? [];
		$messageId    = $data['message_id'] ?? '';
		$timestamp    = $data['timestamp'] ?? time();

		// Generate idempotency key for this error.
		$idempotencyKey = IdempotencyService::generateKey(
			(string) $errorCode,
			$messageId,
			(string) $timestamp
		);

		// Attempt to claim this error for processing.
		if ( ! $this->idempotencyService->claim( $idempotencyKey, IdempotencyService::SCOPE_WEBHOOK ) ) {
			$this->logDebug(
				'Error already processed, skipping',
				[
					'error_code' => $errorCode,
					'message_id' => $messageId,
				]
			);
			return;
		}

		$this->logError(
			'Processing webhook error',
			[
				'code'       => $errorCode,
				'message'    => $errorMessage,
				'title'      => $errorTitle,
				'message_id' => $messageId,
				'details'    => $errorDetails,
			]
		);

		// Categorize the error.
		$errorCategory = $this->categorizeError( (int) $errorCode );

		// Record failure to circuit breaker.
		$this->recordCircuitBreakerFailure( $errorCode, $errorMessage, $errorCategory );

		// Handle based on error category.
		switch ( $errorCategory ) {
			case 'rate_limit':
				$this->handleRateLimitError( $data );
				break;

			case 'auth':
				$this->handleAuthError( $data );
				break;

			case 'template':
				$this->handleTemplateError( $data );
				break;

			case 'recipient':
				$this->handleRecipientError( $data );
				break;

			default:
				$this->handleGenericError( $data );
				break;
		}

		// Fire event for extensibility.
		/**
		 * Fires after a webhook error has been processed.
		 *
		 * @param array  $data          Original error data.
		 * @param string $errorCategory The error category.
		 * @param int    $errorCode     The error code.
		 */
		do_action( 'wch_webhook_error_processed', $data, $errorCategory, (int) $errorCode );

		$this->logInfo(
			'Error processed',
			[
				'code'     => $errorCode,
				'category' => $errorCategory,
			]
		);
	}

	/**
	 * Categorize an error code.
	 *
	 * @param int $errorCode The error code.
	 * @return string The error category.
	 */
	private function categorizeError( int $errorCode ): string {
		if ( in_array( $errorCode, self::RATE_LIMIT_CODES, true ) ) {
			return 'rate_limit';
		}

		if ( in_array( $errorCode, self::AUTH_ERROR_CODES, true ) ) {
			return 'auth';
		}

		if ( in_array( $errorCode, self::TEMPLATE_ERROR_CODES, true ) ) {
			return 'template';
		}

		// Recipient-related errors are typically in the 131xxx range.
		if ( $errorCode >= 131000 && $errorCode < 132000 ) {
			return 'recipient';
		}

		return 'generic';
	}

	/**
	 * Record failure to circuit breaker.
	 *
	 * @param int|string $errorCode     The error code.
	 * @param string     $errorMessage  The error message.
	 * @param string     $errorCategory The error category.
	 * @return void
	 */
	private function recordCircuitBreakerFailure( $errorCode, string $errorMessage, string $errorCategory ): void {
		if ( ! $this->circuitBreaker ) {
			return;
		}

		// Only record circuit breaker failures for system-level errors.
		// Skip recipient errors as they're not service failures.
		if ( 'recipient' === $errorCategory ) {
			return;
		}

		$reason = sprintf( '[%s] %s: %s', $errorCategory, $errorCode, $errorMessage );
		$this->circuitBreaker->recordFailure( $reason );

		$this->logDebug(
			'Recorded circuit breaker failure',
			[
				'reason'   => $reason,
				'category' => $errorCategory,
			]
		);
	}

	/**
	 * Handle rate limit errors.
	 *
	 * @param array $data The error data.
	 * @return void
	 */
	private function handleRateLimitError( array $data ): void {
		$this->logWarning( 'Rate limit error received', $data );

		// Calculate backoff time from error details if available.
		$retryAfter = $data['error_data']['retry_after'] ?? null;
		$retryAfter = $data['details']['retry_after'] ?? $retryAfter;
		$retryAfter = $retryAfter ? (int) $retryAfter : 60; // Default 60 seconds.

		/**
		 * Fires when a rate limit error is received.
		 *
		 * @param array $data       The error data.
		 * @param int   $retryAfter Seconds to wait before retrying.
		 */
		do_action( 'wch_rate_limit_error', $data, $retryAfter );

		// Store rate limit info for the throttler.
		set_transient( 'wch_rate_limit_until', time() + $retryAfter, $retryAfter + 60 );
	}

	/**
	 * Handle authentication errors.
	 *
	 * @param array $data The error data.
	 * @return void
	 */
	private function handleAuthError( array $data ): void {
		$this->logError( 'Authentication error - check API credentials', $data );

		/**
		 * Fires when an authentication error is received.
		 * This is a critical error that requires admin attention.
		 *
		 * @param array $data The error data.
		 */
		do_action( 'wch_auth_error', $data );

		// Send admin notification for critical auth errors.
		$this->notifyAdmin( 'Authentication Error', $data );
	}

	/**
	 * Handle template errors.
	 *
	 * @param array $data The error data.
	 * @return void
	 */
	private function handleTemplateError( array $data ): void {
		$this->logWarning( 'Template error - check template configuration', $data );

		/**
		 * Fires when a template error is received.
		 *
		 * @param array $data The error data.
		 */
		do_action( 'wch_template_error', $data );
	}

	/**
	 * Handle recipient errors.
	 *
	 * @param array $data The error data.
	 * @return void
	 */
	private function handleRecipientError( array $data ): void {
		$this->logInfo( 'Recipient error - message could not be delivered', $data );

		/**
		 * Fires when a recipient error is received.
		 * These errors indicate the recipient is unreachable.
		 *
		 * @param array $data The error data.
		 */
		do_action( 'wch_recipient_error', $data );
	}

	/**
	 * Handle generic errors.
	 *
	 * @param array $data The error data.
	 * @return void
	 */
	private function handleGenericError( array $data ): void {
		$this->logWarning( 'Generic webhook error', $data );

		/**
		 * Fires when a generic/unclassified error is received.
		 *
		 * @param array $data The error data.
		 */
		do_action( 'wch_generic_error', $data );
	}

	/**
	 * Notify admin about critical errors.
	 *
	 * @param string $subject Error subject.
	 * @param array  $data    Error data.
	 * @return void
	 */
	private function notifyAdmin( string $subject, array $data ): void {
		// Check if admin notifications are enabled.
		$notifyAdmin = get_option( 'wch_notify_admin_on_errors', true );
		if ( ! $notifyAdmin ) {
			return;
		}

		// Get admin email.
		$adminEmail = get_option( 'wch_admin_email', get_option( 'admin_email' ) );
		if ( empty( $adminEmail ) ) {
			return;
		}

		// Rate limit admin notifications (max 1 per hour per error type).
		$rateLimitKey = 'wch_admin_notify_' . md5( $subject );
		if ( get_transient( $rateLimitKey ) ) {
			return;
		}
		set_transient( $rateLimitKey, true, HOUR_IN_SECONDS );

		// Build notification message.
		$message = sprintf(
			"WhatsApp Commerce Hub - %s\n\n" .
			"Error Code: %s\n" .
			"Message: %s\n" .
			"Time: %s\n\n" .
			"Details:\n%s",
			$subject,
			$data['code'] ?? $data['error_code'] ?? 'Unknown',
			$data['message'] ?? $data['error_message'] ?? 'Unknown error',
			current_time( 'mysql' ),
			wp_json_encode( $data, JSON_PRETTY_PRINT )
		);

		wp_mail(
			$adminEmail,
			'[WCH] ' . $subject,
			$message
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function isCircuitOpen(): bool {
		if ( ! $this->circuitBreaker ) {
			return false;
		}

		return ! $this->circuitBreaker->isAvailable();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Error processing should generally succeed.
	 * Only retry on unexpected exceptions, not on validation errors.
	 */
	public function shouldRetry( \Throwable $exception ): bool {
		// Don't retry validation errors.
		if ( $exception instanceof \InvalidArgumentException ) {
			return false;
		}

		return parent::shouldRetry( $exception );
	}
}
