=== WhatsApp Commerce Hub ===
Contributors: whatsappcommercehub
Tags: whatsapp, woocommerce, ecommerce, chat commerce, conversational commerce
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform your WooCommerce store into a complete commerce ecosystem inside WhatsApp with real-time sync and conversational AI.

== Description ==

WhatsApp Commerce Hub turns WhatsApp into a complete e-commerce platform for your WooCommerce store. Customers can browse products, add items to cart, checkout, and track orders - all within WhatsApp conversations.

= Key Features =

* **Product Catalog Sync** - Automatic two-way sync between WooCommerce and WhatsApp catalog
* **Real-time Inventory Management** - Instant stock updates across all platforms
* **Conversational Shopping** - AI-powered chat assistant helps customers find and purchase products
* **Order Management** - Complete order lifecycle from creation to fulfillment via WhatsApp
* **Payment Integration** - Support for multiple payment gateways including Stripe, PayPal, and Razorpay
* **Abandoned Cart Recovery** - Automated follow-ups to recover lost sales
* **Customer Re-engagement** - Smart campaigns to bring back previous customers
* **Message Templates** - Pre-approved templates for order confirmations, shipping updates, and more
* **Broadcast Messaging** - Send promotional messages to customer segments
* **Analytics Dashboard** - Track conversions, revenue, and customer engagement metrics
* **Multi-language Support** - Fully translatable with i18n support

= Use Cases =

* Run your entire store through WhatsApp conversations
* Reduce cart abandonment with automated reminders
* Provide instant customer support and product recommendations
* Re-engage customers with personalized promotions
* Track order status and send shipping notifications
* Accept payments directly in WhatsApp conversations

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.1 or higher
* WhatsApp Business API account
* Valid SSL certificate (HTTPS)
* MySQL 5.7+ or MariaDB 10.3+

= Setup Overview =

1. Install and activate the plugin
2. Configure WhatsApp Business API credentials in Settings
3. Set up webhook URL in WhatsApp Business Manager
4. Configure payment gateways
5. Customize message templates
6. Start receiving customer messages!

= Documentation & Support =

* [Documentation](https://github.com/yourusername/whatsapp-commerce-hub/wiki)
* [API Reference](https://github.com/yourusername/whatsapp-commerce-hub/wiki/API-Reference)
* [GitHub Repository](https://github.com/yourusername/whatsapp-commerce-hub)
* [Issue Tracker](https://github.com/yourusername/whatsapp-commerce-hub/issues)

= Privacy & Data =

This plugin communicates with WhatsApp Business API to send and receive messages. Customer data is processed according to your privacy policy. The plugin stores:
* Customer conversations and message history
* Order information linked to WhatsApp interactions
* Customer preferences and engagement metrics

All data is stored in your WordPress database and is not shared with third parties except WhatsApp for message delivery.

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins > Add New
3. Search for "WhatsApp Commerce Hub"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Navigate to Plugins > Add New > Upload Plugin
4. Choose the downloaded ZIP file and click "Install Now"
5. After installation, click "Activate Plugin"

= Configuration Steps =

1. **WhatsApp Business API Setup**
   - Go to WooCommerce > WhatsApp Hub > Settings
   - Enter your WhatsApp Business API credentials
   - Configure webhook URL in your WhatsApp Business Manager
   - Test the connection

2. **Payment Gateway Configuration**
   - Navigate to Settings > Payment Gateways
   - Enable and configure your preferred payment methods
   - Test payment flow in sandbox mode first

3. **Template Setup**
   - Go to WhatsApp Hub > Templates
   - Review and customize default message templates
   - Submit templates for WhatsApp approval if needed

4. **Catalog Sync**
   - Navigate to WhatsApp Hub > Catalog Sync
   - Select products to sync with WhatsApp catalog
   - Configure automatic sync settings

5. **Testing**
   - Send a test message to your business number
   - Verify webhook is receiving messages
   - Test a complete purchase flow

= Common Issues =

* **Webhook not receiving messages** - Ensure your site has a valid SSL certificate and the webhook URL is correctly configured in WhatsApp Business Manager
* **Products not syncing** - Check that products have required fields (name, price, image) and verify API credentials
* **Payment failures** - Verify payment gateway credentials and ensure your site meets PCI compliance requirements

== Frequently Asked Questions ==

= What is WhatsApp Business API and how do I get access? =

WhatsApp Business API is an enterprise solution for businesses to communicate with customers at scale. You need to apply through an official WhatsApp Business Solution Provider (BSP). The plugin requires valid API credentials to function.

= How much does WhatsApp Business API cost? =

WhatsApp charges per conversation based on the country and whether the conversation is business-initiated or user-initiated. Pricing varies by region. Check WhatsApp's official pricing page for current rates. The plugin itself is free.

= Is customer data secure and private? =

Yes. All customer data is stored in your WordPress database with industry-standard security measures. Messages are encrypted in transit using HTTPS. We do not share data with third parties except WhatsApp for message delivery. Ensure you have a privacy policy that covers WhatsApp communications.

= Does this work with WooCommerce Subscriptions? =

The plugin is designed for standard WooCommerce products and simple/variable products. Subscription support may be added in future versions.

= Can I customize the message templates? =

Yes! You can customize all message templates through the admin interface. However, some templates must be pre-approved by WhatsApp before they can be used for business-initiated conversations.

= What payment gateways are supported? =

The plugin includes built-in support for Stripe, PayPal, Razorpay, and bank transfer. You can also integrate custom payment gateways using the provided APIs.

= How does abandoned cart recovery work? =

When a customer adds items to cart but doesn't complete checkout, the plugin automatically schedules reminder messages at configurable intervals (e.g., 1 hour, 24 hours). Messages include a direct link to complete the purchase.

= Can I send promotional broadcasts to all customers? =

Yes, but with limitations. WhatsApp has strict rules about business-initiated conversations. Promotional messages can only be sent to customers who have opted in, and you must use pre-approved message templates. Violating these rules can result in your account being banned.

= Does the plugin support multiple languages? =

Yes! The plugin is fully translatable using standard WordPress i18n functions. You can provide translations for your language or use the included POT file to create translations.

= What happens if my WhatsApp API account is rate-limited? =

The plugin includes built-in rate limiting and retry logic. Messages are queued and sent according to WhatsApp's rate limits. Failed messages are automatically retried with exponential backoff.

= How do I migrate from WhatsApp Business App to this plugin? =

You'll need to apply for WhatsApp Business API access through a BSP. Once approved, you can migrate your phone number from the Business App to the API. This process can take a few days and involves verification steps.

= Can multiple team members respond to customer messages? =

Yes! The Inbox interface allows multiple WordPress admin users to view and respond to conversations simultaneously. The plugin tracks which agent is handling each conversation.

= Is there a limit on the number of products I can sync? =

WhatsApp catalog has a limit of 10,000 products per catalog. The plugin can handle syncing large catalogs, but initial sync may take some time depending on your server resources.

= How do I handle returns and refunds? =

The plugin supports refund processing through the Order Management interface. Customers can request refunds via WhatsApp, and admins can approve and process them directly from the dashboard. Refunds are synced back to WooCommerce and your payment gateway.

== Screenshots ==

1. **Dashboard Overview** - Main dashboard showing key metrics: conversation count, revenue, conversion rate, and recent activity
2. **Settings Page** - WhatsApp API configuration with webhook setup and connection testing
3. **Inbox Interface** - Unified inbox showing all customer conversations with real-time updates and agent assignment
4. **Conversation View** - Detailed conversation view with customer info, order history, and quick actions
5. **Catalog Sync** - Product catalog synchronization interface with bulk actions and sync status
6. **Message Templates** - Template management with approval status and variable placeholders
7. **Analytics Dashboard** - Comprehensive analytics with charts for revenue, conversions, and customer engagement
8. **Payment Settings** - Payment gateway configuration with multiple provider support
9. **Abandoned Cart Recovery** - Automated cart recovery campaigns with scheduling and templates
10. **Broadcast Messaging** - Segment-based broadcast campaign creation with template selection

== Changelog ==

= 1.0.0 - 2026-01-07 =
* Initial release
* Product catalog sync with WhatsApp
* Real-time inventory management
* Conversational shopping with AI assistant
* Complete order management lifecycle
* Multi-gateway payment integration (Stripe, PayPal, Razorpay)
* Abandoned cart recovery system
* Customer re-engagement campaigns
* Message template management
* Broadcast messaging with segmentation
* Analytics and reporting dashboard
* Background job queue for async processing
* Error handling and logging system
* REST API for integrations
* Multi-language support with i18n
* WooCommerce 8.0+ compatibility
* WordPress 6.0+ compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release. Welcome to WhatsApp Commerce Hub! Please configure your WhatsApp Business API credentials in Settings before use.

== Additional Information ==

= System Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 8.1 or higher with extensions: json, curl, mbstring, openssl
* MySQL 5.7+ or MariaDB 10.3+
* Valid SSL certificate (HTTPS required)
* Minimum 256MB PHP memory limit (512MB recommended)

= WhatsApp Business API Requirements =

* Approved WhatsApp Business API account
* Verified business phone number
* Access to WhatsApp Business Manager
* Active Facebook Business Manager account

= Third-Party Services =

This plugin relies on the following external services:

* **WhatsApp Business API** - For sending and receiving messages (https://business.whatsapp.com/)
* **Payment Gateways** - Stripe, PayPal, Razorpay for payment processing (optional, based on configuration)

Please review the terms of service and privacy policies of these services.

= Contributing =

We welcome contributions! Visit our GitHub repository to report issues, suggest features, or submit pull requests:
https://github.com/yourusername/whatsapp-commerce-hub

= Credits =

Developed by WhatsApp Commerce Hub Team
Built with WooCommerce and WordPress
