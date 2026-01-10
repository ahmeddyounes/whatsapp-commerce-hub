<?php
/**
 * Abstract Repository
 *
 * Base repository implementation with common CRUD operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Repositories;

use WhatsAppCommerceHub\Contracts\Repositories\RepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// SQL uses safe table names from $wpdb->prefix. Hook names use wch_ project prefix.

/**
 * Class AbstractRepository
 *
 * Base implementation of the repository pattern for WordPress database access.
 */
abstract class AbstractRepository implements RepositoryInterface {

	/**
	 * The WordPress database instance.
	 *
	 * @var \wpdb
	 */
	protected \wpdb $wpdb;

	/**
	 * The full table name (with prefix).
	 *
	 * @var string
	 */
	protected string $table;

	/**
	 * The primary key column name.
	 *
	 * @var string
	 */
	protected string $primary_key = 'id';

	/**
	 * Whether the table uses soft deletes.
	 *
	 * @var bool
	 */
	protected bool $soft_deletes = false;

	/**
	 * Column name for soft delete timestamp.
	 *
	 * @var string
	 */
	protected string $deleted_at_column = 'deleted_at';

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb The WordPress database instance.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . $this->getTableName();
	}

	/**
	 * Get the table name without prefix.
	 *
	 * @return string
	 */
	abstract protected function getTableName(): string;

	/**
	 * Sanitize a column name for safe use in SQL.
	 *
	 * Validates column name contains only allowed characters and wraps in backticks.
	 *
	 * @param string $column The column name.
	 * @return string The sanitized column name wrapped in backticks.
	 * @throws \InvalidArgumentException If column name contains invalid characters.
	 */
	protected function sanitizeColumn( string $column ): string {
		// Allow only alphanumeric, underscores, and dots (for table.column).
		if ( ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Invalid column name: %s', $column )
			);
		}

		// Handle table.column notation.
		if ( str_contains( $column, '.' ) ) {
			$parts = explode( '.', $column, 2 );
			return '`' . $parts[0] . '`.`' . $parts[1] . '`';
		}

		return '`' . $column . '`';
	}

	/**
	 * Map a database row to an entity.
	 *
	 * Override this method in child classes to return proper entity objects.
	 *
	 * @param array $row The database row.
	 * @return object The entity.
	 */
	protected function mapToEntity( array $row ): object {
		return (object) $row;
	}

	/**
	 * Prepare data for insert/update.
	 *
	 * Override this method to transform entity data before database operations.
	 *
	 * @param array $data The entity data.
	 * @return array The prepared data.
	 */
	protected function prepareData( array $data ): array {
		return $data;
	}

	/**
	 * Get the format strings for wpdb operations.
	 *
	 * Override this method to specify column types.
	 *
	 * @param array $data The data being inserted/updated.
	 * @return array Array of format strings (%s, %d, %f).
	 */
	protected function getFormats( array $data ): array {
		$formats = array();
		foreach ( $data as $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find( int $id ): ?object {
		$sql = "SELECT * FROM {$this->table} WHERE {$this->primary_key} = %d";

		if ( $this->soft_deletes ) {
			$sql .= " AND {$this->deleted_at_column} IS NULL";
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( $sql, $id ),
			ARRAY_A
		);

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findBy( array $criteria ): ?object {
		$where = $this->buildWhereClause( $criteria );

		if ( empty( $where['clause'] ) ) {
			return null;
		}

		$sql = "SELECT * FROM {$this->table} WHERE {$where['clause']}";

		if ( $this->soft_deletes ) {
			$sql .= " AND {$this->deleted_at_column} IS NULL";
		}

		$sql .= ' LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return $row ? $this->mapToEntity( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function findAll(
		array $criteria = array(),
		array $orderBy = array(),
		?int $limit = null,
		int $offset = 0
	): array {
		$sql = "SELECT * FROM {$this->table}";

		$where_parts = array();

		if ( ! empty( $criteria ) ) {
			$where         = $this->buildWhereClause( $criteria );
			$where_parts[] = $where['clause'];
		}

		if ( $this->soft_deletes ) {
			$where_parts[] = "{$this->deleted_at_column} IS NULL";
		}

		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		if ( ! empty( $orderBy ) ) {
			$order_clauses = array();
			foreach ( $orderBy as $column => $direction ) {
				$direction       = strtoupper( $direction ) === 'DESC' ? 'DESC' : 'ASC';
				$order_clauses[] = $this->sanitizeColumn( $column ) . " {$direction}";
			}
			$sql .= ' ORDER BY ' . implode( ', ', $order_clauses );
		}

		if ( null !== $limit ) {
			$sql .= $this->wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'mapToEntity' ), $rows ?: array() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function create( array $data ): int {
		$data = $this->prepareData( $data );

		// Add timestamps.
		$now = current_time( 'mysql' );
		if ( ! isset( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = $now;
		}

		$this->wpdb->insert(
			$this->table,
			$data,
			$this->getFormats( $data )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Note: Returns false if the record doesn't exist OR if there was a database error.
	 * Returns true even if data was unchanged (update executed successfully).
	 */
	public function update( int $id, array $data ): bool {
		// Verify record exists before attempting update.
		// This prevents false positives when updating non-existent records.
		if ( ! $this->exists( $id ) ) {
			return false;
		}

		$data = $this->prepareData( $data );

		// Update timestamp.
		$data['updated_at'] = current_time( 'mysql' );

		$result = $this->wpdb->update(
			$this->table,
			$data,
			array( $this->primary_key => $id ),
			$this->getFormats( $data ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( int $id ): bool {
		if ( $this->soft_deletes ) {
			return $this->update(
				$id,
				array( $this->deleted_at_column => current_time( 'mysql' ) )
			);
		}

		$result = $this->wpdb->delete(
			$this->table,
			array( $this->primary_key => $id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function count( array $criteria = array() ): int {
		$sql = "SELECT COUNT(*) FROM {$this->table}";

		$where_parts = array();

		if ( ! empty( $criteria ) ) {
			$where         = $this->buildWhereClause( $criteria );
			$where_parts[] = $where['clause'];
		}

		if ( $this->soft_deletes ) {
			$where_parts[] = "{$this->deleted_at_column} IS NULL";
		}

		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists( int $id ): bool {
		return null !== $this->find( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function beginTransaction(): void {
		$this->wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function commit(): void {
		$this->wpdb->query( 'COMMIT' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function rollback(): void {
		$this->wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Build a WHERE clause from criteria array.
	 *
	 * Supports operators: =, !=, <, >, <=, >=, LIKE, NOT LIKE, IN, NOT IN, IS NULL, IS NOT NULL.
	 *
	 * @param array $criteria The search criteria.
	 * @return array{clause: string, values: array}
	 */
	protected function buildWhereClause( array $criteria ): array {
		$conditions = array();
		$values     = array();

		foreach ( $criteria as $column => $value ) {
			$column = $this->sanitizeColumn( $column );

			if ( is_array( $value ) && isset( $value['operator'] ) ) {
				// Complex condition with operator.
				$operator = strtoupper( $value['operator'] );
				$val      = $value['value'] ?? null;

				switch ( $operator ) {
					case 'IS NULL':
						$conditions[] = "{$column} IS NULL";
						break;

					case 'IS NOT NULL':
						$conditions[] = "{$column} IS NOT NULL";
						break;

					case 'IN':
						if ( is_array( $val ) ) {
							if ( empty( $val ) ) {
								// IN with empty array matches nothing.
								$conditions[] = '1=0';
							} else {
								$placeholders = implode( ', ', array_fill( 0, count( $val ), '%s' ) );
								$conditions[] = $this->wpdb->prepare(
									"{$column} IN ({$placeholders})",
									...$val
								);
							}
						}
						break;

					case 'NOT IN':
						if ( is_array( $val ) ) {
							if ( empty( $val ) ) {
								// NOT IN with empty array matches everything - no condition needed.
								// Just skip adding a condition.
							} else {
								$placeholders = implode( ', ', array_fill( 0, count( $val ), '%s' ) );
								$conditions[] = $this->wpdb->prepare(
									"{$column} NOT IN ({$placeholders})",
									...$val
								);
							}
						}
						break;

					case 'LIKE':
					case 'NOT LIKE':
						$conditions[] = $this->wpdb->prepare(
							"{$column} {$operator} %s",
							$val
						);
						break;

					case 'BETWEEN':
						if ( is_array( $val ) && count( $val ) === 2 ) {
							$conditions[] = $this->wpdb->prepare(
								"{$column} BETWEEN %s AND %s",
								$val[0],
								$val[1]
							);
						}
						break;

					default:
						// Comparison operators: =, !=, <, >, <=, >=.
						if ( in_array( $operator, array( '=', '!=', '<', '>', '<=', '>=' ), true ) ) {
							$conditions[] = $this->wpdb->prepare(
								"{$column} {$operator} %s",
								$val
							);
						}
						break;
				}
			} elseif ( null === $value ) {
				$conditions[] = "{$column} IS NULL";
			} elseif ( is_array( $value ) ) {
				// Simple IN clause.
				if ( ! empty( $value ) ) {
					$placeholders = implode( ', ', array_fill( 0, count( $value ), '%s' ) );
					$conditions[] = $this->wpdb->prepare(
						"{$column} IN ({$placeholders})",
						...$value
					);
				}
			} else {
				// Simple equality.
				$conditions[] = $this->wpdb->prepare( "{$column} = %s", $value );
			}
		}

		return array(
			'clause' => implode( ' AND ', $conditions ),
			'values' => $values,
		);
	}

	/**
	 * Execute a raw query.
	 *
	 * @param string $sql  The SQL query.
	 * @param array  $args The query arguments.
	 * @return array The query results.
	 */
	protected function query( string $sql, array $args = array() ): array {
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $this->wpdb->prepare( $sql, ...$args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Execute a raw query and return a single row.
	 *
	 * @param string $sql  The SQL query.
	 * @param array  $args The query arguments.
	 * @return array|null The row or null.
	 */
	protected function queryRow( string $sql, array $args = array() ): ?array {
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $this->wpdb->prepare( $sql, ...$args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Execute a raw query and return a single value.
	 *
	 * @param string $sql  The SQL query.
	 * @param array  $args The query arguments.
	 * @return mixed The value or null.
	 */
	protected function queryVar( string $sql, array $args = array() ): mixed {
		if ( ! empty( $args ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $this->wpdb->prepare( $sql, ...$args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_var( $sql );
	}

	/**
	 * Bulk insert multiple rows.
	 *
	 * Validates ALL rows upfront before processing any to ensure
	 * consistency and prevent partial state on validation failure.
	 *
	 * @param array $rows Array of data arrays.
	 * @return int Number of rows inserted.
	 *
	 * @throws \InvalidArgumentException If rows have inconsistent columns.
	 */
	public function bulkInsert( array $rows ): int {
		if ( empty( $rows ) ) {
			return 0;
		}

		$now = current_time( 'mysql' );

		// Phase 1: Prepare all rows and extract columns.
		$prepared_rows = array();
		$columns       = null;

		foreach ( $rows as $index => $row ) {
			$row = $this->prepareData( $row );

			if ( ! isset( $row['created_at'] ) ) {
				$row['created_at'] = $now;
			}
			if ( ! isset( $row['updated_at'] ) ) {
				$row['updated_at'] = $now;
			}

			$current_columns = array_keys( $row );

			if ( null === $columns ) {
				$columns = $current_columns;
			} elseif ( $current_columns !== $columns ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Row %d has different columns than the first row. Expected: [%s], Got: [%s]',
						$index,
						implode( ', ', $columns ),
						implode( ', ', $current_columns )
					)
				);
			}

			$prepared_rows[] = $row;
		}

		// Phase 2: Validate ALL column names upfront (fail fast).
		foreach ( $columns as $col ) {
			$this->sanitizeColumn( $col ); // Throws on invalid
		}

		// Phase 3: Build SQL values (all validation passed).
		$values = array();
		foreach ( $prepared_rows as $row ) {
			$placeholders = array();
			foreach ( $row as $value ) {
				if ( is_int( $value ) ) {
					$placeholders[] = '%d';
				} elseif ( is_float( $value ) ) {
					$placeholders[] = '%f';
				} else {
					$placeholders[] = '%s';
				}
			}

			$values[] = $this->wpdb->prepare(
				'(' . implode( ', ', $placeholders ) . ')',
				...array_values( $row )
			);
		}

		// Phase 4: Execute insert.
		$columns_sql = implode( ', ', array_map( fn( $col ) => $this->sanitizeColumn( $col ), $columns ) );
		$values_sql  = implode( ', ', $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query( "INSERT INTO {$this->table} ({$columns_sql}) VALUES {$values_sql}" );

		return count( $rows );
	}

	/**
	 * Update multiple rows matching criteria.
	 *
	 * @param array $data     The data to update.
	 * @param array $criteria The criteria to match.
	 * @return int Number of rows affected.
	 */
	public function bulkUpdate( array $data, array $criteria ): int {
		$data               = $this->prepareData( $data );
		$data['updated_at'] = current_time( 'mysql' );

		$set_parts = array();
		foreach ( $data as $column => $value ) {
			$sanitized_column = $this->sanitizeColumn( $column );

			// Use appropriate format based on value type.
			if ( is_int( $value ) ) {
				$set_parts[] = $this->wpdb->prepare( "{$sanitized_column} = %d", $value );
			} elseif ( is_float( $value ) ) {
				$set_parts[] = $this->wpdb->prepare( "{$sanitized_column} = %f", $value );
			} else {
				$set_parts[] = $this->wpdb->prepare( "{$sanitized_column} = %s", $value );
			}
		}

		$where = $this->buildWhereClause( $criteria );

		$sql = "UPDATE {$this->table} SET " . implode( ', ', $set_parts );

		if ( ! empty( $where['clause'] ) ) {
			$sql .= " WHERE {$where['clause']}";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->query( $sql );
	}

	/**
	 * Delete multiple rows matching criteria.
	 *
	 * @param array $criteria The criteria to match.
	 * @return int Number of rows deleted.
	 */
	public function bulkDelete( array $criteria ): int {
		$where = $this->buildWhereClause( $criteria );

		if ( empty( $where['clause'] ) ) {
			return 0;
		}

		if ( $this->soft_deletes ) {
			return $this->bulkUpdate(
				array( $this->deleted_at_column => current_time( 'mysql' ) ),
				$criteria
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->wpdb->query( "DELETE FROM {$this->table} WHERE {$where['clause']}" );
	}

	/**
	 * Get the last database error.
	 *
	 * @return string The error message.
	 */
	public function getLastError(): string {
		return $this->wpdb->last_error;
	}
}
