<?php
/**
 * Secure Vault
 *
 * Provides AES-256-GCM encryption with envelope encryption pattern.
 * Supports key rotation and authenticated encryption.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Security;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SecureVault
 *
 * Secure encryption service using AES-256-GCM with HKDF key derivation.
 */
class SecureVault {

	/**
	 * Encryption algorithm.
	 */
	private const ALGORITHM = 'aes-256-gcm';

	/**
	 * IV length in bytes.
	 */
	private const IV_LENGTH = 12;

	/**
	 * Tag length in bytes.
	 */
	private const TAG_LENGTH = 16;

	/**
	 * Current key version.
	 *
	 * @var int
	 */
	private int $current_key_version;

	/**
	 * Encryption keys by version.
	 *
	 * @var array<int, string>
	 */
	private array $keys = array();

	/**
	 * Installation-unique HKDF salt.
	 *
	 * @var string
	 */
	private string $hkdf_salt;

	/**
	 * Whether the vault is using a weak fallback key.
	 *
	 * @var bool
	 */
	private bool $using_fallback_key = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->loadHkdfSalt();
		$this->loadKeys();
	}

	/**
	 * Load or generate the installation-unique HKDF salt.
	 *
	 * The salt is stored in the database and is unique per WordPress installation.
	 * This prevents rainbow table attacks across multiple installations.
	 *
	 * @return void
	 */
	private function loadHkdfSalt(): void {
		$salt = get_option( 'wch_encryption_hkdf_salt' );

		if ( empty( $salt ) || strlen( $salt ) < 32 ) {
			// Generate a cryptographically secure random salt.
			try {
				$salt = bin2hex( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				// Fallback to a combination of site-specific values.
				// This is less secure but better than a hardcoded value.
				$salt = hash(
					'sha256',
					wp_salt( 'secure_auth' ) . get_option( 'siteurl' ) . wp_generate_uuid4()
				);
			}
			update_option( 'wch_encryption_hkdf_salt', $salt, false );
		}

		$this->hkdf_salt = $salt;
	}

	/**
	 * Load encryption keys from configuration.
	 *
	 * @return void
	 */
	private function loadKeys(): void {
		// Primary key from environment or WordPress constant.
		$primary_key = defined( 'WCH_ENCRYPTION_KEY' )
			? WCH_ENCRYPTION_KEY
			: getenv( 'WCH_ENCRYPTION_KEY' );

		if ( empty( $primary_key ) || ! is_string( $primary_key ) ) {
			// Fallback to WordPress auth key (less secure, for backward compatibility).
			$primary_key              = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
			$this->using_fallback_key = true;

			// Log security warning about using fallback key.
			$this->logSecurityWarning(
				'SecureVault using WordPress fallback key',
				'For production environments, set WCH_ENCRYPTION_KEY in wp-config.php or environment variables. ' .
				'Using WordPress salts is less secure as they may be shared or predictable.'
			);
		}

		// Load key versions from options.
		$key_versions = get_option( 'wch_encryption_key_versions', array() );

		if ( empty( $key_versions ) ) {
			// Initialize with version 1.
			$key_versions = array(
				1 => array(
					'created_at' => time(),
					'active'     => true,
				),
			);
			update_option( 'wch_encryption_key_versions', $key_versions );
		}

		// Derive keys for each version using HKDF.
		$latest_version = 1;
		foreach ( $key_versions as $version => $meta ) {
			$this->keys[ $version ] = $this->deriveKey( $primary_key, "wch-key-v{$version}" );
			$latest_version         = max( $latest_version, (int) $version );

			if ( ! empty( $meta['active'] ) ) {
				$this->current_key_version = (int) $version;
			}
		}

		// Ensure current_key_version is always set (fallback to latest).
		if ( ! isset( $this->current_key_version ) ) {
			$this->current_key_version = $latest_version;
		}
	}

	/**
	 * Derive a key using HKDF.
	 *
	 * Uses an installation-unique salt to prevent rainbow table attacks
	 * across multiple WordPress installations.
	 *
	 * @param string $master_key The master key material.
	 * @param string $context    Context for key derivation.
	 * @return string The derived key (32 bytes for AES-256).
	 */
	private function deriveKey( string $master_key, string $context ): string {
		// Use HKDF with SHA-256 and installation-unique salt.
		return hash_hkdf(
			'sha256',
			$master_key,
			32,
			$context,
			$this->hkdf_salt
		);
	}

	/**
	 * Derive a field-specific key.
	 *
	 * @param string   $field       The field name.
	 * @param int|null $key_version Optional key version.
	 * @return string The derived key.
	 */
	private function deriveFieldKey( string $field, ?int $key_version = null ): string {
		$version  = $key_version ?? $this->current_key_version;
		$base_key = $this->keys[ $version ] ?? $this->keys[ $this->current_key_version ];

		return $this->deriveKey( $base_key, "wch-field-{$field}" );
	}

	/**
	 * Encrypt data using AES-256-GCM.
	 *
	 * @param string      $plaintext The data to encrypt.
	 * @param string|null $field     Optional field name for key derivation.
	 * @return string The encrypted data (base64-encoded with version prefix).
	 * @throws \RuntimeException If encryption fails.
	 */
	public function encrypt( string $plaintext, ?string $field = null ): string {
		if ( empty( $plaintext ) ) {
			return '';
		}

		$key = $field ? $this->deriveFieldKey( $field ) : $this->keys[ $this->current_key_version ];

		// Generate random IV with error handling.
		try {
			$iv = random_bytes( self::IV_LENGTH );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Failed to generate random IV: ' . $e->getMessage(), 0, $e );
		}

		// Encrypt with GCM.
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::ALGORITHM,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext ) {
			// Log detailed error for debugging, but don't expose to caller.
			$this->logSecurityError( 'Encryption failed', openssl_error_string() );
			throw new \RuntimeException( 'Encryption operation failed' );
		}

		// Pack: version (1 byte) + iv (12 bytes) + tag (16 bytes) + ciphertext.
		$packed = pack( 'C', $this->current_key_version ) . $iv . $tag . $ciphertext;

		return 'v2:' . base64_encode( $packed );
	}

	/**
	 * Decrypt data.
	 *
	 * @param string      $encrypted The encrypted data.
	 * @param string|null $field     Optional field name for key derivation.
	 * @return string The decrypted data.
	 * @throws \RuntimeException If decryption fails.
	 */
	public function decrypt( string $encrypted, ?string $field = null ): string {
		if ( empty( $encrypted ) ) {
			return '';
		}

		// Check for version prefix.
		if ( str_starts_with( $encrypted, 'v2:' ) ) {
			return $this->decryptV2( substr( $encrypted, 3 ), $field );
		}

		// Try legacy decryption (v1 - CBC mode).
		return $this->decryptLegacy( $encrypted );
	}

	/**
	 * Decrypt v2 format (GCM).
	 *
	 * @param string      $encrypted Base64-encoded encrypted data.
	 * @param string|null $field     Optional field name.
	 * @return string The decrypted data.
	 * @throws \RuntimeException If decryption fails.
	 */
	private function decryptV2( string $encrypted, ?string $field = null ): string {
		$packed = base64_decode( $encrypted, true );

		if ( false === $packed || strlen( $packed ) < ( 1 + self::IV_LENGTH + self::TAG_LENGTH + 1 ) ) {
			throw new \RuntimeException( 'Invalid encrypted data format' );
		}

		// Unpack components.
		$version    = unpack( 'C', $packed[0] )[1];
		$iv         = substr( $packed, 1, self::IV_LENGTH );
		$tag        = substr( $packed, 1 + self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $packed, 1 + self::IV_LENGTH + self::TAG_LENGTH );

		// Get the appropriate key.
		if ( ! isset( $this->keys[ $version ] ) ) {
			throw new \RuntimeException( "Unknown key version: {$version}" );
		}

		$key = $field ? $this->deriveFieldKey( $field, $version ) : $this->keys[ $version ];

		// Decrypt.
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::ALGORITHM,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			// Log detailed error for debugging, but don't expose to caller.
			$this->logSecurityError( 'Decryption failed', openssl_error_string() );
			throw new \RuntimeException( 'Decryption operation failed' );
		}

		return $plaintext;
	}

	/**
	 * Decrypt legacy format (CBC mode from v1).
	 *
	 * @param string $encrypted The encrypted data.
	 * @return string The decrypted data.
	 * @throws \RuntimeException If decryption fails.
	 */
	private function decryptLegacy( string $encrypted ): string {
		// Use legacy encryption class if available.
		if ( class_exists( 'WCH_Encryption' ) ) {
			try {
				$decrypted = \WCH_Encryption::instance()->decrypt( $encrypted );
				if ( $decrypted ) {
					return $decrypted;
				}
			} catch ( \Exception $e ) {
				// Fall through to error.
			}
		}

		throw new \RuntimeException( 'Unable to decrypt legacy format' );
	}

	/**
	 * Rotate to a new key version.
	 *
	 * Uses database locking to prevent race conditions when multiple
	 * processes attempt to rotate keys simultaneously.
	 *
	 * @return int The new key version.
	 * @throws \RuntimeException If lock acquisition fails after retries.
	 */
	public function rotateKey(): int {
		global $wpdb;

		$lock_name    = 'wch_key_rotation_lock';
		$lock_timeout = 30; // seconds
		$max_retries  = 3;
		$retry_delay  = 100000; // microseconds (0.1 seconds)

		// Track whether we acquired the lock for proper cleanup.
		$lock_acquired = false;

		try {
			// Acquire database-level lock to prevent concurrent rotations.
			for ( $attempt = 0; $attempt < $max_retries; $attempt++ ) {
				$result = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT GET_LOCK(%s, %d)',
						$lock_name,
						$lock_timeout
					)
				);

				if ( '1' === $result ) {
					$lock_acquired = true;
					break;
				}

				if ( $attempt < $max_retries - 1 ) {
					usleep( $retry_delay * ( $attempt + 1 ) );
				}
			}

			if ( ! $lock_acquired ) {
				throw new \RuntimeException( 'Failed to acquire key rotation lock after ' . $max_retries . ' attempts' );
			}

			// Re-read key versions inside lock to get current state.
			$key_versions = get_option( 'wch_encryption_key_versions', array() );
			$old_version  = $this->current_key_version;

			// Deactivate current key.
			foreach ( $key_versions as $version => $meta ) {
				$key_versions[ $version ]['active'] = false;
			}

			// Create new version.
			$new_version                  = empty( $key_versions ) ? 1 : max( array_keys( $key_versions ) ) + 1;
			$key_versions[ $new_version ] = array(
				'created_at' => time(),
				'active'     => true,
			);

			update_option( 'wch_encryption_key_versions', $key_versions );

			// Reload keys.
			$this->loadKeys();

			// Log the rotation.
			do_action(
				'wch_security_log',
				'key_rotation',
				array(
					'old_version' => $old_version,
					'new_version' => $new_version,
				)
			);

			return $new_version;
		} finally {
			// Only release if we actually acquired the lock.
			if ( $lock_acquired ) {
				$wpdb->query(
					$wpdb->prepare(
						'SELECT RELEASE_LOCK(%s)',
						$lock_name
					)
				);
			}
		}
	}

	/**
	 * Re-encrypt data with the current key.
	 *
	 * @param string      $encrypted The encrypted data.
	 * @param string|null $field     Optional field name.
	 * @return string The re-encrypted data.
	 */
	public function reencrypt( string $encrypted, ?string $field = null ): string {
		$plaintext = $this->decrypt( $encrypted, $field );
		return $this->encrypt( $plaintext, $field );
	}

	/**
	 * Get the current key version.
	 *
	 * @return int The current key version.
	 */
	public function getCurrentKeyVersion(): int {
		return $this->current_key_version;
	}

	/**
	 * Check if data needs re-encryption (uses old key).
	 *
	 * @param string $encrypted The encrypted data.
	 * @return bool True if re-encryption is needed.
	 */
	public function needsReencryption( string $encrypted ): bool {
		if ( empty( $encrypted ) ) {
			return false;
		}

		// Legacy format always needs re-encryption.
		if ( ! str_starts_with( $encrypted, 'v2:' ) ) {
			return true;
		}

		// Check version.
		$packed = base64_decode( substr( $encrypted, 3 ), true );
		if ( $packed && strlen( $packed ) > 0 ) {
			$version = unpack( 'C', $packed[0] )[1];
			return $version < $this->current_key_version;
		}

		return true;
	}

	/**
	 * Generate a secure random token.
	 *
	 * @param int $length Token length in bytes.
	 * @return string Hex-encoded token.
	 */
	public function generateToken( int $length = 32 ): string {
		return bin2hex( random_bytes( $length ) );
	}

	/**
	 * Create a hash for comparison (constant-time safe).
	 *
	 * @param string $data The data to hash.
	 * @return string The hash.
	 */
	public function hash( string $data ): string {
		$key = $this->keys[ $this->current_key_version ];
		return hash_hmac( 'sha256', $data, $key );
	}

	/**
	 * Verify a hash (constant-time safe).
	 *
	 * @param string $data The data to verify.
	 * @param string $hash The hash to compare against.
	 * @return bool True if the hash matches.
	 */
	public function verifyHash( string $data, string $hash ): bool {
		$computed = $this->hash( $data );
		return hash_equals( $hash, $computed );
	}

	/**
	 * Log a security error with details but without exposing to callers.
	 *
	 * This method logs the detailed error message (including OpenSSL errors)
	 * for debugging purposes while ensuring sensitive details are not exposed
	 * in exception messages that might be displayed to users.
	 *
	 * @param string      $message       The generic error message.
	 * @param string|null $detailed_error The detailed error (e.g., from openssl_error_string()).
	 */
	private function logSecurityError( string $message, ?string $detailed_error ): void {
		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::error(
				$message,
				array(
					'category'      => 'security',
					'openssl_error' => $detailed_error ?: 'No additional details',
				)
			);
		} elseif ( function_exists( 'error_log' ) ) {
			// Fallback to PHP error log if WCH_Logger is not available.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WCH SecureVault] %s: %s', $message, $detailed_error ?: 'No details' ) );
		}
	}

	/**
	 * Log a security warning.
	 *
	 * Used for non-critical security concerns that should be addressed
	 * but don't prevent operation.
	 *
	 * @param string $message The warning message.
	 * @param string $details Additional details.
	 */
	private function logSecurityWarning( string $message, string $details ): void {
		// Only log once per request to avoid flooding logs.
		static $logged = array();
		$key           = md5( $message );

		if ( isset( $logged[ $key ] ) ) {
			return;
		}
		$logged[ $key ] = true;

		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::warning(
				$message,
				array(
					'category' => 'security',
					'details'  => $details,
				)
			);
		} elseif ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Only log to PHP error log in debug mode for warnings.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WCH SecureVault Warning] %s: %s', $message, $details ) );
		}
	}

	/**
	 * Check if the vault is using a weak fallback encryption key.
	 *
	 * @return bool True if using the fallback WordPress salt instead of a dedicated key.
	 */
	public function isUsingFallbackKey(): bool {
		return $this->using_fallback_key;
	}

	/**
	 * Get the HKDF salt (for migration/backup purposes only).
	 *
	 * Warning: This method exposes the salt. Use only for backup/restore operations.
	 *
	 * @return string The HKDF salt.
	 */
	public function getHkdfSalt(): string {
		return $this->hkdf_salt;
	}
}
