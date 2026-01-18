# Reengagement Feature

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for the Reengagement feature module, which will handle:
- Customer win-back campaigns
- Inactive customer targeting
- Reengagement analytics and tracking
- Personalized reengagement strategies

## Migration Plan

This is part of the Phase 2 architectural work outlined in `docs/architecture-improvement-plan.md`. The module will include:

- `ReengagementService.php` - Core reengagement orchestration
- `ReengagementStrategy.php` - Strategy pattern for different reengagement approaches
- `CustomerActivityTracker.php` - Tracking customer engagement levels
- Contracts for reengagement campaign management

## Current State

Currently references to `WhatsAppCommerceHub\Features\Reengagement\ReengagementService` exist in bootstrap code but are not implemented. This directory will house the actual implementation once boot consolidation (Phase 0) and feature modularity (Phase 2) are completed.

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 0 & Phase 2)
- Bootstrap: `whatsapp-commerce-hub.php` - Contains placeholder references
