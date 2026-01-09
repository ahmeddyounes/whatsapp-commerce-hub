<?php
/**
 * Audience Calculator Interface
 *
 * Contract for calculating broadcast audience.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\Broadcasts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface AudienceCalculatorInterface
 *
 * Defines the contract for audience calculation operations.
 */
interface AudienceCalculatorInterface {

	/**
	 * Calculate audience count based on criteria.
	 *
	 * @param array $criteria Audience selection criteria.
	 * @return int Estimated number of recipients.
	 */
	public function calculateCount( array $criteria ): int;

	/**
	 * Get campaign recipients.
	 *
	 * @param array $criteria Audience selection criteria.
	 * @param int   $limit    Maximum recipients to return (0 for no limit).
	 * @return array Array of phone numbers.
	 */
	public function getRecipients( array $criteria, int $limit = 0 ): array;

	/**
	 * Validate audience criteria.
	 *
	 * @param array $criteria Audience criteria to validate.
	 * @return array{valid: bool, errors: array} Validation result.
	 */
	public function validateCriteria( array $criteria ): array;

	/**
	 * Get available audience segments.
	 *
	 * @return array List of available segments.
	 */
	public function getAvailableSegments(): array;
}
