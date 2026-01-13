# Support

Shared utilities, helpers, and cross-cutting concerns.

## Purpose

Support layer provides:
- **Utilities** - Common helper functions
- **AI** - NLP and intent classification
- **Messaging** - Message building and formatting
- **Validation** - Input validation utilities

## Structure

```
Support/
├── Utilities/    # General utilities
├── AI/           # AI and NLP utilities
├── Messaging/    # Message formatting
└── Validation/   # Validation utilities
```

## Namespace

```php
WhatsAppCommerceHub\Support
```

## Examples

### Address Parser
```php
use WhatsAppCommerceHub\Support\Utilities\AddressParser;

$parser = wch(AddressParser::class);
$address = $parser->parse($userInput);
```

### Intent Classifier
```php
use WhatsAppCommerceHub\Support\AI\IntentClassifier;

$classifier = wch(IntentClassifier::class);
$intent = $classifier->classify($message);
```

### Message Builder
```php
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;

$builder = wch(MessageBuilder::class);
$message = $builder->buildCartSummary($cart);
```

## Principles

1. **Reusability** - Used across multiple layers
2. **Stateless** - Utilities should be stateless
3. **No Business Logic** - Pure utilities only
4. **Well-Tested** - High test coverage required

## Migration Status

Phase 8 - Not Started
- 🔴 AI utilities
- 🔴 Messaging utilities
- 🔴 General utilities
- 🔴 Validation utilities
