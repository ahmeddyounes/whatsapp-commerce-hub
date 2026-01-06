<?php
/**
 * API-specific exception class for WhatsApp Commerce Hub.
 *
 * Extended exception for handling WhatsApp Graph API errors.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_API_Exception
 *
 * Custom exception for WhatsApp Graph API errors.
 */
class WCH_API_Exception extends WCH_Exception {
	/**
	 * Graph API error code.
	 *
	 * @var int|null
	 */
	protected $api_error_code;

	/**
	 * Graph API error type.
	 *
	 * @var string|null
	 */
	protected $api_error_type;

	/**
	 * Graph API error subcode.
	 *
	 * @var int|null
	 */
	protected $api_error_subcode;

	/**
	 * Constructor.
	 *
	 * @param string         $message          Exception message.
	 * @param int|null       $api_error_code   Graph API error code.
	 * @param string|null    $api_error_type   Graph API error type.
	 * @param int|null       $api_error_subcode Graph API error subcode.
	 * @param int            $http_status      HTTP status code. Default 500.
	 * @param array          $context          Additional context data.
	 * @param Throwable|null $previous         Previous exception.
	 */
	public function __construct(
		$message = '',
		$api_error_code = null,
		$api_error_type = null,
		$api_error_subcode = null,
		$http_status = 500,
		array $context = array(),
		?Throwable $previous = null
	) {
		$error_code = 'api_error';
		if ( $api_error_code ) {
			$error_code = 'api_error_' . $api_error_code;
		}

		parent::__construct( $message, $error_code, $http_status, $context, 0, $previous );

		$this->api_error_code    = $api_error_code;
		$this->api_error_type    = $api_error_type;
		$this->api_error_subcode = $api_error_subcode;
	}

	/**
	 * Get Graph API error code.
	 *
	 * @return int|null
	 */
	public function get_api_error_code() {
		return $this->api_error_code;
	}

	/**
	 * Get Graph API error type.
	 *
	 * @return string|null
	 */
	public function get_api_error_type() {
		return $this->api_error_type;
	}

	/**
	 * Get Graph API error subcode.
	 *
	 * @return int|null
	 */
	public function get_api_error_subcode() {
		return $this->api_error_subcode;
	}

	/**
	 * Convert exception to array.
	 *
	 * @param bool $include_trace Whether to include stack trace. Default false.
	 * @return array
	 */
	public function to_array( $include_trace = false ) {
		$data = parent::to_array( $include_trace );

		$data['api_error_code']    = $this->api_error_code;
		$data['api_error_type']    = $this->api_error_type;
		$data['api_error_subcode'] = $this->api_error_subcode;

		return $data;
	}
}
