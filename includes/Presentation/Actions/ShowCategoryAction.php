<?php
/**
 * Show Category Action
 *
 * Displays products in a specific category.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Actions;

use WhatsAppCommerceHub\ValueObjects\ActionResult;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShowCategoryAction
 *
 * Fetches and displays products in a category with pagination.
 */
class ShowCategoryAction extends AbstractAction {

	/**
	 * Action name.
	 *
	 * @var string
	 */
	protected string $name = 'show_category';

	/**
	 * Products per page.
	 *
	 * @var int
	 */
	private const PRODUCTS_PER_PAGE = 10;

	/**
	 * Execute the action.
	 *
	 * @param string              $phone   Customer phone number.
	 * @param array               $params  Action parameters with category_id and optional page.
	 * @param ConversationContext $context Conversation context.
	 * @return ActionResult
	 */
	public function handle( string $phone, array $params, ConversationContext $context ): ActionResult {
		try {
			if ( empty( $params['category_id'] ) ) {
				return $this->error( __( 'Category not specified. Please select a category from the menu.', 'whatsapp-commerce-hub' ) );
			}

			$categoryId = (int) $params['category_id'];
			$page       = ! empty( $params['page'] ) ? (int) $params['page'] : 1;

			$this->log(
				'Showing category',
				[
					'category_id' => $categoryId,
					'page'        => $page,
				]
			);

			// Get category.
			$category = get_term( $categoryId, 'product_cat' );

			if ( ! $category || is_wp_error( $category ) ) {
				return $this->error( __( 'Category not found. Please try again.', 'whatsapp-commerce-hub' ) );
			}

			// Get products in category.
			$products = $this->getCategoryProducts( $categoryId, $page );

			if ( empty( $products['items'] ) ) {
				return $this->showEmptyCategory( $category->name );
			}

			// Build product list message.
			$message = $this->buildProductList( $category, $products, $page );

			return ActionResult::success(
				[ $message ],
				null,
				[
					'current_category' => $categoryId,
					'current_page'     => $page,
				]
			);

		} catch ( \Exception $e ) {
			$this->log( 'Error showing category', [ 'error' => $e->getMessage() ], 'error' );
			return $this->error( __( 'Sorry, we could not load the category. Please try again.', 'whatsapp-commerce-hub' ) );
		}
	}

	/**
	 * Get products in category with pagination.
	 *
	 * @param int $categoryId Category ID.
	 * @param int $page       Page number.
	 * @return array Products and pagination info.
	 */
	private function getCategoryProducts( int $categoryId, int $page ): array {
		$args = [
			'status'   => 'publish',
			'category' => [ $categoryId ],
			'limit'    => self::PRODUCTS_PER_PAGE,
			'page'     => $page,
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
		];

		$products = wc_get_products( $args );
		$total    = wc_get_products(
			[
				'status'   => 'publish',
				'category' => [ $categoryId ],
				'return'   => 'ids',
			]
		);

		return [
			'items'       => $products,
			'total'       => count( $total ),
			'total_pages' => (int) ceil( count( $total ) / self::PRODUCTS_PER_PAGE ),
		];
	}

	/**
	 * Build product list message.
	 *
	 * @param \WP_Term $category Category term.
	 * @param array    $products Products data.
	 * @param int      $page     Current page.
	 * @return \WCH_Message_Builder
	 */
	private function buildProductList( \WP_Term $category, array $products, int $page ): \WCH_Message_Builder {
		$message = $this->createMessageBuilder();

		// Header with category name.
		$header = sprintf(
			'%s (%d %s)',
			$category->name,
			$products['total'],
			_n( 'product', 'products', $products['total'], 'whatsapp-commerce-hub' )
		);
		$message->header( $header );

		// Build product rows.
		$rows = [];

		foreach ( $products['items'] as $product ) {
			$price       = $this->formatPrice( (float) $product->get_price() );
			$stockStatus = $product->is_in_stock()
				? '✅'
				: __( '❌ Out of stock', 'whatsapp-commerce-hub' );

			$rows[] = [
				'id'          => 'product_' . $product->get_id(),
				'title'       => wp_trim_words( $product->get_name(), 3, '...' ),
				'description' => sprintf( '%s | %s', $price, $stockStatus ),
			];
		}

		$message->section( __( 'Products', 'whatsapp-commerce-hub' ), $rows );

		// Add pagination if needed.
		if ( $products['total_pages'] > 1 ) {
			$footer = sprintf(
				__( 'Page %1$d of %2$d', 'whatsapp-commerce-hub' ),
				$page,
				$products['total_pages']
			);
			$message->footer( $footer );

			if ( $page > 1 ) {
				$message->button(
					'reply',
					[
						'id'    => 'prev_page_' . $category->term_id,
						'title' => __( '← Previous', 'whatsapp-commerce-hub' ),
					]
				);
			}

			if ( $page < $products['total_pages'] ) {
				$message->button(
					'reply',
					[
						'id'    => 'next_page_' . $category->term_id,
						'title' => __( 'Next →', 'whatsapp-commerce-hub' ),
					]
				);
			}
		}

		// Back to menu button.
		$message->button(
			'reply',
			[
				'id'    => 'back_to_menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			]
		);

		return $message;
	}

	/**
	 * Show empty category message.
	 *
	 * @param string $categoryName Category name.
	 * @return ActionResult
	 */
	private function showEmptyCategory( string $categoryName ): ActionResult {
		$message = $this->createMessageBuilder();

		$text = sprintf(
			"%s\n\n%s",
			sprintf( __( 'No products found in %s.', 'whatsapp-commerce-hub' ), $categoryName ),
			__( 'Would you like to browse other categories or search for products?', 'whatsapp-commerce-hub' )
		);

		$message->text( $text );

		$message->button(
			'reply',
			[
				'id'    => 'back_to_menu',
				'title' => __( 'Main Menu', 'whatsapp-commerce-hub' ),
			]
		);

		return ActionResult::success( [ $message ] );
	}
}
