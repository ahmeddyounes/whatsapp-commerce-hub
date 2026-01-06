<?php
/**
 * Custom exception class for WhatsApp Commerce Hub.
 *
 * Extends the base Exception class with additional properties
 * for error codes, HTTP status codes, and context data.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Exception
 *
 * Custom exception with additional error handling properties.
 */
class WCH_Exception extends Exception {
	/**
	 * Error code identifier.
	 *
	 * @var string
	 */
	protected $error_code;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	protected $http_status;

	/**
	 * Additional context data.
	 *
	 * @var array
	 */
	protected $context;

	/**
	 * Constructor.
	 *
	 * @param string         $message     Exception message.
	 * @param string         $error_code  Error code identifier.
	 * @param int            $http_status HTTP status code. Default 500.
	 * @param array          $context     Additional context data.
	 * @param int            $code        Exception code. Default 0.
	 * @param Throwable|null $previous    Previous exception.
	 */
	public function __construct(
		$message = '',
		$error_code = 'unknown_error',
		$http_status = 500,
		array $context = array(),
		$code = 0,
		?Throwable $previous = null
	) {
		parent::__construct( $message, $code, $previous );

		$this->error_code  = $error_code;
		$this->http_status = $http_status;
		$this->context     = $context;
	}

	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Get HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_status() {
		return $this->http_status;
	}

	/**
	 * Get context data.
	 *
	 * @return array
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Convert exception to array.
	 *
	 * @param bool $include_trace Whether to include stack trace. Default false.
	 * @return array
	 */
	public function to_array( $include_trace = false ) {
		$data = array(
			'message'     => $this->getMessage(),
			'error_code'  => $this->error_code,
			'http_status' => $this->http_status,
			'context'     => $this->context,
			'file'        => $this->getFile(),
			'line'        => $this->getLine(),
		);

		if ( $include_trace || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			$data['trace'] = $this->getTraceAsString();
		}

		return $data;
	}

	/**
	 * Convert exception to JSON.
	 *
	 * @param bool $include_trace Whether to include stack trace. Default false.
	 * @return string
	 */
	public function to_json( $include_trace = false ) {
		return wp_json_encode( $this->to_array( $include_trace ) );
	}

	/**
	 * Get exception as WP_Error.
	 *
	 * @return WP_Error
	 */
	public function to_wp_error() {
		return new WP_Error(
			$this->error_code,
			$this->getMessage(),
			array(
				'status'  => $this->http_status,
				'context' => $this->context,
			)
		);
	}

	/**
	 * Log this exception.
	 *
	 * @param string $level Log level. Default 'error'.
	 */
	public function log( $level = 'error' ) {
		$context = array_merge(
			$this->context,
			array(
				'exception'   => get_class( $this ),
				'error_code'  => $this->error_code,
				'http_status' => $this->http_status,
				'file'        => $this->getFile(),
				'line'        => $this->getLine(),
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$context['trace'] = $this->getTraceAsString();
		}

		switch ( $level ) {
			case 'critical':
				WCH_Logger::critical( $this->getMessage(), $context );
				break;
			case 'warning':
				WCH_Logger::warning( $this->getMessage(), $context );
				break;
			case 'info':
				WCH_Logger::info( $this->getMessage(), $context );
				break;
			case 'debug':
				WCH_Logger::debug( $this->getMessage(), $context );
				break;
			default:
				WCH_Logger::error( $this->getMessage(), $context );
				break;
		}
	}
}
