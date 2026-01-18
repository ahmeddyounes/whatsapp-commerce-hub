<?php
/**
 * DatabaseManager Migrations Test
 *
 * Tests for the DatabaseManager migration functionality.
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Tests\Unit\Infrastructure\Database;

use WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager;
use WCH_Unit_Test_Case;

/**
 * Class DatabaseManagerMigrationsTest
 *
 * Test DatabaseManager migration system.
 */
class DatabaseManagerMigrationsTest extends WCH_Unit_Test_Case {

	/**
	 * DatabaseManager instance.
	 *
	 * @var DatabaseManager
	 */
	protected $db_manager;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db_manager = new DatabaseManager();
	}

	/**
	 * Test that migrations are loaded correctly.
	 */
	public function test_migrations_are_loaded() {
		// Use reflection to access private getMigrations method.
		$reflection = new \ReflectionClass( $this->db_manager );
		$method     = $reflection->getMethod( 'getMigrations' );
		$method->setAccessible( true );

		$migrations = $method->invoke( $this->db_manager );

		// Verify migrations is an array.
		$this->assertIsArray( $migrations );

		// If there are migrations, verify they implement the interface.
		foreach ( $migrations as $version => $migration ) {
			$this->assertInstanceOf(
				'WhatsAppCommerceHub\Infrastructure\Database\Migrations\MigrationInterface',
				$migration
			);
			$this->assertEquals( $version, $migration->getVersion() );
		}
	}

	/**
	 * Test that migrations are sorted by version.
	 */
	public function test_migrations_are_sorted_by_version() {
		// Use reflection to access private getMigrations method.
		$reflection = new \ReflectionClass( $this->db_manager );
		$method     = $reflection->getMethod( 'getMigrations' );
		$method->setAccessible( true );

		$migrations = $method->invoke( $this->db_manager );
		$versions   = array_keys( $migrations );

		// Verify versions are in ascending order.
		$sortedVersions = $versions;
		usort( $sortedVersions, 'version_compare' );

		$this->assertEquals( $sortedVersions, $versions );
	}

	/**
	 * Test that needsMigration returns correct value.
	 */
	public function test_needs_migration() {
		// Delete the version option to simulate fresh install.
		delete_option( DatabaseManager::DB_VERSION_OPTION );

		$this->assertTrue( $this->db_manager->needsMigration() );

		// Update to current version.
		update_option( DatabaseManager::DB_VERSION_OPTION, DatabaseManager::DB_VERSION );

		$this->assertFalse( $this->db_manager->needsMigration() );
	}

	/**
	 * Test that version is updated correctly.
	 */
	public function test_version_update() {
		$testVersion = '2.5.0';

		$result = $this->db_manager->updateVersion( $testVersion );

		$this->assertTrue( $result );
		$this->assertEquals( $testVersion, $this->db_manager->getCurrentVersion() );
	}

	/**
	 * Test that getCurrentVersion returns default when not set.
	 */
	public function test_get_current_version_default() {
		delete_option( DatabaseManager::DB_VERSION_OPTION );

		$this->assertEquals( '0.0.0', $this->db_manager->getCurrentVersion() );
	}

	/**
	 * Teardown after each test.
	 */
	protected function tearDown(): void {
		// Clean up version option.
		delete_option( DatabaseManager::DB_VERSION_OPTION );
		parent::tearDown();
	}
}
