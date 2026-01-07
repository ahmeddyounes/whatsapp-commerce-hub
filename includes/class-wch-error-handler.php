<?php
/**
 * Error handler class for WhatsApp Commerce Hub.
 *
 * Handles exceptions, errors, and fatal errors with comprehensive logging.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Error_Handler
 *
 * Centralized error and exception handling.
 */
class WCH_Error_Handler {
	/**
	 * Whether the error handler has been initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize error handlers.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}

		// Register exception handler.
		set_exception_handler( array( __CLASS__, 'handle_exception' ) );

		// Register error handler.
		set_error_handler( array( __CLASS__, 'handle_error' ) );

		// Register shutdown handler for fatal errors.
		register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );

		self::$initialized = true;
	}

	/**
	 * Handle uncaught exceptions.
	 *
	 * @param Throwable $throwable The exception to handle.
	 */
	public static function handle_exception( Throwable $throwable ) {
		// Prepare context.
		$context = array(
			'exception' => get_class( $throwable ),
			'file'      => $throwable->getFile(),
			'line'      => $throwable->getLine(),
			'code'      => $throwable->getCode(),
		);

		// Add additional context for WCH_Exception.
		if ( $throwable instanceof WCH_Exception ) {
			$context['error_code']  = $throwable->get_error_code();
			$context['http_status'] = $throwable->get_http_status();
			$context                = array_merge( $context, $throwable->get_context() );
		}

		// Include trace in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$context['trace'] = $throwable->getTraceAsString();
		}

		// Log the exception.
		WCH_Logger::critical( $throwable->getMessage(), $context );

		// Display error based on environment.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			self::display_error( $throwable );
		} else {
			// Production - show generic error.
			self::display_generic_error();
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
	public static function handle_error( $errno, $errstr, $errfile, $errline ) {
		// Check if error should be reported.
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}

		// Map error level to log level.
		$log_level = self::get_log_level_for_error( $errno );

		// Log the error.
		$context = array(
			'errno' => $errno,
			'file'  => $errfile,
			'line'  => $errline,
			'type'  => self::get_error_type_name( $errno ),
		);

		switch ( $log_level ) {
			case 'critical':
				WCH_Logger::critical( $errstr, $context );
				break;
			case 'error':
				WCH_Logger::error( $errstr, $context );
				break;
			case 'warning':
				WCH_Logger::warning( $errstr, $context );
				break;
			case 'info':
				WCH_Logger::info( $errstr, $context );
				break;
			default:
				WCH_Logger::debug( $errstr, $context );
				break;
		}

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
	 */
	public static function handle_shutdown() {
		$error = error_get_last();

		if ( null === $error ) {
			return;
		}

		// Only handle fatal errors.
		$fatal_errors = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( ! in_array( $error['type'], $fatal_errors, true ) ) {
			return;
		}

		// Log the fatal error.
		$context = array(
			'type'     => self::get_error_type_name( $error['type'] ),
			'file'     => $error['file'],
			'line'     => $error['line'],
			'is_fatal' => true,
		);

		WCH_Logger::critical( $error['message'], $context );

		// Display error based on environment.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			self::display_fatal_error( $error );
		}
	}

	/**
	 * Get log level for PHP error type.
	 *
	 * @param int $errno Error number.
	 * @return string
	 */
	private static function get_log_level_for_error( $errno ) {
		switch ( $errno ) {
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
				return 'critical';

			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				return 'error';

			case E_NOTICE:
			case E_USER_NOTICE:
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				return 'warning';

			case E_STRICT:
				return 'info';

			default:
				return 'debug';
		}
	}

	/**
	 * Get human-readable error type name.
	 *
	 * @param int $errno Error number.
	 * @return string
	 */
	private static function get_error_type_name( $errno ) {
		$error_types = array(
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
		);

		return isset( $error_types[ $errno ] ) ? $error_types[ $errno ] : 'UNKNOWN';
	}

	/**
	 * Display detailed error information.
	 *
	 * @param Throwable $throwable The exception.
	 */
	private static function display_error( Throwable $throwable ) {
		if ( wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
			// For AJAX/REST requests, send JSON response.
			header( 'Content-Type: application/json' );
			http_response_code( $throwable instanceof WCH_Exception ? $throwable->get_http_status() : 500 );

			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => array(
						'message' => $throwable->getMessage(),
						'code'    => $throwable instanceof WCH_Exception ? $throwable->get_error_code() : 'internal_error',
						'file'    => $throwable->getFile(),
						'line'    => $throwable->getLine(),
						'trace'   => $throwable->getTraceAsString(),
					),
				)
			);
		} else {
			// For regular requests, display HTML error.
			?>
			<!DOCTYPE html>
			<html>
			<head>
				<title>Error - WhatsApp Commerce Hub</title>
				<style>
					body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; padding: 40px; background: #f0f0f1; }
					.error-container { background: white; border-left: 4px solid #d63638; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; max-width: 800px; margin: 0 auto; }
					h1 { color: #d63638; margin-top: 0; }
					.error-details { background: #f6f7f7; padding: 15px; border-radius: 3px; margin: 15px 0; overflow-x: auto; }
					.trace { font-family: monospace; font-size: 12px; white-space: pre-wrap; }
				</style>
			</head>
			<body>
				<div class="error-container">
					<h1>WhatsApp Commerce Hub Error</h1>
					<p><strong>Message:</strong> <?php echo esc_html( $throwable->getMessage() ); ?></p>
					<?php if ( $throwable instanceof WCH_Exception ) : ?>
						<p><strong>Error Code:</strong> <?php echo esc_html( $throwable->get_error_code() ); ?></p>
					<?php endif; ?>
					<div class="error-details">
						<p><strong>File:</strong> <?php echo esc_html( $throwable->getFile() ); ?></p>
						<p><strong>Line:</strong> <?php echo esc_html( $throwable->getLine() ); ?></p>
					</div>
					<details>
						<summary><strong>Stack Trace</strong></summary>
						<div class="trace"><?php echo esc_html( $throwable->getTraceAsString() ); ?></div>
					</details>
				</div>
			</body>
			</html>
			<?php
		}
	}

	/**
	 * Display fatal error information.
	 *
	 * @param array $error Error details.
	 */
	private static function display_fatal_error( array $error ) {
		if ( wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
			header( 'Content-Type: application/json' );
			http_response_code( 500 );

			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => array(
						'message' => $error['message'],
						'code'    => 'fatal_error',
						'file'    => $error['file'],
						'line'    => $error['line'],
						'type'    => self::get_error_type_name( $error['type'] ),
					),
				)
			);
		}
		// For regular requests, WordPress will display its own fatal error page.
	}

	/**
	 * Display generic error message.
	 */
	private static function display_generic_error() {
		if ( wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
			header( 'Content-Type: application/json' );
			http_response_code( 500 );

			echo wp_json_encode(
				array(
					'success' => false,
					'error'   => array(
						'message' => 'An internal error occurred. Please try again later.',
						'code'    => 'internal_error',
					),
				)
			);
		} else {
			wp_die(
				esc_html__( 'An internal error occurred. Please try again later.', 'whatsapp-commerce-hub' ),
				esc_html__( 'Error', 'whatsapp-commerce-hub' ),
				array( 'response' => 500 )
			);
		}
	}
}
