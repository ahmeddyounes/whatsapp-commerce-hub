# Settings Schema Deprecation

**Status:** Deprecated in v3.0.0
**Migration Period:** TBD
**Removal Target:** TBD

## Overview

The flat `wch.settings` and `wch.setting` schema used in service providers has been deprecated in favor of the sectioned `SettingsInterface`. This change improves settings organization, enables better validation, and supports encryption for sensitive data.

## What's Changing

### Deprecated (Old)
```php
// Accessing settings as a flat array
$container->get('wch.settings');
// Returns: ['phone_number_id' => '...', 'access_token' => '...', ...]

// Using the helper function
$getSetting = $container->get('wch.setting');
$phoneId = $getSetting('phone_number_id');
```

### Recommended (New)
```php
// Using SettingsInterface with sectioned keys
$settings = $container->get(SettingsInterface::class);
$phoneId = $settings->get('api.whatsapp_phone_number_id');

// Or for API credentials specifically
$credentials = $settings->getApiCredentials();
// Returns: ['access_token' => '...', 'phone_number_id' => '...', 'business_account_id' => '...']
```

## Key Mapping

Old flat keys map to new sectioned keys as follows:

| Old Key (Deprecated) | New Key (Sectioned) |
|---------------------|---------------------|
| `phone_number_id` | `api.whatsapp_phone_number_id` |
| `business_account_id` | `api.whatsapp_business_account_id` |
| `access_token` | `api.access_token` |
| `verify_token` | `api.webhook_verify_token` |
| `webhook_secret` | `api.webhook_secret` |
| `openai_api_key` | `ai.openai_api_key` |
| `enable_ai_chat` | `ai.enable_ai` |
| `ai_model` | `ai.ai_model` |
| `store_currency` | `catalog.currency_symbol` |
| `enable_cart_recovery` | `recovery.enabled` |
| `cart_expiry_hours` | `recovery.delay_sequence_3` |
| `reminder_1_delay` | `recovery.delay_sequence_1` |
| `reminder_2_delay` | `recovery.delay_sequence_2` |
| `reminder_3_delay` | `recovery.delay_sequence_3` |
| `enable_order_tracking` | `notifications.order_status_updates` |
| `enable_debug_logging` | _(Use Logger service directly)_ |

## Compatibility Adapter

During the transition period, a `LegacySettingsAdapter` class provides backward compatibility:

- The adapter implements `ArrayAccess` to maintain array-like access
- It transparently maps old keys to new sectioned keys
- Deprecation warnings are logged in `WP_DEBUG` mode
- All reads/writes are routed through `SettingsInterface`

## Migration Guide

### For Service Providers

**Before:**
```php
$container->singleton(
    MyService::class,
    static function (ContainerInterface $c) {
        $settings = $c->get('wch.settings');
        return new MyService(
            $settings['phone_number_id'],
            $settings['access_token']
        );
    }
);
```

**After:**
```php
$container->singleton(
    MyService::class,
    static function (ContainerInterface $c) {
        $settings = $c->get(SettingsInterface::class);
        $credentials = $settings->getApiCredentials();
        return new MyService(
            $credentials['phone_number_id'],
            $credentials['access_token']
        );
    }
);
```

### For Application Code

**Before:**
```php
$getSetting = $container->get('wch.setting');
$enableAI = $getSetting('enable_ai_chat', false);
```

**After:**
```php
$settings = $container->get(SettingsInterface::class);
$enableAI = $settings->get('ai.enable_ai', false);
```

### For Tests

**Before:**
```php
$container->set('wch.settings', [
    'phone_number_id' => 'test_id',
    'access_token' => 'test_token',
]);
```

**After:**
```php
use WhatsAppCommerceHub\Tests\Mocks\MockSettings;
use WhatsAppCommerceHub\Infrastructure\Configuration\LegacySettingsAdapter;

$mockSettings = new MockSettings();
$mockSettings->set('api.whatsapp_phone_number_id', 'test_id');
$mockSettings->set('api.access_token', 'test_token');

$container->set(SettingsInterface::class, $mockSettings);

// If you still need wch.settings for legacy code:
$container->set('wch.settings', new LegacySettingsAdapter($mockSettings));
```

## Benefits of the New System

1. **Better Organization**: Settings grouped by domain (api, ai, catalog, etc.)
2. **Type Safety**: Schema validation ensures correct types
3. **Encryption**: Sensitive fields automatically encrypted/decrypted
4. **Caching**: Built-in caching for performance
5. **Extensibility**: Easy to add new setting groups

## Timeline

- **v3.0.0**: Deprecation announced, adapter introduced
- **Future version**: Deprecation warnings enabled by default
- **TBD**: Legacy adapter removed

## Need Help?

If you have questions about migrating your code, please:
1. Review the `SettingsInterface` contract in `includes/Contracts/Services/SettingsInterface.php`
2. Check the `SettingsManager` implementation in `includes/Infrastructure/Configuration/SettingsManager.php`
3. Look at examples in `includes/Providers/CoreServiceProvider.php`
