<?php
/**
 * Customer Re-engagement Service Class
 *
 * Handles automated re-engagement campaigns for inactive customers.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Reengagement_Service
 */
class WCH_Reengagement_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Reengagement_Service|null
	 */
	private static $instance = null;

	/**
	 * Settings instance.
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Database instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Database manager instance.
	 *
	 * @var WCH_Database_Manager
	 */
	private $db_manager;

	/**
	 * WhatsApp API client.
	 *
	 * @var WCH_WhatsApp_API_Client
	 */
	private $api_client;

	/**
	 * Customer service instance.
	 *
	 * @var WCH_Customer_Service
	 */
	private $customer_service;

	/**
	 * Campaign types.
	 *
	 * @var array
	 */
	const CAMPAIGN_TYPES = array(
		'we_miss_you'    => 'Generic re-engagement',
		'new_arrivals'   => 'New products since last visit',
		'back_in_stock'  => 'Previously viewed items back in stock',
		'price_drop'     => 'Price drops on viewed products',
		'loyalty_reward' => 'Discount based on lifetime value',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WCH_Reengagement_Service
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
		global $wpdb;
		$this->wpdb             = $wpdb;
		$this->settings         = WCH_Settings::getInstance();
		$this->db_manager       = WCH_Database_Manager::instance();
		$this->customer_service = WCH_Customer_Service::instance();

		// Initialize API client if needed.
		add_action( 'init', array( $this, 'init_api_client' ) );
	}

	/**
	 * Initialize WhatsApp API client.
	 */
	public function init_api_client() {
		$phone_number_id = $this->settings->get( 'api.phone_number_id' );
		$access_token    = $this->settings->get( 'api.access_token' );
		$api_version     = $this->settings->get( 'api.version', 'v18.0' );

		if ( $phone_number_id && $access_token ) {
			try {
				$this->api_client = new WCH_WhatsApp_API_Client(
					array(
						'phone_number_id' => $phone_number_id,
						'access_token'    => $access_token,
						'api_version'     => $api_version,
					)
				);
			} catch ( Exception $e ) {
				WCH_Logger::error(
					'Failed to initialize WhatsApp API client for re-engagement',
					array( 'error' => $e->getMessage() )
				);
			}
		}
	}

	/**
	 * Initialize re-engagement system.
	 *
	 * Sets up scheduled tasks.
	 */
	public function init() {
		// Schedule daily task to identify and engage inactive customers.
		if ( ! as_next_scheduled_action( 'wch_process_reengagement_campaigns', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				strtotime( 'tomorrow 9:00am' ),
				DAY_IN_SECONDS,
				'wch_process_reengagement_campaigns',
				array(),
				'wch'
			);
		}

		// Schedule hourly task to check for back-in-stock notifications.
		if ( ! as_next_scheduled_action( 'wch_check_back_in_stock', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_check_back_in_stock',
				array(),
				'wch'
			);
		}

		// Schedule hourly task to check for price drops.
		if ( ! as_next_scheduled_action( 'wch_check_price_drops', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_check_price_drops',
				array(),
				'wch'
			);
		}
	}

	/**
	 * Process re-engagement campaigns.
	 *
	 * Main scheduled task that runs daily.
	 */
	public static function process_reengagement_campaigns() {
		$instance = self::instance();

		if ( ! $instance->is_reengagement_enabled() ) {
			return;
		}

		WCH_Logger::info(
			'Processing re-engagement campaigns',
			'reengagement'
		);

		// Identify inactive customers.
		$inactive_customers = $instance->identify_inactive_customers();

		WCH_Logger::info(
			'Found inactive customers',
			'reengagement',
			array( 'count' => count( $inactive_customers ) )
		);

		// Process each inactive customer.
		foreach ( $inactive_customers as $customer ) {
			$instance->queue_reengagement_message( $customer );
		}
	}

	/**
	 * Identify inactive customers.
	 *
	 * Query customers who:
	 * - Have made at least one purchase
	 * - No orders in X days (configurable)
	 * - Opted in to marketing
	 * - Haven't been messaged in the last 7 days
	 *
	 * @return array Array of customer data.
	 */
	public function identify_inactive_customers() {
		$inactivity_threshold = $this->get_inactivity_threshold();
		$threshold_date       = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $inactivity_threshold * DAY_IN_SECONDS ) );
		$recent_message_date  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 7 * DAY_IN_SECONDS ) );

		$profiles_table     = $this->db_manager->get_table_name( 'customer_profiles' );
		$reengagement_table = $this->db_manager->get_table_name( 'reengagement_log' );

		// Find customers with linked WC accounts who are opted in to marketing.
		$query = "
			SELECT p.*,
				(SELECT MAX(post_date)
				 FROM {$this->wpdb->posts} posts
				 INNER JOIN {$this->wpdb->postmeta} meta ON posts.ID = meta.post_id
				 WHERE posts.post_type = 'shop_order'
				 AND meta.meta_key = '_customer_user'
				 AND meta.meta_value = p.wc_customer_id
				 AND posts.post_status IN ('wc-completed', 'wc-processing')
				) as last_order_date,
				(SELECT COUNT(*)
				 FROM {$this->wpdb->posts} posts
				 INNER JOIN {$this->wpdb->postmeta} meta ON posts.ID = meta.post_id
				 WHERE posts.post_type = 'shop_order'
				 AND meta.meta_key = '_customer_user'
				 AND meta.meta_value = p.wc_customer_id
				 AND posts.post_status IN ('wc-completed', 'wc-processing')
				) as total_orders
			FROM {$profiles_table} p
			WHERE p.wc_customer_id IS NOT NULL
			AND p.opt_in_marketing = 1
			HAVING total_orders > 0
			AND last_order_date IS NOT NULL
			AND last_order_date < %s
			AND (
				p.phone NOT IN (
					SELECT customer_phone
					FROM {$reengagement_table}
					WHERE sent_at > %s
				)
			)
		";

		$customers = $this->wpdb->get_results(
			$this->wpdb->prepare( $query, $threshold_date, $recent_message_date ),
			ARRAY_A
		);

		return $customers ?: array();
	}

	/**
	 * Queue re-engagement message for a customer.
	 *
	 * @param array $customer Customer data.
	 */
	private function queue_reengagement_message( $customer ) {
		// Determine best campaign type for this customer.
		$campaign_type = $this->determine_campaign_type( $customer );

		// Schedule the message to be sent.
		WCH_Job_Dispatcher::dispatch(
			'wch_send_reengagement_message',
			array(
				'customer_phone' => $customer['phone'],
				'campaign_type'  => $campaign_type,
			),
			0
		);

		WCH_Logger::debug(
			'Queued re-engagement message',
			array(
				'phone'         => $customer['phone'],
				'campaign_type' => $campaign_type,
			)
		);
	}

	/**
	 * Determine the best campaign type for a customer.
	 *
	 * @param array $customer Customer data.
	 * @return string Campaign type.
	 */
	private function determine_campaign_type( $customer ) {
		// Check for back-in-stock items.
		if ( $this->has_back_in_stock_items( $customer['phone'] ) ) {
			return 'back_in_stock';
		}

		// Check for price drops.
		if ( $this->has_price_drops( $customer['phone'] ) ) {
			return 'price_drop';
		}

		// Check if customer has high lifetime value for loyalty reward.
		$stats   = $this->customer_service->calculate_customer_stats( $customer['phone'] );
		$min_ltv = $this->settings->get( 'reengagement.loyalty_min_ltv', 500 );

		if ( ! empty( $stats['total_spent'] ) && $stats['total_spent'] >= $min_ltv ) {
			return 'loyalty_reward';
		}

		// Check for new arrivals in categories they've purchased from.
		if ( $this->has_new_arrivals_for_customer( $customer ) ) {
			return 'new_arrivals';
		}

		// Default to generic re-engagement.
		return 'we_miss_you';
	}

	/**
	 * Send re-engagement message.
	 *
	 * @param array $args Job arguments.
	 */
	public static function send_reengagement_message( $args ) {
		$instance = self::instance();

		$customer_phone = $args['customer_phone'] ?? null;
		$campaign_type  = $args['campaign_type'] ?? 'we_miss_you';

		if ( ! $customer_phone ) {
			WCH_Logger::error(
				'Missing customer phone in re-engagement job',
				array( 'args' => $args )
			);
			return;
		}

		// Get customer profile.
		$customer = $instance->customer_service->get_or_create_profile( $customer_phone );
		if ( ! $customer ) {
			WCH_Logger::error(
				'Customer profile not found for re-engagement',
				array( 'phone' => $customer_phone )
			);
			return;
		}

		// Check frequency cap.
		if ( ! $instance->check_frequency_cap( $customer_phone ) ) {
			WCH_Logger::info(
				'Skipping re-engagement due to frequency cap',
				array( 'phone' => $customer_phone )
			);
			return;
		}

		// Send the message.
		$result = $instance->send_campaign_message( $customer, $campaign_type );

		if ( $result['success'] ) {
			// Log the sent message.
			$instance->log_reengagement_message( $customer_phone, $campaign_type, $result );

			WCH_Logger::info(
				'Re-engagement message sent successfully',
				array(
					'phone'         => $customer_phone,
					'campaign_type' => $campaign_type,
				)
			);
		} else {
			WCH_Logger::error(
				'Failed to send re-engagement message',
				array(
					'phone'         => $customer_phone,
					'campaign_type' => $campaign_type,
					'error'         => $result['error'] ?? 'Unknown error',
				)
			);
		}
	}

	/**
	 * Send campaign message via WhatsApp.
	 *
	 * @param WCH_Customer_Profile $customer Customer profile.
	 * @param string               $campaign_type Campaign type.
	 * @return array Result with 'success' key.
	 */
	private function send_campaign_message( $customer, $campaign_type ) {
		if ( ! $this->api_client ) {
			return array(
				'success' => false,
				'error'   => 'WhatsApp API client not initialized',
			);
		}

		// Build message content based on campaign type.
		$message_data = $this->build_campaign_message( $customer, $campaign_type );

		if ( ! $message_data ) {
			return array(
				'success' => false,
				'error'   => 'Failed to build message content',
			);
		}

		try {
			// Send text message with personalization.
			$result = $this->api_client->send_text_message(
				$customer->phone,
				$message_data['text'],
				true
			);

			return array(
				'success'    => true,
				'message_id' => $result['message_id'] ?? null,
			);
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Build campaign message content.
	 *
	 * @param WCH_Customer_Profile $customer Customer profile.
	 * @param string               $campaign_type Campaign type.
	 * @return array|null Message data or null on failure.
	 */
	private function build_campaign_message( $customer, $campaign_type ) {
		$customer_name = $customer->name ?: 'Customer';
		$stats         = $this->customer_service->calculate_customer_stats( $customer->phone );

		switch ( $campaign_type ) {
			case 'we_miss_you':
				$last_product = $this->get_last_purchased_product( $customer );
				$text         = sprintf(
					"Hi %s! We haven't seen you in a while and we miss you! 😊\n\nWe have some exciting new products you might love. Check them out: %s",
					$customer_name,
					home_url( '/shop' )
				);
				break;

			case 'new_arrivals':
				$new_products = $this->get_new_arrivals_for_customer( $customer );
				$text         = sprintf(
					"Hi %s! 🎉 We've added new products based on your interests:\n\n%s\n\nBrowse all new arrivals: %s",
					$customer_name,
					$this->format_product_list( $new_products ),
					home_url( '/shop' )
				);
				break;

			case 'back_in_stock':
				$products = $this->get_back_in_stock_products( $customer->phone );
				$text     = sprintf(
					"Great news, %s! 🎊 Products you were interested in are back in stock:\n\n%s\n\nShop now: %s",
					$customer_name,
					$this->format_product_list( $products ),
					home_url( '/shop' )
				);
				break;

			case 'price_drop':
				$products = $this->get_price_drop_products( $customer->phone );
				$text     = sprintf(
					"Special alert for %s! 💰 Price drops on products you viewed:\n\n%s\n\nDon't miss out: %s",
					$customer_name,
					$this->format_product_list( $products, true ),
					home_url( '/shop' )
				);
				break;

			case 'loyalty_reward':
				$discount_code   = $this->generate_loyalty_discount( $customer );
				$discount_amount = $this->settings->get( 'reengagement.loyalty_discount', 15 );
				$text            = sprintf(
					"Hi %s! 🌟 Thank you for being a valued customer!\n\nAs a token of our appreciation, here's an exclusive %d%% discount code: *%s*\n\nValid for 7 days. Shop now: %s",
					$customer_name,
					$discount_amount,
					$discount_code,
					home_url( '/shop' )
				);
				break;

			default:
				return null;
		}

		return array(
			'text' => $text,
			'type' => $campaign_type,
		);
	}

	/**
	 * Get last purchased product for customer.
	 *
	 * @param WCH_Customer_Profile $customer Customer profile.
	 * @return array|null Product data or null.
	 */
	private function get_last_purchased_product( $customer ) {
		if ( ! $customer->wc_customer_id ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer->wc_customer_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array( 'completed', 'processing' ),
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		$order = $orders[0];
		$items = $order->get_items();

		if ( empty( $items ) ) {
			return null;
		}

		$item = reset( $items );
		return array(
			'id'   => $item->get_product_id(),
			'name' => $item->get_name(),
		);
	}

	/**
	 * Get new arrivals for customer based on purchase history.
	 *
	 * @param WCH_Customer_Profile $customer Customer profile.
	 * @return array Array of product data.
	 */
	private function get_new_arrivals_for_customer( $customer ) {
		// Get categories from customer's purchase history.
		$purchased_categories = $this->get_customer_categories( $customer );

		if ( empty( $purchased_categories ) ) {
			// Return general new arrivals.
			return $this->get_recent_products( 3 );
		}

		// Get new products from those categories.
		$days_inactive = $this->get_inactivity_threshold();
		$since_date    = gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( $days_inactive * DAY_IN_SECONDS ) );

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => 3,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array(
				array(
					'after' => $since_date,
				),
			),
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $purchased_categories,
				),
			),
		);

		$products = get_posts( $args );

		return array_map(
			function ( $product ) {
				$wc_product = wc_get_product( $product->ID );
				return array(
					'id'    => $product->ID,
					'name'  => $product->post_title,
					'price' => $wc_product ? $wc_product->get_price() : 0,
					'url'   => get_permalink( $product->ID ),
				);
			},
			$products
		);
	}

	/**
	 * Check if customer has new arrivals.
	 *
	 * @param array $customer Customer data.
	 * @return bool True if has new arrivals.
	 */
	private function has_new_arrivals_for_customer( $customer ) {
		$profile  = $this->customer_service->get_or_create_profile( $customer['phone'] );
		$products = $this->get_new_arrivals_for_customer( $profile );
		return ! empty( $products );
	}

	/**
	 * Get customer's purchased categories.
	 *
	 * @param WCH_Customer_Profile $customer Customer profile.
	 * @return array Array of category IDs.
	 */
	private function get_customer_categories( $customer ) {
		if ( ! $customer->wc_customer_id ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer->wc_customer_id,
				'limit'       => -1,
				'status'      => array( 'completed', 'processing' ),
			)
		);

		$categories = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product ) {
					$terms = get_the_terms( $product->get_id(), 'product_cat' );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$categories[] = $term->term_id;
						}
					}
				}
			}
		}

		return array_unique( $categories );
	}

	/**
	 * Get recent products.
	 *
	 * @param int $limit Number of products to return.
	 * @return array Array of product data.
	 */
	private function get_recent_products( $limit = 3 ) {
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$products = get_posts( $args );

		return array_map(
			function ( $product ) {
				$wc_product = wc_get_product( $product->ID );
				return array(
					'id'    => $product->ID,
					'name'  => $product->post_title,
					'price' => $wc_product ? $wc_product->get_price() : 0,
					'url'   => get_permalink( $product->ID ),
				);
			},
			$products
		);
	}

	/**
	 * Track product view for back-in-stock and price drop notifications.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param int    $product_id Product ID.
	 */
	public function track_product_view( $customer_phone, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$table_name = $this->db_manager->get_table_name( 'product_views' );

		// Check if already tracked recently (within last hour).
		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table_name}
				WHERE customer_phone = %s
				AND product_id = %d
				AND viewed_at > %s",
				$customer_phone,
				$product_id,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS )
			)
		);

		if ( $existing ) {
			return;
		}

		// Insert new view record.
		$this->wpdb->insert(
			$table_name,
			array(
				'customer_phone' => $customer_phone,
				'product_id'     => $product_id,
				'price_at_view'  => $product->get_price(),
				'in_stock'       => $product->is_in_stock(),
				'viewed_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%f', '%d', '%s' )
		);
	}

	/**
	 * Check for back-in-stock items for a customer.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return bool True if has back-in-stock items.
	 */
	private function has_back_in_stock_items( $customer_phone ) {
		$products = $this->get_back_in_stock_products( $customer_phone );
		return ! empty( $products );
	}

	/**
	 * Get back-in-stock products for customer.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return array Array of product data.
	 */
	private function get_back_in_stock_products( $customer_phone ) {
		$table_name = $this->db_manager->get_table_name( 'product_views' );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DISTINCT product_id
				FROM {$table_name}
				WHERE customer_phone = %s
				AND in_stock = 0
				AND viewed_at > %s",
				$customer_phone,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		);

		$products = array();

		foreach ( $results as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( $product && $product->is_in_stock() ) {
				$products[] = array(
					'id'    => $product->get_id(),
					'name'  => $product->get_name(),
					'price' => $product->get_price(),
					'url'   => $product->get_permalink(),
				);
			}
		}

		return $products;
	}

	/**
	 * Check back-in-stock notifications.
	 *
	 * Scheduled task to check for products that are back in stock.
	 */
	public static function check_back_in_stock() {
		$instance = self::instance();

		if ( ! $instance->is_reengagement_enabled() ) {
			return;
		}

		// Find products that recently came back in stock.
		$table_name = $instance->db_manager->get_table_name( 'product_views' );

		// Get distinct product IDs that were out of stock.
		$products = $instance->wpdb->get_results(
			"SELECT DISTINCT product_id
			FROM {$table_name}
			WHERE in_stock = 0",
			ARRAY_A
		);

		foreach ( $products as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( $product && $product->is_in_stock() ) {
				// Product is back in stock, notify interested customers.
				$instance->notify_back_in_stock( $row['product_id'] );
			}
		}
	}

	/**
	 * Notify customers interested in a product that's back in stock.
	 *
	 * @param int $product_id Product ID.
	 */
	private function notify_back_in_stock( $product_id ) {
		$table_name = $this->db_manager->get_table_name( 'product_views' );

		// Get customers who viewed this product when it was out of stock.
		$customers = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DISTINCT customer_phone
				FROM {$table_name}
				WHERE product_id = %d
				AND in_stock = 0",
				$product_id
			),
			ARRAY_A
		);

		foreach ( $customers as $row ) {
			// Check frequency cap.
			if ( ! $this->check_frequency_cap( $row['customer_phone'] ) ) {
				continue;
			}

			// Queue back-in-stock notification.
			WCH_Job_Dispatcher::dispatch(
				'wch_send_reengagement_message',
				array(
					'customer_phone' => $row['customer_phone'],
					'campaign_type'  => 'back_in_stock',
				),
				0
			);
		}

		// Update the view records.
		$this->wpdb->update(
			$table_name,
			array( 'in_stock' => 1 ),
			array( 'product_id' => $product_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Check for price drops.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return bool True if has price drops.
	 */
	private function has_price_drops( $customer_phone ) {
		$products = $this->get_price_drop_products( $customer_phone );
		return ! empty( $products );
	}

	/**
	 * Get products with price drops for customer.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return array Array of product data.
	 */
	private function get_price_drop_products( $customer_phone ) {
		$table_name = $this->db_manager->get_table_name( 'product_views' );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT product_id, price_at_view
				FROM {$table_name}
				WHERE customer_phone = %s
				AND viewed_at > %s",
				$customer_phone,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		);

		$products         = array();
		$min_drop_percent = 10; // 10% minimum price drop.

		foreach ( $results as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$current_price = floatval( $product->get_price() );
			$old_price     = floatval( $row['price_at_view'] );

			if ( $old_price > 0 && $current_price > 0 ) {
				$drop_percent = ( ( $old_price - $current_price ) / $old_price ) * 100;

				if ( $drop_percent >= $min_drop_percent ) {
					$products[] = array(
						'id'        => $product->get_id(),
						'name'      => $product->get_name(),
						'old_price' => $old_price,
						'price'     => $current_price,
						'drop'      => round( $drop_percent, 0 ),
						'url'       => $product->get_permalink(),
					);
				}
			}
		}

		return $products;
	}

	/**
	 * Check for price drops.
	 *
	 * Scheduled task.
	 */
	public static function check_price_drops() {
		$instance = self::instance();

		if ( ! $instance->is_reengagement_enabled() ) {
			return;
		}

		// This is handled inline when sending price_drop campaigns.
		// No separate action needed as we check on-demand.
	}

	/**
	 * Format product list for message.
	 *
	 * @param array $products Array of products.
	 * @param bool  $show_price_drop Whether to show price drop info.
	 * @return string Formatted product list.
	 */
	private function format_product_list( $products, $show_price_drop = false ) {
		if ( empty( $products ) ) {
			return '';
		}

		$lines = array();
		foreach ( array_slice( $products, 0, 3 ) as $product ) {
			if ( $show_price_drop && isset( $product['drop'] ) ) {
				$lines[] = sprintf(
					'• %s - %s%% OFF! Now: %s',
					$product['name'],
					$product['drop'],
					wc_price( $product['price'] )
				);
			} else {
				$lines[] = sprintf(
					'• %s - %s',
					$product['name'],
					wc_price( $product['price'] )
				);
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Generate loyalty discount coupon.
	 *
	 * @param WCH_Customer_Profile $customer Customer profile.
	 * @return string|null Coupon code or null.
	 */
	private function generate_loyalty_discount( $customer ) {
		$discount_amount = $this->settings->get( 'reengagement.loyalty_discount', 15 );
		$coupon_code     = 'LOYAL' . strtoupper( substr( md5( $customer->phone . time() ), 0, 8 ) );

		// Create WooCommerce coupon.
		$coupon = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $discount_amount );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_date_expires( strtotime( '+7 days' ) );

		if ( $customer->wc_customer_id ) {
			$wc_customer = new WC_Customer( $customer->wc_customer_id );
			$email       = $wc_customer->get_email();
			if ( $email ) {
				$coupon->set_email_restrictions( array( $email ) );
			}
		}

		try {
			$coupon->save();
			return $coupon_code;
		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Failed to create loyalty discount coupon',
				array(
					'phone' => $customer->phone,
					'error' => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Check frequency cap for customer.
	 *
	 * Max 1 message per 7 days, max 4 per month.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return bool True if can send, false otherwise.
	 */
	private function check_frequency_cap( $customer_phone ) {
		$table_name = $this->db_manager->get_table_name( 'reengagement_log' );

		// Check last 7 days.
		$count_week = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE customer_phone = %s
				AND sent_at > %s",
				$customer_phone,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 7 * DAY_IN_SECONDS ) )
			)
		);

		if ( $count_week >= 1 ) {
			return false;
		}

		// Check last 30 days.
		$count_month = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name}
				WHERE customer_phone = %s
				AND sent_at > %s",
				$customer_phone,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) )
			)
		);

		if ( $count_month >= 4 ) {
			return false;
		}

		return true;
	}

	/**
	 * Log re-engagement message.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $campaign_type Campaign type.
	 * @param array  $result Send result.
	 */
	private function log_reengagement_message( $customer_phone, $campaign_type, $result ) {
		$table_name = $this->db_manager->get_table_name( 'reengagement_log' );

		$this->wpdb->insert(
			$table_name,
			array(
				'customer_phone' => $customer_phone,
				'campaign_type'  => $campaign_type,
				'message_id'     => $result['message_id'] ?? null,
				'status'         => 'sent',
				'sent_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Track campaign conversion.
	 *
	 * Called when customer makes a purchase after receiving re-engagement message.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param int    $order_id Order ID.
	 */
	public function track_conversion( $customer_phone, $order_id ) {
		$table_name = $this->db_manager->get_table_name( 'reengagement_log' );

		// Find the most recent re-engagement message sent to this customer.
		$log_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table_name}
				WHERE customer_phone = %s
				AND sent_at > %s
				ORDER BY sent_at DESC
				LIMIT 1",
				$customer_phone,
				gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) )
			)
		);

		if ( $log_id ) {
			$this->wpdb->update(
				$table_name,
				array(
					'converted'    => 1,
					'order_id'     => $order_id,
					'converted_at' => current_time( 'mysql' ),
				),
				array( 'id' => $log_id ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);

			WCH_Logger::info(
				'Re-engagement conversion tracked',
				array(
					'phone'    => $customer_phone,
					'order_id' => $order_id,
					'log_id'   => $log_id,
				)
			);
		}
	}

	/**
	 * Get re-engagement analytics.
	 *
	 * @param int $days Number of days to analyze.
	 * @return array Analytics data by campaign type.
	 */
	public function get_analytics( $days = 30 ) {
		$table_name = $this->db_manager->get_table_name( 'reengagement_log' );
		$since_date = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT campaign_type,
					COUNT(*) as sent,
					SUM(CASE WHEN status = 'delivered' OR status = 'read' THEN 1 ELSE 0 END) as delivered,
					SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as opened,
					SUM(converted) as converted
				FROM {$table_name}
				WHERE sent_at >= %s
				GROUP BY campaign_type",
				$since_date
			),
			ARRAY_A
		);

		$analytics = array();

		foreach ( $results as $row ) {
			$sent                               = intval( $row['sent'] );
			$analytics[ $row['campaign_type'] ] = array(
				'sent'            => $sent,
				'delivered'       => intval( $row['delivered'] ),
				'opened'          => intval( $row['opened'] ),
				'converted'       => intval( $row['converted'] ),
				'conversion_rate' => $sent > 0 ? round( ( intval( $row['converted'] ) / $sent ) * 100, 2 ) : 0,
			);
		}

		return $analytics;
	}

	/**
	 * Check if re-engagement is enabled.
	 *
	 * @return bool True if enabled.
	 */
	private function is_reengagement_enabled() {
		return (bool) $this->settings->get( 'reengagement.enabled', false );
	}

	/**
	 * Get inactivity threshold in days.
	 *
	 * @return int Number of days.
	 */
	private function get_inactivity_threshold() {
		return (int) $this->settings->get( 'reengagement.inactivity_threshold', 60 );
	}
}
