# WhatsApp Webhook Setup Guide

This guide explains how to configure and use the WhatsApp webhook handler for the WhatsApp Commerce Hub plugin.

## Overview

The webhook handler receives real-time events from WhatsApp Business API, including:
- **Messages**: New incoming messages from customers
- **Statuses**: Message delivery status updates (sent, delivered, read, failed)
- **Errors**: Delivery errors and failures

## Configuration

### 1. Set Webhook Credentials

In WordPress Admin, navigate to WCH Settings and configure:

```php
// API Settings
$settings = WCH_Settings::getInstance();

// Set webhook verify token (used by Meta for webhook verification)
$settings->set('api.webhook_verify_token', 'your_secure_verify_token_here');

// Set webhook secret (used for signature validation)
$settings->set('api.webhook_secret', 'your_secure_webhook_secret_here');
```

**Security Notes:**
- Use strong, random strings for both tokens
- The verify token is used during Meta webhook setup
- The secret is used to validate incoming webhook signatures (HMAC-SHA256)
- Both values are encrypted in the database

### 2. Configure Webhook in Meta Business Manager

1. Go to your Meta App Dashboard
2. Navigate to WhatsApp > Configuration
3. Set the webhook URL:
   ```
   https://yourdomain.com/wp-json/wch/v1/webhook
   ```
4. Set the verify token (must match the one in settings)
5. Subscribe to the following webhook fields:
   - `messages`
   - `message_status` (or `statuses`)
   - `errors`

### 3. Verify Setup

Meta will send a GET request to verify the webhook:

```http
GET /wp-json/wch/v1/webhook?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=CHALLENGE_VALUE
```

The webhook handler will:
- Validate the verify token
- Return the challenge value if valid
- Return 403 Forbidden if invalid

## Webhook Events

### Message Event

Triggered when a customer sends a message:

```php
// Listen for incoming messages
add_action('wch_webhook_messages', function($data) {
    // $data contains:
    // - message_id: WhatsApp message ID
    // - from: Customer phone number
    // - timestamp: Message timestamp
    // - type: Message type (text, interactive, image, document, button, location)
    // - content: Extracted message content based on type
    // - context: Replied message ID (if this is a reply)

    error_log('New message from: ' . $data['from']);
    error_log('Message type: ' . $data['type']);
    error_log('Content: ' . print_r($data['content'], true));
});
```

**Supported Message Types:**

1. **Text**
   ```php
   $content = ['body' => 'Message text']
   ```

2. **Interactive Button Reply**
   ```php
   $content = [
       'type' => 'button_reply',
       'id' => 'button_id',
       'title' => 'Button title'
   ]
   ```

3. **Interactive List Reply**
   ```php
   $content = [
       'type' => 'list_reply',
       'id' => 'list_item_id',
       'title' => 'Item title',
       'description' => 'Item description'
   ]
   ```

4. **Image**
   ```php
   $content = [
       'id' => 'media_id',
       'mime_type' => 'image/jpeg',
       'sha256' => 'file_hash',
       'caption' => 'Optional caption'
   ]
   ```

5. **Document**
   ```php
   $content = [
       'id' => 'media_id',
       'filename' => 'document.pdf',
       'mime_type' => 'application/pdf',
       'sha256' => 'file_hash',
       'caption' => 'Optional caption'
   ]
   ```

6. **Button**
   ```php
   $content = [
       'payload' => 'button_payload',
       'text' => 'Button text'
   ]
   ```

7. **Location**
   ```php
   $content = [
       'latitude' => '37.7749',
       'longitude' => '-122.4194',
       'name' => 'Location name',
       'address' => 'Full address'
   ]
   ```

### Status Event

Triggered when message status changes:

```php
// Listen for status updates
add_action('wch_webhook_statuses', function($data) {
    // $data contains:
    // - message_id: WhatsApp message ID
    // - status: Status (sent, delivered, read, failed)
    // - timestamp: Status update timestamp
    // - recipient_id: Customer phone number
    // - errors: Array of errors (if status is 'failed')

    error_log('Message ' . $data['message_id'] . ' status: ' . $data['status']);

    if ($data['status'] === 'failed' && !empty($data['errors'])) {
        error_log('Errors: ' . print_r($data['errors'], true));
    }
});
```

**Status Values:**
- `sent`: Message sent to WhatsApp servers
- `delivered`: Message delivered to customer's device
- `read`: Customer read the message
- `failed`: Message delivery failed

### Error Event

Triggered when WhatsApp reports an error:

```php
// Listen for error events
add_action('wch_webhook_errors', function($data) {
    // $data contains:
    // - code: Error code
    // - title: Error title
    // - message: Error message
    // - details: Additional error details

    error_log('WhatsApp error: ' . $data['message']);
    error_log('Error code: ' . $data['code']);
});
```

## Security Features

### 1. Signature Validation

All incoming webhook requests are validated using HMAC-SHA256:

```php
// Automatic validation in webhook handler
$signature = $request->get_header('X-Hub-Signature-256');
$body = $request->get_body();
$expected = 'sha256=' . hash_hmac('sha256', $body, $webhook_secret);

// Uses timing-safe comparison
if (!hash_equals($expected, $signature)) {
    return 401 Unauthorized;
}
```

### 2. Idempotency

Duplicate messages are automatically ignored:

```php
// Transient-based idempotency (1 hour TTL)
$transient_key = 'wch_msg_' . $message_id;

if (get_transient($transient_key)) {
    // Duplicate message, ignore
    return;
}

set_transient($transient_key, true, HOUR_IN_SECONDS);
```

### 3. Rate Limiting

Webhook endpoint has rate limiting:
- 1000 requests per minute per client
- Based on IP address
- Returns 429 Too Many Requests if exceeded

## Async Processing

All webhook events are processed asynchronously using WooCommerce Action Scheduler:

```php
// Events are queued immediately
as_enqueue_async_action(
    'wch_process_webhook_messages',
    ['data' => $message_data],
    'wch'
);

// Returns 200 OK immediately to WhatsApp
// Processing happens in background
```

**Benefits:**
- Fast webhook response (< 100ms)
- No timeout issues
- Automatic retry on failure
- Scalable processing

## Testing

### Local Testing

Use the included test script:

```bash
cd wp-content/plugins/whatsapp-commerce-hub
php examples/webhook-test.php
```

This will test:
1. Webhook verification (GET request)
2. Signature validation
3. Message event processing
4. Status event processing
5. Error event processing
6. Idempotency check
7. Invalid signature handling

### cURL Testing

Test webhook verification:

```bash
curl "https://yourdomain.com/wp-json/wch/v1/webhook?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=12345"
```

Test message webhook:

```bash
# Calculate signature
PAYLOAD='{"object":"whatsapp_business_account","entry":[...]}'
SECRET='your_webhook_secret'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

# Send webhook
curl -X POST https://yourdomain.com/wp-json/wch/v1/webhook \
  -H "Content-Type: application/json" \
  -H "X-Hub-Signature-256: sha256=$SIGNATURE" \
  -d "$PAYLOAD"
```

## Debugging

### 1. Check Logs

All webhook events are logged:

```php
// In WP Admin > WCH > Logs, filter by:
// - Category: webhook
// - Category: queue

// Example log entries:
// [info] Webhook event received (object: whatsapp_business_account, entry_count: 1)
// [info] Message event processed (message_id: wamid.xxx, from: +1555...)
// [debug] Duplicate message ignored (message_id: wamid.xxx)
// [error] Webhook signature validation failed
```

### 2. View Raw Payloads

Raw webhook payloads are logged for debugging:

```php
WCH_Logger::log('debug', 'Raw webhook payload stored', 'webhook', [
    'reference_id' => $message_id,
    'event_type' => 'message',
    'payload' => $raw_payload
]);
```

### 3. Monitor Action Scheduler

Check background job processing:

```
WP Admin > WooCommerce > Status > Scheduled Actions
```

Filter by group: `wch`

## Advanced Usage

### Custom Event Handlers

You can add your own handlers:

```php
// Handle incoming text messages
add_action('wch_webhook_messages', function($data) {
    if ($data['type'] !== 'text') {
        return;
    }

    $message = $data['content']['body'];
    $from = $data['from'];

    // Auto-reply example
    if (stripos($message, 'hours') !== false) {
        // Send business hours info
        wch_send_message($from, "We're open Mon-Fri 9am-5pm");
    }
}, 10, 1);
```

### Track Message Status

```php
// Update local message status
add_action('wch_webhook_statuses', function($data) {
    global $wpdb;

    $wpdb->update(
        $wpdb->prefix . 'wch_messages',
        ['status' => $data['status']],
        ['wa_message_id' => $data['message_id']],
        ['%s'],
        ['%s']
    );
}, 10, 1);
```

## Troubleshooting

### Webhook Not Receiving Events

1. Check webhook URL is publicly accessible
2. Verify SSL certificate is valid
3. Check firewall/security plugins aren't blocking requests
4. Verify webhook secret and verify token are correct
5. Check Meta webhook configuration

### Signature Validation Failing

1. Ensure webhook secret matches Meta configuration
2. Check for any middleware modifying request body
3. Verify Content-Type is application/json
4. Check for trailing whitespace in secret

### Duplicate Messages

- This is expected behavior due to idempotency
- Check logs for "Duplicate message ignored"
- Transients expire after 1 hour

### Events Not Processing

1. Check Action Scheduler is running
2. Verify WooCommerce is active
3. Check for PHP errors in debug log
4. Verify async actions are queued: WC > Status > Scheduled Actions

## API Reference

### REST Endpoints

**Webhook Verification (GET)**
```
GET /wp-json/wch/v1/webhook
Query params: hub.mode, hub.verify_token, hub.challenge
Response: Integer (challenge value) or 403
```

**Webhook Event Receiver (POST)**
```
POST /wp-json/wch/v1/webhook
Headers: X-Hub-Signature-256
Body: JSON webhook payload
Response: {"success": true, "message": "Webhook received"}
```

### Action Hooks

```php
// Sync hooks (fire immediately)
do_action('wch_webhook_messages', $data);
do_action('wch_webhook_statuses', $data);
do_action('wch_webhook_errors', $data);

// Async hooks (queued for background processing)
do_action('wch_process_webhook_messages', ['data' => $data]);
do_action('wch_process_webhook_statuses', ['data' => $data]);
do_action('wch_process_webhook_errors', ['data' => $data]);
```

## Security Best Practices

1. **Use HTTPS**: Always use SSL for webhook endpoint
2. **Strong Secrets**: Use 32+ character random strings
3. **Rotate Tokens**: Periodically update webhook secret
4. **Monitor Logs**: Watch for failed signature validations
5. **Rate Limiting**: Keep default rate limits enabled
6. **Firewall**: Consider IP whitelisting for Meta IPs
7. **Validate Input**: Always sanitize webhook data before use

## Performance Considerations

- Webhook responses are sent in < 100ms
- Processing happens asynchronously
- No database writes in webhook handler
- Transients used for fast idempotency checks
- Rate limiting prevents abuse
- Automatic scaling with Action Scheduler

## Support

For issues or questions:
1. Check logs in WP Admin > WCH > Logs
2. Review test script output
3. Verify Meta webhook configuration
4. Check WordPress error logs
5. Review Action Scheduler queue
