# WhatsApp Commerce Hub - Test Suite

Comprehensive PHPUnit test suite for the WhatsApp Commerce Hub plugin.

## Prerequisites

1. WordPress test library installed
2. WooCommerce plugin available
3. PHP 8.1 or higher
4. Composer dependencies installed

## Installation

```bash
# Install composer dependencies
composer install

# Set up WordPress test environment (one-time setup)
./bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

## Running Tests

```bash
# Run all tests
composer test

# Run only unit tests
composer test:unit

# Run only integration tests
composer test:integration

# Run with coverage report
composer test:coverage
```

## Test Structure

```
tests/
├── bootstrap.php                          # Test bootstrap and setup
├── class-wch-unit-test-case.php          # Base unit test class
├── class-wch-integration-test-case.php   # Base integration test class
├── Unit/                                  # Unit tests
│   ├── WCH_Settings_Test.php
│   ├── WCH_Message_Builder_Test.php
│   ├── WCH_Intent_Classifier_Test.php
│   ├── WCH_Cart_Manager_Test.php
│   ├── WCH_Conversation_FSM_Test.php
│   └── WCH_Customer_Service_Test.php
├── Integration/                           # Integration tests
│   ├── WCH_Product_Sync_Test.php
│   ├── WCH_Order_Sync_Test.php
│   ├── WCH_Checkout_Test.php
│   ├── WCH_Webhook_Test.php
│   └── WCH_Payment_Test.php
└── fixtures/                              # Test fixtures
    ├── webhook_text_message.json
    ├── webhook_button_reply.json
    ├── webhook_list_reply.json
    ├── webhook_status_update.json
    ├── webhook_image_message.json
    ├── webhook_location_message.json
    ├── sample_products.json
    └── conversation_contexts.json
```

## Unit Tests

Unit tests verify individual components in isolation with mocked dependencies:

- **WCH_Settings_Test**: Settings get/set/encryption
- **WCH_Message_Builder_Test**: Message building and validation
- **WCH_Intent_Classifier_Test**: Intent classification with various phrasings
- **WCH_Cart_Manager_Test**: Cart CRUD operations
- **WCH_Conversation_FSM_Test**: State machine transitions
- **WCH_Customer_Service_Test**: Customer profile operations

## Integration Tests

Integration tests verify components working together with real database operations:

- **WCH_Product_Sync_Test**: Product sync to WhatsApp catalog
- **WCH_Order_Sync_Test**: Order creation and notification flow
- **WCH_Checkout_Test**: Full checkout process
- **WCH_Webhook_Test**: Webhook payload processing
- **WCH_Payment_Test**: Payment gateway flows

## Writing Tests

### Unit Test Example

```php
class My_Feature_Test extends WCH_Unit_Test_Case {

    public function test_my_feature() {
        // Arrange
        $product = $this->create_test_product();

        // Act
        $result = my_function( $product->get_id() );

        // Assert
        $this->assertTrue( $result );
    }
}
```

### Integration Test Example

```php
class My_Integration_Test extends WCH_Integration_Test_Case {

    public function test_api_integration() {
        // Mock HTTP response
        $this->mock_whatsapp_success();

        // Test real flow
        $result = $this->service->sync_data();

        // Verify database state
        $this->assertDatabaseHas( 'wch_table', array(
            'status' => 'synced'
        ) );
    }
}
```

## Helper Methods

Both test base classes provide helpful methods:

### WCH_Unit_Test_Case

- `create_test_product( $args )` - Create WooCommerce product
- `create_test_order( $args )` - Create WooCommerce order
- `create_test_conversation( $args )` - Create conversation record
- `create_test_context( $conversation_id, $data )` - Create conversation context
- `create_mock_api_client()` - Get mocked WhatsApp API client

### WCH_Integration_Test_Case

- All methods from WCH_Unit_Test_Case, plus:
- `mock_whatsapp_success( $message_id )` - Mock successful API response
- `mock_whatsapp_error( $message, $code )` - Mock failed API response
- `add_http_mock( $url_pattern, $response )` - Add custom HTTP mock
- `assertDatabaseHas( $table, $conditions )` - Assert DB record exists
- `assertDatabaseMissing( $table, $conditions )` - Assert DB record doesn't exist

## Coverage Goals

Target: >80% code coverage

Run coverage report:
```bash
composer test:coverage
```

View HTML report: `coverage/html/index.html`

## Continuous Integration

Tests are designed to run in CI/CD pipelines. Example GitHub Actions workflow:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - name: Install dependencies
        run: composer install
      - name: Run tests
        run: composer test
```

## Troubleshooting

### WordPress test library not found

```bash
# Install WordPress test library
./bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

### Database connection errors

Check your `phpunit.xml.dist` database configuration:
```xml
<env name="WP_TESTS_DIR" value="/tmp/wordpress-tests-lib"/>
```

### WooCommerce not loading

Ensure WooCommerce is available in your test environment or adjust the path in `bootstrap.php`.

## Best Practices

1. Each test should be independent and isolated
2. Use descriptive test names: `test_feature_does_what_when_condition`
3. Follow AAA pattern: Arrange, Act, Assert
4. Mock external dependencies (APIs, file system)
5. Clean up after tests (done automatically by base classes)
6. Use fixtures for complex test data
7. Test edge cases and error conditions

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Plugin Unit Tests](https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/)
- [WooCommerce Testing Guide](https://github.com/woocommerce/woocommerce/wiki/Unit-tests)
