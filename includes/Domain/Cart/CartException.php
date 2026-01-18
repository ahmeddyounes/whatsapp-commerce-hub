<?php
/**
 * Cart Exception
 *
 * Domain exception for cart-related errors.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Domain\Cart;

use WhatsAppCommerceHub\Exceptions\DomainException;

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
 * violations. These should NOT be retried as the same operation will
 * always fail.
 */
class CartException extends DomainException {
	/**
	 * Constructor.
	 *
	 * @param string          $message     Exception message.
	 * @param string          $error_code  Error code identifier.
	 * @param int             $http_status HTTP status code. Default 400.
	 * @param array           $context     Additional context data.
	 * @param int             $code        Exception code. Default 0.
	 * @param \Throwable|null $previous    Previous exception.
	 */
	public function __construct(
		string $message = '',
		string $error_code = 'cart_error',
		int $http_status = 400,
		array $context = [],
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $error_code, $http_status, $context, $code, $previous );
	}
}
