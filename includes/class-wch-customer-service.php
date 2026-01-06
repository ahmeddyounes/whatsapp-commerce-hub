<?php
/**
 * Customer Service Class
 *
 * Handles customer profile management linking WhatsApp users to WooCommerce customers.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Customer_Service
 */
class WCH_Customer_Service {
	/**
	 * Singleton instance.
	 *
	 * @var WCH_Customer_Service
	 */
	private static $instance = null;

	/**
	 * Global wpdb instance.
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
	 * Get singleton instance.
	 *
	 * @return WCH_Customer_Service
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
		$this->wpdb       = $wpdb;
		$this->db_manager = new WCH_Database_Manager();
	}

	/**
	 * Normalize phone number to E.164 format.
	 *
	 * @param string $phone Phone number.
	 * @return string Normalized phone number.
	 */
	private function normalize_phone( $phone ) {
		// Remove all non-numeric characters.
		$phone = preg_replace( '/[^0-9+]/', '', $phone );

		// If it doesn't start with +, add it.
		if ( substr( $phone, 0, 1 ) !== '+' ) {
			$phone = '+' . $phone;
		}

		return $phone;
	}

	/**
	 * Get or create customer profile.
	 *
	 * @param string $phone Phone number (will be normalized to E.164).
	 * @return WCH_Customer_Profile|null Customer profile object or null on error.
	 */
	public function get_or_create_profile( $phone ) {
		// Normalize phone to E.164.
		$phone = $this->normalize_phone( $phone );

		// Check if profile exists.
		$table_name = $this->db_manager->get_table_name( 'customer_profiles' );
		$row        = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE phone = %s",
				$phone
			),
			ARRAY_A
		);

		// If profile exists, return it.
		if ( $row ) {
			return $this->row_to_profile( $row );
		}

		// Create new profile.
		$now = current_time( 'mysql' );
		$this->wpdb->insert(
			$table_name,
			array(
				'phone'            => $phone,
				'wc_customer_id'   => null,
				'name'             => '',
				'saved_addresses'  => wp_json_encode( array() ),
				'preferences'      => wp_json_encode( array() ),
				'opt_in_marketing' => 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		// Retrieve the newly created profile.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE phone = %s",
				$phone
			),
			ARRAY_A
		);

		if ( $row ) {
			WCH_Logger::info(
				'Created new customer profile',
				'customer-service',
				array( 'phone' => $phone )
			);
			return $this->row_to_profile( $row );
		}

		return null;
	}

	/**
	 * Link WhatsApp profile to WooCommerce customer.
	 *
	 * @param string $phone          Phone number.
	 * @param int    $wc_customer_id WooCommerce customer ID.
	 * @return bool Success status.
	 */
	public function link_to_wc_customer( $phone, $wc_customer_id ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Get or create profile.
		$profile = $this->get_or_create_profile( $phone );
		if ( ! $profile ) {
			return false;
		}

		// Update with WC customer ID.
		$table_name = $this->db_manager->get_table_name( 'customer_profiles' );
		$result     = $this->wpdb->update(
			$table_name,
			array(
				'wc_customer_id' => $wc_customer_id,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'phone' => $phone ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		if ( false !== $result ) {
			WCH_Logger::info(
				'Linked customer profile to WooCommerce customer',
				'customer-service',
				array(
					'phone'          => $phone,
					'wc_customer_id' => $wc_customer_id,
				)
			);
			return true;
		}

		return false;
	}

	/**
	 * Find WooCommerce customer by phone number.
	 *
	 * Searches for WC customers by billing_phone meta with format variations.
	 *
	 * @param string $phone Phone number.
	 * @return int|null WooCommerce customer ID or null if not found.
	 */
	public function find_wc_customer_by_phone( $phone ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Generate phone variations.
		$variations = $this->get_phone_variations( $phone );

		// Search for customer with matching billing phone.
		foreach ( $variations as $variation ) {
			$customer_id = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT user_id FROM {$this->wpdb->usermeta}
					WHERE meta_key = 'billing_phone'
					AND meta_value = %s
					LIMIT 1",
					$variation
				)
			);

			if ( $customer_id ) {
				return intval( $customer_id );
			}
		}

		return null;
	}

	/**
	 * Generate phone number variations.
	 *
	 * @param string $phone Phone number in E.164 format.
	 * @return array Array of phone variations.
	 */
	private function get_phone_variations( $phone ) {
		$variations = array( $phone );

		// Remove + prefix.
		if ( substr( $phone, 0, 1 ) === '+' ) {
			$without_plus = substr( $phone, 1 );
			$variations[] = $without_plus;

			// If starts with country code (e.g., +1), try without it.
			if ( strlen( $without_plus ) > 10 ) {
				// Try removing first 1-3 digits as country code.
				for ( $i = 1; $i <= 3; $i++ ) {
					$variations[] = substr( $without_plus, $i );
				}
			}
		}

		return array_unique( $variations );
	}

	/**
	 * Save customer address.
	 *
	 * @param string $phone        Phone number.
	 * @param array  $address_data Address data.
	 * @param bool   $is_default   Mark as default address.
	 * @return bool Success status.
	 */
	public function save_address( $phone, $address_data, $is_default = false ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Validate address fields.
		$required_fields = array( 'address_1', 'city', 'postcode', 'country' );
		foreach ( $required_fields as $field ) {
			if ( empty( $address_data[ $field ] ) ) {
				WCH_Logger::warning(
					'Invalid address data - missing required field',
					'customer-service',
					array(
						'phone'        => $phone,
						'missing_field' => $field,
					)
				);
				return false;
			}
		}

		// Get profile.
		$profile = $this->get_or_create_profile( $phone );
		if ( ! $profile ) {
			return false;
		}

		// Get current saved addresses.
		$saved_addresses = $profile->saved_addresses;
		if ( ! is_array( $saved_addresses ) ) {
			$saved_addresses = array();
		}

		// If marking as default, unset previous defaults.
		if ( $is_default ) {
			foreach ( $saved_addresses as &$addr ) {
				$addr['is_default'] = false;
			}
		}

		// Add new address.
		$address_data['is_default'] = $is_default;
		$address_data['saved_at']   = current_time( 'mysql' );
		$saved_addresses[]          = $address_data;

		// Update profile.
		$table_name = $this->db_manager->get_table_name( 'customer_profiles' );
		$result     = $this->wpdb->update(
			$table_name,
			array(
				'saved_addresses' => wp_json_encode( $saved_addresses ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'phone' => $phone ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		if ( false !== $result ) {
			WCH_Logger::info(
				'Saved customer address',
				'customer-service',
				array(
					'phone'      => $phone,
					'is_default' => $is_default,
				)
			);
			return true;
		}

		return false;
	}

	/**
	 * Get default customer address.
	 *
	 * @param string $phone Phone number.
	 * @return array|null Default address or null.
	 */
	public function get_default_address( $phone ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Get profile.
		$profile = $this->get_or_create_profile( $phone );
		if ( ! $profile ) {
			return null;
		}

		// Find default address.
		$saved_addresses = $profile->saved_addresses;
		if ( is_array( $saved_addresses ) ) {
			foreach ( $saved_addresses as $address ) {
				if ( ! empty( $address['is_default'] ) ) {
					return $address;
				}
			}
		}

		return null;
	}

	/**
	 * Update customer preferences.
	 *
	 * @param string $phone       Phone number.
	 * @param array  $preferences Preferences to update.
	 * @return bool Success status.
	 */
	public function update_preferences( $phone, $preferences ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Get profile.
		$profile = $this->get_or_create_profile( $phone );
		if ( ! $profile ) {
			return false;
		}

		// Merge preferences.
		$current_preferences = $profile->preferences;
		if ( ! is_array( $current_preferences ) ) {
			$current_preferences = array();
		}
		$merged_preferences = array_merge( $current_preferences, $preferences );

		// Update profile.
		$table_name = $this->db_manager->get_table_name( 'customer_profiles' );
		$result     = $this->wpdb->update(
			$table_name,
			array(
				'preferences' => wp_json_encode( $merged_preferences ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'phone' => $phone ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		if ( false !== $result ) {
			WCH_Logger::info(
				'Updated customer preferences',
				'customer-service',
				array(
					'phone'       => $phone,
					'preferences' => $preferences,
				)
			);
			return true;
		}

		return false;
	}

	/**
	 * Get customer order history.
	 *
	 * @param string $phone Phone number.
	 * @return array Array of order data.
	 */
	public function get_order_history( $phone ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Get profile.
		$profile = $this->get_or_create_profile( $phone );
		if ( ! $profile ) {
			return array();
		}

		$orders = array();

		// If linked to WC customer, fetch their orders.
		if ( $profile->wc_customer_id ) {
			$wc_orders = wc_get_orders(
				array(
					'customer_id' => $profile->wc_customer_id,
					'limit'       => -1,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);

			foreach ( $wc_orders as $order ) {
				$orders[] = $this->format_order( $order );
			}
		} else {
			// Fetch orders with matching billing phone.
			$phone_variations = $this->get_phone_variations( $phone );

			foreach ( $phone_variations as $variation ) {
				$wc_orders = wc_get_orders(
					array(
						'meta_key'   => '_billing_phone',
						'meta_value' => $variation,
						'limit'      => -1,
						'orderby'    => 'date',
						'order'      => 'DESC',
					)
				);

				foreach ( $wc_orders as $order ) {
					$order_id = $order->get_id();
					// Avoid duplicates.
					if ( ! isset( $orders[ $order_id ] ) ) {
						$orders[ $order_id ] = $this->format_order( $order );
					}
				}
			}

			// Remove array keys (order IDs used for deduplication).
			$orders = array_values( $orders );
		}

		return $orders;
	}

	/**
	 * Format order data.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Formatted order data.
	 */
	private function format_order( $order ) {
		return array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'status'       => $order->get_status(),
			'total'        => $order->get_total(),
			'currency'     => $order->get_currency(),
			'date'         => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
			'items_count'  => $order->get_item_count(),
		);
	}

	/**
	 * Calculate customer statistics.
	 *
	 * @param string $phone Phone number.
	 * @return array Customer stats.
	 */
	public function calculate_customer_stats( $phone ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Get order history.
		$orders = $this->get_order_history( $phone );

		// Initialize stats.
		$stats = array(
			'total_orders'         => 0,
			'total_spent'          => 0.0,
			'average_order_value'  => 0.0,
			'days_since_last_order' => null,
		);

		if ( empty( $orders ) ) {
			return $stats;
		}

		// Calculate stats.
		$total_spent  = 0.0;
		$last_order   = null;

		foreach ( $orders as $order ) {
			$total_spent += floatval( $order['total'] );

			if ( null === $last_order || strtotime( $order['date'] ) > strtotime( $last_order ) ) {
				$last_order = $order['date'];
			}
		}

		$stats['total_orders'] = count( $orders );
		$stats['total_spent']  = $total_spent;

		if ( $stats['total_orders'] > 0 ) {
			$stats['average_order_value'] = $total_spent / $stats['total_orders'];
		}

		if ( $last_order ) {
			$last_order_time = strtotime( $last_order );
			$current_time    = current_time( 'timestamp' );
			$stats['days_since_last_order'] = floor( ( $current_time - $last_order_time ) / DAY_IN_SECONDS );
		}

		// Update profile with calculated stats.
		$table_name = $this->db_manager->get_table_name( 'customer_profiles' );
		$this->wpdb->update(
			$table_name,
			array(
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'phone' => $phone ),
			array( '%s' ),
			array( '%s' )
		);

		return $stats;
	}

	/**
	 * Export customer data for GDPR compliance.
	 *
	 * @param string $phone Phone number.
	 * @return array Customer data export.
	 */
	public function export_customer_data( $phone ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Get profile.
		$profile = $this->get_or_create_profile( $phone );
		if ( ! $profile ) {
			return array();
		}

		// Get order history.
		$orders = $this->get_order_history( $phone );

		// Get conversations.
		$table_name    = $this->db_manager->get_table_name( 'conversations' );
		$conversations = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE customer_phone = %s",
				$phone
			),
			ARRAY_A
		);

		// Build export data.
		$export = array(
			'profile'       => $profile->to_array(),
			'orders'        => $orders,
			'conversations' => $conversations,
			'stats'         => $this->calculate_customer_stats( $phone ),
			'exported_at'   => current_time( 'mysql' ),
		);

		WCH_Logger::info(
			'Exported customer data for GDPR',
			'customer-service',
			array( 'phone' => $phone )
		);

		return $export;
	}

	/**
	 * Delete customer data for GDPR compliance.
	 *
	 * Anonymizes conversations and deletes profile.
	 *
	 * @param string $phone Phone number.
	 * @return bool Success status.
	 */
	public function delete_customer_data( $phone ) {
		// Normalize phone.
		$phone = $this->normalize_phone( $phone );

		// Anonymize conversations.
		$conv_table = $this->db_manager->get_table_name( 'conversations' );
		$this->wpdb->update(
			$conv_table,
			array(
				'customer_phone' => 'ANONYMIZED',
				'context'        => wp_json_encode( array( 'anonymized' => true ) ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'customer_phone' => $phone ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		// Anonymize messages (if they contain customer phone in content).
		$msg_table = $this->db_manager->get_table_name( 'messages' );
		$messages  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT m.* FROM {$msg_table} m
				INNER JOIN {$conv_table} c ON m.conversation_id = c.id
				WHERE c.customer_phone = %s",
				'ANONYMIZED'
			),
			ARRAY_A
		);

		foreach ( $messages as $message ) {
			$content = json_decode( $message['content'], true );
			if ( is_array( $content ) ) {
				// Remove any phone references.
				$content = array_map(
					function( $value ) use ( $phone ) {
						if ( is_string( $value ) ) {
							return str_replace( $phone, 'ANONYMIZED', $value );
						}
						return $value;
					},
					$content
				);

				$this->wpdb->update(
					$msg_table,
					array( 'content' => wp_json_encode( $content ) ),
					array( 'id' => $message['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		// Delete customer profile.
		$profile_table = $this->db_manager->get_table_name( 'customer_profiles' );
		$result        = $this->wpdb->delete(
			$profile_table,
			array( 'phone' => $phone ),
			array( '%s' )
		);

		if ( false !== $result ) {
			WCH_Logger::info(
				'Deleted customer data for GDPR',
				'customer-service',
				array( 'phone' => $phone )
			);
			return true;
		}

		return false;
	}

	/**
	 * Convert database row to customer profile object.
	 *
	 * @param array $row Database row.
	 * @return WCH_Customer_Profile Profile object.
	 */
	private function row_to_profile( $row ) {
		// Decode JSON fields.
		$saved_addresses = json_decode( $row['saved_addresses'], true );
		$preferences     = json_decode( $row['preferences'], true );

		// Get order history and stats.
		$orders = $this->get_order_history( $row['phone'] );
		$stats  = $this->calculate_customer_stats( $row['phone'] );

		return new WCH_Customer_Profile(
			array(
				'phone'            => $row['phone'],
				'wc_customer_id'   => $row['wc_customer_id'],
				'name'             => $row['name'],
				'saved_addresses'  => is_array( $saved_addresses ) ? $saved_addresses : array(),
				'order_history'    => $orders,
				'preferences'      => is_array( $preferences ) ? $preferences : array(),
				'opt_in_marketing' => (bool) $row['opt_in_marketing'],
				'lifetime_value'   => $stats['total_spent'],
				'last_order_date'  => ! empty( $orders ) ? $orders[0]['date'] : null,
				'total_orders'     => $stats['total_orders'],
				'created_at'       => $row['created_at'],
				'updated_at'       => $row['updated_at'],
			)
		);
	}
}
