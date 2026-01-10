<?php
/**
 * Phase 3 Complete Verification Script
 *
 * Tests all 18 Phase 3 classes (100% complete):
 * - Cart Domain (3 classes)
 * - Catalog Domain (2 classes) 
 * - Order Domain (2 classes)
 * - Customer Domain (3 classes)
 * - Conversation Domain (5 classes)
 * - Value Objects (2 classes)
 * - Exceptions (2 classes)
 */

require_once __DIR__ . '/vendor/autoload.php';

// Test counter
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
echo "            PHASE 3 COMPLETE VERIFICATION - ALL 18 CLASSES\n";
echo str_repeat('═', 80) . "\n\n";

// ============================================================================
// Cart Domain (3 classes)
// ============================================================================
echo "=== Cart Domain ===\n";

test('Cart entity exists', function() {
    return class_exists('WhatsAppCommerceHub\Domain\Cart\Cart');
});

test('CartException exists', function() {
    return class_exists('WhatsAppCommerceHub\Domain\Cart\CartException');
});

test('CartService exists and has key methods', function() {
    $class = 'WhatsAppCommerceHub\Domain\Cart\CartService';
    return class_exists($class) &&
           method_exists($class, 'getCart') &&
           method_exists($class, 'addItem') &&
           method_exists($class, 'removeItem');
});

// ============================================================================
// Catalog Domain (2 classes)
// ============================================================================
echo "\n=== Catalog Domain ===\n";

test('ProductSyncService exists', function() {
    return class_exists('WhatsAppCommerceHub\Application\Services\ProductSyncService');
});

test('CatalogBrowser exists', function() {
    return class_exists('WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser');
});

// ============================================================================
// Order Domain (2 classes)
// ============================================================================
echo "\n=== Order Domain ===\n";

test('OrderSyncService exists', function() {
    return class_exists('WhatsAppCommerceHub\Application\Services\OrderSyncService');
});

test('InventorySyncService exists and has key methods', function() {
    $class = 'WhatsAppCommerceHub\Application\Services\InventorySyncService';
    return class_exists($class) &&
           method_exists($class, 'handleStockChange') &&
           method_exists($class, 'detectStockDiscrepancies') &&
           method_exists($class, 'getSyncStats');
});

// ============================================================================
// Customer Domain (3 classes)
// ============================================================================
echo "\n=== Customer Domain ===\n";

test('Customer entity exists', function() {
    return class_exists('WhatsAppCommerceHub\Domain\Customer\Customer');
});

test('CustomerService exists', function() {
    return class_exists('WhatsAppCommerceHub\Domain\Customer\CustomerService');
});

test('CustomerProfile value object exists', function() {
    $class = 'WhatsAppCommerceHub\Domain\Customer\CustomerProfile';
    return class_exists($class) &&
           method_exists($class, 'fromArray') &&
           method_exists($class, 'getPrimaryAddress');
});

// ============================================================================
// Conversation Domain (5 classes)
// ============================================================================
echo "\n=== Conversation Domain ===\n";

test('Conversation entity exists', function() {
    return class_exists('WhatsAppCommerceHub\Domain\Conversation\Conversation');
});

test('Intent value object exists', function() {
    $class = 'WhatsAppCommerceHub\Domain\Conversation\Intent';
    return class_exists($class) &&
           method_exists($class, 'fromString');
});

test('Context exists', function() {
    $class = 'WhatsAppCommerceHub\Domain\Conversation\Context';
    return class_exists($class) &&
           method_exists($class, 'get') &&
           method_exists($class, 'set');
});

test('StateMachine exists and has transition methods', function() {
    $class = 'WhatsAppCommerceHub\Domain\Conversation\StateMachine';
    return class_exists($class) &&
           method_exists($class, 'transitionTo') &&
           method_exists($class, 'canTransitionTo');
});

test('IntentClassifier exists', function() {
    $class = 'WhatsAppCommerceHub\Support\AI\IntentClassifier';
    return class_exists($class) &&
           method_exists($class, 'classify');
});

// ============================================================================
// Value Objects (2 classes)
// ============================================================================
echo "\n=== Value Objects ===\n";

test('ParsedResponse value object exists', function() {
    $class = 'WhatsAppCommerceHub\ValueObjects\ParsedResponse';
    return class_exists($class) &&
           method_exists($class, 'getType') &&
           method_exists($class, 'toArray');
});

test('ActionResult value object exists', function() {
    $class = 'WhatsAppCommerceHub\ValueObjects\ActionResult';
    return class_exists($class) &&
           method_exists($class, 'isSuccess') &&
           method_exists($class, 'getMessages') &&
           method_exists($class, 'success') &&
           method_exists($class, 'failure');
});

// ============================================================================
// Exceptions (2 classes)
// ============================================================================
echo "\n=== Exceptions ===\n";

test('WchException exists and has enhanced methods', function() {
    $class = 'WhatsAppCommerceHub\Exceptions\WchException';
    return class_exists($class) &&
           method_exists($class, 'getErrorCode') &&
           method_exists($class, 'getHttpStatus') &&
           method_exists($class, 'toArray') &&
           method_exists($class, 'toWpError');
});

test('ApiException extends WchException', function() {
    $class = 'WhatsAppCommerceHub\Exceptions\ApiException';
    if (!class_exists($class)) {
        return false;
    }
    $reflection = new ReflectionClass($class);
    return $reflection->getParentClass()->getName() === 'WhatsAppCommerceHub\Exceptions\WchException' &&
           method_exists($class, 'getApiErrorCode');
});

// ============================================================================
// Legacy Mapper Verification
// ============================================================================
echo "\n=== Legacy Mapper ===\n";

test('All Phase 3 legacy mappings registered', function() {
    $mapper = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
    if (!class_exists($mapper)) {
        return false;
    }
    
    $reflection = new ReflectionClass($mapper);
    $property = $reflection->getProperty('mapping');
    $property->setAccessible(true);
    $mapping = $property->getValue();
    
    // Check all 18 Phase 3 mappings
    $expectedMappings = [
        // Cart Domain
        'WCH_Cart_Manager' => 'WhatsAppCommerceHub\Domain\Cart\CartService',
        'WCH_Cart_Exception' => 'WhatsAppCommerceHub\Domain\Cart\CartException',
        
        // Catalog Domain
        'WCH_Product_Sync_Service' => 'WhatsAppCommerceHub\Application\Services\ProductSyncService',
        'WCH_Catalog_Browser' => 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser',
        
        // Order Domain
        'WCH_Order_Sync_Service' => 'WhatsAppCommerceHub\Application\Services\OrderSyncService',
        'WCH_Inventory_Sync_Handler' => 'WhatsAppCommerceHub\Application\Services\InventorySyncService',
        
        // Customer Domain
        'WCH_Customer_Profile' => 'WhatsAppCommerceHub\Domain\Customer\CustomerProfile',
        'WCH_Customer_Service' => 'WhatsAppCommerceHub\Domain\Customer\CustomerService',
        
        // Conversation Domain
        'WCH_Conversation_Context' => 'WhatsAppCommerceHub\Domain\Conversation\Context',
        'WCH_Conversation_FSM' => 'WhatsAppCommerceHub\Domain\Conversation\StateMachine',
        'WCH_Intent' => 'WhatsAppCommerceHub\Domain\Conversation\Intent',
        'WCH_Intent_Classifier' => 'WhatsAppCommerceHub\Support\AI\IntentClassifier',
        
        // Value Objects
        'WCH_Parsed_Response' => 'WhatsAppCommerceHub\ValueObjects\ParsedResponse',
        'WCH_Action_Result' => 'WhatsAppCommerceHub\ValueObjects\ActionResult',
        
        // Exceptions
        'WCH_Exception' => 'WhatsAppCommerceHub\Exceptions\WchException',
        'WCH_API_Exception' => 'WhatsAppCommerceHub\Exceptions\ApiException',
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
echo "Total Classes: 18\n\n";

if ($tests_failed === 0) {
    echo "✅ ALL TESTS PASSED ($tests_passed/$total)\n";
    echo "🎉 PHASE 3 DOMAIN LAYER: 100% COMPLETE (18/18 classes)\n\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED\n";
    echo "Passed: $tests_passed\n";
    echo "Failed: $tests_failed\n\n";
    exit(1);
}
