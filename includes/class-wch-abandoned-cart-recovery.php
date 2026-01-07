<?php
/**
 * Abandoned Cart Recovery System Class
 *
 * Handles automated abandoned cart recovery with multi-sequence messaging.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Abandoned_Cart_Recovery
 */
class WCH_Abandoned_Cart_Recovery {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Abandoned_Cart_Recovery|null
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Abandoned_Cart_Recovery
	 */
	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = WCH_Settings::getInstance();
	}

	/**
	 * Initialize recovery system.
	 */
	public function init() {
		// Schedule recurring job to find and process abandoned carts.
		if ( ! as_next_scheduled_action( 'wch_schedule_recovery_reminders', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				30 * MINUTE_IN_SECONDS,
				'wch_schedule_recovery_reminders',
				array(),
				'wch'
			);
		}
	}

	/**
	 * Schedule recovery reminders for abandoned carts.
	 *
	 * Runs every 30 minutes to find carts that need recovery messages.
	 */
	public static function schedule_recovery_reminders() {
		$instance = self::getInstance();

		if ( ! $instance->is_recovery_enabled() ) {
			return;
		}

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
			return;
		}

		$now = current_time( 'mysql' );

		// Get delay settings for each sequence.
		$delay_1 = $instance->get_sequence_delay( 1 );
		$delay_2 = $instance->get_sequence_delay( 2 );
		$delay_3 = $instance->get_sequence_delay( 3 );

		// Find carts for each sequence.
		$sequences = array(
			1 => $delay_1,
			2 => $delay_2,
			3 => $delay_3,
		);

		foreach ( $sequences as $sequence => $delay_hours ) {
			$carts = $instance->find_carts_for_sequence( $sequence, $delay_hours );

			foreach ( $carts as $cart ) {
				// Check if already scheduled.
				$is_scheduled = WCH_Job_Dispatcher::is_scheduled(
					'wch_process_recovery_message',
					array(
						'cart_id'  => $cart['id'],
						'sequence' => $sequence,
					)
				);

				if ( ! $is_scheduled ) {
					WCH_Job_Dispatcher::dispatch(
						'wch_process_recovery_message',
						array(
							'cart_id'  => $cart['id'],
							'sequence' => $sequence,
						),
						0
					);

					WCH_Logger::log(
						'info',
						'Scheduled recovery message',
						'abandoned-cart',
						array(
							'cart_id'  => $cart['id'],
							'sequence' => $sequence,
						)
					);
				}
			}
		}
	}

	/**
	 * Process recovery message job.
	 *
	 * @param array $args Job arguments containing cart_id and sequence.
	 */
	public static function process_recovery_message( $args ) {
		$instance = self::getInstance();

		$cart_id  = $args['cart_id'] ?? null;
		$sequence = $args['sequence'] ?? null;

		if ( ! $cart_id || ! $sequence ) {
			WCH_Logger::log(
				'error',
				'Invalid arguments for recovery message job',
				'abandoned-cart',
				array( 'args' => $args )
			);
			return;
		}

		// Get cart details.
		$cart = $instance->get_cart( $cart_id );

		if ( ! $cart ) {
			WCH_Logger::log(
				'warning',
				'Cart not found for recovery message',
				'abandoned-cart',
				array( 'cart_id' => $cart_id )
			);
			return;
		}

		// Validate cart is eligible for recovery.
		if ( ! $instance->is_cart_eligible( $cart, $sequence ) ) {
			WCH_Logger::log(
				'info',
				'Cart not eligible for recovery message',
				'abandoned-cart',
				array(
					'cart_id'  => $cart_id,
					'sequence' => $sequence,
					'status'   => $cart['status'],
				)
			);
			return;
		}

		// Send recovery message.
		$result = $instance->send_recovery_message( $cart, $sequence );

		if ( $result['success'] ) {
			WCH_Logger::log(
				'info',
				'Recovery message sent successfully',
				'abandoned-cart',
				array(
					'cart_id'  => $cart_id,
					'sequence' => $sequence,
				)
			);
		} else {
			WCH_Logger::log(
				'error',
				'Failed to send recovery message',
				'abandoned-cart',
				array(
					'cart_id'  => $cart_id,
					'sequence' => $sequence,
					'error'    => $result['error'] ?? 'Unknown error',
				)
			);
		}
	}

	/**
	 * Find carts eligible for a specific sequence.
	 *
	 * @param int $sequence Sequence number (1, 2, or 3).
	 * @param int $delay_hours Hours since last activity.
	 * @return array Array of cart records.
	 */
	private function find_carts_for_sequence( $sequence, $delay_hours ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		$delay_timestamp = current_time( 'timestamp' ) - ( $delay_hours * HOUR_IN_SECONDS );
		$delay_date      = gmdate( 'Y-m-d H:i:s', $delay_timestamp );

		$reminder_field = "reminder_{$sequence}_sent_at";

		// Build query based on sequence.
		if ( 1 === $sequence ) {
			// First reminder: cart updated before delay and no reminder 1 sent.
			$carts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name}
					WHERE status = %s
					AND updated_at < %s
					AND items IS NOT NULL
					AND JSON_LENGTH(items) > 0
					AND reminder_1_sent_at IS NULL",
					'active',
					$delay_date
				),
				ARRAY_A
			);
		} elseif ( 2 === $sequence ) {
			// Second reminder: reminder 1 sent, but not reminder 2, and cart still not converted.
			$carts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name}
					WHERE status = %s
					AND reminder_1_sent_at IS NOT NULL
					AND reminder_1_sent_at < %s
					AND reminder_2_sent_at IS NULL
					AND recovered = 0",
					'active',
					$delay_date
				),
				ARRAY_A
			);
		} else {
			// Third reminder: reminder 2 sent, but not reminder 3, and cart still not converted.
			$carts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name}
					WHERE status = %s
					AND reminder_2_sent_at IS NOT NULL
					AND reminder_2_sent_at < %s
					AND reminder_3_sent_at IS NULL
					AND recovered = 0",
					'active',
					$delay_date
				),
				ARRAY_A
			);
		}

		return $carts ?: array();
	}

	/**
	 * Get cart by ID.
	 *
	 * @param int $cart_id Cart ID.
	 * @return array|null Cart data or null if not found.
	 */
	private function get_cart( $cart_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

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
	 * Check if cart is eligible for recovery message.
	 *
	 * @param array $cart Cart data.
	 * @param int   $sequence Sequence number.
	 * @return bool True if eligible, false otherwise.
	 */
	private function is_cart_eligible( $cart, $sequence ) {
		// Cart must be active.
		if ( 'active' !== $cart['status'] ) {
			return false;
		}

		// Cart must not be recovered.
		if ( ! empty( $cart['recovered'] ) ) {
			return false;
		}

		// Cart must have items.
		$items = json_decode( $cart['items'], true );
		if ( empty( $items ) ) {
			return false;
		}

		// Check sequence hasn't been sent yet.
		$reminder_field = "reminder_{$sequence}_sent_at";
		if ( ! empty( $cart[ $reminder_field ] ) ) {
			return false;
		}

		// For sequence 2 and 3, check previous sequence was sent.
		if ( $sequence > 1 ) {
			$prev_reminder_field = 'reminder_' . ( $sequence - 1 ) . '_sent_at';
			if ( empty( $cart[ $prev_reminder_field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Send recovery message to customer.
	 *
	 * @param array $cart Cart data.
	 * @param int   $sequence Sequence number (1, 2, or 3).
	 * @return array Result array with 'success' key.
	 */
	public function send_recovery_message( $cart, $sequence ) {
		if ( empty( $cart['customer_phone'] ) ) {
			return array(
				'success' => false,
				'error'   => 'No customer phone number',
			);
		}

		// Build template variables.
		$variables = $this->build_template_variables( $cart, $sequence );

		// Get template name for this sequence.
		$template_name = $this->get_template_name_for_sequence( $sequence );

		if ( ! $template_name ) {
			return array(
				'success' => false,
				'error'   => 'No template configured for sequence',
			);
		}

		// Generate coupon for sequence 3 if enabled.
		$coupon_code = null;
		if ( 3 === $sequence && $this->is_discount_enabled() ) {
			$coupon_code = $this->generate_recovery_coupon( $cart );
			if ( $coupon_code ) {
				$variables['discount_code'] = $coupon_code;
				$variables['discount_amount'] = $this->get_discount_display();
			}
		}

		// TODO: Implement actual WhatsApp message sending via template.
		// For now, we'll simulate success.
		$success = true;

		if ( $success ) {
			// Update cart with sent timestamp and coupon if applicable.
			$this->mark_reminder_sent( $cart['id'], $sequence, $coupon_code );

			return array(
				'success'   => true,
				'cart_id'   => $cart['id'],
				'sequence'  => $sequence,
				'sent_at'   => current_time( 'mysql' ),
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to send message',
		);
	}

	/**
	 * Build template variables for recovery message.
	 *
	 * @param array $cart Cart data.
	 * @param int   $sequence Sequence number.
	 * @return array Template variables.
	 */
	private function build_template_variables( $cart, $sequence ) {
		$items = json_decode( $cart['items'], true );

		// Get customer name.
		$customer_name = $this->get_customer_name( $cart['customer_phone'] );

		// Calculate item count.
		$item_count = 0;
		foreach ( $items as $item ) {
			$item_count += $item['quantity'] ?? 1;
		}

		// Get top item (first item or highest value).
		$top_item_name = '';
		$top_item_image = '';
		if ( ! empty( $items[0] ) ) {
			$top_item_name = $items[0]['name'] ?? '';
			$product_id = $items[0]['product_id'] ?? 0;
			if ( $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$top_item_image = wp_get_attachment_url( $product->get_image_id() );
				}
			}
		}

		// Build cart URL (deep link).
		$cart_url = home_url( '/cart' );

		$variables = array(
			'customer_name'  => $customer_name,
			'item_count'     => $item_count,
			'cart_total'     => wc_price( $cart['total'] ),
			'top_item_name'  => $top_item_name,
			'top_item_image' => $top_item_image,
			'cart_url'       => $cart_url,
		);

		return apply_filters( 'wch_recovery_template_variables', $variables, $cart, $sequence );
	}

	/**
	 * Get customer name from phone number.
	 *
	 * @param string $phone Customer phone number.
	 * @return string Customer name or default greeting.
	 */
	private function get_customer_name( $phone ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_customer_profiles';

		$name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$table_name} WHERE phone = %s",
				$phone
			)
		);

		return $name ?: __( 'Customer', 'whatsapp-commerce-hub' );
	}

	/**
	 * Generate a unique recovery coupon for the cart.
	 *
	 * @param array $cart Cart data.
	 * @return string|null Coupon code or null on failure.
	 */
	private function generate_recovery_coupon( $cart ) {
		$discount_type = $this->settings->get( 'recovery.discount_type', 'percent' );
		$discount_amount = $this->settings->get( 'recovery.discount_amount', 10 );

		// Generate unique coupon code.
		$coupon_code = 'RECOVER' . strtoupper( substr( md5( $cart['id'] . time() ), 0, 8 ) );

		// Create WooCommerce coupon.
		$coupon = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_discount_type( 'percent' === $discount_type ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( $discount_amount );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_date_expires( strtotime( '+7 days' ) );

		// Restrict to this customer's phone/email if possible.
		$customer_email = $this->get_customer_email( $cart['customer_phone'] );
		if ( $customer_email ) {
			$coupon->set_email_restrictions( array( $customer_email ) );
		}

		try {
			$coupon->save();
			return $coupon_code;
		} catch ( Exception $e ) {
			WCH_Logger::log(
				'error',
				'Failed to create recovery coupon',
				'abandoned-cart',
				array(
					'cart_id' => $cart['id'],
					'error'   => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Get customer email from phone number.
	 *
	 * @param string $phone Customer phone number.
	 * @return string|null Customer email or null.
	 */
	private function get_customer_email( $phone ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_customer_profiles';

		$wc_customer_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT wc_customer_id FROM {$table_name} WHERE phone = %s",
				$phone
			)
		);

		if ( $wc_customer_id ) {
			$customer = new WC_Customer( $wc_customer_id );
			return $customer->get_email();
		}

		return null;
	}

	/**
	 * Mark reminder as sent for a cart.
	 *
	 * @param int         $cart_id Cart ID.
	 * @param int         $sequence Sequence number.
	 * @param string|null $coupon_code Optional coupon code.
	 * @return bool True on success, false on failure.
	 */
	private function mark_reminder_sent( $cart_id, $sequence, $coupon_code = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		$update_data = array(
			"reminder_{$sequence}_sent_at" => current_time( 'mysql' ),
		);

		if ( $coupon_code ) {
			$update_data['recovery_coupon_code'] = $coupon_code;
		}

		$result = $wpdb->update(
			$table_name,
			$update_data,
			array( 'id' => $cart_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Mark cart as recovered when order is completed.
	 *
	 * @param int   $cart_id Cart ID.
	 * @param int   $order_id WooCommerce order ID.
	 * @param float $revenue Order total.
	 * @return bool True on success, false on failure.
	 */
	public function mark_cart_recovered( $cart_id, $order_id, $revenue ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		$result = $wpdb->update(
			$table_name,
			array(
				'recovered'           => 1,
				'recovered_order_id'  => $order_id,
				'recovered_revenue'   => $revenue,
				'status'              => 'completed',
			),
			array( 'id' => $cart_id ),
			array( '%d', '%d', '%f', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			WCH_Logger::log(
				'info',
				'Cart marked as recovered',
				'abandoned-cart',
				array(
					'cart_id'  => $cart_id,
					'order_id' => $order_id,
					'revenue'  => $revenue,
				)
			);
		}

		return false !== $result;
	}

	/**
	 * Stop recovery sequence for a cart.
	 *
	 * Called when customer replies, completes purchase, opts out, or cart is modified.
	 *
	 * @param string $phone Customer phone number.
	 * @param string $reason Reason for stopping sequence.
	 * @return bool True on success, false on failure.
	 */
	public function stop_sequence( $phone, $reason = 'customer_action' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		// Get cart ID for this phone.
		$cart_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE customer_phone = %s AND status = 'active'",
				$phone
			)
		);

		if ( ! $cart_id ) {
			return false;
		}

		// Cancel any pending recovery jobs for this cart.
		for ( $sequence = 1; $sequence <= 3; $sequence++ ) {
			WCH_Job_Dispatcher::cancel(
				'wch_process_recovery_message',
				array(
					'cart_id'  => $cart_id,
					'sequence' => $sequence,
				)
			);
		}

		WCH_Logger::log(
			'info',
			'Recovery sequence stopped',
			'abandoned-cart',
			array(
				'cart_id' => $cart_id,
				'phone'   => $phone,
				'reason'  => $reason,
			)
		);

		return true;
	}

	/**
	 * Get recovery statistics for dashboard.
	 *
	 * @param int $days Number of days to analyze (default: 7).
	 * @return array Statistics array.
	 */
	public function get_recovery_stats( $days = 7 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wch_carts';

		$since_date = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		// Abandoned carts count.
		$abandoned_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE created_at >= %s
				AND status = 'active'
				AND (reminder_1_sent_at IS NOT NULL OR reminder_2_sent_at IS NOT NULL OR reminder_3_sent_at IS NOT NULL)",
				$since_date
			)
		);

		// Recovery messages sent.
		$messages_sent = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT
					(SELECT COUNT(*) FROM {$table_name} WHERE reminder_1_sent_at >= %s) +
					(SELECT COUNT(*) FROM {$table_name} WHERE reminder_2_sent_at >= %s) +
					(SELECT COUNT(*) FROM {$table_name} WHERE reminder_3_sent_at >= %s)",
				$since_date,
				$since_date,
				$since_date
			)
		);

		// Carts recovered.
		$recovered_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE created_at >= %s
				AND recovered = 1",
				$since_date
			)
		);

		// Revenue recovered.
		$revenue_recovered = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(recovered_revenue) FROM {$table_name}
				WHERE created_at >= %s
				AND recovered = 1",
				$since_date
			)
		);

		// Calculate recovery rate.
		$recovery_rate = 0;
		if ( $abandoned_count > 0 ) {
			$recovery_rate = ( $recovered_count / $abandoned_count ) * 100;
		}

		return array(
			'abandoned_carts'   => (int) $abandoned_count,
			'messages_sent'     => (int) $messages_sent,
			'carts_recovered'   => (int) $recovered_count,
			'recovery_rate'     => round( $recovery_rate, 2 ),
			'revenue_recovered' => (float) $revenue_recovered,
		);
	}

	/**
	 * Check if recovery is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	private function is_recovery_enabled() {
		return (bool) $this->settings->get( 'recovery.enabled', false );
	}

	/**
	 * Get sequence delay in hours.
	 *
	 * @param int $sequence Sequence number (1, 2, or 3).
	 * @return int Delay in hours.
	 */
	private function get_sequence_delay( $sequence ) {
		$defaults = array(
			1 => 4,
			2 => 24,
			3 => 48,
		);

		return (int) $this->settings->get( "recovery.delay_sequence_{$sequence}", $defaults[ $sequence ] ?? 4 );
	}

	/**
	 * Get template name for sequence.
	 *
	 * @param int $sequence Sequence number.
	 * @return string|null Template name or null.
	 */
	private function get_template_name_for_sequence( $sequence ) {
		return $this->settings->get( "recovery.template_sequence_{$sequence}", null );
	}

	/**
	 * Check if discount is enabled for final reminder.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	private function is_discount_enabled() {
		return (bool) $this->settings->get( 'recovery.discount_enabled', false );
	}

	/**
	 * Get discount display string.
	 *
	 * @return string Discount display.
	 */
	private function get_discount_display() {
		$type = $this->settings->get( 'recovery.discount_type', 'percent' );
		$amount = $this->settings->get( 'recovery.discount_amount', 10 );

		if ( 'percent' === $type ) {
			return $amount . '%';
		}

		return wc_price( $amount );
	}
}
