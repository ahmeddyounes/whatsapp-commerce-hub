<?php
/**
 * PII Encryptor
 *
 * Specialized encryption for Personally Identifiable Information.
 * Provides searchable encryption with blind indexes.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Security;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PIIEncryptor
 *
 * Handles encryption of PII data with support for searchable blind indexes.
 */
class PIIEncryptor {

	/**
	 * PII field definitions with their encryption requirements.
	 *
	 * @var array<string, array>
	 */
	private array $pii_fields = [
		'phone'         => [
			'searchable' => true,
			'normalize'  => 'phone',
		],
		'email'         => [
			'searchable' => true,
			'normalize'  => 'email',
		],
		'name'          => [ 'searchable' => false ],
		'address_line1' => [ 'searchable' => false ],
		'address_line2' => [ 'searchable' => false ],
		'city'          => [
			'searchable' => true,
			'normalize'  => 'lowercase',
		],
		'postcode'      => [
			'searchable' => true,
			'normalize'  => 'uppercase',
		],
		'country'       => [
			'searchable' => true,
			'normalize'  => 'uppercase',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param SecureVault $vault The secure vault instance.
	 */
	public function __construct( private SecureVault $vault ) {
	}

	/**
	 * Encrypt a PII field value.
	 *
	 * @param string $field The field name.
	 * @param string $value The value to encrypt.
	 * @return array{encrypted: string, blind_index: string|null}
	 */
	public function encrypt( string $field, string $value ): array {
		if ( empty( $value ) ) {
			return [
				'encrypted'   => '',
				'blind_index' => null,
			];
		}

		$field_config = $this->pii_fields[ $field ] ?? [];

		// Encrypt the value.
		$encrypted = $this->vault->encrypt( $value, "pii-{$field}" );

		// Generate blind index if searchable.
		$blind_index = null;
		if ( ! empty( $field_config['searchable'] ) ) {
			$normalized  = $this->normalize( $value, $field_config['normalize'] ?? null );
			$blind_index = $this->generateBlindIndex( $field, $normalized );
		}

		return [
			'encrypted'   => $encrypted,
			'blind_index' => $blind_index,
		];
	}

	/**
	 * Decrypt a PII field value.
	 *
	 * @param string $field     The field name.
	 * @param string $encrypted The encrypted value.
	 * @return string The decrypted value.
	 */
	public function decrypt( string $field, string $encrypted ): string {
		if ( empty( $encrypted ) ) {
			return '';
		}

		return $this->vault->decrypt( $encrypted, "pii-{$field}" );
	}

	/**
	 * Generate a blind index for searching.
	 *
	 * Uses 32-character truncation (128 bits) to provide adequate collision
	 * resistance. With 16 chars (64 bits), birthday paradox collision risk
	 * becomes significant at ~2^32 entries. With 32 chars, this rises to ~2^64.
	 *
	 * @param string $field The field name.
	 * @param string $value The normalized value.
	 * @return string The blind index (truncated hash).
	 */
	private function generateBlindIndex( string $field, string $value ): string {
		$hash = $this->vault->hash( "{$field}:{$value}" );
		// Truncate to 32 chars (128 bits) for collision resistance.
		return substr( $hash, 0, 32 );
	}

	/**
	 * Get a blind index for searching.
	 *
	 * @param string $field The field name.
	 * @param string $value The search value.
	 * @return string The blind index to search for.
	 */
	public function getSearchIndex( string $field, string $value ): string {
		$field_config = $this->pii_fields[ $field ] ?? [];

		if ( empty( $field_config['searchable'] ) ) {
			return '';
		}

		$normalized = $this->normalize( $value, $field_config['normalize'] ?? null );
		return $this->generateBlindIndex( $field, $normalized );
	}

	/**
	 * Normalize a value for consistent indexing.
	 *
	 * @param string      $value The value to normalize.
	 * @param string|null $type  The normalization type.
	 * @return string The normalized value.
	 */
	private function normalize( string $value, ?string $type ): string {
		return match ( $type ) {
			'phone'     => $this->normalizePhone( $value ),
			'email'     => strtolower( trim( $value ) ),
			'lowercase' => strtolower( trim( $value ) ),
			'uppercase' => strtoupper( trim( $value ) ),
			default     => trim( $value ),
		};
	}

	/**
	 * Normalize a phone number for consistent hashing.
	 *
	 * Preserves international format while removing formatting characters.
	 * Leading zeros are kept for countries that use them (e.g., UK mobiles).
	 *
	 * @param string $phone The phone number.
	 * @return string The normalized phone number.
	 */
	private function normalizePhone( string $phone ): string {
		// Trim whitespace.
		$phone = trim( $phone );

		// Preserve + for international format, remove all other non-digits.
		$has_plus   = str_starts_with( $phone, '+' );
		$normalized = preg_replace( '/[^0-9]/', '', $phone );

		// Re-add + prefix if it was present (indicates international format).
		if ( $has_plus ) {
			$normalized = '+' . $normalized;
		}

		return $normalized;
	}

	/**
	 * Encrypt multiple fields at once.
	 *
	 * @param array $data Key-value pairs of field => value.
	 * @return array{encrypted: array, indexes: array}
	 */
	public function encryptMany( array $data ): array {
		$encrypted = [];
		$indexes   = [];

		foreach ( $data as $field => $value ) {
			if ( ! isset( $this->pii_fields[ $field ] ) ) {
				// Not a PII field, keep as-is.
				$encrypted[ $field ] = $value;
				continue;
			}

			$result              = $this->encrypt( $field, $value );
			$encrypted[ $field ] = $result['encrypted'];

			if ( $result['blind_index'] ) {
				$indexes[ "{$field}_index" ] = $result['blind_index'];
			}
		}

		return [
			'encrypted' => $encrypted,
			'indexes'   => $indexes,
		];
	}

	/**
	 * Decrypt multiple fields at once.
	 *
	 * @param array $data Key-value pairs of field => encrypted_value.
	 * @return array Key-value pairs of field => decrypted_value.
	 */
	public function decryptMany( array $data ): array {
		$decrypted = [];

		foreach ( $data as $field => $value ) {
			// Skip index fields.
			if ( str_ends_with( $field, '_index' ) ) {
				continue;
			}

			if ( ! isset( $this->pii_fields[ $field ] ) ) {
				// Not a PII field, keep as-is.
				$decrypted[ $field ] = $value;
				continue;
			}

			$decrypted[ $field ] = $this->decrypt( $field, $value );
		}

		return $decrypted;
	}

	/**
	 * Check if a field is a PII field.
	 *
	 * @param string $field The field name.
	 * @return bool True if PII field.
	 */
	public function isPIIField( string $field ): bool {
		return isset( $this->pii_fields[ $field ] );
	}

	/**
	 * Check if a field is searchable.
	 *
	 * @param string $field The field name.
	 * @return bool True if searchable.
	 */
	public function isSearchable( string $field ): bool {
		return ! empty( $this->pii_fields[ $field ]['searchable'] );
	}

	/**
	 * Register a custom PII field.
	 *
	 * @param string $field      The field name.
	 * @param bool   $searchable Whether the field is searchable.
	 * @param string $normalize  Normalization type.
	 * @return void
	 */
	public function registerField( string $field, bool $searchable = false, string $normalize = '' ): void {
		$this->pii_fields[ $field ] = [
			'searchable' => $searchable,
			'normalize'  => $normalize,
		];
	}

	/**
	 * Mask PII data for display.
	 *
	 * @param string $field The field name.
	 * @param string $value The value to mask.
	 * @return string The masked value.
	 */
	public function mask( string $field, string $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		return match ( $field ) {
			'phone'  => $this->maskPhone( $value ),
			'email'  => $this->maskEmail( $value ),
			'name'   => $this->maskName( $value ),
			default  => $this->maskGeneric( $value ),
		};
	}

	/**
	 * Mask a phone number.
	 *
	 * @param string $phone The phone number.
	 * @return string The masked phone.
	 */
	private function maskPhone( string $phone ): string {
		$length = strlen( $phone );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return substr( $phone, 0, 3 ) . str_repeat( '*', $length - 5 ) . substr( $phone, -2 );
	}

	/**
	 * Mask an email address.
	 *
	 * Masks both local part and domain to prevent information leakage.
	 * The domain is partially masked while preserving TLD for context.
	 *
	 * @param string $email The email address.
	 * @return string The masked email.
	 */
	private function maskEmail( string $email ): string {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return $this->maskGeneric( $email );
		}

		$local  = $parts[0];
		$domain = $parts[1];

		// Mask local part - show first 2 chars.
		$masked_local = strlen( $local ) > 2
			? substr( $local, 0, 2 ) . str_repeat( '*', strlen( $local ) - 2 )
			: str_repeat( '*', strlen( $local ) );

		// Mask domain - preserve TLD but mask the rest.
		$domain_parts = explode( '.', $domain );
		if ( count( $domain_parts ) >= 2 ) {
			$tld            = array_pop( $domain_parts );
			$domain_name    = implode( '.', $domain_parts );
			$masked_domain  = strlen( $domain_name ) > 1
				? substr( $domain_name, 0, 1 ) . str_repeat( '*', strlen( $domain_name ) - 1 )
				: str_repeat( '*', strlen( $domain_name ) );
			$masked_domain .= '.' . $tld;
		} else {
			$masked_domain = str_repeat( '*', strlen( $domain ) );
		}

		return $masked_local . '@' . $masked_domain;
	}

	/**
	 * Mask a name.
	 *
	 * @param string $name The name.
	 * @return string The masked name.
	 */
	private function maskName( string $name ): string {
		$parts = explode( ' ', $name );

		return implode(
			' ',
			array_map(
				function ( $part ) {
					$length = strlen( $part );
					if ( $length <= 1 ) {
							return '*';
					}
					return substr( $part, 0, 1 ) . str_repeat( '*', $length - 1 );
				},
				$parts
			)
		);
	}

	/**
	 * Mask generic text.
	 *
	 * @param string $value The value to mask.
	 * @return string The masked value.
	 */
	private function maskGeneric( string $value ): string {
		$length = strlen( $value );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		$visible = min( 4, (int) ( $length * 0.3 ) );
		return substr( $value, 0, $visible ) . str_repeat( '*', $length - $visible );
	}

	/**
	 * Export PII data (for GDPR).
	 *
	 * @param array $encrypted_data The encrypted data.
	 * @return array The decrypted data for export.
	 */
	public function exportForGDPR( array $encrypted_data ): array {
		return $this->decryptMany( $encrypted_data );
	}

	/**
	 * Anonymize PII data (for GDPR deletion).
	 *
	 * @return array Anonymized placeholder values.
	 */
	public function getAnonymizedValues(): array {
		return [
			'phone'         => '[DELETED]',
			'email'         => '[DELETED]',
			'name'          => '[DELETED]',
			'address_line1' => '[DELETED]',
			'address_line2' => '[DELETED]',
			'city'          => '[DELETED]',
			'postcode'      => '[DELETED]',
			'country'       => '[DELETED]',
		];
	}
}
