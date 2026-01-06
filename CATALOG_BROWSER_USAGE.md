# WCH_Catalog_Browser Usage Guide

## Overview

The `WCH_Catalog_Browser` class provides a comprehensive product browsing experience for WhatsApp Commerce Hub. It enables customers to browse products, search, view details, and select variants through WhatsApp's interactive messaging interface.

## Class Location

`includes/class-wch-catalog-browser.php`

## Public Methods

### 1. show_main_menu()

Displays the main catalog browsing menu with dynamic categories, featured products, search, and quick reorder options.

```php
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_main_menu( $conversation );
```

**Features:**
- Dynamically loads categories from WooCommerce
- Shows Featured Products option
- Shows Search Products option
- Shows Quick Reorder for returning customers
- Product count displayed for each category

### 2. show_category()

Shows products in a specific category with pagination support.

```php
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_category( $conversation, $category_id, $page = 1 );
```

**Parameters:**
- `$conversation`: WCH_Conversation_Context object
- `$category_id`: WooCommerce category term ID
- `$page`: Page number (default: 1, 10 products per page)

**Features:**
- Products grouped by subcategory or alphabetically
- Each product shows: name (max 50 chars), price, short description (max 72 chars)
- Stock status indicator (✅/❌)
- Navigation buttons: Previous Page, Next Page, Back to Categories
- Page indicator in footer

### 3. show_product_detail()

Displays comprehensive product information in a sequence of messages.

```php
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_product_detail( $conversation, $product_id );
```

**Message Sequence:**
1. **Image message** - Main product photo (optimized 500x500)
2. **Product details** - Name, full description, price, availability
3. **Variant information** - For variable products (numbered list)
4. **Interactive buttons** - Add to Cart, View More Images, Back

**Features:**
- Sale price highlighted with "was $X" format
- Low stock warning for quantities < 10
- Gallery support for multiple images
- Smart variant selection for variable products

### 4. show_variant_selector()

Shows variant selection interface for variable products.

```php
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_variant_selector( $conversation, $product_id, $attribute = null );
```

**Parameters:**
- `$conversation`: WCH_Conversation_Context object
- `$product_id`: WooCommerce product ID
- `$attribute`: Specific attribute name (optional, defaults to first attribute)

**Features:**
- Lists available options for selected attribute (color, size, etc.)
- Displays up to 10 options per message
- Formatted attribute names (removes 'pa_' prefix, converts to title case)

### 5. search_products()

Searches products by name and SKU, returning top 10 matches.

```php
$browser = new WCH_Catalog_Browser();
$messages = $browser->search_products( $conversation, $query );
```

**Features:**
- Searches product names and SKUs
- Returns up to 10 most relevant results
- Suggests categories if no matches found
- Uses WooCommerce's native search functionality

### 6. show_featured()

Displays featured and on-sale products.

```php
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_featured( $conversation );
```

**Features:**
- Prioritizes products with `_featured` meta
- Falls back to on-sale products
- Shows up to 10 featured products
- Sorted by date (newest first)

## Image Optimization

The class implements automatic image optimization for WhatsApp:

**Features:**
- Generates 500x500 pixel thumbnails
- Caches optimized image URLs in post meta (`_wch_optimized_image_url`)
- Falls back to full-size images if optimization fails
- Uses WordPress's native image resizing

**Cache Key:** `_wch_optimized_image_url` (stored on attachment)

## Constants

```php
const PRODUCTS_PER_PAGE = 10;           // Products shown per page
const MAX_PRODUCT_NAME_LENGTH = 50;     // Max chars for product name in lists
const MAX_PRODUCT_DESC_LENGTH = 72;     // Max chars for description in lists
const IMAGE_SIZE = 500;                 // Thumbnail size for optimization (500x500)
```

## Integration Examples

### Example 1: Catalog Main Menu

```php
// In an action handler or webhook processor
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_main_menu( $conversation );

// Send messages via WhatsApp API
foreach ( $messages as $message ) {
    $api_client->send_message( $conversation->customer_phone, $message->build() );
}
```

### Example 2: Category Browsing with Pagination

```php
// Handle category selection
$category_id = 42; // From user selection
$page = 1;

$browser = new WCH_Catalog_Browser();
$messages = $browser->show_category( $conversation, $category_id, $page );

// Handle next page
$page = 2;
$messages = $browser->show_category( $conversation, $category_id, $page );
```

### Example 3: Product Search Flow

```php
// User searches for "blue shoes"
$query = 'blue shoes';

$browser = new WCH_Catalog_Browser();
$messages = $browser->search_products( $conversation, $query );

// If user selects a product from results
$product_id = 123; // From user selection
$messages = $browser->show_product_detail( $conversation, $product_id );
```

### Example 4: Variable Product Flow

```php
// Show product detail (detects variable product automatically)
$browser = new WCH_Catalog_Browser();
$messages = $browser->show_product_detail( $conversation, $product_id );

// User clicks "Select Options"
$messages = $browser->show_variant_selector( $conversation, $product_id );

// User selects a specific attribute option
// (This would typically trigger variant filtering or direct add-to-cart)
```

## Message Format

All methods return an array of `WCH_Message_Builder` instances that need to be built and sent:

```php
$messages = $browser->show_category( $conversation, $category_id );

foreach ( $messages as $message_builder ) {
    $built_message = $message_builder->build();
    // Send via WhatsApp API
    $api_client->send_interactive_message( $phone, $built_message );
}
```

## Database Integration

The class integrates with WooCommerce's native data structures:

- **Categories**: `wp_terms` (taxonomy: `product_cat`)
- **Products**: `wp_posts` (post_type: `product`)
- **Product Meta**: `wp_postmeta` (image optimization cache)
- **Orders**: `wp_wch_orders` (for quick reorder detection)

## Error Handling

All methods handle errors gracefully:

- Returns error messages as `WCH_Message_Builder` instances
- Logs errors via `WCH_Logger`
- Validates input parameters
- Checks product visibility and availability

## Logging

The class logs all significant actions:

```php
// Logged events
- Main catalog menu shown
- Category products shown (with count)
- Product detail shown
- Variant selector shown
- Product search completed (with result count)
- Featured products shown
```

## Performance Considerations

1. **Pagination**: Limits to 10 products per page to stay within WhatsApp message limits
2. **Image Caching**: Optimized images cached in post meta to avoid repeated processing
3. **Query Optimization**: Uses WooCommerce's native `wc_get_products()` with proper filters
4. **Text Truncation**: All text fields truncated to stay within WhatsApp limits

## WhatsApp Message Limits

The class respects all WhatsApp API constraints:

- **Sections**: Max 10 per message
- **Rows per section**: Max 10
- **Product name**: 50 characters
- **Description**: 72 characters
- **Header**: 60 characters
- **Footer**: 60 characters
- **Body**: 1024 characters

## Acceptance Criteria ✓

- ✅ Categories load dynamically from WC
- ✅ Products display correctly with pagination
- ✅ Pagination works (Previous/Next buttons)
- ✅ Variants selectable via interactive lists
- ✅ Search returns relevant results (top 10)
- ✅ Images optimized (500x500 thumbnails with caching)

## Next Steps

To integrate into the conversation flow:

1. **Create Action Handlers**: Create `WCH_Action_*` classes that use the browser methods
2. **Update FSM**: Add states and transitions for catalog browsing
3. **Map User Inputs**: Map button IDs and list selections to browser methods
4. **Send Messages**: Use `WCH_WhatsApp_API_Client` to send the built messages
5. **Track Context**: Store current category/page in conversation context

## Example Integration with Action Handler

```php
class WCH_Action_BrowseCatalog extends WCH_Flow_Action {
    public function execute( $conversation, $context, $payload ) {
        $browser = new WCH_Catalog_Browser();

        // Determine action from payload
        if ( isset( $payload['category_id'] ) ) {
            $page = $payload['page'] ?? 1;
            $messages = $browser->show_category(
                $conversation,
                $payload['category_id'],
                $page
            );
        } else {
            $messages = $browser->show_main_menu( $conversation );
        }

        return WCH_Action_Result::success( $messages );
    }
}
```
