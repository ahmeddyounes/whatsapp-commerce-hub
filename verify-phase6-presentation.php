<?php
/**
 * Phase 6 Verification Script - Presentation Layer
 *
 * Tests all Presentation layer classes including:
 * - Actions (9 classes)
 * - Admin Pages (8 classes)
 * - Admin Widgets (1 class)
 * - Template Manager (1 class - TBD)
 *
 * @package WhatsApp_Commerce_Hub
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Color output helpers
function green(string $text): string {
    return "\033[32m{$text}\033[0m";
}

function red(string $text): string {
    return "\033[31m{$text}\033[0m";
}

function yellow(string $text): string {
    return "\033[33m{$text}\033[0m";
}

function bold(string $text): string {
    return "\033[1m{$text}\033[0m";
}

// Test results tracking
$passed = 0;
$failed = 0;
$results = [];

/**
 * Test that a modern class exists and is loadable
 */
function testClassExists(string $className, string $description): bool {
    global $passed, $failed, $results;
    
    $result = class_exists($className);
    
    if ($result) {
        $passed++;
        $results[] = green("✓") . " {$description}";
    } else {
        $failed++;
        $results[] = red("✗") . " {$description}";
    }
    
    return $result;
}

/**
 * Test that a legacy class alias works through LegacyClassMapper
 */
function testLegacyAlias(string $legacyClass, string $modernClass, string $description): bool {
    global $passed, $failed, $results;
    
    // Check if legacy class is aliased
    $result = class_exists($legacyClass);
    
    if ($result) {
        $passed++;
        $results[] = green("✓") . " {$description}";
    } else {
        $failed++;
        $results[] = red("✗") . " {$description}";
    }
    
    return $result;
}

echo bold("\n╔═══════════════════════════════════════════════════════════════╗\n");
echo bold("║        Phase 6: Presentation Layer Verification               ║\n");
echo bold("╚═══════════════════════════════════════════════════════════════╝\n\n");

// =============================================================================
// ACTIONS (9 classes)
// =============================================================================

echo bold("Actions Layer (9 classes)\n");
echo str_repeat("─", 70) . "\n";

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\AbstractAction',
    'AbstractAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\AddToCartAction',
    'AddToCartAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\ShowCartAction',
    'ShowCartAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\RemoveFromCartAction',
    'RemoveFromCartAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\CheckoutAction',
    'CheckoutAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\ClearCartAction',
    'ClearCartAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\ShowProductAction',
    'ShowProductAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\BrowseCatalogAction',
    'BrowseCatalogAction class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Actions\HelpAction',
    'HelpAction class exists'
);

// =============================================================================
// ADMIN PAGES (8 classes)
// =============================================================================

echo "\n" . bold("Admin Pages (8 classes)\n");
echo str_repeat("─", 70) . "\n";

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\AnalyticsPage',
    'AnalyticsPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\BroadcastsPage',
    'BroadcastsPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\CatalogSyncPage',
    'CatalogSyncPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\InboxPage',
    'InboxPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\JobsPage',
    'JobsPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\LogsPage',
    'LogsPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\SettingsPage',
    'SettingsPage class exists'
);

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Pages\TemplatesPage',
    'TemplatesPage class exists'
);

// =============================================================================
// ADMIN WIDGETS (1 class)
// =============================================================================

echo "\n" . bold("Admin Widgets (1 class)\n");
echo str_repeat("─", 70) . "\n";

testClassExists(
    'WhatsAppCommerceHub\Presentation\Admin\Widgets\DashboardWidgets',
    'DashboardWidgets class exists'
);

// =============================================================================
// SUMMARY
// =============================================================================

echo "\n" . bold("═══════════════════════════════════════════════════════════════\n");
echo bold("Test Results Summary\n");
echo bold("═══════════════════════════════════════════════════════════════\n\n");

foreach ($results as $result) {
    echo $result . "\n";
}

echo "\n" . str_repeat("─", 70) . "\n";

$total = $passed + $failed;
$percentage = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo bold("\nTotal Tests: {$total}\n");
echo green("Passed: {$passed}\n");
if ($failed > 0) {
    echo red("Failed: {$failed}\n");
} else {
    echo "Failed: {$failed}\n";
}
echo bold("Success Rate: {$percentage}%\n");

if ($failed === 0) {
    echo "\n" . green(bold("✓ All Phase 6 tests passed!\n"));
    echo green("✓ Presentation layer migration successful\n\n");
    exit(0);
} else {
    echo "\n" . red(bold("✗ Some tests failed\n"));
    echo red("✗ Please review the failures above\n\n");
    exit(1);
}
