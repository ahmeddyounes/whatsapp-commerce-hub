<?php
/**
 * Logger class for WhatsApp Commerce Hub.
 *
 * Provides comprehensive logging with multiple severity levels,
 * log rotation, and sensitive data sanitization.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Logger
 *
 * Handles all logging operations for the plugin.
 */
class WCH_Logger {
	/**
	 * Log level constants.
	 */
	const LEVEL_DEBUG    = 'DEBUG';
	const LEVEL_INFO     = 'INFO';
	const LEVEL_WARNING  = 'WARNING';
	const LEVEL_ERROR    = 'ERROR';
	const LEVEL_CRITICAL = 'CRITICAL';

	/**
	 * Unique request ID for current request.
	 *
	 * @var string
	 */
	private static $request_id = null;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private static $log_dir = null;

	/**
	 * WooCommerce logger instance.
	 *
	 * @var WC_Logger
	 */
	private static $wc_logger = null;

	/**
	 * Get or generate unique request ID.
	 *
	 * @return string
	 */
	private static function get_request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = uniqid( 'wch_', true );
		}
		return self::$request_id;
	}

	/**
	 * Get log directory path.
	 *
	 * @return string
	 */
	private static function get_log_dir() {
		if ( null === self::$log_dir ) {
			$upload_dir     = wp_upload_dir();
			self::$log_dir = $upload_dir['basedir'] . '/wch-logs';

			// Create directory if it doesn't exist.
			if ( ! file_exists( self::$log_dir ) ) {
				wp_mkdir_p( self::$log_dir );

				// Add .htaccess to prevent direct access.
				$htaccess_file = self::$log_dir . '/.htaccess';
				if ( ! file_exists( $htaccess_file ) ) {
					file_put_contents( $htaccess_file, "deny from all\n" );
				}

				// Add index.php to prevent directory listing.
				$index_file = self::$log_dir . '/index.php';
				if ( ! file_exists( $index_file ) ) {
					file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
				}
			}
		}
		return self::$log_dir;
	}

	/**
	 * Get WooCommerce logger instance.
	 *
	 * @return WC_Logger|null
	 */
	private static function get_wc_logger() {
		if ( null === self::$wc_logger && function_exists( 'wc_get_logger' ) ) {
			self::$wc_logger = wc_get_logger();
		}
		return self::$wc_logger;
	}

	/**
	 * Get log file path for current date.
	 *
	 * @return string
	 */
	private static function get_log_file() {
		$date = gmdate( 'Y-m-d' );
		return self::get_log_dir() . '/wch-' . $date . '.log';
	}

	/**
	 * Sanitize sensitive data from context.
	 *
	 * @param array $context Context array.
	 * @return array Sanitized context.
	 */
	private static function sanitize_context( array $context ) {
		$sensitive_patterns = array(
			'access_token',
			'access-token',
			'accessToken',
			'token',
			'password',
			'passwd',
			'secret',
			'api_key',
			'api-key',
			'apiKey',
			'auth',
			'authorization',
		);

		array_walk_recursive(
			$context,
			function ( &$value, $key ) use ( $sensitive_patterns ) {
				$key_lower = strtolower( $key );
				foreach ( $sensitive_patterns as $pattern ) {
					if ( stripos( $key_lower, $pattern ) !== false ) {
						$value = '***REDACTED***';
						break;
					}
				}
			}
		);

		return $context;
	}

	/**
	 * Format log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 * @return string Formatted log message.
	 */
	private static function format_message( $level, $message, array $context ) {
		$timestamp  = gmdate( 'Y-m-d H:i:s' );
		$request_id = self::get_request_id();

		// Sanitize context.
		$sanitized_context = self::sanitize_context( $context );

		// Format: [TIMESTAMP] [LEVEL] [REQUEST_ID] Message | Context JSON
		$context_json = ! empty( $sanitized_context ) ? ' | ' . wp_json_encode( $sanitized_context ) : '';

		return sprintf(
			'[%s] [%s] [%s] %s%s',
			$timestamp,
			$level,
			$request_id,
			$message,
			$context_json
		);
	}

	/**
	 * Write log entry to file.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	private static function write_log( $level, $message, array $context = array() ) {
		$log_file    = self::get_log_file();
		$log_message = self::format_message( $level, $message, $context ) . PHP_EOL;

		// Write to custom log file.
		error_log( $log_message, 3, $log_file );

		// Write errors and critical to WooCommerce logger.
		if ( in_array( $level, array( self::LEVEL_ERROR, self::LEVEL_CRITICAL ), true ) ) {
			$wc_logger = self::get_wc_logger();
			if ( $wc_logger ) {
				$wc_logger->log( strtolower( $level ), $log_message, array( 'source' => 'whatsapp-commerce-hub' ) );
			}
		}

		// Rotate logs.
		self::rotate_logs();
	}

	/**
	 * Rotate and clean up old log files.
	 */
	private static function rotate_logs() {
		// Only run rotation check occasionally (1% of requests).
		if ( wp_rand( 1, 100 ) > 1 ) {
			return;
		}

		$log_dir = self::get_log_dir();
		$files   = glob( $log_dir . '/wch-*.log' );

		if ( ! $files ) {
			return;
		}

		$cutoff_time = time() - ( 30 * DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff_time ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Log debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function debug( $message, array $context = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::write_log( self::LEVEL_DEBUG, $message, $context );
		}
	}

	/**
	 * Log info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function info( $message, array $context = array() ) {
		self::write_log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function warning( $message, array $context = array() ) {
		self::write_log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function error( $message, array $context = array() ) {
		self::write_log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function critical( $message, array $context = array() ) {
		self::write_log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * Get all log files.
	 *
	 * @return array Array of log files with their details.
	 */
	public static function get_log_files() {
		$log_dir = self::get_log_dir();
		$files   = glob( $log_dir . '/wch-*.log' );

		if ( ! $files ) {
			return array();
		}

		$log_files = array();
		foreach ( $files as $file ) {
			$log_files[] = array(
				'name'     => basename( $file ),
				'path'     => $file,
				'size'     => filesize( $file ),
				'modified' => filemtime( $file ),
			);
		}

		// Sort by modification time, newest first.
		usort(
			$log_files,
			function ( $a, $b ) {
				return $b['modified'] - $a['modified'];
			}
		);

		return $log_files;
	}

	/**
	 * Read log file with optional filtering.
	 *
	 * @param string      $filename Log file name.
	 * @param string|null $level    Optional. Filter by log level.
	 * @param int         $limit    Optional. Maximum lines to return. Default 1000.
	 * @return array Array of log entries.
	 */
	public static function read_log( $filename, $level = null, $limit = 1000 ) {
		$log_dir  = self::get_log_dir();
		$filepath = $log_dir . '/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			return array();
		}

		$lines   = file( $filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$entries = array();

		if ( ! $lines ) {
			return array();
		}

		// Reverse to show newest first.
		$lines = array_reverse( $lines );

		foreach ( $lines as $line ) {
			// Skip if level filter is set and doesn't match.
			if ( $level && stripos( $line, '[' . $level . ']' ) === false ) {
				continue;
			}

			$entries[] = $line;

			if ( count( $entries ) >= $limit ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Delete old log files.
	 *
	 * @param int $days Number of days to keep. Default 30.
	 * @return int Number of files deleted.
	 */
	public static function delete_old_logs( $days = 30 ) {
		$log_dir     = self::get_log_dir();
		$files       = glob( $log_dir . '/wch-*.log' );
		$cutoff_time = time() - ( $days * DAY_IN_SECONDS );
		$deleted     = 0;

		if ( ! $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff_time ) {
				if ( wp_delete_file( $file ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}
}
