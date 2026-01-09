<?php
/**
 * Mock Container for Testing
 *
 * Provides a mock DI container for isolated unit testing.
 *
 * @package WhatsApp_Commerce_Hub
 */

namespace WhatsAppCommerceHub\Tests\Mocks;

use WhatsAppCommerceHub\Container\ContainerInterface;

/**
 * Class MockContainer
 *
 * A test double for the DI container that allows easy service mocking.
 */
class MockContainer implements ContainerInterface {

	/**
	 * Registered services.
	 *
	 * @var array
	 */
	private array $services = array();

	/**
	 * Service factories.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved singleton instances.
	 *
	 * @var array
	 */
	private array $instances = array();

	/**
	 * Register a mock service.
	 *
	 * @param string $id Service identifier.
	 * @param mixed  $service Service instance or mock.
	 * @return self
	 */
	public function set( string $id, mixed $service ): self {
		$this->services[ $id ] = $service;
		return $this;
	}

	/**
	 * Register a factory for lazy instantiation.
	 *
	 * @param string   $id Service identifier.
	 * @param callable $factory Factory callable.
	 * @return self
	 */
	public function setFactory( string $id, callable $factory ): self {
		$this->factories[ $id ] = $factory;
		return $this;
	}

	/**
	 * Register a singleton service.
	 *
	 * @param string               $id Service identifier.
	 * @param callable|object|null $concrete Concrete implementation.
	 * @return void
	 */
	public function singleton( string $id, callable|object|null $concrete = null ): void {
		if ( is_callable( $concrete ) ) {
			$this->factories[ $id ] = $concrete;
		} else {
			$this->services[ $id ] = $concrete;
		}
	}

	/**
	 * Register a binding.
	 *
	 * @param string               $id Service identifier.
	 * @param callable|object|null $concrete Concrete implementation.
	 * @return void
	 */
	public function bind( string $id, callable|object|null $concrete = null ): void {
		$this->singleton( $id, $concrete );
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws \RuntimeException If service not found.
	 */
	public function get( string $id ): mixed {
		// Return existing instance.
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Return pre-set service.
		if ( isset( $this->services[ $id ] ) ) {
			$this->instances[ $id ] = $this->services[ $id ];
			return $this->instances[ $id ];
		}

		// Resolve from factory.
		if ( isset( $this->factories[ $id ] ) ) {
			$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );
			return $this->instances[ $id ];
		}

		throw new \RuntimeException( "Service not found: {$id}" );
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->services[ $id ] ) || isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Reset the container (useful between tests).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->services  = array();
		$this->factories = array();
		$this->instances = array();
	}

	/**
	 * Clear resolved instances (keep registrations).
	 *
	 * @return void
	 */
	public function clearInstances(): void {
		$this->instances = array();
	}

	/**
	 * Get all registered service IDs.
	 *
	 * @return array<string>
	 */
	public function getRegisteredIds(): array {
		return array_unique(
			array_merge(
				array_keys( $this->services ),
				array_keys( $this->factories ),
				array_keys( $this->instances )
			)
		);
	}

	/**
	 * Create a pre-configured container with common mocks.
	 *
	 * @return self
	 */
	public static function createWithCommonMocks(): self {
		$container = new self();

		// Mock wpdb.
		$container->set( \wpdb::class, self::createMockWpdb() );
		$container->set( 'wpdb', $container->get( \wpdb::class ) );

		// Mock settings.
		$container->set(
			'wch.settings',
			array(
				'phone_number_id'       => 'test_phone_id',
				'business_account_id'   => 'test_business_id',
				'access_token'          => 'test_token',
				'verify_token'          => 'test_verify',
				'store_currency'        => 'USD',
				'enable_ai_chat'        => true,
				'enable_cart_recovery'  => true,
				'cart_expiry_hours'     => 72,
			)
		);

		// Mock logger.
		$container->set( 'wch.logger', new MockLogger() );

		// Mock cache.
		$container->set( 'wch.cache', new MockCache() );

		return $container;
	}

	/**
	 * Create a mock wpdb instance.
	 *
	 * @return object
	 */
	private static function createMockWpdb(): object {
		// Define OBJECT constant if not already defined (for test environments).
		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}

		return new class {
			public string $prefix = 'wp_';
			public array $results = array();
			public ?int $insert_id = null;
			private int $insert_sequence = 0;

			public function prepare( string $query, ...$args ): string {
				// Handle WordPress-style placeholders: %s (string), %d (integer), %f (float).
				$prepared = $query;
				foreach ( $args as $arg ) {
					if ( is_string( $arg ) ) {
						$prepared = preg_replace( '/%s/', "'" . addslashes( $arg ) . "'", $prepared, 1 );
					} elseif ( is_int( $arg ) ) {
						$prepared = preg_replace( '/%d/', (string) $arg, $prepared, 1 );
					} elseif ( is_float( $arg ) ) {
						$prepared = preg_replace( '/%f/', (string) $arg, $prepared, 1 );
					} else {
						$prepared = preg_replace( '/%[sdf]/', "'" . addslashes( (string) $arg ) . "'", $prepared, 1 );
					}
				}
				return $prepared;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->results;
			}

			public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): ?object {
				return $this->results[0] ?? null;
			}

			public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
				return null;
			}

			public function get_col( string $query, int $x = 0 ): array {
				return array();
			}

			public function insert( string $table, array $data, ?array $format = null ): int|false {
				++$this->insert_sequence;
				$this->insert_id = $this->insert_sequence;
				return 1;
			}

			public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|false {
				return 1;
			}

			public function delete( string $table, array $where, ?array $where_format = null ): int|false {
				return 1;
			}

			public function query( string $query ): int|bool {
				return true;
			}
		};
	}
}

/**
 * Mock Logger for testing.
 */
class MockLogger {
	public array $logs = array();

	public function debug( string $message, array $context = array() ): void {
		$this->logs[] = array( 'level' => 'debug', 'message' => $message, 'context' => $context );
	}

	public function info( string $message, array $context = array() ): void {
		$this->logs[] = array( 'level' => 'info', 'message' => $message, 'context' => $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->logs[] = array( 'level' => 'warning', 'message' => $message, 'context' => $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->logs[] = array( 'level' => 'error', 'message' => $message, 'context' => $context );
	}

	public function getLogs( ?string $level = null ): array {
		if ( null === $level ) {
			return $this->logs;
		}
		return array_filter( $this->logs, fn( $log ) => $log['level'] === $level );
	}

	public function clear(): void {
		$this->logs = array();
	}
}

/**
 * Mock Cache for testing.
 */
class MockCache {
	private array $cache = array();

	public function get( string $key, mixed $default = null ): mixed {
		return $this->cache[ $key ] ?? $default;
	}

	public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
		$this->cache[ $key ] = $value;
		return true;
	}

	public function delete( string $key ): bool {
		unset( $this->cache[ $key ] );
		return true;
	}

	public function remember( string $key, callable $callback, int $ttl = 3600 ): mixed {
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}
		$value              = $callback();
		$this->cache[ $key ] = $value;
		return $value;
	}

	public function flush( string $prefix = '' ): void {
		if ( empty( $prefix ) ) {
			$this->cache = array();
		} else {
			foreach ( array_keys( $this->cache ) as $key ) {
				if ( str_starts_with( $key, $prefix ) ) {
					unset( $this->cache[ $key ] );
				}
			}
		}
	}
}
