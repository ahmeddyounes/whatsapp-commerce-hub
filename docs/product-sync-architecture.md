# Product Sync Architecture

## Overview

The Product Sync module provides a complete system for synchronizing WooCommerce products with the WhatsApp Business Catalog API. It consists of well-defined service boundaries, clear contracts, and robust progress tracking.

## Core Components

### 1. Service Layer (`includes/Application/Services/ProductSync/`)

#### ProductValidatorService
**Purpose**: Validates products before sync and detects changes.

**Key Responsibilities**:
- Validates product meets sync criteria (published, has price, has name)
- Checks stock status and sync list inclusion
- Generates MD5 hash for change detection
- Verifies sync is enabled globally

**Key Methods**:
- `validate(WC_Product $product): array` - Validates product eligibility
- `hasProductChanged(int $productId): bool` - Detects changes via hash
- `generateProductHash(WC_Product $product): string` - Creates hash from product data
- `isSyncEnabled(): bool` - Checks if sync is enabled

**Dependencies**: `SettingsInterface`

---

#### CatalogTransformerService
**Purpose**: Transforms WooCommerce products to WhatsApp catalog format.

**Key Responsibilities**:
- Converts product data to WhatsApp API format
- Handles both simple and variable products
- Sanitizes names (200 char max) and descriptions (9999 char max)
- Extracts categories, brands, SKUs, prices, images

**Key Methods**:
- `transform(WC_Product $product): array` - Transform single product
- `transformVariableProduct(WC_Product $product): array` - Transform with variations
- `sanitizeName(string $name): string` - Sanitize product name
- `sanitizeDescription(string $desc): string` - Sanitize description

**Output Format**:
```php
[
    'retailer_id' => 'product-123',
    'name' => 'Product Name',
    'price' => 9999, // in cents
    'currency' => 'USD',
    'availability' => 'in stock',
    'image_url' => 'https://...',
    'category' => 'Category Name',
    'brand' => 'Brand Name',
    'description' => 'Product description...'
]
```

**Dependencies**: `ProductValidatorInterface`

---

#### CatalogApiService
**Purpose**: Handles WhatsApp Catalog API operations.

**Key Responsibilities**:
- Creates/updates products in WhatsApp catalog
- Deletes products from catalog
- Manages product sync metadata
- Validates API configuration

**Key Methods**:
- `createProduct(array $catalogData): array` - Create/update product
- `deleteProduct(string $catalogItemId): array` - Delete product
- `updateSyncStatus(int $productId, string $status, string $catalogItemId, string $message): void`
- `clearSyncMetadata(int $productId): void`
- `getCatalogItemId(int $productId): ?string`
- `getCatalogId(): ?string`
- `isConfigured(): bool`

**Metadata Managed**:
- Catalog item ID
- Last sync timestamp
- Sync status
- Error messages
- Product hash

**Dependencies**: `SettingsInterface`, `LoggerInterface`, `WhatsAppApiClient`

---

#### SyncProgressTracker
**Purpose**: Atomic progress tracking for bulk sync operations.

**Key Responsibilities**:
- Initializes bulk sync sessions with unique IDs
- Atomically updates progress counters using database locks
- Records failed items with error details
- Calculates elapsed time and ETA
- Marks sync as completed/failed/in-progress

**Key Methods**:
- `startSync(int $totalItems): string` - Start new session
- `updateProgress(string $syncId, int $processed, int $successful, int $failed): bool`
- `addFailure(string $syncId, int $productId, string $errorMessage): bool`
- `getProgress(): ?array` - Get current progress
- `failSync(string $syncId, string $reason): bool`
- `clearProgress(bool $force): bool`
- `getFailedItems(): array`
- `isSyncInProgress(): bool`
- `getCurrentSyncId(): ?string`

**Concurrency Safety**:
- Uses MySQL `GET_LOCK` / `RELEASE_LOCK` with 30s timeout
- Option key: `wch_bulk_sync_progress`
- Max 100 failed items stored to prevent memory bloat

**Dependencies**: `wpdb`, `LoggerInterface`

---

#### ProductSyncOrchestrator
**Purpose**: Main coordinator for all product sync operations.

**Key Responsibilities**:
- Orchestrates single and bulk product syncs
- Handles variable products and variations
- Dispatches batch jobs to queue
- Manages product update/delete hooks
- Coordinates between all services
- Retries failed items

**Key Methods**:
- `syncProduct(int $productId): array` - Sync single product
- `syncAllProducts(): ?string` - Sync all eligible products
- `deleteProduct(int $productId): array` - Delete from catalog
- `getProductsToSync(): array` - Get eligible product IDs
- `retryFailedItems(): ?string` - Retry previous failures
- `handleProductUpdate(int $productId): void` - WooCommerce hook handler
- `handleProductDelete(int $postId): void` - WooCommerce hook handler
- `processBatch(array $args): void` - Queue job handler

**Batch Processing**:
- Batch size: 50 products
- Dispatched via queue system
- Real-time progress tracking

**Dependencies**: All other sync services, `JobDispatcher`

---

#### ProductSyncAdminUI
**Purpose**: Admin interface elements for product sync status.

**Key Responsibilities**:
- Adds "WhatsApp Sync" column to products list
- Displays sync status with visual indicators
- Implements bulk actions (Sync/Remove)
- Shows admin notices
- Provides sync status summary

**Key Methods**:
- `addSyncStatusColumn(array $columns): array`
- `renderSyncStatusColumn(string $column, int $postId): void`
- `addBulkActions(array $actions): array`
- `handleBulkActions(string $redirect, string $action, array $postIds): string`
- `showBulkActionNotices(): void`
- `getProductSyncStatus(int $productId): array`

**Status Icons**:
- ✓ Green - Synced successfully
- ✗ Red - Sync error
- ◐ Yellow - Partially synced (some variations failed)
- — Gray - Not synced or sync disabled

**Dependencies**: `ProductValidatorInterface`, `ProductSyncOrchestratorInterface`

---

### 2. Contract Layer (`includes/Contracts/Services/ProductSync/`)

#### Interfaces
All services implement well-defined interfaces:
- `ProductValidatorInterface`
- `CatalogTransformerInterface`
- `CatalogApiInterface`
- `SyncProgressTrackerInterface`
- `ProductSyncOrchestratorInterface`

#### Constants Classes

##### ProductSyncMetadata
Centralized metadata key constants:
```php
const SYNC_HASH = '_wch_sync_hash';
const CATALOG_ID = '_wch_catalog_id';
const LAST_SYNCED = '_wch_last_synced';
const SYNC_STATUS = '_wch_sync_status';
const SYNC_MESSAGE = '_wch_sync_message';
```

##### ProductSyncStatus
Enumerated status values:
```php
const SYNCED = 'synced';
const ERROR = 'error';
const PARTIAL = 'partial';
const PENDING = 'pending';
const NOT_SYNCED = 'not_synced';
```

##### ProductSyncSettings
Settings key constants:
```php
const SYNC_ENABLED = 'catalog.sync_enabled';
const CATALOG_ID = 'catalog.catalog_id';
const SYNC_PRODUCTS = 'catalog.sync_products';
const INCLUDE_OUT_OF_STOCK = 'catalog.include_out_of_stock';
const PHONE_NUMBER_ID = 'api.whatsapp_phone_number_id';
const ACCESS_TOKEN = 'api.access_token';
// ... and more
```

---

### 3. Presentation Layer (`includes/Presentation/Admin/Pages/`)

#### CatalogSyncPage
**Purpose**: Main admin page for catalog synchronization.

**Features**:
- Sync overview with statistics
- Product selection and filtering
- Real-time progress tracking
- Sync history (last 100 entries)
- Settings management
- Dry run capability
- Retry failed items

**AJAX Endpoints** (15+ handlers):
- `wch_get_products` - Fetch products with filters
- `wch_bulk_sync` - Bulk sync operations
- `wch_sync_product` - Sync single product
- `wch_remove_from_catalog` - Remove products
- `wch_get_sync_history` - Sync history records
- `wch_get_sync_status` - Current status overview
- `wch_save_sync_settings` - Save configuration
- `wch_dry_run_sync` - Test sync without executing
- `wch_retry_failed` - Retry failed products
- `wch_get_bulk_sync_progress` - Track progress in real-time
- `wch_retry_failed_bulk` - Retry bulk failed items
- `wch_clear_sync_progress` - Clear progress data

**Filters**:
- Category
- Stock status
- Sync status
- Product search
- Pagination support

**Dependencies**: `ProductSyncOrchestratorInterface`, `SyncProgressTrackerInterface`, `SettingsManager`, `QueueManager`

---

### 4. Infrastructure Layer

#### ProductSyncServiceProvider
**Purpose**: Registers and wires all Product Sync services.

**Registered Services** (as singletons):
- `ProductValidatorInterface` → `ProductValidatorService`
- `CatalogTransformerInterface` → `CatalogTransformerService`
- `CatalogApiInterface` → `CatalogApiService`
- `SyncProgressTrackerInterface` → `SyncProgressTracker`
- `ProductSyncOrchestratorInterface` → `ProductSyncOrchestrator`
- `ProductSyncAdminUI`

**WordPress Hooks Registered**:
- `woocommerce_update_product` → `handleProductUpdate()`
- `woocommerce_new_product` → `handleProductUpdate()`
- `before_delete_post` → `handleProductDelete()`

**Queue Hooks Registered**:
- `wch_sync_product_batch` → `processBatch()`
- `wch_sync_single_product` → `syncProduct()`

---

## Data Flow

### Sync Single Product Flow
```
User Action / WooCommerce Hook
    ↓
ProductSyncOrchestrator::syncProduct()
    ↓
ProductValidator::validate()
    ↓
CatalogTransformer::transform()
    ↓
CatalogApiService::createProduct()
    ↓
WhatsAppApiClient → WhatsApp API
    ↓
CatalogApiService::updateSyncStatus()
```

### Bulk Sync Flow
```
User Initiates Bulk Sync
    ↓
ProductSyncOrchestrator::syncAllProducts()
    ↓
SyncProgressTracker::startSync()
    ↓
Split into batches (50 products each)
    ↓
JobDispatcher → Queue System
    ↓
Queue Worker → wch_sync_product_batch action
    ↓
ProductSyncOrchestrator::processBatch()
    ↓
For each product: syncProduct()
    ↓
SyncProgressTracker::updateProgress()
```

### Variable Product Flow
```
ProductSyncOrchestrator::syncProduct(parent_id)
    ↓
Detect: is_type('variable')
    ↓
Get all variations
    ↓
For each variation:
    - Transform variation data
    - Sync to WhatsApp
    - Track success/failure
    ↓
Update parent status:
    - All success → SYNCED
    - Some failures → PARTIAL
    - All failures → ERROR
```

---

## Queue Integration

### Job Dispatching
Jobs are dispatched via `JobDispatcher`:
```php
wch( JobDispatcher::class )->dispatch(
    'wch_sync_product_batch',
    [
        'product_ids'   => [1, 2, 3, ...],
        'batch_index'   => 0,
        'total_batches' => 5,
        'sync_id'       => 'abc123',
        'is_retry'      => false,
    ]
);
```

### Job Processing
Jobs are processed via WordPress actions:
```php
do_action( 'wch_sync_product_batch', $args );
```

The `ProductSyncServiceProvider` registers handlers for these actions.

---

## Configuration

### Required Settings
All settings use constants from `ProductSyncSettings`:
- **API Configuration**:
  - `PHONE_NUMBER_ID` - WhatsApp Business phone number ID
  - `ACCESS_TOKEN` - WhatsApp API access token
  - `CATALOG_ID` - WhatsApp catalog ID

- **Sync Behavior**:
  - `SYNC_ENABLED` - Enable/disable sync
  - `SYNC_PRODUCTS` - 'all' or array of product IDs
  - `INCLUDE_OUT_OF_STOCK` - Include out-of-stock products

- **Scheduling** (optional):
  - `SYNC_MODE` - 'manual', 'on_change', or 'scheduled'
  - `SYNC_FREQUENCY` - 'hourly', 'twicedaily', or 'daily'
  - `CATEGORIES_INCLUDE` - Array of category IDs
  - `CATEGORIES_EXCLUDE` - Array of category IDs

### API Validation
`CatalogApiService::isConfigured()` checks:
1. Phone number ID is set
2. Access token is set
3. Catalog ID is set

---

## Metadata Management

### Product Post Meta
All metadata uses constants from `ProductSyncMetadata`:

| Meta Key | Purpose | Type |
|----------|---------|------|
| `SYNC_HASH` | MD5 hash for change detection | string |
| `CATALOG_ID` | WhatsApp catalog item ID | string |
| `LAST_SYNCED` | Timestamp of last sync | string (MySQL datetime) |
| `SYNC_STATUS` | Current sync status | string (enum) |
| `SYNC_MESSAGE` | Error or status message | string |

### Status Values
All status values use constants from `ProductSyncStatus`:
- `SYNCED` - Successfully synced
- `ERROR` - Sync failed
- `PARTIAL` - Some variations failed
- `PENDING` - Queued for sync
- `NOT_SYNCED` - Not yet synced

---

## Progress Tracking

### Progress Data Structure
```php
[
    'sync_id' => 'abc123',
    'status' => 'in_progress', // or 'completed', 'failed'
    'total' => 100,
    'processed' => 50,
    'successful' => 45,
    'failed' => 5,
    'failed_items' => [
        ['product_id' => 123, 'error' => 'API error'],
        // ... up to 100 failures
    ],
    'started_at' => 1234567890,
    'completed_at' => null,
    'elapsed_seconds' => 120,
    'estimated_remaining_seconds' => 120,
]
```

### Concurrency Safety
- Uses MySQL advisory lock: `GET_LOCK('wch_bulk_sync', 30)`
- Atomic updates to prevent race conditions
- Timeout after 30 seconds if lock cannot be acquired

---

## Error Handling

### Validation Errors
When validation fails:
```php
[
    'valid' => false,
    'reason' => 'Product is not published'
]
```

### API Errors
When API call fails:
```php
[
    'success' => false,
    'error' => 'API error message'
]
```

### Batch Processing Errors
- Individual product failures are tracked
- Sync continues for remaining products
- Failed items stored in progress tracker
- Can be retried via `retryFailedItems()`

---

## Testing End-to-End

### Prerequisites
1. Configure WhatsApp API credentials
2. Set catalog ID
3. Enable sync
4. Have WooCommerce products

### Test Single Product Sync
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$result = $orchestrator->syncProduct( 123 );
// Expected: ['success' => true, 'catalog_item_id' => '...']
```

### Test Bulk Sync
```php
$orchestrator = wch( ProductSyncOrchestratorInterface::class );
$syncId = $orchestrator->syncAllProducts();
// Expected: sync session ID string

// Check progress
$tracker = wch( SyncProgressTrackerInterface::class );
$progress = $tracker->getProgress();
// Expected: progress array with status, counts, etc.
```

### Test Via Admin UI
1. Navigate to WhatsApp → Catalog Sync
2. Select products or "Select All"
3. Click "Sync Selected Products"
4. Monitor progress modal
5. Verify sync status in products list column

---

## Extending the System

### Add Custom Validation
Use the filter hook:
```php
add_filter( 'wch_product_validation', function( $result, $product ) {
    if ( some_custom_check( $product ) ) {
        return [
            'valid' => false,
            'reason' => 'Custom validation failed',
        ];
    }
    return $result;
}, 10, 2 );
```

### Custom Product Transformation
Extend `CatalogTransformerService` or filter the output.

### Custom Sync Triggers
Dispatch sync jobs manually:
```php
wch( JobDispatcher::class )->dispatch(
    'wch_sync_single_product',
    [ 'product_id' => 123 ]
);
```

---

## Architecture Benefits

1. **Clear Boundaries**: Each service has a single responsibility
2. **Testable**: Services are dependency-injected and mockable
3. **Type Safe**: Strict typing with PHP 8.1+ features
4. **Constants**: No magic strings - all keys/statuses are constants
5. **Extensible**: Interfaces allow for alternative implementations
6. **Robust**: Atomic progress tracking with database locks
7. **Admin-Friendly**: Comprehensive UI with real-time feedback
8. **Queue-Ready**: Bulk operations use queue for reliability
9. **Observable**: Extensive logging at all layers
10. **Documented**: Clear contracts and inline documentation

---

## Summary

The Product Sync module provides enterprise-grade synchronization between WooCommerce and WhatsApp Business Catalog. It features:

- ✅ Complete service layer with clear responsibilities
- ✅ Strong contracts with comprehensive interfaces
- ✅ Centralized constants for metadata, statuses, and settings
- ✅ Atomic progress tracking with concurrency safety
- ✅ Queue-based bulk processing
- ✅ Real-time admin UI with progress tracking
- ✅ Full error handling and retry capability
- ✅ Variable product support
- ✅ Comprehensive logging
- ✅ Extensible architecture

The system can now run end-to-end product sync operations reliably with proper boundaries, validated contracts, and consistent configuration management.
