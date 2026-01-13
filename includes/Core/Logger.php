<?php
/**
 * Logger
 *
 * Core logging service with PII sanitization and WordPress integration.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Core
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Core;

use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * Core logging service with context, PII sanitization, and WordPress integration.
 */
class Logger implements LoggerInterface {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Log levels in order of severity.
	 */
	public const LEVEL_DEBUG    = 'debug';
	public const LEVEL_INFO     = 'info';
	public const LEVEL_WARNING  = 'warning';
	public const LEVEL_ERROR    = 'error';
	public const LEVEL_CRITICAL = 'critical';

	/**
	 * Minimum log level to record.
	 *
	 * @var string
	 */
	protected string $minLevel;

	/**
	 * Current request ID for correlation.
	 *
	 * @var string
	 */
	protected string $requestId;

	/**
	 * PII patterns to sanitize.
	 *
	 * @var array<string, string>
	 */
	protected array $piiPatterns = [
		'phone'       => '/\b(\+?[1-9]\d{6,14})\b/',
		'email'       => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
		'credit_card' => '/\b(?:\d[ -]*?){13,16}\b/',
		'ip_address'  => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
	];

	/**
	 * Level priority map.
	 *
	 * @var array<string, int>
	 */
	protected static array $levelPriority = [
		self::LEVEL_DEBUG    => 100,
		self::LEVEL_INFO     => 200,
		self::LEVEL_WARNING  => 300,
		self::LEVEL_ERROR    => 400,
		self::LEVEL_CRITICAL => 500,
	];

	/**
	 * Maximum log file size in bytes (5MB).
	 */
	protected const MAX_LOG_SIZE = 5242880;

	/**
	 * Maximum number of log files to keep.
	 */
	protected const MAX_LOG_FILES = 10;

	/**
	 * Constructor.
	 *
	 * @param string $minLevel   Minimum log level (default: debug in WP_DEBUG, info otherwise).
	 * @param string $requestId  Request ID for correlation (auto-generated if empty).
	 */
	public function __construct( string $minLevel = '', string $requestId = '' ) {
		$this->minLevel  = $minLevel ?: $this->getDefaultMinLevel();
		$this->requestId = $requestId ?: $this->generateRequestId();
	}

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * {@inheritdoc}
	 */
	public function debug( string $message, string $context = 'general', array $data = [] ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function info( string $message, string $context = 'general', array $data = [] ): void {
		$this->log( self::LEVEL_INFO, $message, $context, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function warning( string $message, string $context = 'general', array $data = [] ): void {
		$this->log( self::LEVEL_WARNING, $message, $context, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function error( string $message, string $context = 'general', array $data = [] ): void {
		$this->log( self::LEVEL_ERROR, $message, $context, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function critical( string $message, string $context = 'general', array $data = [] ): void {
		$this->log( self::LEVEL_CRITICAL, $message, $context, $data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function log( string $level, string $message, string $context = 'general', array $data = [] ): void {
		// Check if level meets minimum threshold.
		if ( ! $this->shouldLog( $level ) ) {
			return;
		}

		// Sanitize PII from message and data.
		$sanitizedMessage = $this->sanitizePii( $message );
		$sanitizedData    = $this->sanitizeDataPii( $data );

		// Build log entry.
		$entry = $this->buildLogEntry( $level, $sanitizedMessage, $context, $sanitizedData );

		// Write to log file.
		$this->writeLog( $entry, $context );

		// Fire WordPress action for external integrations.
		do_action( 'wch_log', $level, $sanitizedMessage, $context, $sanitizedData, $this->requestId );
		do_action( "wch_log_{$level}", $sanitizedMessage, array_merge( $sanitizedData, [ 'context' => $context ] ) );

		// Also log to WooCommerce if available and level is warning or higher.
		if ( $this->shouldLogToWooCommerce( $level ) ) {
			$this->logToWooCommerce( $level, $sanitizedMessage, $context, $sanitizedData );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getRequestId(): string {
		return $this->requestId;
	}

	/**
	 * Get log files list.
	 *
	 * @return array<array{filename: string, size: int, modified: int}>
	 */
	public function getLogFiles(): array {
		$logDir = $this->getLogDirectory();
		$files  = [];

		if ( ! is_dir( $logDir ) ) {
			return $files;
		}

		// Wrap DirectoryIterator in try-catch to handle race conditions
		// where directory becomes inaccessible between is_dir() and iteration.
		try {
			$iterator = new \DirectoryIterator( $logDir );

			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'log' === $file->getExtension() ) {
					$files[] = [
						'filename' => $file->getFilename(),
						'size'     => $file->getSize(),
						'modified' => $file->getMTime(),
					];
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			// Directory became inaccessible - return empty array.
			return [];
		}

		// Sort by modified time descending.
		usort(
			$files,
			function ( $a, $b ) {
				return $b['modified'] - $a['modified'];
			}
		);

		return $files;
	}

	/**
	 * Read log file contents.
	 *
	 * @param string $filename Log filename.
	 * @param int    $lines    Number of lines to read (0 = all).
	 * @param int    $offset   Offset from end of file.
	 * @return string Log contents.
	 */
	public function readLog( string $filename, int $lines = 100, int $offset = 0 ): string {
		$filepath = $this->getLogDirectory() . '/' . basename( $filename );

		if ( ! file_exists( $filepath ) ) {
			return '';
		}

		// Security check: ensure file is in log directory.
		$realPath   = realpath( $filepath );
		$realLogDir = realpath( $this->getLogDirectory() );

		if ( ! $realPath || ! $realLogDir || ! str_starts_with( $realPath, $realLogDir ) ) {
			return '';
		}

		if ( 0 === $lines ) {
			// Prevent memory exhaustion by limiting file reads to MAX_LOG_SIZE.
			// On busy sites, concurrent requests could each load large files.
			$fileSize = filesize( $filepath );
			if ( false === $fileSize ) {
				return '';
			}

			if ( $fileSize > self::MAX_LOG_SIZE ) {
				// File too large - read last portion only.
				return $this->readLastLines( $filepath, 10000, 0 );
			}

			$content = file_get_contents( $filepath );
			return false !== $content ? $content : '';
		}

		// Read last N lines efficiently.
		return $this->readLastLines( $filepath, $lines, $offset );
	}

	/**
	 * Clear log file.
	 *
	 * @param string $filename Log filename.
	 * @return bool True on success.
	 */
	public function clearLog( string $filename ): bool {
		$filepath = $this->getLogDirectory() . '/' . basename( $filename );

		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		// Security check.
		$realPath   = realpath( $filepath );
		$realLogDir = realpath( $this->getLogDirectory() );

		if ( ! $realPath || ! $realLogDir || ! str_starts_with( $realPath, $realLogDir ) ) {
			return false;
		}

		// file_put_contents returns bytes written (0 for empty string) or false on failure.
		// We must check for false explicitly, not cast to bool (since (bool)0 === false).
		return false !== file_put_contents( $filepath, '' );
	}

	/**
	 * Delete log file.
	 *
	 * @param string $filename Log filename.
	 * @return bool True on success.
	 */
	public function deleteLog( string $filename ): bool {
		$filepath = $this->getLogDirectory() . '/' . basename( $filename );

		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		// Security check.
		$realPath   = realpath( $filepath );
		$realLogDir = realpath( $this->getLogDirectory() );

		if ( ! $realPath || ! $realLogDir || ! str_starts_with( $realPath, $realLogDir ) ) {
			return false;
		}

		return wp_delete_file( $filepath );
	}

	/**
	 * Get minimum log level.
	 *
	 * @return string
	 */
	public function getMinLevel(): string {
		return $this->minLevel;
	}

	/**
	 * Set minimum log level.
	 *
	 * @param string $level New minimum level.
	 * @return void
	 */
	public function setMinLevel( string $level ): void {
		if ( isset( self::$levelPriority[ $level ] ) ) {
			$this->minLevel = $level;
		}
	}

	/**
	 * Check if a level should be logged.
	 *
	 * @param string $level Log level to check.
	 * @return bool
	 */
	protected function shouldLog( string $level ): bool {
		$currentPriority = self::$levelPriority[ $level ] ?? 0;
		$minPriority     = self::$levelPriority[ $this->minLevel ] ?? 0;

		return $currentPriority >= $minPriority;
	}

	/**
	 * Get default minimum log level.
	 *
	 * @return string
	 */
	protected function getDefaultMinLevel(): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return self::LEVEL_DEBUG;
		}

		return self::LEVEL_INFO;
	}

	/**
	 * Generate unique request ID.
	 *
	 * @return string
	 */
	protected function generateRequestId(): string {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
	}

	/**
	 * Sanitize PII from message.
	 *
	 * @param string $message Message to sanitize.
	 * @return string Sanitized message.
	 */
	protected function sanitizePii( string $message ): string {
		/**
		 * Filter whether to sanitize PII from logs.
		 *
		 * @param bool $sanitize Whether to sanitize (default: true).
		 */
		if ( ! apply_filters( 'wch_log_sanitize_pii', true ) ) {
			return $message;
		}

		foreach ( $this->piiPatterns as $type => $pattern ) {
			$message = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $type ) {
					return $this->maskPii( $matches[0], $type );
				},
				$message
			) ?? $message;
		}

		return $message;
	}

	/**
	 * Sanitize PII from data array.
	 *
	 * @param array $data Data to sanitize.
	 * @return array Sanitized data.
	 */
	protected function sanitizeDataPii( array $data ): array {
		$sensitiveKeys = [ 'phone', 'email', 'password', 'token', 'secret', 'api_key', 'access_token', 'credit_card', 'cvv' ];

		foreach ( $data as $key => $value ) {
			// Check for sensitive keys.
			foreach ( $sensitiveKeys as $sensitiveKey ) {
				if ( false !== stripos( (string) $key, $sensitiveKey ) ) {
					$data[ $key ] = is_string( $value ) ? $this->maskPii( $value, $sensitiveKey ) : '[REDACTED]';
					continue 2;
				}
			}

			// Recursively sanitize arrays.
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->sanitizeDataPii( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = $this->sanitizePii( $value );
			}
		}

		return $data;
	}

	/**
	 * Mask PII value.
	 *
	 * @param string $value Value to mask.
	 * @param string $type  Type of PII.
	 * @return string Masked value.
	 */
	protected function maskPii( string $value, string $type ): string {
		$length = strlen( $value );

		// For very short values, mask completely to prevent data leakage.
		// e.g., phone "123" should be "***" not "123".
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		switch ( $type ) {
			case 'phone':
				// Show last 4 digits only if length > 4.
				return str_repeat( '*', $length - 4 ) . substr( $value, -4 );

			case 'email':
				$parts = explode( '@', $value );
				if ( 2 === count( $parts ) ) {
					$localLen = strlen( $parts[0] );
					if ( $localLen <= 2 ) {
						// Very short local part - mask completely.
						$local = str_repeat( '*', $localLen );
					} else {
						$local = substr( $parts[0], 0, 2 ) . str_repeat( '*', $localLen - 2 );
					}
					return $local . '@' . $parts[1];
				}
				return str_repeat( '*', $length );

			case 'credit_card':
				// Show last 4 digits only.
				return str_repeat( '*', $length - 4 ) . substr( $value, -4 );

			case 'ip_address':
				// Mask last octet.
				$parts = explode( '.', $value );
				if ( 4 === count( $parts ) ) {
					$parts[3] = 'xxx';
					return implode( '.', $parts );
				}
				return str_repeat( '*', $length );

			default:
				// Default: show first 2 and last 2 characters.
				return substr( $value, 0, 2 ) . str_repeat( '*', $length - 4 ) . substr( $value, -2 );
		}
	}

	/**
	 * Build log entry string.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param string $context Log context.
	 * @param array  $data    Additional data.
	 * @return string Formatted log entry.
	 */
	protected function buildLogEntry( string $level, string $message, string $context, array $data ): string {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$levelUp   = strtoupper( $level );

		$entry = "[{$timestamp}] [{$this->requestId}] [{$levelUp}] [{$context}] {$message}";

		if ( ! empty( $data ) ) {
			// wp_json_encode can return false on encoding errors (circular refs, invalid UTF-8).
			$encoded = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
			if ( false !== $encoded ) {
				$entry .= ' ' . $encoded;
			} else {
				// Fallback: indicate encoding failed without losing the log.
				$entry .= ' [data encoding failed]';
			}
		}

		return $entry;
	}

	/**
	 * Write log entry to file.
	 *
	 * @param string $entry   Log entry.
	 * @param string $context Log context for file selection.
	 * @return void
	 */
	protected function writeLog( string $entry, string $context ): void {
		$logDir = $this->getLogDirectory();

		// Ensure log directory exists.
		if ( ! is_dir( $logDir ) ) {
			wp_mkdir_p( $logDir );

			// Create .htaccess to protect logs.
			$htaccess = $logDir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, "deny from all\n" );
			}

			// Create index.php to prevent directory listing.
			$index = $logDir . '/index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" );
			}
		}

		$logFile = $this->getLogFilePath( $context );

		// Check for log rotation.
		$this->maybeRotateLog( $logFile );

		// Write entry.
		file_put_contents( $logFile, $entry . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Get log directory path.
	 *
	 * @return string
	 */
	protected function getLogDirectory(): string {
		$uploadDir = wp_upload_dir();

		// wp_upload_dir() can return an error state if uploads are disabled
		// or the path cannot be created. Fall back to WP_CONTENT_DIR in that case.
		if ( ! empty( $uploadDir['error'] ) || empty( $uploadDir['basedir'] ) ) {
			return WP_CONTENT_DIR . '/wch-logs';
		}

		return $uploadDir['basedir'] . '/wch-logs';
	}

	/**
	 * Get log file path.
	 *
	 * @param string $context Log context.
	 * @return string
	 */
	protected function getLogFilePath( string $context ): string {
		$date     = gmdate( 'Y-m-d' );
		$filename = "wch-{$context}-{$date}.log";

		return $this->getLogDirectory() . '/' . $filename;
	}

	/**
	 * Rotate log file if needed.
	 *
	 * Uses unique suffix to prevent race conditions in concurrent requests.
	 *
	 * @param string $logFile Log file path.
	 * @return void
	 */
	protected function maybeRotateLog( string $logFile ): void {
		if ( ! file_exists( $logFile ) ) {
			return;
		}

		// Clear stat cache to get accurate file size.
		clearstatcache( true, $logFile );

		if ( filesize( $logFile ) < self::MAX_LOG_SIZE ) {
			return;
		}

		// Use timestamp + microseconds + random suffix to prevent race conditions.
		// Multiple processes checking size simultaneously will generate unique filenames.
		$uniqueSuffix = gmdate( 'His' ) . '-' . substr( uniqid( '', true ), -6 );
		$rotatedFile  = $logFile . '.' . $uniqueSuffix;

		// Attempt rename with error suppression - if file was already rotated
		// by another process, this will fail gracefully.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$renamed = @rename( $logFile, $rotatedFile );

		// Only cleanup if we successfully rotated (avoid multiple cleanups).
		if ( $renamed ) {
			$this->cleanupOldLogs();
		}
	}

	/**
	 * Cleanup old log files.
	 *
	 * @return void
	 */
	protected function cleanupOldLogs(): void {
		$files = $this->getLogFiles();

		if ( count( $files ) <= self::MAX_LOG_FILES ) {
			return;
		}

		// Remove oldest files.
		$toRemove = array_slice( $files, self::MAX_LOG_FILES );

		foreach ( $toRemove as $file ) {
			$this->deleteLog( $file['filename'] );
		}
	}

	/**
	 * Read last N lines from file efficiently.
	 *
	 * @param string $filepath File path.
	 * @param int    $lines    Number of lines.
	 * @param int    $offset   Offset from end.
	 * @return string
	 */
	protected function readLastLines( string $filepath, int $lines, int $offset = 0 ): string {
		$handle = fopen( $filepath, 'r' );

		if ( ! $handle ) {
			return '';
		}

		// Check filesize before proceeding - can return false on error.
		$fileSize = filesize( $filepath );
		if ( false === $fileSize || 0 === $fileSize ) {
			fclose( $handle );
			return '';
		}

		$buffer     = [];
		$lineCount  = 0;
		$skipCount  = 0;
		$chunkSize  = 4096;
		$position   = $fileSize;
		$incomplete = '';
		$done       = false;

		try {
			while ( $position > 0 && $lineCount < ( $lines + $offset ) && ! $done ) {
				$readSize  = min( $chunkSize, $position );
				$position -= $readSize;

				// fseek returns -1 on error, 0 on success.
				if ( -1 === fseek( $handle, $position ) ) {
					break;
				}

				$chunk = fread( $handle, $readSize );

				// fread returns false on error.
				if ( false === $chunk ) {
					break;
				}

				// Prepend incomplete line from previous chunk.
				$chunk      = $chunk . $incomplete;
				$chunkLines = explode( "\n", $chunk );

				// First element might be incomplete.
				$incomplete = array_shift( $chunkLines );

				// Process lines in reverse order.
				$chunkLines = array_reverse( $chunkLines );

				foreach ( $chunkLines as $line ) {
					if ( $skipCount < $offset ) {
						++$skipCount;
						continue;
					}

					if ( $lineCount >= $lines ) {
						$done = true;
						break;
					}

					array_unshift( $buffer, $line );
					++$lineCount;
				}
			}

			// Don't forget the incomplete line at the beginning.
			if ( ! empty( $incomplete ) && $lineCount < $lines && $skipCount >= $offset ) {
				array_unshift( $buffer, $incomplete );
			}
		} finally {
			fclose( $handle );
		}

		return implode( "\n", $buffer );
	}

	/**
	 * Check if should log to WooCommerce.
	 *
	 * @param string $level Log level.
	 * @return bool
	 */
	protected function shouldLogToWooCommerce( string $level ): bool {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return false;
		}

		$wcLevels = [ self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL ];

		return in_array( $level, $wcLevels, true );
	}

	/**
	 * Log to WooCommerce logger.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param string $context Context.
	 * @param array  $data    Additional data.
	 * @return void
	 */
	protected function logToWooCommerce( string $level, string $message, string $context, array $data ): void {
		$logger  = wc_get_logger();
		$source  = 'whatsapp-commerce-hub';
		$fullMsg = "[{$context}] {$message}";

		if ( ! empty( $data ) ) {
			// Handle wp_json_encode potentially returning false.
			$encoded = wp_json_encode( $data );
			if ( false !== $encoded ) {
				$fullMsg .= ' ' . $encoded;
			}
		}

		switch ( $level ) {
			case self::LEVEL_WARNING:
				$logger->warning( $fullMsg, [ 'source' => $source ] );
				break;

			case self::LEVEL_ERROR:
				$logger->error( $fullMsg, [ 'source' => $source ] );
				break;

			case self::LEVEL_CRITICAL:
				$logger->critical( $fullMsg, [ 'source' => $source ] );
				break;
		}
	}
}
