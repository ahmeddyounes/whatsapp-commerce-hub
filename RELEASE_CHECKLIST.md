# WhatsApp Commerce Hub - Release Checklist

Use this checklist before releasing a new version to WordPress.org or creating a GitHub release.

## Pre-Release Checklist

### Version Numbers
- [ ] Update version in `whatsapp-commerce-hub.php` header (Plugin Version)
- [ ] Update `WCH_VERSION` constant in `whatsapp-commerce-hub.php`
- [ ] Update `Stable tag` in `readme.txt`
- [ ] Update `Tested up to` WordPress version in `readme.txt` (if applicable)
- [ ] Update `Requires at least` versions if dependencies changed
- [ ] Update `Requires PHP` if minimum PHP version changed
- [ ] Update `WC requires at least` if WooCommerce requirements changed

### Changelog & Documentation
- [ ] Update `CHANGELOG.md` with all changes since last release
- [ ] Update `Changelog` section in `readme.txt`
- [ ] Add `Upgrade Notice` section in `readme.txt` for breaking changes
- [ ] Review and update `README.md` if needed
- [ ] Update documentation in `/docs` directory
- [ ] Review inline code documentation and PHPDoc blocks

### Translations
- [ ] Regenerate POT file: `composer run make-pot`
- [ ] Verify all new strings are translatable
- [ ] Check for hardcoded strings that should be translatable
- [ ] Test with a translation file (if available)
- [ ] Verify text domain is correct: `whatsapp-commerce-hub`

### Code Quality
- [ ] Run linter: `composer run lint`
- [ ] Fix any linting errors: `composer run lint:fix`
- [ ] Run static analysis: `composer run analyze`
- [ ] Address any critical PHPStan errors
- [ ] Verify PHPCS baseline is up to date

### Testing
- [ ] Run all unit tests: `composer run test:unit`
- [ ] Run integration tests: `composer run test:integration`
- [ ] Run full test suite: `composer run test`
- [ ] All tests must pass (0 failures, 0 errors)
- [ ] Manual testing of core features:
  - [ ] Product catalog sync
  - [ ] Order creation via WhatsApp
  - [ ] Payment processing (all configured gateways)
  - [ ] Abandoned cart recovery
  - [ ] Message template rendering
  - [ ] Webhook receiving and processing
  - [ ] Admin dashboard functionality
  - [ ] Settings save and load correctly

### Security Review
- [ ] Review all user input validation
- [ ] Check SQL queries for injection vulnerabilities
- [ ] Verify all output is properly escaped
- [ ] Review file upload handling (if applicable)
- [ ] Check for XSS vulnerabilities
- [ ] Verify nonce usage on all forms and AJAX calls
- [ ] Review capability checks for admin functions
- [ ] Check for CSRF protection
- [ ] Scan with security tools (if available)
- [ ] Review third-party dependency updates

### Performance
- [ ] Profile database queries (no N+1 queries)
- [ ] Check for memory leaks in long-running processes
- [ ] Verify caching is working correctly
- [ ] Test with large product catalogs (1000+ products)
- [ ] Test background job queue performance
- [ ] Check asset file sizes (CSS/JS)
- [ ] Verify lazy loading is implemented where appropriate

### WordPress.org Submission
- [ ] Validate `readme.txt` with [WordPress Plugin Readme Validator](https://wordpress.org/plugins/developers/readme-validator/)
- [ ] All sections in readme.txt are complete
- [ ] FAQ has at least 10 questions
- [ ] Screenshots are described in readme.txt
- [ ] Create/update plugin assets in `.wordpress-org/`:
  - [ ] banner-772x250.png
  - [ ] banner-1544x500.png
  - [ ] icon-128x128.png
  - [ ] icon-256x256.png
  - [ ] icon.svg (optional)
  - [ ] screenshot-1.png through screenshot-N.png
- [ ] Assets meet WordPress.org dimension requirements
- [ ] Test plugin installation from ZIP file
- [ ] Verify plugin activates without errors
- [ ] Verify plugin deactivates cleanly

### WordPress.org Review Guidelines
Review against [WordPress Plugin Review Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/):

- [ ] No obfuscated code
- [ ] No phone-home or tracking without user consent
- [ ] No affiliate links in plugin code
- [ ] Trademarks are respected (WhatsApp trademark usage is appropriate)
- [ ] Licensing is clear (GPL v2 or later)
- [ ] No externally hosted code or libraries
- [ ] All third-party libraries are compatible with GPL
- [ ] Service integrations are clearly documented
- [ ] Admin notices are dismissible and not excessive
- [ ] Settings page is professional and clear

### GitHub Release
- [ ] All changes committed to git
- [ ] Branch is up to date with remote
- [ ] Create git tag: `git tag -a v1.0.0 -m "Version 1.0.0"`
- [ ] Push tag: `git push origin v1.0.0`
- [ ] GitHub Actions workflow completes successfully
- [ ] Review generated release on GitHub
- [ ] Download and test release ZIP file
- [ ] Release notes are accurate and complete

### Post-Release
- [ ] Monitor error logs for new issues
- [ ] Watch support forums for user reports
- [ ] Monitor plugin repository reviews
- [ ] Update documentation site (if applicable)
- [ ] Announce release on relevant channels
- [ ] Monitor GitHub issues for bug reports

## WordPress.org SVN Deployment

After GitHub release is tested and ready:

```bash
# Checkout SVN repository
svn co https://plugins.svn.wordpress.org/whatsapp-commerce-hub svn

# Copy plugin files to trunk
cd svn
rm -rf trunk/*
cp -r /path/to/plugin/* trunk/
cd trunk

# Remove development files
rm -rf .git .github tests bin phpunit.xml.dist phpcs.xml.dist phpstan.neon composer.json composer.lock .editorconfig
rm -rf node_modules vendor/.git*

# Add new files
svn add --force * --auto-props --parents --depth infinity -q

# Remove deleted files
svn status | grep '^!' | awk '{print $2}' | xargs svn delete

# Commit to trunk
svn ci -m "Update to version 1.0.0"

# Create tag from trunk
cd ..
svn cp trunk tags/1.0.0
svn ci -m "Tagging version 1.0.0"

# Update assets (if changed)
cd assets
svn add *.png *.jpg *.svg
svn ci -m "Update plugin assets"
```

## Rollback Plan

If critical issues are discovered post-release:

1. [ ] Identify the issue and create hotfix branch
2. [ ] Fix the issue and test thoroughly
3. [ ] Increment version number (patch version)
4. [ ] Follow release checklist for hotfix release
5. [ ] Deploy hotfix as quickly as possible

Or if rollback is needed:

1. [ ] In WordPress.org SVN, change `Stable tag` in trunk/readme.txt to previous version
2. [ ] Commit: `svn ci -m "Rollback to version X.X.X due to critical issue"`
3. [ ] Users will be served the previous version
4. [ ] Fix issue in separate branch before re-releasing

## Version Numbering

Follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (X.0.0): Breaking changes, incompatible API changes
- **MINOR** (1.X.0): New features, backwards compatible
- **PATCH** (1.0.X): Bug fixes, backwards compatible

## Release Frequency

- **Major releases**: As needed for significant features or breaking changes
- **Minor releases**: Monthly or as features are completed
- **Patch releases**: As needed for bug fixes and security updates

## Support Policy

- **Current major version**: Full support, bug fixes, and security updates
- **Previous major version**: Security updates only for 6 months
- **Older versions**: No support, users encouraged to upgrade

## Emergency Security Releases

For critical security vulnerabilities:

1. Fix the issue immediately in a private branch
2. Test the fix thoroughly
3. Create patch release incrementing patch version
4. Release as quickly as possible (may skip some checklist items)
5. Coordinate with WordPress.org security team if needed
6. Publish security advisory after release
7. Notify affected users through WordPress.org

## Notes

- Keep this checklist updated as release process evolves
- Document any deviations from checklist with justification
- Use issue tracker to manage release tasks
- Consider automating parts of this checklist in CI/CD pipeline
