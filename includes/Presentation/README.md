# Presentation

User interface layer including admin pages and templates.

## Purpose

Presentation layer handles:
- **Admin UI** - WordPress admin pages and widgets
- **Templates** - Message templates and rendering
- **Input Validation** - User input handling
- **Output Formatting** - Response formatting

## Structure

```
Presentation/
├── Admin/
│   ├── Pages/      # Admin dashboard pages
│   ├── Widgets/    # Dashboard widgets
│   └── Settings/   # Settings pages
└── Templates/      # Template management
```

## Namespace

```php
WhatsAppCommerceHub\Presentation
```

## Examples

### Admin Page
```php
use WhatsAppCommerceHub\Presentation\Admin\Pages\AnalyticsPage;

$page = wch(AnalyticsPage::class);
$page->render();
```

## Principles

1. **User Interface Only** - No business logic
2. **Delegates to Application** - Uses application services
3. **Input Validation** - Validates user input
4. **Output Formatting** - Formats responses
5. **Separation of Concerns** - UI separate from logic

## Migration Status

Phase 6 - Not Started
- 🔴 Admin pages
- 🔴 Dashboard widgets
- 🔴 Template system
