<?php
/**
 * Encryption
 *
 * Handles encryption and decryption of sensitive data using OpenSSL with authenticated encryption.
 *
 * @package WhatsAppCommerceHub
 * @subpackage Infrastructure\Security
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Security;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Encryption
 *
 * Provides AES-256-CBC encryption with HMAC-SHA256 authentication.
 */
class Encryption {
	/**
	 * Encryption method.
	 */
	private const ENCRYPTION_METHOD = 'aes-256-cbc';

	/**
	 * HMAC algorithm.
	 */
	private const HMAC_ALGORITHM = 'sha256';

	/**
	 * HMAC length in bytes.
	 */
	private const HMAC_LENGTH = 32;

	/**
	 * Encryption key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * IV length for current cipher.
	 *
	 * @var int
	 */
	private int $ivLength;

	/**
	 * Constructor.
	 *
	 * @param string|null $key Custom encryption key (optional, uses WordPress auth salt if not provided).
	 */
	public function __construct( ?string $key = null ) {
		// Use WordPress auth salt as encryption key if not provided.
		$this->key      = $key ?? wp_salt( 'auth' );
		$this->ivLength = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
	}

	/**
	 * Encrypt a string.
	 *
	 * Uses AES-256-CBC with HMAC-SHA256 for authenticated encryption.
	 * Format: base64(HMAC + IV + ciphertext)
	 *
	 * @param string $value The value to encrypt.
	 * @return string The encrypted value.
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt( string $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		try {
			// Generate an initialization vector using cryptographically secure RNG.
			$iv = random_bytes( $this->ivLength );

			// Encrypt the value using raw output for HMAC calculation.
			$encrypted = openssl_encrypt(
				$value,
				self::ENCRYPTION_METHOD,
				$this->getKey(),
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( false === $encrypted ) {
				throw new \RuntimeException( 'Encryption failed: ' . openssl_error_string() );
			}

			// Generate HMAC for authentication (prevents padding oracle attacks).
			$hmac = hash_hmac( self::HMAC_ALGORITHM, $iv . $encrypted, $this->getHmacKey(), true );

			// Combine HMAC + IV + encrypted data, then base64 encode.
			return base64_encode( $hmac . $iv . $encrypted );

		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Encryption failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Decrypt a string.
	 *
	 * Verifies HMAC before decrypting to prevent padding oracle attacks.
	 *
	 * @param string $value The value to decrypt.
	 * @return string The decrypted value.
	 * @throws \RuntimeException If decryption fails.
	 */
	public function decrypt( string $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		// Base64 decode the value.
		$decoded = base64_decode( $value, true );

		if ( false === $decoded ) {
			throw new \RuntimeException( 'Invalid base64 encoding' );
		}

		if ( strlen( $decoded ) < ( self::HMAC_LENGTH + $this->ivLength + 1 ) ) {
			throw new \RuntimeException( 'Invalid encrypted data format' );
		}

		return $this->decryptNewFormat( $decoded );
	}

	/**
	 * Decrypt new format (with HMAC).
	 *
	 * @param string $decoded Decoded data.
	 * @return string Decrypted value.
	 * @throws \RuntimeException If HMAC verification or decryption fails.
	 */
	private function decryptNewFormat( string $decoded ): string {
		// Extract components: HMAC + IV + ciphertext.
		$hmac      = substr( $decoded, 0, self::HMAC_LENGTH );
		$iv        = substr( $decoded, self::HMAC_LENGTH, $this->ivLength );
		$encrypted = substr( $decoded, self::HMAC_LENGTH + $this->ivLength );

		// Verify HMAC BEFORE decrypting (prevents padding oracle attacks).
		$expectedHmac = hash_hmac( self::HMAC_ALGORITHM, $iv . $encrypted, $this->getHmacKey(), true );

		if ( ! hash_equals( $expectedHmac, $hmac ) ) {
			throw new \RuntimeException( 'HMAC verification failed - possible tampering or wrong key' );
		}

		// Decrypt the value.
		$decrypted = openssl_decrypt(
			$encrypted,
			self::ENCRYPTION_METHOD,
			$this->getKey(),
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $decrypted ) {
			throw new \RuntimeException( 'Decryption failed: ' . openssl_error_string() );
		}

		return $decrypted;
	}

	/**
	 * Check if a value is encrypted.
	 *
	 * @param string $value The value to check.
	 * @return bool True if value appears to be encrypted.
	 */
	public function isEncrypted( string $value ): bool {
		if ( empty( $value ) ) {
			return false;
		}

		// Check if the value is base64 encoded.
		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			return false;
		}

		// Check if the decoded value has the correct minimum length.
		return strlen( $decoded ) > $this->ivLength;
	}

	/**
	 * Rotate encryption key.
	 *
	 * Re-encrypts a value with a new key.
	 *
	 * @param string $value     The encrypted value.
	 * @param string $oldKey    The old encryption key.
	 * @param string $newKey    The new encryption key.
	 * @return string The value encrypted with the new key.
	 * @throws \RuntimeException If rotation fails.
	 */
	public function rotateKey( string $value, string $oldKey, string $newKey ): string {
		// Decrypt with old key.
		$oldEncryption = new self( $oldKey );
		$decrypted     = $oldEncryption->decrypt( $value );

		// Encrypt with new key.
		$newEncryption = new self( $newKey );
		return $newEncryption->encrypt( $decrypted );
	}

	/**
	 * Get the encryption key derived from base key.
	 *
	 * @return string
	 */
	private function getKey(): string {
		// Hash the key to ensure it's the correct length for AES-256 (32 bytes).
		return hash( 'sha256', $this->key, true );
	}

	/**
	 * Get the HMAC key derived from base key.
	 *
	 * Uses a different derivation than the encryption key for security.
	 * Encrypt-then-MAC requires separate keys for encryption and authentication.
	 *
	 * @return string
	 */
	private function getHmacKey(): string {
		// Derive HMAC key separately from encryption key using HKDF-like approach.
		return hash( 'sha256', $this->key . 'hmac_authentication', true );
	}

	/**
	 * Encrypt array data.
	 *
	 * @param array<mixed> $data Data to encrypt.
	 * @return string Encrypted JSON string.
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encryptArray( array $data ): string {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			throw new \RuntimeException( 'Failed to encode array to JSON' );
		}

		return $this->encrypt( $json );
	}

	/**
	 * Decrypt array data.
	 *
	 * @param string $value Encrypted value.
	 * @return array<mixed> Decrypted array.
	 * @throws \RuntimeException If decryption fails.
	 */
	public function decryptArray( string $value ): array {
		$json = $this->decrypt( $value );
		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException( 'Failed to decode JSON: ' . json_last_error_msg() );
		}

		return $data;
	}
}
