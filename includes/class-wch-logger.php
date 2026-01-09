<?php
/**
 * Logger class for WhatsApp Commerce Hub.
 *
 * This class serves as a backward compatibility facade that delegates to
 * the new LoggerService when available. New code should use the DI container:
 *
 * @example
 * // Preferred: Use DI container
 * $logger = wch_get_container()->get(LoggerService::class);
 * $logger->info('Message', 'context', ['data' => 'value']);
 *
 * // Legacy: Static methods still work but are deprecated
 * WCH_Logger::info('Message', ['data' => 'value']);
 *
 * @package WhatsApp_Commerce_Hub
 * @deprecated 3.0.0 Use WhatsAppCommerceHub\Services\LoggerService instead.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WhatsAppCommerceHub\Services\LoggerService;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

/**
 * Class WCH_Logger
 *
 * Backward compatibility facade for LoggerService.
 * All static methods delegate to the container-resolved LoggerService.
 *
 * @deprecated 3.0.0 Use LoggerService via DI container instead.
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
	 * Request timestamp used to detect new requests.
	 *
	 * In persistent PHP environments (PHP-FPM, swoole), static variables
	 * may persist across requests. We track the request time to detect
	 * when a new request starts and regenerate the request ID.
	 *
	 * @var float|null
	 */
	private static $request_time = null;

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
	 * Cached LoggerService instance.
	 *
	 * @var LoggerInterface|null
	 */
	private static $logger_service = null;

	/**
	 * Flag to track if we've successfully resolved the logger.
	 *
	 * @var bool
	 */
	private static $logger_service_resolved = false;

	/**
	 * Get the LoggerService instance from the container.
	 *
	 * Falls back to legacy behavior if container is not available.
	 * Note: Only caches successful retrievals to avoid permanently
	 * caching null if called before container initialization.
	 *
	 * @return LoggerInterface|null
	 */
	private static function get_logger_service(): ?LoggerInterface {
		// Only return cached value if we've successfully resolved before.
		if ( self::$logger_service_resolved ) {
			return self::$logger_service;
		}

		// Try to get LoggerService from container.
		if ( function_exists( 'wch_get_container' ) ) {
			try {
				$container = wch_get_container();
				if ( $container->has( LoggerService::class ) ) {
					self::$logger_service = $container->get( LoggerService::class );
					self::$logger_service_resolved = true;
					return self::$logger_service;
				}
			} catch ( \Throwable $e ) {
				// Don't cache failures - try again next time.
			}
		}

		// Return null but don't cache it - container may be available later.
		return null;
	}

	/**
	 * Get or generate unique request ID.
	 *
	 * Detects new requests in persistent PHP environments by comparing
	 * the current request time with the stored request time. This ensures
	 * each HTTP request gets a unique ID even when static variables persist.
	 *
	 * @return string
	 */
	private static function get_request_id() {
		// Get current request time (available in web contexts).
		$currentRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true );

		// Detect if this is a new request (different timestamp).
		$isNewRequest = ( null === self::$request_time || self::$request_time !== $currentRequestTime );

		if ( $isNewRequest || null === self::$request_id ) {
			self::$request_time = $currentRequestTime;
			self::$request_id   = uniqid( 'wch_', true );
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
			$upload_dir = wp_upload_dir();

			// Handle wp_upload_dir() error state - fall back to WP_CONTENT_DIR.
			if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
				self::$log_dir = WP_CONTENT_DIR . '/wch-logs';
			} else {
				self::$log_dir = $upload_dir['basedir'] . '/wch-logs';
			}

			// Create directory if it doesn't exist.
			if ( ! file_exists( self::$log_dir ) ) {
				$created = wp_mkdir_p( self::$log_dir );

				// Only create protection files if directory was created.
				if ( $created ) {
					// Add .htaccess to prevent direct access.
					$htaccess_file = self::$log_dir . '/.htaccess';
					if ( ! file_exists( $htaccess_file ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						file_put_contents( $htaccess_file, "deny from all\n" );
					}

					// Add index.php to prevent directory listing.
					$index_file = self::$log_dir . '/index.php';
					if ( ! file_exists( $index_file ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
					}
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
		// Credential patterns (always fully redact).
		$credential_patterns = array(
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
			'bearer',
		);

		// PII patterns (mask but keep partial for debugging).
		$pii_patterns = array(
			'phone',
			'customer_phone',
			'billing_phone',
			'from',
			'to',
			'recipient',
			'wa_id',
			'email',
			'customer_email',
			'billing_email',
			'name',
			'customer_name',
			'billing_name',
			'address',
			'billing_address',
			'shipping_address',
			'street',
		);

		array_walk_recursive(
			$context,
			function ( &$value, $key ) use ( $credential_patterns, $pii_patterns ) {
				if ( ! is_string( $value ) ) {
					return;
				}

				$key_lower = strtolower( $key );

				// Check credential patterns first (full redaction).
				foreach ( $credential_patterns as $pattern ) {
					if ( stripos( $key_lower, $pattern ) !== false ) {
						$value = '***REDACTED***';
						return;
					}
				}

				// Check PII patterns (masked redaction).
				foreach ( $pii_patterns as $pattern ) {
					if ( stripos( $key_lower, $pattern ) !== false ) {
						$value = self::mask_pii_value( $value, $pattern );
						return;
					}
				}

				// Final pass: scan ALL string values for phone number patterns
				// regardless of key name to catch phone numbers in arbitrary keys.
				// Skip if already masked (contains asterisks) to avoid double-masking.
				if ( strpos( $value, '***' ) === false ) {
					$value = self::mask_phone_numbers_in_string( $value );
				}
			}
		);

		return $context;
	}

	/**
	 * Mask PII value while keeping partial info for debugging.
	 *
	 * @param string $value   The value to mask.
	 * @param string $pattern The pattern that matched.
	 * @return string Masked value.
	 */
	private static function mask_pii_value( string $value, string $pattern ): string {
		// Handle phone numbers (keep country code and last 2 digits).
		if ( in_array( $pattern, array( 'phone', 'customer_phone', 'billing_phone', 'from', 'to', 'recipient', 'wa_id' ), true ) ) {
			if ( preg_match( '/^\+?\d{10,15}$/', preg_replace( '/\s+/', '', $value ) ) ) {
				$clean = preg_replace( '/\s+/', '', $value );
				if ( strlen( $clean ) > 6 ) {
					return substr( $clean, 0, 4 ) . str_repeat( '*', strlen( $clean ) - 6 ) . substr( $clean, -2 );
				}
				return str_repeat( '*', strlen( $clean ) );
			}
		}

		// Handle email addresses.
		if ( in_array( $pattern, array( 'email', 'customer_email', 'billing_email' ), true ) ) {
			if ( filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
				$parts = explode( '@', $value );
				$local = $parts[0];
				$domain = $parts[1] ?? 'example.com';
				$masked_local = strlen( $local ) > 2 ? substr( $local, 0, 1 ) . '***' . substr( $local, -1 ) : '***';
				$domain_parts = explode( '.', $domain );
				$masked_domain = '***.' . end( $domain_parts );
				return $masked_local . '@' . $masked_domain;
			}
		}

		// Default: keep first 3 chars and mask rest.
		if ( strlen( $value ) > 6 ) {
			return substr( $value, 0, 3 ) . str_repeat( '*', min( 10, strlen( $value ) - 3 ) );
		}

		return '***REDACTED***';
	}

	/**
	 * Check if a number looks like a Unix timestamp rather than a phone number.
	 *
	 * @param string $number The number to check.
	 * @return bool True if it looks like a timestamp.
	 */
	private static function looks_like_timestamp( string $number ): bool {
		// Unix timestamps are typically 10 digits starting with 1 (years 2001-2033).
		// We check if the number is within a reasonable range of current time.
		if ( ! ctype_digit( $number ) ) {
			return false;
		}

		$num_length = strlen( $number );

		// Use a narrower time window (5 years) to reduce false positives.
		// 20 years was too broad and matched valid phone numbers (e.g., Indian phones 14xx-19xx).
		$current_time = time();
		$five_years   = 5 * 365 * 24 * 60 * 60;

		// 10-digit numbers could be timestamps (seconds since epoch).
		if ( 10 === $num_length ) {
			$as_int = (int) $number;
			// Check if within 5 years of current time (conservative timestamp range).
			if ( $as_int >= ( $current_time - $five_years ) && $as_int <= ( $current_time + $five_years ) ) {
				return true;
			}
		}

		// 13-digit numbers could be millisecond timestamps.
		if ( 13 === $num_length ) {
			// Convert to int first, then divide to avoid string division issues.
			$as_int = intdiv( (int) $number, 1000 );
			if ( $as_int >= ( $current_time - $five_years ) && $as_int <= ( $current_time + $five_years ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mask phone numbers in a string for GDPR compliance.
	 *
	 * Detects and masks phone numbers in various formats.
	 *
	 * @param string $text The text to sanitize.
	 * @return string Text with phone numbers masked.
	 */
	private static function mask_phone_numbers_in_string( string $text ): string {
		// Pattern to match international phone numbers in various formats:
		// +1234567890, +12 345 6789, +1-234-567-890, etc.
		$patterns = array(
			// International format with country code (must start with +).
			'/\+\d{1,4}[\s\-]?\d{2,4}[\s\-]?\d{2,4}[\s\-]?\d{2,6}/',
			// WhatsApp ID format (country code without +) - labeled context.
			'/(?:wa_id|from|to|phone)[\s]*[:=][\s]*["\']?(\d{10,15})["\']?/i',
			// Standard 10+ digit numbers (but exclude timestamps).
			'/(?<!\d)\d{10,15}(?!\d)/',
		);

		foreach ( $patterns as $index => $pattern ) {
			$text = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $index ) {
					$phone = $matches[0];
					// Clean the phone number for processing.
					$clean = preg_replace( '/[\s\-]/', '', $phone );

					// For the generic digit pattern (index 2), check if it's a timestamp.
					if ( 2 === $index ) {
						$digits_only = preg_replace( '/\D/', '', $clean );
						if ( self::looks_like_timestamp( $digits_only ) ) {
							return $phone; // Don't mask timestamps.
						}
					}

					// Keep context (like "from:" prefix) but mask the number.
					if ( preg_match( '/(wa_id|from|to|phone)[\s]*[:=][\s]*["\']?/i', $phone, $prefix_match ) ) {
						$prefix = $prefix_match[0];
						$number_part = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', '', $phone );
						$clean_number = preg_replace( '/["\'\s\-]/', '', $number_part );
						if ( strlen( $clean_number ) > 6 ) {
							return $prefix . substr( $clean_number, 0, 4 ) . str_repeat( '*', strlen( $clean_number ) - 6 ) . substr( $clean_number, -2 );
						}
						return $prefix . str_repeat( '*', strlen( $clean_number ) );
					}

					// Regular phone number.
					if ( strlen( $clean ) > 6 ) {
						return substr( $clean, 0, 4 ) . str_repeat( '*', strlen( $clean ) - 6 ) . substr( $clean, -2 );
					}
					return str_repeat( '*', strlen( $clean ) );
				},
				$text
			);
		}

		return $text;
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

		// Sanitize phone numbers in message string for GDPR compliance.
		$sanitized_message = self::mask_phone_numbers_in_string( $message );

		// Sanitize context.
		$sanitized_context = self::sanitize_context( $context );

		// Format: [TIMESTAMP] [LEVEL] [REQUEST_ID] Message | Context JSON
		$context_json = ! empty( $sanitized_context ) ? ' | ' . wp_json_encode( $sanitized_context ) : '';

		return sprintf(
			'[%s] [%s] [%s] %s%s',
			$timestamp,
			$level,
			$request_id,
			$sanitized_message,
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
	 * Legacy log method for backward compatibility.
	 *
	 * Supports both old signature: log($level, $message, $context, $context_array)
	 * and simple signature: log($message, $level)
	 *
	 * @deprecated 3.0.0 Use LoggerService::log() via DI container.
	 * @param string       $level_or_message Log level or message.
	 * @param string|array $message_or_level Message or level (for simple signature).
	 * @param string       $context_name     Optional. Context name (legacy).
	 * @param array        $context_data     Optional. Context data.
	 */
	public static function log( $level_or_message, $message_or_level = 'info', $context_name = '', $context_data = array() ) {
		// Detect signature type.
		$valid_levels = array( 'debug', 'info', 'warning', 'error', 'critical' );

		if ( in_array( strtolower( $level_or_message ), $valid_levels, true ) ) {
			// Old signature: log($level, $message, $context_name, $context_array)
			$level   = strtolower( $level_or_message );
			$message = is_string( $message_or_level ) ? $message_or_level : '';
			$context = is_array( $context_data ) ? $context_data : array();
			$context_str = ! empty( $context_name ) ? $context_name : 'legacy';
		} elseif ( is_string( $message_or_level ) && in_array( strtolower( $message_or_level ), $valid_levels, true ) ) {
			// Simple signature: log($message, $level)
			$level   = strtolower( $message_or_level );
			$message = $level_or_message;
			$context = array();
			$context_str = 'legacy';
		} else {
			// Default to info level.
			$level       = 'info';
			$message     = $level_or_message;
			$context     = is_array( $message_or_level ) ? $message_or_level : array();
			$context_str = 'legacy';
		}

		// Try to delegate to LoggerService.
		$logger = self::get_logger_service();

		if ( null !== $logger ) {
			$logger->log( $level, $message, $context_str, $context );
			return;
		}

		// Legacy fallback.
		self::write_log( strtoupper( $level ), $message, $context );
	}

	/**
	 * Log debug message.
	 *
	 * @deprecated 3.0.0 Use LoggerService::debug() via DI container.
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function debug( $message, array $context = array() ) {
		$logger = self::get_logger_service();

		if ( null !== $logger ) {
			$logger->debug( $message, 'legacy', $context );
			return;
		}

		// Legacy fallback.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::write_log( self::LEVEL_DEBUG, $message, $context );
		}
	}

	/**
	 * Log info message.
	 *
	 * @deprecated 3.0.0 Use LoggerService::info() via DI container.
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function info( $message, array $context = array() ) {
		$logger = self::get_logger_service();

		if ( null !== $logger ) {
			$logger->info( $message, 'legacy', $context );
			return;
		}

		// Legacy fallback.
		self::write_log( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log warning message.
	 *
	 * @deprecated 3.0.0 Use LoggerService::warning() via DI container.
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function warning( $message, array $context = array() ) {
		$logger = self::get_logger_service();

		if ( null !== $logger ) {
			$logger->warning( $message, 'legacy', $context );
			return;
		}

		// Legacy fallback.
		self::write_log( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log error message.
	 *
	 * @deprecated 3.0.0 Use LoggerService::error() via DI container.
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function error( $message, array $context = array() ) {
		$logger = self::get_logger_service();

		if ( null !== $logger ) {
			$logger->error( $message, 'legacy', $context );
			return;
		}

		// Legacy fallback.
		self::write_log( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log critical message.
	 *
	 * @deprecated 3.0.0 Use LoggerService::critical() via DI container.
	 * @param string $message Log message.
	 * @param array  $context Optional. Context data with keys: conversation_id, customer_phone, order_id, exception.
	 */
	public static function critical( $message, array $context = array() ) {
		$logger = self::get_logger_service();

		if ( null !== $logger ) {
			$logger->critical( $message, 'legacy', $context );
			return;
		}

		// Legacy fallback.
		self::write_log( self::LEVEL_CRITICAL, $message, $context );
	}

	/**
	 * Get all log files.
	 *
	 * @deprecated 3.0.0 Use LoggerService::getLogFiles() via DI container.
	 * @return array Array of log files with their details.
	 */
	public static function get_log_files() {
		// Try to delegate to LoggerService.
		$logger = self::get_logger_service();

		if ( null !== $logger && method_exists( $logger, 'getLogFiles' ) ) {
			$files = $logger->getLogFiles();

			// Map to legacy format.
			return array_map(
				function ( $file ) {
					return array(
						'name'     => $file['filename'],
						'path'     => '', // Not provided by new service.
						'size'     => $file['size'],
						'modified' => $file['modified'],
					);
				},
				$files
			);
		}

		// Legacy fallback.
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
	 * @deprecated 3.0.0 Use LoggerService::readLog() via DI container.
	 * @param string      $filename Log file name.
	 * @param string|null $level    Optional. Filter by log level.
	 * @param int         $limit    Optional. Maximum lines to return. Default 1000.
	 * @return array Array of log entries.
	 */
	public static function read_log( $filename, $level = null, $limit = 1000 ) {
		// Try to delegate to LoggerService.
		$logger = self::get_logger_service();

		if ( null !== $logger && method_exists( $logger, 'readLog' ) ) {
			$content = $logger->readLog( $filename, $limit );
			$lines   = explode( "\n", $content );

			// Filter by level if specified.
			if ( $level ) {
				$lines = array_filter(
					$lines,
					function ( $line ) use ( $level ) {
						return stripos( $line, '[' . strtoupper( $level ) . ']' ) !== false;
					}
				);
			}

			return array_values( $lines );
		}

		// Legacy fallback.
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
