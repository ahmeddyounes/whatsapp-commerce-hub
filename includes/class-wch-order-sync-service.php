<?php
/**
 * Order Sync Service Class
 *
 * Handles order synchronization from WhatsApp to WooCommerce and status updates.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Order_Sync_Service
 */
class WCH_Order_Sync_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Order_Sync_Service
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Notification templates mapping.
	 *
	 * @var array
	 */
	private $notification_templates = array(
		'pending->processing'   => 'order_confirmed',
		'processing->completed' => 'order_completed',
		'processing->shipped'   => 'shipping_update',
		'any->cancelled'        => 'order_cancelled',
		'any->refunded'         => 'order_cancelled',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Order_Sync_Service
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = WCH_Settings::instance();
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Hook on order status changes.
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order_status_to_whatsapp' ), 10, 3 );

		// Admin hooks.
		add_action( 'add_meta_boxes', array( $this, 'add_whatsapp_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_footer', array( $this, 'add_quick_reply_modal' ) );

		// Order list customizations.
		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_whatsapp_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_whatsapp_column' ), 10, 2 );
		add_filter( 'request', array( $this, 'filter_orders_by_whatsapp' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_whatsapp_filter_dropdown' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wch_send_quick_message', array( $this, 'ajax_send_quick_message' ) );
		add_action( 'wp_ajax_wch_save_tracking_info', array( $this, 'ajax_save_tracking_info' ) );
	}

	/**
	 * Create WooCommerce order from WhatsApp cart data.
	 *
	 * @param array  $cart_data      Cart data including items, shipping, payment method.
	 * @param string $customer_phone Customer phone number.
	 * @return int Order ID on success.
	 * @throws WCH_Exception If order creation fails.
	 */
	public function create_order_from_cart( $cart_data, $customer_phone ) {
		global $wpdb;

		// Start transaction.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Validate cart items are in stock.
			$this->validate_cart_items( $cart_data['items'] ?? array() );

			// Create WC_Order.
			$order = wc_create_order();

			if ( is_wp_error( $order ) ) {
				throw new WCH_Exception( 'Failed to create order: ' . $order->get_error_message() );
			}

			// Add line items with current prices.
			$this->add_line_items( $order, $cart_data['items'] ?? array() );

			// Set addresses.
			$this->set_order_addresses( $order, $cart_data['shipping_address'] ?? array(), $customer_phone );

			// Set payment method.
			$payment_method = $cart_data['payment_method'] ?? 'cod';
			$order->set_payment_method( $payment_method );
			$order->set_payment_method_title( $this->get_payment_method_title( $payment_method ) );

			// Apply coupon if present.
			if ( ! empty( $cart_data['coupon_code'] ) ) {
				$this->apply_coupon( $order, $cart_data['coupon_code'] );
			}

			// Calculate totals.
			$order->calculate_totals();

			// Set order meta.
			$order->update_meta_data( '_wch_order', true );
			$order->update_meta_data( '_wch_customer_phone', $customer_phone );

			if ( ! empty( $cart_data['conversation_id'] ) ) {
				$order->update_meta_data( '_wch_conversation_id', $cart_data['conversation_id'] );
			}

			// Set order status based on payment method.
			$status = ( 'cod' === $payment_method ) ? 'pending' : 'processing';
			$order->set_status( $status );

			// Save order.
			$order->save();

			// Reduce stock levels atomically.
			wc_reduce_stock_levels( $order->get_id() );

			// Add order note about WhatsApp origin.
			$order->add_order_note(
				sprintf(
					__( 'Order created via WhatsApp Commerce Hub from phone: %s', 'whatsapp-commerce-hub' ),
					$customer_phone
				)
			);

			// Commit transaction.
			$wpdb->query( 'COMMIT' );

			WCH_Logger::info(
				'Order created from WhatsApp cart',
				'order-sync',
				array(
					'order_id'       => $order->get_id(),
					'customer_phone' => $customer_phone,
					'status'         => $status,
				)
			);

			return $order->get_id();

		} catch ( Exception $e ) {
			// Rollback transaction on error.
			$wpdb->query( 'ROLLBACK' );

			WCH_Logger::error(
				'Failed to create order from cart',
				'order-sync',
				array(
					'customer_phone' => $customer_phone,
					'error'          => $e->getMessage(),
				)
			);

			throw new WCH_Exception( $e->getMessage() );
		}
	}

	/**
	 * Validate that cart items are still in stock.
	 *
	 * @param array $items Cart items.
	 * @throws WCH_Exception If validation fails.
	 */
	private function validate_cart_items( $items ) {
		if ( empty( $items ) ) {
			throw new WCH_Exception( 'Cart is empty' );
		}

		foreach ( $items as $item ) {
			$product_id = $item['product_id'] ?? 0;
			$quantity   = $item['quantity'] ?? 0;

			if ( ! $product_id || ! $quantity ) {
				throw new WCH_Exception( 'Invalid cart item data' );
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				throw new WCH_Exception( "Product {$product_id} not found" );
			}

			// Check if product is purchasable.
			if ( ! $product->is_purchasable() ) {
				throw new WCH_Exception( "Product {$product->get_name()} is not purchasable" );
			}

			// Check stock status.
			if ( ! $product->is_in_stock() ) {
				throw new WCH_Exception( "Product {$product->get_name()} is out of stock" );
			}

			// Check stock quantity if managing stock.
			if ( $product->managing_stock() ) {
				$stock_quantity = $product->get_stock_quantity();
				if ( $stock_quantity < $quantity ) {
					throw new WCH_Exception(
						sprintf(
							'Insufficient stock for %s. Available: %d, Requested: %d',
							$product->get_name(),
							$stock_quantity,
							$quantity
						)
					);
				}
			}
		}
	}

	/**
	 * Add line items to order with current prices.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $items Cart items.
	 */
	private function add_line_items( $order, $items ) {
		foreach ( $items as $item ) {
			$product_id = $item['product_id'];
			$quantity   = $item['quantity'];

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			// Add product to order with current price.
			$order->add_product( $product, $quantity );
		}
	}

	/**
	 * Set order billing and shipping addresses.
	 *
	 * @param WC_Order $order            Order object.
	 * @param array    $shipping_address Shipping address data.
	 * @param string   $customer_phone   Customer phone number.
	 */
	private function set_order_addresses( $order, $shipping_address, $customer_phone ) {
		$address_data = array(
			'first_name' => $shipping_address['first_name'] ?? '',
			'last_name'  => $shipping_address['last_name'] ?? '',
			'company'    => $shipping_address['company'] ?? '',
			'address_1'  => $shipping_address['address_1'] ?? '',
			'address_2'  => $shipping_address['address_2'] ?? '',
			'city'       => $shipping_address['city'] ?? '',
			'state'      => $shipping_address['state'] ?? '',
			'postcode'   => $shipping_address['postcode'] ?? '',
			'country'    => $shipping_address['country'] ?? '',
			'phone'      => $customer_phone,
		);

		// Set email if available.
		if ( ! empty( $shipping_address['email'] ) ) {
			$address_data['email'] = $shipping_address['email'];
		}

		// Set both billing and shipping addresses.
		$order->set_address( $address_data, 'billing' );
		$order->set_address( $address_data, 'shipping' );
	}

	/**
	 * Get payment method title.
	 *
	 * @param string $payment_method Payment method ID.
	 * @return string Payment method title.
	 */
	private function get_payment_method_title( $payment_method ) {
		$titles = array(
			'cod'    => __( 'Cash on Delivery', 'whatsapp-commerce-hub' ),
			'bacs'   => __( 'Bank Transfer', 'whatsapp-commerce-hub' ),
			'cheque' => __( 'Check Payment', 'whatsapp-commerce-hub' ),
		);

		return $titles[ $payment_method ] ?? ucfirst( str_replace( '_', ' ', $payment_method ) );
	}

	/**
	 * Apply coupon to order.
	 *
	 * @param WC_Order $order       Order object.
	 * @param string   $coupon_code Coupon code.
	 * @throws WCH_Exception If coupon is invalid.
	 */
	private function apply_coupon( $order, $coupon_code ) {
		$coupon = new WC_Coupon( $coupon_code );

		if ( ! $coupon->is_valid() ) {
			throw new WCH_Exception( "Coupon '{$coupon_code}' is not valid" );
		}

		// Add coupon to order.
		$order->apply_coupon( $coupon );
	}

	/**
	 * Sync order status changes to WhatsApp.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 */
	public function sync_order_status_to_whatsapp( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Only process WhatsApp orders.
		if ( ! $order->get_meta( '_wch_order' ) ) {
			return;
		}

		$customer_phone  = $order->get_meta( '_wch_customer_phone' );
		$conversation_id = $order->get_meta( '_wch_conversation_id' );

		if ( empty( $customer_phone ) ) {
			return;
		}

		// Map status change to notification template.
		$template_name = $this->get_notification_template( $old_status, $new_status );

		if ( ! $template_name ) {
			return;
		}

		// Prepare notification data.
		$notification_data = array(
			'customer_phone'  => $customer_phone,
			'conversation_id' => $conversation_id,
			'template_name'   => $template_name,
			'order_id'        => $order_id,
			'order_number'    => $order->get_order_number(),
			'old_status'      => $old_status,
			'new_status'      => $new_status,
		);

		// Add tracking info for shipping updates.
		if ( 'shipping_update' === $template_name ) {
			$notification_data['tracking_number'] = $order->get_meta( '_wch_tracking_number' );
			$notification_data['carrier']         = $order->get_meta( '_wch_carrier' );
		}

		// Queue notification.
		WCH_Job_Dispatcher::dispatch(
			'wch_send_order_notification',
			$notification_data
		);

		WCH_Logger::info(
			'Order status notification queued',
			'order-sync',
			array(
				'order_id'   => $order_id,
				'old_status' => $old_status,
				'new_status' => $new_status,
				'template'   => $template_name,
			)
		);
	}

	/**
	 * Get notification template name for status change.
	 *
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 * @return string|null Template name or null if no notification needed.
	 */
	private function get_notification_template( $old_status, $new_status ) {
		// Direct mapping.
		$key = "{$old_status}->{$new_status}";
		if ( isset( $this->notification_templates[ $key ] ) ) {
			return $this->notification_templates[ $key ];
		}

		// Check for 'any' mappings.
		$any_key = "any->{$new_status}";
		if ( isset( $this->notification_templates[ $any_key ] ) ) {
			return $this->notification_templates[ $any_key ];
		}

		// Check for shipped status (custom status).
		if ( 'shipped' === $new_status && 'processing' === $old_status ) {
			return 'shipping_update';
		}

		return null;
	}

	/**
	 * Add tracking information to order.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $tracking_number Tracking number.
	 * @param string $carrier         Carrier name.
	 * @return bool True on success, false on failure.
	 */
	public function add_tracking_info( $order_id, $tracking_number, $carrier ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		// Store tracking info in order meta.
		$order->update_meta_data( '_wch_tracking_number', $tracking_number );
		$order->update_meta_data( '_wch_carrier', $carrier );
		$order->save();

		// Add order note.
		$order->add_order_note(
			sprintf(
				__( 'Tracking information added - Carrier: %1$s, Tracking Number: %2$s', 'whatsapp-commerce-hub' ),
				$carrier,
				$tracking_number
			)
		);

		// Trigger shipping update notification if order is WhatsApp order.
		if ( $order->get_meta( '_wch_order' ) ) {
			$customer_phone  = $order->get_meta( '_wch_customer_phone' );
			$conversation_id = $order->get_meta( '_wch_conversation_id' );

			if ( $customer_phone ) {
				WCH_Job_Dispatcher::dispatch(
					'wch_send_order_notification',
					array(
						'customer_phone'  => $customer_phone,
						'conversation_id' => $conversation_id,
						'template_name'   => 'shipping_update',
						'order_id'        => $order_id,
						'tracking_number' => $tracking_number,
						'carrier'         => $carrier,
					)
				);
			}
		}

		WCH_Logger::info(
			'Tracking info added to order',
			'order-sync',
			array(
				'order_id'        => $order_id,
				'tracking_number' => $tracking_number,
				'carrier'         => $carrier,
			)
		);

		return true;
	}

	/**
	 * Add WhatsApp column to orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_whatsapp_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'order_number' === $key ) {
				$new_columns['whatsapp'] = __( 'WhatsApp', 'whatsapp-commerce-hub' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render WhatsApp column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID (order ID).
	 */
	public function render_whatsapp_column( $column, $post_id ) {
		if ( 'whatsapp' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			echo '<span style="color: #999;">—</span>';
			return;
		}

		$conversation_id = $order->get_meta( '_wch_conversation_id' );

		echo '<span style="color: #25d366; font-size: 18px;" title="' . esc_attr__( 'WhatsApp Order', 'whatsapp-commerce-hub' ) . '">✓</span>';

		if ( $conversation_id ) {
			$conversation_link = admin_url( 'admin.php?page=wch-conversations&id=' . $conversation_id );
			echo '<br><a href="' . esc_url( $conversation_link ) . '" style="font-size: 11px;">' . esc_html__( 'View Chat', 'whatsapp-commerce-hub' ) . '</a>';
		}
	}

	/**
	 * Add WhatsApp filter dropdown to orders list.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_whatsapp_filter_dropdown( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$current = isset( $_GET['wch_source'] ) ? $_GET['wch_source'] : '';

		?>
		<select name="wch_source" id="wch_source">
			<option value=""><?php esc_html_e( 'All Orders', 'whatsapp-commerce-hub' ); ?></option>
			<option value="whatsapp" <?php selected( $current, 'whatsapp' ); ?>><?php esc_html_e( 'WhatsApp Orders', 'whatsapp-commerce-hub' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Filter orders by WhatsApp source.
	 *
	 * @param array $vars Query vars.
	 * @return array Modified query vars.
	 */
	public function filter_orders_by_whatsapp( $vars ) {
		global $typenow;

		if ( 'shop_order' === $typenow && isset( $_GET['wch_source'] ) && 'whatsapp' === $_GET['wch_source'] ) {
			$vars['meta_query'] = array(
				array(
					'key'     => '_wch_order',
					'value'   => true,
					'compare' => '=',
				),
			);
		}

		return $vars;
	}

	/**
	 * Add WhatsApp metabox to order page.
	 */
	public function add_whatsapp_metabox() {
		add_meta_box(
			'wch_order_whatsapp',
			__( 'WhatsApp Commerce Hub', 'whatsapp-commerce-hub' ),
			array( $this, 'render_whatsapp_metabox' ),
			'shop_order',
			'side',
			'high'
		);
	}

	/**
	 * Render WhatsApp metabox content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_whatsapp_metabox( $post ) {
		$order = wc_get_order( $post->ID );

		if ( ! $order || ! $order->get_meta( '_wch_order' ) ) {
			echo '<p>' . esc_html__( 'This is not a WhatsApp order.', 'whatsapp-commerce-hub' ) . '</p>';
			return;
		}

		$customer_phone  = $order->get_meta( '_wch_customer_phone' );
		$conversation_id = $order->get_meta( '_wch_conversation_id' );

		?>
		<div class="wch-order-metabox">
			<p>
				<strong><?php esc_html_e( 'Customer Phone:', 'whatsapp-commerce-hub' ); ?></strong><br>
				<?php echo esc_html( $customer_phone ); ?>
			</p>

			<?php if ( $conversation_id ) : ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-conversations&id=' . $conversation_id ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'View Conversation', 'whatsapp-commerce-hub' ); ?>
				</a>
			</p>
			<?php endif; ?>

			<p>
				<button type="button" class="button button-primary wch-quick-reply" data-phone="<?php echo esc_attr( $customer_phone ); ?>" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
					<?php esc_html_e( 'Quick Reply', 'whatsapp-commerce-hub' ); ?>
				</button>
			</p>

			<hr>

			<p>
				<strong><?php esc_html_e( 'Tracking Info:', 'whatsapp-commerce-hub' ); ?></strong>
			</p>

			<?php
			$tracking_number = $order->get_meta( '_wch_tracking_number' );
			$carrier         = $order->get_meta( '_wch_carrier' );
			?>

			<p>
				<label for="wch_tracking_number"><?php esc_html_e( 'Tracking Number:', 'whatsapp-commerce-hub' ); ?></label><br>
				<input type="text" id="wch_tracking_number" name="wch_tracking_number" value="<?php echo esc_attr( $tracking_number ); ?>" class="widefat">
			</p>

			<p>
				<label for="wch_carrier"><?php esc_html_e( 'Carrier:', 'whatsapp-commerce-hub' ); ?></label><br>
				<input type="text" id="wch_carrier" name="wch_carrier" value="<?php echo esc_attr( $carrier ); ?>" class="widefat">
			</p>

			<p>
				<button type="button" class="button button-secondary wch-save-tracking" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
					<?php esc_html_e( 'Save Tracking Info', 'whatsapp-commerce-hub' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'post.php' !== $hook && 'edit.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'shop_order' !== $screen->id ) {
			return;
		}

		wp_enqueue_script(
			'wch-order-admin',
			WCH_PLUGIN_URL . 'assets/js/wch-order-admin.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-order-admin',
			'wchOrderAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wch_order_admin' ),
			)
		);
	}

	/**
	 * Add quick reply modal to admin footer.
	 */
	public function add_quick_reply_modal() {
		$screen = get_current_screen();
		if ( ! $screen || 'shop_order' !== $screen->id ) {
			return;
		}

		?>
		<div id="wch-quick-reply-modal" style="display: none;">
			<div class="wch-modal-overlay">
				<div class="wch-modal-content">
					<h2><?php esc_html_e( 'Quick Reply', 'whatsapp-commerce-hub' ); ?></h2>
					<p>
						<label for="wch-quick-message"><?php esc_html_e( 'Message:', 'whatsapp-commerce-hub' ); ?></label><br>
						<textarea id="wch-quick-message" rows="5" class="widefat"></textarea>
					</p>
					<p>
						<button type="button" class="button button-primary wch-send-message"><?php esc_html_e( 'Send', 'whatsapp-commerce-hub' ); ?></button>
						<button type="button" class="button wch-close-modal"><?php esc_html_e( 'Cancel', 'whatsapp-commerce-hub' ); ?></button>
					</p>
				</div>
			</div>
		</div>

		<style>
			.wch-modal-overlay {
				position: fixed;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background: rgba(0, 0, 0, 0.7);
				display: flex;
				align-items: center;
				justify-content: center;
				z-index: 100000;
			}
			.wch-modal-content {
				background: #fff;
				padding: 20px;
				border-radius: 5px;
				max-width: 500px;
				width: 90%;
			}
		</style>
		<?php
	}

	/**
	 * AJAX handler for sending quick message.
	 */
	public function ajax_send_quick_message() {
		check_ajax_referer( 'wch_order_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$phone    = sanitize_text_field( $_POST['phone'] ?? '' );
		$order_id = intval( $_POST['order_id'] ?? 0 );
		$message  = sanitize_textarea_field( $_POST['message'] ?? '' );

		if ( empty( $phone ) || empty( $message ) ) {
			wp_send_json_error( 'Missing required parameters' );
		}

		// TODO: Implement actual message sending when WhatsApp API integration is complete.
		// For now, just log the attempt.
		WCH_Logger::info(
			'Quick message send requested',
			'order-sync',
			array(
				'phone'    => $phone,
				'order_id' => $order_id,
				'message'  => $message,
			)
		);

		wp_send_json_success( array( 'message' => 'Message queued for sending' ) );
	}

	/**
	 * AJAX handler for saving tracking information.
	 */
	public function ajax_save_tracking_info() {
		check_ajax_referer( 'wch_order_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$order_id        = intval( $_POST['order_id'] ?? 0 );
		$tracking_number = sanitize_text_field( $_POST['tracking_number'] ?? '' );
		$carrier         = sanitize_text_field( $_POST['carrier'] ?? '' );

		if ( ! $order_id || empty( $tracking_number ) || empty( $carrier ) ) {
			wp_send_json_error( 'Missing required parameters' );
		}

		$result = $this->add_tracking_info( $order_id, $tracking_number, $carrier );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message' => 'Tracking information saved',
					'note'    => true,
				)
			);
		} else {
			wp_send_json_error( 'Failed to save tracking information' );
		}
	}
}
