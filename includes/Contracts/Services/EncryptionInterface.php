<?php
/**
 * Encryption Interface
 *
 * Contract for encryption services.
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
 * Interface EncryptionInterface
 *
 * Defines the contract for encryption/decryption operations.
 */
interface EncryptionInterface {

	/**
	 * Encrypt a value.
	 *
	 * @param string $value The value to encrypt.
	 * @return string The encrypted value (base64 encoded).
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt( string $value ): string;

	/**
	 * Decrypt a value.
	 *
	 * @param string $encrypted The encrypted value (base64 encoded).
	 * @return string The decrypted value.
	 * @throws \RuntimeException If decryption fails.
	 */
	public function decrypt( string $encrypted ): string;

	/**
	 * Hash a value (one-way).
	 *
	 * @param string $value The value to hash.
	 * @return string The hashed value.
	 */
	public function hash( string $value ): string;

	/**
	 * Verify a value against a hash.
	 *
	 * @param string $value The value to verify.
	 * @param string $hash  The hash to verify against.
	 * @return bool True if the value matches the hash.
	 */
	public function verify( string $value, string $hash ): bool;

	/**
	 * Generate a secure random token.
	 *
	 * @param int $length Token length in bytes.
	 * @return string Hex-encoded random token.
	 */
	public function generateToken( int $length = 32 ): string;

	/**
	 * Check if encryption is available.
	 *
	 * @return bool True if encryption is available.
	 */
	public function isAvailable(): bool;
}
