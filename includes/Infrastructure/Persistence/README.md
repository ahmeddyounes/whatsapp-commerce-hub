# Persistence Layer

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for persistence abstractions and utilities that:
- Provide common persistence patterns (Unit of Work, Identity Map, etc.)
- Handle entity hydration and mapping
- Manage database transactions and connection pooling
- Abstract persistence concerns from repositories

## Migration Plan

This is part of the Phase 1 architectural work outlined in `docs/architecture-improvement-plan.md`. The persistence layer will include:

- `EntityManager.php` - Coordinates persistence operations
- `Hydrator.php` - Maps database rows to domain objects
- `UnitOfWork.php` - Tracks entity changes for batch operations
- `TransactionManager.php` - Manages database transactions
- `QueryBuilder.php` - Fluent query building interface

## Current State

Persistence logic is currently implemented directly in repositories using wpdb. This creates:
- Code duplication across repositories
- Inconsistent error handling
- Direct coupling to WordPress database API
- Difficulty testing persistence logic

## Target State

Repositories will delegate to persistence layer services:

```php
// Current (direct wpdb usage)
class CartRepository {
    public function find($id) {
        global $wpdb;
        $row = $wpdb->get_row(...);
        // Manual hydration
        return new Cart(...);
    }
}

// Target (using persistence layer)
class CartRepository {
    public function find($id) {
        return $this->entityManager->find(Cart::class, $id);
    }
}
```

## Benefits

- Centralized query building and optimization
- Consistent caching strategy
- Easier to swap persistence mechanisms
- Better testability with mock persistence layer

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 1)
- Current repositories: `includes/Repositories/`
- Database manager: `includes/Infrastructure/Database/DatabaseManager.php`
