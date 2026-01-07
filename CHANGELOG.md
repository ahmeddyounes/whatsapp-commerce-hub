# Changelog

All notable changes to WhatsApp Commerce Hub will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Multi-language conversation support
- Voice message handling
- Video catalog support
- Advanced analytics with cohort analysis
- A/B testing for message templates
- Customer segmentation improvements

## [1.0.0] - 2024-01-15

### Added
- **Core Features**
  - Complete WhatsApp Business API integration
  - Conversation State Machine (FSM) for managing customer interactions
  - AI-powered intent classification using OpenAI
  - Natural language understanding for customer queries
  - Bidirectional product catalog synchronization with WooCommerce
  - Real-time inventory management and sync
  - Full shopping cart functionality within WhatsApp conversations

- **Payment Gateways**
  - Cash on Delivery (COD) support
  - Stripe integration for card payments
  - Razorpay integration for Indian market
  - WhatsApp Pay integration
  - PIX payment support for Brazil
  - Extensible payment gateway architecture

- **Admin Dashboard**
  - Conversation inbox with real-time updates
  - Analytics dashboard with key metrics
  - Product catalog sync management
  - Broadcast campaign creator
  - Job queue monitoring
  - Comprehensive logging system
  - Template management interface
  - Settings management with encryption for sensitive data

- **Automation**
  - Abandoned cart recovery with multi-sequence campaigns
  - Customer re-engagement system
  - Automated order status notifications
  - Scheduled product sync jobs
  - Background job processing with Action Scheduler

- **Developer Features**
  - Comprehensive REST API with authentication
  - 50+ WordPress hooks for customization
  - Extensible architecture for custom intents
  - Payment gateway interface for custom gateways
  - Custom FSM state support
  - Detailed logging and debugging tools

- **Documentation**
  - Complete installation guide
  - Configuration reference
  - API documentation with OpenAPI spec
  - Hooks reference guide
  - Extension development guide
  - Troubleshooting guide
  - PHPDoc coverage for all public APIs

### Security
- API credentials encrypted in database
- Webhook signature validation
- Rate limiting on all endpoints
- SQL injection prevention
- XSS protection on all outputs
- Secure token generation and validation

### Performance
- Object caching support
- Database query optimization
- Async webhook processing
- Lazy loading of admin assets
- Efficient batch processing for syncs

## [0.9.0] - 2024-01-08 (Beta)

### Added
- Beta testing release
- Core conversation engine
- Basic product browsing
- Simple checkout flow
- COD payment only
- Basic admin interface

### Fixed
- Memory leaks in long conversations
- Race conditions in cart management
- Inventory sync timing issues

### Known Issues
- Message delivery delays under high load (fixed in 1.0.0)
- Limited error recovery in sync jobs (fixed in 1.0.0)

## [0.8.0] - 2024-01-01 (Alpha)

### Added
- Alpha testing release
- Proof of concept implementation
- WhatsApp webhook handling
- Basic message parsing
- Product display in messages
- Simple cart functionality

### Known Issues
- No persistent conversation state
- Limited error handling
- Manual product sync required
- No payment integration

## Version History

### Version Numbering

We use Semantic Versioning (MAJOR.MINOR.PATCH):
- **MAJOR**: Incompatible API changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

### Release Schedule

- **Major releases**: Quarterly (Q1, Q2, Q3, Q4)
- **Minor releases**: Monthly
- **Patch releases**: As needed for critical bugs

### Upgrade Notes

#### Upgrading to 1.0.0 from Beta

1. **Backup Database**: Always backup before upgrading
2. **Update Dependencies**: Ensure PHP 8.1+, WordPress 6.0+, WooCommerce 8.0+
3. **Run Migrations**: Database migrations run automatically
4. **Clear Caches**: Clear all caches after upgrade
5. **Test Webhooks**: Verify webhook still receiving messages
6. **Check API Keys**: Verify all API credentials still valid

```bash
# Recommended upgrade procedure
wp db export backup-before-upgrade.sql
wp plugin update whatsapp-commerce-hub
wp cache flush
```

### Deprecation Policy

Features marked as deprecated will be supported for at least 2 major versions before removal.

**Currently Deprecated**: None

### Breaking Changes

#### 1.0.0
None (initial stable release)

### Migration Guides

#### Beta to 1.0.0

**Database Schema Changes**:
- Added `wch_recovery_campaigns` table
- Added `wch_reengagement_tracking` table
- Modified `wch_conversations` table: added `metadata` column
- Modified `wch_carts` table: added `recovery_sent_at` column

Migrations run automatically on upgrade. No manual intervention required.

**API Changes**:
- None (new API in this version)

**Hook Changes**:
- None (hooks formalized in this version)

## Support

### Getting Help

- **Documentation**: [docs](docs/README.md)
- **GitHub Issues**: [Issues](https://github.com/your-repo/issues)
- **Community Forum**: [Forum](https://community.example.com)
- **Email Support**: support@example.com

### Reporting Bugs

When reporting bugs, please include:
1. Plugin version (check in WordPress → Plugins)
2. PHP version (`php -v`)
3. WordPress version
4. WooCommerce version
5. Error messages (from logs or screen)
6. Steps to reproduce
7. Expected vs actual behavior

### Feature Requests

Submit feature requests via GitHub Issues with:
1. Clear use case description
2. Expected behavior
3. How it benefits users
4. Any relevant examples or mockups

---

**Legend**:
- `Added` for new features
- `Changed` for changes in existing functionality
- `Deprecated` for soon-to-be removed features
- `Removed` for now removed features
- `Fixed` for any bug fixes
- `Security` for vulnerability fixes

[Unreleased]: https://github.com/your-repo/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/your-repo/releases/tag/v1.0.0
[0.9.0]: https://github.com/your-repo/releases/tag/v0.9.0
[0.8.0]: https://github.com/your-repo/releases/tag/v0.8.0
