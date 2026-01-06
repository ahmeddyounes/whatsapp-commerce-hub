<?php
/**
 * WCH Action: Show Category
 *
 * Display products in a specific category.
 *
 * @package WhatsApp_Commerce_Hub
 * @subpackage Actions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Action_ShowCategory class
 *
 * Fetches and displays products in a category with pagination.
 * Shows product list with images, prices, and quick-add buttons.
 */
class WCH_Action_ShowCategory extends WCH_Flow_Action {
	/**
	 * Products per page
	 */
	const PRODUCTS_PER_PAGE = 10;

	/**
	 * Execute the action
	 *
	 * @param WCH_Conversation_Context $conversation Current conversation.
	 * @param array                    $context Action context.
	 * @param array                    $payload Event payload with category_id.
	 * @return WCH_Action_Result
	 */
	public function execute( $conversation, $context, $payload ) {
		try {
			// Validate category_id.
			if ( empty( $payload['category_id'] ) ) {
				return $this->error( 'Category not specified. Please select a category from the menu.' );
			}

			$category_id = intval( $payload['category_id'] );
			$page = isset( $payload['page'] ) ? intval( $payload['page'] ) : 1;

			$this->log(
				'Showing category',
				array(
					'category_id' => $category_id,
					'page'        => $page,
				)
			);

			// Get category.
			$category = get_term( $category_id, 'product_cat' );

			if ( ! $category || is_wp_error( $category ) ) {
				return $this->error( 'Category not found. Please try again.' );
			}

			// Get products in category.
			$products = $this->get_category_products( $category_id, $page );

			if ( empty( $products['items'] ) ) {
				return $this->show_empty_category( $category->name );
			}

			// Build product list message.
			$message = $this->build_product_list( $category, $products, $page );

			return WCH_Action_Result::success(
				array( $message ),
				null,
				array(
					'current_category' => $category_id,
					'current_page'     => $page,
				)
			);

		} catch ( Exception $e ) {
			$this->log( 'Error showing category', array( 'error' => $e->getMessage() ), 'error' );
			return $this->error( 'Sorry, we could not load the category. Please try again.' );
		}
	}

	/**
	 * Get products in category with pagination
	 *
	 * @param int $category_id Category ID.
	 * @param int $page Page number.
	 * @return array Products and pagination info.
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
		$total    = wc_get_products(
			array(
				'status'   => 'publish',
				'category' => array( $category_id ),
				'return'   => 'ids',
			)
		);

		return array(
			'items'       => $products,
			'total'       => count( $total ),
			'total_pages' => ceil( count( $total ) / self::PRODUCTS_PER_PAGE ),
		);
	}

	/**
	 * Build product list message
	 *
	 * @param WP_Term $category Category term.
	 * @param array   $products Products data.
	 * @param int     $page Current page.
	 * @return WCH_Message_Builder
	 */
	private function build_product_list( $category, $products, $page ) {
		$message = new WCH_Message_Builder();

		// Header with category name.
		$header = sprintf( '%s (%d products)', $category->name, $products['total'] );
		$message->header( $header );

		// Build product rows.
		$rows = array();

		foreach ( $products['items'] as $product ) {
			$price = $this->format_price( floatval( $product->get_price() ) );
			$stock_status = $product->is_in_stock() ? '✅' : '❌ Out of stock';

			$rows[] = array(
				'id'          => 'product_' . $product->get_id(),
				'title'       => wp_trim_words( $product->get_name(), 3, '...' ),
				'description' => sprintf( '%s | %s', $price, $stock_status ),
			);
		}

		$message->section( 'Products', $rows );

		// Add pagination buttons if needed.
		if ( $products['total_pages'] > 1 ) {
			$footer = sprintf( 'Page %d of %d', $page, $products['total_pages'] );
			$message->footer( $footer );

			if ( $page > 1 ) {
				$message->button(
					'reply',
					array(
						'id'    => 'prev_page_' . $category->term_id,
						'title' => '← Previous',
					)
				);
			}

			if ( $page < $products['total_pages'] ) {
				$message->button(
					'reply',
					array(
						'id'    => 'next_page_' . $category->term_id,
						'title' => 'Next →',
					)
				);
			}
		}

		// Back to menu button.
		$message->button(
			'reply',
			array(
				'id'    => 'back_to_menu',
				'title' => 'Main Menu',
			)
		);

		return $message;
	}

	/**
	 * Show empty category message
	 *
	 * @param string $category_name Category name.
	 * @return WCH_Action_Result
	 */
	private function show_empty_category( $category_name ) {
		$message = new WCH_Message_Builder();

		$text = sprintf(
			"No products found in %s.\n\nWould you like to browse other categories or search for products?",
			$category_name
		);

		$message->text( $text );
		$message->button(
			'reply',
			array(
				'id'    => 'back_to_menu',
				'title' => 'Main Menu',
			)
		);

		return WCH_Action_Result::success( array( $message ) );
	}
}
