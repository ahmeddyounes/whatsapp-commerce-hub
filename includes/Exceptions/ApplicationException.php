<?php

namespace Younis\WhatsappCommerceHub\Exceptions;

/**
 * ApplicationException - Application layer errors and orchestration failures.
 *
 * These exceptions represent errors in the application layer such as:
 * - Validation failures
 * - State management errors
 * - Workflow orchestration issues
 * - Data processing errors
 *
 * Whether these should be retried depends on the specific subtype:
 * - Validation errors: NOT retryable
 * - State errors: MAY be retryable
 * - Processing errors: MAY be retryable
 *
 * @since 1.0.0
 */
class ApplicationException extends WchException
{
    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param string $errorCode Error code identifier
     * @param int $httpStatus HTTP status code (default: 500)
     * @param array $context Additional context
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        string $errorCode = 'application_error',
        int $httpStatus = 500,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $context, $previous);
    }

    /**
     * Check if this exception should be retried.
     *
     * Application exceptions may or may not be retryable.
     * Override in subclasses to provide specific logic.
     *
     * @return bool Default to false for safety
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
