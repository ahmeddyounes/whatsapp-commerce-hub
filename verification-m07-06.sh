#!/bin/bash
# Verification script for M07-06

echo "=== M07-06 Verification ==="
echo ""

echo "1. Checking readme.txt exists and is valid format..."
if [ -f "readme.txt" ]; then
    echo "   ✓ readme.txt exists"
    head -1 readme.txt | grep -q "===" && echo "   ✓ Valid format (starts with ===)"
    grep -q "Contributors:" readme.txt && echo "   ✓ Has Contributors field"
    grep -q "Stable tag:" readme.txt && echo "   ✓ Has Stable tag field"
    grep -q "== Description ==" readme.txt && echo "   ✓ Has Description section"
    grep -q "== Installation ==" readme.txt && echo "   ✓ Has Installation section"
    grep -q "== Frequently Asked Questions ==" readme.txt && echo "   ✓ Has FAQ section"
    grep -q "== Screenshots ==" readme.txt && echo "   ✓ Has Screenshots section"
    grep -q "== Changelog ==" readme.txt && echo "   ✓ Has Changelog section"
else
    echo "   ✗ readme.txt not found"
fi
echo ""

echo "2. Checking WordPress.org assets directory..."
if [ -d ".wordpress-org" ]; then
    echo "   ✓ .wordpress-org directory exists"
    [ -f ".wordpress-org/README.md" ] && echo "   ✓ Assets documentation exists"
else
    echo "   ✗ .wordpress-org directory not found"
fi
echo ""

echo "3. Checking POT file..."
if [ -f "languages/whatsapp-commerce-hub.pot" ]; then
    echo "   ✓ POT file exists"
    size=$(wc -c < "languages/whatsapp-commerce-hub.pot")
    echo "   ✓ POT file size: $size bytes"
else
    echo "   ✗ POT file not found"
fi
echo ""

echo "4. Checking composer.json has make-pot script..."
if grep -q "make-pot" composer.json; then
    echo "   ✓ make-pot script added to composer.json"
else
    echo "   ✗ make-pot script not found in composer.json"
fi
echo ""

echo "5. Checking release workflow..."
if [ -f ".github/workflows/release.yml" ]; then
    echo "   ✓ GitHub release workflow exists"
    grep -q "tags:" .github/workflows/release.yml && echo "   ✓ Triggers on tags"
    grep -q "zip" .github/workflows/release.yml && echo "   ✓ Creates ZIP file"
else
    echo "   ✗ Release workflow not found"
fi
echo ""

echo "6. Checking documentation files..."
[ -f "RELEASE_CHECKLIST.md" ] && echo "   ✓ RELEASE_CHECKLIST.md exists"
[ -f "WORDPRESS_ORG_SUBMISSION.md" ] && echo "   ✓ WORDPRESS_ORG_SUBMISSION.md exists"
[ -f ".distignore" ] && echo "   ✓ .distignore exists"
echo ""

echo "7. Checking text domain usage..."
count=$(grep -r "'whatsapp-commerce-hub'" includes/ --include="*.php" 2>/dev/null | wc -l)
echo "   ✓ Text domain used $count times in includes/"
echo ""

echo "8. Checking version consistency..."
plugin_version=$(grep "Version:" whatsapp-commerce-hub.php | awk '{print $3}')
constant_version=$(grep "define.*WCH_VERSION" whatsapp-commerce-hub.php | grep -o "[0-9]\+\.[0-9]\+\.[0-9]\+")
readme_version=$(grep "Stable tag:" readme.txt | awk '{print $3}')
echo "   Plugin header version: $plugin_version"
echo "   WCH_VERSION constant: $constant_version"
echo "   readme.txt stable tag: $readme_version"
if [ "$plugin_version" = "$constant_version" ] && [ "$plugin_version" = "$readme_version" ]; then
    echo "   ✓ All versions match!"
else
    echo "   ✗ Version mismatch detected"
fi
echo ""

echo "=== Verification Complete ==="
