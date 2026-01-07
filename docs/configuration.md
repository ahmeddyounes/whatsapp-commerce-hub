# Configuration Guide

This guide provides detailed information about all configuration options available in WhatsApp Commerce Hub.

## Table of Contents

- [Accessing Settings](#accessing-settings)
- [API Configuration](#api-configuration)
- [General Settings](#general-settings)
- [Catalog Settings](#catalog-settings)
- [Checkout Settings](#checkout-settings)
- [Payment Gateway Configuration](#payment-gateway-configuration)
- [Notification Settings](#notification-settings)
- [Inventory Settings](#inventory-settings)
- [AI Assistant Settings](#ai-assistant-settings)
- [Abandoned Cart Recovery](#abandoned-cart-recovery)
- [Environment Variables](#environment-variables)
- [wp-config.php Constants](#wp-configphp-constants)
- [Multi-site Considerations](#multi-site-considerations)

## Accessing Settings

Navigate to: **WhatsApp Commerce → Settings** in your WordPress admin panel.

Settings are organized into sections with tabs for easy navigation.

## API Configuration

### WhatsApp Business Account ID

- **Setting Key**: `api.whatsapp_business_account_id`
- **Type**: String
- **Required**: Yes
- **Description**: Your WhatsApp Business Account ID from Meta Business Suite
- **Where to Find**: Meta Business Suite → WhatsApp → Settings → Business Account ID
- **Example**: `102290129340398`

### Phone Number ID

- **Setting Key**: `api.whatsapp_phone_number_id`
- **Type**: String
- **Required**: Yes
- **Description**: The ID of your WhatsApp Business phone number
- **Where to Find**: Meta Business Suite → WhatsApp → Phone Numbers → Select your number → Phone Number ID
- **Example**: `105055555555555`
- **Recommendation**: Use a dedicated business phone number, not a personal number

### Access Token

- **Setting Key**: `api.access_token`
- **Type**: String (Encrypted)
- **Required**: Yes
- **Description**: Permanent access token for WhatsApp Business API
- **Security**: Stored encrypted in database
- **Where to Find**: Meta Business Suite → WhatsApp → API Setup → Generate Token
- **Example**: `EAABsbCS1iHgBOZBVD...`
- **Important**:
  - Generate a permanent token, not a temporary one
  - Keep this token secure and never share it
  - Rotate tokens periodically for security

### Webhook Verify Token

- **Setting Key**: `api.webhook_verify_token`
- **Type**: String (Encrypted)
- **Required**: Yes
- **Description**: Custom token used to verify webhook requests from Meta
- **Security**: Should be a random, hard-to-guess string
- **Recommendation**: Use a strong random string (e.g., `openssl rand -hex 32`)
- **Example**: `my_super_secret_verify_token_2024`

### Webhook Secret

- **Setting Key**: `api.webhook_secret`
- **Type**: String (Encrypted)
- **Required**: No
- **Description**: Optional secret for additional webhook validation
- **Recommendation**: Enable for production environments

### API Version

- **Setting Key**: `api.api_version`
- **Type**: String
- **Default**: `v18.0`
- **Description**: WhatsApp Graph API version to use
- **Options**: `v18.0`, `v19.0`, `v20.0`
- **Recommendation**: Use latest stable version unless you need specific older features

## General Settings

### Enable Bot

- **Setting Key**: `general.enable_bot`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Master switch to enable/disable the WhatsApp bot
- **Recommendation**: Keep disabled until configuration is complete, then enable for production

### Business Name

- **Setting Key**: `general.business_name`
- **Type**: String
- **Default**: WordPress site name
- **Description**: Your business name displayed in conversations
- **Example**: `Acme Store`
- **Recommendation**: Use the same name as registered with WhatsApp Business

### Welcome Message

- **Setting Key**: `general.welcome_message`
- **Type**: String
- **Default**: `"Welcome! How can we help you today?"`
- **Description**: First message sent to new customers
- **Character Limit**: 1024 characters recommended
- **Example**:
  ```
  Welcome to Acme Store! 🛍️

  I'm here to help you:
  • Browse products
  • Track orders
  • Answer questions

  Type 'menu' to get started!
  ```

### Fallback Message

- **Setting Key**: `general.fallback_message`
- **Type**: String
- **Default**: `"Sorry, I didn't understand that. Please try again or type 'help' for assistance."`
- **Description**: Message shown when bot doesn't understand user input
- **Recommendation**: Keep it friendly and provide guidance

### Operating Hours

- **Setting Key**: `general.operating_hours`
- **Type**: JSON Object
- **Default**: `{}` (24/7 operation)
- **Description**: Define when your bot is active
- **Format**:
  ```json
  {
    "enabled": true,
    "timezone": "America/New_York",
    "schedule": {
      "monday": {"start": "09:00", "end": "17:00"},
      "tuesday": {"start": "09:00", "end": "17:00"},
      "wednesday": {"start": "09:00", "end": "17:00"},
      "thursday": {"start": "09:00", "end": "17:00"},
      "friday": {"start": "09:00", "end": "17:00"},
      "saturday": {"start": "10:00", "end": "14:00"},
      "sunday": {"closed": true}
    },
    "out_of_hours_message": "We're currently closed. Our hours are Mon-Fri 9am-5pm EST."
  }
  ```

### Timezone

- **Setting Key**: `general.timezone`
- **Type**: String
- **Default**: WordPress timezone
- **Description**: Timezone for scheduling and timestamps
- **Format**: PHP timezone string (e.g., `America/New_York`, `Europe/London`, `Asia/Dubai`)
- **Recommendation**: Match your business location

## Catalog Settings

### Catalog ID

- **Setting Key**: `catalog.catalog_id`
- **Type**: String
- **Required**: Auto-generated after first sync
- **Description**: WhatsApp catalog ID for your products
- **Note**: Automatically created during initial sync

### Sync Enabled

- **Setting Key**: `catalog.sync_enabled`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Enable automatic product synchronization
- **Recommendation**: Enable once API credentials are configured

### Sync Products

- **Setting Key**: `catalog.sync_products`
- **Type**: Array or String
- **Default**: `"all"`
- **Options**:
  - `"all"`: Sync all products
  - `["category_id_1", "category_id_2"]`: Sync specific categories
  - `[123, 456, 789]`: Sync specific product IDs
- **Example**:
  ```php
  // Sync only Electronics and Clothing categories
  ["electronics", "clothing"]
  ```

### Include Out of Stock

- **Setting Key**: `catalog.include_out_of_stock`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Whether to sync out-of-stock products to WhatsApp
- **Recommendation**: Set to `false` to avoid customer frustration

### Price Format

- **Setting Key**: `catalog.price_format`
- **Type**: String
- **Default**: WooCommerce price format
- **Description**: How prices are displayed in messages
- **Example**: `$%s`, `%s USD`, `₹%s`

### Currency Symbol

- **Setting Key**: `catalog.currency_symbol`
- **Type**: String
- **Default**: WooCommerce currency symbol
- **Description**: Currency symbol to display
- **Example**: `$`, `€`, `£`, `₹`

## Checkout Settings

### Enabled Payment Methods

- **Setting Key**: `checkout.enabled_payment_methods`
- **Type**: Array
- **Default**: `[]`
- **Options**: `["cod", "stripe", "razorpay", "whatsapp_pay", "pix"]`
- **Description**: List of payment methods available to customers
- **Recommendation**: Enable at least one method before going live

### COD Enabled

- **Setting Key**: `checkout.cod_enabled`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Enable Cash on Delivery payments
- **Recommendation**: Enable for markets where COD is preferred

### COD Extra Charge

- **Setting Key**: `checkout.cod_extra_charge`
- **Type**: Float
- **Default**: `0.0`
- **Description**: Additional charge for COD orders (flat rate)
- **Example**: `5.00` adds $5 to COD orders
- **Recommendation**: Use to offset COD handling costs

### Minimum Order Amount

- **Setting Key**: `checkout.min_order_amount`
- **Type**: Float
- **Default**: `0.0`
- **Description**: Minimum cart total required for checkout
- **Example**: `10.00` requires $10 minimum order
- **Use Case**: Ensure orders are profitable after shipping costs

### Maximum Order Amount

- **Setting Key**: `checkout.max_order_amount`
- **Type**: Float
- **Default**: `0.0` (no limit)
- **Description**: Maximum cart total allowed
- **Use Case**: Limit high-value orders for fraud prevention

### Require Phone Verification

- **Setting Key**: `checkout.require_phone_verification`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Send verification code before allowing checkout
- **Recommendation**: Enable for added security, especially with COD

## Payment Gateway Configuration

### Stripe

Configure in: **WhatsApp Commerce → Settings → Payments → Stripe**

- **Publishable Key**: Your Stripe publishable key (starts with `pk_`)
- **Secret Key**: Your Stripe secret key (starts with `sk_`)
- **Webhook Secret**: Stripe webhook signing secret
- **Supported Currencies**: Comma-separated list (e.g., `USD,EUR,GBP`)
- **Test Mode**: Enable for testing with test keys

**Setup Guide**:
1. Create account at [stripe.com](https://stripe.com)
2. Get API keys from Dashboard → Developers → API keys
3. Set up webhook: Dashboard → Developers → Webhooks
4. Webhook URL: `https://yoursite.com/wp-json/wch/v1/payments/stripe/webhook`
5. Subscribe to events: `payment_intent.succeeded`, `payment_intent.payment_failed`

### Razorpay (India)

Configure in: **WhatsApp Commerce → Settings → Payments → Razorpay**

- **Key ID**: Your Razorpay Key ID
- **Key Secret**: Your Razorpay Key Secret
- **Webhook Secret**: Razorpay webhook secret
- **Test Mode**: Enable for testing

**Setup Guide**:
1. Create account at [razorpay.com](https://razorpay.com)
2. Get credentials from Settings → API Keys
3. Set up webhook in Settings → Webhooks
4. Webhook URL: `https://yoursite.com/wp-json/wch/v1/payments/razorpay/webhook`

### WhatsApp Pay

Configure in: **WhatsApp Commerce → Settings → Payments → WhatsApp Pay**

- **Enabled**: Toggle to enable WhatsApp Pay
- **Supported Countries**: Currently available in India, Brazil, Singapore
- **Configuration**: Done through Meta Business Suite

**Requirements**:
- WhatsApp Business API access
- Business verified in supported country
- Meta Commerce Manager account

### PIX (Brazil)

Configure in: **WhatsApp Commerce → Settings → Payments → PIX**

- **PIX Key**: Your PIX key (email, phone, CPF, or random)
- **PIX Key Type**: Type of key used
- **Bank Name**: Your bank name
- **Account Holder**: Name on account

## Notification Settings

### Order Confirmation

- **Setting Key**: `notifications.order_confirmation`
- **Type**: Boolean
- **Default**: `true`
- **Description**: Send confirmation message after order is placed
- **Recommendation**: Always keep enabled

### Order Status Updates

- **Setting Key**: `notifications.order_status_updates`
- **Type**: Boolean
- **Default**: `true`
- **Description**: Notify customer when order status changes
- **Triggers**: Processing, Completed, Cancelled, Refunded

### Shipping Updates

- **Setting Key**: `notifications.shipping_updates`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Send shipping/tracking information
- **Requirements**: Shipping tracking plugin integration

### Abandoned Cart Reminder

- **Setting Key**: `notifications.abandoned_cart_reminder`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Send reminder for abandoned carts
- **Recommendation**: Enable to recover lost sales

### Abandoned Cart Delay Hours

- **Setting Key**: `notifications.abandoned_cart_delay_hours`
- **Type**: Integer
- **Default**: `24`
- **Description**: Hours to wait before sending reminder
- **Recommendation**: 4-24 hours depending on your products

## Inventory Settings

### Enable Real-time Sync

- **Setting Key**: `inventory.enable_realtime_sync`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Sync inventory changes to WhatsApp immediately
- **Performance**: May impact site performance with large catalogs
- **Recommendation**: Enable for small-medium catalogs, use scheduled sync for large catalogs

### Low Stock Threshold

- **Setting Key**: `inventory.low_stock_threshold`
- **Type**: Integer
- **Default**: `5`
- **Description**: Quantity considered "low stock"
- **Use Case**: Alert customers or admins when products are running low

### Notify Low Stock

- **Setting Key**: `inventory.notify_low_stock`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Send admin notification when products hit low stock threshold

### Auto Fix Discrepancies

- **Setting Key**: `inventory.auto_fix_discrepancies`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Automatically fix stock mismatches between WooCommerce and WhatsApp
- **Recommendation**: Enable with caution, monitor logs

## AI Assistant Settings

### Enable AI

- **Setting Key**: `ai.enable_ai`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Enable AI-powered natural language understanding
- **Requirements**: OpenAI API key
- **Recommendation**: Enable for better customer experience

### OpenAI API Key

- **Setting Key**: `ai.openai_api_key`
- **Type**: String (Encrypted)
- **Required**: If AI is enabled
- **Description**: Your OpenAI API key
- **Where to Get**: [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
- **Security**: Stored encrypted

### AI Model

- **Setting Key**: `ai.ai_model`
- **Type**: String
- **Default**: `gpt-4`
- **Options**:
  - `gpt-4-turbo`: Latest GPT-4, best performance
  - `gpt-4`: Standard GPT-4
  - `gpt-3.5-turbo`: Faster, cheaper, slightly less accurate
- **Recommendation**: Use `gpt-4-turbo` for production, `gpt-3.5-turbo` for high-volume/budget-conscious

### AI Temperature

- **Setting Key**: `ai.ai_temperature`
- **Type**: Float
- **Default**: `0.7`
- **Range**: `0.0` to `1.0`
- **Description**: Controls randomness in AI responses
- **Guidelines**:
  - `0.0-0.3`: More focused and deterministic (recommended for product info)
  - `0.4-0.7`: Balanced (recommended for general conversation)
  - `0.8-1.0`: More creative and varied
- **Recommendation**: `0.5-0.7` for e-commerce

### AI Max Tokens

- **Setting Key**: `ai.ai_max_tokens`
- **Type**: Integer
- **Default**: `500`
- **Description**: Maximum tokens in AI responses
- **Guidelines**:
  - `150-300`: Short, concise responses
  - `300-500`: Standard responses (recommended)
  - `500-1000`: Detailed responses
- **Cost Impact**: Higher tokens = higher API costs

### AI System Prompt

- **Setting Key**: `ai.ai_system_prompt`
- **Type**: String (Long text)
- **Default**: `"You are a helpful customer service assistant for an e-commerce store."`
- **Description**: Instructions that define AI personality and behavior
- **Example**:
  ```
  You are a friendly and professional customer service assistant for Acme Store.

  Guidelines:
  - Be concise and helpful
  - Use emojis sparingly
  - Always provide product details when available
  - Guide customers toward making a purchase
  - Be polite and patient
  - For complex issues, offer to connect with human support

  Store Information:
  - We sell electronics and accessories
  - Free shipping on orders over $50
  - 30-day return policy
  - Customer support available Mon-Fri 9am-5pm EST
  ```

### Monthly Budget Cap

- **Setting Key**: `ai.monthly_budget_cap`
- **Type**: Float
- **Default**: `0.0` (no cap)
- **Description**: Maximum OpenAI spending per month
- **Example**: `100.00` limits to $100/month
- **Recommendation**: Set a reasonable cap to avoid unexpected costs

## Abandoned Cart Recovery

### Enabled

- **Setting Key**: `recovery.enabled`
- **Type**: Boolean
- **Default**: `false`
- **Description**: Enable abandoned cart recovery campaigns

### Delay Sequence

- **Sequence 1 Delay**: `recovery.delay_sequence_1` - Default: 4 hours
- **Sequence 2 Delay**: `recovery.delay_sequence_2` - Default: 24 hours
- **Sequence 3 Delay**: `recovery.delay_sequence_3` - Default: 48 hours
- **Description**: Send up to 3 reminder messages at specified intervals
- **Recommendation**:
  - First reminder: 2-4 hours (cart still fresh in mind)
  - Second reminder: 24 hours (next day)
  - Third reminder: 48-72 hours (last chance)

### Message Templates

- **Template 1**: `recovery.template_sequence_1`
- **Template 2**: `recovery.template_sequence_2`
- **Template 3**: `recovery.template_sequence_3`
- **Description**: WhatsApp message template IDs for each sequence
- **Requirements**: Templates must be approved by Meta

### Discount Settings

- **Discount Enabled**: `recovery.discount_enabled` - Offer discount to recover cart
- **Discount Type**: `recovery.discount_type` - `"percent"` or `"fixed"`
- **Discount Amount**: `recovery.discount_amount` - Value of discount
- **Example**: Type: `percent`, Amount: `10` = 10% off

## Environment Variables

You can override settings using environment variables. This is useful for:
- Keeping secrets out of database
- Different configurations per environment
- Docker/container deployments

### Supported Environment Variables

```bash
# API Configuration
WCH_WHATSAPP_PHONE_NUMBER_ID=105055555555555
WCH_WHATSAPP_BUSINESS_ACCOUNT_ID=102290129340398
WCH_ACCESS_TOKEN=EAABsbCS1iHgBOZBVD...
WCH_WEBHOOK_VERIFY_TOKEN=my_secret_token

# OpenAI Configuration
WCH_OPENAI_API_KEY=sk-...
WCH_OPENAI_MODEL=gpt-4-turbo

# Payment Gateways
WCH_STRIPE_SECRET_KEY=sk_live_...
WCH_STRIPE_PUBLISHABLE_KEY=pk_live_...
WCH_RAZORPAY_KEY_ID=rzp_live_...
WCH_RAZORPAY_KEY_SECRET=...
```

### Usage in Docker

```yaml
# docker-compose.yml
services:
  wordpress:
    environment:
      - WCH_WHATSAPP_PHONE_NUMBER_ID=${WHATSAPP_PHONE_ID}
      - WCH_ACCESS_TOKEN=${WHATSAPP_ACCESS_TOKEN}
      - WCH_OPENAI_API_KEY=${OPENAI_API_KEY}
```

## wp-config.php Constants

Define constants in `wp-config.php` for environment-specific settings.

### WCH_DEBUG

```php
define('WCH_DEBUG', true);
```

- **Type**: Boolean
- **Default**: `false`
- **Description**: Enable debug mode
- **Effects**:
  - Detailed error logging
  - Additional log messages
  - Stack traces in logs
- **Recommendation**: Enable only in development

### WCH_LOG_LEVEL

```php
define('WCH_LOG_LEVEL', 'debug');
```

- **Type**: String
- **Options**: `debug`, `info`, `warning`, `error`
- **Default**: `info`
- **Description**: Minimum log level to record
- **Recommendation**:
  - Development: `debug`
  - Staging: `info`
  - Production: `warning`

### WCH_DISABLE_AI

```php
define('WCH_DISABLE_AI', true);
```

- **Type**: Boolean
- **Default**: `false`
- **Description**: Completely disable AI features
- **Use Case**: Emergency fallback if OpenAI has issues

### WCH_CACHE_TTL

```php
define('WCH_CACHE_TTL', 3600);
```

- **Type**: Integer (seconds)
- **Default**: `900` (15 minutes)
- **Description**: Cache time-to-live for product data

### WCH_MAX_CART_ITEMS

```php
define('WCH_MAX_CART_ITEMS', 20);
```

- **Type**: Integer
- **Default**: `50`
- **Description**: Maximum items allowed in cart

### Security Constants

```php
// Encryption key (must be 32 characters)
define('WCH_ENCRYPTION_KEY', 'your-32-character-encryption-key-here');

// API key salt
define('WCH_API_KEY_SALT', 'random-salt-string');
```

## Multi-site Considerations

### Network Activation

When activated network-wide, each site needs individual configuration.

### Shared vs Individual Settings

**Shared Across Network** (Optional):
- OpenAI API key
- Stripe credentials (if using same account)

**Individual Per Site** (Required):
- WhatsApp Business Account
- Phone Number ID
- Access Token
- Webhook endpoints

### Configuration

```php
// wp-config.php - Network-wide constants
if (is_multisite()) {
    // Shared OpenAI key
    define('WCH_OPENAI_API_KEY', 'sk-...');

    // Site-specific WhatsApp credentials
    switch (get_current_blog_id()) {
        case 1: // Main site
            define('WCH_WHATSAPP_PHONE_NUMBER_ID', '111111111');
            break;
        case 2: // Site 2
            define('WCH_WHATSAPP_PHONE_NUMBER_ID', '222222222');
            break;
    }
}
```

### Database Tables

Each site has its own tables:
- `wp_wch_conversations`
- `wp_wch_carts`
- `wp_wch_messages`

For site 2: `wp_2_wch_conversations`, etc.

### Webhook URLs

Each site needs unique webhook:
- Site 1: `https://site1.com/wp-json/wch/v1/webhook`
- Site 2: `https://site2.com/wp-json/wch/v1/webhook`

## Best Practices

### Security

1. **Use Environment Variables** for sensitive data
2. **Enable HTTPS** for all webhook endpoints
3. **Rotate Tokens** periodically
4. **Limit API Access** to necessary IP ranges
5. **Monitor Logs** for suspicious activity

### Performance

1. **Disable Real-time Sync** for large catalogs
2. **Use Caching** for frequently accessed data
3. **Set Reasonable** AI token limits
4. **Schedule Heavy Operations** during off-peak hours

### Cost Optimization

1. **Use gpt-3.5-turbo** for high-volume conversations
2. **Set Monthly Budget Cap** for OpenAI
3. **Monitor API Usage** regularly
4. **Cache AI Responses** for common queries

### Customer Experience

1. **Test Conversation Flows** thoroughly
2. **Use Clear Welcome** and fallback messages
3. **Set Realistic** operating hours
4. **Enable Order Notifications** for transparency
5. **Monitor Abandoned Carts** and optimize recovery

---

**Next Steps**: [Set up webhooks](installation.md#webhook-configuration) | [Learn about the API](api-reference.md)
