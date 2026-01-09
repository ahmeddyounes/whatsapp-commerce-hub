<?php
/**
 * Database Manager Interface
 *
 * Contract for database management services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface DatabaseManagerInterface
 *
 * Defines the contract for database schema management.
 */
interface DatabaseManagerInterface {

	/**
	 * Create all plugin database tables.
	 *
	 * @return bool True on success.
	 */
	public function createTables(): bool;

	/**
	 * Drop all plugin database tables.
	 *
	 * @return bool True on success.
	 */
	public function dropTables(): bool;

	/**
	 * Run database migrations.
	 *
	 * @return bool True on success.
	 */
	public function runMigrations(): bool;

	/**
	 * Get the current database schema version.
	 *
	 * @return string Current version.
	 */
	public function getCurrentVersion(): string;

	/**
	 * Get the target database schema version.
	 *
	 * @return string Target version.
	 */
	public function getTargetVersion(): string;

	/**
	 * Check if migrations are needed.
	 *
	 * @return bool True if migrations are needed.
	 */
	public function needsMigration(): bool;

	/**
	 * Get a table name with prefix.
	 *
	 * @param string $table Table name without prefix.
	 * @return string Full table name with prefix.
	 */
	public function getTableName( string $table ): string;

	/**
	 * Check if a table exists.
	 *
	 * @param string $table Table name without prefix.
	 * @return bool True if table exists.
	 */
	public function tableExists( string $table ): bool;

	/**
	 * Optimize plugin tables.
	 *
	 * @return bool True on success.
	 */
	public function optimizeTables(): bool;

	/**
	 * Get table statistics.
	 *
	 * @return array<string, array{rows: int, size: int}> Table statistics.
	 */
	public function getTableStats(): array;
}
