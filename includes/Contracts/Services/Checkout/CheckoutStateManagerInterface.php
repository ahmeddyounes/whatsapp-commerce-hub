<?php
/**
 * Checkout State Manager Interface
 *
 * Contract for managing checkout session state.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Services\Checkout;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CheckoutStateManagerInterface
 *
 * Defines contract for checkout state persistence and retrieval.
 */
interface CheckoutStateManagerInterface {

	/**
	 * Checkout step constants.
	 */
	public const STEP_ADDRESS         = 'address';
	public const STEP_SHIPPING_METHOD = 'shipping_method';
	public const STEP_PAYMENT_METHOD  = 'payment_method';
	public const STEP_REVIEW          = 'review';
	public const STEP_CONFIRM         = 'confirm';

	/**
	 * Default checkout timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 900;

	/**
	 * Initialize a new checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Initial state data.
	 */
	public function initializeState( string $phone ): array;

	/**
	 * Save checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @param array  $state State data.
	 * @return bool Success status.
	 */
	public function saveState( string $phone, array $state ): bool;

	/**
	 * Load checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return array|null State data or null if not found.
	 */
	public function loadState( string $phone ): ?array;

	/**
	 * Clear checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function clearState( string $phone ): bool;

	/**
	 * Update specific state fields.
	 *
	 * @param string $phone  Customer phone number.
	 * @param array  $fields Fields to update.
	 * @return bool Success status.
	 */
	public function updateState( string $phone, array $fields ): bool;

	/**
	 * Advance to the next checkout step.
	 *
	 * @param string $phone Customer phone number.
	 * @param string $step  Step to advance to.
	 * @return bool Success status.
	 */
	public function advanceToStep( string $phone, string $step ): bool;

	/**
	 * Get the previous step in checkout flow.
	 *
	 * @param string $currentStep Current step.
	 * @return string Previous step.
	 */
	public function getPreviousStep( string $currentStep ): string;

	/**
	 * Get checkout timeout in seconds.
	 *
	 * @return int Timeout in seconds.
	 */
	public function getCheckoutTimeout(): int;

	/**
	 * Check if checkout has timed out.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if timed out.
	 */
	public function hasTimedOut( string $phone ): bool;

	/**
	 * Extend checkout timeout.
	 *
	 * @param string $phone   Customer phone number.
	 * @param int    $seconds Additional seconds.
	 * @return bool Success status.
	 */
	public function extendTimeout( string $phone, int $seconds = 900 ): bool;

	/**
	 * Get data for a specific checkout step.
	 *
	 * @param string $phone Customer phone number.
	 * @param string $step  Checkout step.
	 * @return array Step-specific data.
	 */
	public function getStepData( string $phone, string $step ): array;
}
