#!/bin/bash
# Verification script for C03-04: Ban wch() usage in providers
# This script verifies that providers don't use wch() and that the PHPStan rule prevents it

set -e

echo "========================================"
echo "C03-04 Verification Script"
echo "========================================"
echo ""

# 1. Check that no provider files contain wch() calls
echo "1. Checking for wch() usage in provider files..."
if grep -r "wch(" includes/Providers/*.php 2>/dev/null; then
    echo "❌ FAILED: Found wch() usage in provider files"
    exit 1
else
    echo "✓ PASSED: No wch() usage found in provider files"
fi
echo ""

# 2. Verify PHPStan rule exists
echo "2. Checking PHPStan rule exists..."
if [ ! -f "phpstan-rules/NoWchCallsInProvidersRule.php" ]; then
    echo "❌ FAILED: PHPStan rule file not found"
    exit 1
else
    echo "✓ PASSED: PHPStan rule file exists"
fi
echo ""

# 3. Verify PHPStan configuration includes the rule
echo "3. Checking PHPStan configuration..."
if ! grep -q "NoWchCallsInProvidersRule" phpstan.neon; then
    echo "❌ FAILED: PHPStan rule not configured in phpstan.neon"
    exit 1
else
    echo "✓ PASSED: PHPStan rule is configured"
fi
echo ""

# 4. Create a test file with wch() and verify it's caught
echo "4. Testing PHPStan rule enforcement..."
cat > includes/Providers/TestWchProvider.php << 'EOF'
<?php
declare(strict_types=1);
namespace WhatsAppCommerceHub\Providers;
use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
class TestWchProvider implements ServiceProviderInterface {
    public function register( ContainerInterface $container ): void {
        $container->singleton('test', static function() {
            return wch('something'); // Should trigger error
        });
    }
    public function boot( ContainerInterface $container ): void {}
    public function provides(): array { return []; }
    public function dependsOn(): array { return []; }
}
EOF

# Run PHPStan on the test file
if vendor/bin/phpstan analyze includes/Providers/TestWchProvider.php --memory-limit=1G 2>&1 | grep -q "Service providers must not call wch()"; then
    echo "✓ PASSED: PHPStan rule correctly detects wch() usage"
else
    echo "❌ FAILED: PHPStan rule did not detect wch() usage"
    rm includes/Providers/TestWchProvider.php
    exit 1
fi

# Clean up test file
rm includes/Providers/TestWchProvider.php
echo ""

# 5. Verify documentation exists
echo "5. Checking documentation..."
if [ ! -f "includes/Providers/README.md" ]; then
    echo "❌ FAILED: Providers README.md not found"
    exit 1
elif ! grep -q "wch()" includes/Providers/README.md; then
    echo "❌ FAILED: README.md doesn't document wch() ban"
    exit 1
else
    echo "✓ PASSED: Documentation exists and mentions wch() ban"
fi
echo ""

echo "========================================"
echo "✓ All verification tests passed!"
echo "========================================"
