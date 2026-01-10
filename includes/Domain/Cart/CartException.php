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

use WhatsAppCommerceHub\Exceptions\WchException;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartException
 *
 * Exception thrown when cart operations fail.
 */
class CartException extends WchException {
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
		array $context = array(),
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $error_code, $http_status, $context, $code, $previous );
	}
}
