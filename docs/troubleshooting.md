# Troubleshooting Guide

Common issues and solutions for WhatsApp Commerce Hub.

## Table of Contents

- [Installation Issues](#installation-issues)
- [Webhook Problems](#webhook-problems)
- [Message Delivery Issues](#message-delivery-issues)
- [Sync Problems](#sync-problems)
- [AI Assistant Issues](#ai-assistant-issues)
- [Payment Gateway Issues](#payment-gateway-issues)
- [Performance Problems](#performance-problems)
- [Debug Mode](#debug-mode)
- [Log Interpretation](#log-interpretation)
- [WhatsApp API Error Codes](#whatsapp-api-error-codes)
- [Support Contact](#support-contact)

---

## Installation Issues

### Plugin Won't Activate

**Symptom**: Error message when trying to activate plugin

**Common Causes**:
1. PHP version too old
2. WooCommerce not installed
3. Missing PHP extensions

**Solutions**:

```bash
# Check PHP version
php -v
# Should be 8.1 or higher

# Check required extensions
php -m | grep -E '(curl|json|mbstring|openssl|pdo_mysql|xml)'

# Install missing extensions (Ubuntu/Debian)
sudo apt-get install php8.1-curl php8.1-mbstring php8.1-xml

# Restart web server
sudo systemctl restart apache2
# or
sudo systemctl restart nginx
```

### Database Tables Not Created

**Symptom**: Plugin activates but features don't work

**Solution**:
```php
// Run in WordPress admin or via WP-CLI
WCH_Database_Manager::getInstance()->createTables();
```

Or via WP-CLI:
```bash
wp eval 'WCH_Database_Manager::getInstance()->createTables();'
```

### White Screen of Death

**Symptom**: Blank page after activation

**Solution**:
1. Enable WordPress debug mode:
   ```php
   // Add to wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

2. Check error log:
   ```bash
   tail -f wp-content/debug.log
   ```

3. Common fixes:
   - Increase PHP memory limit
   - Disable conflicting plugins
   - Clear all caches

---

## Webhook Problems

### Webhook Not Receiving Messages

**Symptom**: Messages sent to WhatsApp number don't trigger bot responses

**Diagnostic Steps**:

1. **Test Webhook URL**:
   ```bash
   curl -X POST https://yoursite.com/wp-json/wch/v1/webhook \
     -H "Content-Type: application/json" \
     -d '{"test": true}'
   ```
   Should return: `{"success":true}`

2. **Check Webhook Verification**:
   ```bash
   curl "https://yoursite.com/wp-json/wch/v1/webhook?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=test123"
   ```
   Should return: `test123`

3. **Verify Permalink Structure**:
   - Go to: Settings → Permalinks
   - Click "Save Changes" to flush rewrite rules

4. **Check Server Logs**:
   ```bash
   # Apache
   tail -f /var/log/apache2/error.log

   # Nginx
   tail -f /var/log/nginx/error.log
   ```

**Common Solutions**:

- **Firewall blocking**: Whitelist Meta's IP ranges
- **SSL certificate issues**: Ensure valid SSL certificate
- **htaccess rules**: Check for conflicting rewrite rules
- **Security plugins**: Temporarily disable to test

### Webhook Signature Validation Fails

**Symptom**: Error: "Invalid webhook signature"

**Solution**:
1. Verify webhook secret matches in both Meta and plugin settings
2. Check server is receiving `X-Hub-Signature-256` header
3. Ensure raw request body is used for validation (not parsed JSON)

```php
// Debug signature validation
add_action('wch_webhook_signature_failed', function($expected, $received) {
    error_log("Expected: {$expected}");
    error_log("Received: {$received}");
});
```

### Webhook Timeout Issues

**Symptom**: WhatsApp shows "Message not delivered" errors

**Solution**:
1. Increase PHP execution time:
   ```php
   // wp-config.php
   set_time_limit(300);
   ```

2. Move to async processing:
   ```php
   // Already implemented via Action Scheduler
   // Check scheduled actions:
   ```
   Navigate to: Tools → Scheduled Actions

3. Check for slow database queries:
   ```php
   define('SAVEQUERIES', true);
   ```

---

## Message Delivery Issues

### Messages Not Sending

**Symptom**: Outbound messages fail to deliver

**Diagnostic Steps**:

1. **Check API Credentials**:
   - WhatsApp Commerce → Settings → API
   - Click "Test Connection"
   - Should show: ✅ Connection successful

2. **Check API Rate Limits**:
   ```bash
   # Check recent API calls
   tail -100 wp-content/uploads/wch-logs/api-YYYY-MM-DD.log | grep "rate_limit"
   ```

3. **Verify Phone Number Format**:
   - Must include country code
   - No spaces, dashes, or parentheses
   - Example: `+15551234567` (correct)
   - Example: `(555) 123-4567` (incorrect)

**Common Solutions**:

- **Invalid access token**: Regenerate token in Meta Business Suite
- **Phone number not opted in**: Customer must initiate conversation first
- **Message template not approved**: Use approved templates for proactive messages
- **24-hour window expired**: Customer must send message first

### Messages Marked as Failed

**Symptom**: Messages show "failed" status in inbox

**Check Error Codes**:

Navigate to: WhatsApp Commerce → Logs → Filter by "error"

Common error codes and solutions:
- `131026`: Message undeliverable (user blocked bot)
- `131047`: Re-engagement message outside 24-hour window
- `131053`: Rate limit exceeded
- `133016`: Access token expired

### Images Not Displaying

**Symptom**: Image messages fail or show blank

**Solutions**:

1. **Verify image URL is publicly accessible**:
   ```bash
   curl -I https://yoursite.com/wp-content/uploads/product.jpg
   # Should return 200 OK
   ```

2. **Check image format and size**:
   - Supported: JPEG, PNG, WebP
   - Max size: 5MB for images
   - Recommended: 1024x1024 or smaller

3. **SSL certificate must be valid**:
   ```bash
   curl https://yoursite.com/wp-content/uploads/product.jpg
   # Should not show SSL errors
   ```

---

## Sync Problems

### Products Not Syncing

**Symptom**: Products don't appear in WhatsApp catalog

**Diagnostic Steps**:

1. **Check Sync Status**:
   - Navigate to: WhatsApp Commerce → Catalog Sync
   - Look for error messages

2. **Verify Product Requirements**:
   - Product must be published
   - Must have price
   - Must have at least one image
   - Must be in stock (if "Include Out of Stock" is disabled)

3. **Check Logs**:
   ```bash
   tail -100 wp-content/uploads/wch-logs/sync-YYYY-MM-DD.log
   ```

**Common Solutions**:

```php
// Re-sync single product
$sync = WCH_Product_Sync_Service::getInstance();
$result = $sync->sync_product(PRODUCT_ID);

// Re-sync all products
$result = $sync->sync_all_products();
```

### Inventory Discrepancies

**Symptom**: Stock levels don't match between WooCommerce and WhatsApp

**Solutions**:

1. **Enable Real-time Sync**:
   - Settings → Inventory → Enable Real-time Sync

2. **Manual Reconciliation**:
   ```bash
   wp eval '$sync = WCH_Inventory_Sync_Handler::getInstance(); $sync->reconcile_inventory();'
   ```

3. **Check Sync Logs**:
   - Navigate to: WhatsApp Commerce → Logs
   - Filter: Type = "inventory"

---

## AI Assistant Issues

### AI Not Responding

**Symptom**: Bot sends generic responses instead of AI-powered ones

**Diagnostic Steps**:

1. **Verify AI is Enabled**:
   - Settings → AI Assistant → Enable AI (checkbox)

2. **Check API Key**:
   ```bash
   # Test OpenAI API key
   curl https://api.openai.com/v1/models \
     -H "Authorization: Bearer YOUR_API_KEY"
   ```

3. **Check Budget Limits**:
   - Settings → AI Assistant → Monthly Budget Cap
   - Ensure not exceeded

**Common Solutions**:

- **Invalid API key**: Regenerate in OpenAI dashboard
- **Budget exceeded**: Increase monthly cap
- **OpenAI service down**: Check [status.openai.com](https://status.openai.com)

### AI Responses Too Slow

**Symptom**: Long delays before AI responds

**Solutions**:

1. **Use Faster Model**:
   - Settings → AI Assistant → AI Model
   - Switch to `gpt-3.5-turbo` for faster responses

2. **Reduce Token Limit**:
   - Settings → AI Assistant → Max Tokens
   - Reduce to 200-300 for faster responses

3. **Check Network Latency**:
   ```bash
   time curl https://api.openai.com/v1/models \
     -H "Authorization: Bearer YOUR_API_KEY"
   ```

### AI Giving Incorrect Information

**Symptom**: AI provides wrong product details or prices

**Solutions**:

1. **Update System Prompt**:
   - Settings → AI Assistant → System Prompt
   - Add more specific instructions

2. **Use Functions Instead of Knowledge**:
   - AI should use `get_product_info()` function
   - Not rely on training data

3. **Check Product Data Quality**:
   - Ensure products have complete descriptions
   - Verify prices are current

---

## Payment Gateway Issues

### COD Not Working

**Symptom**: Cash on Delivery option doesn't appear

**Solutions**:

1. **Enable COD**:
   - Settings → Checkout → COD Enabled ✓

2. **Check Order Minimum**:
   - Settings → Checkout → Minimum Order Amount
   - Ensure cart total meets minimum

3. **Verify Shipping Zone**:
   - WooCommerce → Settings → Shipping
   - Ensure COD is enabled for customer's region

### Stripe Payment Fails

**Symptom**: Stripe payment returns error

**Diagnostic Steps**:

1. **Check Stripe Logs**:
   - Stripe Dashboard → Developers → Logs

2. **Verify Webhook**:
   - Stripe Dashboard → Developers → Webhooks
   - Check webhook delivery status

3. **Test Mode**:
   - Enable test mode
   - Use test card: `4242 4242 4242 4242`

**Common Solutions**:

- **Webhook not receiving events**: Update webhook URL
- **Currency mismatch**: Verify supported currencies
- **3D Secure issues**: Enable SCA handling

---

## Performance Problems

### Slow Admin Dashboard

**Symptom**: WhatsApp Commerce pages load slowly

**Solutions**:

1. **Enable Object Caching**:
   ```bash
   wp plugin install redis-cache --activate
   wp redis enable
   ```

2. **Limit Conversations Display**:
   - Reduce "Items per page" in Inbox

3. **Optimize Database**:
   ```bash
   wp db optimize
   ```

4. **Check for Slow Queries**:
   ```php
   // wp-config.php
   define('SAVEQUERIES', true);

   // View queries
   global $wpdb;
   print_r($wpdb->queries);
   ```

### High Memory Usage

**Symptom**: PHP memory exhausted errors

**Solutions**:

1. **Increase Memory Limit**:
   ```php
   // wp-config.php
   define('WP_MEMORY_LIMIT', '256M');
   define('WP_MAX_MEMORY_LIMIT', '512M');
   ```

2. **Optimize Sync Batch Size**:
   ```php
   add_filter('wch_sync_batch_size', function() {
       return 10; // Reduce from default 50
   });
   ```

3. **Disable Features**:
   - Temporarily disable AI if not needed
   - Disable real-time sync for large catalogs

---

## Debug Mode

### Enabling Debug Mode

```php
// wp-config.php

// WhatsApp Commerce Hub debug mode
define('WCH_DEBUG', true);
define('WCH_LOG_LEVEL', 'debug');

// WordPress debug mode
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Debug Information

```php
// Get debug info
$debug_info = [
    'php_version' => PHP_VERSION,
    'wp_version' => get_bloginfo('version'),
    'wc_version' => WC()->version,
    'wch_version' => WCH_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'plugins' => get_option('active_plugins'),
];

// Export debug info
echo json_encode($debug_info, JSON_PRETTY_PRINT);
```

---

## Log Interpretation

### Log Locations

```bash
# Plugin logs
wp-content/uploads/wch-logs/

# WordPress debug log
wp-content/debug.log

# Web server logs
/var/log/apache2/error.log
/var/log/nginx/error.log
```

### Log Levels

- **debug**: Detailed information for debugging
- **info**: Informational messages
- **warning**: Warning messages, not errors
- **error**: Error messages

### Common Log Patterns

**Webhook received**:
```
[2024-01-15 10:25:00] INFO [webhook] Webhook received from +15559876543
```

**API error**:
```
[2024-01-15 10:25:05] ERROR [api] WhatsApp API error: 131047 - Message undeliverable
```

**Sync issue**:
```
[2024-01-15 10:26:00] WARNING [sync] Product 123 missing required field: price
```

---

## WhatsApp API Error Codes

### Message Errors

| Code | Description | Solution |
|------|-------------|----------|
| 131026 | Message undeliverable | User blocked bot or number invalid |
| 131047 | Re-engagement outside 24h window | Use message template |
| 131053 | Rate limit exceeded | Wait before sending more messages |
| 133016 | Access token expired | Regenerate token in Meta |
| 135000 | Generic service error | Retry after delay |

### Template Errors

| Code | Description | Solution |
|------|-------------|----------|
| 132000 | Template not found | Use approved template name |
| 132001 | Template paused | Check template status in Meta |
| 132005 | Template deleted | Create new template |
| 132012 | Invalid parameters | Check parameter format |

### Authentication Errors

| Code | Description | Solution |
|------|-------------|----------|
| 190 | Access token invalid | Regenerate token |
| 200 | Permission denied | Check app permissions |
| 368 | Temporarily blocked | Wait 24 hours |

### Full Error Code Reference

See: [Meta WhatsApp Business API Error Codes](https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes)

---

## Support Contact

### Before Contacting Support

Please gather:

1. **System Information**:
   ```bash
   wp --info
   ```

2. **Error Logs**:
   ```bash
   tail -50 wp-content/uploads/wch-logs/error-YYYY-MM-DD.log
   ```

3. **Steps to Reproduce**

4. **Screenshots** (if applicable)

### Support Channels

- **Documentation**: [docs.example.com](https://docs.example.com)
- **GitHub Issues**: [github.com/your-repo/issues](https://github.com)
- **Email Support**: support@example.com
- **Priority Support**: support@example.com (for paid customers)

### Community Resources

- **Community Forum**: [community.example.com](https://community.example.com)
- **Facebook Group**: [facebook.com/groups/wch](https://facebook.com/groups/)
- **Twitter**: [@wch_support](https://twitter.com)

---

## Quick Diagnostic Script

Run this to get comprehensive diagnostic information:

```bash
#!/bin/bash

echo "=== WhatsApp Commerce Hub Diagnostics ==="
echo ""

echo "PHP Version:"
php -v | head -1
echo ""

echo "WordPress Version:"
wp core version
echo ""

echo "Plugin Version:"
wp plugin get whatsapp-commerce-hub --field=version
echo ""

echo "Required PHP Extensions:"
php -m | grep -E '(curl|json|mbstring|openssl|pdo_mysql|xml)'
echo ""

echo "Memory Limit:"
php -r "echo ini_get('memory_limit');"
echo ""

echo "Recent Errors (last 20):"
tail -20 wp-content/uploads/wch-logs/error-*.log
echo ""

echo "Active Plugins:"
wp plugin list --status=active --field=name
echo ""

echo "Database Tables:"
wp db query "SHOW TABLES LIKE 'wp_wch_%';"
echo ""

echo "=== End Diagnostics ==="
```

Save as `wch-diagnostics.sh`, make executable, and run:
```bash
chmod +x wch-diagnostics.sh
./wch-diagnostics.sh > diagnostics.txt
```

Send `diagnostics.txt` to support for faster resolution.

---

**Need More Help?** Check our [FAQ](README.md#faq) or [Contact Support](#support-contact)
