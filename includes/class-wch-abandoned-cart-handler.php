<?php
/**
 * Abandoned Cart Handler Class
 *
 * Handles sending reminder messages for abandoned carts.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Abandoned_Cart_Handler
 */
class WCH_Abandoned_Cart_Handler {
	/**
	 * Process an abandoned cart job.
	 *
	 * @param array $args Job arguments.
	 */
	public static function process( $args ) {
		$cart_id = $args['cart_id'] ?? null;

		if ( ! $cart_id ) {
			WCH_Logger::log(
				'error',
				'Invalid cart ID for abandoned cart job',
				'queue',
				array()
			);
			return;
		}

		WCH_Logger::log(
			'info',
			'Processing abandoned cart reminder',
			'queue',
			array( 'cart_id' => $cart_id )
		);

		// Get cart details.
		$cart = self::get_cart( $cart_id );

		if ( ! $cart ) {
			WCH_Logger::log(
				'warning',
				'Cart not found for abandoned cart reminder',
				'queue',
				array( 'cart_id' => $cart_id )
			);
			return;
		}

		// Check if cart is still active and abandoned.
		if ( $cart['status'] !== 'active' ) {
			WCH_Logger::log(
				'info',
				'Cart is no longer active, skipping reminder',
				'queue',
				array( 'cart_id' => $cart_id, 'status' => $cart['status'] )
			);
			return;
		}

		// Check if cart is idle for configured hours.
		$settings       = WCH_Settings::getInstance();
		$delay_hours    = $settings->get( 'notifications.abandoned_cart_delay_hours', 24 );
		$idle_timestamp = current_time( 'timestamp' ) - ( $delay_hours * HOUR_IN_SECONDS );
		$updated_at     = strtotime( $cart['updated_at'] );

		if ( $updated_at > $idle_timestamp ) {
			WCH_Logger::log(
				'info',
				'Cart is not idle long enough, skipping reminder',
				'queue',
				array(
					'cart_id'        => $cart_id,
					'delay_hours'    => $delay_hours,
					'updated_at'     => $cart['updated_at'],
				)
			);
			return;
		}

		// Send reminder message.
		$result = self::send_reminder( $cart );

		if ( $result['success'] ) {
			// Update cart with reminder sent timestamp.
			self::mark_reminder_sent( $cart_id );

			WCH_Logger::log(
				'info',
				'Abandoned cart reminder sent successfully',
				'queue',
				array( 'cart_id' => $cart_id )
			);
		} else {
			WCH_Logger::log(
				'error',
				'Failed to send abandoned cart reminder',
				'queue',
				array(
					'cart_id' => $cart_id,
					'error'   => $result['error'] ?? 'Unknown error',
				)
			);
		}
	}

	/**
	 * Get cart by ID.
	 *
	 * @param int $cart_id Cart ID.
	 * @return array|null Cart data or null if not found.
	 */
	private static function get_cart( $cart_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_carts';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists !== $table_name ) {
			return null;
		}

		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d",
				$cart_id
			),
			ARRAY_A
		);

		return $cart ?: null;
	}

	/**
	 * Send abandoned cart reminder.
	 *
	 * @param array $cart Cart data.
	 * @return array Result array with 'success' key.
	 */
	private static function send_reminder( $cart ) {
		// Placeholder for actual message sending logic.
		// This will be implemented when the WhatsApp API integration is available.

		if ( empty( $cart['customer_phone'] ) ) {
			return array(
				'success' => false,
				'error'   => 'No customer phone number',
			);
		}

		// Generate reminder message.
		$message = self::generate_reminder_message( $cart );

		// Simulate message sending.
		// In production, this would call the WhatsApp Business API.
		$success = true;

		if ( $success ) {
			return array(
				'success'  => true,
				'cart_id'  => $cart['id'],
				'sent_at'  => current_time( 'mysql' ),
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to send message',
		);
	}

	/**
	 * Generate reminder message for abandoned cart.
	 *
	 * @param array $cart Cart data.
	 * @return string Reminder message.
	 */
	private static function generate_reminder_message( $cart ) {
		$items = json_decode( $cart['items'], true );
		$total = $cart['total'];

		$message = __( "Hi! You left some items in your cart:\n\n", 'whatsapp-commerce-hub' );

		// Add cart items.
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				$message .= sprintf(
					"- %s x%d\n",
					$item['name'] ?? __( 'Product', 'whatsapp-commerce-hub' ),
					$item['quantity'] ?? 1
				);
			}
		}

		$message .= sprintf(
			"\n" . __( 'Total: %s', 'whatsapp-commerce-hub' ),
			wc_price( $total )
		);

		$message .= "\n\n" . __( 'Would you like to complete your order?', 'whatsapp-commerce-hub' );

		return apply_filters( 'wch_abandoned_cart_reminder_message', $message, $cart );
	}

	/**
	 * Mark reminder as sent for a cart.
	 *
	 * @param int $cart_id Cart ID.
	 * @return bool True on success, false on failure.
	 */
	private static function mark_reminder_sent( $cart_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_carts';

		$result = $wpdb->update(
			$table_name,
			array(
				'reminder_sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => $cart_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Schedule abandoned cart reminders.
	 *
	 * Scans for carts that are idle and haven't received a reminder yet.
	 */
	public static function schedule_reminders() {
		global $wpdb;

		$settings    = WCH_Settings::getInstance();
		$enabled     = $settings->get( 'notifications.abandoned_cart_reminder', false );

		if ( ! $enabled ) {
			return;
		}

		$delay_hours    = $settings->get( 'notifications.abandoned_cart_delay_hours', 24 );
		$idle_timestamp = current_time( 'timestamp' ) - ( $delay_hours * HOUR_IN_SECONDS );
		$idle_date      = gmdate( 'Y-m-d H:i:s', $idle_timestamp );

		$table_name = $wpdb->prefix . 'wch_carts';

		// Check if table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists !== $table_name ) {
			return;
		}

		// Find abandoned carts.
		$carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table_name}
				WHERE status = %s
				AND updated_at < %s
				AND (reminder_sent_at IS NULL OR reminder_sent_at < updated_at)",
				'active',
				$idle_date
			),
			ARRAY_A
		);

		if ( empty( $carts ) ) {
			return;
		}

		// Schedule reminder for each cart.
		foreach ( $carts as $cart ) {
			// Check if already scheduled.
			$is_scheduled = WCH_Job_Dispatcher::is_scheduled(
				'wch_process_abandoned_cart',
				array( 'cart_id' => $cart['id'] )
			);

			if ( ! $is_scheduled ) {
				WCH_Job_Dispatcher::dispatch(
					'wch_process_abandoned_cart',
					array( 'cart_id' => $cart['id'] ),
					0
				);
			}
		}

		WCH_Logger::log(
			'info',
			'Abandoned cart reminders scheduled',
			'queue',
			array( 'count' => count( $carts ) )
		);
	}
}
