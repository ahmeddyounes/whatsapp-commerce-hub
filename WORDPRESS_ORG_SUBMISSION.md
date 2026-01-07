# WordPress.org Plugin Submission Guide

This guide walks through the process of submitting WhatsApp Commerce Hub to the WordPress.org plugin directory.

## Prerequisites

Before submitting:

1. **Plugin is ready for release**
   - All features are complete and tested
   - Code passes quality checks (linting, static analysis)
   - All tests pass
   - Security review completed
   - Performance testing done

2. **Documentation is complete**
   - readme.txt is properly formatted and validated
   - All sections filled out completely
   - Screenshots are described
   - FAQ has at least 10 questions

3. **Assets are prepared**
   - Banners (772x250px and 1544x500px)
   - Icons (128x128px and 256x256px)
   - Screenshots (numbered, high quality)
   - All assets in `.wordpress-org/` directory

4. **Legal requirements met**
   - Plugin is GPL v2 or later compatible
   - All third-party code is GPL compatible
   - No trademark violations
   - Privacy policy addresses WhatsApp integration

## Step 1: Validate readme.txt

Before submission, validate your readme.txt:

1. Visit [WordPress Plugin Readme Validator](https://wordpress.org/plugins/developers/readme-validator/)
2. Paste contents of `readme.txt`
3. Fix any errors or warnings
4. Ensure all required sections are present

## Step 2: Create WordPress.org Account

If you don't have one:

1. Go to [WordPress.org](https://wordpress.org)
2. Click "Get WordPress" → "Log In" → "Register"
3. Complete registration
4. Verify your email address

## Step 3: Submit Plugin for Review

1. Go to [Add Your Plugin](https://wordpress.org/plugins/developers/add/)
2. Log in with your WordPress.org account
3. Read and agree to the guidelines
4. Upload your plugin ZIP file (production build without dev files)
5. Submit for review

### What to Include in ZIP

Create a clean ZIP file:

```bash
# Use the release workflow or manually:
composer install --no-dev --optimize-autoloader
zip -r whatsapp-commerce-hub.zip . -x \
  "*.git*" \
  "node_modules/*" \
  "tests/*" \
  "bin/*" \
  ".wordpress-org/*" \
  "*.md" \
  "composer.json" \
  "composer.lock" \
  "phpunit.xml.dist" \
  "phpcs.xml.dist" \
  "phpstan.neon" \
  "test-*.php" \
  "verify-*.php"
```

## Step 4: Wait for Review

- **Review time**: Typically 1-14 days
- **Email notification**: You'll receive an email when review is complete
- **Review repo created**: If approved, you'll get SVN access

Common reasons for rejection:
- Security vulnerabilities
- Using non-GPL compatible code
- Guideline violations
- Trademark issues
- Poor code quality
- Missing functionality described in readme

## Step 5: Access SVN Repository

Once approved, you'll receive:
- SVN repository URL: `https://plugins.svn.wordpress.org/whatsapp-commerce-hub`
- Commit access for your WordPress.org account

## Step 6: Initial SVN Commit

### Checkout Repository

```bash
svn co https://plugins.svn.wordpress.org/whatsapp-commerce-hub svn
cd svn
```

### Directory Structure

```
whatsapp-commerce-hub/
├── trunk/          # Development version (latest code)
├── tags/           # Released versions
│   ├── 1.0.0/
│   ├── 1.0.1/
│   └── ...
└── assets/         # Plugin directory assets (banners, icons, screenshots)
```

### Add Plugin Files to Trunk

```bash
# Copy plugin files to trunk
cp -r /path/to/plugin/* trunk/

# Remove development files
cd trunk
rm -rf .git .github tests bin node_modules
rm phpunit.xml.dist phpcs.xml.dist phpstan.neon composer.json composer.lock
rm test-*.php verify-*.php

# Add files to SVN
svn add --force * --auto-props --parents --depth infinity -q

# Commit to trunk
svn ci -m "Initial commit of WhatsApp Commerce Hub"
```

### Add Assets

```bash
# From repository root
cd assets
cp ../.wordpress-org/*.png .
cp ../.wordpress-org/*.jpg .
svn add *.png *.jpg
svn ci -m "Add plugin assets"
```

### Create First Tag

```bash
# From repository root
cd ..
svn cp trunk tags/1.0.0
svn ci -m "Tagging version 1.0.0"
```

## Step 7: Plugin Goes Live

After your first tag is created:
- Plugin appears on WordPress.org within minutes
- Available in WordPress admin plugin installer
- Listed in plugin directory search

## Updating the Plugin

### For Each New Release

1. **Update trunk**
   ```bash
   svn up
   cd trunk
   # Copy new files
   svn status  # Review changes
   svn add <new-files>
   svn delete <removed-files>
   svn ci -m "Update to version 1.1.0"
   ```

2. **Create new tag**
   ```bash
   cd ..
   svn cp trunk tags/1.1.0
   svn ci -m "Tagging version 1.1.0"
   ```

3. **Update assets (if changed)**
   ```bash
   cd assets
   # Copy new assets
   svn add <new-assets>
   svn ci -m "Update plugin assets"
   ```

### Automated Deployment

Use the GitHub Actions workflow in `.github/workflows/release.yml`:

1. Set repository secrets:
   - `WP_ORG_SVN_USERNAME`: Your WordPress.org username
   - `WP_ORG_SVN_PASSWORD`: Your WordPress.org password (or app-specific password)

2. Create and push a git tag:
   ```bash
   git tag -a v1.1.0 -m "Version 1.1.0"
   git push origin v1.1.0
   ```

3. GitHub Actions will automatically:
   - Build production ZIP
   - Create GitHub release
   - Deploy to WordPress.org SVN (if secrets are set)

## Troubleshooting

### Plugin Not Showing in Directory

- Check `Stable tag` in trunk/readme.txt matches a tag
- Ensure tag exists in `/tags/` directory
- Wait 15-30 minutes for cache to clear

### SVN Authentication Failed

- Verify username and password
- Check you have commit access to the plugin
- Try with app-specific password if using 2FA

### Conflicts During SVN Update

```bash
# Accept their version
svn resolve --accept theirs-full <file>

# Or accept your version
svn resolve --accept mine-full <file>
```

### Rollback a Release

Change `Stable tag` in trunk/readme.txt to previous version:

```bash
cd trunk
# Edit readme.txt, change Stable tag to previous version
svn ci -m "Rollback to version 1.0.0 due to critical bug"
```

## Best Practices

1. **Test thoroughly before tagging**
   - Never release broken code to WordPress.org
   - Test on clean WordPress install
   - Test with common plugins/themes

2. **Keep trunk stable**
   - Only commit working code to trunk
   - Use branches for experimental features
   - Trunk should always be releasable

3. **Write good commit messages**
   - Describe what changed
   - Reference issue numbers if applicable
   - Be concise but informative

4. **Respond to support requests**
   - Monitor WordPress.org support forums
   - Respond within 48 hours
   - Be professional and helpful

5. **Update regularly**
   - Keep up with WordPress/WooCommerce updates
   - Fix bugs promptly
   - Release security updates immediately

6. **Respect guidelines**
   - Follow WordPress coding standards
   - Don't abuse admin notices
   - Respect user data and privacy

## Plugin Statistics

After going live, you can track:
- Active installations
- Download counts
- Ratings and reviews
- Support requests

Access at: `https://wordpress.org/plugins/whatsapp-commerce-hub/`

## Support Forums

Your plugin gets a support forum automatically:
- URL: `https://wordpress.org/support/plugin/whatsapp-commerce-hub/`
- Monitor for new threads
- Mark resolved threads as resolved
- Be respectful and helpful

## Resources

- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Plugin Review Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Readme Validator](https://wordpress.org/plugins/developers/readme-validator/)
- [SVN Primer](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [Plugin Assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)

## Getting Help

If you have questions:
- [Plugin Review Team](https://make.wordpress.org/plugins/)
- [#pluginreview Slack channel](https://wordpress.slack.com/)
- [Make WordPress Plugins](https://make.wordpress.org/plugins/)
