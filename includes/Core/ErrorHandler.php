<?php
/**
 * ErrorHandler
 *
 * Core error and exception handler with comprehensive logging.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Core
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Core;

use ErrorException;
use Throwable;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ErrorHandler
 *
 * Centralized error and exception handling with logging integration.
 */
class ErrorHandler {
	/**
	 * Whether the error handler has been initialized.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface|null
	 */
	private static ?LoggerInterface $logger = null;

	/**
	 * Initialize error handlers.
	 *
	 * @param LoggerInterface|null $logger Logger instance (optional, will use container if not provided).
	 * @return void
	 */
	public static function init( ?LoggerInterface $logger = null ): void {
		if ( self::$initialized ) {
			return;
		}

		// Store logger or get from container.
		self::$logger = $logger ?? self::getLogger();

		// Register exception handler.
		set_exception_handler( array( __CLASS__, 'handleException' ) );

		// Register error handler.
		set_error_handler( array( __CLASS__, 'handleError' ) );

		// Register shutdown handler for fatal errors.
		register_shutdown_function( array( __CLASS__, 'handleShutdown' ) );

		self::$initialized = true;
	}

	/**
	 * Handle uncaught exceptions.
	 *
	 * @param Throwable $throwable The exception to handle.
	 * @return void
	 */
	public static function handleException( Throwable $throwable ): void {
		$logger = self::getLogger();

		// Prepare context.
		$context = array(
			'exception' => get_class( $throwable ),
			'file'      => $throwable->getFile(),
			'line'      => $throwable->getLine(),
			'code'      => $throwable->getCode(),
		);

		// Include trace in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$context['trace'] = $throwable->getTraceAsString();
		}

		// Log the exception.
		$logger->critical( $throwable->getMessage(), 'errors', $context );

		// Display error based on environment.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			self::displayError( $throwable );
		} else {
			// Production - show generic error.
			self::displayGenericError();
		}

		// Don't execute PHP internal error handler.
		exit( 1 );
	}

	/**
	 * Handle PHP errors and convert to exceptions in debug mode.
	 *
	 * @param int    $errno   Error level.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where error occurred.
	 * @param int    $errline Line number where error occurred.
	 * @return bool
	 * @throws ErrorException In debug mode.
	 */
	public static function handleError( int $errno, string $errstr, string $errfile, int $errline ): bool {
		// Check if error should be reported.
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}

		$logger = self::getLogger();

		// Map error level to log level.
		$logLevel = self::getLogLevelForError( $errno );

		// Log the error.
		$context = array(
			'errno' => $errno,
			'file'  => $errfile,
			'line'  => $errline,
			'type'  => self::getErrorTypeName( $errno ),
		);

		// Log based on severity.
		match ( $logLevel ) {
			'critical' => $logger->critical( $errstr, 'errors', $context ),
			'error'    => $logger->error( $errstr, 'errors', $context ),
			'warning'  => $logger->warning( $errstr, 'errors', $context ),
			'info'     => $logger->info( $errstr, 'errors', $context ),
			default    => $logger->debug( $errstr, 'errors', $context ),
		};

		// Convert to exception in debug mode for fatal errors.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( in_array( $errno, array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
				throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
			}
		}

		// Don't execute PHP internal error handler.
		return true;
	}

	/**
	 * Handle fatal errors on shutdown.
	 *
	 * @return void
	 */
	public static function handleShutdown(): void {
		$error = error_get_last();

		if ( null === $error ) {
			return;
		}

		// Only handle fatal errors.
		$fatalErrors = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( ! in_array( $error['type'], $fatalErrors, true ) ) {
			return;
		}

		$logger = self::getLogger();

		// Log the fatal error.
		$context = array(
			'type'     => self::getErrorTypeName( $error['type'] ),
			'file'     => $error['file'],
			'line'     => $error['line'],
			'is_fatal' => true,
		);

		$logger->critical( $error['message'], 'errors', $context );

		// Display error based on environment.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			self::displayFatalError( $error );
		}
	}

	/**
	 * Get log level for PHP error type.
	 *
	 * @param int $errno Error number.
	 * @return string
	 */
	private static function getLogLevelForError( int $errno ): string {
		return match ( $errno ) {
			E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'critical',
			E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'error',
			E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 'warning',
			E_STRICT => 'info',
			default => 'debug',
		};
	}

	/**
	 * Get human-readable error type name.
	 *
	 * @param int $errno Error number.
	 * @return string
	 */
	private static function getErrorTypeName( int $errno ): string {
		return match ( $errno ) {
			E_ERROR             => 'E_ERROR',
			E_WARNING           => 'E_WARNING',
			E_PARSE             => 'E_PARSE',
			E_NOTICE            => 'E_NOTICE',
			E_CORE_ERROR        => 'E_CORE_ERROR',
			E_CORE_WARNING      => 'E_CORE_WARNING',
			E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
			E_USER_ERROR        => 'E_USER_ERROR',
			E_USER_WARNING      => 'E_USER_WARNING',
			E_USER_NOTICE       => 'E_USER_NOTICE',
			E_STRICT            => 'E_STRICT',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED        => 'E_DEPRECATED',
			E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
			default             => 'UNKNOWN',
		};
	}

	/**
	 * Display error message (debug mode).
	 *
	 * @param Throwable $throwable The exception to display.
	 * @return void
	 */
	private static function displayError( Throwable $throwable ): void {
		$html = sprintf(
			'<div style="padding: 20px; background: #fee; border: 2px solid #c33; margin: 20px; font-family: monospace;">' .
			'<h2 style="color: #c33; margin: 0 0 10px;">Uncaught Exception</h2>' .
			'<p><strong>%s:</strong> %s</p>' .
			'<p><strong>File:</strong> %s:%d</p>' .
			'<pre style="background: #fff; padding: 10px; overflow: auto;">%s</pre>' .
			'</div>',
			esc_html( get_class( $throwable ) ),
			esc_html( $throwable->getMessage() ),
			esc_html( $throwable->getFile() ),
			$throwable->getLine(),
			esc_html( $throwable->getTraceAsString() )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	/**
	 * Display fatal error message (debug mode).
	 *
	 * @param array<string, mixed> $error Error details from error_get_last().
	 * @return void
	 */
	private static function displayFatalError( array $error ): void {
		$html = sprintf(
			'<div style="padding: 20px; background: #fee; border: 2px solid #c33; margin: 20px; font-family: monospace;">' .
			'<h2 style="color: #c33; margin: 0 0 10px;">Fatal Error</h2>' .
			'<p><strong>Type:</strong> %s</p>' .
			'<p><strong>Message:</strong> %s</p>' .
			'<p><strong>File:</strong> %s:%d</p>' .
			'</div>',
			esc_html( self::getErrorTypeName( $error['type'] ) ),
			esc_html( $error['message'] ),
			esc_html( $error['file'] ),
			$error['line']
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	/**
	 * Display generic error message (production).
	 *
	 * @return void
	 */
	private static function displayGenericError(): void {
		$html = '<div style="padding: 20px; background: #fee; border: 2px solid #c33; margin: 20px;">' .
			'<h2 style="color: #c33;">An error occurred</h2>' .
			'<p>We\'re sorry, but something went wrong. Please try again later.</p>' .
			'</div>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	/**
	 * Get logger instance.
	 *
	 * @return LoggerInterface
	 */
	private static function getLogger(): LoggerInterface {
		if ( null === self::$logger ) {
			// Try to get from container.
			if ( function_exists( 'wch' ) ) {
				try {
					self::$logger = wch( LoggerInterface::class );
				} catch ( \Exception $e ) {
					// Fallback to Logger directly.
					self::$logger = wch( Logger::class );
				}
			} else {
				// Last resort - create new instance.
				self::$logger = new Logger();
			}
		}

		return self::$logger;
	}

	/**
	 * Reset error handler (for testing).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$initialized = false;
		self::$logger      = null;
		restore_error_handler();
		restore_exception_handler();
	}
}
