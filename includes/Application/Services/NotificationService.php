<?php
/**
 * Notification Service
 *
 * Manages order lifecycle notifications via WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationService
 *
 * Handles sending WhatsApp notifications for order lifecycle events.
 */
class NotificationService {
	/**
	 * Maximum retry attempts.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Retry delay in seconds.
	 */
	private const RETRY_DELAY = 300;

	/**
	 * Quiet hours start (24-hour format).
	 */
	private const QUIET_HOURS_START = 22;

	/**
	 * Quiet hours end (24-hour format).
	 */
	private const QUIET_HOURS_END = 8;

	/**
	 * WhatsApp API client.
	 *
	 * @var \WCH_WhatsApp_API_Client
	 */
	private \WCH_WhatsApp_API_Client $apiClient;

	/**
	 * Template manager.
	 *
	 * @var \WCH_Template_Manager|null
	 */
	private ?\WCH_Template_Manager $templateManager;

	/**
	 * Status to notification info mapping.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $statusMap = [
		'pending'    => [
			'text'          => 'Payment Pending',
			'emoji'         => '',
			'action_needed' => 'Please complete payment to process your order.',
			'template'      => 'order_status_update',
		],
		'processing' => [
			'text'          => 'Processing',
			'emoji'         => '',
			'action_needed' => 'We are preparing your order.',
			'template'      => 'order_processing',
		],
		'on-hold'    => [
			'text'          => 'On Hold',
			'emoji'         => '',
			'action_needed' => 'Your order is on hold. We will contact you shortly.',
			'template'      => 'order_status_update',
		],
		'completed'  => [
			'text'          => 'Delivered',
			'emoji'         => '',
			'action_needed' => 'Thank you for your order!',
			'template'      => 'order_completed',
		],
		'cancelled'  => [
			'text'          => 'Cancelled',
			'emoji'         => '',
			'action_needed' => 'Your order has been cancelled.',
			'template'      => 'order_cancelled',
		],
		'refunded'   => [
			'text'          => 'Refunded',
			'emoji'         => '',
			'action_needed' => 'Your refund has been processed.',
			'template'      => 'order_refunded',
		],
		'failed'     => [
			'text'          => 'Failed',
			'emoji'         => '',
			'action_needed' => 'Payment failed. Please try again.',
			'template'      => 'order_status_update',
		],
	];

	/**
	 * Carrier tracking URLs.
	 *
	 * @var array<string, string>
	 */
	private array $carrierUrls = [
		'ups'   => 'https://www.ups.com/track?tracknum=%s',
		'usps'  => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
		'fedex' => 'https://www.fedex.com/fedextrack/?tracknumbers=%s',
		'dhl'   => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
	];

	/**
	 * Constructor.
	 *
	 * @param \WCH_WhatsApp_API_Client|null $apiClient       WhatsApp API client.
	 * @param \WCH_Template_Manager|null    $templateManager Template manager.
	 */
	public function __construct(
		?\WCH_WhatsApp_API_Client $apiClient = null,
		?\WCH_Template_Manager $templateManager = null
	) {
		$this->apiClient       = $apiClient ?? new \WCH_WhatsApp_API_Client();
		$this->templateManager = $templateManager ?? ( class_exists( 'WCH_Template_Manager' ) ? \WCH_Template_Manager::getInstance() : null );
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'handleStatusChange' ], 10, 3 );
		add_action( 'woocommerce_new_order', [ $this, 'handleNewOrder' ], 10, 1 );
		add_action( 'woocommerce_shipment_tracking_info_added', [ $this, 'handleShippingUpdate' ], 10, 3 );
		add_action( 'add_meta_boxes', [ $this, 'addNotificationMetabox' ] );
		add_action( 'wp_ajax_wch_get_notification_history', [ $this, 'ajaxGetNotificationHistory' ] );
	}

	/**
	 * Handle new order creation.
	 *
	 * @param int $orderId Order ID.
	 * @return void
	 */
	public function handleNewOrder( int $orderId ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		if ( ! $this->isNotificationEnabled( 'order_confirmation' ) ) {
			return;
		}

		$this->queueNotification(
			[
				'order_id'          => $orderId,
				'notification_type' => 'order_confirmation',
			],
			30
		);
	}

	/**
	 * Handle order status changes.
	 *
	 * @param int    $orderId   Order ID.
	 * @param string $oldStatus Old status.
	 * @param string $newStatus New status.
	 * @return void
	 */
	public function handleStatusChange( int $orderId, string $oldStatus, string $newStatus ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		if ( ! $this->isNotificationEnabled( 'status_updates' ) ) {
			return;
		}

		$this->queueNotification(
			[
				'order_id'          => $orderId,
				'notification_type' => 'status_update',
				'old_status'        => $oldStatus,
				'new_status'        => $newStatus,
			],
			30
		);
	}

	/**
	 * Handle shipping tracking addition.
	 *
	 * @param int    $orderId        Order ID.
	 * @param string $trackingNumber Tracking number.
	 * @param string $carrier        Carrier name.
	 * @return void
	 */
	public function handleShippingUpdate( int $orderId, string $trackingNumber, string $carrier ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		if ( ! $this->isNotificationEnabled( 'shipping' ) ) {
			return;
		}

		$this->queueNotification(
			[
				'order_id'          => $orderId,
				'notification_type' => 'shipping_update',
				'tracking_number'   => $trackingNumber,
				'carrier'           => $carrier,
			],
			30
		);
	}

	/**
	 * Send order confirmation notification.
	 *
	 * @param int $orderId Order ID.
	 * @return bool Success status.
	 */
	public function sendOrderConfirmation( int $orderId ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			$this->log( "Order confirmation failed: Order {$orderId} not found", [], 'error' );
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );
		if ( ! $customerPhone ) {
			$this->log( "Order confirmation failed: No phone for order {$orderId}", [], 'error' );
			return false;
		}

		if ( ! $this->canSendNotification( $customerPhone ) ) {
			$this->log( "Order confirmation skipped: Opt-out or quiet hours for order {$orderId}" );
			return false;
		}

		$variables = [
			'customer_name'      => $order->get_billing_first_name(),
			'order_number'       => $order->get_order_number(),
			'order_total'        => $order->get_formatted_order_total(),
			'item_count'         => (string) $order->get_item_count(),
			'estimated_delivery' => $this->getEstimatedDelivery( $order ),
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			'order_confirmation',
			'order_confirmation',
			$variables
		);
	}

	/**
	 * Send status update notification.
	 *
	 * @param int    $orderId   Order ID.
	 * @param string $newStatus New status.
	 * @return bool Success status.
	 */
	public function sendStatusUpdate( int $orderId, string $newStatus ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			$this->log( "Status update failed: Order {$orderId} not found", [], 'error' );
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );
		if ( ! $customerPhone ) {
			$this->log( "Status update failed: No phone for order {$orderId}", [], 'error' );
			return false;
		}

		if ( ! $this->canSendNotification( $customerPhone ) ) {
			$this->log( "Status update skipped: Opt-out or quiet hours for order {$orderId}" );
			return false;
		}

		$statusInfo = $this->getStatusInfo( $newStatus );

		$variables = [
			'order_number'  => $order->get_order_number(),
			'status_text'   => $statusInfo['text'],
			'status_emoji'  => $statusInfo['emoji'],
			'action_needed' => $statusInfo['action_needed'],
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			'status_update',
			$statusInfo['template'],
			$variables
		);
	}

	/**
	 * Send shipping update notification.
	 *
	 * @param int    $orderId        Order ID.
	 * @param string $trackingNumber Tracking number.
	 * @param string $carrier        Carrier name.
	 * @return bool Success status.
	 */
	public function sendShippingUpdate( int $orderId, string $trackingNumber, string $carrier ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			$this->log( "Shipping update failed: Order {$orderId} not found", [], 'error' );
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );
		if ( ! $customerPhone ) {
			$this->log( "Shipping update failed: No phone for order {$orderId}", [], 'error' );
			return false;
		}

		if ( ! $this->canSendNotification( $customerPhone ) ) {
			$this->log( "Shipping update skipped: Opt-out or quiet hours for order {$orderId}" );
			return false;
		}

		$variables = [
			'order_number'    => $order->get_order_number(),
			'carrier_name'    => $this->formatCarrierName( $carrier ),
			'tracking_number' => $trackingNumber,
			'tracking_url'    => $this->getTrackingUrl( $carrier, $trackingNumber ),
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			'shipping_update',
			'shipping_update',
			$variables
		);
	}

	/**
	 * Send delivery confirmation notification.
	 *
	 * @param int $orderId Order ID.
	 * @return bool Success status.
	 */
	public function sendDeliveryConfirmation( int $orderId ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			$this->log( "Delivery confirmation failed: Order {$orderId} not found", [], 'error' );
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );
		if ( ! $customerPhone ) {
			$this->log( "Delivery confirmation failed: No phone for order {$orderId}", [], 'error' );
			return false;
		}

		if ( ! $this->canSendNotification( $customerPhone ) ) {
			$this->log( "Delivery confirmation skipped: Opt-out or quiet hours for order {$orderId}" );
			return false;
		}

		$variables = [
			'order_number'  => $order->get_order_number(),
			'customer_name' => $order->get_billing_first_name(),
		];

		if ( post_type_supports( 'product', 'comments' ) ) {
			$variables['review_url'] = get_permalink( $order->get_id() );
		}

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			'delivery_confirmation',
			'order_completed',
			$variables
		);
	}

	/**
	 * Process notification job (called by queue).
	 *
	 * @param array $args Job arguments.
	 * @return void
	 */
	public function processNotificationJob( array $args ): void {
		$orderId          = $args['order_id'] ?? 0;
		$notificationType = $args['notification_type'] ?? '';

		if ( ! $orderId || ! $notificationType ) {
			$this->log( 'Invalid notification job args: ' . wp_json_encode( $args ), [], 'error' );
			return;
		}

		switch ( $notificationType ) {
			case 'order_confirmation':
				$this->sendOrderConfirmation( $orderId );
				break;

			case 'status_update':
				$newStatus = $args['new_status'] ?? '';
				if ( $newStatus ) {
					$this->sendStatusUpdate( $orderId, $newStatus );
				}
				break;

			case 'shipping_update':
				$trackingNumber = $args['tracking_number'] ?? '';
				$carrier        = $args['carrier'] ?? '';
				if ( $trackingNumber && $carrier ) {
					$this->sendShippingUpdate( $orderId, $trackingNumber, $carrier );
				}
				break;

			case 'delivery_confirmation':
				$this->sendDeliveryConfirmation( $orderId );
				break;

			default:
				$this->log( "Unknown notification type: {$notificationType}", [], 'error' );
		}
	}

	/**
	 * Send template notification.
	 *
	 * @param int    $orderId          Order ID.
	 * @param string $customerPhone    Customer phone.
	 * @param string $notificationType Notification type.
	 * @param string $templateName     Template name.
	 * @param array  $variables        Template variables.
	 * @return bool Success status.
	 */
	private function sendTemplateNotification(
		int $orderId,
		string $customerPhone,
		string $notificationType,
		string $templateName,
		array $variables
	): bool {
		$logId = $this->createNotificationLog( $orderId, $customerPhone, $notificationType, $templateName );

		try {
			// Render template if template manager is available.
			if ( $this->templateManager ) {
				$rendered = $this->templateManager->render_template( $templateName, $variables );
				if ( ! $rendered ) {
					throw new \Exception( "Template {$templateName} not found or failed to render" );
				}
			}

			// Send message.
			$result = $this->apiClient->send_template_message(
				$customerPhone,
				$templateName,
				array_values( $variables )
			);

			if ( ! $result || ! isset( $result['messages'][0]['id'] ) ) {
				throw new \Exception( 'Failed to send WhatsApp message' );
			}

			$this->updateNotificationLog(
				$logId,
				[
					'status'        => 'sent',
					'wa_message_id' => $result['messages'][0]['id'],
					'sent_at'       => current_time( 'mysql' ),
				]
			);

			$this->log( "Notification sent for order {$orderId}: {$notificationType}" );
			return true;

		} catch ( \Exception $e ) {
			$this->updateNotificationLog(
				$logId,
				[
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
				]
			);

			$this->log( "Notification failed for order {$orderId}: {$e->getMessage()}", [], 'error' );
			$this->scheduleRetry( $logId, $orderId, $notificationType );

			return false;
		}
	}

	/**
	 * Queue notification for later sending.
	 *
	 * @param array $data         Notification data.
	 * @param int   $delaySeconds Delay in seconds.
	 * @return void
	 */
	private function queueNotification( array $data, int $delaySeconds = 30 ): void {
		if ( class_exists( 'WCH_Job_Dispatcher' ) ) {
			\WCH_Job_Dispatcher::dispatch(
				'wch_send_order_notification',
				$data,
				$delaySeconds
			);
		}
	}

	/**
	 * Schedule retry for failed notification.
	 *
	 * @param int    $logId            Log ID.
	 * @param int    $orderId          Order ID.
	 * @param string $notificationType Notification type.
	 * @return void
	 */
	private function scheduleRetry( int $logId, int $orderId, string $notificationType ): void {
		global $wpdb;

		$tableName = $this->getTableName( 'notification_log' );

		$log = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT retry_count FROM {$tableName} WHERE id = %d",
				$logId
			)
		);

		if ( ! $log ) {
			return;
		}

		$retryCount = (int) $log->retry_count;

		if ( $retryCount >= self::MAX_RETRIES ) {
			$this->log( "Max retries reached for notification log {$logId}", [], 'error' );
			return;
		}

		$wpdb->update(
			$tableName,
			[ 'retry_count' => $retryCount + 1 ],
			[ 'id' => $logId ],
			[ '%d' ],
			[ '%d' ]
		);

		$this->queueNotification(
			[
				'order_id'          => $orderId,
				'notification_type' => $notificationType,
			],
			self::RETRY_DELAY
		);

		$this->log( 'Scheduled retry ' . ( $retryCount + 1 ) . " for notification log {$logId}" );
	}

	/**
	 * Create notification log entry.
	 *
	 * @param int    $orderId          Order ID.
	 * @param string $customerPhone    Customer phone.
	 * @param string $notificationType Notification type.
	 * @param string $templateName     Template name.
	 * @return int Log ID.
	 */
	private function createNotificationLog(
		int $orderId,
		string $customerPhone,
		string $notificationType,
		string $templateName
	): int {
		global $wpdb;

		$tableName = $this->getTableName( 'notification_log' );

		$wpdb->insert(
			$tableName,
			[
				'order_id'          => $orderId,
				'notification_type' => $notificationType,
				'customer_phone'    => $customerPhone,
				'template_name'     => $templateName,
				'status'            => 'queued',
				'retry_count'       => 0,
				'created_at'        => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return $wpdb->insert_id;
	}

	/**
	 * Update notification log.
	 *
	 * @param int   $logId Log ID.
	 * @param array $data  Data to update.
	 * @return void
	 */
	private function updateNotificationLog( int $logId, array $data ): void {
		global $wpdb;

		$tableName = $this->getTableName( 'notification_log' );

		$data['updated_at'] = current_time( 'mysql' );

		$wpdb->update(
			$tableName,
			$data,
			[ 'id' => $logId ],
			null,
			[ '%d' ]
		);
	}

	/**
	 * Check if notification can be sent.
	 *
	 * @param string $customerPhone Customer phone.
	 * @return bool
	 */
	private function canSendNotification( string $customerPhone ): bool {
		if ( $this->isCustomerOptedOut( $customerPhone ) ) {
			return false;
		}

		if ( $this->isQuietHours( $customerPhone ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if customer has opted out.
	 *
	 * @param string $customerPhone Customer phone.
	 * @return bool
	 */
	private function isCustomerOptedOut( string $customerPhone ): bool {
		global $wpdb;

		$tableName = $this->getTableName( 'customer_profiles' );

		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT notification_opt_out FROM {$tableName} WHERE phone = %s",
				$customerPhone
			)
		);

		return $profile && ! empty( $profile->notification_opt_out );
	}

	/**
	 * Check if it's quiet hours.
	 *
	 * @param string $customerPhone Customer phone.
	 * @return bool
	 */
	private function isQuietHours( string $customerPhone ): bool {
		$timezoneString = get_option( 'timezone_string', 'UTC' );

		try {
			$timezone = new \DateTimeZone( $timezoneString );
			$datetime = new \DateTime( 'now', $timezone );
			$hour     = (int) $datetime->format( 'G' );

			return $hour >= self::QUIET_HOURS_START || $hour < self::QUIET_HOURS_END;

		} catch ( \Exception $e ) {
			$this->log( "Timezone error: {$e->getMessage()}", [], 'error' );
			return false;
		}
	}

	/**
	 * Check if notification type is enabled.
	 *
	 * @param string $type Notification type.
	 * @return bool
	 */
	private function isNotificationEnabled( string $type ): bool {
		if ( class_exists( 'WCH_Settings' ) ) {
			$settings = \WCH_Settings::getInstance();
			return (bool) $settings->get( "notifications.{$type}_enabled", true );
		}
		return true;
	}

	/**
	 * Get customer phone from order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return string|null
	 */
	private function getCustomerPhone( \WC_Order $order ): ?string {
		$phone = $order->get_meta( '_wch_customer_phone' );

		if ( ! $phone ) {
			$phone = $order->get_billing_phone();
		}

		return $phone ?: null;
	}

	/**
	 * Get estimated delivery date.
	 *
	 * @param \WC_Order $order Order object.
	 * @return string
	 */
	private function getEstimatedDelivery( \WC_Order $order ): string {
		$customDelivery = $order->get_meta( '_estimated_delivery_date' );
		if ( $customDelivery ) {
			return $customDelivery;
		}

		return '3-5 business days';
	}

	/**
	 * Get status information.
	 *
	 * @param string $status Order status.
	 * @return array Status info.
	 */
	private function getStatusInfo( string $status ): array {
		return $this->statusMap[ $status ] ?? [
			'text'          => ucfirst( str_replace( '-', ' ', $status ) ),
			'emoji'         => '',
			'action_needed' => '',
			'template'      => 'order_status_update',
		];
	}

	/**
	 * Get tracking URL for carrier.
	 *
	 * @param string $carrier        Carrier name.
	 * @param string $trackingNumber Tracking number.
	 * @return string
	 */
	private function getTrackingUrl( string $carrier, string $trackingNumber ): string {
		$carrierKey = strtolower( $carrier );
		$urlPattern = $this->carrierUrls[ $carrierKey ] ?? '#';

		return sprintf( $urlPattern, rawurlencode( $trackingNumber ) );
	}

	/**
	 * Format carrier name.
	 *
	 * @param string $carrier Carrier slug.
	 * @return string
	 */
	private function formatCarrierName( string $carrier ): string {
		$names = [
			'ups'   => 'UPS',
			'usps'  => 'USPS',
			'fedex' => 'FedEx',
			'dhl'   => 'DHL',
		];

		return $names[ strtolower( $carrier ) ] ?? ucfirst( $carrier );
	}

	/**
	 * Get notification history for order.
	 *
	 * @param int $orderId Order ID.
	 * @return array Notifications.
	 */
	public function getNotificationHistory( int $orderId ): array {
		global $wpdb;

		$tableName = $this->getTableName( 'notification_log' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tableName} WHERE order_id = %d ORDER BY created_at DESC",
				$orderId
			)
		);
	}

	/**
	 * Add notification metabox to order page.
	 *
	 * @return void
	 */
	public function addNotificationMetabox(): void {
		add_meta_box(
			'wch_order_notifications',
			__( 'WhatsApp Notifications', 'whatsapp-commerce-hub' ),
			[ $this, 'renderNotificationMetabox' ],
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Render notification metabox.
	 *
	 * @param \WP_Post $post Order post.
	 * @return void
	 */
	public function renderNotificationMetabox( \WP_Post $post ): void {
		$orderId       = $post->ID;
		$notifications = $this->getNotificationHistory( $orderId );

		if ( empty( $notifications ) ) {
			echo '<p>' . esc_html__( 'No notifications sent yet.', 'whatsapp-commerce-hub' ) . '</p>';
			return;
		}

		$this->renderNotificationStyles();
		$this->renderNotificationItems( $notifications );
	}

	/**
	 * Render notification styles.
	 *
	 * @return void
	 */
	private function renderNotificationStyles(): void {
		?>
		<style>
			.wch-notification-history { font-size: 12px; }
			.wch-notification-item { padding: 8px; border-bottom: 1px solid #ddd; }
			.wch-notification-item:last-child { border-bottom: none; }
			.wch-notification-type { font-weight: bold; }
			.wch-notification-status { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; }
			.wch-notification-status.sent { background: #c6e1c6; color: #0a5d0a; }
			.wch-notification-status.delivered { background: #b3d9ff; color: #004085; }
			.wch-notification-status.read { background: #d4edda; color: #155724; }
			.wch-notification-status.failed { background: #f8d7da; color: #721c24; }
			.wch-notification-status.queued { background: #fff3cd; color: #856404; }
		</style>
		<?php
	}

	/**
	 * Render notification items.
	 *
	 * @param array $notifications Notifications.
	 * @return void
	 */
	private function renderNotificationItems( array $notifications ): void {
		echo '<div class="wch-notification-history">';

		foreach ( $notifications as $notification ) {
			$statusClass = esc_attr( $notification->status );
			$statusText  = ucfirst( $notification->status );
			$typeText    = ucfirst( str_replace( '_', ' ', $notification->notification_type ) );
			$date        = mysql2date( 'M j, Y g:i A', $notification->created_at );

			echo '<div class="wch-notification-item">';
			echo '<div class="wch-notification-type">' . esc_html( $typeText ) . '</div>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $statusClass is escaped above.
			echo '<div><span class="wch-notification-status ' . $statusClass . '">' . esc_html( $statusText ) . '</span></div>';
			echo '<div style="color: #666; font-size: 11px;">' . esc_html( $date ) . '</div>';

			if ( 'failed' === $notification->status && ! empty( $notification->error_message ) ) {
				echo '<div style="color: #721c24; font-size: 11px; margin-top: 4px;">Error: ' . esc_html( $notification->error_message ) . '</div>';
			}

			if ( $notification->retry_count > 0 ) {
				echo '<div style="color: #856404; font-size: 11px;">Retries: ' . esc_html( $notification->retry_count ) . '</div>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * AJAX handler for getting notification history.
	 *
	 * @return void
	 */
	public function ajaxGetNotificationHistory(): void {
		check_ajax_referer( 'wch-admin', 'nonce' );

		$orderId = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $orderId ) {
			wp_send_json_error( [ 'message' => 'Invalid order ID' ] );
		}

		$notifications = $this->getNotificationHistory( $orderId );
		wp_send_json_success( [ 'notifications' => $notifications ] );
	}

	/**
	 * Get table name with prefix.
	 *
	 * @param string $table Table name without prefix.
	 * @return string
	 */
	private function getTableName( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . 'wch_' . $table;
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context.
	 * @param string $level   Log level.
	 * @return void
	 */
	private function log( string $message, array $context = [], string $level = 'info' ): void {
		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::{ $level }( $message, $context );
		}
	}
}
