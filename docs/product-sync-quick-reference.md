# Product Sync Quick Reference

## Constants Reference

### Metadata Keys (`ProductSyncMetadata`)
```php
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncMetadata;

ProductSyncMetadata::SYNC_HASH      // '_wch_sync_hash'
ProductSyncMetadata::CATALOG_ID     // '_wch_catalog_id'
ProductSyncMetadata::LAST_SYNCED    // '_wch_last_synced'
ProductSyncMetadata::SYNC_STATUS    // '_wch_sync_status'
ProductSyncMetadata::SYNC_MESSAGE   // '_wch_sync_message'
```

### Status Values (`ProductSyncStatus`)
```php
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncStatus;

ProductSyncStatus::SYNCED       // 'synced'
ProductSyncStatus::ERROR        // 'error'
ProductSyncStatus::PARTIAL      // 'partial'
ProductSyncStatus::PENDING      // 'pending'
ProductSyncStatus::NOT_SYNCED   // 'not_synced'

// Helper methods
ProductSyncStatus::getAllStatuses(): array
ProductSyncStatus::isValid(string $status): bool
```

### Settings Keys (`ProductSyncSettings`)
```php
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncSettings;

// API Configuration
ProductSyncSettings::PHONE_NUMBER_ID        // 'api.whatsapp_phone_number_id'
ProductSyncSettings::ACCESS_TOKEN           // 'api.access_token'
ProductSyncSettings::CATALOG_ID             // 'catalog.catalog_id'

// Sync Behavior
ProductSyncSettings::SYNC_ENABLED           // 'catalog.sync_enabled'
ProductSyncSettings::SYNC_PRODUCTS          // 'catalog.sync_products'
ProductSyncSettings::INCLUDE_OUT_OF_STOCK   // 'catalog.include_out_of_stock'

// Scheduling
ProductSyncSettings::SYNC_MODE              // 'sync.mode'
ProductSyncSettings::SYNC_FREQUENCY         // 'sync.frequency'
ProductSyncSettings::CATEGORIES_INCLUDE     // 'sync.categories_include'
ProductSyncSettings::CATEGORIES_EXCLUDE     // 'sync.categories_exclude'
ProductSyncSettings::LAST_FULL_SYNC         // 'sync.last_full_sync'
```

---

## Service Interfaces

### Get Services
```php
use WhatsAppCommerceHub\Contracts\Services\ProductSync\*;

$validator    = wch( ProductValidatorInterface::class );
$transformer  = wch( CatalogTransformerInterface::class );
$catalogApi   = wch( CatalogApiInterface::class );
$tracker      = wch( SyncProgressTrackerInterface::class );
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
```

---

## Common Operations

### Sync Single Product
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$result = $orchestrator->syncProduct( 123 );

if ( $result['success'] ) {
    $catalogItemId = $result['catalog_item_id'];
    echo "Synced! Catalog ID: {$catalogItemId}";
} else {
    echo "Error: {$result['error']}";
}
```

### Sync All Products
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$syncId = $orchestrator->syncAllProducts();

if ( $syncId ) {
    echo "Bulk sync started: {$syncId}";
}
```

### Delete Product from Catalog
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$result = $orchestrator->deleteProduct( 123 );

if ( $result['success'] ) {
    echo "Deleted from catalog";
}
```

### Check Sync Progress
```php
$tracker = wch( SyncProgressTrackerInterface::class );
$progress = $tracker->getProgress();

if ( $progress ) {
    echo "Processed: {$progress['processed']} / {$progress['total']}";
    echo "Successful: {$progress['successful']}";
    echo "Failed: {$progress['failed']}";
    echo "Status: {$progress['status']}";
}
```

### Retry Failed Items
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$syncId = $orchestrator->retryFailedItems();

if ( $syncId ) {
    echo "Retry sync started: {$syncId}";
} else {
    echo "No failed items to retry";
}
```

### Get Failed Items
```php
$tracker = wch( SyncProgressTrackerInterface::class );
$failedItems = $tracker->getFailedItems();

foreach ( $failedItems as $item ) {
    echo "Product {$item['product_id']}: {$item['error']}\n";
}
```

---

## Check Product Status

### Get Sync Status
```php
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncMetadata;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncStatus;

$productId = 123;

$status = get_post_meta( $productId, ProductSyncMetadata::SYNC_STATUS, true );
$catalogId = get_post_meta( $productId, ProductSyncMetadata::CATALOG_ID, true );
$lastSynced = get_post_meta( $productId, ProductSyncMetadata::LAST_SYNCED, true );
$message = get_post_meta( $productId, ProductSyncMetadata::SYNC_MESSAGE, true );

echo "Status: {$status}\n";
echo "Catalog ID: {$catalogId}\n";
echo "Last Synced: {$lastSynced}\n";
echo "Message: {$message}\n";

if ( ProductSyncStatus::SYNCED === $status ) {
    echo "Product is synced!";
}
```

### Validate Product
```php
$validator = wch( ProductValidatorInterface::class );
$product = wc_get_product( 123 );

$result = $validator->validate( $product );

if ( $result['valid'] ) {
    echo "Product is valid for sync";
} else {
    echo "Cannot sync: {$result['reason']}";
}
```

### Check if Product Changed
```php
$validator = wch( ProductValidatorInterface::class );

if ( $validator->hasProductChanged( 123 ) ) {
    echo "Product has changed since last sync";
} else {
    echo "Product unchanged";
}
```

---

## Queue Integration

### Dispatch Batch Job
```php
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

wch( JobDispatcher::class )->dispatch(
    'wch_sync_product_batch',
    [
        'product_ids'   => [1, 2, 3, 4, 5],
        'batch_index'   => 0,
        'total_batches' => 1,
        'sync_id'       => 'custom-sync-123',
        'is_retry'      => false,
    ]
);
```

### Dispatch Single Product Job
```php
wch( JobDispatcher::class )->dispatch(
    'wch_sync_single_product',
    [ 'product_id' => 123 ]
);
```

---

## Admin UI Integration

### Get Eligible Products
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$productIds = $orchestrator->getProductsToSync();

echo count( $productIds ) . " products eligible for sync";
```

### Check if Sync Enabled
```php
$validator = wch( ProductValidatorInterface::class );

if ( $validator->isSyncEnabled() ) {
    echo "Sync is enabled";
} else {
    echo "Sync is disabled";
}
```

### Check API Configuration
```php
$catalogApi = wch( CatalogApiInterface::class );

if ( $catalogApi->isConfigured() ) {
    $catalogId = $catalogApi->getCatalogId();
    echo "API configured. Catalog ID: {$catalogId}";
} else {
    echo "API not configured";
}
```

---

## Metadata Management

### Update Sync Status
```php
$catalogApi = wch( CatalogApiInterface::class );

$catalogApi->updateSyncStatus(
    123,                              // Product ID
    ProductSyncStatus::SYNCED,        // Status
    'whatsapp-item-abc123',          // Catalog item ID
    'Successfully synced'             // Message (optional)
);
```

### Clear All Metadata
```php
$catalogApi = wch( CatalogApiInterface::class );
$catalogApi->clearSyncMetadata( 123 );
```

### Get Catalog Item ID
```php
$catalogApi = wch( CatalogApiInterface::class );
$catalogItemId = $catalogApi->getCatalogItemId( 123 );

if ( $catalogItemId ) {
    echo "Catalog Item ID: {$catalogItemId}";
} else {
    echo "Product not synced";
}
```

---

## Settings Access

### Get Settings
```php
$settings = wch( SettingsInterface::class );

$syncEnabled = $settings->get( ProductSyncSettings::SYNC_ENABLED, false );
$catalogId = $settings->get( ProductSyncSettings::CATALOG_ID );
$syncProducts = $settings->get( ProductSyncSettings::SYNC_PRODUCTS, 'all' );
$includeOutOfStock = $settings->get( ProductSyncSettings::INCLUDE_OUT_OF_STOCK, false );
```

### Update Settings
```php
$settings = wch( SettingsInterface::class );

$settings->set( ProductSyncSettings::SYNC_ENABLED, true );
$settings->set( ProductSyncSettings::CATALOG_ID, 'your-catalog-id' );
$settings->set( ProductSyncSettings::SYNC_PRODUCTS, 'all' ); // or [1, 2, 3]
$settings->set( ProductSyncSettings::INCLUDE_OUT_OF_STOCK, true );
```

---

## Hooks & Filters

### Product Validation Filter
```php
add_filter( 'wch_product_validation', function( $result, $product ) {
    // Custom validation logic
    if ( some_condition( $product ) ) {
        return [
            'valid'  => false,
            'reason' => 'Custom validation failed',
        ];
    }
    return $result;
}, 10, 2 );
```

### Auto-sync on Product Update
The orchestrator automatically listens to:
- `woocommerce_update_product`
- `woocommerce_new_product`
- `before_delete_post` (for products)

No manual hook registration needed.

---

## Error Handling

### Check for Errors
```php
$result = $orchestrator->syncProduct( 123 );

if ( ! $result['success'] ) {
    $error = $result['error'] ?? 'Unknown error';

    // Log error
    wch( LoggerInterface::class )->log(
        'error',
        'Product sync failed',
        'product-sync',
        [
            'product_id' => 123,
            'error'      => $error,
        ]
    );

    // Or handle error
    wp_die( "Sync failed: {$error}" );
}
```

### Handle Batch Failures
```php
$tracker = wch( SyncProgressTrackerInterface::class );
$progress = $tracker->getProgress();

if ( $progress && $progress['status'] === 'failed' ) {
    echo "Bulk sync failed: {$progress['error']}";

    // Get failed items
    $failedItems = $tracker->getFailedItems();
    foreach ( $failedItems as $item ) {
        echo "- Product {$item['product_id']}: {$item['error']}\n";
    }
}
```

---

## Testing Helpers

### Dry Run Check
```php
// Validate without syncing
$validator = wch( ProductValidatorInterface::class );
$product = wc_get_product( 123 );

$validation = $validator->validate( $product );
if ( $validation['valid'] ) {
    // Transform without API call
    $transformer = wch( CatalogTransformerInterface::class );
    $catalogData = $transformer->transform( $product );

    echo "Would sync:\n";
    print_r( $catalogData );
}
```

### Mock Progress Tracking
```php
$tracker = wch( SyncProgressTrackerInterface::class );

// Start test sync
$syncId = $tracker->startSync( 10 );

// Simulate progress
$tracker->updateProgress( $syncId, 5, 4, 1 );

// Add test failure
$tracker->addFailure( $syncId, 123, 'Test error' );

// Get progress
$progress = $tracker->getProgress();
print_r( $progress );

// Clean up
$tracker->clearProgress( true );
```

---

## Performance Tips

1. **Batch Size**: Default is 50 products per batch. Adjust `ProductSyncOrchestrator::BATCH_SIZE` if needed.

2. **Parallel Processing**: Queue system processes batches asynchronously for better performance.

3. **Change Detection**: Hash-based change detection prevents unnecessary API calls.

4. **Progress Tracking**: Uses database locks for atomic updates - avoid manual queries.

5. **Failed Item Limit**: Max 100 failed items stored to prevent memory issues.

---

## Debugging

### Enable Logging
```php
$logger = wch( LoggerInterface::class );

// Log is automatically written by all services
// Check logs in: WordPress admin → Tools → Logs
```

### Check Queue Status
```php
$queueManager = wch( QueueManager::class );
// Queue status methods vary by implementation
```

### Inspect Progress Data
```php
$progress = get_option( 'wch_bulk_sync_progress' );
print_r( $progress );
```

### Check Metadata
```php
$allMeta = get_post_meta( 123 );
print_r( $allMeta );
```

---

## Migration from Old Code

### Old Code → New Code

```php
// OLD: Magic strings
get_post_meta( $id, '_wch_sync_status', true );
update_post_meta( $id, '_wch_sync_status', 'synced' );

// NEW: Constants
get_post_meta( $id, ProductSyncMetadata::SYNC_STATUS, true );
update_post_meta( $id, ProductSyncMetadata::SYNC_STATUS, ProductSyncStatus::SYNCED );
```

```php
// OLD: Settings with magic strings
$enabled = $settings->get( 'catalog.sync_enabled' );

// NEW: Settings with constants
$enabled = $settings->get( ProductSyncSettings::SYNC_ENABLED );
```

```php
// OLD: Status checks with strings
if ( $status === 'synced' ) { }

// NEW: Status checks with constants
if ( $status === ProductSyncStatus::SYNCED ) { }
```

---

## See Also

- Full Architecture Guide: `docs/product-sync-architecture.md`
- Service Contracts: `includes/Contracts/Services/ProductSync/`
- Service Implementations: `includes/Application/Services/ProductSync/`
- Admin UI: `includes/Presentation/Admin/Pages/CatalogSyncPage.php`
