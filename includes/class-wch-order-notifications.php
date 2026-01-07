<?php
/**
 * Order Notifications Handler
 *
 * Manages order lifecycle notifications via WhatsApp templates
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCH_Order_Notifications
 *
 * Handles sending WhatsApp notifications for order lifecycle events:
 * - Order confirmation
 * - Status updates
 * - Shipping updates
 * - Delivery confirmation
 */
class WCH_Order_Notifications {

	/**
	 * Singleton instance
	 *
	 * @var WCH_Order_Notifications|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return WCH_Order_Notifications
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WooCommerce hooks
	 */
	private function init_hooks() {
		// Order status changes
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 10, 3 );

		// New orders (confirmation)
		add_action( 'woocommerce_new_order', array( $this, 'handle_new_order' ), 10, 1 );

		// Shipping tracking (if available)
		add_action( 'woocommerce_shipment_tracking_info_added', array( $this, 'handle_shipping_update' ), 10, 3 );

		// Admin metabox
		add_action( 'add_meta_boxes', array( $this, 'add_notification_metabox' ) );

		// AJAX handlers
		add_action( 'wp_ajax_wch_get_notification_history', array( $this, 'ajax_get_notification_history' ) );
	}

	/**
	 * Handle new order creation
	 *
	 * @param int $order_id Order ID
	 */
	public function handle_new_order( $order_id ) {
		$order = wc_get_order( $order_id );

		// Only process WhatsApp orders
		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		// Check if confirmation notifications are enabled
		if ( ! $this->is_notification_enabled( 'order_confirmation' ) ) {
			return;
		}

		// Queue confirmation notification with 30-second delay
		$this->queue_notification(
			array(
				'order_id'          => $order_id,
				'notification_type' => 'order_confirmation',
			),
			30
		);
	}

	/**
	 * Handle order status changes
	 *
	 * @param int    $order_id   Order ID
	 * @param string $old_status Old status
	 * @param string $new_status New status
	 */
	public function handle_status_change( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );

		// Only process WhatsApp orders
		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		// Check if status notifications are enabled
		if ( ! $this->is_notification_enabled( 'status_updates' ) ) {
			return;
		}

		// Queue status notification with 30-second delay
		$this->queue_notification(
			array(
				'order_id'          => $order_id,
				'notification_type' => 'status_update',
				'old_status'        => $old_status,
				'new_status'        => $new_status,
			),
			30
		);
	}

	/**
	 * Handle shipping tracking addition
	 *
	 * @param int    $order_id        Order ID
	 * @param string $tracking_number Tracking number
	 * @param string $carrier         Carrier name
	 */
	public function handle_shipping_update( $order_id, $tracking_number, $carrier ) {
		$order = wc_get_order( $order_id );

		// Only process WhatsApp orders
		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		// Check if shipping notifications are enabled
		if ( ! $this->is_notification_enabled( 'shipping' ) ) {
			return;
		}

		// Queue shipping notification
		$this->queue_notification(
			array(
				'order_id'          => $order_id,
				'notification_type' => 'shipping_update',
				'tracking_number'   => $tracking_number,
				'carrier'           => $carrier,
			),
			30
		);
	}

	/**
	 * Send order confirmation notification
	 *
	 * @param int $order_id Order ID
	 * @return bool Success status
	 */
	public function send_order_confirmation( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			WCH_Logger::error( "Order confirmation failed: Order {$order_id} not found" );
			return false;
		}

		// Get customer phone
		$customer_phone = $this->get_customer_phone( $order );
		if ( ! $customer_phone ) {
			WCH_Logger::error( "Order confirmation failed: No phone for order {$order_id}" );
			return false;
		}

		// Check opt-out and quiet hours
		if ( ! $this->can_send_notification( $customer_phone ) ) {
			WCH_Logger::info( "Order confirmation skipped: Customer opt-out or quiet hours for order {$order_id}" );
			return false;
		}

		// Build template variables
		$variables = array(
			'customer_name'      => $order->get_billing_first_name(),
			'order_number'       => $order->get_order_number(),
			'order_total'        => $order->get_formatted_order_total(),
			'item_count'         => $order->get_item_count(),
			'estimated_delivery' => $this->get_estimated_delivery( $order ),
		);

		// Send notification
		return $this->send_template_notification(
			$order_id,
			$customer_phone,
			'order_confirmation',
			'order_confirmation',
			$variables
		);
	}

	/**
	 * Send status update notification
	 *
	 * @param int    $order_id   Order ID
	 * @param string $new_status New status
	 * @return bool Success status
	 */
	public function send_status_update( $order_id, $new_status ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			WCH_Logger::error( "Status update failed: Order {$order_id} not found" );
			return false;
		}

		// Get customer phone
		$customer_phone = $this->get_customer_phone( $order );
		if ( ! $customer_phone ) {
			WCH_Logger::error( "Status update failed: No phone for order {$order_id}" );
			return false;
		}

		// Check opt-out and quiet hours
		if ( ! $this->can_send_notification( $customer_phone ) ) {
			WCH_Logger::info( "Status update skipped: Customer opt-out or quiet hours for order {$order_id}" );
			return false;
		}

		// Map status to customer-friendly text and emoji
		$status_info = $this->get_status_info( $new_status );

		// Build template variables
		$variables = array(
			'order_number'  => $order->get_order_number(),
			'status_text'   => $status_info['text'],
			'status_emoji'  => $status_info['emoji'],
			'action_needed' => $status_info['action_needed'],
		);

		// Get template name based on status
		$template_name = $this->get_status_template( $new_status );

		// Send notification
		return $this->send_template_notification(
			$order_id,
			$customer_phone,
			'status_update',
			$template_name,
			$variables
		);
	}

	/**
	 * Send shipping update notification
	 *
	 * @param int    $order_id        Order ID
	 * @param string $tracking_number Tracking number
	 * @param string $carrier         Carrier name
	 * @return bool Success status
	 */
	public function send_shipping_update( $order_id, $tracking_number, $carrier ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			WCH_Logger::error( "Shipping update failed: Order {$order_id} not found" );
			return false;
		}

		// Get customer phone
		$customer_phone = $this->get_customer_phone( $order );
		if ( ! $customer_phone ) {
			WCH_Logger::error( "Shipping update failed: No phone for order {$order_id}" );
			return false;
		}

		// Check opt-out and quiet hours
		if ( ! $this->can_send_notification( $customer_phone ) ) {
			WCH_Logger::info( "Shipping update skipped: Customer opt-out or quiet hours for order {$order_id}" );
			return false;
		}

		// Build tracking URL
		$tracking_url = $this->get_tracking_url( $carrier, $tracking_number );

		// Build template variables
		$variables = array(
			'order_number'    => $order->get_order_number(),
			'carrier_name'    => $this->format_carrier_name( $carrier ),
			'tracking_number' => $tracking_number,
			'tracking_url'    => $tracking_url,
		);

		// Send notification
		return $this->send_template_notification(
			$order_id,
			$customer_phone,
			'shipping_update',
			'shipping_update',
			$variables
		);
	}

	/**
	 * Send delivery confirmation notification
	 *
	 * @param int $order_id Order ID
	 * @return bool Success status
	 */
	public function send_delivery_confirmation( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			WCH_Logger::error( "Delivery confirmation failed: Order {$order_id} not found" );
			return false;
		}

		// Get customer phone
		$customer_phone = $this->get_customer_phone( $order );
		if ( ! $customer_phone ) {
			WCH_Logger::error( "Delivery confirmation failed: No phone for order {$order_id}" );
			return false;
		}

		// Check opt-out and quiet hours
		if ( ! $this->can_send_notification( $customer_phone ) ) {
			WCH_Logger::info( "Delivery confirmation skipped: Customer opt-out or quiet hours for order {$order_id}" );
			return false;
		}

		// Build template variables
		$variables = array(
			'order_number'  => $order->get_order_number(),
			'customer_name' => $order->get_billing_first_name(),
		);

		// Add review link if reviews are enabled
		if ( post_type_supports( 'product', 'comments' ) ) {
			$variables['review_url'] = get_permalink( $order->get_id() );
		}

		// Send notification
		return $this->send_template_notification(
			$order_id,
			$customer_phone,
			'delivery_confirmation',
			'order_completed',
			$variables
		);
	}

	/**
	 * Send template notification
	 *
	 * @param int    $order_id          Order ID
	 * @param string $customer_phone    Customer phone
	 * @param string $notification_type Notification type
	 * @param string $template_name     Template name
	 * @param array  $variables         Template variables
	 * @return bool Success status
	 */
	private function send_template_notification( $order_id, $customer_phone, $notification_type, $template_name, $variables ) {
		global $wpdb;

		// Create notification log entry
		$log_id = $this->create_notification_log( $order_id, $customer_phone, $notification_type, $template_name );

		try {
			// Get template manager
			$template_manager = WCH_Template_Manager::getInstance();

			// Render template
			$rendered = $template_manager->render_template( $template_name, $variables );

			if ( ! $rendered ) {
				throw new Exception( "Template {$template_name} not found or failed to render" );
			}

			// Get WhatsApp API client
			$whatsapp_client = new WCH_WhatsApp_API_Client();

			// Send message
			$result = $whatsapp_client->send_template_message(
				$customer_phone,
				$template_name,
				array_values( $variables )
			);

			if ( ! $result || ! isset( $result['messages'][0]['id'] ) ) {
				throw new Exception( 'Failed to send WhatsApp message' );
			}

			// Update log with success
			$this->update_notification_log(
				$log_id,
				array(
					'status'        => 'sent',
					'wa_message_id' => $result['messages'][0]['id'],
					'sent_at'       => current_time( 'mysql' ),
				)
			);

			WCH_Logger::info( "Notification sent for order {$order_id}: {$notification_type}" );

			return true;

		} catch ( Exception $e ) {
			// Update log with failure
			$this->update_notification_log(
				$log_id,
				array(
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
				)
			);

			WCH_Logger::error( "Notification failed for order {$order_id}: {$e->getMessage()}" );

			// Schedule retry if under max attempts
			$this->schedule_retry( $log_id, $order_id, $notification_type );

			return false;
		}
	}

	/**
	 * Process notification job (called by queue)
	 *
	 * @param array $args Job arguments
	 */
	public static function process_notification_job( $args ) {
		$instance = self::instance();

		$order_id          = $args['order_id'] ?? 0;
		$notification_type = $args['notification_type'] ?? '';

		if ( ! $order_id || ! $notification_type ) {
			WCH_Logger::error( 'Invalid notification job args: ' . wp_json_encode( $args ) );
			return;
		}

		// Route to appropriate handler
		switch ( $notification_type ) {
			case 'order_confirmation':
				$instance->send_order_confirmation( $order_id );
				break;

			case 'status_update':
				$new_status = $args['new_status'] ?? '';
				if ( $new_status ) {
					$instance->send_status_update( $order_id, $new_status );
				}
				break;

			case 'shipping_update':
				$tracking_number = $args['tracking_number'] ?? '';
				$carrier         = $args['carrier'] ?? '';
				if ( $tracking_number && $carrier ) {
					$instance->send_shipping_update( $order_id, $tracking_number, $carrier );
				}
				break;

			case 'delivery_confirmation':
				$instance->send_delivery_confirmation( $order_id );
				break;

			default:
				WCH_Logger::error( "Unknown notification type: {$notification_type}" );
		}
	}

	/**
	 * Queue notification for later sending
	 *
	 * @param array $data         Notification data
	 * @param int   $delay_seconds Delay in seconds
	 */
	private function queue_notification( $data, $delay_seconds = 30 ) {
		WCH_Job_Dispatcher::dispatch(
			'wch_send_order_notification',
			$data,
			$delay_seconds
		);
	}

	/**
	 * Schedule retry for failed notification
	 *
	 * @param int    $log_id           Log ID
	 * @param int    $order_id         Order ID
	 * @param string $notification_type Notification type
	 */
	private function schedule_retry( $log_id, $order_id, $notification_type ) {
		global $wpdb;

		$table_name = WCH_Database_Manager::instance()->get_table_name( 'notification_log' );

		// Get current retry count
		$log = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT retry_count FROM {$table_name} WHERE id = %d",
				$log_id
			)
		);

		if ( ! $log ) {
			return;
		}

		$retry_count = (int) $log->retry_count;

		// Max 3 retries
		if ( $retry_count >= 3 ) {
			WCH_Logger::error( "Max retries reached for notification log {$log_id}" );
			return;
		}

		// Increment retry count
		$wpdb->update(
			$table_name,
			array( 'retry_count' => $retry_count + 1 ),
			array( 'id' => $log_id ),
			array( '%d' ),
			array( '%d' )
		);

		// Schedule retry with 5-minute delay
		$this->queue_notification(
			array(
				'order_id'          => $order_id,
				'notification_type' => $notification_type,
			),
			300 // 5 minutes
		);

		WCH_Logger::info( 'Scheduled retry ' . ( $retry_count + 1 ) . " for notification log {$log_id}" );
	}

	/**
	 * Create notification log entry
	 *
	 * @param int    $order_id          Order ID
	 * @param string $customer_phone    Customer phone
	 * @param string $notification_type Notification type
	 * @param string $template_name     Template name
	 * @return int Log ID
	 */
	private function create_notification_log( $order_id, $customer_phone, $notification_type, $template_name ) {
		global $wpdb;

		$table_name = WCH_Database_Manager::instance()->get_table_name( 'notification_log' );

		$wpdb->insert(
			$table_name,
			array(
				'order_id'          => $order_id,
				'notification_type' => $notification_type,
				'customer_phone'    => $customer_phone,
				'template_name'     => $template_name,
				'status'            => 'queued',
				'retry_count'       => 0,
				'created_at'        => current_time( 'mysql' ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Update notification log
	 *
	 * @param int   $log_id Log ID
	 * @param array $data   Data to update
	 */
	private function update_notification_log( $log_id, $data ) {
		global $wpdb;

		$table_name = WCH_Database_Manager::instance()->get_table_name( 'notification_log' );

		$data['updated_at'] = current_time( 'mysql' );

		$wpdb->update(
			$table_name,
			$data,
			array( 'id' => $log_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * Check if notification can be sent
	 *
	 * @param string $customer_phone Customer phone
	 * @return bool Can send
	 */
	private function can_send_notification( $customer_phone ) {
		// Check customer opt-out
		if ( $this->is_customer_opted_out( $customer_phone ) ) {
			return false;
		}

		// Check quiet hours
		if ( $this->is_quiet_hours( $customer_phone ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if customer has opted out
	 *
	 * @param string $customer_phone Customer phone
	 * @return bool Is opted out
	 */
	private function is_customer_opted_out( $customer_phone ) {
		global $wpdb;

		$table_name = WCH_Database_Manager::instance()->get_table_name( 'customer_profiles' );

		$profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT notification_opt_out FROM {$table_name} WHERE phone = %s",
				$customer_phone
			)
		);

		return $profile && ! empty( $profile->notification_opt_out );
	}

	/**
	 * Check if it's quiet hours for customer
	 *
	 * @param string $customer_phone Customer phone
	 * @return bool Is quiet hours
	 */
	private function is_quiet_hours( $customer_phone ) {
		// Get customer timezone (default to site timezone)
		$timezone_string = get_option( 'timezone_string', 'UTC' );

		try {
			$timezone = new DateTimeZone( $timezone_string );
			$datetime = new DateTime( 'now', $timezone );
			$hour     = (int) $datetime->format( 'G' );

			// Quiet hours: 10pm - 8am (22:00 - 08:00)
			return $hour >= 22 || $hour < 8;

		} catch ( Exception $e ) {
			WCH_Logger::error( "Timezone error: {$e->getMessage()}" );
			return false;
		}
	}

	/**
	 * Check if notification type is enabled
	 *
	 * @param string $type Notification type
	 * @return bool Is enabled
	 */
	private function is_notification_enabled( $type ) {
		$settings = WCH_Settings::getInstance();
		return $settings->get( "notifications.{$type}_enabled", true );
	}

	/**
	 * Get customer phone from order
	 *
	 * @param WC_Order $order Order object
	 * @return string|false Customer phone or false
	 */
	private function get_customer_phone( $order ) {
		// Try WCH meta first
		$phone = $order->get_meta( '_wch_customer_phone' );

		// Fallback to billing phone
		if ( ! $phone ) {
			$phone = $order->get_billing_phone();
		}

		return $phone ?: false;
	}

	/**
	 * Get estimated delivery date
	 *
	 * @param WC_Order $order Order object
	 * @return string Estimated delivery
	 */
	private function get_estimated_delivery( $order ) {
		// Check for custom meta
		$custom_delivery = $order->get_meta( '_estimated_delivery_date' );
		if ( $custom_delivery ) {
			return $custom_delivery;
		}

		// Default: 3-5 business days
		return '3-5 business days';
	}

	/**
	 * Get status information
	 *
	 * @param string $status Order status
	 * @return array Status info with text, emoji, and action_needed
	 */
	private function get_status_info( $status ) {
		$status_map = array(
			'pending'    => array(
				'text'          => 'Payment Pending',
				'emoji'         => '⏳',
				'action_needed' => 'Please complete payment to process your order.',
			),
			'processing' => array(
				'text'          => 'Processing',
				'emoji'         => '📦',
				'action_needed' => 'We are preparing your order.',
			),
			'on-hold'    => array(
				'text'          => 'On Hold',
				'emoji'         => '⏸️',
				'action_needed' => 'Your order is on hold. We will contact you shortly.',
			),
			'completed'  => array(
				'text'          => 'Delivered',
				'emoji'         => '✅',
				'action_needed' => 'Thank you for your order!',
			),
			'cancelled'  => array(
				'text'          => 'Cancelled',
				'emoji'         => '❌',
				'action_needed' => 'Your order has been cancelled.',
			),
			'refunded'   => array(
				'text'          => 'Refunded',
				'emoji'         => '💰',
				'action_needed' => 'Your refund has been processed.',
			),
			'failed'     => array(
				'text'          => 'Failed',
				'emoji'         => '⚠️',
				'action_needed' => 'Payment failed. Please try again.',
			),
		);

		return $status_map[ $status ] ?? array(
			'text'          => ucfirst( str_replace( '-', ' ', $status ) ),
			'emoji'         => '📋',
			'action_needed' => '',
		);
	}

	/**
	 * Get template name for status
	 *
	 * @param string $status Order status
	 * @return string Template name
	 */
	private function get_status_template( $status ) {
		$template_map = array(
			'processing' => 'order_processing',
			'completed'  => 'order_completed',
			'cancelled'  => 'order_cancelled',
			'refunded'   => 'order_refunded',
		);

		return $template_map[ $status ] ?? 'order_status_update';
	}

	/**
	 * Get tracking URL for carrier
	 *
	 * @param string $carrier         Carrier name
	 * @param string $tracking_number Tracking number
	 * @return string Tracking URL
	 */
	private function get_tracking_url( $carrier, $tracking_number ) {
		$carrier_urls = array(
			'ups'   => "https://www.ups.com/track?tracknum={$tracking_number}",
			'usps'  => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$tracking_number}",
			'fedex' => "https://www.fedex.com/fedextrack/?tracknumbers={$tracking_number}",
			'dhl'   => "https://www.dhl.com/en/express/tracking.html?AWB={$tracking_number}",
		);

		$carrier_key = strtolower( $carrier );

		return $carrier_urls[ $carrier_key ] ?? '#';
	}

	/**
	 * Format carrier name
	 *
	 * @param string $carrier Carrier slug
	 * @return string Formatted carrier name
	 */
	private function format_carrier_name( $carrier ) {
		$names = array(
			'ups'   => 'UPS',
			'usps'  => 'USPS',
			'fedex' => 'FedEx',
			'dhl'   => 'DHL',
		);

		return $names[ strtolower( $carrier ) ] ?? ucfirst( $carrier );
	}

	/**
	 * Add notification metabox to order page
	 */
	public function add_notification_metabox() {
		add_meta_box(
			'wch_order_notifications',
			__( 'WhatsApp Notifications', 'whatsapp-commerce-hub' ),
			array( $this, 'render_notification_metabox' ),
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Render notification metabox
	 *
	 * @param WP_Post $post Order post
	 */
	public function render_notification_metabox( $post ) {
		$order_id = $post->ID;

		// Get notification history
		$notifications = $this->get_notification_history( $order_id );

		if ( empty( $notifications ) ) {
			echo '<p>' . esc_html__( 'No notifications sent yet.', 'whatsapp-commerce-hub' ) . '</p>';
			return;
		}

		echo '<div class="wch-notification-history">';
		echo '<style>
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
		</style>';

		foreach ( $notifications as $notification ) {
			$status_class = esc_attr( $notification->status );
			$status_text  = ucfirst( $notification->status );
			$type_text    = ucfirst( str_replace( '_', ' ', $notification->notification_type ) );
			$date         = mysql2date( 'M j, Y g:i A', $notification->created_at );

			echo '<div class="wch-notification-item">';
			echo '<div class="wch-notification-type">' . esc_html( $type_text ) . '</div>';
			echo '<div><span class="wch-notification-status ' . $status_class . '">' . esc_html( $status_text ) . '</span></div>';
			echo '<div style="color: #666; font-size: 11px;">' . esc_html( $date ) . '</div>';

			if ( $notification->status === 'failed' && $notification->error_message ) {
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
	 * Get notification history for order
	 *
	 * @param int $order_id Order ID
	 * @return array Notifications
	 */
	private function get_notification_history( $order_id ) {
		global $wpdb;

		$table_name = WCH_Database_Manager::instance()->get_table_name( 'notification_log' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY created_at DESC",
				$order_id
			)
		);
	}

	/**
	 * AJAX handler for getting notification history
	 */
	public function ajax_get_notification_history() {
		check_ajax_referer( 'wch-admin', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => 'Invalid order ID' ) );
		}

		$notifications = $this->get_notification_history( $order_id );

		wp_send_json_success( array( 'notifications' => $notifications ) );
	}
}
