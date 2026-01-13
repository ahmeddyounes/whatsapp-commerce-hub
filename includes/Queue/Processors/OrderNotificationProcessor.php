<?php
/**
 * Order Notification Processor
 *
 * Processes order lifecycle notifications via WhatsApp templates.
 * Handles confirmation, status updates, shipping, and delivery notifications.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Queue\Processors;

use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Presentation\Templates\TemplateManager;
use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\PriorityQueue;
use WhatsAppCommerceHub\Queue\IdempotencyService;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderNotificationProcessor
 *
 * Processes order notifications:
 * - Order confirmation
 * - Status updates (processing, shipped, completed, etc.)
 * - Shipping tracking information
 * - Delivery confirmation
 *
 * Includes quiet hours checking and customer opt-out handling.
 */
class OrderNotificationProcessor extends AbstractQueueProcessor {

	/**
	 * Processor name.
	 */
	private const NAME = 'order_notification';

	/**
	 * Action Scheduler hook name.
	 */
	private const HOOK_NAME = 'wch_send_order_notification';

	/**
	 * Notification types.
	 */
	private const TYPE_CONFIRMATION  = 'order_confirmation';
	private const TYPE_STATUS_UPDATE = 'status_update';
	private const TYPE_SHIPPING      = 'shipping_update';
	private const TYPE_DELIVERY      = 'delivery_confirmation';

	/**
	 * Idempotency service.
	 *
	 * @var IdempotencyService
	 */
	private IdempotencyService $idempotencyService;

	/**
	 * Circuit breaker for WhatsApp API.
	 *
	 * @var CircuitBreaker|null
	 */
	private ?CircuitBreaker $circuitBreaker;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue      $priorityQueue      Priority queue for retries.
	 * @param DeadLetterQueue    $deadLetterQueue    Dead letter queue for failures.
	 * @param IdempotencyService $idempotencyService Idempotency service for deduplication.
	 * @param CircuitBreaker     $circuitBreaker     Circuit breaker for API protection.
	 * @param \wpdb|null         $wpdb               WordPress database instance.
	 */
	public function __construct(
		PriorityQueue $priorityQueue,
		DeadLetterQueue $deadLetterQueue,
		IdempotencyService $idempotencyService,
		?CircuitBreaker $circuitBreaker = null,
		?\wpdb $wpdb = null
	) {
		parent::__construct( $priorityQueue, $deadLetterQueue );

		$this->idempotencyService = $idempotencyService;
		$this->circuitBreaker     = $circuitBreaker;

		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
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
		// Extract notification details.
		$orderId          = (int) ( $payload['order_id'] ?? 0 );
		$notificationType = $payload['notification_type'] ?? '';
		$customerPhone    = $payload['customer_phone'] ?? '';
		$templateName     = $payload['template_name'] ?? '';

		// Validate required fields.
		if ( 0 === $orderId ) {
			throw new \InvalidArgumentException( 'Missing required field: order_id' );
		}

		if ( empty( $notificationType ) ) {
			throw new \InvalidArgumentException( 'Missing required field: notification_type' );
		}

		// Generate idempotency key for this notification.
		$idempotencyKey = IdempotencyService::generateKey(
			(string) $orderId,
			$notificationType,
			$templateName
		);

		// Attempt to claim this notification for processing.
		if ( ! $this->idempotencyService->claim( $idempotencyKey, IdempotencyService::SCOPE_NOTIFICATION ) ) {
			$this->logInfo(
				'Notification already sent, skipping',
				[
					'order_id' => $orderId,
					'type'     => $notificationType,
				]
			);
			return;
		}

		$this->logDebug(
			'Processing order notification',
			[
				'order_id' => $orderId,
				'type'     => $notificationType,
			]
		);

		// Get the WooCommerce order.
		$order = $this->getOrder( $orderId );
		if ( ! $order ) {
			throw new \InvalidArgumentException(
				sprintf( 'Order not found: %d', $orderId )
			);
		}

		// Get customer phone if not provided.
		if ( empty( $customerPhone ) ) {
			$customerPhone = $this->getCustomerPhone( $order );
		}

		if ( empty( $customerPhone ) ) {
			$this->logWarning(
				'No customer phone for notification',
				[
					'order_id' => $orderId,
				]
			);
			return; // Don't throw - just skip silently.
		}

		// Check if we can send to this customer.
		if ( ! $this->canSendNotification( $customerPhone ) ) {
			$this->logInfo(
				'Notification blocked by opt-out or quiet hours',
				[
					'order_id' => $orderId,
					'phone'    => $this->maskPhone( $customerPhone ),
				]
			);
			return;
		}

		// Route to appropriate handler based on notification type.
		$success = $this->sendNotification( $notificationType, $order, $customerPhone, $payload );

		if ( ! $success ) {
			// Record circuit breaker failure if applicable.
			if ( $this->circuitBreaker ) {
				$this->circuitBreaker->recordFailure( 'Notification send failed' );
			}

			throw new \RuntimeException(
				sprintf( 'Failed to send %s notification for order %d', $notificationType, $orderId )
			);
		}

		// Record success to circuit breaker.
		if ( $this->circuitBreaker ) {
			$this->circuitBreaker->recordSuccess();
		}

		$this->logInfo(
			'Order notification sent successfully',
			[
				'order_id' => $orderId,
				'type'     => $notificationType,
			]
		);
	}

	/**
	 * Send the appropriate notification based on type.
	 *
	 * @param string    $type     Notification type.
	 * @param \WC_Order $order    The WooCommerce order.
	 * @param string    $phone    Customer phone number.
	 * @param array     $payload  Full payload with additional params.
	 * @return bool True on success.
	 */
	private function sendNotification( string $type, \WC_Order $order, string $phone, array $payload ): bool {
		switch ( $type ) {
			case self::TYPE_CONFIRMATION:
				return $this->sendOrderConfirmation( $order, $phone );

			case self::TYPE_STATUS_UPDATE:
				$newStatus = $payload['new_status'] ?? '';
				return $this->sendStatusUpdate( $order, $phone, $newStatus );

			case self::TYPE_SHIPPING:
				$trackingNumber = $payload['tracking_number'] ?? '';
				$carrier        = $payload['carrier'] ?? '';
				return $this->sendShippingUpdate( $order, $phone, $trackingNumber, $carrier );

			case self::TYPE_DELIVERY:
				return $this->sendDeliveryConfirmation( $order, $phone );

			default:
				$this->logWarning( 'Unknown notification type', [ 'type' => $type ] );
				return false;
		}
	}

	/**
	 * Send order confirmation notification.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param string    $phone Customer phone number.
	 * @return bool True on success.
	 */
	private function sendOrderConfirmation( \WC_Order $order, string $phone ): bool {
		$variables = [
			'customer_name'      => $order->get_billing_first_name(),
			'order_number'       => $order->get_order_number(),
			'order_total'        => $order->get_formatted_order_total(),
			'item_count'         => (string) $order->get_item_count(),
			'estimated_delivery' => $this->getEstimatedDelivery( $order ),
		];

		return $this->sendTemplateMessage(
			$order->get_id(),
			$phone,
			'order_confirmation',
			$variables
		);
	}

	/**
	 * Send status update notification.
	 *
	 * @param \WC_Order $order     The WooCommerce order.
	 * @param string    $phone     Customer phone number.
	 * @param string    $newStatus The new order status.
	 * @return bool True on success.
	 */
	private function sendStatusUpdate( \WC_Order $order, string $phone, string $newStatus ): bool {
		$statusInfo = $this->getStatusInfo( $newStatus );

		$variables = [
			'order_number'  => $order->get_order_number(),
			'status_text'   => $statusInfo['text'],
			'status_emoji'  => $statusInfo['emoji'],
			'action_needed' => $statusInfo['action_needed'],
		];

		$templateName = $this->getStatusTemplate( $newStatus );

		return $this->sendTemplateMessage(
			$order->get_id(),
			$phone,
			$templateName,
			$variables
		);
	}

	/**
	 * Send shipping update notification.
	 *
	 * @param \WC_Order $order          The WooCommerce order.
	 * @param string    $phone          Customer phone number.
	 * @param string    $trackingNumber Tracking number.
	 * @param string    $carrier        Carrier name.
	 * @return bool True on success.
	 */
	private function sendShippingUpdate( \WC_Order $order, string $phone, string $trackingNumber, string $carrier ): bool {
		$variables = [
			'order_number'    => $order->get_order_number(),
			'carrier_name'    => $this->formatCarrierName( $carrier ),
			'tracking_number' => $trackingNumber,
			'tracking_url'    => $this->getTrackingUrl( $carrier, $trackingNumber ),
		];

		return $this->sendTemplateMessage(
			$order->get_id(),
			$phone,
			'shipping_update',
			$variables
		);
	}

	/**
	 * Send delivery confirmation notification.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param string    $phone Customer phone number.
	 * @return bool True on success.
	 */
	private function sendDeliveryConfirmation( \WC_Order $order, string $phone ): bool {
		$variables = [
			'order_number'  => $order->get_order_number(),
			'customer_name' => $order->get_billing_first_name(),
		];

		// Add review link if reviews are enabled.
		if ( post_type_supports( 'product', 'comments' ) ) {
			$variables['review_url'] = $this->getReviewUrl( $order );
		}

		return $this->sendTemplateMessage(
			$order->get_id(),
			$phone,
			'order_completed',
			$variables
		);
	}

	/**
	 * Send a template message via WhatsApp API.
	 *
	 * @param int    $orderId      Order ID for logging.
	 * @param string $phone        Customer phone number.
	 * @param string $templateName Template name.
	 * @param array  $variables    Template variables.
	 * @return bool True on success.
	 */
	private function sendTemplateMessage( int $orderId, string $phone, string $templateName, array $variables ): bool {
		try {
			$apiClient = wch( WhatsAppApiClient::class );
			$templateManager = wch( TemplateManager::class );

			// Render template to validate and track usage.
			$templateManager->renderTemplate( $templateName, $variables );

			$components = [
				[
					'type'       => 'body',
					'parameters' => array_map(
						static fn( $value ) => [ 'type' => 'text', 'text' => (string) $value ],
						array_values( $variables )
					),
				],
			];

			$result = $apiClient->sendTemplate( $phone, $templateName, 'en', $components );

			if ( is_wp_error( $result ) ) {
				$this->logError(
					'Template send failed',
					[
						'order_id' => $orderId,
						'template' => $templateName,
						'error'    => $result->get_error_message(),
					]
				);
				return false;
			}

			// Log successful notification.
			$this->logNotificationHistory( $orderId, $phone, $templateName, 'sent', $result );

			/**
			 * Fires after an order notification is sent successfully.
			 *
			 * @param int    $orderId      The order ID.
			 * @param string $phone        Customer phone number.
			 * @param string $templateName Template name used.
			 * @param array  $variables    Template variables.
			 * @param mixed  $result       API response.
			 */
			do_action( 'wch_order_notification_sent', $orderId, $phone, $templateName, $variables, $result );

			return true;
		} catch ( \Throwable $e ) {
			$this->logError(
				'Template send exception',
				[
					'order_id' => $orderId,
					'template' => $templateName,
					'error'    => $e->getMessage(),
				]
			);

			// Log failed notification.
			$this->logNotificationHistory( $orderId, $phone, $templateName, 'failed', $e->getMessage() );

			return false;
		}
	}

	/**
	 * Get a WooCommerce order by ID.
	 *
	 * @param int $orderId Order ID.
	 * @return \WC_Order|null The order or null.
	 */
	private function getOrder( int $orderId ): ?\WC_Order {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $orderId );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Get customer phone from order.
	 *
	 * @param \WC_Order $order The order.
	 * @return string The phone number or empty string.
	 */
	private function getCustomerPhone( \WC_Order $order ): string {
		// First check WCH-specific meta.
		$phone = $order->get_meta( '_wch_customer_phone' );
		if ( ! empty( $phone ) ) {
			return $phone;
		}

		// Fall back to billing phone.
		return $order->get_billing_phone() ?? '';
	}

	/**
	 * Check if notification can be sent to customer.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if notification can be sent.
	 */
	private function canSendNotification( string $phone ): bool {
		// Check customer opt-out.
		if ( $this->isCustomerOptedOut( $phone ) ) {
			return false;
		}

		// Check quiet hours.
		if ( $this->isQuietHours( $phone ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if customer has opted out of notifications.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if opted out.
	 */
	private function isCustomerOptedOut( string $phone ): bool {
		$tableName = $this->wpdb->prefix . 'wch_customer_profiles';

		$profile = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT notification_opt_out FROM {$tableName} WHERE phone = %s",
				$phone
			)
		);

		return $profile && ! empty( $profile->notification_opt_out );
	}

	/**
	 * Check if it's currently quiet hours for the customer's timezone.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if it's quiet hours.
	 */
	private function isQuietHours( string $phone ): bool {
		// Check if quiet hours are enabled.
		$quietHoursEnabled = get_option( 'wch_quiet_hours_enabled', false );
		if ( ! $quietHoursEnabled ) {
			return false;
		}

		$quietStart = get_option( 'wch_quiet_hours_start', '21:00' );
		$quietEnd   = get_option( 'wch_quiet_hours_end', '09:00' );

		// Get customer timezone if available.
		$timezone = $this->getCustomerTimezone( $phone );
		if ( ! $timezone ) {
			$timezone = wp_timezone();
		}

		try {
			$now         = new \DateTime( 'now', $timezone );
			$currentTime = $now->format( 'H:i' );

			// Handle overnight quiet hours (e.g., 21:00 to 09:00).
			if ( $quietStart > $quietEnd ) {
				return $currentTime >= $quietStart || $currentTime < $quietEnd;
			}

			return $currentTime >= $quietStart && $currentTime < $quietEnd;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get customer timezone from profile.
	 *
	 * @param string $phone Customer phone number.
	 * @return \DateTimeZone|null Customer timezone or null.
	 */
	private function getCustomerTimezone( string $phone ): ?\DateTimeZone {
		$tableName = $this->wpdb->prefix . 'wch_customer_profiles';

		$timezone = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT timezone FROM {$tableName} WHERE phone = %s",
				$phone
			)
		);

		if ( $timezone ) {
			try {
				return new \DateTimeZone( $timezone );
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Get estimated delivery date text.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Estimated delivery text.
	 */
	private function getEstimatedDelivery( \WC_Order $order ): string {
		// Check for stored estimate.
		$estimate = $order->get_meta( '_wch_estimated_delivery' );
		if ( ! empty( $estimate ) ) {
			return $estimate;
		}

		// Default to 3-5 business days.
		return __( '3-5 business days', 'whatsapp-commerce-hub' );
	}

	/**
	 * Get status information for display.
	 *
	 * @param string $status Order status.
	 * @return array Status info with text, emoji, and action_needed.
	 */
	private function getStatusInfo( string $status ): array {
		$statuses = [
			'processing' => [
				'text'          => __( 'Being prepared', 'whatsapp-commerce-hub' ),
				'emoji'         => '📦',
				'action_needed' => '',
			],
			'on-hold'    => [
				'text'          => __( 'On hold', 'whatsapp-commerce-hub' ),
				'emoji'         => '⏸️',
				'action_needed' => __( 'Please contact support', 'whatsapp-commerce-hub' ),
			],
			'shipped'    => [
				'text'          => __( 'Shipped', 'whatsapp-commerce-hub' ),
				'emoji'         => '🚚',
				'action_needed' => '',
			],
			'completed'  => [
				'text'          => __( 'Delivered', 'whatsapp-commerce-hub' ),
				'emoji'         => '✅',
				'action_needed' => '',
			],
			'cancelled'  => [
				'text'          => __( 'Cancelled', 'whatsapp-commerce-hub' ),
				'emoji'         => '❌',
				'action_needed' => __( 'Contact support for refund', 'whatsapp-commerce-hub' ),
			],
			'refunded'   => [
				'text'          => __( 'Refunded', 'whatsapp-commerce-hub' ),
				'emoji'         => '💰',
				'action_needed' => '',
			],
		];

		return $statuses[ $status ] ?? [
			'text'          => ucfirst( str_replace( '-', ' ', $status ) ),
			'emoji'         => '📋',
			'action_needed' => '',
		];
	}

	/**
	 * Get template name for status.
	 *
	 * @param string $status Order status.
	 * @return string Template name.
	 */
	private function getStatusTemplate( string $status ): string {
		$templates = [
			'processing' => 'order_processing',
			'on-hold'    => 'order_on_hold',
			'shipped'    => 'order_shipped',
			'completed'  => 'order_completed',
			'cancelled'  => 'order_cancelled',
			'refunded'   => 'order_refunded',
		];

		return $templates[ $status ] ?? 'order_status_update';
	}

	/**
	 * Format carrier name for display.
	 *
	 * @param string $carrier Carrier identifier.
	 * @return string Formatted carrier name.
	 */
	private function formatCarrierName( string $carrier ): string {
		$carriers = [
			'fedex'    => 'FedEx',
			'ups'      => 'UPS',
			'usps'     => 'USPS',
			'dhl'      => 'DHL',
			'aramex'   => 'Aramex',
			'bluedart' => 'Blue Dart',
			'dtdc'     => 'DTDC',
		];

		return $carriers[ strtolower( $carrier ) ] ?? ucfirst( $carrier );
	}

	/**
	 * Get tracking URL for carrier.
	 *
	 * @param string $carrier        Carrier identifier.
	 * @param string $trackingNumber Tracking number.
	 * @return string Tracking URL.
	 */
	private function getTrackingUrl( string $carrier, string $trackingNumber ): string {
		$urlPatterns = [
			'fedex'  => 'https://www.fedex.com/fedextrack/?trknbr=%s',
			'ups'    => 'https://www.ups.com/track?tracknum=%s',
			'usps'   => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
			'dhl'    => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
			'aramex' => 'https://www.aramex.com/track/shipments?ShipmentNumber=%s',
		];

		$carrier = strtolower( $carrier );
		if ( isset( $urlPatterns[ $carrier ] ) ) {
			return sprintf( $urlPatterns[ $carrier ], urlencode( $trackingNumber ) );
		}

		return '';
	}

	/**
	 * Get review URL for order.
	 *
	 * @param \WC_Order $order The order.
	 * @return string Review URL.
	 */
	private function getReviewUrl( \WC_Order $order ): string {
		// Get the first product from the order for review.
		$items     = $order->get_items();
		$firstItem = reset( $items );

		if ( $firstItem ) {
			$product = $firstItem->get_product();
			if ( $product ) {
				return get_permalink( $product->get_id() ) . '#reviews';
			}
		}

		return home_url();
	}

	/**
	 * Log notification to history table.
	 *
	 * @param int    $orderId      Order ID.
	 * @param string $phone        Customer phone.
	 * @param string $templateName Template name.
	 * @param string $status       Notification status.
	 * @param mixed  $response     API response or error.
	 * @return void
	 */
	private function logNotificationHistory( int $orderId, string $phone, string $templateName, string $status, $response ): void {
		$tableName = $this->wpdb->prefix . 'wch_notification_history';

		// Check if table exists.
		if ( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) !== $tableName ) {
			return;
		}

		$this->wpdb->insert(
			$tableName,
			[
				'order_id'       => $orderId,
				'customer_phone' => $phone,
				'template_name'  => $templateName,
				'status'         => $status,
				'response'       => is_string( $response ) ? $response : wp_json_encode( $response ),
				'created_at'     => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Mask phone number for logging.
	 *
	 * @param string $phone Phone number.
	 * @return string Masked phone number.
	 */
	private function maskPhone( string $phone ): string {
		if ( strlen( $phone ) < 6 ) {
			return '****';
		}

		return substr( $phone, 0, 3 ) . '****' . substr( $phone, -3 );
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
	 * Notifications should be retried on transient failures but not on
	 * validation errors or opt-out scenarios.
	 */
	public function shouldRetry( \Throwable $exception ): bool {
		// Don't retry validation errors.
		if ( $exception instanceof \InvalidArgumentException ) {
			return false;
		}

		// Don't retry if order not found.
		if ( str_contains( $exception->getMessage(), 'not found' ) ) {
			return false;
		}

		return parent::shouldRetry( $exception );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Notifications have higher retry count to ensure delivery.
	 */
	public function getMaxRetries(): int {
		return 5;
	}
}
