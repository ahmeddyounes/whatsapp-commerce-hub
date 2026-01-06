# WhatsApp Commerce Hub - WordPress Plugin

Complete e-commerce ecosystem inside WhatsApp with WooCommerce sync.

## Requirements

- **PHP**: 8.1 or higher
- **WordPress**: 6.0 or higher
- **WooCommerce**: 8.0 or higher

## Directory Structure

```
whatsapp-commerce-hub/
├── whatsapp-commerce-hub.php    # Main plugin file
├── includes/                     # Core classes
│   └── class-wch-test.php       # Test class (example)
├── admin/                        # Admin UI components
├── public/                       # Frontend components
├── assets/
│   ├── css/                     # Stylesheets
│   ├── js/                      # JavaScript files
│   └── images/                  # Image assets
├── templates/                    # Message templates
└── languages/                    # Internationalization files
```

## Features

### Plugin Bootstrap (M00-01)

- ✅ Main plugin file with proper WordPress plugin headers
- ✅ Singleton pattern implementation for main plugin class
- ✅ PSR-4 compatible autoloader for `WCH_` prefixed classes
- ✅ Dependency checking (PHP, WordPress, WooCommerce versions)
- ✅ Activation hook with requirements validation
- ✅ Deactivation hook for cleanup
- ✅ Proper initialization on `plugins_loaded` hook (priority 20)
- ✅ Global constants: `WCH_VERSION`, `WCH_PLUGIN_DIR`, `WCH_PLUGIN_URL`, `WCH_PLUGIN_BASENAME`

## Installation

1. Upload the plugin directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and activated

## Development

### Class Naming Convention

All classes should follow the `WCH_` prefix naming convention. The autoloader will automatically convert:
- Class name: `WCH_Example_Class`
- File path: `includes/class-wch-example-class.php`

### Verification

Run the bootstrap verification test:

```bash
php test-plugin-bootstrap.php
```

This will verify:
- All constants are defined correctly
- Autoloader is registered
- Classes can be loaded automatically
- Singleton pattern is implemented
- Activation/deactivation hooks are registered

## Constants

| Constant | Description | Example Value |
|----------|-------------|---------------|
| `WCH_VERSION` | Plugin version | `1.0.0` |
| `WCH_PLUGIN_DIR` | Absolute path to plugin directory | `/path/to/wp-content/plugins/whatsapp-commerce-hub/` |
| `WCH_PLUGIN_URL` | URL to plugin directory | `https://example.com/wp-content/plugins/whatsapp-commerce-hub/` |
| `WCH_PLUGIN_BASENAME` | Plugin basename | `whatsapp-commerce-hub/whatsapp-commerce-hub.php` |

## Main Plugin Class

The main plugin class `WCH_Plugin` uses the singleton pattern. Access it via:

```php
$plugin = WCH_Plugin::getInstance();
```

## License

This plugin is proprietary software for WhatsApp Commerce Hub.
