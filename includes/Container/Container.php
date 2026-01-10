<?php
/**
 * Dependency Injection Container
 *
 * A lightweight PSR-11 compatible dependency injection container for WordPress.
 * Supports auto-wiring, lazy loading, and service providers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Container
 *
 * Main dependency injection container implementation.
 */
class Container implements ContainerInterface {

	/**
	 * The container's shared instances (singletons).
	 *
	 * @var array<string, mixed>
	 */
	protected array $instances = [];

	/**
	 * The container's bindings.
	 *
	 * @var array<string, array{concrete: callable|string|null, shared: bool}>
	 */
	protected array $bindings = [];

	/**
	 * The registered service providers.
	 *
	 * @var ServiceProviderInterface[]
	 */
	protected array $providers = [];

	/**
	 * Whether providers have been booted.
	 *
	 * @var bool
	 */
	protected bool $booted = false;

	/**
	 * The stack of classes being resolved (for circular dependency detection).
	 *
	 * @var array<string>
	 */
	protected array $resolving = [];

	/**
	 * The global container instance.
	 *
	 * @var Container|null
	 */
	protected static ?Container $instance = null;

	/**
	 * Get the global container instance.
	 *
	 * @return Container
	 */
	public static function getInstance(): Container {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Set the global container instance.
	 *
	 * Useful for testing to inject a mock container.
	 *
	 * @param Container|null $container The container instance or null to reset.
	 * @return void
	 */
	public static function setInstance( ?Container $container ): void {
		self::$instance = $container;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( string $id ): mixed {
		// Check for existing shared instance.
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Check for binding.
		if ( isset( $this->bindings[ $id ] ) ) {
			return $this->resolve( $id );
		}

		// Try to auto-resolve if it's a class.
		if ( class_exists( $id ) ) {
			return $this->make( $id );
		}

		throw new NotFoundException( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] )
			|| isset( $this->instances[ $id ] )
			|| class_exists( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function bind( string $abstract, callable|string|null $concrete = null, bool $shared = false ): void {
		// Remove any existing instance if rebinding.
		unset( $this->instances[ $abstract ] );

		$this->bindings[ $abstract ] = [
			'concrete' => $concrete ?? $abstract,
			'shared'   => $shared,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function singleton( string $abstract, callable|string|null $concrete = null ): void {
		$this->bind( $abstract, $concrete, true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function instance( string $abstract, mixed $instance ): mixed {
		$this->instances[ $abstract ] = $instance;
		return $instance;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceProviderInterface $provider ): void {
		$this->providers[] = $provider;
		$provider->register( $this );
	}

	/**
	 * Boot all registered service providers.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		foreach ( $this->providers as $provider ) {
			$provider->boot( $this );
		}

		$this->booted = true;
	}

	/**
	 * Check if the container has been booted.
	 *
	 * @return bool
	 */
	public function isBooted(): bool {
		return $this->booted;
	}

	/**
	 * {@inheritdoc}
	 */
	public function make( string $concrete, array $parameters = [] ): mixed {
		// Check for circular dependency.
		if ( in_array( $concrete, $this->resolving, true ) ) {
			$chain   = $this->resolving;
			$chain[] = $concrete;
			throw ContainerException::circularDependency( $chain );
		}

		$this->resolving[] = $concrete;

		try {
			$reflector = new \ReflectionClass( $concrete );

			if ( ! $reflector->isInstantiable() ) {
				throw ContainerException::notInstantiable( $concrete );
			}

			$constructor = $reflector->getConstructor();

			if ( null === $constructor ) {
				return new $concrete();
			}

			$dependencies = $this->resolveDependencies(
				$concrete,
				$constructor->getParameters(),
				$parameters
			);

			return $reflector->newInstanceArgs( $dependencies );
		} finally {
			array_pop( $this->resolving );
		}
	}

	/**
	 * Resolve a binding.
	 *
	 * @param string $abstract The abstract type.
	 * @return mixed
	 * @throws ContainerException If circular dependency detected.
	 */
	protected function resolve( string $abstract ): mixed {
		// Check for circular dependency - this catches alias chains like A->B->C->A.
		if ( in_array( $abstract, $this->resolving, true ) ) {
			$chain   = $this->resolving;
			$chain[] = $abstract;
			throw ContainerException::circularDependency( $chain );
		}

		$this->resolving[] = $abstract;

		try {
			$binding  = $this->bindings[ $abstract ];
			$concrete = $binding['concrete'];

			// Resolve the concrete implementation.
			if ( $concrete instanceof \Closure ) {
				$object = $concrete( $this );
			} elseif ( is_string( $concrete ) && $concrete !== $abstract ) {
				$object = $this->get( $concrete );
			} else {
				$object = $this->make( is_string( $concrete ) ? $concrete : $abstract );
			}

			// Store if shared.
			if ( $binding['shared'] ) {
				$this->instances[ $abstract ] = $object;
			}

			return $object;
		} finally {
			array_pop( $this->resolving );
		}
	}

	/**
	 * Resolve constructor dependencies.
	 *
	 * @param string                 $class      The class being resolved.
	 * @param \ReflectionParameter[] $parameters The constructor parameters.
	 * @param array                  $primitives Override values for primitive parameters.
	 * @return array
	 */
	protected function resolveDependencies(
		string $class,
		array $parameters,
		array $primitives = []
	): array {
		$dependencies = [];

		foreach ( $parameters as $parameter ) {
			$name = $parameter->getName();

			// Check for primitive override.
			if ( isset( $primitives[ $name ] ) ) {
				$dependencies[] = $primitives[ $name ];
				continue;
			}

			// Get type hint.
			$type = $parameter->getType();

			// Handle parameters without type hints.
			if ( null === $type || $type->isBuiltin() ) {
				if ( $parameter->isDefaultValueAvailable() ) {
					$dependencies[] = $parameter->getDefaultValue();
				} elseif ( $parameter->allowsNull() ) {
					$dependencies[] = null;
				} else {
					throw ContainerException::unresolvableParameter( $class, $name );
				}
				continue;
			}

			// Handle union types (PHP 8.0+).
			if ( $type instanceof \ReflectionUnionType ) {
				$resolved = false;
				foreach ( $type->getTypes() as $unionType ) {
					if ( $unionType->isBuiltin() ) {
						continue;
					}
					try {
						$dependencies[] = $this->get( $unionType->getName() );
						$resolved       = true;
						break;
					} catch ( NotFoundException $e ) {
						continue;
					}
				}
				if ( ! $resolved ) {
					if ( $parameter->isDefaultValueAvailable() ) {
						$dependencies[] = $parameter->getDefaultValue();
					} elseif ( $parameter->allowsNull() ) {
						$dependencies[] = null;
					} else {
						throw ContainerException::unresolvableParameter( $class, $name );
					}
				}
				continue;
			}

			// Handle named type.
			$typeName = $type->getName();

			try {
				$dependencies[] = $this->get( $typeName );
			} catch ( NotFoundException $e ) {
				if ( $parameter->isDefaultValueAvailable() ) {
					$dependencies[] = $parameter->getDefaultValue();
				} elseif ( $parameter->allowsNull() ) {
					$dependencies[] = null;
				} else {
					throw $e;
				}
			}
		}

		return $dependencies;
	}

	/**
	 * Call a method on an object with automatic dependency injection.
	 *
	 * @param object|string $target     The object or class::method string.
	 * @param string|null   $method     The method name (optional if target is class::method).
	 * @param array         $parameters Override parameters.
	 * @return mixed
	 */
	public function call( object|string $target, ?string $method = null, array $parameters = [] ): mixed {
		// Parse class::method syntax.
		if ( is_string( $target ) && str_contains( $target, '::' ) ) {
			[ $class, $method ] = explode( '::', $target, 2 );
			$target             = $this->get( $class );
		}

		if ( null === $method ) {
			throw new ContainerException( 'Method name is required' );
		}

		$reflector    = new \ReflectionMethod( $target, $method );
		$dependencies = $this->resolveDependencies(
			get_class( $target ),
			$reflector->getParameters(),
			$parameters
		);

		return $reflector->invokeArgs( $target, $dependencies );
	}

	/**
	 * Get all registered bindings.
	 *
	 * @return array<string, array{concrete: callable|string|null, shared: bool}>
	 */
	public function getBindings(): array {
		return $this->bindings;
	}

	/**
	 * Get all shared instances.
	 *
	 * @return array<string, mixed>
	 */
	public function getInstances(): array {
		return $this->instances;
	}

	/**
	 * Flush the container of all bindings and resolved instances.
	 *
	 * Clears all state including the resolving stack to prevent stale
	 * circular dependency detection after container reset.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->bindings  = [];
		$this->instances = [];
		$this->providers = [];
		$this->booted    = false;
		$this->resolving = [];
	}

	/**
	 * Create an alias for an existing binding.
	 *
	 * @param string $abstract The abstract type.
	 * @param string $alias    The alias.
	 * @return void
	 */
	public function alias( string $abstract, string $alias ): void {
		$this->bindings[ $alias ] = [
			'concrete' => $abstract,
			'shared'   => false, // Alias doesn't affect sharing, uses the target's setting.
		];
	}

	/**
	 * Extend a binding with a decorator.
	 *
	 * @param string   $abstract The abstract type.
	 * @param callable $callback The decorator callback.
	 * @return void
	 */
	public function extend( string $abstract, callable $callback ): void {
		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			throw new NotFoundException( $abstract );
		}

		$original = $this->bindings[ $abstract ];

		$this->bindings[ $abstract ] = [
			'concrete' => function ( Container $container ) use ( $abstract, $original, $callback ) {
				$concrete = $original['concrete'];

				// Resolve original.
				if ( $concrete instanceof \Closure ) {
					$object = $concrete( $container );
				} else {
					$object = $container->make( is_string( $concrete ) ? $concrete : $abstract );
				}

				// Apply decorator.
				return $callback( $object, $container );
			},
			'shared'   => $original['shared'],
		];

		// Clear cached instance if it exists.
		unset( $this->instances[ $abstract ] );
	}

	/**
	 * Register a callback to run when a specific type is resolved.
	 *
	 * @param string   $abstract The abstract type.
	 * @param callable $callback The callback to run.
	 * @return void
	 */
	public function resolving( string $abstract, callable $callback ): void {
		$this->extend(
			$abstract,
			function ( $object, Container $container ) use ( $callback ) {
				$callback( $object, $container );
				return $object;
			}
		);
	}
}
