<?php
/**
 * Database Manager Class
 *
 * Handles database schema creation, migrations, and table management.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Database_Manager
 */
class WCH_Database_Manager {
	/**
	 * Database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Option name for storing DB version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'wch_db_version';

	/**
	 * Singleton instance
	 *
	 * @var WCH_Database_Manager|null
	 */
	private static $instance = null;

	/**
	 * Global wpdb instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Get singleton instance
	 *
	 * @return WCH_Database_Manager
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
	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @param string $table Table name without prefix (e.g., 'conversations').
	 * @return string Full table name with prefix.
	 */
	public function get_table_name( $table ) {
		return $this->wpdb->prefix . 'wch_' . $table;
	}

	/**
	 * Install database tables.
	 *
	 * Creates all required tables using dbDelta for idempotent operations.
	 */
	public function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();

		// Create wch_conversations table.
		$sql_conversations = "CREATE TABLE " . $this->get_table_name( 'conversations' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			wa_conversation_id VARCHAR(100) NOT NULL,
			status ENUM('pending', 'active', 'closed') NOT NULL DEFAULT 'pending',
			assigned_agent_id BIGINT(20) UNSIGNED NULL,
			context JSON NULL,
			last_message_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY customer_phone (customer_phone),
			KEY last_message_at (last_message_at)
		) $charset_collate;";

		// Create wch_messages table.
		$sql_messages = "CREATE TABLE " . $this->get_table_name( 'messages' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL,
			direction ENUM('inbound', 'outbound') NOT NULL,
			message_type ENUM('text', 'interactive', 'image', 'document', 'template') NOT NULL,
			wa_message_id VARCHAR(100) NOT NULL,
			content JSON NULL,
			status ENUM('sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'sent',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wa_message_id (wa_message_id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Create wch_carts table.
		$sql_carts = "CREATE TABLE " . $this->get_table_name( 'carts' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			items JSON NULL,
			total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			coupon_code VARCHAR(50) NULL,
			shipping_address JSON NULL,
			status ENUM('active', 'completed', 'abandoned') NOT NULL DEFAULT 'active',
			reminder_sent_at DATETIME NULL,
			reminder_1_sent_at DATETIME NULL,
			reminder_2_sent_at DATETIME NULL,
			reminder_3_sent_at DATETIME NULL,
			recovery_coupon_code VARCHAR(50) NULL,
			recovered TINYINT(1) NOT NULL DEFAULT 0,
			recovered_order_id BIGINT(20) UNSIGNED NULL,
			recovered_revenue DECIMAL(10,2) NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY customer_phone (customer_phone),
			KEY status (status),
			KEY updated_at (updated_at),
			KEY expires_at (expires_at),
			KEY recovered (recovered)
		) $charset_collate;";

		// Create wch_customer_profiles table.
		$sql_customer_profiles = "CREATE TABLE " . $this->get_table_name( 'customer_profiles' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phone VARCHAR(20) NOT NULL,
			wc_customer_id BIGINT(20) UNSIGNED NULL,
			name VARCHAR(100) NOT NULL,
			saved_addresses JSON NULL,
			preferences JSON NULL,
			opt_in_marketing TINYINT(1) NOT NULL DEFAULT 0,
			notification_opt_out TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY wc_customer_id (wc_customer_id)
		) $charset_collate;";

		// Create wch_broadcast_campaigns table.
		$sql_broadcast_campaigns = "CREATE TABLE " . $this->get_table_name( 'broadcast_campaigns' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(200) NOT NULL,
			template_name VARCHAR(100) NOT NULL,
			audience_filter JSON NULL,
			status ENUM('draft', 'scheduled', 'sending', 'completed', 'failed') NOT NULL DEFAULT 'draft',
			scheduled_at DATETIME NULL,
			sent_count INT(11) NOT NULL DEFAULT 0,
			delivered_count INT(11) NOT NULL DEFAULT 0,
			read_count INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at)
		) $charset_collate;";

		// Create wch_sync_queue table.
		$sql_sync_queue = "CREATE TABLE " . $this->get_table_name( 'sync_queue' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			entity_type ENUM('product', 'order', 'inventory') NOT NULL,
			entity_id BIGINT(20) UNSIGNED NOT NULL,
			action ENUM('create', 'update', 'delete') NOT NULL,
			status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
			attempts INT(11) NOT NULL DEFAULT 0,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY entity_type_id (entity_type, entity_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Create wch_notification_log table.
		$sql_notification_log = "CREATE TABLE " . $this->get_table_name( 'notification_log' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			notification_type VARCHAR(50) NOT NULL,
			customer_phone VARCHAR(20) NOT NULL,
			template_name VARCHAR(100) NULL,
			wa_message_id VARCHAR(100) NULL,
			status ENUM('queued', 'sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'queued',
			retry_count INT(11) NOT NULL DEFAULT 0,
			sent_at DATETIME NULL,
			delivered_at DATETIME NULL,
			read_at DATETIME NULL,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY customer_phone (customer_phone),
			KEY notification_type (notification_type),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Execute dbDelta for all tables.
		dbDelta( $sql_conversations );
		dbDelta( $sql_messages );
		dbDelta( $sql_carts );
		dbDelta( $sql_customer_profiles );
		dbDelta( $sql_broadcast_campaigns );
		dbDelta( $sql_sync_queue );
		dbDelta( $sql_notification_log );

		// Update the database version.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Uninstall database tables.
	 *
	 * Removes all tables when WCH_REMOVE_DATA constant is true.
	 */
	public function uninstall() {
		// Only remove data if explicitly defined.
		if ( ! defined( 'WCH_REMOVE_DATA' ) || ! WCH_REMOVE_DATA ) {
			return;
		}

		$tables = array(
			'conversations',
			'messages',
			'carts',
			'customer_profiles',
			'broadcast_campaigns',
			'sync_queue',
			'notification_log',
		);

		foreach ( $tables as $table ) {
			$table_name = $this->get_table_name( $table );
			$this->wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}

		// Delete the database version option.
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Run database migrations.
	 *
	 * Checks current DB version and runs migrations if needed.
	 */
	public function run_migrations() {
		$current_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		// If versions don't match, reinstall (for now, we only have one version).
		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			$this->install();
		}
	}
}
