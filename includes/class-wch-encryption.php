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
 *
 * @deprecated 2.0.0 Use SecureVault for new code. This class is maintained for backward compatibility.
 */
class WCH_Encryption {
	/**
	 * Encryption method.
	 *
	 * @var string
	 */
	const ENCRYPTION_METHOD = 'aes-256-cbc';

	/**
	 * Singleton instance.
	 *
	 * @var WCH_Encryption|null
	 */
	private static $instance = null;

	/**
	 * Encryption key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Get singleton instance.
	 *
	 * @deprecated 2.1.0 Use wch_get_container()->get(WCH_Encryption::class) instead.
	 * @return WCH_Encryption
	 */
	public static function instance(): WCH_Encryption {
		// Use container if available for consistent instance.
		if ( function_exists( 'wch_get_container' ) ) {
			try {
				$container = wch_get_container();
				if ( $container->has( self::class ) ) {
					return $container->get( self::class );
				}
			} catch ( \Throwable $e ) {
				// Fall through to legacy behavior.
			}
		}

		// Legacy fallback for backwards compatibility.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

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
	 * Uses AES-256-CBC with HMAC-SHA256 for authenticated encryption.
	 * Format: base64(HMAC + IV + ciphertext)
	 *
	 * @param string $value The value to encrypt.
	 * @return string|false The encrypted value or false on failure.
	 */
	public function encrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Generate an initialization vector using cryptographically secure RNG.
		$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		$iv        = random_bytes( $iv_length );

		// Encrypt the value using raw output for HMAC calculation.
		$encrypted = openssl_encrypt(
			$value,
			self::ENCRYPTION_METHOD,
			$this->get_key(),
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $encrypted ) {
			return false;
		}

		// Generate HMAC for authentication (prevents padding oracle attacks).
		// HMAC covers IV + ciphertext to prevent tampering with either.
		$hmac = hash_hmac( 'sha256', $iv . $encrypted, $this->get_hmac_key(), true );

		// Combine HMAC + IV + encrypted data, then base64 encode.
		// Format: [32 bytes HMAC][16 bytes IV][ciphertext]
		return base64_encode( $hmac . $iv . $encrypted );
	}

	/**
	 * Decrypt a string.
	 *
	 * Verifies HMAC before decrypting to prevent padding oracle attacks.
	 * Supports both new format (HMAC + IV + ciphertext) and legacy format (IV + ciphertext).
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

		// Extract components.
		$iv_length   = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		$hmac_length = 32; // SHA-256 produces 32 bytes.

		// Check if this is new format (with HMAC) or legacy format.
		// New format minimum: 32 (HMAC) + 16 (IV) + 1 (ciphertext) = 49 bytes.
		// Legacy format minimum: 16 (IV) + 1 (ciphertext) = 17 bytes.
		$is_new_format = strlen( $decoded ) >= ( $hmac_length + $iv_length + 1 );

		if ( $is_new_format ) {
			// New format: HMAC + IV + ciphertext.
			$hmac      = substr( $decoded, 0, $hmac_length );
			$iv        = substr( $decoded, $hmac_length, $iv_length );
			$encrypted = substr( $decoded, $hmac_length + $iv_length );

			// Verify HMAC BEFORE decrypting (prevents padding oracle attacks).
			$expected_hmac = hash_hmac( 'sha256', $iv . $encrypted, $this->get_hmac_key(), true );

			if ( ! hash_equals( $expected_hmac, $hmac ) ) {
				// HMAC verification failed - data was tampered with or wrong key.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WCH_Encryption: HMAC verification failed - possible tampering or key mismatch' );
				return false;
			}

			// Decrypt the value (raw data).
			$decrypted = openssl_decrypt(
				$encrypted,
				self::ENCRYPTION_METHOD,
				$this->get_key(),
				OPENSSL_RAW_DATA,
				$iv
			);
		} else {
			// Legacy format: IV + base64(ciphertext) - no HMAC.
			// Validate decoded data has sufficient length for IV extraction.
			if ( strlen( $decoded ) <= $iv_length ) {
				return false;
			}

			$iv        = substr( $decoded, 0, $iv_length );
			$encrypted = substr( $decoded, $iv_length );

			// Decrypt the value (legacy used non-raw mode).
			$decrypted = openssl_decrypt(
				$encrypted,
				self::ENCRYPTION_METHOD,
				$this->get_key(),
				0,
				$iv
			);
		}

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
	 * Get the HMAC key derived from WordPress salt.
	 *
	 * Uses a different derivation than the encryption key for security.
	 * Encrypt-then-MAC requires separate keys for encryption and authentication.
	 *
	 * @return string
	 */
	private function get_hmac_key() {
		// Derive HMAC key separately from encryption key using HKDF-like approach.
		// Using 'hmac' context ensures this key differs from the encryption key.
		return hash( 'sha256', $this->key . 'hmac_authentication', true );
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
