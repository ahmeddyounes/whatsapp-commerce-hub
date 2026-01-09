<?php
/**
 * Data Validator
 *
 * Provides validation methods for common data types including
 * phone numbers, emails, and timestamps.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Validation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DataValidator
 *
 * Centralized validation utilities for phone, email, and timestamp data.
 */
final class DataValidator {

	/**
	 * Validate a WhatsApp phone number (E.164 format).
	 *
	 * WhatsApp phone numbers should be in E.164 format:
	 * - Start with country code (1-3 digits)
	 * - Followed by subscriber number (4-14 digits)
	 * - Total length 5-15 digits
	 * - No leading + or other characters (WhatsApp API strips these)
	 *
	 * @param string $phone The phone number to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function isValidPhone( string $phone ): bool {
		// Empty phone is invalid.
		if ( '' === $phone ) {
			return false;
		}

		// Remove any whitespace.
		$phone = preg_replace( '/\s+/', '', $phone );

		// WhatsApp E.164 format: numeric only, 5-15 digits.
		// The API strips the leading +, so we check for digits only.
		if ( ! preg_match( '/^[1-9][0-9]{4,14}$/', $phone ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize a phone number by removing invalid characters.
	 *
	 * Removes everything except digits and strips leading zeros.
	 *
	 * @param string $phone The phone number to sanitize.
	 * @return string The sanitized phone number.
	 */
	public static function sanitizePhone( string $phone ): string {
		// Remove all non-digit characters.
		$phone = preg_replace( '/[^0-9]/', '', $phone );

		// Remove leading zeros (invalid for E.164).
		$phone = ltrim( $phone, '0' );

		return $phone;
	}

	/**
	 * Validate and optionally sanitize a phone number.
	 *
	 * @param string $phone    The phone number to validate.
	 * @param bool   $sanitize Whether to sanitize before validation.
	 * @return string|null The valid phone number or null if invalid.
	 */
	public static function validatePhone( string $phone, bool $sanitize = true ): ?string {
		if ( $sanitize ) {
			$phone = self::sanitizePhone( $phone );
		}

		return self::isValidPhone( $phone ) ? $phone : null;
	}

	/**
	 * Validate an email address.
	 *
	 * Uses PHP's filter_var with FILTER_VALIDATE_EMAIL.
	 *
	 * @param string $email The email address to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function isValidEmail( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}

		// Use filter_var for robust email validation.
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Sanitize an email address.
	 *
	 * Trims whitespace and converts to lowercase.
	 *
	 * @param string $email The email to sanitize.
	 * @return string The sanitized email.
	 */
	public static function sanitizeEmail( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Validate and optionally sanitize an email address.
	 *
	 * @param string $email    The email to validate.
	 * @param bool   $sanitize Whether to sanitize before validation.
	 * @return string|null The valid email or null if invalid.
	 */
	public static function validateEmail( string $email, bool $sanitize = true ): ?string {
		if ( $sanitize ) {
			$email = self::sanitizeEmail( $email );
		}

		return self::isValidEmail( $email ) ? $email : null;
	}

	/**
	 * Validate a Unix timestamp.
	 *
	 * Checks that the timestamp is:
	 * - A valid integer or numeric string
	 * - Within a reasonable range (not before 2010, not more than 1 day in future)
	 *
	 * @param mixed $timestamp The timestamp to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function isValidTimestamp( mixed $timestamp ): bool {
		// Must be numeric.
		if ( ! is_numeric( $timestamp ) ) {
			return false;
		}

		$ts = (int) $timestamp;

		// Minimum: Jan 1, 2010 (WhatsApp launched in 2009).
		$min_timestamp = 1262304000;

		// Maximum: 1 day in the future (allow for clock skew).
		$max_timestamp = time() + 86400;

		return $ts >= $min_timestamp && $ts <= $max_timestamp;
	}

	/**
	 * Validate and normalize a timestamp to integer.
	 *
	 * @param mixed $timestamp The timestamp to validate.
	 * @return int|null The valid timestamp as integer or null if invalid.
	 */
	public static function validateTimestamp( mixed $timestamp ): ?int {
		if ( ! self::isValidTimestamp( $timestamp ) ) {
			return null;
		}

		return (int) $timestamp;
	}

	/**
	 * Get current timestamp (for fallback when invalid).
	 *
	 * @return int Current Unix timestamp.
	 */
	public static function getCurrentTimestamp(): int {
		return time();
	}

	/**
	 * Validate a WhatsApp message ID format.
	 *
	 * WhatsApp message IDs are typically:
	 * - Alphanumeric with underscores
	 * - Format like: wamid.XXX== or just alphanumeric string
	 *
	 * @param string $message_id The message ID to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function isValidMessageId( string $message_id ): bool {
		if ( '' === $message_id ) {
			return false;
		}

		// WhatsApp message IDs are alphanumeric with some special chars.
		// Typical format: wamid.HBgNMjUzNTMxMTI4NTU1FQIAERgSM0I5...
		// Also allow simpler formats for testing.
		return (bool) preg_match( '/^[a-zA-Z0-9._=-]{10,200}$/', $message_id );
	}

	/**
	 * Validate and sanitize a message ID.
	 *
	 * @param string $message_id The message ID to validate.
	 * @return string|null The valid message ID or null if invalid.
	 */
	public static function validateMessageId( string $message_id ): ?string {
		$message_id = trim( $message_id );

		return self::isValidMessageId( $message_id ) ? $message_id : null;
	}

	/**
	 * Validate a WhatsApp conversation ID.
	 *
	 * Similar format to message IDs.
	 *
	 * @param string $conversation_id The conversation ID to validate.
	 * @return bool True if valid, false otherwise.
	 */
	public static function isValidConversationId( string $conversation_id ): bool {
		if ( '' === $conversation_id ) {
			return false;
		}

		// Similar format to message IDs.
		return (bool) preg_match( '/^[a-zA-Z0-9._=-]{5,200}$/', $conversation_id );
	}

	/**
	 * Batch validate an array of phones.
	 *
	 * @param array $phones   Array of phone numbers.
	 * @param bool  $sanitize Whether to sanitize before validation.
	 * @return array Array with 'valid' and 'invalid' keys.
	 */
	public static function validatePhones( array $phones, bool $sanitize = true ): array {
		$result = array(
			'valid'   => array(),
			'invalid' => array(),
		);

		foreach ( $phones as $phone ) {
			$validated = self::validatePhone( (string) $phone, $sanitize );
			if ( null !== $validated ) {
				$result['valid'][] = $validated;
			} else {
				$result['invalid'][] = $phone;
			}
		}

		return $result;
	}
}
