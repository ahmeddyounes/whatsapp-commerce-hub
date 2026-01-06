<?php
/**
 * WCH Catalog Browser
 *
 * Interactive product browsing experience in WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Catalog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Catalog_Browser class
 *
 * Provides methods for browsing products in WhatsApp:
 * - Main menu with categories, featured, search, quick reorder
 * - Category browsing with pagination
 * - Product detail views
 * - Variant selection for variable products
 * - Product search
 * - Featured/on-sale products
 * - Image optimization
 */
class WCH_Catalog_Browser {
	/**
	 * Products per page
	 */
	const PRODUCTS_PER_PAGE = 10;

	/**
	 * Maximum product name length in list
	 */
	const MAX_PRODUCT_NAME_LENGTH = 50;

	/**
	 * Maximum product description length in list
	 */
	const MAX_PRODUCT_DESC_LENGTH = 72;

	/**
	 * Image thumbnail size for WhatsApp optimization
	 */
	const IMAGE_SIZE = 500;

	/**
	 * Show main menu
	 *
	 * @param WCH_Conversation_Context $conversation Conversation context.
	 * @return array Array of WCH_Message_Builder instances.
	 */
	public function show_main_menu( $conversation ) {
		$messages = array();

		// Create main menu message.
		$message = new WCH_Message_Builder();
		$message->body( 'Browse our catalog and discover amazing products!' );

		// Categories section - dynamically load from WooCommerce.
		$categories = $this->get_categories();
		if ( ! empty( $categories ) ) {
			$category_rows = array();
			foreach ( $categories as $category ) {
				$count = $this->get_category_product_count( $category->term_id );
				$category_rows[] = array(
					'id'          => 'category_' . $category->term_id,
					'title'       => $this->truncate_text( $category->name, self::MAX_PRODUCT_NAME_LENGTH ),
					'description' => sprintf( '%d products', $count ),
				);

				// Limit to 10 categories per section.
				if ( count( $category_rows ) >= 10 ) {
					break;
				}
			}

			if ( ! empty( $category_rows ) ) {
				$message->section( 'Categories', $category_rows );
			}
		}

		// Shopping options section.
		$shopping_rows = array(
			array(
				'id'          => 'featured_products',
				'title'       => '⭐ Featured Products',
				'description' => 'Check out our top picks',
			),
			array(
				'id'          => 'search_products',
				'title'       => '🔍 Search Products',
				'description' => 'Find what you need',
			),
		);

		// Quick reorder option for returning customers.
		if ( $this->is_returning_customer( $conversation->customer_phone ) ) {
			$shopping_rows[] = array(
				'id'          => 'quick_reorder',
				'title'       => '🔄 Quick Reorder',
				'description' => 'Reorder from previous purchases',
			);
		}

		$message->section( 'Shopping', $shopping_rows );

		// Back to main menu button.
		$message->button( 'reply', array( 'id' => 'main_menu', 'title' => 'Main Menu' ) );

		$messages[] = $message;

		$this->log( 'Main catalog menu shown', array( 'phone' => $conversation->customer_phone ) );

		return $messages;
	}

	/**
	 * Show category products with pagination
	 *
	 * @param WCH_Conversation_Context $conversation Conversation context.
	 * @param int                      $category_id Category ID.
	 * @param int                      $page Page number (default 1).
	 * @return array Array of WCH_Message_Builder instances.
	 */
	public function show_category( $conversation, $category_id, $page = 1 ) {
		$messages = array();

		// Validate category.
		$category = get_term( $category_id, 'product_cat' );
		if ( ! $category || is_wp_error( $category ) ) {
			$error_message = new WCH_Message_Builder();
			$error_message->text( 'Category not found. Please try again.' );
			return array( $error_message );
		}

		// Get products with pagination.
		$products_data = $this->get_category_products( $category_id, $page );

		if ( empty( $products_data['items'] ) ) {
			$empty_message = new WCH_Message_Builder();
			$empty_message->text( sprintf( 'No products found in %s. Browse other categories?', $category->name ) );
			$empty_message->button( 'reply', array( 'id' => 'browse_catalog', 'title' => 'Browse Catalog' ) );
			return array( $empty_message );
		}

		// Build product list message.
		$message = new WCH_Message_Builder();

		// Header with category name.
		$header = sprintf( '%s (%d products)', $category->name, $products_data['total'] );
		$message->header( $this->truncate_text( $header, 60 ) );

		// Body with product count.
		$body = sprintf(
			'Showing %d-%d of %d products',
			( ( $page - 1 ) * self::PRODUCTS_PER_PAGE ) + 1,
			min( $page * self::PRODUCTS_PER_PAGE, $products_data['total'] ),
			$products_data['total']
		);
		$message->body( $body );

		// Group products by subcategory or alphabetically.
		$grouped_products = $this->group_products( $products_data['items'], $category_id );

		foreach ( $grouped_products as $group_name => $group_products ) {
			$rows = array();
			foreach ( $group_products as $product ) {
				$rows[] = $this->format_product_row( $product );

				// Limit to 10 products per section.
				if ( count( $rows ) >= 10 ) {
					break;
				}
			}

			if ( ! empty( $rows ) ) {
				$message->section( $group_name, $rows );
			}

			// If we've hit the limit, break out.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		// Navigation buttons.
		if ( $products_data['total_pages'] > 1 ) {
			$footer = sprintf( 'Page %d of %d', $page, $products_data['total_pages'] );
			$message->footer( $this->truncate_text( $footer, 60 ) );
		}

		// Previous Page button.
		if ( $page > 1 ) {
			$message->button(
				'reply',
				array(
					'id'    => 'prev_page_' . $category_id . '_' . ( $page - 1 ),
					'title' => '← Previous',
				)
			);
		}

		// Next Page button.
		if ( $page < $products_data['total_pages'] ) {
			$message->button(
				'reply',
				array(
					'id'    => 'next_page_' . $category_id . '_' . ( $page + 1 ),
					'title' => 'Next →',
				)
			);
		}

		// Back to Categories button.
		$message->button(
			'reply',
			array(
				'id'    => 'back_to_categories',
				'title' => 'Categories',
			)
		);

		$messages[] = $message;

		$this->log(
			'Category products shown',
			array(
				'category_id' => $category_id,
				'page'        => $page,
				'count'       => count( $products_data['items'] ),
			)
		);

		return $messages;
	}

	/**
	 * Show product detail
	 *
	 * @param WCH_Conversation_Context $conversation Conversation context.
	 * @param int                      $product_id Product ID.
	 * @return array Array of WCH_Message_Builder instances.
	 */
	public function show_product_detail( $conversation, $product_id ) {
		$messages = array();

		// Get product.
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			$error_message = new WCH_Message_Builder();
			$error_message->text( 'Product not found. Please try another product.' );
			return array( $error_message );
		}

		// 1. Image message with main product photo.
		$image_url = $this->get_optimized_product_image( $product );
		if ( $image_url ) {
			$image_message = new WCH_Message_Builder();
			// Note: In production, use actual WhatsApp media API.
			$image_message->text( sprintf( '📷 %s', $image_url ) );
			$messages[] = $image_message;
		}

		// 2. Text message with name, description, price, availability.
		$detail_message = new WCH_Message_Builder();
		$detail_message->header( $this->truncate_text( $product->get_name(), 60 ) );

		// Full description.
		$description = $this->get_product_description( $product );
		$detail_message->body( $description );

		// Price and availability.
		$footer = $this->format_price_availability( $product );
		$detail_message->footer( $this->truncate_text( $footer, 60 ) );

		$messages[] = $detail_message;

		// 3. Text message with variants if variable product.
		if ( $product->is_type( 'variable' ) ) {
			$variant_message = $this->build_variant_overview( $product );
			if ( $variant_message ) {
				$messages[] = $variant_message;
			}
		}

		// 4. Interactive buttons: Add to Cart, View More Images, Back.
		$button_message = new WCH_Message_Builder();
		$button_message->body( 'What would you like to do?' );

		// Add to Cart button (for simple products).
		if ( $product->is_type( 'simple' ) && $product->is_in_stock() ) {
			$button_message->button(
				'reply',
				array(
					'id'    => 'add_to_cart_' . $product_id,
					'title' => '🛒 Add to Cart',
				)
			);
		} elseif ( $product->is_type( 'variable' ) ) {
			$button_message->button(
				'reply',
				array(
					'id'    => 'select_variant_' . $product_id,
					'title' => '🎨 Select Options',
				)
			);
		}

		// View More Images button (if multiple images).
		$gallery_ids = $product->get_gallery_image_ids();
		if ( ! empty( $gallery_ids ) ) {
			$button_message->button(
				'reply',
				array(
					'id'    => 'view_images_' . $product_id,
					'title' => '📸 More Images',
				)
			);
		}

		// Back to Browsing button.
		$button_message->button(
			'reply',
			array(
				'id'    => 'back_to_browsing',
				'title' => '← Back',
			)
		);

		$messages[] = $button_message;

		$this->log( 'Product detail shown', array( 'product_id' => $product_id ) );

		return $messages;
	}

	/**
	 * Show variant selector for variable product
	 *
	 * @param WCH_Conversation_Context $conversation Conversation context.
	 * @param int                      $product_id Product ID.
	 * @param string                   $attribute Attribute to select (optional).
	 * @return array Array of WCH_Message_Builder instances.
	 */
	public function show_variant_selector( $conversation, $product_id, $attribute = null ) {
		$messages = array();

		// Get product.
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			$error_message = new WCH_Message_Builder();
			$error_message->text( 'Product not found or has no variants.' );
			return array( $error_message );
		}

		// If no attribute specified, show first attribute.
		if ( ! $attribute ) {
			$attributes = $product->get_variation_attributes();
			if ( empty( $attributes ) ) {
				$error_message = new WCH_Message_Builder();
				$error_message->text( 'No variants available for this product.' );
				return array( $error_message );
			}

			// Get first attribute.
			$attribute = array_key_first( $attributes );
		}

		// Get available options for this attribute.
		$attributes = $product->get_variation_attributes();
		if ( ! isset( $attributes[ $attribute ] ) ) {
			$error_message = new WCH_Message_Builder();
			$error_message->text( 'Attribute not found.' );
			return array( $error_message );
		}

		$options = $attributes[ $attribute ];

		// Build list message with options.
		$message = new WCH_Message_Builder();
		$message->body( sprintf( 'Select %s:', $this->format_attribute_name( $attribute ) ) );

		$rows = array();
		foreach ( $options as $index => $option ) {
			$rows[] = array(
				'id'          => 'attr_' . $product_id . '_' . sanitize_title( $attribute ) . '_' . sanitize_title( $option ),
				'title'       => $this->truncate_text( $option, self::MAX_PRODUCT_NAME_LENGTH ),
				'description' => sprintf( 'Option %d', $index + 1 ),
			);

			// Limit to 10 options.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		$message->section( 'Available Options', $rows );

		// Back button.
		$message->button(
			'reply',
			array(
				'id'    => 'back_to_product_' . $product_id,
				'title' => '← Back to Product',
			)
		);

		$messages[] = $message;

		$this->log(
			'Variant selector shown',
			array(
				'product_id' => $product_id,
				'attribute'  => $attribute,
			)
		);

		return $messages;
	}

	/**
	 * Search products by name and SKU
	 *
	 * @param WCH_Conversation_Context $conversation Conversation context.
	 * @param string                   $query Search query.
	 * @return array Array of WCH_Message_Builder instances.
	 */
	public function search_products( $conversation, $query ) {
		$messages = array();

		// Search WooCommerce products.
		$products = $this->perform_product_search( $query );

		if ( empty( $products ) ) {
			// No matches - suggest categories.
			$message = new WCH_Message_Builder();
			$message->body( sprintf( 'No products found for "%s".', esc_html( $query ) ) );

			// Suggest categories.
			$categories = $this->get_categories( 5 );
			if ( ! empty( $categories ) ) {
				$message->body( $message->body . "\n\nBrowse these categories instead:" );

				$category_rows = array();
				foreach ( $categories as $category ) {
					$count = $this->get_category_product_count( $category->term_id );
					$category_rows[] = array(
						'id'          => 'category_' . $category->term_id,
						'title'       => $this->truncate_text( $category->name, self::MAX_PRODUCT_NAME_LENGTH ),
						'description' => sprintf( '%d products', $count ),
					);
				}

				if ( ! empty( $category_rows ) ) {
					$message->section( 'Suggested Categories', $category_rows );
				}
			}

			$message->button( 'reply', array( 'id' => 'browse_catalog', 'title' => 'Browse Catalog' ) );
			$messages[] = $message;

			$this->log( 'Product search - no results', array( 'query' => $query ) );

			return $messages;
		}

		// Build search results message.
		$message = new WCH_Message_Builder();
		$message->header( sprintf( 'Search: "%s"', $this->truncate_text( $query, 40 ) ) );
		$message->body( sprintf( 'Found %d result%s', count( $products ), count( $products ) === 1 ? '' : 's' ) );

		// Build product rows.
		$rows = array();
		foreach ( $products as $product ) {
			$rows[] = $this->format_product_row( $product );

			// Limit to 10 results.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		$message->section( 'Results', $rows );

		// Back button.
		$message->button( 'reply', array( 'id' => 'browse_catalog', 'title' => 'Browse Catalog' ) );

		$messages[] = $message;

		$this->log(
			'Product search completed',
			array(
				'query'   => $query,
				'results' => count( $products ),
			)
		);

		return $messages;
	}

	/**
	 * Show featured products
	 *
	 * @param WCH_Conversation_Context $conversation Conversation context.
	 * @return array Array of WCH_Message_Builder instances.
	 */
	public function show_featured( $conversation ) {
		$messages = array();

		// Query products with _featured meta or on_sale.
		$featured_products = $this->get_featured_products();

		if ( empty( $featured_products ) ) {
			$message = new WCH_Message_Builder();
			$message->text( 'No featured products available at the moment. Browse our catalog?' );
			$message->button( 'reply', array( 'id' => 'browse_catalog', 'title' => 'Browse Catalog' ) );
			$messages[] = $message;
			return $messages;
		}

		// Build featured products message.
		$message = new WCH_Message_Builder();
		$message->header( '⭐ Featured Products' );
		$message->body( 'Check out our handpicked selection of amazing products!' );

		// Build product rows.
		$rows = array();
		foreach ( $featured_products as $product ) {
			$rows[] = $this->format_product_row( $product );

			// Limit to 10 products.
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}

		$message->section( 'Featured', $rows );

		// Back button.
		$message->button( 'reply', array( 'id' => 'browse_catalog', 'title' => 'Browse Catalog' ) );

		$messages[] = $message;

		$this->log( 'Featured products shown', array( 'count' => count( $featured_products ) ) );

		return $messages;
	}

	/**
	 * Get categories from WooCommerce
	 *
	 * @param int $limit Limit number of categories (default: no limit).
	 * @return array Array of WP_Term objects.
	 */
	private function get_categories( $limit = 0 ) {
		$args = array(
			'taxonomy'   => 'product_cat',
			'orderby'    => 'name',
			'order'      => 'ASC',
			'hide_empty' => true,
		);

		if ( $limit > 0 ) {
			$args['number'] = $limit;
		}

		$categories = get_terms( $args );

		if ( is_wp_error( $categories ) ) {
			return array();
		}

		return $categories;
	}

	/**
	 * Get product count for category
	 *
	 * @param int $category_id Category ID.
	 * @return int Product count.
	 */
	private function get_category_product_count( $category_id ) {
		$args = array(
			'status'   => 'publish',
			'category' => array( $category_id ),
			'return'   => 'ids',
		);

		$products = wc_get_products( $args );

		return count( $products );
	}

	/**
	 * Check if customer is returning
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if returning customer.
	 */
	private function is_returning_customer( $phone ) {
		global $wpdb;

		// Check if customer has previous orders.
		$order_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wch_orders WHERE customer_phone = %s AND status IN ('completed', 'processing')",
				$phone
			)
		);

		return $order_count > 0;
	}

	/**
	 * Get category products with pagination
	 *
	 * @param int $category_id Category ID.
	 * @param int $page Page number.
	 * @return array Products data with pagination info.
	 */
	private function get_category_products( $category_id, $page ) {
		$args = array(
			'status'   => 'publish',
			'category' => array( $category_id ),
			'limit'    => self::PRODUCTS_PER_PAGE,
			'page'     => $page,
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
		);

		$products = wc_get_products( $args );

		// Get total count.
		$total_args = array(
			'status'   => 'publish',
			'category' => array( $category_id ),
			'return'   => 'ids',
		);
		$total = wc_get_products( $total_args );

		return array(
			'items'       => $products,
			'total'       => count( $total ),
			'total_pages' => ceil( count( $total ) / self::PRODUCTS_PER_PAGE ),
		);
	}

	/**
	 * Group products by subcategory or alphabetically
	 *
	 * @param array $products Array of WC_Product objects.
	 * @param int   $parent_category_id Parent category ID.
	 * @return array Grouped products.
	 */
	private function group_products( $products, $parent_category_id ) {
		$grouped = array();

		// Try to group by subcategory.
		$has_subcategories = false;

		foreach ( $products as $product ) {
			$categories = wp_get_post_terms( $product->get_id(), 'product_cat' );
			$subcategory_name = 'Products';

			// Find subcategory under parent.
			foreach ( $categories as $category ) {
				if ( $category->parent === $parent_category_id ) {
					$subcategory_name = $category->name;
					$has_subcategories = true;
					break;
				}
			}

			if ( ! isset( $grouped[ $subcategory_name ] ) ) {
				$grouped[ $subcategory_name ] = array();
			}

			$grouped[ $subcategory_name ][] = $product;
		}

		// If no subcategories found, group alphabetically.
		if ( ! $has_subcategories ) {
			$grouped = array( 'Products' => $products );
		}

		return $grouped;
	}

	/**
	 * Format product for list row
	 *
	 * @param WC_Product $product Product object.
	 * @return array Product row data.
	 */
	private function format_product_row( $product ) {
		$price = wc_price( floatval( $product->get_price() ) );
		$stock_status = $product->is_in_stock() ? '✅' : '❌ Out of stock';

		// Get short description.
		$description = $product->get_short_description();
		if ( empty( $description ) ) {
			$description = $product->get_description();
		}
		$description = wp_strip_all_tags( $description );
		$description = $this->truncate_text( $description, 50 );

		// Combine price and stock.
		$desc_text = sprintf( '%s | %s', wp_strip_all_tags( $price ), $stock_status );
		if ( ! empty( $description ) ) {
			$desc_text = $this->truncate_text( $description . ' | ' . $desc_text, self::MAX_PRODUCT_DESC_LENGTH );
		}

		return array(
			'id'          => 'product_' . $product->get_id(),
			'title'       => $this->truncate_text( $product->get_name(), self::MAX_PRODUCT_NAME_LENGTH ),
			'description' => $desc_text,
		);
	}

	/**
	 * Get optimized product image URL
	 *
	 * @param WC_Product $product Product object.
	 * @return string|null Optimized image URL or null.
	 */
	private function get_optimized_product_image( $product ) {
		$image_id = $product->get_image_id();
		if ( ! $image_id ) {
			return null;
		}

		// Check if we have cached optimized image.
		$cached_url = get_post_meta( $image_id, '_wch_optimized_image_url', true );
		if ( $cached_url ) {
			return $cached_url;
		}

		// Generate WhatsApp-optimized thumbnail (500x500).
		$image_url = $this->generate_optimized_image( $image_id );

		// Cache URL.
		if ( $image_url ) {
			update_post_meta( $image_id, '_wch_optimized_image_url', $image_url );
		}

		return $image_url;
	}

	/**
	 * Generate WhatsApp-optimized image
	 *
	 * @param int $image_id Image attachment ID.
	 * @return string|null Optimized image URL or null.
	 */
	private function generate_optimized_image( $image_id ) {
		// Use WordPress image resize functionality.
		$image_data = wp_get_attachment_image_src( $image_id, array( self::IMAGE_SIZE, self::IMAGE_SIZE ) );

		if ( ! $image_data ) {
			// Fall back to full image.
			$image_data = wp_get_attachment_image_src( $image_id, 'full' );
		}

		return $image_data ? $image_data[0] : null;
	}

	/**
	 * Get product description
	 *
	 * @param WC_Product $product Product object.
	 * @return string Product description.
	 */
	private function get_product_description( $product ) {
		$description = $product->get_description();

		if ( empty( $description ) ) {
			$description = $product->get_short_description();
		}

		if ( empty( $description ) ) {
			$description = 'No description available.';
		}

		// Strip HTML and limit length for WhatsApp.
		$description = wp_strip_all_tags( $description );
		$description = wp_trim_words( $description, 150, '...' );

		// Ensure within WhatsApp body limit (1024 chars).
		if ( strlen( $description ) > 1000 ) {
			$description = substr( $description, 0, 997 ) . '...';
		}

		return $description;
	}

	/**
	 * Format price and availability
	 *
	 * @param WC_Product $product Product object.
	 * @return string Formatted price and availability.
	 */
	private function format_price_availability( $product ) {
		// Price (highlight sale price).
		$regular_price = $product->get_regular_price();
		$sale_price = $product->get_sale_price();

		if ( $sale_price ) {
			$price_text = sprintf(
				'%s (was %s)',
				wc_price( floatval( $sale_price ) ),
				wc_price( floatval( $regular_price ) )
			);
		} else {
			$price_text = wc_price( floatval( $product->get_price() ) );
		}

		// Availability.
		if ( $product->is_in_stock() ) {
			$stock = '✅ In Stock';

			if ( $product->managing_stock() ) {
				$qty = $product->get_stock_quantity();
				if ( $qty < 10 ) {
					$stock = sprintf( '⚠️ Only %d left', $qty );
				}
			}
		} else {
			$stock = '❌ Out of Stock';
		}

		return sprintf( '%s | %s', wp_strip_all_tags( $price_text ), $stock );
	}

	/**
	 * Build variant overview message
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return WCH_Message_Builder|null Variant message or null.
	 */
	private function build_variant_overview( $product ) {
		$variations = $product->get_available_variations();
		if ( empty( $variations ) ) {
			return null;
		}

		$message = new WCH_Message_Builder();

		// Build variant list as numbered text.
		$variant_text = "Available variants:\n\n";

		$count = 0;
		foreach ( $variations as $variation ) {
			$count++;
			$variation_obj = wc_get_product( $variation['variation_id'] );

			if ( ! $variation_obj ) {
				continue;
			}

			$attributes = array();
			foreach ( $variation['attributes'] as $attr_value ) {
				$attributes[] = $attr_value;
			}

			$variant_name = implode( ', ', $attributes );
			$variant_price = wc_price( floatval( $variation_obj->get_price() ) );
			$in_stock = $variation_obj->is_in_stock() ? '✅' : '❌';

			$variant_text .= sprintf(
				"%d. %s - %s %s\n",
				$count,
				$variant_name,
				wp_strip_all_tags( $variant_price ),
				$in_stock
			);

			// Limit to 10 variants.
			if ( $count >= 10 ) {
				if ( count( $variations ) > 10 ) {
					$variant_text .= sprintf( "\n...and %d more variants", count( $variations ) - 10 );
				}
				break;
			}
		}

		$message->text( $variant_text );

		return $message;
	}

	/**
	 * Perform product search
	 *
	 * @param string $query Search query.
	 * @return array Array of WC_Product objects (top 10).
	 */
	private function perform_product_search( $query ) {
		// Search by name and SKU.
		$args = array(
			'status' => 'publish',
			'limit'  => 10,
		);

		// Use WooCommerce product search.
		$data_store = WC_Data_Store::load( 'product' );
		$product_ids = $data_store->search_products( $query, '', true );

		if ( empty( $product_ids ) ) {
			return array();
		}

		// Limit to top 10.
		$product_ids = array_slice( $product_ids, 0, 10 );

		// Get product objects.
		$products = array();
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product && $product->is_visible() ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * Get featured products
	 *
	 * @return array Array of WC_Product objects.
	 */
	private function get_featured_products() {
		// Get featured products.
		$featured_args = array(
			'status'   => 'publish',
			'featured' => true,
			'limit'    => 10,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$featured = wc_get_products( $featured_args );

		// If not enough featured, get on-sale products.
		if ( count( $featured ) < 10 ) {
			$on_sale_args = array(
				'status'  => 'publish',
				'limit'   => 10 - count( $featured ),
				'orderby' => 'date',
				'order'   => 'DESC',
			);

			$on_sale = wc_get_products( $on_sale_args );

			// Filter to only on-sale products.
			$on_sale = array_filter(
				$on_sale,
				function ( $product ) {
					return $product->is_on_sale();
				}
			);

			$featured = array_merge( $featured, $on_sale );
		}

		return $featured;
	}

	/**
	 * Format attribute name
	 *
	 * @param string $attribute Raw attribute name.
	 * @return string Formatted attribute name.
	 */
	private function format_attribute_name( $attribute ) {
		// Remove 'pa_' prefix if present.
		$attribute = str_replace( 'pa_', '', $attribute );

		// Convert to title case.
		$attribute = str_replace( array( '_', '-' ), ' ', $attribute );
		$attribute = ucwords( $attribute );

		return $attribute;
	}

	/**
	 * Truncate text to specified length
	 *
	 * @param string $text Text to truncate.
	 * @param int    $length Maximum length.
	 * @return string Truncated text.
	 */
	private function truncate_text( $text, $length ) {
		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, $length - 3 ) . '...';
	}

	/**
	 * Log message
	 *
	 * @param string $message Log message.
	 * @param array  $data Additional data.
	 * @param string $level Log level.
	 */
	private function log( $message, $data = array(), $level = 'info' ) {
		WCH_Logger::log(
			'WCH_Catalog_Browser: ' . $message,
			$data,
			$level
		);
	}
}
