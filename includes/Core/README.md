# Core

Core infrastructure components that provide foundational functionality for the entire plugin.

## Purpose

This directory contains the essential building blocks and utilities that the rest of the application depends on.

## Contents

- **Bootstrap/** - Plugin initialization and bootstrapping logic
- **Deprecation.php** - Handles deprecation warnings during PSR-4 migration
- **LegacyClassMapper.php** - Maps legacy WCH_ classes to new PSR-4 classes
- **CompatibilityLayer.php** - Provides backward compatibility wrappers
- **Logger.php** - Logging functionality (to be migrated)
- **ErrorHandler.php** - Global error and exception handling (to be migrated)

## Namespace

```php
WhatsAppCommerceHub\Core
```

## Examples

### Using the Logger
```php
use WhatsAppCommerceHub\Core\Logger;

$logger = wch(Logger::class);
$logger->info('Order processed successfully', ['order_id' => 123]);
```

### Triggering Deprecation
```php
use WhatsAppCommerceHub\Core\Deprecation;

Deprecation::trigger(
    'WCH_Old_Class',
    'WhatsAppCommerceHub\New\NewClass',
    '2.0.0'
);
```

## Principles

1. **Foundation First** - Core components have no dependencies on other layers
2. **Stability** - Changes to core should be rare and well-tested
3. **Single Responsibility** - Each class has one clear purpose
4. **No Business Logic** - Core is infrastructure only

## Migration Status

- ✅ Deprecation system implemented
- ✅ Legacy class mapper implemented
- ✅ Compatibility layer implemented
- 🔴 Logger migration pending
- 🔴 ErrorHandler migration pending
