# Service Providers

Service providers are responsible for registering and bootstrapping services in the dependency injection container.

## Architecture Rules

### 1. No `wch()` Calls in Providers

**CRITICAL:** Service providers MUST NOT use the `wch()` global helper function. Instead, they must use the injected `$container` parameter exclusively.

#### ❌ Wrong
```php
public function register( ContainerInterface $container ): void {
    $container->singleton(
        SomeService::class,
        static function () {
            // BAD: Using wch() inside provider
            $dependency = wch( DependencyInterface::class );
            return new SomeService( $dependency );
        }
    );
}
```

#### ✅ Correct
```php
public function register( ContainerInterface $container ): void {
    $container->singleton(
        SomeService::class,
        static fn( ContainerInterface $c ) => new SomeService(
            $c->get( DependencyInterface::class )
        )
    );
}
```

#### Why This Rule Exists

1. **Testability**: Using `wch()` creates an implicit dependency on the global container, making providers harder to test in isolation.

2. **Determinism**: The `wch()` function accesses the global container, which may not be the same instance passed to the provider. This can lead to unpredictable behavior.

3. **Explicit Dependencies**: Using the injected `$container` parameter makes dependencies explicit and clear.

4. **Circular Dependency Detection**: The container can properly detect and prevent circular dependencies when all access goes through the injected parameter.

#### Enforcement

This rule is enforced by:
- PHPStan rule: `NoWchCallsInProvidersRule`
- Run `composer analyze` to check for violations

### 2. Using the Container in Providers

When registering services, you have two options:

#### Option A: Use Container in Factory Closure
```php
$container->singleton(
    ServiceInterface::class,
    static fn( ContainerInterface $c ) => new Service(
        $c->get( DependencyInterface::class )
    )
);
```

#### Option B: Use Autowiring (if dependencies are type-hinted in constructor)
```php
$container->singleton(
    ServiceInterface::class,
    static fn( ContainerInterface $c ) => $c->make( Service::class )
);
```

### 3. Context-Aware Booting

Providers can implement `shouldBoot()` to control when they boot based on context:

```php
public function shouldBoot(): bool {
    // Only boot in admin context
    return $this->isAdmin();
}
```

Available context methods (from `AbstractServiceProvider`):
- `isAdmin()` - WordPress admin area
- `isRest()` - REST API request
- `isAjax()` - AJAX request
- `isCron()` - Cron job
- `isFrontend()` - Frontend request

## Provider Lifecycle

1. **Registration Phase** (`register()`)
   - Register service definitions
   - Set up container bindings
   - Do NOT instantiate services yet
   - Do NOT add WordPress hooks yet

2. **Boot Phase** (`boot()`)
   - Initialize services that need early setup
   - Add WordPress hooks
   - Perform side effects
   - Services can be resolved from container

## Best Practices

1. Always use the injected `$container` parameter
2. Prefer type-hinted constructor injection
3. Keep registration logic simple
4. Use `shouldBoot()` to avoid unnecessary initialization
5. Declare dependencies in `dependsOn()`
6. List provided services in `provides()`

## See Also

- [C03-04 Implementation Plan](.plans/C03-04.md)
- [Container Documentation](../Container/README.md)
