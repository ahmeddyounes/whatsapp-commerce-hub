<?php
/**
 * Phase 3 Final Verification
 *
 * Verifies ALL Phase 3 domain migrations including CustomerProfile.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once __DIR__ . '/vendor/autoload.php';
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }

function green( $t ) { return "\033[32m$t\033[0m"; }
function red( $t ) { return "\033[31m$t\033[0m"; }
function blue( $t ) { return "\033[34m$t\033[0m"; }
function yellow( $t ) { return "\033[33m$t\033[0m"; }

echo blue( "╔══════════════════════════════════════════════════════════════════════════════╗\n" );
echo blue( "║              PHASE 3 FINAL VERIFICATION - ALL 14 CLASSES                    ║\n" );
echo blue( "╚══════════════════════════════════════════════════════════════════════════════╝\n\n" );

$results = array();
$classes = array(
'Cart' => array(
'Cart'          => 'WhatsAppCommerceHub\Domain\Cart\Cart',
'CartException' => 'WhatsAppCommerceHub\Domain\Cart\CartException',
'CartService'   => 'WhatsAppCommerceHub\Domain\Cart\CartService',
),
'Catalog' => array(
'ProductSyncService' => 'WhatsAppCommerceHub\Application\Services\ProductSyncService',
'CatalogBrowser'     => 'WhatsAppCommerceHub\Domain\Catalog\CatalogBrowser',
),
'Order' => array(
'OrderSyncService' => 'WhatsAppCommerceHub\Application\Services\OrderSyncService',
),
'Customer' => array(
'Customer'        => 'WhatsAppCommerceHub\Domain\Customer\Customer',
'CustomerService' => 'WhatsAppCommerceHub\Domain\Customer\CustomerService',
'CustomerProfile' => 'WhatsAppCommerceHub\Domain\Customer\CustomerProfile',
),
'Conversation' => array(
'Conversation'     => 'WhatsAppCommerceHub\Domain\Conversation\Conversation',
'Intent'           => 'WhatsAppCommerceHub\Domain\Conversation\Intent',
'Context'          => 'WhatsAppCommerceHub\Domain\Conversation\Context',
'StateMachine'     => 'WhatsAppCommerceHub\Domain\Conversation\StateMachine',
'IntentClassifier' => 'WhatsAppCommerceHub\Support\AI\IntentClassifier',
),
);

$totalClasses = 0;
foreach ( $classes as $domain => $domainClasses ) {
echo yellow( "\n=== $domain Domain ===\n" );
foreach ( $domainClasses as $name => $class ) {
echo "Testing $name... ";
try {
if ( ! class_exists( $class ) ) { throw new Exception( 'Not found' ); }
$ref = new ReflectionClass( $class );
$content = file_get_contents( $ref->getFileName() );
if ( strpos( $content, 'declare(strict_types=1)' ) === false ) {
throw new Exception( 'No strict types' );
}
echo green( "✓\n" );
$results[ $name ] = true;
$totalClasses++;
} catch ( Exception $e ) {
echo red( "✗ " . $e->getMessage() . "\n" );
$results[ $name ] = false;
}
}
}

// Legacy mapper
echo "\n" . yellow( "=== Legacy Mapper ===\n" );
echo "Testing mappings... ";
try {
$mapper = 'WhatsAppCommerceHub\Core\LegacyClassMapper';
$mapping = $mapper::getMapping();
$expected = array(
'WCH_Customer_Profile'   => 'WhatsAppCommerceHub\Domain\Customer\CustomerProfile',
);
foreach ( $expected as $legacy => $modern ) {
if ( ! isset( $mapping[ $legacy ] ) || $mapping[ $legacy ] !== $modern ) {
throw new Exception( "Mapping error: $legacy" );
}
}
echo green( "✓\n" );
$results['Mapper'] = true;
} catch ( Exception $e ) {
echo red( "✗ " . $e->getMessage() . "\n" );
$results['Mapper'] = false;
}

echo "\n" . blue( "╔══════════════════════════════════════════════════════════════════════════════╗\n" );
echo blue( "║                                SUMMARY                                       ║\n" );
echo blue( "╚══════════════════════════════════════════════════════════════════════════════╝\n\n" );

echo yellow( "Total Classes: $totalClasses\n" );
$passed = count( array_filter( $results ) );
$total = count( $results );

if ( $passed === $total ) {
echo green( "\n✅ ALL TESTS PASSED ($passed/$total)\n" );
echo green( "🎉 PHASE 3 DOMAIN LAYER: 78% COMPLETE (14/18 classes)\n" );
exit( 0 );
} else {
echo red( "\n✗ Some tests failed ($passed/$total)\n" );
exit( 1 );
}
