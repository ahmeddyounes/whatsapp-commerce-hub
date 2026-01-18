<?php
/**
 * Cart Factory for Testing
 *
 * Provides factory methods for creating Cart entities in tests.
 *
 * @package WhatsApp_Commerce_Hub
 */

namespace WhatsAppCommerceHub\Tests\Factories;

use WhatsAppCommerceHub\Domain\Cart\Cart;
use WhatsAppCommerceHub\Entities\CartItem;

/**
 * Class CartFactory
 *
 * Factory for creating Cart test fixtures.
 */
class CartFactory {

	/**
	 * Default cart attributes.
	 *
	 * @var array
	 */
	private static array $defaults = [
		'id'             => null,
		'phone_number'   => '+1234567890',
		'items'          => [],
		'currency'       => 'USD',
		'status'         => 'active',
		'created_at'     => null,
		'updated_at'     => null,
		'expires_at'     => null,
	];

	/**
	 * Sequence counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Create a Cart entity.
	 *
	 * @param array $attributes Override attributes.
	 * @return Cart
	 */
	public static function create( array $attributes = [] ): Cart {
		self::$sequence++;

		$data = array_merge( self::$defaults, $attributes );

		// Generate ID if not provided.
		if ( null === $data['id'] ) {
			$data['id'] = self::$sequence;
		}

		// Generate timestamps if not provided.
		$now = new \DateTimeImmutable();
		if ( null === $data['created_at'] ) {
			$data['created_at'] = $now;
		}
		if ( null === $data['updated_at'] ) {
			$data['updated_at'] = $now;
		}
		if ( null === $data['expires_at'] ) {
			$data['expires_at'] = $now->modify( '+72 hours' );
		}

		return new Cart(
			$data['id'],
			$data['phone_number'],
			$data['items'],
			$data['currency'],
			$data['status'],
			$data['created_at'],
			$data['updated_at'],
			$data['expires_at']
		);
	}

	/**
	 * Create a Cart with items.
	 *
	 * @param int   $item_count Number of items to add.
	 * @param array $attributes Override cart attributes.
	 * @return Cart
	 */
	public static function createWithItems( int $item_count = 3, array $attributes = [] ): Cart {
		$items = [];
		for ( $i = 0; $i < $item_count; $i++ ) {
			$items[] = CartItemFactory::create( [
				'product_id' => 100 + $i,
				'name'       => "Test Product {$i}",
				'quantity'   => 1 + ( $i % 5 ),  // Predictable quantity: 1-5 based on index.
			] );
		}

		$attributes['items'] = $items;
		return self::create( $attributes );
	}

	/**
	 * Create an empty cart.
	 *
	 * @param array $attributes Override cart attributes.
	 * @return Cart
	 */
	public static function createEmpty( array $attributes = [] ): Cart {
		$attributes['items'] = [];
		return self::create( $attributes );
	}

	/**
	 * Create an abandoned cart.
	 *
	 * @param array $attributes Override cart attributes.
	 * @return Cart
	 */
	public static function createAbandoned( array $attributes = [] ): Cart {
		$now = new \DateTimeImmutable();
		$attributes = array_merge(
			[
				'status'     => 'abandoned',
				'updated_at' => $now->modify( '-48 hours' ),
			],
			$attributes
		);
		return self::createWithItems( 2, $attributes );
	}

	/**
	 * Create an expired cart.
	 *
	 * @param array $attributes Override cart attributes.
	 * @return Cart
	 */
	public static function createExpired( array $attributes = [] ): Cart {
		$now = new \DateTimeImmutable();
		$attributes = array_merge(
			[
				'expires_at' => $now->modify( '-1 hour' ),
			],
			$attributes
		);
		return self::createWithItems( 1, $attributes );
	}

	/**
	 * Reset the sequence counter.
	 *
	 * @return void
	 */
	public static function resetSequence(): void {
		self::$sequence = 0;
	}
}

/**
 * Class CartItemFactory
 *
 * Factory for creating CartItem test fixtures.
 */
class CartItemFactory {

	/**
	 * Default cart item attributes.
	 *
	 * @var array
	 */
	private static array $defaults = [
		'product_id'   => 1,
		'variation_id' => 0,
		'name'         => 'Test Product',
		'price'        => 29.99,
		'quantity'     => 1,
		'image_url'    => 'https://example.com/image.jpg',
		'attributes'   => [],
	];

	/**
	 * Sequence counter.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Create a CartItem.
	 *
	 * @param array $attributes Override attributes.
	 * @return CartItem
	 */
	public static function create( array $attributes = [] ): CartItem {
		self::$sequence++;

		$data = array_merge( self::$defaults, $attributes );

		// Generate product ID if not provided.
		if ( 1 === $data['product_id'] ) {
			$data['product_id'] = self::$sequence;
		}

		return new CartItem(
			$data['product_id'],
			$data['variation_id'],
			$data['name'],
			$data['price'],
			$data['quantity'],
			$data['image_url'],
			$data['attributes']
		);
	}

	/**
	 * Create a variable product item.
	 *
	 * @param array $attributes Override attributes.
	 * @return CartItem
	 */
	public static function createVariable( array $attributes = [] ): CartItem {
		// Use sequence-based variation ID for reproducibility.
		$seq        = self::$sequence + 1;
		$attributes = array_merge(
			[
				'variation_id' => 1000 + $seq,  // Predictable variation ID.
				'attributes'   => [
					'size'  => 'Large',
					'color' => 'Blue',
				],
			],
			$attributes
		);
		return self::create( $attributes );
	}

	/**
	 * Create multiple cart items.
	 *
	 * @param int   $count Number of items.
	 * @param array $attributes Override attributes applied to all items.
	 * @return array<CartItem>
	 */
	public static function createMany( int $count, array $attributes = [] ): array {
		$items = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = self::create( array_merge(
				$attributes,
				[ 'product_id' => 100 + $i, 'name' => "Product {$i}" ]
			) );
		}
		return $items;
	}

	/**
	 * Reset the sequence counter.
	 *
	 * @return void
	 */
	public static function resetSequence(): void {
		self::$sequence = 0;
	}
}
