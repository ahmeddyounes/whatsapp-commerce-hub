<?php
/**
 * MigrationInterface
 *
 * Interface for database migrations.
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
 * Interface MigrationInterface
 *
 * Defines the contract for database migrations.
 */
interface MigrationInterface {
	/**
	 * Get the migration version.
	 *
	 * @return string Version string (e.g., '2.1.0').
	 */
	public function getVersion(): string;

	/**
	 * Run the migration.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @return void
	 */
	public function up( DatabaseManager $db ): void;

	/**
	 * Check if migration should run.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @return bool True if migration should run.
	 */
	public function shouldRun( DatabaseManager $db ): bool;
}
