<?php
/**
 * Background Job Queue Class
 *
 * Manages async job processing using Action Scheduler bundled with WooCommerce.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Queue
 */
class WCH_Queue {
	/**
	 * Registered action hooks.
	 *
	 * @var array
	 */
	private $registered_hooks = array(
		'wch_process_sync_job',
		'wch_send_broadcast_batch',
		'wch_cleanup_expired_carts',
		'wch_process_abandoned_cart',
		'wch_retry_failed_message',
		'wch_process_webhook_messages',
		'wch_process_webhook_statuses',
		'wch_process_webhook_errors',
		'wch_sync_single_product',
		'wch_sync_product_batch',
		'wch_send_order_notification',
		'wch_process_stock_sync',
		'wch_detect_stock_discrepancies',
		'wch_schedule_recovery_reminders',
		'wch_process_recovery_message',
	);

	/**
	 * The single instance of the class.
	 *
	 * @var WCH_Queue
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WCH_Queue
	 */
	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the queue system.
	 */
	private function init() {
		// Register action hooks on init.
		add_action( 'init', array( $this, 'register_action_hooks' ) );

		// Register custom cron schedule for hourly cleanup.
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );

		// Schedule hourly cleanup if not already scheduled.
		add_action( 'init', array( $this, 'schedule_recurring_jobs' ) );
	}

	/**
	 * Register action hooks for job processing.
	 */
	public function register_action_hooks() {
		foreach ( $this->registered_hooks as $hook ) {
			// Only register if the hook handler exists.
			if ( has_action( $hook ) ) {
				continue;
			}

			// Register the handler based on the hook name.
			switch ( $hook ) {
				case 'wch_process_sync_job':
					add_action( $hook, array( 'WCH_Sync_Job_Handler', 'process' ), 10, 1 );
					break;

				case 'wch_send_broadcast_batch':
					add_action( $hook, array( 'WCH_Broadcast_Job_Handler', 'process' ), 10, 1 );
					break;

				case 'wch_cleanup_expired_carts':
					add_action( $hook, array( 'WCH_Cart_Cleanup_Handler', 'process' ), 10, 1 );
					break;

				case 'wch_process_abandoned_cart':
					add_action( $hook, array( 'WCH_Abandoned_Cart_Handler', 'process' ), 10, 1 );
					break;

				case 'wch_retry_failed_message':
					add_action( $hook, array( $this, 'retry_failed_message' ), 10, 1 );
					break;

				case 'wch_process_webhook_messages':
					add_action( $hook, array( $this, 'process_webhook_messages' ), 10, 1 );
					break;

				case 'wch_process_webhook_statuses':
					add_action( $hook, array( $this, 'process_webhook_statuses' ), 10, 1 );
					break;

				case 'wch_process_webhook_errors':
					add_action( $hook, array( $this, 'process_webhook_errors' ), 10, 1 );
					break;

				case 'wch_sync_single_product':
					add_action( $hook, array( 'WCH_Product_Sync_Service', 'process_single_product' ), 10, 1 );
					break;

				case 'wch_sync_product_batch':
					add_action( $hook, array( 'WCH_Product_Sync_Service', 'process_product_batch' ), 10, 1 );
					break;

				case 'wch_send_order_notification':
					add_action( $hook, array( 'WCH_Order_Notifications', 'process_notification_job' ), 10, 1 );
					break;

				case 'wch_process_stock_sync':
					add_action( $hook, array( 'WCH_Inventory_Sync_Handler', 'process_stock_sync' ), 10, 1 );
					break;

				case 'wch_detect_stock_discrepancies':
					add_action( $hook, array( 'WCH_Inventory_Sync_Handler', 'detect_stock_discrepancies' ), 10, 0 );
					break;

				case 'wch_schedule_recovery_reminders':
					add_action( $hook, array( 'WCH_Abandoned_Cart_Recovery', 'schedule_recovery_reminders' ), 10, 0 );
					break;

				case 'wch_process_recovery_message':
					add_action( $hook, array( 'WCH_Abandoned_Cart_Recovery', 'process_recovery_message' ), 10, 1 );
					break;
			}
		}
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_custom_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['wch_hourly'] ) ) {
			$schedules['wch_hourly'] = array(
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Once Hourly (WCH)', 'whatsapp-commerce-hub' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule recurring jobs.
	 */
	public function schedule_recurring_jobs() {
		// Schedule hourly cart cleanup if not already scheduled.
		if ( ! as_next_scheduled_action( 'wch_cleanup_expired_carts' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_cleanup_expired_carts',
				array(),
				'wch'
			);
		}
	}

	/**
	 * Retry a failed message.
	 *
	 * @param array $args Arguments containing message details.
	 */
	public function retry_failed_message( $args ) {
		// This will be implemented when the messaging system is available.
		// For now, just log the attempt.
		WCH_Logger::log(
			'info',
			'Retry failed message job received',
			'queue',
			$args
		);
	}

	/**
	 * Get all registered hooks.
	 *
	 * @return array
	 */
	public function get_registered_hooks() {
		return $this->registered_hooks;
	}

	/**
	 * Process webhook messages event.
	 *
	 * @param array $args Arguments containing message data.
	 */
	public function process_webhook_messages( $args ) {
		$data = $args['data'] ?? array();

		WCH_Logger::log(
			'info',
			'Processing webhook message event',
			'queue',
			array(
				'message_id' => $data['message_id'] ?? '',
				'from'       => $data['from'] ?? '',
				'type'       => $data['type'] ?? '',
			)
		);

		// TODO: Implement actual message processing logic when conversation handler is available.
		// This is a placeholder for future implementation.
	}

	/**
	 * Process webhook status event.
	 *
	 * @param array $args Arguments containing status data.
	 */
	public function process_webhook_statuses( $args ) {
		$data = $args['data'] ?? array();

		WCH_Logger::log(
			'info',
			'Processing webhook status event',
			'queue',
			array(
				'message_id' => $data['message_id'] ?? '',
				'status'     => $data['status'] ?? '',
			)
		);

		// TODO: Implement actual status update logic when message handler is available.
		// This is a placeholder for future implementation.
	}

	/**
	 * Process webhook error event.
	 *
	 * @param array $args Arguments containing error data.
	 */
	public function process_webhook_errors( $args ) {
		$data = $args['data'] ?? array();

		WCH_Logger::log(
			'error',
			'Processing webhook error event',
			'queue',
			$data
		);

		// TODO: Implement actual error handling logic when error handler is available.
		// This is a placeholder for future implementation.
	}

	/**
	 * Send order notification to customer.
	 *
	 * @param array $args Arguments containing notification data.
	 */
	public function send_order_notification( $args ) {
		$customer_phone = $args['customer_phone'] ?? '';
		$template_name = $args['template_name'] ?? '';
		$order_id = $args['order_id'] ?? 0;

		if ( empty( $customer_phone ) || empty( $template_name ) ) {
			WCH_Logger::warning(
				'Missing required parameters for order notification',
				'queue',
				$args
			);
			return;
		}

		WCH_Logger::info(
			'Processing order notification',
			'queue',
			array(
				'customer_phone' => $customer_phone,
				'template_name' => $template_name,
				'order_id' => $order_id,
			)
		);

		// TODO: Implement actual notification sending when WhatsApp API integration is complete.
		// This is a placeholder for future implementation.
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of the instance.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
