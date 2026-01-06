<?php
/**
 * Encryption Helper Class
 *
 * Handles encryption and decryption of sensitive data using OpenSSL.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Encryption
 */
class WCH_Encryption {
	/**
	 * Encryption method.
	 *
	 * @var string
	 */
	const ENCRYPTION_METHOD = 'aes-256-cbc';

	/**
	 * Encryption key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Use WordPress auth salt as encryption key.
		$this->key = wp_salt( 'auth' );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $value The value to encrypt.
	 * @return string|false The encrypted value or false on failure.
	 */
	public function encrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Generate an initialization vector.
		$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		// Encrypt the value.
		$encrypted = openssl_encrypt(
			$value,
			self::ENCRYPTION_METHOD,
			$this->get_key(),
			0,
			$iv
		);

		if ( false === $encrypted ) {
			return false;
		}

		// Combine IV and encrypted data, then base64 encode.
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a string.
	 *
	 * @param string $value The value to decrypt.
	 * @return string|false The decrypted value or false on failure.
	 */
	public function decrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Base64 decode the value.
		$decoded = base64_decode( $value, true );

		if ( false === $decoded ) {
			return false;
		}

		// Extract IV and encrypted data.
		$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		$iv        = substr( $decoded, 0, $iv_length );
		$encrypted = substr( $decoded, $iv_length );

		// Decrypt the value.
		$decrypted = openssl_decrypt(
			$encrypted,
			self::ENCRYPTION_METHOD,
			$this->get_key(),
			0,
			$iv
		);

		return $decrypted;
	}

	/**
	 * Get the encryption key derived from WordPress salt.
	 *
	 * @return string
	 */
	private function get_key() {
		// Hash the key to ensure it's the correct length for AES-256.
		return hash( 'sha256', $this->key, true );
	}

	/**
	 * Check if a value is encrypted.
	 *
	 * @param string $value The value to check.
	 * @return bool
	 */
	public function is_encrypted( $value ) {
		if ( empty( $value ) ) {
			return false;
		}

		// Check if the value is base64 encoded.
		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			return false;
		}

		// Check if the decoded value has the correct length for IV.
		$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		return strlen( $decoded ) > $iv_length;
	}
}
