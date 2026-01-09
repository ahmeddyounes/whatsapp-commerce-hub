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
	const DB_VERSION = '2.2.0';

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
	 * Get singleton instance.
	 *
	 * @deprecated 2.1.0 Use wch_get_container()->get(WCH_Database_Manager::class) instead.
	 * @return WCH_Database_Manager
	 */
	public static function instance() {
		// Use container if available for consistent instance.
		if ( function_exists( 'wch_get_container' ) ) {
			try {
				$container = wch_get_container();
				if ( $container->has( self::class ) ) {
					return $container->get( self::class );
				}
			} catch ( \Throwable $e ) {
				// Fall through to legacy behavior.
			}
		}

		// Legacy fallback for backwards compatibility.
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
		$sql_conversations = 'CREATE TABLE ' . $this->get_table_name( 'conversations' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			wa_conversation_id VARCHAR(100) NOT NULL,
			status ENUM('pending', 'active', 'idle', 'escalated', 'closed') NOT NULL DEFAULT 'pending',
			state VARCHAR(50) NULL,
			assigned_agent_id BIGINT(20) UNSIGNED NULL,
			context JSON NULL,
			message_count INT(11) NOT NULL DEFAULT 0,
			unread_count INT(11) NOT NULL DEFAULT 0,
			last_message_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY customer_phone (customer_phone),
			KEY last_message_at (last_message_at),
			KEY status (status),
			KEY state (state),
			KEY unread_count (unread_count)
		) $charset_collate;";

		// Create wch_messages table.
		$sql_messages = 'CREATE TABLE ' . $this->get_table_name( 'messages' ) . " (
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
		$sql_carts = 'CREATE TABLE ' . $this->get_table_name( 'carts' ) . " (
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
		$sql_customer_profiles = 'CREATE TABLE ' . $this->get_table_name( 'customer_profiles' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phone VARCHAR(20) NOT NULL,
			wc_customer_id BIGINT(20) UNSIGNED NULL,
			name VARCHAR(100) NOT NULL,
			saved_addresses JSON NULL,
			preferences JSON NULL,
			tags JSON NULL,
			total_orders INT(11) NOT NULL DEFAULT 0,
			total_spent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			opt_in_marketing TINYINT(1) NOT NULL DEFAULT 0,
			notification_opt_out TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY wc_customer_id (wc_customer_id),
			KEY total_orders (total_orders),
			KEY total_spent (total_spent)
		) $charset_collate;";

		// Create wch_broadcast_campaigns table.
		$sql_broadcast_campaigns = 'CREATE TABLE ' . $this->get_table_name( 'broadcast_campaigns' ) . " (
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
		$sql_sync_queue = 'CREATE TABLE ' . $this->get_table_name( 'sync_queue' ) . " (
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
		$sql_notification_log = 'CREATE TABLE ' . $this->get_table_name( 'notification_log' ) . " (
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

		// Create wch_product_views table for re-engagement tracking.
		$sql_product_views = 'CREATE TABLE ' . $this->get_table_name( 'product_views' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			price_at_view DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			in_stock TINYINT(1) NOT NULL DEFAULT 1,
			viewed_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY customer_phone (customer_phone),
			KEY product_id (product_id),
			KEY viewed_at (viewed_at)
		) $charset_collate;";

		// Create wch_reengagement_log table.
		$sql_reengagement_log = 'CREATE TABLE ' . $this->get_table_name( 'reengagement_log' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			campaign_type VARCHAR(50) NOT NULL,
			message_id VARCHAR(100) NULL,
			status ENUM('sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'sent',
			converted TINYINT(1) NOT NULL DEFAULT 0,
			order_id BIGINT(20) UNSIGNED NULL,
			sent_at DATETIME NOT NULL,
			converted_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY customer_phone (customer_phone),
			KEY campaign_type (campaign_type),
			KEY sent_at (sent_at),
			KEY converted (converted)
		) $charset_collate;";

		// Create wch_circuit_breakers table for resilience pattern state persistence.
		$sql_circuit_breakers = 'CREATE TABLE ' . $this->get_table_name( 'circuit_breakers' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			service_name VARCHAR(100) NOT NULL,
			state ENUM('closed', 'open', 'half_open') NOT NULL DEFAULT 'closed',
			failure_count INT(11) NOT NULL DEFAULT 0,
			success_count INT(11) NOT NULL DEFAULT 0,
			last_failure_at DATETIME NULL,
			last_success_at DATETIME NULL,
			opened_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY service_name (service_name),
			KEY state (state)
		) $charset_collate;";

		// Create wch_rate_limits table for database-backed rate limiting.
		// Each row represents a single request hit for per-hit counting.
		$sql_rate_limits = 'CREATE TABLE ' . $this->get_table_name( 'rate_limits' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			identifier_hash VARCHAR(64) NOT NULL,
			limit_type VARCHAR(32) NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			metadata JSON NULL,
			PRIMARY KEY (id),
			KEY identifier_type (identifier_hash, limit_type),
			KEY cleanup (created_at),
			KEY expires (expires_at)
		) $charset_collate;";

		// Create wch_dead_letter_queue table for failed job persistence.
		$sql_dead_letter_queue = 'CREATE TABLE ' . $this->get_table_name( 'dead_letter_queue' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(100) NOT NULL,
			hook VARCHAR(200) NOT NULL,
			args LONGTEXT NOT NULL,
			error_message TEXT NOT NULL,
			failure_count INT(11) NOT NULL DEFAULT 1,
			first_failed_at DATETIME NOT NULL,
			last_failed_at DATETIME NOT NULL,
			status ENUM('pending', 'retrying', 'dismissed', 'recovered') NOT NULL DEFAULT 'pending',
			PRIMARY KEY (id),
			KEY status (status),
			KEY hook (hook),
			KEY last_failed_at (last_failed_at)
		) $charset_collate;";

		// Create wch_event_log table for async event processing.
		$sql_event_log = 'CREATE TABLE ' . $this->get_table_name( 'event_log' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_name VARCHAR(100) NOT NULL,
			event_data LONGTEXT NOT NULL,
			processed TINYINT(1) NOT NULL DEFAULT 0,
			processed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_name (event_name),
			KEY processed (processed),
			KEY created_at (created_at)
		) $charset_collate;";

		// Create wch_webhook_idempotency table for atomic message deduplication.
		$sql_webhook_idempotency = 'CREATE TABLE ' . $this->get_table_name( 'webhook_idempotency' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id VARCHAR(100) NOT NULL,
			processed_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY message_id (message_id),
			KEY processed_at (processed_at)
		) $charset_collate;";

		// Create wch_saga_state table for saga orchestration.
		$sql_saga_state = 'CREATE TABLE ' . $this->get_table_name( 'saga_state' ) . " (
			saga_id VARCHAR(100) NOT NULL,
			saga_type VARCHAR(50) NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			context LONGTEXT NOT NULL,
			log LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (saga_id),
			KEY idx_state (state),
			KEY idx_saga_type (saga_type),
			KEY idx_updated (updated_at)
		) $charset_collate;";

		// Create wch_security_log table for security audit logging.
		$sql_security_log = 'CREATE TABLE ' . $this->get_table_name( 'security_log' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(100) NOT NULL,
			level ENUM('info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
			context LONGTEXT NULL,
			ip_address VARCHAR(45) NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event (event),
			KEY level (level),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Create wch_webhook_events table for payment webhook idempotency (prevents double-spend).
		$sql_webhook_events = 'CREATE TABLE ' . $this->get_table_name( 'webhook_events' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(100) NOT NULL,
			status ENUM('processing', 'completed', 'failed') NOT NULL DEFAULT 'processing',
			gateway VARCHAR(50) NULL,
			order_id BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_id (event_id),
			KEY status (status),
			KEY gateway (gateway),
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
		dbDelta( $sql_product_views );
		dbDelta( $sql_reengagement_log );

		// New architecture tables (v2.0.0).
		dbDelta( $sql_circuit_breakers );
		dbDelta( $sql_rate_limits );
		dbDelta( $sql_dead_letter_queue );
		dbDelta( $sql_event_log );
		dbDelta( $sql_webhook_idempotency );
		dbDelta( $sql_saga_state );
		dbDelta( $sql_security_log );
		dbDelta( $sql_webhook_events );

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
			'product_views',
			'reengagement_log',
			// New architecture tables (v2.0.0).
			'circuit_breakers',
			'rate_limits',
			'dead_letter_queue',
			'event_log',
			'webhook_idempotency',
			'saga_state',
			'security_log',
			'webhook_events',
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
