<?php
/**
 * AbstractMigration
 *
 * Base class for database migrations.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Infrastructure\Database\Migrations
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Database\Migrations;

use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractMigration
 *
 * Provides common functionality for database migrations.
 */
abstract class AbstractMigration implements MigrationInterface {
	/**
	 * Migration version.
	 *
	 * @var string
	 */
	protected string $version;

	/**
	 * Constructor.
	 *
	 * @param string $version Migration version.
	 */
	public function __construct( string $version ) {
		$this->version = $version;
	}

	/**
	 * Get the migration version.
	 *
	 * @return string Version string.
	 */
	public function getVersion(): string {
		return $this->version;
	}

	/**
	 * Check if migration should run.
	 *
	 * Default implementation checks if current version is less than migration version.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @return bool True if migration should run.
	 */
	public function shouldRun( DatabaseManager $db ): bool {
		return version_compare( $db->getCurrentVersion(), $this->version, '<' );
	}

	/**
	 * Execute SQL using dbDelta for idempotent operations.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @param string          $sql SQL statement.
	 * @return void
	 */
	protected function dbDelta( DatabaseManager $db, string $sql ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Execute raw SQL query.
	 *
	 * Use with caution. Prefer dbDelta for schema changes.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @param string          $sql SQL statement.
	 * @return void
	 */
	protected function query( DatabaseManager $db, string $sql ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $sql );
	}

	/**
	 * Add a column to a table if it doesn't exist.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @param string          $table Table name without prefix.
	 * @param string          $column Column name.
	 * @param string          $definition Column definition (e.g., 'VARCHAR(255) NULL').
	 * @return void
	 */
	protected function addColumn( DatabaseManager $db, string $table, string $column, string $definition ): void {
		global $wpdb;
		$tableName = $db->getTableName( $table );

		// Check if column exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columnExists = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$tableName,
				$column
			)
		);

		if ( empty( $columnExists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$tableName} ADD COLUMN {$column} {$definition}"
			);
		}
	}

	/**
	 * Drop a column from a table if it exists.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @param string          $table Table name without prefix.
	 * @param string          $column Column name.
	 * @return void
	 */
	protected function dropColumn( DatabaseManager $db, string $table, string $column ): void {
		global $wpdb;
		$tableName = $db->getTableName( $table );

		// Check if column exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columnExists = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				$tableName,
				$column
			)
		);

		if ( ! empty( $columnExists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$tableName} DROP COLUMN {$column}"
			);
		}
	}

	/**
	 * Add an index to a table if it doesn't exist.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @param string          $table Table name without prefix.
	 * @param string          $indexName Index name.
	 * @param string          $columns Column definition (e.g., 'column1, column2').
	 * @param string          $type Index type ('INDEX', 'UNIQUE', 'FULLTEXT').
	 * @return void
	 */
	protected function addIndex( DatabaseManager $db, string $table, string $indexName, string $columns, string $type = 'INDEX' ): void {
		global $wpdb;
		$tableName = $db->getTableName( $table );

		// Check if index exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexExists = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW INDEX FROM %i WHERE Key_name = %s',
				$tableName,
				$indexName
			)
		);

		if ( empty( $indexExists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$tableName} ADD {$type} {$indexName} ({$columns})"
			);
		}
	}

	/**
	 * Drop an index from a table if it exists.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @param string          $table Table name without prefix.
	 * @param string          $indexName Index name.
	 * @return void
	 */
	protected function dropIndex( DatabaseManager $db, string $table, string $indexName ): void {
		global $wpdb;
		$tableName = $db->getTableName( $table );

		// Check if index exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexExists = $wpdb->get_results(
			$wpdb->prepare(
				'SHOW INDEX FROM %i WHERE Key_name = %s',
				$tableName,
				$indexName
			)
		);

		if ( ! empty( $indexExists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"ALTER TABLE {$tableName} DROP INDEX {$indexName}"
			);
		}
	}

	/**
	 * Run the migration.
	 *
	 * Must be implemented by concrete migration classes.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @return void
	 */
	abstract public function up( DatabaseManager $db ): void;
}
