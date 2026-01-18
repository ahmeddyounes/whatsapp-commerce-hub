<?php

namespace Younis\WhatsappCommerceHub\Exceptions;

/**
 * InfrastructureException - External service and infrastructure failures.
 *
 * These exceptions represent failures in external dependencies and infrastructure:
 * - API calls to external services (WhatsApp, OpenAI, payment gateways)
 * - Database connection failures
 * - Network timeouts
 * - File system errors
 * - Circuit breaker open states
 *
 * These should generally be retried with exponential backoff as they
 * represent transient failures that may succeed on retry.
 *
 * @since 1.0.0
 */
class InfrastructureException extends WchException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param string $errorCode Error code identifier
     * @param int $httpStatus HTTP status code (default: 503)
     * @param array $context Additional context
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        string $errorCode = 'infrastructure_error',
        int $httpStatus = 503,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $context, $previous);
    }

    /**
     * Check if this exception should be retried.
     *
     * Infrastructure exceptions are generally retryable as they represent
     * transient failures in external systems.
     *
     * @return bool Default to true for infrastructure failures
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
