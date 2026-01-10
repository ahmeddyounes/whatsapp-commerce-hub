# Presentation

User interface layer including admin pages, user actions, and templates.

## Purpose

Presentation layer handles:
- **Admin UI** - WordPress admin pages and widgets
- **User Actions** - WhatsApp conversation actions
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
├── Actions/        # WhatsApp user actions
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

### User Action
```php
use WhatsAppCommerceHub\Presentation\Actions\AddToCartAction;

$action = wch(AddToCartAction::class);
$result = $action->execute($context);
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
- 🔴 User actions
- 🔴 Template system
