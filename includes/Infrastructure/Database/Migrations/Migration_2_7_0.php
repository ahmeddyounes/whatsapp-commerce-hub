<?php
/**
 * Migration_2_7_0
 *
 * Example migration for version 2.7.0.
 * Demonstrates adding a new column to an existing table.
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
 * Class Migration_2_7_0
 *
 * Example migration that adds metadata column to customer_profiles table.
 */
class Migration_2_7_0 extends AbstractMigration {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( '2.7.0' );
	}

	/**
	 * Run the migration.
	 *
	 * Adds a metadata JSON column to customer_profiles table for storing
	 * additional custom data.
	 *
	 * @param DatabaseManager $db Database manager instance.
	 * @return void
	 */
	public function up( DatabaseManager $db ): void {
		// Example: Add a new column to customer_profiles table.
		// This is idempotent - it will only add the column if it doesn't exist.
		$this->addColumn(
			$db,
			'customer_profiles',
			'metadata',
			'JSON NULL COMMENT \'Additional metadata for customer\''
		);

		// Example: Add an index.
		// This is also idempotent.
		$this->addIndex(
			$db,
			'customer_profiles',
			'opt_in_marketing',
			'opt_in_marketing'
		);
	}
}
