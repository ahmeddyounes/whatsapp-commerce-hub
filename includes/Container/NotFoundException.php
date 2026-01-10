<?php
/**
 * Not Found Exception
 *
 * Exception thrown when a requested entry is not found in the container.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotFoundException
 *
 * PSR-11 compatible exception for when an entry is not found.
 */
class NotFoundException extends \Exception {

	/**
	 * Constructor.
	 *
	 * @param string          $id       The identifier that was not found.
	 * @param int             $code     The exception code.
	 * @param \Throwable|null $previous The previous throwable used for exception chaining.
	 */
	public function __construct( string $id, int $code = 0, ?\Throwable $previous = null ) {
		$message = sprintf( 'No entry was found for identifier: %s', $id );
		parent::__construct( $message, $code, $previous );
	}
}
