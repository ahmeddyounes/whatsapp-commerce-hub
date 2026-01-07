# Installation Guide

This guide will walk you through installing and setting up WhatsApp Commerce Hub on your WordPress site.

## System Requirements

### Minimum Requirements

- **WordPress**: 6.0 or higher
- **WooCommerce**: 8.0 or higher
- **PHP**: 8.1 or higher
- **MySQL**: 5.7 or higher / MariaDB 10.3 or higher
- **PHP Extensions**:
  - `curl`
  - `json`
  - `mbstring`
  - `openssl`
  - `pdo_mysql`
  - `xml`

### Recommended Requirements

- **WordPress**: Latest stable version
- **WooCommerce**: Latest stable version
- **PHP**: 8.2 or higher
- **MySQL**: 8.0 or higher
- **Memory Limit**: 256MB or higher
- **Max Execution Time**: 300 seconds or higher (for large catalog syncs)
- **HTTPS**: SSL certificate required for webhook endpoints

### External Services

- **WhatsApp Business API**: Meta Business Account with verified phone number
- **OpenAI API**: Account and API key for AI assistant features
- **Payment Gateway Accounts** (optional):
  - Stripe account for card payments
  - Razorpay account for Indian payments
  - PIX integration for Brazilian payments

## Installation Methods

### Method 1: WordPress Admin (Recommended)

1. **Download the Plugin**
   ```
   Download the latest release from GitHub or your distribution channel
   ```

2. **Upload via WordPress Admin**
   - Navigate to: `WordPress Admin → Plugins → Add New → Upload Plugin`
   - Click "Choose File" and select the `whatsapp-commerce-hub.zip`
   - Click "Install Now"

3. **Activate the Plugin**
   - After installation completes, click "Activate Plugin"
   - You'll be redirected to the plugin settings page

### Method 2: Manual Installation

1. **Upload Plugin Files**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   unzip whatsapp-commerce-hub.zip
   # Or clone from repository
   git clone https://github.com/your-repo/whatsapp-commerce-hub.git
   ```

2. **Set File Permissions**
   ```bash
   cd whatsapp-commerce-hub
   find . -type d -exec chmod 755 {} \;
   find . -type f -exec chmod 644 {} \;
   ```

3. **Activate in WordPress**
   - Go to: `WordPress Admin → Plugins`
   - Find "WhatsApp Commerce Hub" and click "Activate"

### Method 3: Composer Installation

```bash
composer require whatsapp-commerce-hub/core
```

Then activate through WordPress admin or via WP-CLI:

```bash
wp plugin activate whatsapp-commerce-hub
```

## WhatsApp Business API Setup

### Step 1: Create Meta Business Account

1. Go to [Meta Business Suite](https://business.facebook.com/)
2. Click "Create Account" and follow the prompts
3. Verify your business information

### Step 2: Set Up WhatsApp Business API

1. **Access Business Settings**
   - Navigate to: Business Settings → WhatsApp Accounts
   - Click "Add" to create a new WhatsApp Business Account

2. **Add Phone Number**
   - Click "Add Phone Number"
   - Choose between:
     - **Test Number**: For development (limited messages)
     - **Production Number**: Verified business phone number
   - Follow verification process

3. **Get API Credentials**
   - Navigate to: WhatsApp → API Setup
   - Note down:
     - **Business Account ID**: Found in WhatsApp Business Account settings
     - **Phone Number ID**: Found under your phone number
     - **Access Token**: Generate a permanent token

   ![Screenshot Placeholder: Meta Business Suite - API Credentials Location]

### Step 3: Configure Webhook

1. **Get Webhook URL**
   ```
   Your webhook URL: https://yoursite.com/wp-json/wch/v1/webhook
   ```

2. **Set Up Webhook in Meta**
   - Navigate to: WhatsApp → Configuration
   - Click "Edit" next to Webhook
   - **Callback URL**: Enter your webhook URL
   - **Verify Token**: Create a random string (save this for plugin config)
   - Click "Verify and Save"

3. **Subscribe to Webhook Fields**
   - Select the following fields:
     - `messages`
     - `message_status`
     - `message_template_status_update`

   ![Screenshot Placeholder: Webhook Configuration in Meta Business Suite]

### Step 4: Verify API Access

Test your API credentials using curl:

```bash
curl -X GET "https://graph.facebook.com/v18.0/YOUR_PHONE_NUMBER_ID" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
```

Expected response:
```json
{
  "verified_name": "Your Business Name",
  "display_phone_number": "+1234567890",
  "id": "123456789012345"
}
```

## WooCommerce Configuration

### Prerequisites

1. **Install WooCommerce**
   - Navigate to: `Plugins → Add New`
   - Search for "WooCommerce"
   - Install and activate

2. **Configure WooCommerce**
   - Complete the WooCommerce setup wizard
   - Configure:
     - Store address
     - Currency
     - Shipping zones
     - Payment methods
     - Tax settings

3. **Add Products**
   - Create at least a few test products with:
     - Title
     - Description
     - Price
     - Images
     - Stock quantity
     - Categories

### Product Requirements

For best results with WhatsApp Commerce Hub:

- **Images**: At least one product image (JPG, PNG, WebP)
- **Description**: Clear product descriptions
- **SKU**: Unique SKUs for inventory tracking
- **Stock Management**: Enable stock management
- **Categories**: Organize products into logical categories
- **Variations**: Supported for variable products

## Plugin Configuration

### Step 1: Access Settings

Navigate to: `WhatsApp Commerce → Settings`

### Step 2: WhatsApp API Configuration

1. **Business Account ID**
   ```
   Enter your WhatsApp Business Account ID from Meta
   ```

2. **Phone Number ID**
   ```
   Enter your WhatsApp Phone Number ID
   ```

3. **Access Token**
   ```
   Paste your permanent access token
   ```

4. **Webhook Verify Token**
   ```
   Enter the verify token you created in Meta webhook setup
   ```

5. **Test Connection**
   - Click "Test Connection" button
   - Verify success message appears

### Step 3: OpenAI Configuration

1. **API Key**
   ```
   Enter your OpenAI API key (starts with sk-...)
   ```

2. **Model Selection**
   ```
   Recommended: gpt-4-turbo or gpt-3.5-turbo
   ```

3. **Temperature** (Optional)
   ```
   Default: 0.7 (range 0.0-1.0)
   Lower = more focused, Higher = more creative
   ```

### Step 4: Payment Gateway Configuration

Configure at least one payment method:

#### Cash on Delivery (COD)
- Enable: Check the box
- No additional configuration needed

#### Stripe
1. Enable Stripe
2. Enter Stripe Publishable Key
3. Enter Stripe Secret Key
4. Select supported currencies

#### Razorpay (for India)
1. Enable Razorpay
2. Enter Key ID
3. Enter Key Secret

#### WhatsApp Pay
1. Enable WhatsApp Pay
2. Configure through Meta Business Suite
3. No additional plugin configuration needed

#### PIX (for Brazil)
1. Enable PIX
2. Enter PIX Key
3. Configure QR code generation

### Step 5: General Settings

1. **Store Timezone**: Select your timezone
2. **Default Language**: Choose default language
3. **Session Timeout**: How long to keep conversation context (default: 30 minutes)
4. **Cart Expiry**: When to clean up abandoned carts (default: 24 hours)

### Step 6: Save Configuration

Click "Save Changes" at the bottom of the page.

## First Sync Walkthrough

### Initial Product Sync

1. **Navigate to Catalog Sync**
   ```
   WhatsApp Commerce → Catalog Sync
   ```

2. **Review Products**
   - You'll see a list of WooCommerce products
   - Check which products to sync (or select all)

3. **Start Sync**
   - Click "Sync to WhatsApp"
   - Progress bar will show sync status
   - Wait for completion (may take several minutes for large catalogs)

4. **Verify Sync**
   - Check sync status for each product
   - Green checkmark = successfully synced
   - Red X = sync failed (check logs)

### Verify Webhook

1. **Send Test Message**
   - Using a personal WhatsApp account
   - Send a message to your business number
   - Example: "Hello"

2. **Check Inbox**
   - Navigate to: `WhatsApp Commerce → Inbox`
   - Your test message should appear
   - Reply to verify two-way communication

3. **Test Product Browsing**
   - Send: "Show me products"
   - Bot should respond with product categories
   - Navigate through the conversation

## Post-Installation Steps

### 1. Configure Logging

```php
// Add to wp-config.php for detailed logging
define('WCH_DEBUG', true);
define('WCH_LOG_LEVEL', 'debug'); // debug, info, warning, error
```

### 2. Set Up Cron Jobs

The plugin uses WordPress cron for background tasks. For better reliability, set up system cron:

```bash
# Edit crontab
crontab -e

# Add this line (runs every minute)
* * * * * cd /path/to/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

### 3. Configure Backups

Ensure regular backups of:
- WordPress database (includes conversations, carts, orders)
- Plugin configuration
- WhatsApp API credentials (securely)

### 4. Set Up Monitoring

Monitor these metrics:
- Webhook uptime and response time
- API rate limits and usage
- Message delivery rate
- Conversion rate

### 5. Security Hardening

1. **HTTPS Required**
   ```
   Ensure SSL certificate is valid
   ```

2. **API Key Security**
   ```php
   // Store in wp-config.php instead of database
   define('WCH_WHATSAPP_ACCESS_TOKEN', 'your-token-here');
   define('WCH_OPENAI_API_KEY', 'your-key-here');
   ```

3. **Webhook Security**
   - Use strong verify token
   - Enable IP whitelisting if possible

4. **File Permissions**
   ```bash
   # Ensure logs directory is writable but not public
   chmod 750 wp-content/uploads/wch-logs/
   ```

## Multi-site Installation

For WordPress Multisite networks:

### Network Activation

```bash
wp plugin activate whatsapp-commerce-hub --network
```

### Per-Site Configuration

1. Each site needs its own:
   - WhatsApp Business API credentials
   - Phone number
   - Webhook endpoint

2. Configure per-site:
   ```
   Navigate to: Site Admin → WhatsApp Commerce → Settings
   ```

3. Considerations:
   - Separate WhatsApp numbers for each site
   - Shared or separate OpenAI API keys
   - Isolated customer databases

## Troubleshooting Installation

### Plugin Won't Activate

**Error**: PHP version requirement not met
```
Solution: Upgrade to PHP 8.1 or higher
```

**Error**: WooCommerce not found
```
Solution: Install and activate WooCommerce first
```

### Webhook Not Receiving Messages

1. **Check URL Accessibility**
   ```bash
   curl -X POST https://yoursite.com/wp-json/wch/v1/webhook \
     -H "Content-Type: application/json" \
     -d '{"test": true}'
   ```

2. **Check Permalink Settings**
   - Go to: Settings → Permalinks
   - Click "Save Changes" to flush rewrite rules

3. **Check Firewall Rules**
   - Whitelist Meta's IP ranges
   - Allow POST requests to webhook endpoint

### Database Tables Not Created

```bash
# Manually trigger database setup
wp eval 'WCH_Database_Manager::getInstance()->createTables();'
```

### Sync Failures

1. **Check API Credentials**
   - Test connection in settings
   - Verify token hasn't expired

2. **Check Product Data**
   - Ensure products have images
   - Check product is published
   - Verify price is set

3. **Check Logs**
   ```
   WhatsApp Commerce → Logs
   Filter by: 'error' level
   ```

## Next Steps

After successful installation:

1. ✅ [Configure Settings](configuration.md) - Fine-tune plugin behavior
2. ✅ [Test Conversation Flow](../README.md#quick-start) - Ensure everything works
3. ✅ [Set Up Analytics](configuration.md#analytics) - Track performance
4. ✅ [Enable Abandoned Cart Recovery](configuration.md#abandoned-cart) - Increase conversions
5. ✅ [Read API Documentation](api-reference.md) - For custom integrations

## Getting Help

- **Documentation**: [Full documentation](README.md)
- **Troubleshooting**: [Common issues and solutions](troubleshooting.md)
- **Support**: Contact support team
- **Community**: Join our community forum

---

**Installation Complete!** 🎉 Your WhatsApp Commerce Hub is ready to use.
