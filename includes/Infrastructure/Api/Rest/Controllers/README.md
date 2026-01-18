# REST Controllers

**Status:** Migration in progress

## Purpose

This directory is intended for REST API endpoint controllers that:
- Handle inbound HTTP requests to the plugin's REST API
- Validate and sanitize request inputs
- Delegate to application services for business logic
- Format and return API responses
- Handle authentication and authorization

## Migration Plan

This is part of the Phase 5 architectural work outlined in `docs/architecture-improvement-plan.md` (Presentation layer cleanup). Controllers should be migrated here from the top-level `includes/Controllers/` directory.

## Current State

**Active REST controllers currently live in:** `includes/Controllers/`

Existing implementations:
- `AbstractController.php` - Base controller with common functionality
- `WebhookController.php` - WhatsApp webhook endpoints
- `AdminAjaxController.php` - Admin AJAX handlers
- `CheckoutController.php` - Checkout API endpoints
- And others...

## Target State

All REST controller implementations should eventually move here to follow the structure:
```
includes/
  Infrastructure/
    Api/
      Rest/
        Controllers/         ← REST controllers (this directory)
          AbstractController.php
          WebhookController.php
          ...
      Clients/              ← External API clients
```

This separates:
- **Inbound HTTP** (REST controllers) - Requests coming INTO the plugin
- **Outbound HTTP** (API clients) - Requests going OUT to external services

## Controller Responsibilities

Controllers should be thin and focused on HTTP concerns:
- Request validation and input sanitization
- Authentication/authorization checks
- Delegating to application services
- Response formatting and error handling
- Rate limiting and security checks

Business logic should live in `includes/Application/Services/` and be injected via constructor.

## Migration Steps

1. Keep existing `includes/Controllers/` functioning (no breaking changes)
2. Ensure all controllers extend `AbstractController`
3. Standardize auth, rate limiting, and signature verification
4. Gradually move controller classes here
5. Update namespace and imports
6. Remove old location once migration is verified

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 5)
- Current location: `includes/Controllers/`
- Application services: `includes/Application/Services/`
