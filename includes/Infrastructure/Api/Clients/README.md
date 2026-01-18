# API Clients

**Status:** Migration in progress

## Purpose

This directory is intended for external API client implementations that:
- Communicate with third-party services (WhatsApp, OpenAI, payment gateways, etc.)
- Handle authentication, rate limiting, and retry logic
- Abstract API-specific details behind service contracts
- Provide consistent error handling for external dependencies

## Migration Plan

This is part of the infrastructure layer organization. Clients should be migrated here from the top-level `includes/Clients/` directory to align with layered architecture.

## Current State

**Active API clients currently live in:** `includes/Clients/`

Existing implementations:
- `WhatsAppApiClient.php` - WhatsApp Business API integration
- `OpenAIClient.php` - OpenAI API for AI features
- `HttpClient.php` - Base HTTP client with resilience features
- And others...

## Target State

All API client implementations should eventually move here to follow the structure:
```
includes/
  Infrastructure/
    Api/
      Clients/               ← Client implementations (this directory)
        WhatsAppApiClient.php
        OpenAIClient.php
        ...
      Rest/
        Controllers/         ← REST endpoint controllers
```

This aligns API clients with other infrastructure concerns and separates inbound (REST controllers) from outbound (API clients) concerns.

## Migration Steps

1. Keep existing `includes/Clients/` functioning (no breaking changes)
2. Gradually move client classes here
3. Update namespace from `WhatsAppCommerceHub\Clients\*` to `WhatsAppCommerceHub\Infrastructure\Api\Clients\*`
4. Update service provider bindings
5. Update imports across codebase
6. Remove old location once migration is verified

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 2 - Feature modularity)
- Current location: `includes/Clients/`
- Resilience features: `includes/Resilience/` (circuit breakers, retry policies)
