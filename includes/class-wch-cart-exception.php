<?php
/**
 * Cart Exception Class
 *
 * Custom exception for cart-related errors.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Cart_Exception
 *
 * Exception thrown when cart operations fail.
 */
class WCH_Cart_Exception extends WCH_Exception {
	/**
	 * Constructor.
	 *
	 * @param string         $message     Exception message.
	 * @param string         $error_code  Error code identifier.
	 * @param int            $http_status HTTP status code. Default 400.
	 * @param array          $context     Additional context data.
	 * @param int            $code        Exception code. Default 0.
	 * @param Throwable|null $previous    Previous exception.
	 */
	public function __construct(
		$message = '',
		$error_code = 'cart_error',
		$http_status = 400,
		array $context = array(),
		$code = 0,
		?Throwable $previous = null
	) {
		parent::__construct( $message, $error_code, $http_status, $context, $code, $previous );
	}
}
