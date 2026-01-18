# WhatsApp Commerce Hub Documentation

Welcome to the WhatsApp Commerce Hub documentation. This comprehensive guide will help you set up, configure, and extend a complete e-commerce ecosystem inside WhatsApp with seamless WooCommerce integration.

## Overview

WhatsApp Commerce Hub transforms WhatsApp into a fully-featured e-commerce platform, enabling customers to browse products, manage their shopping cart, complete purchases, and receive order updates—all within WhatsApp conversations.

### Key Features

- **🛍️ Complete Product Catalog**: Automatic synchronization of WooCommerce products to WhatsApp Business API
- **🤖 AI-Powered Assistant**: Natural language understanding for customer queries using OpenAI integration
- **💬 Conversational Commerce**: Interactive shopping experience with FSM-driven conversation flows
- **🛒 Cart Management**: Full shopping cart functionality within WhatsApp conversations
- **💳 Multiple Payment Gateways**: Support for COD, Stripe, Razorpay, WhatsApp Pay, and PIX
- **📦 Order Management**: Real-time order status updates and notifications
- **📊 Analytics Dashboard**: Track conversation metrics, conversion rates, and revenue
- **🎯 Abandoned Cart Recovery**: Automated re-engagement campaigns
- **📢 Broadcast Messaging**: Targeted marketing campaigns to customer segments
- **🔄 Real-time Sync**: Bidirectional sync between WooCommerce and WhatsApp catalog
- **🌐 Multi-language Support**: Internationalization ready with translation files

## Quick Start

### Requirements

- WordPress 6.0 or higher
- WooCommerce 8.0 or higher
- PHP 8.1 or higher
- MySQL 5.7 or higher
- WhatsApp Business API account
- OpenAI API key (for AI assistant features)

### Installation

```bash
# Install the plugin
1. Upload the plugin files to `/wp-content/plugins/whatsapp-commerce-hub/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your WhatsApp Business API credentials
4. Run initial product sync
```

For detailed installation instructions, see [Installation Guide](installation.md).

### First Steps

1. **Configure WhatsApp API**: Enter your Business Account ID, Phone Number ID, and Access Token
2. **Set Up Payment Gateways**: Configure your preferred payment methods
3. **Sync Products**: Perform initial product catalog synchronization
4. **Test the Webhook**: Verify WhatsApp webhook is receiving messages
5. **Send a Test Message**: Start a conversation with your WhatsApp Business number

## Documentation Structure

### For Administrators

- **[Installation Guide](installation.md)**: System requirements, installation steps, and WhatsApp API setup
- **[Configuration](configuration.md)**: Complete settings reference and recommendations
- **[Troubleshooting](troubleshooting.md)**: Common issues, debugging, and support resources

### For Developers

- **[Module Map](module-map.md)**: Complete module overview with providers, contracts, tables, jobs, and hooks
- **[Boot Sequence](boot-sequence.md)**: Plugin initialization flow, requirements, container, and context management
- **[Webhooks Ingestion Pipeline](webhooks-ingestion-pipeline.md)**: Complete webhook processing flow from REST controller to actions
- **[Queue System](queue-system.md)**: Priority queue, unified payload format, and extension points
- **[API Reference](api-reference.md)**: REST API endpoints, authentication, and examples
- **[Hooks Reference](hooks-reference.md)**: WordPress actions and filters for customization
- **[Extending the Plugin](extending.md)**: Build custom intents, payment gateways, and add-ons
- **[Architecture Improvement Plan](architecture-improvement-plan.md)**: Roadmap for architectural enhancements

### Architecture Decision Records (ADRs)

- **[ADR-001: Canonical Model Layer](adr-001-canonical-model-layer.md)**: Decision on Domain vs Entities/ValueObjects ownership

## Core Concepts

### Conversation Flow State Machine (FSM)

The plugin uses a finite state machine to manage conversation states:

- **IDLE**: Customer not in active conversation
- **BROWSING**: Viewing products and categories
- **CART**: Managing shopping cart
- **CHECKOUT**: Providing shipping information
- **PAYMENT**: Processing payment
- **CONFIRMED**: Order completed

### Intent Classification

The AI assistant classifies customer messages into intents:

- **BROWSE_PRODUCTS**: Customer wants to view products
- **ASK_PRODUCT_INFO**: Questions about specific products
- **ADD_TO_CART**: Add items to cart
- **VIEW_CART**: See cart contents
- **CHECKOUT**: Begin checkout process
- **TRACK_ORDER**: Check order status
- **CUSTOMER_SERVICE**: General inquiries

### Webhook Processing

All WhatsApp messages are received via webhook and processed through:

1. **Webhook Handler**: Validates and parses incoming messages
2. **Intent Classifier**: Determines customer intent
3. **Context Manager**: Maintains conversation context
4. **FSM**: Executes appropriate flow actions
5. **Message Builder**: Formats and sends responses

## Support and Resources

- **GitHub Repository**: [github.com/your-repo/whatsapp-commerce-hub](https://github.com)
- **Issue Tracker**: Report bugs and request features
- **Community Forum**: Get help from other users
- **Professional Support**: Contact us for premium support

## License

This plugin is licensed under the GPL v2 or later.

## Contributing

We welcome contributions! Please read our [Contributing Guide](../CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.

---

**Need Help?** Check our [Troubleshooting Guide](troubleshooting.md) or reach out to support.
