# Notifications Feature

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for the Notifications feature module, which will handle:
- Order status notifications
- Abandoned cart reminders
- Customer engagement notifications
- System alerts and notifications

## Migration Plan

This is part of the Phase 2 architectural work outlined in `docs/architecture-improvement-plan.md`. The module will include:

- `OrderNotifications.php` - Order-related notification service
- `NotificationTemplateManager.php` - Template management for notifications
- `NotificationDispatcher.php` - Routing and delivery coordination
- Contracts for notification scheduling and delivery

## Current State

Currently references to `WhatsAppCommerceHub\Features\Notifications\OrderNotifications` exist in bootstrap code but are not implemented. This directory will house the actual implementation once boot consolidation (Phase 0) and feature modularity (Phase 2) are completed.

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 0 & Phase 2)
- Bootstrap: `whatsapp-commerce-hub.php` - Contains placeholder references
