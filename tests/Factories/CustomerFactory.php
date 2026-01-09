<?php
/**
 * Customer Factory for Testing
 *
 * Provides factory methods for creating Customer entities in tests.
 *
 * @package WhatsApp_Commerce_Hub
 */

namespace WhatsAppCommerceHub\Tests\Factories;

use WhatsAppCommerceHub\Entities\Customer;

/**
 * Class CustomerFactory
 *
 * Factory for creating Customer test fixtures.
 */
class CustomerFactory {

	/**
	 * Default customer attributes.
	 *
	 * @var array
	 */
	private static array $defaults = array(
		'id'              => null,
		'phone_number'    => '+1234567890',
		'name'            => 'John Doe',
		'email'           => null,
		'whatsapp_id'     => null,
		'wc_customer_id'  => null,
		'language'        => 'en',
		'preferences'     => array(),
		'metadata'        => array(),
		'created_at'      => null,
		'updated_at'      => null,
	);

	/**
	 * Sequence counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Create a Customer entity.
	 *
	 * @param array $attributes Override attributes.
	 * @return Customer
	 */
	public static function create( array $attributes = array() ): Customer {
		self::$sequence++;

		$data = array_merge( self::$defaults, $attributes );

		// Generate ID if not provided.
		if ( null === $data['id'] ) {
			$data['id'] = self::$sequence;
		}

		// Generate unique phone if still default.
		if ( '+1234567890' === $data['phone_number'] ) {
			$data['phone_number'] = '+1' . str_pad( (string) self::$sequence, 10, '0', STR_PAD_LEFT );
		}

		// Generate WhatsApp ID from phone if not provided.
		if ( null === $data['whatsapp_id'] ) {
			$data['whatsapp_id'] = ltrim( $data['phone_number'], '+' );
		}

		// Generate email if not provided.
		if ( null === $data['email'] ) {
			$data['email'] = 'customer' . self::$sequence . '@example.com';
		}

		// Generate timestamps if not provided.
		$now = new \DateTimeImmutable();
		if ( null === $data['created_at'] ) {
			$data['created_at'] = $now;
		}
		if ( null === $data['updated_at'] ) {
			$data['updated_at'] = $now;
		}

		return new Customer(
			$data['id'],
			$data['phone_number'],
			$data['name'],
			$data['email'],
			$data['whatsapp_id'],
			$data['wc_customer_id'],
			$data['language'],
			$data['preferences'],
			$data['metadata'],
			$data['created_at'],
			$data['updated_at']
		);
	}

	/**
	 * Create a customer linked to WooCommerce.
	 *
	 * @param int   $wc_customer_id WooCommerce customer ID.
	 * @param array $attributes Override attributes.
	 * @return Customer
	 */
	public static function createWithWooCommerce( int $wc_customer_id, array $attributes = array() ): Customer {
		$attributes['wc_customer_id'] = $wc_customer_id;
		return self::create( $attributes );
	}

	/**
	 * Create a customer with preferences.
	 *
	 * @param array $preferences Customer preferences.
	 * @param array $attributes Override attributes.
	 * @return Customer
	 */
	public static function createWithPreferences( array $preferences = array(), array $attributes = array() ): Customer {
		$default_prefs = array(
			'notifications_enabled' => true,
			'marketing_opt_in'      => false,
			'preferred_currency'    => 'USD',
			'order_updates'         => true,
		);

		$attributes['preferences'] = array_merge( $default_prefs, $preferences );
		return self::create( $attributes );
	}

	/**
	 * Create a returning customer (has purchase history).
	 *
	 * @param array $attributes Override attributes.
	 * @return Customer
	 */
	public static function createReturning( array $attributes = array() ): Customer {
		// Use sequence-based values for reproducibility.
		$seq      = self::$sequence + 1;
		$metadata = array(
			'total_orders'     => 2 + ( $seq % 9 ),  // 2-10 based on sequence.
			'total_spent'      => 100.00 + ( $seq * 50.00 ),  // Predictable spend.
			'last_order_date'  => ( new \DateTimeImmutable() )->modify( '-' . ( 1 + ( $seq % 30 ) ) . ' days' )->format( 'Y-m-d' ),
			'customer_segment' => 'returning',
		);

		$attributes['metadata'] = array_merge( $metadata, $attributes['metadata'] ?? array() );
		$attributes['wc_customer_id'] = $attributes['wc_customer_id'] ?? ( 1000 + $seq );

		return self::create( $attributes );
	}

	/**
	 * Create a VIP customer.
	 *
	 * @param array $attributes Override attributes.
	 * @return Customer
	 */
	public static function createVIP( array $attributes = array() ): Customer {
		// Use sequence-based values for reproducibility.
		$seq      = self::$sequence + 1;
		$metadata = array(
			'total_orders'     => 20 + ( $seq * 5 ),  // 25, 30, 35, etc.
			'total_spent'      => 5000 + ( $seq * 1000 ),  // Predictable VIP spend.
			'customer_segment' => 'vip',
			'vip_since'        => ( new \DateTimeImmutable() )->modify( '-' . ( 180 + ( $seq % 186 ) ) . ' days' )->format( 'Y-m-d' ),
		);

		$preferences = array(
			'notifications_enabled' => true,
			'marketing_opt_in'      => true,
			'vip_priority'          => true,
		);

		$attributes['metadata']    = array_merge( $metadata, $attributes['metadata'] ?? array() );
		$attributes['preferences'] = array_merge( $preferences, $attributes['preferences'] ?? array() );
		$attributes['wc_customer_id'] = $attributes['wc_customer_id'] ?? ( 2000 + $seq );

		return self::create( $attributes );
	}

	/**
	 * Create a new/guest customer.
	 *
	 * @param array $attributes Override attributes.
	 * @return Customer
	 */
	public static function createGuest( array $attributes = array() ): Customer {
		$attributes = array_merge(
			array(
				'wc_customer_id' => null,
				'email'          => null,
				'name'           => 'Guest',
				'metadata'       => array(
					'customer_segment' => 'new',
					'source'           => 'whatsapp',
				),
			),
			$attributes
		);
		return self::create( $attributes );
	}

	/**
	 * Create customers with different languages.
	 *
	 * @param array $languages List of language codes.
	 * @param array $attributes Override attributes applied to all.
	 * @return array<Customer>
	 */
	public static function createMultiLanguage( array $languages = array( 'en', 'es', 'ar', 'pt' ), array $attributes = array() ): array {
		$customers = array();
		foreach ( $languages as $lang ) {
			$customers[] = self::create( array_merge(
				$attributes,
				array( 'language' => $lang )
			) );
		}
		return $customers;
	}

	/**
	 * Create multiple customers.
	 *
	 * @param int   $count Number of customers.
	 * @param array $attributes Override attributes applied to all.
	 * @return array<Customer>
	 */
	public static function createMany( int $count, array $attributes = array() ): array {
		$customers = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$customers[] = self::create( $attributes );
		}
		return $customers;
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
