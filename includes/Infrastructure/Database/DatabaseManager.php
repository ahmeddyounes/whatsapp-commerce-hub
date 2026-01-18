<?php
/**
 * DatabaseManager
 *
 * Handles database schema management and migrations.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Infrastructure\Database
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DatabaseManager
 *
 * Manages database schema, migrations, and table operations.
 */
class DatabaseManager {
	/**
	 * Database schema version.
	 */
	public const DB_VERSION = '2.6.0';

	/**
	 * Option name for storing DB version.
	 */
	public const DB_VERSION_OPTION = 'wch_db_version';

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Table prefix for plugin tables.
	 */
	private const TABLE_PREFIX = 'wch_';

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $wpdb WordPress database instance.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @param string $table Table name without prefix (e.g., 'conversations').
	 * @return string Full table name with prefix.
	 */
	public function getTableName( string $table ): string {
		return $this->wpdb->prefix . self::TABLE_PREFIX . $table;
	}

	/**
	 * Get current database version.
	 *
	 * @return string Current version or '0.0.0' if not set.
	 */
	public function getCurrentVersion(): string {
		return get_option( self::DB_VERSION_OPTION, '0.0.0' );
	}

	/**
	 * Update database version.
	 *
	 * @param string $version Version to set.
	 * @return bool True on success.
	 */
	public function updateVersion( string $version ): bool {
		return update_option( self::DB_VERSION_OPTION, $version );
	}

	/**
	 * Check if migrations are needed.
	 *
	 * @return bool True if database needs update.
	 */
	public function needsMigration(): bool {
		return version_compare( $this->getCurrentVersion(), self::DB_VERSION, '<' );
	}

	/**
	 * Install database tables.
	 *
	 * Creates all required tables using dbDelta for idempotent operations.
	 *
	 * @return void
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charsetCollate = $this->wpdb->get_charset_collate();

		// Get all table schemas.
		$tables = $this->getTableSchemas( $charsetCollate );

		// Create each table.
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		// Update database version.
		$this->updateVersion( self::DB_VERSION );

		// Log installation.
		do_action( 'wch_database_installed', self::DB_VERSION );
	}

	/**
	 * Run pending migrations (snake_case alias).
	 *
	 * @return void
	 */
	public function run_migrations(): void {
		$this->runMigrations();
	}

	/**
	 * Run pending migrations.
	 *
	 * Executes migrations in order, ensuring idempotency via dbDelta where appropriate.
	 * Each migration updates the database version upon successful completion.
	 *
	 * @return void
	 */
	public function runMigrations(): void {
		if ( ! $this->needsMigration() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// First, ensure base tables exist using dbDelta (idempotent).
		$charsetCollate = $this->wpdb->get_charset_collate();
		foreach ( $this->getTableSchemas( $charsetCollate ) as $sql ) {
			dbDelta( $sql );
		}

		$currentVersion = $this->getCurrentVersion();

		// Load and run migration files in version order.
		$migrations = $this->getMigrations();

		foreach ( $migrations as $migration ) {
			// Check if migration should run based on version and custom logic.
			if ( $migration->shouldRun( $this ) ) {
				// Execute the migration.
				$migration->up( $this );

				// Update version after successful migration.
				$migrationVersion = $migration->getVersion();
				$this->updateVersion( $migrationVersion );

				// Log migration execution.
				do_action( 'wch_migration_executed', $migrationVersion );
			}
		}

		// Ensure we're at the latest version.
		$this->updateVersion( self::DB_VERSION );

		// Log completion.
		do_action( 'wch_migrations_completed', self::DB_VERSION );
	}

	/**
	 * Get all table schemas.
	 *
	 * @param string $charsetCollate Charset collation string.
	 * @return array<string> Array of CREATE TABLE SQL statements.
	 */
	private function getTableSchemas( string $charsetCollate ): array {
		return [
			$this->getConversationsTableSchema( $charsetCollate ),
			$this->getMessagesTableSchema( $charsetCollate ),
			$this->getCartsTableSchema( $charsetCollate ),
			$this->getCustomerProfilesTableSchema( $charsetCollate ),
			$this->getBroadcastRecipientsTableSchema( $charsetCollate ),
			$this->getSyncQueueTableSchema( $charsetCollate ),
			$this->getNotificationLogTableSchema( $charsetCollate ),
			$this->getProductViewsTableSchema( $charsetCollate ),
			$this->getReengagementTableSchema( $charsetCollate ),
			$this->getRateLimitsTableSchema( $charsetCollate ),
			$this->getSecurityLogTableSchema( $charsetCollate ),
			$this->getWebhookIdempotencyTableSchema( $charsetCollate ),
			$this->getWebhookEventsTableSchema( $charsetCollate ),
		];
	}

	/**
	 * Get conversations table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getConversationsTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'conversations' ) . " (
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
		) $charsetCollate;";
	}

	/**
	 * Get messages table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getMessagesTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'messages' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL,
			direction ENUM('inbound', 'outbound') NOT NULL,
			type ENUM('text', 'interactive', 'image', 'document', 'template', 'audio', 'video', 'location', 'reaction', 'button') NOT NULL,
			wa_message_id VARCHAR(100) NOT NULL,
			content JSON NULL,
			raw_payload JSON NULL,
			status ENUM('pending', 'sent', 'delivered', 'read', 'failed') NOT NULL DEFAULT 'pending',
			retry_count INT(11) NOT NULL DEFAULT 0,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			sent_at DATETIME NULL,
			delivered_at DATETIME NULL,
			read_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wa_message_id (wa_message_id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at),
			KEY status (status)
		) $charsetCollate;";
	}

	/**
	 * Get carts table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getCartsTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'carts' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			items JSON NULL,
			total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			coupon_code VARCHAR(50) NULL,
			shipping_address JSON NULL,
			status ENUM('active', 'abandoned', 'converted', 'expired') NOT NULL DEFAULT 'active',
			reminder_sent_at DATETIME NULL,
			reminder_1_sent_at DATETIME NULL,
			reminder_2_sent_at DATETIME NULL,
			reminder_3_sent_at DATETIME NULL,
			abandoned_at DATETIME NULL,
			recovery_coupon_code VARCHAR(50) NULL,
			recovered TINYINT(1) NOT NULL DEFAULT 0,
			recovered_order_id BIGINT(20) UNSIGNED NULL,
			recovered_revenue DECIMAL(10,2) NULL,
			recovered_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY customer_phone (customer_phone),
			KEY status (status),
			KEY updated_at (updated_at),
			KEY expires_at (expires_at),
			KEY recovered (recovered)
		) $charsetCollate;";
	}

	/**
	 * Get customer profiles table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getCustomerProfilesTableSchema( string $charsetCollate ): string {
			return 'CREATE TABLE ' . $this->getTableName( 'customer_profiles' ) . " (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				phone VARCHAR(20) NOT NULL,
				wc_customer_id BIGINT(20) UNSIGNED NULL,
				name VARCHAR(100) NOT NULL,
				email VARCHAR(100) NULL,
				last_known_address JSON NULL,
				saved_addresses JSON NULL,
				preferences JSON NULL,
				tags JSON NULL,
				total_orders INT(11) NOT NULL DEFAULT 0,
				total_spent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
				opt_in_marketing TINYINT(1) NOT NULL DEFAULT 0,
				notification_opt_out TINYINT(1) NOT NULL DEFAULT 0,
				last_interaction_at DATETIME NULL,
				marketing_opted_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY wc_customer_id (wc_customer_id),
			KEY total_orders (total_orders),
			KEY total_spent (total_spent)
		) $charsetCollate;";
	}

	/**
	 * Get broadcast recipients table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getBroadcastRecipientsTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'broadcast_recipients' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			phone VARCHAR(20) NOT NULL,
			wa_message_id VARCHAR(100) NULL,
			status ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
			sent_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY campaign_phone (campaign_id, phone),
			KEY phone (phone),
			KEY sent_at (sent_at),
			KEY campaign_id (campaign_id)
		) $charsetCollate;";
	}

	/**
	 * Get sync queue table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getSyncQueueTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'sync_queue' ) . " (
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
		) $charsetCollate;";
	}

	/**
	 * Get notification log table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getNotificationLogTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'notification_log' ) . " (
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
		) $charsetCollate;";
	}

	/**
	 * Get product views table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getProductViewsTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'product_views' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			viewed_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY customer_phone (customer_phone),
			KEY product_id (product_id),
			KEY viewed_at (viewed_at)
		) $charsetCollate;";
	}

	/**
	 * Get reengagement table schema.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getReengagementTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'reengagement' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_phone VARCHAR(20) NOT NULL,
			campaign_type VARCHAR(50) NOT NULL,
			product_id BIGINT(20) UNSIGNED NULL,
			sent_at DATETIME NOT NULL,
			converted TINYINT(1) NOT NULL DEFAULT 0,
			conversion_order_id BIGINT(20) UNSIGNED NULL,
			conversion_revenue DECIMAL(10,2) NULL,
			converted_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY customer_phone (customer_phone),
			KEY campaign_type (campaign_type),
			KEY sent_at (sent_at),
			KEY converted (converted)
		) $charsetCollate;";
	}

	/**
	 * Get rate limits table schema.
	 *
	 * Used by RateLimiter and PriorityQueue for sliding window rate limiting.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getRateLimitsTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'rate_limits' ) . " (
			identifier_hash VARCHAR(64) NOT NULL,
			limit_type VARCHAR(32) NOT NULL,
			request_count INT UNSIGNED DEFAULT 0,
			window_start VARCHAR(16) NOT NULL,
			created_at DATETIME NULL,
			expires_at DATETIME NULL,
			metadata JSON NULL,
			PRIMARY KEY (identifier_hash, limit_type, window_start),
			KEY idx_window (window_start),
			KEY idx_expires (expires_at)
		) $charsetCollate;";
	}

	/**
	 * Get security log table schema.
	 *
	 * Used by SecurityServiceProvider logger to track security events.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getSecurityLogTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'security_log' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(100) NOT NULL,
			level VARCHAR(20) NOT NULL,
			context JSON NULL,
			ip_address VARCHAR(45) NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event (event),
			KEY level (level),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY ip_address (ip_address)
		) $charsetCollate;";
	}

	/**
	 * Get webhook idempotency table schema.
	 *
	 * Used by IdempotencyService to prevent duplicate webhook processing.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getWebhookIdempotencyTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'webhook_idempotency' ) . " (
			message_id VARCHAR(255) NOT NULL,
			scope VARCHAR(100) NOT NULL,
			processed_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY (message_id, scope),
			KEY idx_expires (expires_at)
		) $charsetCollate;";
	}

	/**
	 * Get webhook events table schema.
	 *
	 * Used by PaymentWebhookController to track payment webhook processing status.
	 *
	 * @param string $charsetCollate Charset collation.
	 * @return string SQL statement.
	 */
	private function getWebhookEventsTableSchema( string $charsetCollate ): string {
		return 'CREATE TABLE ' . $this->getTableName( 'webhook_events' ) . " (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL,
			created_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_id (event_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charsetCollate;";
	}

	/**
	 * Get available migrations.
	 *
	 * Scans the Migrations directory and loads all migration classes.
	 * Returns them sorted by version.
	 *
	 * @return array<string, Migrations\MigrationInterface> Version => Migration instance.
	 */
	private function getMigrations(): array {
		$migrations     = [];
		$migrationsPath = plugin_dir_path( __DIR__ ) . 'Database/Migrations/';

		// Scan for migration files.
		if ( ! is_dir( $migrationsPath ) ) {
			return $migrations;
		}

		$files = glob( $migrationsPath . 'Migration_*.php' );
		if ( false === $files ) {
			return $migrations;
		}

		foreach ( $files as $file ) {
			$className = 'WhatsAppCommerceHub\\Infrastructure\\Database\\Migrations\\' . basename( $file, '.php' );

			if ( ! class_exists( $className ) ) {
				require_once $file;
			}

			if ( class_exists( $className ) ) {
				$migration = new $className();
				if ( $migration instanceof Migrations\MigrationInterface ) {
					$migrations[ $migration->getVersion() ] = $migration;
				}
			}
		}

		// Sort migrations by version.
		uksort( $migrations, 'version_compare' );

		return $migrations;
	}

	/**
	 * Drop all plugin tables.
	 *
	 * WARNING: This will delete all data. Use only during uninstall.
	 *
	 * @return void
	 */
	public function dropTables(): void {
		$tables = [
			'webhook_events',
			'webhook_idempotency',
			'security_log',
			'rate_limits',
			'reengagement',
			'product_views',
			'notification_log',
			'sync_queue',
			'broadcast_recipients',
			'customer_profiles',
			'carts',
			'messages',
			'conversations',
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->getTableName( $table ) );
		}

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Check if a table exists.
	 *
	 * @param string $table Table name without prefix.
	 * @return bool True if table exists.
	 */
	public function tableExists( string $table ): bool {
		$tableName = $this->getTableName( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName )
		) === $tableName;
	}
}
