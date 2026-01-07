# Contributing to WhatsApp Commerce Hub

Thank you for your interest in contributing to WhatsApp Commerce Hub! This document provides guidelines and instructions for contributing.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Coding Standards](#coding-standards)
- [Pull Request Process](#pull-request-process)
- [Testing Requirements](#testing-requirements)
- [Branch Naming Convention](#branch-naming-convention)
- [Commit Messages](#commit-messages)
- [Documentation](#documentation)
- [Issue Reporting](#issue-reporting)

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inclusive environment for all contributors.

### Our Standards

**Positive Behavior**:
- Using welcoming and inclusive language
- Being respectful of differing viewpoints and experiences
- Gracefully accepting constructive criticism
- Focusing on what is best for the community
- Showing empathy towards other community members

**Unacceptable Behavior**:
- Trolling, insulting/derogatory comments, and personal or political attacks
- Public or private harassment
- Publishing others' private information without explicit permission
- Other conduct which could reasonably be considered inappropriate

### Enforcement

Instances of abusive, harassing, or otherwise unacceptable behavior may be reported by contacting the project team at conduct@example.com. All complaints will be reviewed and investigated promptly and fairly.

## Getting Started

### Prerequisites

- PHP 8.1 or higher
- Node.js 18+ and npm
- WordPress 6.0+
- WooCommerce 8.0+
- Composer
- Git

### Development Environment Setup

1. **Clone the Repository**:
   ```bash
   git clone https://github.com/your-repo/whatsapp-commerce-hub.git
   cd whatsapp-commerce-hub
   ```

2. **Install Dependencies**:
   ```bash
   # PHP dependencies
   composer install

   # Node dependencies (if any)
   npm install
   ```

3. **Set Up WordPress**:
   ```bash
   # Using wp-env (recommended)
   npm -g install @wordpress/env
   wp-env start

   # Or use Local, MAMP, Docker, etc.
   ```

4. **Configure Environment**:
   ```bash
   # Copy environment template
   cp .env.example .env

   # Edit .env with your credentials
   # - WhatsApp Business API credentials
   # - OpenAI API key
   # - Database credentials
   ```

5. **Activate Plugin**:
   ```bash
   wp plugin activate whatsapp-commerce-hub
   ```

### Development Tools

**Required**:
- **PHP_CodeSniffer**: For code style checking
- **PHPUnit**: For running tests
- **WP-CLI**: For WordPress operations

**Recommended**:
- **Xdebug**: For debugging
- **PHPStan**: For static analysis
- **VSCode**: With PHP Intelephense extension

## Development Workflow

### 1. Fork and Clone

```bash
# Fork the repository on GitHub

# Clone your fork
git clone https://github.com/YOUR-USERNAME/whatsapp-commerce-hub.git
cd whatsapp-commerce-hub

# Add upstream remote
git remote add upstream https://github.com/original-repo/whatsapp-commerce-hub.git
```

### 2. Create Feature Branch

```bash
# Update main branch
git checkout main
git pull upstream main

# Create feature branch
git checkout -b feature/your-feature-name
```

### 3. Make Changes

- Write your code following our [coding standards](#coding-standards)
- Write or update tests
- Update documentation
- Test thoroughly

### 4. Commit Changes

```bash
# Stage changes
git add .

# Commit with descriptive message
git commit -m "Add: Brief description of changes"
```

### 5. Push and Create PR

```bash
# Push to your fork
git push origin feature/your-feature-name

# Create Pull Request on GitHub
```

## Coding Standards

We follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) with some modifications.

### PHP Coding Standards

#### Code Style

```php
<?php
/**
 * File docblock with description.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class description.
 *
 * Detailed explanation of what this class does.
 *
 * @since 1.0.0
 */
class WCH_Example_Class {

    /**
     * Property description.
     *
     * @since 1.0.0
     * @var string
     */
    private $property_name;

    /**
     * Method description.
     *
     * More detailed explanation if needed.
     *
     * @since 1.0.0
     * @param string $param1 Description of parameter.
     * @param int    $param2 Description of parameter.
     * @return bool True on success, false on failure.
     * @throws WCH_Exception If something goes wrong.
     */
    public function method_name( $param1, $param2 ) {
        // Method implementation
        if ( empty( $param1 ) ) {
            return false;
        }

        return true;
    }
}
```

#### Naming Conventions

- **Classes**: `WCH_Class_Name` (PascalCase with WCH_ prefix)
- **Functions**: `wch_function_name()` (snake_case with wch_ prefix)
- **Methods**: `method_name()` (snake_case)
- **Variables**: `$variable_name` (snake_case)
- **Constants**: `WCH_CONSTANT_NAME` (UPPER_SNAKE_CASE)
- **Hooks**: `wch_hook_name` (snake_case with wch_ prefix)

#### Indentation and Spacing

- Use **tabs** for indentation
- Use **spaces** for alignment
- No trailing whitespace
- Blank line at end of file
- Maximum line length: 120 characters (soft limit)

#### Code Organization

```php
// 1. Namespace and use statements (if using namespaces)
// 2. Security check
// 3. Constants
// 4. Class definition
// 5. Properties (grouped by visibility)
// 6. Constructor
// 7. Public methods
// 8. Protected methods
// 9. Private methods
// 10. Magic methods
```

### JavaScript Coding Standards

Follow WordPress JavaScript Coding Standards:

```javascript
/**
 * Function description.
 *
 * @since 1.0.0
 * @param {string} param1 Description.
 * @param {number} param2 Description.
 * @return {boolean} Description of return value.
 */
function exampleFunction( param1, param2 ) {
    // Use camelCase for variables
    const variableName = 'value';

    if ( 'test' === param1 ) {
        return true;
    }

    return false;
}
```

### CSS/SCSS Coding Standards

```css
/**
 * Component description.
 *
 * @since 1.0.0
 */

/* Use BEM naming convention */
.wch-component {
    display: flex;
    padding: 1rem;
}

.wch-component__element {
    margin: 0.5rem;
}

.wch-component--modifier {
    background-color: #f0f0f0;
}
```

### Database Queries

```php
// Use wpdb for database operations
global $wpdb;

// Prepared statements for security
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wch_conversations WHERE customer_phone = %s",
        $phone
    )
);

// Use appropriate escape functions
$safe_value = esc_sql( $value );
$safe_like = $wpdb->esc_like( $search );
```

### Security Best Practices

```php
// 1. Validate and sanitize all inputs
$phone = sanitize_text_field( $_POST['phone'] ?? '' );

// 2. Escape all outputs
echo esc_html( $phone );
echo esc_url( $url );
echo esc_attr( $attribute );

// 3. Verify nonces for form submissions
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'action_name' ) ) {
    wp_die( 'Security check failed' );
}

// 4. Check user capabilities
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
}

// 5. Use prepared statements for database queries
$wpdb->prepare( "SELECT * FROM table WHERE id = %d", $id );
```

### Code Quality Tools

Run these before submitting PR:

```bash
# PHP Code Sniffer
composer run phpcs

# Fix auto-fixable issues
composer run phpcbf

# PHPStan (static analysis)
composer run phpstan

# Run all checks
composer run check
```

## Pull Request Process

### Before Submitting

1. **Update Documentation**: If your PR adds features, update docs
2. **Add Tests**: Write unit/integration tests for new functionality
3. **Run Tests**: Ensure all tests pass locally
4. **Check Code Style**: Run PHPCS and fix any issues
5. **Update Changelog**: Add entry to CHANGELOG.md under [Unreleased]
6. **Rebase on Main**: Ensure your branch is up to date

```bash
git fetch upstream
git rebase upstream/main
```

### PR Title Format

Use conventional commit format:

```
Type: Brief description

Examples:
Add: New abandoned cart recovery feature
Fix: Message delivery failure on high load
Update: Improve AI response accuracy
Refactor: Simplify payment gateway architecture
Docs: Add API authentication examples
```

**Types**:
- `Add`: New feature
- `Fix`: Bug fix
- `Update`: Improvement to existing feature
- `Refactor`: Code refactoring
- `Docs`: Documentation only
- `Test`: Adding or updating tests
- `Chore`: Maintenance tasks

### PR Description Template

```markdown
## Description
Brief description of what this PR does.

## Motivation
Why is this change needed? What problem does it solve?

## Changes
- List of specific changes made
- Another change
- Yet another change

## Testing
How has this been tested?
- [ ] Unit tests
- [ ] Integration tests
- [ ] Manual testing

## Screenshots (if applicable)
Add screenshots to demonstrate UI changes.

## Checklist
- [ ] Code follows style guidelines
- [ ] Self-review completed
- [ ] Comments added for complex code
- [ ] Documentation updated
- [ ] No new warnings generated
- [ ] Tests added/updated and passing
- [ ] Changelog updated
- [ ] No breaking changes (or documented if unavoidable)

## Related Issues
Closes #123
Relates to #456
```

### Review Process

1. **Automated Checks**: CI/CD runs tests and code quality checks
2. **Code Review**: Maintainer reviews code and provides feedback
3. **Revisions**: Make requested changes
4. **Approval**: Once approved, PR will be merged
5. **Release**: Changes included in next release

### Merging

- Squash and merge for feature PRs
- Merge commit for release PRs
- Rebase and merge for documentation updates

## Testing Requirements

### Unit Tests

Write unit tests for all new functions and methods:

```php
<?php
/**
 * Test case for WCH_Example_Class.
 *
 * @package WhatsApp_Commerce_Hub
 */

class Test_WCH_Example_Class extends WP_UnitTestCase {

    /**
     * Test method_name with valid input.
     */
    public function test_method_name_with_valid_input() {
        $example = new WCH_Example_Class();
        $result = $example->method_name( 'test', 123 );

        $this->assertTrue( $result );
    }

    /**
     * Test method_name with invalid input.
     */
    public function test_method_name_with_invalid_input() {
        $example = new WCH_Example_Class();
        $result = $example->method_name( '', 123 );

        $this->assertFalse( $result );
    }
}
```

### Integration Tests

Test integration between components:

```php
public function test_order_creation_sends_whatsapp_notification() {
    // Create order
    $order = wc_create_order();
    $order->set_billing_phone( '+15551234567' );
    $order->save();

    // Trigger order creation hook
    do_action( 'woocommerce_checkout_order_created', $order );

    // Assert notification was queued
    $this->assertNotificationQueued( '+15551234567', 'order_confirmation' );
}
```

### Running Tests

```bash
# Install test environment
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run all tests
composer test

# Run specific test file
phpunit tests/test-example-class.php

# Run with coverage
composer test:coverage
```

## Branch Naming Convention

Format: `type/short-description`

**Types**:
- `feature/` - New features
- `fix/` - Bug fixes
- `update/` - Updates to existing features
- `refactor/` - Code refactoring
- `docs/` - Documentation changes
- `test/` - Test additions or changes
- `hotfix/` - Urgent production fixes

**Examples**:
```
feature/abandoned-cart-recovery
fix/message-delivery-timeout
update/ai-response-optimization
refactor/payment-gateway-architecture
docs/api-authentication-guide
test/add-cart-manager-tests
hotfix/critical-security-patch
```

## Commit Messages

### Format

```
Type: Brief description (50 chars or less)

More detailed explanation if needed (wrap at 72 chars).
Explain what and why, not how.

- Bullet points are okay
- Typically a hyphen or asterisk is used

Resolves: #123
See also: #456, #789
```

### Types

Same as PR title types: `Add`, `Fix`, `Update`, `Refactor`, `Docs`, `Test`, `Chore`

### Examples

```
Add: Abandoned cart recovery with multi-sequence campaigns

Implements a flexible abandoned cart recovery system with up to 3
customizable reminder sequences. Includes discount code generation
and template variable support.

Resolves: #234
```

```
Fix: Message delivery timeout on high load

Increased webhook processing timeout and moved heavy operations
to background jobs using Action Scheduler. Messages now process
reliably under load.

Resolves: #567
```

## Documentation

### Required Documentation

When adding features:

1. **Code Comments**: PHPDoc blocks for all public APIs
2. **README Updates**: If changing installation/setup
3. **API Docs**: If adding/changing REST endpoints
4. **Hooks Docs**: If adding new hooks
5. **User Guide**: If adding user-facing features
6. **Changelog**: Add entry under [Unreleased]

### Documentation Style

- Use clear, concise language
- Include code examples
- Add screenshots for UI features
- Link to related documentation
- Keep formatting consistent

## Issue Reporting

### Bug Reports

Include:
1. **Description**: Clear description of the bug
2. **Steps to Reproduce**: Numbered steps
3. **Expected Behavior**: What should happen
4. **Actual Behavior**: What actually happens
5. **Environment**:
   - Plugin version
   - WordPress version
   - WooCommerce version
   - PHP version
   - Browser (if relevant)
6. **Logs**: Relevant error messages
7. **Screenshots**: If applicable

### Feature Requests

Include:
1. **Use Case**: Why is this needed?
2. **Proposed Solution**: How should it work?
3. **Alternatives**: Other approaches considered
4. **Benefits**: Who benefits and how?
5. **Examples**: Similar features elsewhere

### Labels

We use these labels:
- `bug` - Something isn't working
- `enhancement` - New feature or request
- `documentation` - Documentation improvements
- `good first issue` - Good for newcomers
- `help wanted` - Extra attention needed
- `priority: high` - Should be fixed soon
- `priority: low` - Nice to have
- `wontfix` - Will not be addressed

## Recognition

Contributors will be:
- Listed in CONTRIBUTORS.md
- Mentioned in release notes
- Given credit in documentation

Thank you for contributing! 🙌

## Questions?

- **GitHub Discussions**: Ask questions and discuss ideas
- **Slack**: Join our Slack channel
- **Email**: dev@example.com

---

**Happy coding!** 🚀
