# Presentation Actions

**Status:** Planned, not yet implemented

## Purpose

This directory is reserved for WordPress action handlers that bridge the presentation layer, including:
- Admin page render actions
- Form submission handlers (non-AJAX)
- WordPress hook handlers for UI updates
- Template rendering coordination

## Migration Plan

This is part of the Phase 5 architectural work outlined in `docs/architecture-improvement-plan.md` (Presentation layer cleanup). This directory will consolidate action handlers that are currently scattered.

## Current State

Action handlers are currently distributed across:
- `includes/Admin/` - Admin-specific hooks
- `includes/Presentation/Admin/` - Modern admin presentation
- Various service providers
- Individual service classes

## Target State

Action handlers should be organized here by concern:

```
includes/
  Presentation/
    Actions/
      AdminActions.php      ← Admin page lifecycle hooks
      AssetActions.php      ← Script/style enqueuing
      FormActions.php       ← Form submission handlers
      TemplateActions.php   ← Template rendering hooks
```

## Action Handler Pattern

```php
namespace WhatsAppCommerceHub\Presentation\Actions;

class AdminActions {
    public function __construct(
        private SettingsService $settings,
        private AssetManager $assets
    ) {}

    public function register(): void {
        add_action('admin_menu', [$this, 'registerMenuPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenuPages(): void {
        // Delegate to admin page classes
    }

    public function enqueueAssets(): void {
        // Delegate to asset manager
    }
}
```

## Responsibilities

Actions should:
- Be registered via service providers
- Delegate business logic to application services
- Handle WordPress-specific hook signatures
- Remain thin and focused on coordination

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 5)
- Current admin code: `includes/Admin/`, `includes/Presentation/Admin/`
- Service providers: `includes/Providers/`
