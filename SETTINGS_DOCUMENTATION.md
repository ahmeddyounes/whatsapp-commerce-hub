# WCH Settings Framework Documentation

## Overview

The WCH Settings Framework provides centralized settings management for the WhatsApp Commerce Hub plugin with built-in encryption support for sensitive data.

## Components

### 1. WCH_Encryption Class
Location: `includes/class-wch-encryption.php`

Handles encryption and decryption of sensitive settings using OpenSSL AES-256-CBC.

**Key Features:**
- Uses `wp_salt('auth')` as encryption key
- Generates unique IV for each encryption
- Base64 encoding for storage compatibility

**Methods:**
- `encrypt($value)` - Encrypts a string value
- `decrypt($value)` - Decrypts an encrypted value
- `is_encrypted($value)` - Checks if a value is encrypted

### 2. WCH_Settings Class
Location: `includes/class-wch-settings.php`

Singleton class that manages all plugin settings with support for encryption, validation, and defaults.

**Key Features:**
- Single WordPress option storage (`wch_settings`)
- Automatic encryption for sensitive fields
- Type validation on set operations
- Default values with filter support
- Section-based organization

## Settings Structure

Settings are organized into sections:

### API Section
- `api.whatsapp_phone_number_id` (string)
- `api.whatsapp_business_account_id` (string)
- `api.access_token` (string, encrypted)
- `api.webhook_verify_token` (string)
- `api.api_version` (string, default: 'v18.0')

### General Section
- `general.enable_bot` (bool)
- `general.business_name` (string)
- `general.welcome_message` (string)
- `general.fallback_message` (string)
- `general.operating_hours` (json)
- `general.timezone` (string)

### Catalog Section
- `catalog.sync_enabled` (bool)
- `catalog.sync_products` (array)
- `catalog.include_out_of_stock` (bool)
- `catalog.price_format` (string)
- `catalog.currency_symbol` (string)

### Checkout Section
- `checkout.enabled_payment_methods` (array)
- `checkout.cod_enabled` (bool)
- `checkout.cod_extra_charge` (float)
- `checkout.min_order_amount` (float)
- `checkout.max_order_amount` (float)
- `checkout.require_phone_verification` (bool)

### Notifications Section
- `notifications.order_confirmation` (bool)
- `notifications.order_status_updates` (bool)
- `notifications.shipping_updates` (bool)
- `notifications.abandoned_cart_reminder` (bool)
- `notifications.abandoned_cart_delay_hours` (int)

### AI Section
- `ai.enable_ai` (bool)
- `ai.openai_api_key` (string, encrypted)
- `ai.ai_model` (string, default: 'gpt-4')
- `ai.ai_temperature` (float)
- `ai.ai_max_tokens` (int)
- `ai.ai_system_prompt` (string)

## Usage Examples

### Basic Get/Set

```php
$settings = WCH_Settings::getInstance();

// Set a value
$settings->set('general.business_name', 'My Store');

// Get a value
$name = $settings->get('general.business_name');

// Get with default
$timezone = $settings->get('general.timezone', 'UTC');
```

### Working with Encrypted Fields

```php
$settings = WCH_Settings::getInstance();

// Set an encrypted field (automatically encrypted)
$settings->set('api.access_token', 'my_secret_token');

// Get an encrypted field (automatically decrypted)
$token = $settings->get('api.access_token');

// Direct database access shows encrypted value
$raw = get_option('wch_settings');
// $raw['api']['access_token'] contains encrypted value
```

### Section Operations

```php
$settings = WCH_Settings::getInstance();

// Get all settings in a section
$api_settings = $settings->get_section('api');

// Result includes all API settings with encrypted fields decrypted
print_r($api_settings);
```

### Delete Settings

```php
$settings = WCH_Settings::getInstance();

// Delete a specific setting
$settings->delete('general.business_name');

// After deletion, get() returns default value
$name = $settings->get('general.business_name'); // Returns site name default
```

### Get All Settings

```php
$settings = WCH_Settings::getInstance();

// Get all settings (encrypted fields remain encrypted in this view)
$all_settings = $settings->get_all();
```

## Type Validation

The framework validates data types when setting values:

```php
$settings = WCH_Settings::getInstance();

// This will fail - wrong type
$settings->set('general.enable_bot', 'yes'); // Returns false

// This will succeed - correct type
$settings->set('general.enable_bot', true); // Returns true

// Integer validation
$settings->set('notifications.abandoned_cart_delay_hours', 24); // OK
$settings->set('notifications.abandoned_cart_delay_hours', '24'); // Fails

// Float validation
$settings->set('checkout.cod_extra_charge', 5.99); // OK
$settings->set('checkout.cod_extra_charge', 5); // OK (int converted to float)
```

## Filters

### wch_settings_defaults

Modify default settings values:

```php
add_filter('wch_settings_defaults', function($defaults) {
    // Change default welcome message
    $defaults['general']['welcome_message'] = 'Custom welcome!';

    // Add custom defaults
    $defaults['custom_section']['custom_key'] = 'custom_value';

    return $defaults;
});
```

## Security Considerations

1. **Encrypted Fields**: The following fields are automatically encrypted:
   - `api.access_token`
   - `ai.openai_api_key`

2. **Database Storage**: Encrypted fields cannot be read directly from the database without decryption.

3. **Encryption Key**: Uses WordPress auth salt, which should be unique per installation.

## Testing

### Automated Tests

Run the test suite:

```php
require_once 'includes/class-wch-settings-test.php';
$results = WCH_Settings_Test::run_tests();
WCH_Settings_Test::display_results($results);
```

### Manual Testing

Access the test page: `/wp-content/plugins/whatsapp-commerce-hub/test-settings.php`

Tests include:
- Encryption/decryption functionality
- Settings persistence across requests
- Encrypted fields protection
- Default value retrieval
- Type validation
- Section operations
- Delete operations

## Acceptance Criteria

✅ Settings persist across requests
✅ Encrypted fields cannot be read directly from database
✅ get() returns defaults for unset keys
✅ Settings validate types on set()
✅ All sections and keys defined in spec are implemented
✅ Filter 'wch_settings_defaults' available for custom defaults

## Implementation Details

- **Storage**: All settings stored in single `wch_settings` WordPress option
- **Caching**: Settings cached in memory during request lifecycle
- **Atomic Updates**: Using WordPress `update_option()` ensures atomic updates
- **Singleton Pattern**: Prevents multiple instances and ensures consistency
- **Autoloading**: Classes autoload via plugin's autoloader

## Performance

- Settings are cached in memory after first access
- Only one database query per request (on first access)
- Atomic updates prevent race conditions
- Efficient serialization using WordPress option functions
