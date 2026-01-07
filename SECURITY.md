# Security Policy

## Security Measures Implemented

WhatsApp Commerce Hub implements multiple layers of security to protect user data and ensure safe operations:

### 1. Database Security

All database queries use WordPress's `$wpdb->prepare()` method to prevent SQL injection attacks:

```php
$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wch_conversations WHERE id = %d", $conversation_id );
```

**Key practices:**
- All user input is sanitized before database operations
- Parameterized queries are used exclusively
- Direct SQL concatenation is avoided

### 2. Output Escaping

All output is properly escaped to prevent XSS (Cross-Site Scripting) attacks:

- `esc_html()` - For HTML content
- `esc_attr()` - For HTML attributes
- `esc_url()` - For URLs
- `wp_kses_post()` - For post content with allowed HTML

**Example:**
```php
echo esc_html( $customer_name );
echo '<a href="' . esc_url( $order_url ) . '">' . esc_html( $order_number ) . '</a>';
```

### 3. Input Sanitization

All user input is sanitized using WordPress sanitization functions:

- `sanitize_text_field()` - For text inputs
- `sanitize_email()` - For email addresses
- `sanitize_url()` - For URLs
- `absint()` - For positive integers
- `sanitize_key()` - For keys/slugs

### 4. Nonce Verification

All form submissions and AJAX requests verify WordPress nonces:

```php
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wch_action_name' ) ) {
    wp_die( 'Invalid security token' );
}
```

**Protected actions:**
- Settings updates
- Order processing
- Template management
- Analytics data access

### 5. Capability Checks

All administrative actions check user capabilities:

```php
if ( ! current_user_can( 'manage_woocommerce' ) ) {
    return new WP_Error( 'forbidden', 'Insufficient permissions' );
}
```

**Capabilities used:**
- `manage_woocommerce` - For WooCommerce settings and data
- `manage_options` - For plugin configuration
- `edit_posts` - For content management

### 6. Data Encryption

Sensitive data is encrypted at rest:

- API credentials (WhatsApp API tokens, payment gateway keys)
- Customer personal information (where applicable)
- Session data

**Implementation:**
- Uses WordPress's encryption functions
- Keys stored in secure configuration
- Automatic encryption/decryption on read/write

### 7. API Security

**WhatsApp API:**
- Webhook signature verification
- Rate limiting implementation
- Request origin validation

**Payment Gateways:**
- Webhook signature verification (Stripe, Razorpay)
- Secure credential storage
- PCI DSS compliance considerations

### 8. File Upload Security

File uploads (if any) are secured:
- Type validation (MIME type checking)
- Size restrictions
- Secure storage locations
- Filename sanitization

### 9. AJAX Security

All AJAX endpoints implement:
- Nonce verification
- Capability checks
- Input sanitization
- Output escaping

### 10. REST API Security

REST API endpoints include:
- Authentication via WordPress authentication
- Permission callbacks for all routes
- Input validation and sanitization
- Rate limiting (where applicable)

## Responsible Disclosure Process

We take security vulnerabilities seriously. If you discover a security issue, please follow these steps:

### Reporting a Vulnerability

1. **DO NOT** create a public GitHub issue for security vulnerabilities
2. Email security details to: [security@example.com](mailto:security@example.com)
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

### What to Expect

- **Acknowledgment**: Within 48 hours of your report
- **Initial Assessment**: Within 1 week
- **Status Updates**: Every 2 weeks until resolved
- **Resolution**: Security patches released as soon as possible

### Disclosure Timeline

- Security fixes are typically released within 30 days
- Critical vulnerabilities may be patched sooner
- We coordinate disclosure timing with the reporter
- Public disclosure occurs after patch release

## Known Security Considerations

### Third-Party Dependencies

This plugin relies on third-party services:

1. **WhatsApp Business API**
   - Requires secure API credentials
   - Webhook endpoints must be HTTPS
   - Recommend IP whitelisting where possible

2. **Payment Gateways**
   - Stripe, Razorpay, PIX, WhatsApp Pay
   - Each has specific security requirements
   - Follow provider security guidelines

3. **WordPress and WooCommerce**
   - Keep WordPress and WooCommerce updated
   - Use latest PHP version (8.1+)
   - Regular security updates are critical

### Recommended Security Practices

**For Site Administrators:**

1. **Keep Everything Updated**
   - WordPress core
   - WooCommerce
   - This plugin
   - All other plugins and themes

2. **Use HTTPS**
   - SSL certificate required
   - Especially for webhooks and API endpoints

3. **Secure API Credentials**
   - Never commit credentials to version control
   - Use environment variables where possible
   - Rotate credentials periodically

4. **Monitor Logs**
   - Review plugin logs regularly
   - Watch for suspicious activity
   - Enable debug mode only when needed

5. **Backup Regularly**
   - Database backups
   - File backups
   - Test restore procedures

6. **Limit Administrative Access**
   - Use strong passwords
   - Enable two-factor authentication
   - Limit number of admin users

### Security Audit History

- Initial security review: January 2026
- Last security audit: [To be determined]
- Next scheduled audit: [To be determined]

## Security-Related Configuration

### Recommended wp-config.php Settings

```php
// Disable file editing from admin
define( 'DISALLOW_FILE_EDIT', true );

// Force SSL for admin
define( 'FORCE_SSL_ADMIN', true );

// Security keys (use unique values)
// https://api.wordpress.org/secret-key/1.1/salt/
define( 'AUTH_KEY', 'put your unique phrase here' );
// ... other keys ...
```

### Plugin-Specific Settings

1. **Webhook Security**: Enable signature verification in plugin settings
2. **Rate Limiting**: Configure in plugin settings (default: 100 requests/minute)
3. **Data Retention**: Configure automatic cleanup of old data
4. **Encryption**: Ensure encryption is enabled for sensitive fields

## Compliance

This plugin strives to comply with:

- **GDPR** (General Data Protection Regulation)
- **CCPA** (California Consumer Privacy Act)
- **PCI DSS** (Payment Card Industry Data Security Standard) - for payment handling

### Data Privacy

- Customer data is processed according to privacy policies
- Data retention policies can be configured
- Data export and deletion capabilities included
- Consent management for WhatsApp communications

## Additional Resources

- [WordPress Security Guide](https://wordpress.org/support/article/hardening-wordpress/)
- [WooCommerce Security](https://woocommerce.com/document/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)

## Security Contact

For security-related questions or concerns:
- Email: [security@example.com](mailto:security@example.com)
- Response time: Within 48 hours

---

**Last Updated**: January 2026
