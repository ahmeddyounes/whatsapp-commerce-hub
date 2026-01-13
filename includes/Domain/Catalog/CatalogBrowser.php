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

use WhatsAppCommerceHub\Actions\ActionRegistry;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;
use WhatsAppCommerceHub\ValueObjects\ConversationContext;

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
		return $this->executeAction( 'show_main_menu', $conversation, [] );
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
		return $this->executeAction(
			'show_category',
			$conversation,
			[
				'category_id' => $categoryId,
				'page'        => $page,
			]
		);
	}

	/**
	 * Show product details.
	 *
	 * @param int   $productId Product ID.
	 * @param mixed $conversation Conversation context.
	 * @return array Array of message builder instances.
	 */
	public function showProduct( int $productId, $conversation ): array {
		return $this->executeAction(
			'show_product',
			$conversation,
			[ 'product_id' => $productId ]
		);
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
		$products = wc_get_products(
			[
				'status' => 'publish',
				'limit'  => self::PRODUCTS_PER_PAGE,
				'page'   => max( 1, $page ),
				's'      => $query,
			]
		);

		return $this->buildProductList(
			$products,
			empty( $query )
				? __( 'Please enter a search term to find products.', 'whatsapp-commerce-hub' )
				: __( 'No products found for your search.', 'whatsapp-commerce-hub' ),
			__( 'Search Results', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Show featured products.
	 *
	 * @param int   $page Page number.
	 * @param mixed $conversation Conversation context.
	 * @return array Array of message builder instances.
	 */
	public function showFeaturedProducts( int $page, $conversation ): array {
		$products = wc_get_products(
			[
				'status'   => 'publish',
				'limit'    => self::PRODUCTS_PER_PAGE,
				'page'     => max( 1, $page ),
				'featured' => true,
			]
		);

		return $this->buildProductList(
			$products,
			__( 'No featured products available right now.', 'whatsapp-commerce-hub' ),
			__( 'Featured Products', 'whatsapp-commerce-hub' )
		);
	}

	/**
	 * Get products per page.
	 *
	 * @return int
	 */
	public function getProductsPerPage(): int {
		return self::PRODUCTS_PER_PAGE;
	}

	/**
	 * Execute a registered action handler and return message builders.
	 *
	 * @param string $actionName Action name.
	 * @param mixed  $conversation Conversation context.
	 * @param array  $params Action parameters.
	 * @return array
	 */
	private function executeAction( string $actionName, $conversation, array $params ): array {
		$phone = $this->getConversationPhone( $conversation );
		if ( '' === $phone ) {
			return [];
		}

		$context   = $this->buildConversationContext( $conversation );
		$registry  = wch( ActionRegistry::class );
		$actionRes = $registry->execute( $actionName, $phone, $params, $context );

		return $actionRes ? $actionRes->getMessages() : [];
	}

	/**
	 * Build a conversation context value object from stored context data.
	 *
	 * @param mixed $conversation Conversation context.
	 * @return ConversationContext
	 */
	private function buildConversationContext( $conversation ): ConversationContext {
		$contextData = [];

		if ( is_array( $conversation ) ) {
			$contextData = $conversation['context'] ?? $conversation['conversation_context'] ?? [];
		} elseif ( is_object( $conversation ) ) {
			$contextData = $conversation->context ?? $conversation->conversation_context ?? [];
		}

		if ( is_string( $contextData ) ) {
			$decoded = json_decode( $contextData, true );
			$contextData = is_array( $decoded ) ? $decoded : [];
		}

		return new ConversationContext( is_array( $contextData ) ? $contextData : [] );
	}

	/**
	 * Extract a phone number from the conversation object/array.
	 *
	 * @param mixed $conversation Conversation context.
	 * @return string
	 */
	private function getConversationPhone( $conversation ): string {
		if ( is_array( $conversation ) ) {
			return (string) ( $conversation['customer_phone'] ?? $conversation['phone'] ?? '' );
		}

		if ( is_object( $conversation ) ) {
			return (string) ( $conversation->customer_phone ?? $conversation->phone ?? '' );
		}

		return '';
	}

	/**
	 * Build a simple product list message.
	 *
	 * @param array  $products Product list.
	 * @param string $emptyMessage Message when empty.
	 * @param string $title List title.
	 * @return array
	 */
	private function buildProductList( array $products, string $emptyMessage, string $title ): array {
		if ( empty( $products ) ) {
			return [ ( new MessageBuilder() )->text( $emptyMessage ) ];
		}

		$lines = [];
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$lines[] = sprintf(
				'%s - %s',
				$product->get_name(),
				wc_price( (float) $product->get_price() )
			);
		}

		$message = new MessageBuilder();
		$message->text( $title . "\n\n" . implode( "\n", $lines ) );

		return [ $message ];
	}
}
