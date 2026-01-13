<?php
/**
 * Order Notifications Service
 *
 * Manages order lifecycle notifications via WhatsApp templates.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\Notifications;

use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Core\Logger;
use WhatsAppCommerceHub\Presentation\Templates\TemplateManager;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Notifications Service
 *
 * Handles sending WhatsApp notifications for order lifecycle events:
 * - Order confirmation
 * - Status updates
 * - Shipping updates
 * - Delivery confirmation
 */
class OrderNotifications {

	/**
	 * Notification types
	 */
	private const TYPE_CONFIRMATION  = 'order_confirmation';
	private const TYPE_STATUS_UPDATE = 'order_status_update';
	private const TYPE_SHIPPING      = 'shipping_update';
	private const TYPE_DELIVERY      = 'delivery_confirmation';

	/**
	 * Constructor
	 *
	 * @param SettingsManager   $settings Settings manager
	 * @param Logger            $logger Logger instance
	 * @param TemplateManager   $templateManager Template manager
	 * @param WhatsAppApiClient $apiClient WhatsApp API client
	 * @param JobDispatcher     $jobDispatcher Job dispatcher
	 */
	public function __construct(
		private readonly SettingsManager $settings,
		private readonly Logger $logger,
		private readonly TemplateManager $templateManager,
		private readonly WhatsAppApiClient $apiClient,
		private readonly JobDispatcher $jobDispatcher
	) {
	}

	/**
	 * Initialize WooCommerce hooks
	 */
	public function initHooks(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'handleStatusChange' ], 10, 3 );
		add_action( 'woocommerce_new_order', [ $this, 'handleNewOrder' ], 10, 1 );
		add_action( 'woocommerce_shipment_tracking_info_added', [ $this, 'handleShippingUpdate' ], 10, 3 );
	}

	/**
	 * Handle new order creation
	 *
	 * @param int $orderId Order ID
	 */
	public function handleNewOrder( int $orderId ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		if ( ! $this->isNotificationEnabled( self::TYPE_CONFIRMATION ) ) {
			return;
		}

		// Queue notification with 30 second delay to ensure order is fully processed
		$this->queueNotification(
			[
				'order_id' => $orderId,
				'type'     => self::TYPE_CONFIRMATION,
			],
			30
		);
	}

	/**
	 * Handle order status change
	 *
	 * @param int    $orderId Order ID
	 * @param string $oldStatus Old status
	 * @param string $newStatus New status
	 */
	public function handleStatusChange( int $orderId, string $oldStatus, string $newStatus ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		if ( ! $this->isNotificationEnabled( self::TYPE_STATUS_UPDATE ) ) {
			return;
		}

		// Only send notifications for certain status changes
		$notifyStatuses = [ 'processing', 'completed', 'cancelled', 'failed' ];

		if ( ! in_array( $newStatus, $notifyStatuses, true ) ) {
			return;
		}

		$this->sendStatusUpdate( $orderId, $newStatus );
	}

	/**
	 * Handle shipping update
	 *
	 * @param int    $orderId Order ID
	 * @param string $trackingNumber Tracking number
	 * @param string $carrier Carrier name
	 */
	public function handleShippingUpdate( int $orderId, string $trackingNumber, string $carrier ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		if ( ! $this->isNotificationEnabled( self::TYPE_SHIPPING ) ) {
			return;
		}

		$this->sendShippingUpdate( $orderId, $trackingNumber, $carrier );
	}

	/**
	 * Send order confirmation notification
	 *
	 * @param int $orderId Order ID
	 * @return bool True on success
	 */
	public function sendOrderConfirmation( int $orderId ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );

		if ( ! $customerPhone || ! $this->canSendNotification( $customerPhone ) ) {
			return false;
		}

		$variables = [
			'1' => $order->get_billing_first_name(),
			'2' => (string) $order->get_order_number(),
			'3' => $order->get_formatted_order_total(),
			'4' => $this->getEstimatedDelivery( $order ),
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			self::TYPE_CONFIRMATION,
			'order_confirmation',
			$variables
		);
	}

	/**
	 * Send status update notification
	 *
	 * @param int    $orderId Order ID
	 * @param string $newStatus New status
	 * @return bool True on success
	 */
	public function sendStatusUpdate( int $orderId, string $newStatus ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );

		if ( ! $customerPhone || ! $this->canSendNotification( $customerPhone ) ) {
			return false;
		}

		$statusLabel = wc_get_order_status_name( $newStatus );

		$variables = [
			'1' => $order->get_billing_first_name(),
			'2' => (string) $order->get_order_number(),
			'3' => $statusLabel,
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			self::TYPE_STATUS_UPDATE,
			'order_status_update',
			$variables
		);
	}

	/**
	 * Send shipping update notification
	 *
	 * @param int    $orderId Order ID
	 * @param string $trackingNumber Tracking number
	 * @param string $carrier Carrier name
	 * @return bool True on success
	 */
	public function sendShippingUpdate( int $orderId, string $trackingNumber, string $carrier ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );

		if ( ! $customerPhone || ! $this->canSendNotification( $customerPhone ) ) {
			return false;
		}

		$variables = [
			'1' => $order->get_billing_first_name(),
			'2' => (string) $order->get_order_number(),
			'3' => $carrier,
			'4' => $trackingNumber,
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			self::TYPE_SHIPPING,
			'shipping_update',
			$variables
		);
	}

	/**
	 * Send delivery confirmation notification
	 *
	 * @param int $orderId Order ID
	 * @return bool True on success
	 */
	public function sendDeliveryConfirmation( int $orderId ): bool {
		$order = wc_get_order( $orderId );

		if ( ! $order ) {
			return false;
		}

		$customerPhone = $this->getCustomerPhone( $order );

		if ( ! $customerPhone || ! $this->canSendNotification( $customerPhone ) ) {
			return false;
		}

		$variables = [
			'1' => $order->get_billing_first_name(),
			'2' => (string) $order->get_order_number(),
		];

		return $this->sendTemplateNotification(
			$orderId,
			$customerPhone,
			self::TYPE_DELIVERY,
			'delivery_confirmation',
			$variables
		);
	}

	/**
	 * Send template notification
	 *
	 * @param int                   $orderId Order ID
	 * @param string                $customerPhone Customer phone
	 * @param string                $notificationType Notification type
	 * @param string                $templateName Template name
	 * @param array<string, string> $variables Template variables
	 * @return bool True on success
	 */
	private function sendTemplateNotification(
		int $orderId,
		string $customerPhone,
		string $notificationType,
		string $templateName,
		array $variables
	): bool {
		try {
			// Create notification log
			$logId = $this->createNotificationLog( $orderId, $customerPhone, $notificationType, $templateName );

			// Render template
			$message = $this->templateManager->renderTemplate( $templateName, $variables );

			// Send via WhatsApp
			$result = $this->apiClient->sendMessage( $customerPhone, $message );

			// Update log
			$this->updateNotificationLog(
				$logId,
				[
					'status'     => 'sent',
					'message_id' => $result['id'] ?? null,
					'sent_at'    => current_time( 'mysql' ),
				]
			);

			$this->logger->info(
				'Order notification sent',
				[
					'order_id' => $orderId,
					'type'     => $notificationType,
					'phone'    => $customerPhone,
				]
			);

			return true;
		} catch ( \Exception $e ) {
			$this->logger->error(
				'Failed to send order notification',
				[
					'order_id' => $orderId,
					'type'     => $notificationType,
					'error'    => $e->getMessage(),
				]
			);

			if ( isset( $logId ) ) {
				$this->updateNotificationLog(
					$logId,
					[
						'status' => 'failed',
						'error'  => $e->getMessage(),
					]
				);
			}

			return false;
		}
	}

	/**
	 * Queue notification for later delivery
	 *
	 * @param array<string, mixed> $data Notification data
	 * @param int                  $delaySeconds Delay in seconds
	 */
	private function queueNotification( array $data, int $delaySeconds = 30 ): void {
		$this->jobDispatcher->dispatch( 'wch_send_order_notification', $data, $delaySeconds );
	}

	/**
	 * Create notification log
	 *
	 * @param int    $orderId Order ID
	 * @param string $customerPhone Customer phone
	 * @param string $notificationType Notification type
	 * @param string $templateName Template name
	 * @return int Log ID
	 */
	private function createNotificationLog(
		int $orderId,
		string $customerPhone,
		string $notificationType,
		string $templateName
	): int {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_notification_logs';

		$wpdb->insert(
			$tableName,
			[
				'order_id'          => $orderId,
				'customer_phone'    => $customerPhone,
				'notification_type' => $notificationType,
				'template_name'     => $templateName,
				'status'            => 'pending',
				'created_at'        => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update notification log
	 *
	 * @param int                  $logId Log ID
	 * @param array<string, mixed> $data Update data
	 */
	private function updateNotificationLog( int $logId, array $data ): void {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_notification_logs';

		$wpdb->update(
			$tableName,
			$data,
			[ 'id' => $logId ],
			null,
			[ '%d' ]
		);
	}

	/**
	 * Check if notification can be sent
	 *
	 * @param string $customerPhone Customer phone
	 * @return bool True if can send
	 */
	private function canSendNotification( string $customerPhone ): bool {
		// Check opt-out status
		if ( $this->isCustomerOptedOut( $customerPhone ) ) {
			return false;
		}

		// Check quiet hours
		if ( $this->isQuietHours( $customerPhone ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if customer has opted out
	 *
	 * @param string $customerPhone Customer phone
	 * @return bool True if opted out
	 */
	private function isCustomerOptedOut( string $customerPhone ): bool {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_customer_profiles';

		$optOut = $wpdb->get_var(
			$wpdb->prepare( "SELECT notification_opt_out FROM {$tableName} WHERE phone = %s", $customerPhone )
		);

		return (int) $optOut === 1;
	}

	/**
	 * Check if it's quiet hours
	 *
	 * @param string $customerPhone Customer phone (for timezone detection)
	 * @return bool True if quiet hours
	 */
	private function isQuietHours( string $customerPhone ): bool {
		$quietHoursEnabled = (bool) $this->settings->get( 'notifications.quiet_hours_enabled', false );

		if ( ! $quietHoursEnabled ) {
			return false;
		}

		$hour      = (int) current_time( 'H' );
		$startHour = (int) $this->settings->get( 'notifications.quiet_hours_start', 22 );
		$endHour   = (int) $this->settings->get( 'notifications.quiet_hours_end', 8 );

		if ( $startHour < $endHour ) {
			return $hour >= $startHour && $hour < $endHour;
		}

		return $hour >= $startHour || $hour < $endHour;
	}

	/**
	 * Check if notification type is enabled
	 *
	 * @param string $type Notification type
	 * @return bool True if enabled
	 */
	private function isNotificationEnabled( string $type ): bool {
		$configKey = "notifications.{$type}_enabled";
		return (bool) $this->settings->get( $configKey, true );
	}

	/**
	 * Get customer phone from order
	 *
	 * @param WC_Order $order Order object
	 * @return string|null Customer phone or null
	 */
	private function getCustomerPhone( WC_Order $order ): ?string {
		$phone = $order->get_meta( '_wch_customer_phone' );
		return $phone ?: null;
	}

	/**
	 * Get estimated delivery date
	 *
	 * @param WC_Order $order Order object
	 * @return string Estimated delivery date
	 */
	private function getEstimatedDelivery( WC_Order $order ): string {
		$estimatedDays = (int) $this->settings->get( 'shipping.estimated_delivery_days', 5 );
		$deliveryDate  = strtotime( "+{$estimatedDays} days" );

		return gmdate( 'F j, Y', $deliveryDate );
	}

	/**
	 * Get notification history for an order
	 *
	 * @param int $orderId Order ID
	 * @return array<int, array<string, mixed>> Notification history
	 */
	public function getNotificationHistory( int $orderId ): array {
		global $wpdb;
		$tableName = $wpdb->prefix . 'wch_notification_logs';

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tableName} WHERE order_id = %d ORDER BY created_at DESC",
				$orderId
			),
			ARRAY_A
		);

		return $logs ?: [];
	}
}
