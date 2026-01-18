<?php

namespace Younis\WhatsappCommerceHub\Exceptions;

/**
 * DomainException - Business logic violations and domain rule failures.
 *
 * These exceptions represent violations of business rules or domain invariants.
 * They should NOT be retried as the same input will always fail.
 *
 * Examples:
 * - Cart is expired
 * - Product out of stock
 * - Invalid coupon code
 * - Order cannot be modified after payment
 *
 * @since 1.0.0
 */
class DomainException extends WchException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param string $errorCode Error code identifier
     * @param int $httpStatus HTTP status code (default: 400)
     * @param array $context Additional context
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        string $errorCode = 'domain_error',
        int $httpStatus = 400,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $context, $previous);
    }

    /**
     * Check if this exception should be retried.
     *
     * Domain exceptions should NEVER be retried as they represent
     * business rule violations that won't change on retry.
     *
     * @return bool Always false
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
