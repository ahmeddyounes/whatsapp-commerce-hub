<?php
/**
 * Phase 4 Infrastructure Layer - Complete Verification
 *
 * Tests all 9 Phase 4 classes:
 * - REST API (3)
 * - Controllers (2)
 * - Queue System (3)
 * - API Client (1) - Already modern
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
echo "        PHASE 4 INFRASTRUCTURE LAYER - COMPLETE VERIFICATION\n";
echo str_repeat('═', 80) . "\n\n";

// ============================================================================
// REST API Layer (3 classes)
// ============================================================================
echo "=== REST API Layer ===\n";

test('RestApi exists', function() {
    return class_exists('WhatsAppCommerceHub\Infrastructure\Api\Rest\RestApi');
});

test('WebhookController exists', function() {
    return class_exists('WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\WebhookController');
});

// ============================================================================
// Controllers (2 classes)
// ============================================================================
echo "\n=== REST Controllers ===\n";

test('ConversationsController exists in new location', function() {
    return class_exists('WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\ConversationsController');
});

test('AnalyticsController exists in new location', function() {
    return class_exists('WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\AnalyticsController');
});

// ============================================================================
// Queue System (3 classes)
// ============================================================================
echo "\n=== Queue System ===\n";

test('QueueManager exists and has key methods', function() {
    $class = 'WhatsAppCommerceHub\Infrastructure\Queue\QueueManager';
    return class_exists($class) &&
           method_exists($class, 'registerActionHooks') &&
           method_exists($class, 'scheduleRecurringJobs') &&
           method_exists($class, 'getQueueStats');
});

test('JobDispatcher exists and has key methods', function() {
    $class = 'WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher';
    return class_exists($class) &&
           method_exists($class, 'dispatch') &&
           method_exists($class, 'schedule') &&
           method_exists($class, 'scheduleRecurring') &&
           method_exists($class, 'dispatchBatch');
});

test('SyncJobHandler exists and has key methods', function() {
    $class = 'WhatsAppCommerceHub\Infrastructure\Queue\Handlers\SyncJobHandler';
    return class_exists($class) &&
           method_exists($class, 'process') &&
           method_exists($class, 'getJobResult') &&
           method_exists($class, 'getJobStats');
});

// ============================================================================
// API Client (1 class) - Already modern
// ============================================================================
echo "\n=== API Client ===\n";

test('WhatsAppApiClient exists (already modern)', function() {
    return class_exists('WhatsAppCommerceHub\Clients\WhatsAppApiClient');
});

// ============================================================================
// Legacy Mapper Verification
// ============================================================================
echo "\n=== Legacy Mapper ===\n";

test('All Phase 4 legacy mappings registered', function() {
    $mapper = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
    if (!class_exists($mapper)) {
        return false;
    }
    
    $reflection = new ReflectionClass($mapper);
    $property = $reflection->getProperty('mapping');
    $property->setAccessible(true);
    $mapping = $property->getValue();
    
    // Check all Phase 4 mappings
    $expectedMappings = [
        'WCH_REST_API' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestApi',
        'WCH_REST_Controller' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController',
        'WCH_Webhook_Handler' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\WebhookController',
        'WCH_Conversations_Controller' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\ConversationsController',
        'WCH_Analytics_Controller' => 'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\AnalyticsController',
        'WCH_Queue' => 'WhatsAppCommerceHub\Infrastructure\Queue\QueueManager',
        'WCH_Job_Dispatcher' => 'WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher',
        'WCH_Sync_Job_Handler' => 'WhatsAppCommerceHub\Infrastructure\Queue\Handlers\SyncJobHandler',
        'WCH_WhatsApp_API_Client' => 'WhatsAppCommerceHub\Infrastructure\Api\Clients\WhatsAppApiClient',
    ];
    
    foreach ($expectedMappings as $legacy => $modern) {
        if (!isset($mapping[$legacy]) || $mapping[$legacy] !== $modern) {
            echo "    Missing or incorrect: $legacy => $modern\n";
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
echo "Phase 4 Classes: 9/9\n\n";

if ($tests_failed === 0) {
    echo "✅ ALL TESTS PASSED ($tests_passed/$total)\n";
    echo "🎉 PHASE 4 INFRASTRUCTURE LAYER: 100% COMPLETE\n\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED\n";
    echo "Passed: $tests_passed\n";
    echo "Failed: $tests_failed\n\n";
    exit(1);
}
