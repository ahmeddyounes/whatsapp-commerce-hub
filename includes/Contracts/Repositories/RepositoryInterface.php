<?php
/**
 * Repository Interface
 *
 * Base interface for all repository implementations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Repositories;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface RepositoryInterface
 *
 * Defines the contract for basic CRUD operations on entities.
 */
interface RepositoryInterface {

	/**
	 * Find an entity by its primary key.
	 *
	 * @param int $id The primary key.
	 * @return object|null The entity or null if not found.
	 */
	public function find( int $id ): ?object;

	/**
	 * Find an entity by specific criteria.
	 *
	 * @param array<string, mixed> $criteria The search criteria.
	 * @return object|null The first matching entity or null.
	 */
	public function findBy( array $criteria ): ?object;

	/**
	 * Find all entities matching criteria.
	 *
	 * @param array<string, mixed>  $criteria The search criteria.
	 * @param array<string, string> $orderBy  Column => direction pairs.
	 * @param int|null              $limit    Maximum number of results.
	 * @param int                   $offset   Number of results to skip.
	 * @return array<object> Array of entities.
	 */
	public function findAll(
		array $criteria = array(),
		array $orderBy = array(),
		?int $limit = null,
		int $offset = 0
	): array;

	/**
	 * Create a new entity.
	 *
	 * @param array<string, mixed> $data The entity data.
	 * @return int The ID of the created entity.
	 */
	public function create( array $data ): int;

	/**
	 * Update an existing entity.
	 *
	 * @param int                  $id   The entity ID.
	 * @param array<string, mixed> $data The data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( int $id, array $data ): bool;

	/**
	 * Delete an entity.
	 *
	 * @param int $id The entity ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( int $id ): bool;

	/**
	 * Count entities matching criteria.
	 *
	 * @param array<string, mixed> $criteria The search criteria.
	 * @return int The count.
	 */
	public function count( array $criteria = array() ): int;

	/**
	 * Check if an entity exists.
	 *
	 * @param int $id The entity ID.
	 * @return bool True if exists, false otherwise.
	 */
	public function exists( int $id ): bool;

	/**
	 * Begin a database transaction.
	 *
	 * @return void
	 */
	public function beginTransaction(): void;

	/**
	 * Commit a database transaction.
	 *
	 * @return void
	 */
	public function commit(): void;

	/**
	 * Rollback a database transaction.
	 *
	 * @return void
	 */
	public function rollback(): void;
}
