# Legacy Code

**Status:** Migration target for deprecated code

## Purpose

This directory is reserved for legacy code during the architectural migration process. Code placed here:
- Is deprecated and scheduled for removal
- Should not be used in new code
- May be referenced for backward compatibility only
- Will be removed once migration is complete

## Migration Plan

As part of the architectural modernization outlined in `docs/architecture-improvement-plan.md`:

1. Legacy `WCH_*` classes will be moved here when deprecated
2. Each legacy class should include `@deprecated` annotations with migration path
3. Facade/adapter classes may temporarily bridge legacy and modern code
4. Once all references are migrated, files will be removed

## Current State

Empty - no legacy code has been formally deprecated yet. The migration is ongoing, with modern namespaced architecture (`WhatsAppCommerceHub\*`) replacing older patterns.

## Guidelines for Using This Directory

- **Never add new code here** - This is for deprecation only
- Include clear deprecation notices and migration instructions
- Add PHP deprecation warnings (`trigger_error()`) where appropriate
- Reference the modern replacement in comments

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 0, Phase 1, Phase 6)
- Current architecture: Modern code lives in `includes/` with proper namespacing
