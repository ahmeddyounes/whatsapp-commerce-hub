# Implementation Summary: C03-04

## Task
Ban `wch()` usage in service providers to ensure deterministic and testable provider code.

## Status
✅ **DONE**

## Changes Made

### 1. PHPStan Rule Implementation
**File:** `phpstan-rules/NoWchCallsInProvidersRule.php`

Created a custom PHPStan rule that:
- Scans all files in `includes/Providers/` directory
- Detects any calls to the `wch()` function
- Reports error: "Service providers must not call wch(). Use the injected $container parameter instead."
- Uses identifier `wch.providerPurity` for easy filtering

### 2. PHPStan Configuration Update
**File:** `phpstan.neon`

Added the new rule to the PHPStan configuration:
```yaml
rules:
  - WhatsAppCommerceHub\PHPStan\Rules\NoWordPressFunctionsInDomainRule
  - WhatsAppCommerceHub\PHPStan\Rules\NoWordPressGlobalsInDomainRule
  - WhatsAppCommerceHub\PHPStan\Rules\NoWchCallsInProvidersRule  # New
```

### 3. Documentation
**File:** `includes/Providers/README.md`

Created comprehensive documentation covering:
- Architecture rule: No `wch()` in providers
- Code examples (wrong vs. correct)
- Rationale (testability, determinism, explicit dependencies)
- Enforcement mechanism
- Best practices for using container in providers
- Provider lifecycle explanation
- Context-aware booting guidelines

### 4. Verification Script
**File:** `.verification/test-c03-04.sh`

Created automated verification script that:
- Checks for existing `wch()` usage in providers (should be none)
- Verifies PHPStan rule file exists
- Verifies PHPStan configuration includes the rule
- Tests rule enforcement by creating temporary violating code
- Verifies documentation exists and mentions the ban

### 5. State Update
**File:** `.t2/state.json`

Marked task C03-04 as completed.

## Verification Results

All verification tests pass:
```
✓ PASSED: No wch() usage found in provider files
✓ PASSED: PHPStan rule file exists
✓ PASSED: PHPStan rule is configured
✓ PASSED: PHPStan rule correctly detects wch() usage
✓ PASSED: Documentation exists and mentions wch() ban
```

## How to Verify

### Run verification script:
```bash
.verification/test-c03-04.sh
```

### Run PHPStan on providers:
```bash
composer analyze includes/Providers/
```

### Check for wch() usage:
```bash
grep -r "wch(" includes/Providers/*.php
# Should return no results
```

## Impact

### Positive
1. **Testability**: Providers can now be tested in isolation without global state
2. **Determinism**: All container access is explicit and traceable
3. **Maintainability**: Clear dependency graph, easier to understand
4. **Safety**: Prevents circular dependency bugs from implicit container access
5. **Code Quality**: Automated enforcement prevents regressions

### Risks / Follow-ups
- **None identified**: All existing providers already follow this pattern
- **Educational**: New developers need to be aware of this rule (documented in README)

## Technical Details

### Why Ban `wch()` in Providers?

1. **Global State Problem**: `wch()` accesses a global container instance, creating hidden dependencies
2. **Testing Difficulty**: Hard to mock or replace dependencies when using global helpers
3. **Circular Dependencies**: Can mask circular dependency issues that should be caught
4. **Container Isolation**: Providers receive a specific container instance - should use it exclusively

### The Solution

Providers must use the injected `$container` parameter:

```php
// Before (if it existed - it didn't, but this is what we're preventing)
public function register( ContainerInterface $container ): void {
    $container->singleton(
        ServiceInterface::class,
        static function () {
            $dep = wch( DependencyInterface::class ); // ❌ Bad
            return new Service( $dep );
        }
    );
}

// After (correct way)
public function register( ContainerInterface $container ): void {
    $container->singleton(
        ServiceInterface::class,
        static fn( ContainerInterface $c ) => new Service(
            $c->get( DependencyInterface::class ) // ✅ Good
        )
    );
}
```

## Related Tasks
- C03-01: Eliminate global `$wch_container`
- C03-02: Providers use injected `$container`
- C03-03: Extract `wch_container()` bootstrap

## References
- Spec: `.plans/C03-04.md`
- PHPStan Rule: `phpstan-rules/NoWchCallsInProvidersRule.php`
- Documentation: `includes/Providers/README.md`
