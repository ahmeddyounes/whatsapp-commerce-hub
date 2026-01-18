# Domain Layer Purity

## Overview

The Domain layer in WhatsApp Commerce Hub follows strict architectural boundaries to maintain code quality, testability, and maintainability. Domain entities and services must not directly call WordPress or global functions.

## Rule Enforcement

PHPStan custom rules automatically enforce domain purity. These rules are defined in:

- `phpstan-rules/NoWordPressFunctionsInDomainRule.php` - Prevents WordPress function calls
- `phpstan-rules/NoWordPressGlobalsInDomainRule.php` - Prevents WordPress global variable access

## Forbidden in Domain Layer

The following WordPress functions and globals are **forbidden** in `includes/Domain/**`:

### WordPress Hooks
- `do_action()`, `apply_filters()`
- `add_action()`, `add_filter()`
- `remove_action()`, `remove_filter()`
- `did_action()`, `has_filter()`

### WordPress Options
- `get_option()`, `update_option()`
- `delete_option()`, `add_option()`

### WordPress Data Functions
- `wp_json_encode()` - use `json_encode()` instead
- `wp_json_decode()` - use `json_decode()` instead
- `current_time()` - use `new \DateTimeImmutable()` instead

### WordPress Post/Meta Functions
- `wp_insert_post()`, `wp_update_post()`
- `get_post()`, `get_posts()`
- `update_post_meta()`, `get_post_meta()`, `delete_post_meta()`

### WordPress Database Access
- `global $wpdb` - use Repository pattern instead

### WordPress User Functions
- `get_current_user_id()`, `wp_get_current_user()`

## Allowed Alternatives

Instead of forbidden functions, use:

1. **For JSON encoding:**
   ```php
   // ❌ Forbidden
   $json = wp_json_encode($data);

   // ✅ Allowed
   $json = json_encode($data, JSON_THROW_ON_ERROR);
   ```

2. **For timestamps:**
   ```php
   // ❌ Forbidden
   $now = current_time('mysql');

   // ✅ Allowed
   $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
   ```

3. **For database access:**
   ```php
   // ❌ Forbidden - Direct database access
   global $wpdb;
   $wpdb->get_row("SELECT...");

   // ✅ Allowed - Use Repository pattern
   $customer = $this->customerRepository->findByPhone($phone);
   ```

4. **For logging:**
   ```php
   // ❌ Forbidden
   do_action('wch_log_error', 'Error message', ['context' => 'data']);

   // ✅ Allowed - Return error/exception, let Infrastructure layer handle logging
   throw new \RuntimeException('Error message');
   ```

## Migration Strategy

When migrating existing code:

1. **Move WordPress-specific code to Infrastructure layer:**
   - Database queries → Repository classes
   - Logging → Application/Infrastructure services
   - WordPress API calls → Infrastructure adapters

2. **Inject dependencies:**
   - Pass Repository interfaces to Domain services
   - Use dependency injection for Infrastructure services

3. **Replace WordPress utilities:**
   - Use native PHP functions where possible
   - Create pure PHP abstractions for WordPress features

## Example: Moving Database Access

### Before (❌ Violates domain purity)
```php
// includes/Domain/Cart/CartService.php
private function getCouponUsageCount(int $coupon_id, string $phone): int {
    global $wpdb;
    $table = $wpdb->prefix . 'wch_coupon_phone_usage';

    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND phone = %s",
            $coupon_id,
            $phone
        )
    );

    return (int) $count;
}
```

### After (✅ Clean separation)
```php
// includes/Infrastructure/Persistence/CouponUsageTracker.php
class CouponUsageTracker {
    public function getUsageCount(int $coupon_id, string $phone): int {
        global $wpdb;
        // WordPress-specific implementation here
        return (int) $count;
    }
}

// includes/Domain/Cart/CartService.php
class CartService {
    public function __construct(
        private CartRepositoryInterface $repository,
        private CouponUsageTracker $couponTracker
    ) {}

    private function checkCouponUsage(int $coupon_id, string $phone): int {
        return $this->couponTracker->getUsageCount($coupon_id, $phone);
    }
}
```

## Running PHPStan

To verify domain purity:

```bash
# Check entire Domain layer
vendor/bin/phpstan analyse includes/Domain --level=5 --memory-limit=1G

# Check specific file
vendor/bin/phpstan analyse includes/Domain/Cart/Cart.php --level=5
```

PHPStan will report violations with error code `wch.domainPurity`.

## Benefits

Maintaining domain purity provides:

1. **Better testability** - Domain code can be unit tested without WordPress
2. **Clearer architecture** - Clear separation of concerns
3. **Easier maintenance** - Changes to infrastructure don't affect domain logic
4. **Portability** - Domain logic could theoretically work with different frameworks
5. **Type safety** - Better IDE support and static analysis

## Related Documentation

- [PHPStan Configuration](../../phpstan.neon)
- [Repository Pattern](./repository-pattern.md)
- [Dependency Injection](./dependency-injection.md)

## References

- Implementation: C01-04 - Domain Layer Purity Guardrail
- Spec: `.plans/C01-04.md`
