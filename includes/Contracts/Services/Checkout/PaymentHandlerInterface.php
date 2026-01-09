<?php
/**
 * Payment Handler Interface
 *
 * Contract for handling checkout payment methods.
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
 * Interface PaymentHandlerInterface
 *
 * Defines contract for payment method operations.
 */
interface PaymentHandlerInterface {

	/**
	 * Get available payment methods for WhatsApp checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of available payment methods.
	 */
	public function getAvailableMethods( string $phone ): array;

	/**
	 * Get enabled payment methods from settings.
	 *
	 * @return array Array of enabled payment method IDs.
	 */
	public function getEnabledMethods(): array;

	/**
	 * Validate payment method selection.
	 *
	 * @param string $methodId Payment method ID.
	 * @return bool True if valid.
	 */
	public function validateMethod( string $methodId ): bool;

	/**
	 * Get payment method details.
	 *
	 * @param string $methodId Payment method ID.
	 * @return array|null Payment method data or null if not found.
	 */
	public function getMethodDetails( string $methodId ): ?array;

	/**
	 * Get payment fee for a gateway.
	 *
	 * @param string $methodId Payment method ID.
	 * @param float  $orderTotal Order total amount.
	 * @return float Fee amount.
	 */
	public function getPaymentFee( string $methodId, float $orderTotal = 0 ): float;

	/**
	 * Check if payment method requires redirect.
	 *
	 * @param string $methodId Payment method ID.
	 * @return bool True if redirect required.
	 */
	public function requiresRedirect( string $methodId ): bool;
}
