# Broadcasts Feature

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for the Broadcasts feature module, which will handle:
- Mass messaging campaigns to customer segments
- Scheduled broadcast messages
- Broadcast analytics and tracking
- Template message distribution

## Migration Plan

This is part of the Phase 2 architectural work outlined in `docs/architecture-improvement-plan.md`. The module will include:

- `BroadcastService.php` - Core broadcast orchestration
- `BroadcastRepository.php` - Persistence layer for broadcast campaigns
- `BroadcastProcessor.php` - Queue processor for broadcast execution
- Contracts for broadcast scheduling and targeting

## Current State

Currently, broadcast functionality (if any) may be scattered across other modules. This directory will consolidate all broadcast-related code once the feature modularity phase is completed.

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 2 - Feature modularity)
- Related: `includes/Features/` - Sibling feature modules
