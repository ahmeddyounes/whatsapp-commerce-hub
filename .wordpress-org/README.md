# WordPress.org Assets

This directory contains assets for the WordPress.org plugin repository.

## Required Assets

### Banners

- **banner-772x250.png** - Low resolution banner (772x250px)
- **banner-1544x500.png** - High resolution banner (1544x500px)

These banners appear at the top of your plugin page on WordPress.org.

### Icons

- **icon-128x128.png** - Low resolution icon (128x128px)
- **icon-256x256.png** - High resolution icon (256x256px)
- **icon.svg** - Vector icon (optional, recommended)

These icons appear in search results and the plugin directory.

### Screenshots

Place numbered screenshots that correspond to the descriptions in readme.txt:

- **screenshot-1.png** - Dashboard Overview
- **screenshot-2.png** - Settings Page
- **screenshot-3.png** - Inbox Interface
- **screenshot-4.png** - Conversation View
- **screenshot-5.png** - Catalog Sync
- **screenshot-6.png** - Message Templates
- **screenshot-7.png** - Analytics Dashboard
- **screenshot-8.png** - Payment Settings
- **screenshot-9.png** - Abandoned Cart Recovery
- **screenshot-10.png** - Broadcast Messaging

## Image Specifications

### Banners
- Format: PNG or JPG
- Dimensions: Exactly 772x250px (low-res) and 1544x500px (high-res)
- File size: Under 1MB recommended
- Background: Should work well with WordPress.org's design

### Icons
- Format: PNG or SVG
- Dimensions: Exactly 128x128px and 256x256px for PNG
- Transparent background recommended
- File size: Under 256KB

### Screenshots
- Format: PNG or JPG
- Max width: 1280px (will be scaled to fit)
- Aspect ratio: 4:3 or 16:9 recommended
- File size: Under 1MB per screenshot
- Show actual plugin interface, not mockups

## Design Guidelines

1. **Brand Consistency**: Use colors and fonts consistent with the plugin branding
2. **Professional Quality**: Use high-quality, non-pixelated images
3. **Clear Focus**: Ensure text and UI elements are clearly visible
4. **White Space**: Don't overcrowd banners with too much text
5. **Call to Action**: Banners should be inviting and communicate the plugin's value

## How to Update Assets

When you have the actual assets ready:

1. Replace placeholder files with your designed assets
2. Ensure all dimensions match exactly
3. Optimize images for web (use tools like ImageOptim, TinyPNG)
4. Test on different backgrounds (light/dark)
5. Upload to WordPress.org SVN repository's `/assets` directory

## SVN Upload Commands

```bash
# Checkout the SVN repository
svn co https://plugins.svn.wordpress.org/whatsapp-commerce-hub

# Navigate to assets directory
cd whatsapp-commerce-hub/assets

# Add your asset files
svn add banner-772x250.png
svn add banner-1544x500.png
svn add icon-128x128.png
svn add icon-256x256.png
svn add screenshot-*.png

# Commit the assets
svn ci -m "Add plugin assets"
```

## Notes

- Assets are stored separately from plugin releases
- You can update assets without releasing a new version
- Assets should be uploaded before your plugin is approved
- Make sure you have rights to use all images (no copyright violations)
