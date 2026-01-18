<?php
/**
 * Cart Exception Class
 *
 * Exception for cart-related errors.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Exceptions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartException
 *
 * Exception thrown when cart operations fail.
 *
 * This is a DomainException because cart errors represent business rule
 * violations (out of stock, expired cart, invalid quantity, etc.).
 * These should NOT be retried as the same operation will always fail.
 */
class CartException extends DomainException {

	/**
	 * Error codes for cart operations.
	 */
	public const ERROR_CART_NOT_FOUND     = 'cart_not_found';
	public const ERROR_ITEM_NOT_FOUND     = 'cart_item_not_found';
	public const ERROR_PRODUCT_NOT_FOUND  = 'product_not_found';
	public const ERROR_OUT_OF_STOCK       = 'out_of_stock';
	public const ERROR_INSUFFICIENT_STOCK = 'insufficient_stock';
	public const ERROR_INVALID_QUANTITY   = 'invalid_quantity';
	public const ERROR_CART_EXPIRED       = 'cart_expired';
	public const ERROR_CART_LOCKED        = 'cart_locked';
	public const ERROR_CART_EMPTY         = 'cart_empty';
	public const ERROR_COUPON_INVALID     = 'coupon_invalid';

	/**
	 * Product ID related to the error.
	 *
	 * @var int|null
	 */
	protected ?int $productId;

	/**
	 * Constructor.
	 *
	 * @param string          $message    Exception message.
	 * @param string          $errorCode  Error code identifier.
	 * @param int|null        $productId  Product ID related to the error.
	 * @param int             $httpStatus HTTP status code.
	 * @param array           $context    Additional context data.
	 * @param \Throwable|null $previous   Previous exception.
	 */
	public function __construct(
		string $message = '',
		string $errorCode = 'cart_error',
		?int $productId = null,
		int $httpStatus = 400,
		array $context = [],
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $errorCode, $httpStatus, $context, 0, $previous );

		$this->productId = $productId;
	}

	/**
	 * Get product ID.
	 *
	 * @return int|null
	 */
	public function getProductId(): ?int {
		return $this->productId;
	}

	/**
	 * Create an out of stock exception.
	 *
	 * @param int    $productId   Product ID.
	 * @param string $productName Product name.
	 * @return static
	 */
	public static function outOfStock( int $productId, string $productName = '' ): static {
		$message = $productName
			? sprintf( 'Product "%s" is out of stock', $productName )
			: 'Product is out of stock';

		return new static(
			$message,
			self::ERROR_OUT_OF_STOCK,
			$productId,
			400,
			[ 'product_name' => $productName ]
		);
	}

	/**
	 * Create an insufficient stock exception.
	 *
	 * @param int $productId Product ID.
	 * @param int $requested Requested quantity.
	 * @param int $available Available quantity.
	 * @return static
	 */
	public static function insufficientStock( int $productId, int $requested, int $available ): static {
		return new static(
			sprintf( 'Only %d items available (requested %d)', $available, $requested ),
			self::ERROR_INSUFFICIENT_STOCK,
			$productId,
			400,
			[
				'requested' => $requested,
				'available' => $available,
			]
		);
	}

	/**
	 * Create a cart not found exception.
	 *
	 * @param string $identifier Cart identifier (phone or session).
	 * @return static
	 */
	public static function notFound( string $identifier ): static {
		return new static(
			'Cart not found',
			self::ERROR_CART_NOT_FOUND,
			null,
			404,
			[ 'identifier' => $identifier ]
		);
	}

	/**
	 * Create an expired cart exception.
	 *
	 * @param string $identifier Cart identifier.
	 * @return static
	 */
	public static function expired( string $identifier ): static {
		return new static(
			'Cart has expired',
			self::ERROR_CART_EXPIRED,
			null,
			410,
			[ 'identifier' => $identifier ]
		);
	}

	/**
	 * Create an empty cart exception.
	 *
	 * @return static
	 */
	public static function empty(): static {
		return new static(
			'Cart is empty',
			self::ERROR_CART_EMPTY,
			null,
			400
		);
	}
}
