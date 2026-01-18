# Admin Settings Pages

**Status:** Migration in progress

## Purpose

This directory is intended for admin settings page implementations that:
- Render settings forms and UI
- Handle settings validation and sanitization
- Organize settings into logical sections/tabs
- Provide contextual help and documentation

## Migration Plan

This is part of the Phase 5 architectural work outlined in `docs/architecture-improvement-plan.md` (Presentation layer cleanup). Settings pages should be organized here for better structure.

## Current State

Settings-related code is currently distributed across:
- `includes/Admin/` - Legacy admin pages
- `includes/Presentation/Admin/` - Modern admin pages
- `includes/Controllers/AdminAjaxController.php` - AJAX settings handlers
- `includes/Infrastructure/Configuration/SettingsManager.php` - Settings storage

The architecture plan also notes that a single home for admin UI should be chosen between `includes/Presentation/Admin/*` and `includes/Admin/*`.

## Target State

Settings pages should be organized here by feature:

```
includes/
  Presentation/
    Admin/
      Settings/
        GeneralSettingsPage.php    ← General/API settings
        WhatsAppSettingsPage.php   ← WhatsApp-specific config
        PaymentSettingsPage.php    ← Payment gateway settings
        SecuritySettingsPage.php   ← Security & encryption
        AdvancedSettingsPage.php   ← Advanced/debugging
```

Each page class should:
- Extend a common `AbstractSettingsPage` base class
- Use the `SettingsInterface` service for reading/writing
- Implement consistent validation and error handling
- Delegate storage to `SettingsService`

## Settings Page Pattern

```php
namespace WhatsAppCommerceHub\Presentation\Admin\Settings;

class GeneralSettingsPage extends AbstractSettingsPage {
    public function render(): void {
        // Render form using template
    }

    public function validate(array $input): array {
        // Validate and sanitize
        return $validated;
    }

    public function getSections(): array {
        return ['api', 'general', 'notifications'];
    }
}
```

## References

- Architecture Plan: `docs/architecture-improvement-plan.md` (Phase 0 - Settings single source of truth, Phase 5 - Presentation cleanup)
- Settings service: `includes/Application/Services/SettingsService.php`
- Settings documentation: `SETTINGS_DOCUMENTATION.md`
- Deprecation notes: `docs/SETTINGS-DEPRECATION.md`
