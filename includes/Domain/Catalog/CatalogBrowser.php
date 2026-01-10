<?php
/**
 * Catalog Browser
 *
 * Domain service for interactive product browsing in WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Domain\Catalog;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Class CatalogBrowser
 *
 * Provides methods for browsing products in WhatsApp:
 * - Main menu with categories, featured, search, quick reorder
 * - Category browsing with pagination
 * - Product detail views
 * - Variant selection for variable products
 * - Product search
 * - Featured/on-sale products
 * - Image optimization
 *
 * Note: This is a transitional class. Full migration will implement proper
 * domain models (Product, Category, etc.) in a future phase.
 */
class CatalogBrowser {
/**
 * Products per page.
 */
private const PRODUCTS_PER_PAGE = 10;

/**
 * Maximum product name length in list.
 */
private const MAX_PRODUCT_NAME_LENGTH = 50;

/**
 * Maximum product description length in list.
 */
private const MAX_PRODUCT_DESC_LENGTH = 72;

/**
 * Image thumbnail size for WhatsApp optimization.
 */
private const IMAGE_SIZE = 500;

/**
 * Show main menu.
 *
 * @param mixed $conversation Conversation context.
 * @return array Array of message builder instances.
 */
public function showMainMenu( $conversation ): array {
// Delegate to legacy implementation for now.
// TODO: Implement with proper domain models in future phase.
if ( class_exists( 'WCH_Catalog_Browser' ) ) {
$legacy = new \WCH_Catalog_Browser();
return $legacy->show_main_menu( $conversation );
}

return array();
}

/**
 * Show category products.
 *
 * @param int   $categoryId Category ID.
 * @param int   $page       Page number.
 * @param mixed $conversation Conversation context.
 * @return array Array of message builder instances.
 */
public function showCategory( int $categoryId, int $page, $conversation ): array {
// Delegate to legacy implementation for now.
if ( class_exists( 'WCH_Catalog_Browser' ) ) {
$legacy = new \WCH_Catalog_Browser();
return $legacy->show_category( $categoryId, $page, $conversation );
}

return array();
}

/**
 * Show product details.
 *
 * @param int   $productId Product ID.
 * @param mixed $conversation Conversation context.
 * @return array Array of message builder instances.
 */
public function showProduct( int $productId, $conversation ): array {
// Delegate to legacy implementation for now.
if ( class_exists( 'WCH_Catalog_Browser' ) ) {
$legacy = new \WCH_Catalog_Browser();
return $legacy->show_product( $productId, $conversation );
}

return array();
}

/**
 * Search products.
 *
 * @param string $query Search query.
 * @param int    $page  Page number.
 * @param mixed  $conversation Conversation context.
 * @return array Array of message builder instances.
 */
public function searchProducts( string $query, int $page, $conversation ): array {
// Delegate to legacy implementation for now.
if ( class_exists( 'WCH_Catalog_Browser' ) ) {
$legacy = new \WCH_Catalog_Browser();
return $legacy->search_products( $query, $page, $conversation );
}

return array();
}

/**
 * Show featured products.
 *
 * @param int   $page Page number.
 * @param mixed $conversation Conversation context.
 * @return array Array of message builder instances.
 */
public function showFeaturedProducts( int $page, $conversation ): array {
// Delegate to legacy implementation for now.
if ( class_exists( 'WCH_Catalog_Browser' ) ) {
$legacy = new \WCH_Catalog_Browser();
return $legacy->show_featured_products( $page, $conversation );
}

return array();
}

/**
 * Get products per page.
 *
 * @return int
 */
public function getProductsPerPage(): int {
return self::PRODUCTS_PER_PAGE;
}
}
