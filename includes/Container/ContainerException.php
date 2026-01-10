<?php
/**
 * Container Exception
 *
 * Exception thrown when an error occurs while retrieving an entry from the container.
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
 * Class ContainerException
 *
 * PSR-11 compatible exception for container errors.
 */
class ContainerException extends \Exception {

	/**
	 * The identifier that caused the error.
	 *
	 * @var string|null
	 */
	protected ?string $identifier = null;

	/**
	 * Constructor.
	 *
	 * @param string          $message  The exception message.
	 * @param string|null     $id       The identifier that caused the error.
	 * @param int             $code     The exception code.
	 * @param \Throwable|null $previous The previous throwable used for exception chaining.
	 */
	public function __construct(
		string $message,
		?string $id = null,
		int $code = 0,
		?\Throwable $previous = null
	) {
		$this->identifier = $id;
		parent::__construct( $message, $code, $previous );
	}

	/**
	 * Get the identifier that caused the error.
	 *
	 * @return string|null
	 */
	public function getIdentifier(): ?string {
		return $this->identifier;
	}

	/**
	 * Create an exception for a non-instantiable class.
	 *
	 * @param string $class The class name.
	 * @return self
	 */
	public static function notInstantiable( string $class ): self {
		return new self(
			sprintf( 'Class %s is not instantiable (abstract class or interface)', $class ),
			$class
		);
	}

	/**
	 * Create an exception for an unresolvable parameter.
	 *
	 * @param string $class     The class name.
	 * @param string $parameter The parameter name.
	 * @return self
	 */
	public static function unresolvableParameter( string $class, string $parameter ): self {
		return new self(
			sprintf(
				'Unable to resolve parameter "%s" for class "%s". No type hint or default value provided.',
				$parameter,
				$class
			),
			$class
		);
	}

	/**
	 * Create an exception for a circular dependency.
	 *
	 * @param array $chain The dependency chain.
	 * @return self
	 */
	public static function circularDependency( array $chain ): self {
		return new self(
			sprintf(
				'Circular dependency detected: %s',
				implode( ' -> ', $chain )
			)
		);
	}
}
