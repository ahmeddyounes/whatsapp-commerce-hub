<?php

/**
 * Reengagement Message Builder
 *
 * Builds personalized re-engagement messages for customers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementMessageBuilderInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ProductTrackingServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\LoyaltyCouponGeneratorInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReengagementMessageBuilder
 *
 * Builds campaign messages based on type.
 */
class ReengagementMessageBuilder implements ReengagementMessageBuilderInterface {

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Product tracking service.
	 *
	 * @var ProductTrackingServiceInterface
	 */
	protected ProductTrackingServiceInterface $productTracking;

	/**
	 * Loyalty coupon generator.
	 *
	 * @var LoyaltyCouponGeneratorInterface
	 */
	protected LoyaltyCouponGeneratorInterface $loyaltyGenerator;

	/**
	 * Customer service.
	 *
	 * @var \WCH_Customer_Service
	 */
	protected \WCH_Customer_Service $customerService;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface               $settings         Settings service.
	 * @param ProductTrackingServiceInterface $productTracking  Product tracking.
	 * @param LoyaltyCouponGeneratorInterface $loyaltyGenerator Loyalty generator.
	 */
	public function __construct(
		SettingsInterface $settings,
		ProductTrackingServiceInterface $productTracking,
		LoyaltyCouponGeneratorInterface $loyaltyGenerator
	) {
		$this->settings         = $settings;
		$this->productTracking  = $productTracking;
		$this->loyaltyGenerator = $loyaltyGenerator;
		$this->customerService  = \WCH_Customer_Service::instance();
	}

	/**
	 * Build a campaign message for a customer.
	 *
	 * @param object $customer Customer profile object.
	 * @param string $campaignType Campaign type.
	 * @return array|null Message data with 'text' and 'type' keys, or null on failure.
	 */
	public function build( object $customer, string $campaignType ): ?array {
		$customerName = $customer->name ?: __( 'Customer', 'whatsapp-commerce-hub' );
		$shopUrl      = home_url( '/shop' );

		switch ( $campaignType ) {
			case 'we_miss_you':
				$text = $this->buildWeMissYouMessage( $customerName, $shopUrl );
				break;

			case 'new_arrivals':
				$newProducts = $this->getNewArrivalsForCustomer( $customer );
				$text        = $this->buildNewArrivalsMessage( $customerName, $newProducts, $shopUrl );
				break;

			case 'back_in_stock':
				$products = $this->productTracking->getBackInStockProducts( $customer->phone );
				$text     = $this->buildBackInStockMessage( $customerName, $products, $shopUrl );
				break;

			case 'price_drop':
				$products = $this->productTracking->getPriceDropProducts( $customer->phone );
				$text     = $this->buildPriceDropMessage( $customerName, $products, $shopUrl );
				break;

			case 'loyalty_reward':
				$couponCode = $this->loyaltyGenerator->generate( $customer );
				$text       = $this->buildLoyaltyRewardMessage(
					$customerName,
					$couponCode,
					$this->loyaltyGenerator->getDiscountAmount(),
					$shopUrl
				);
				break;

			default:
				return null;
		}

		return array(
			'text' => $text,
			'type' => $campaignType,
		);
	}

	/**
	 * Build "We Miss You" message.
	 *
	 * @param string $customerName Customer name.
	 * @param string $shopUrl Shop URL.
	 * @return string Message text.
	 */
	protected function buildWeMissYouMessage( string $customerName, string $shopUrl ): string {
		return sprintf(
			/* translators: 1: Customer name, 2: Shop URL */
			__(
				"Hi %1\$s! We haven't seen you in a while and we miss you! 😊\n\n" .
				'We have some exciting new products you might love. Check them out: %2$s',
				'whatsapp-commerce-hub'
			),
			$customerName,
			$shopUrl
		);
	}

	/**
	 * Build new arrivals message.
	 *
	 * @param string $customerName Customer name.
	 * @param array  $products Products array.
	 * @param string $shopUrl Shop URL.
	 * @return string Message text.
	 */
	protected function buildNewArrivalsMessage( string $customerName, array $products, string $shopUrl ): string {
		return sprintf(
			/* translators: 1: Customer name, 2: Product list, 3: Shop URL */
			__(
				"Hi %1\$s! 🎉 We've added new products based on your interests:\n\n%2\$s\n\nBrowse all new arrivals: %3\$s",
				'whatsapp-commerce-hub'
			),
			$customerName,
			$this->formatProductList( $products ),
			$shopUrl
		);
	}

	/**
	 * Build back-in-stock message.
	 *
	 * @param string $customerName Customer name.
	 * @param array  $products Products array.
	 * @param string $shopUrl Shop URL.
	 * @return string Message text.
	 */
	protected function buildBackInStockMessage( string $customerName, array $products, string $shopUrl ): string {
		return sprintf(
			/* translators: 1: Customer name, 2: Product list, 3: Shop URL */
			__(
				"Great news, %1\$s! 🎊 Products you were interested in are back in stock:\n\n%2\$s\n\nShop now: %3\$s",
				'whatsapp-commerce-hub'
			),
			$customerName,
			$this->formatProductList( $products ),
			$shopUrl
		);
	}

	/**
	 * Build price drop message.
	 *
	 * @param string $customerName Customer name.
	 * @param array  $products Products array.
	 * @param string $shopUrl Shop URL.
	 * @return string Message text.
	 */
	protected function buildPriceDropMessage( string $customerName, array $products, string $shopUrl ): string {
		return sprintf(
			/* translators: 1: Customer name, 2: Product list, 3: Shop URL */
			__(
				"Special alert for %1\$s! 💰 Price drops on products you viewed:\n\n%2\$s\n\nDon't miss out: %3\$s",
				'whatsapp-commerce-hub'
			),
			$customerName,
			$this->formatProductList( $products, true ),
			$shopUrl
		);
	}

	/**
	 * Build loyalty reward message.
	 *
	 * @param string      $customerName Customer name.
	 * @param string|null $couponCode Coupon code.
	 * @param int         $discountAmount Discount percentage.
	 * @param string      $shopUrl Shop URL.
	 * @return string Message text.
	 */
	protected function buildLoyaltyRewardMessage(
		string $customerName,
		?string $couponCode,
		int $discountAmount,
		string $shopUrl
	): string {
		if ( ! $couponCode ) {
			return $this->buildWeMissYouMessage( $customerName, $shopUrl );
		}

		return sprintf(
			/* translators: 1: Customer name, 2: Discount amount, 3: Coupon code, 4: Shop URL */
			__(
				"Hi %1\$s! 🌟 Thank you for being a valued customer!\n\n" .
				"As a token of our appreciation, here's an exclusive %2\$d%% discount code: *%3\$s*\n\n" .
				'Valid for 7 days. Shop now: %4$s',
				'whatsapp-commerce-hub'
			),
			$customerName,
			$discountAmount,
			$couponCode,
			$shopUrl
		);
	}

	/**
	 * Format a product list for message content.
	 *
	 * @param array $products Array of products.
	 * @param bool  $showPriceDrop Whether to show price drop info.
	 * @param int   $limit Max products to show.
	 * @return string Formatted product list.
	 */
	public function formatProductList( array $products, bool $showPriceDrop = false, int $limit = 3 ): string {
		if ( empty( $products ) ) {
			return '';
		}

		$lines = array();
		foreach ( array_slice( $products, 0, $limit ) as $product ) {
			if ( $showPriceDrop && isset( $product['drop'] ) ) {
				$lines[] = sprintf(
					'• %s - %s%% OFF! Now: %s',
					$product['name'],
					$product['drop'],
					wc_price( $product['price'] )
				);
			} else {
				$lines[] = sprintf(
					'• %s - %s',
					$product['name'],
					wc_price( $product['price'] )
				);
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get new arrivals for a customer based on purchase history.
	 *
	 * @param object $customer Customer profile.
	 * @param int    $limit Number of products.
	 * @return array Array of product data.
	 */
	public function getNewArrivalsForCustomer( object $customer, int $limit = 3 ): array {
		$purchasedCategories = $this->getCustomerCategories( $customer );

		if ( empty( $purchasedCategories ) ) {
			return $this->getRecentProducts( $limit );
		}

		$daysInactive = (int) $this->settings->get( 'reengagement.inactivity_threshold', 60 );
		$sinceDate    = gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( $daysInactive * DAY_IN_SECONDS ) );

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array(
				array(
					'after' => $sinceDate,
				),
			),
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $purchasedCategories,
				),
			),
		);

		$posts = get_posts( $args );

		return array_map(
			function ( $post ) {
				$wcProduct = wc_get_product( $post->ID );
				return array(
					'id'    => $post->ID,
					'name'  => $post->post_title,
					'price' => $wcProduct ? $wcProduct->get_price() : 0,
					'url'   => get_permalink( $post->ID ),
				);
			},
			$posts
		);
	}

	/**
	 * Get customer's purchased category IDs.
	 *
	 * @param object $customer Customer profile.
	 * @return array Array of category term IDs.
	 */
	public function getCustomerCategories( object $customer ): array {
		if ( ! isset( $customer->wc_customer_id ) || ! $customer->wc_customer_id ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer->wc_customer_id,
				'limit'       => -1,
				'status'      => array( 'completed', 'processing' ),
			)
		);

		$categories = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( $product ) {
					$terms = get_the_terms( $product->get_id(), 'product_cat' );
					if ( $terms && ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$categories[] = $term->term_id;
						}
					}
				}
			}
		}

		return array_unique( $categories );
	}

	/**
	 * Get the last purchased product for a customer.
	 *
	 * @param object $customer Customer profile.
	 * @return array|null Product data or null.
	 */
	public function getLastPurchasedProduct( object $customer ): ?array {
		if ( ! isset( $customer->wc_customer_id ) || ! $customer->wc_customer_id ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer->wc_customer_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => array( 'completed', 'processing' ),
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		$order = $orders[0];
		$items = $order->get_items();

		if ( empty( $items ) ) {
			return null;
		}

		$item = reset( $items );
		return array(
			'id'   => $item->get_product_id(),
			'name' => $item->get_name(),
		);
	}

	/**
	 * Get recent products.
	 *
	 * @param int $limit Number of products.
	 * @return array Array of product data.
	 */
	protected function getRecentProducts( int $limit = 3 ): array {
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$posts = get_posts( $args );

		return array_map(
			function ( $post ) {
				$wcProduct = wc_get_product( $post->ID );
				return array(
					'id'    => $post->ID,
					'name'  => $post->post_title,
					'price' => $wcProduct ? $wcProduct->get_price() : 0,
					'url'   => get_permalink( $post->ID ),
				);
			},
			$posts
		);
	}
}
