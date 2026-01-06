<?php
/**
 * Customer Profile Class
 *
 * Represents a WhatsApp customer profile with all associated data.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Customer_Profile
 */
class WCH_Customer_Profile {
	/**
	 * Customer phone number in E.164 format.
	 *
	 * @var string
	 */
	public $phone;

	/**
	 * WooCommerce customer ID (nullable).
	 *
	 * @var int|null
	 */
	public $wc_customer_id;

	/**
	 * Customer name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Saved addresses array.
	 *
	 * @var array
	 */
	public $saved_addresses;

	/**
	 * Order history from WooCommerce if linked.
	 *
	 * @var array
	 */
	public $order_history;

	/**
	 * Customer preferences (language, currency).
	 *
	 * @var array
	 */
	public $preferences;

	/**
	 * Marketing opt-in status.
	 *
	 * @var bool
	 */
	public $opt_in_marketing;

	/**
	 * Calculated lifetime value.
	 *
	 * @var float
	 */
	public $lifetime_value;

	/**
	 * Last order date.
	 *
	 * @var string|null
	 */
	public $last_order_date;

	/**
	 * Total number of orders.
	 *
	 * @var int
	 */
	public $total_orders;

	/**
	 * Created at timestamp.
	 *
	 * @var string
	 */
	public $created_at;

	/**
	 * Updated at timestamp.
	 *
	 * @var string
	 */
	public $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array $data Profile data.
	 */
	public function __construct( $data = array() ) {
		$this->phone            = $data['phone'] ?? '';
		$this->wc_customer_id   = isset( $data['wc_customer_id'] ) ? intval( $data['wc_customer_id'] ) : null;
		$this->name             = $data['name'] ?? '';
		$this->saved_addresses  = $data['saved_addresses'] ?? array();
		$this->order_history    = $data['order_history'] ?? array();
		$this->preferences      = $data['preferences'] ?? array();
		$this->opt_in_marketing = isset( $data['opt_in_marketing'] ) ? (bool) $data['opt_in_marketing'] : false;
		$this->lifetime_value   = isset( $data['lifetime_value'] ) ? floatval( $data['lifetime_value'] ) : 0.0;
		$this->last_order_date  = $data['last_order_date'] ?? null;
		$this->total_orders     = isset( $data['total_orders'] ) ? intval( $data['total_orders'] ) : 0;
		$this->created_at       = $data['created_at'] ?? '';
		$this->updated_at       = $data['updated_at'] ?? '';
	}

	/**
	 * Convert profile to array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'phone'            => $this->phone,
			'wc_customer_id'   => $this->wc_customer_id,
			'name'             => $this->name,
			'saved_addresses'  => $this->saved_addresses,
			'order_history'    => $this->order_history,
			'preferences'      => $this->preferences,
			'opt_in_marketing' => $this->opt_in_marketing,
			'lifetime_value'   => $this->lifetime_value,
			'last_order_date'  => $this->last_order_date,
			'total_orders'     => $this->total_orders,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		);
	}
}
