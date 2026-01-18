# Payment Gateways

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for payment gateway integrations, which will handle:
- Multiple payment provider implementations
- Gateway-specific adapters and clients
- Payment method capabilities and validation
- Gateway-specific webhook handlers

## Migration Plan

This is part of the Phase 2 architectural work outlined in `docs/architecture-improvement-plan.md`. Each gateway will include:

- `{Gateway}Adapter.php` - Implementation of payment gateway contract
- `{Gateway}Client.php` - HTTP client for gateway API
- `{Gateway}WebhookHandler.php` - Gateway-specific webhook processing
- Gateway configuration and credentials management

## Current State

Core payment processing exists in `includes/Application/Services/PaymentService.php` and related infrastructure. This directory will house gateway-specific implementations following a common contract pattern.

## Planned Gateways

- Stripe
- PayPal
- WooCommerce Payments
- Other regional/specialized gateways as needed

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 2 - Feature modularity)
- Parent: `includes/Features/Payments/` - Core payment abstractions
