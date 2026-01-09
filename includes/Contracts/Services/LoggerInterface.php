<?php
/**
 * Logger Interface
 *
 * Contract for logging services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface LoggerInterface
 *
 * Defines the contract for logging operations.
 * Follows PSR-3 LoggerInterface patterns.
 */
interface LoggerInterface {

	/**
	 * Log a debug message.
	 *
	 * @param string               $message Log message.
	 * @param string               $context Log context/category.
	 * @param array<string, mixed> $data    Additional data to log.
	 * @return void
	 */
	public function debug( string $message, string $context = 'general', array $data = array() ): void;

	/**
	 * Log an info message.
	 *
	 * @param string               $message Log message.
	 * @param string               $context Log context/category.
	 * @param array<string, mixed> $data    Additional data to log.
	 * @return void
	 */
	public function info( string $message, string $context = 'general', array $data = array() ): void;

	/**
	 * Log a warning message.
	 *
	 * @param string               $message Log message.
	 * @param string               $context Log context/category.
	 * @param array<string, mixed> $data    Additional data to log.
	 * @return void
	 */
	public function warning( string $message, string $context = 'general', array $data = array() ): void;

	/**
	 * Log an error message.
	 *
	 * @param string               $message Log message.
	 * @param string               $context Log context/category.
	 * @param array<string, mixed> $data    Additional data to log.
	 * @return void
	 */
	public function error( string $message, string $context = 'general', array $data = array() ): void;

	/**
	 * Log a critical message.
	 *
	 * @param string               $message Log message.
	 * @param string               $context Log context/category.
	 * @param array<string, mixed> $data    Additional data to log.
	 * @return void
	 */
	public function critical( string $message, string $context = 'general', array $data = array() ): void;

	/**
	 * Log with a specific level.
	 *
	 * @param string               $level   Log level (debug, info, warning, error, critical).
	 * @param string               $message Log message.
	 * @param string               $context Log context/category.
	 * @param array<string, mixed> $data    Additional data to log.
	 * @return void
	 */
	public function log( string $level, string $message, string $context = 'general', array $data = array() ): void;

	/**
	 * Get the current request ID.
	 *
	 * @return string Unique request identifier.
	 */
	public function getRequestId(): string;
}
