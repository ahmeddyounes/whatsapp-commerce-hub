# Core

Core infrastructure components that provide foundational functionality for the entire plugin.

## Purpose

This directory contains the essential building blocks and utilities that the rest of the application depends on.

## Contents

- **Logger.php** - Logging functionality
- **ErrorHandler.php** - Global error and exception handling

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

## Principles

1. **Foundation First** - Core components have no dependencies on other layers
2. **Stability** - Changes to core should be rare and well-tested
3. **Single Responsibility** - Each class has one clear purpose
4. **No Business Logic** - Core is infrastructure only

## Migration Status

- ✅ Logger
- ✅ ErrorHandler
