<?php
/**
 * Phase 4 Infrastructure Layer Verification Script
 *
 * Tests REST API and related infrastructure classes (partial - 3 of 9 classes)
 */

require_once __DIR__ . '/vendor/autoload.php';

$tests_passed = 0;
$tests_failed = 0;

function test($description, $callback) {
    global $tests_passed, $tests_failed;
    
    try {
        $result = $callback();
        if ($result) {
            echo "✓ $description\n";
            $tests_passed++;
        } else {
            echo "✗ $description\n";
            $tests_failed++;
        }
    } catch (Exception $e) {
        echo "✗ $description - Exception: {$e->getMessage()}\n";
        $tests_failed++;
    }
}

echo "\n";
echo str_repeat('═', 80) . "\n";
echo "          PHASE 4 INFRASTRUCTURE LAYER - PARTIAL VERIFICATION\n";
echo str_repeat('═', 80) . "\n\n";

// ============================================================================
// REST API Classes (3/4 completed this session)
// ============================================================================
echo "=== REST API Layer ===\n";

test('RestApi exists and has key methods', function() {
    $class = 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestApi';
    return class_exists($class) &&
           method_exists($class, 'registerRoutes') &&
           method_exists($class, 'getApiInfo') &&
           defined("$class::NAMESPACE");
});

test('RestController exists as abstract base', function() {
    $class = 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController';
    if (!class_exists($class)) {
        return false;
    }
    
    $reflection = new ReflectionClass($class);
    return $reflection->isAbstract() &&
           method_exists($class, 'checkAdminPermission') &&
           method_exists($class, 'validatePhone') &&
           method_exists($class, 'checkRateLimit');
});

test('WebhookController exists and extends RestController', function() {
    $class = 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\WebhookController';
    if (!class_exists($class)) {
        return false;
    }
    
    $reflection = new ReflectionClass($class);
    $parent = $reflection->getParentClass();
    
    return $parent && $parent->getName() === 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController' &&
           method_exists($class, 'verifyWebhook') &&
           method_exists($class, 'handleWebhook');
});

// ============================================================================
// Existing Modern Controllers
// ============================================================================
echo "\n=== Existing Modern Controllers ===\n";

test('ConversationsController exists (already modern)', function() {
    return class_exists('WhatsAppCommerceHub\Controllers\ConversationsController');
});

test('AnalyticsController exists (already modern)', function() {
    return class_exists('WhatsAppCommerceHub\Controllers\AnalyticsController');
});

// ============================================================================
// Legacy Mapper Verification
// ============================================================================
echo "\n=== Legacy Mapper ===\n";

test('Phase 4 REST API mappings registered', function() {
    $mapper = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
    if (!class_exists($mapper)) {
        return false;
    }
    
    $reflection = new ReflectionClass($mapper);
    $property = $reflection->getProperty('mapping');
    $property->setAccessible(true);
    $mapping = $property->getValue();
    
    // Check REST API mappings
    $expectedMappings = [
        'WCH_REST_API' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestApi',
        'WCH_REST_Controller' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController',
        'WCH_Webhook_Handler' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\WebhookController',
    ];
    
    foreach ($expectedMappings as $legacy => $modern) {
        if (!isset($mapping[$legacy]) || $mapping[$legacy] !== $modern) {
            echo "    Missing or incorrect: $legacy\n";
            return false;
        }
    }
    
    return true;
});

// ============================================================================
// Summary
// ============================================================================
echo "\n";
echo str_repeat('═', 80) . "\n";
echo "                                SUMMARY\n";
echo str_repeat('═', 80) . "\n\n";

$total = $tests_passed + $tests_failed;
echo "Phase 4 Progress: 3/9 classes (REST API layer)\n";
echo "Remaining: Queue System (3), Controllers move (2), WhatsApp API Client (1)\n\n";

if ($tests_failed === 0) {
    echo "✅ ALL TESTS PASSED ($tests_passed/$total)\n";
    echo "🎉 PHASE 4 REST API LAYER: COMPLETE\n";
    echo "⏳ Queue System and Controllers remain\n\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED\n";
    echo "Passed: $tests_passed\n";
    echo "Failed: $tests_failed\n\n";
    exit(1);
}
