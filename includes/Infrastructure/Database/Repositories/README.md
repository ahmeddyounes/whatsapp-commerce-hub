# Database Repositories

**Status:** Migration in progress

## Purpose

This directory is intended for database repository implementations that:
- Provide data access layer for domain entities
- Abstract wpdb operations behind repository contracts
- Implement query optimization and caching strategies
- Follow repository pattern for testability

## Migration Plan

This is part of the Phase 1 architectural work outlined in `docs/architecture-improvement-plan.md`. Repositories should be migrated here from the top-level `includes/Repositories/` directory to align with layered architecture.

## Current State

**Active repositories currently live in:** `includes/Repositories/`

Existing implementations:
- `CartRepository.php`
- `ConversationRepository.php`
- `CustomerRepository.php`
- `IntentRepository.php`
- `ProductSyncRepository.php`
- And others...

## Target State

All repository implementations should eventually move here to follow the structure:
```
includes/
  Infrastructure/
    Database/
      Repositories/        ← Repository implementations (this directory)
        CartRepository.php
        ConversationRepository.php
        ...
      Migrations/          ← Schema migrations
```

This aligns repositories with other infrastructure concerns and makes the architectural layers clearer.

## Migration Steps

1. Keep existing `includes/Repositories/` functioning (no breaking changes)
2. Gradually move repository classes here
3. Update namespace imports across codebase
4. Add aliases if needed for backward compatibility
5. Remove old location once migration is verified

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 1 - Define canonical models)
- Current location: `includes/Repositories/`
- Contracts: `includes/Contracts/Repositories/`
