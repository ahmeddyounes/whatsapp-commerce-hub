<?php
declare(strict_types=1);

/**
 * Checkout State Manager
 *
 * Manages checkout session state persistence.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\CheckoutStateManagerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutStateManager
 *
 * Handles checkout state storage and retrieval.
 */
class CheckoutStateManager implements CheckoutStateManagerInterface {

	/**
	 * State transient prefix.
	 */
	private const STATE_PREFIX = 'wch_checkout_';

	/**
	 * Step order for navigation.
	 *
	 * @var array<string, int>
	 */
	private const STEP_ORDER = array(
		self::STEP_ADDRESS         => 1,
		self::STEP_SHIPPING_METHOD => 2,
		self::STEP_PAYMENT_METHOD  => 3,
		self::STEP_REVIEW          => 4,
		self::STEP_CONFIRM         => 5,
	);

	/**
	 * Initialize a new checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Initial state data.
	 */
	public function initializeState( string $phone ): array {
		$phone = $this->sanitizePhone( $phone );

		$state = array(
			'step'            => self::STEP_ADDRESS,
			'phone'           => $phone,
			'address'         => null,
			'shipping_method' => null,
			'payment_method'  => null,
			'coupon_code'     => null,
			'started_at'      => time(),
			'updated_at'      => time(),
		);

		$this->saveState( $phone, $state );

		return $state;
	}

	/**
	 * Save checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @param array  $state State data.
	 * @return bool Success status.
	 */
	public function saveState( string $phone, array $state ): bool {
		$phone   = $this->sanitizePhone( $phone );
		$key     = self::STATE_PREFIX . md5( $phone );
		$timeout = $this->getCheckoutTimeout() + 300; // Extra buffer.

		return set_transient( $key, $state, $timeout );
	}

	/**
	 * Load checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return array|null State data or null if not found.
	 */
	public function loadState( string $phone ): ?array {
		$phone = $this->sanitizePhone( $phone );
		$key   = self::STATE_PREFIX . md5( $phone );
		$state = get_transient( $key );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Clear checkout state.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool Success status.
	 */
	public function clearState( string $phone ): bool {
		$phone = $this->sanitizePhone( $phone );
		$key   = self::STATE_PREFIX . md5( $phone );

		return delete_transient( $key );
	}

	/**
	 * Update specific state fields.
	 *
	 * @param string $phone  Customer phone number.
	 * @param array  $fields Fields to update.
	 * @return bool Success status.
	 */
	public function updateState( string $phone, array $fields ): bool {
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return false;
		}

		foreach ( $fields as $key => $value ) {
			$state[ $key ] = $value;
		}

		$state['updated_at'] = time();

		return $this->saveState( $phone, $state );
	}

	/**
	 * Advance to the next checkout step.
	 *
	 * @param string $phone Customer phone number.
	 * @param string $step  Step to advance to.
	 * @return bool Success status.
	 */
	public function advanceToStep( string $phone, string $step ): bool {
		return $this->updateState( $phone, array( 'step' => $step ) );
	}

	/**
	 * Get the previous step in checkout flow.
	 *
	 * @param string $currentStep Current step.
	 * @return string Previous step.
	 */
	public function getPreviousStep( string $currentStep ): string {
		$currentOrder = self::STEP_ORDER[ $currentStep ] ?? 1;
		$previousStep = self::STEP_ADDRESS;

		foreach ( self::STEP_ORDER as $step => $order ) {
			if ( $order < $currentOrder ) {
				$previousStep = $step;
			}
		}

		return $previousStep;
	}

	/**
	 * Get checkout timeout in seconds.
	 *
	 * @return int Timeout in seconds.
	 */
	public function getCheckoutTimeout(): int {
		return (int) get_option( 'wch_checkout_timeout', self::DEFAULT_TIMEOUT );
	}

	/**
	 * Check if checkout has timed out.
	 *
	 * @param string $phone Customer phone number.
	 * @return bool True if timed out.
	 */
	public function hasTimedOut( string $phone ): bool {
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return false;
		}

		$timeout   = $this->getCheckoutTimeout();
		$startedAt = $state['started_at'] ?? 0;

		return ( time() - $startedAt ) > $timeout;
	}

	/**
	 * Extend checkout timeout.
	 *
	 * @param string $phone   Customer phone number.
	 * @param int    $seconds Additional seconds.
	 * @return bool Success status.
	 */
	public function extendTimeout( string $phone, int $seconds = 900 ): bool {
		// Extending by resetting started_at to current time.
		return $this->updateState( $phone, array( 'started_at' => time() ) );
	}

	/**
	 * Get data for a specific checkout step.
	 *
	 * @param string $phone Customer phone number.
	 * @param string $step  Checkout step.
	 * @return array Step-specific data.
	 */
	public function getStepData( string $phone, string $step ): array {
		$state = $this->loadState( $phone );

		if ( ! $state ) {
			return array();
		}

		// Return basic state data - orchestrator will enrich this.
		return array(
			'current_step'    => $step,
			'address'         => $state['address'] ?? null,
			'shipping_method' => $state['shipping_method'] ?? null,
			'payment_method'  => $state['payment_method'] ?? null,
			'coupon_code'     => $state['coupon_code'] ?? null,
		);
	}

	/**
	 * Sanitize phone number.
	 *
	 * @param string $phone Phone number.
	 * @return string Sanitized phone.
	 */
	private function sanitizePhone( string $phone ): string {
		return preg_replace( '/[^0-9+]/', '', $phone );
	}
}
