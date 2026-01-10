<?php
declare(strict_types=1);


/**
 * Payment Handler
 *
 * Handles checkout payment method operations.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */


namespace WhatsAppCommerceHub\Application\Services\Checkout;

use WhatsAppCommerceHub\Contracts\Services\Checkout\PaymentHandlerInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PaymentHandler
 *
 * Manages payment method availability and fees.
 */
class PaymentHandler implements PaymentHandlerInterface {

	/**
	 * Default enabled payment methods.
	 *
	 * @var array
	 */
	private const DEFAULT_ENABLED_METHODS = array( 'cod' );

	/**
	 * Get available payment methods for WhatsApp checkout.
	 *
	 * @param string $phone Customer phone number.
	 * @return array Array of available payment methods.
	 */
	public function getAvailableMethods( string $phone ): array {
		$gateways       = WC()->payment_gateways()->get_available_payment_gateways();
		$enabledMethods = $this->getEnabledMethods();
		$methods        = array();

		foreach ( $gateways as $gateway ) {
			if ( ! in_array( $gateway->id, $enabledMethods, true ) ) {
				continue;
			}

			$methods[] = array(
				'id'          => $gateway->id,
				'label'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
				'icon'        => $gateway->get_icon(),
				'fee'         => $this->getPaymentFee( $gateway->id ),
			);
		}

		return apply_filters( 'wch_payment_methods', $methods, $phone );
	}

	/**
	 * Get enabled payment methods from settings.
	 *
	 * @return array Array of enabled payment method IDs.
	 */
	public function getEnabledMethods(): array {
		$enabled = get_option( 'wch_enabled_payment_methods', self::DEFAULT_ENABLED_METHODS );

		if ( ! is_array( $enabled ) ) {
			$enabled = self::DEFAULT_ENABLED_METHODS;
		}

		return $enabled;
	}

	/**
	 * Validate payment method selection.
	 *
	 * @param string $methodId Payment method ID.
	 * @return bool True if valid.
	 */
	public function validateMethod( string $methodId ): bool {
		$enabledMethods = $this->getEnabledMethods();

		if ( ! in_array( $methodId, $enabledMethods, true ) ) {
			return false;
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		return isset( $gateways[ $methodId ] );
	}

	/**
	 * Get payment method details.
	 *
	 * @param string $methodId Payment method ID.
	 * @return array|null Payment method data or null if not found.
	 */
	public function getMethodDetails( string $methodId ): ?array {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( ! isset( $gateways[ $methodId ] ) ) {
			return null;
		}

		$gateway = $gateways[ $methodId ];

		return array(
			'id'                => $gateway->id,
			'label'             => $gateway->get_title(),
			'description'       => $gateway->get_description(),
			'icon'              => $gateway->get_icon(),
			'fee'               => $this->getPaymentFee( $methodId ),
			'requires_redirect' => $this->requiresRedirect( $methodId ),
		);
	}

	/**
	 * Get payment fee for a gateway.
	 *
	 * @param string $methodId   Payment method ID.
	 * @param float  $orderTotal Order total amount.
	 * @return float Fee amount.
	 */
	public function getPaymentFee( string $methodId, float $orderTotal = 0 ): float {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$gateway  = $gateways[ $methodId ] ?? null;

		if ( ! $gateway ) {
			return 0.0;
		}

		// Allow plugins to add payment fees.
		$fee = apply_filters( 'wch_payment_gateway_fee', 0, $gateway, $orderTotal );

		return (float) $fee;
	}

	/**
	 * Check if payment method requires redirect.
	 *
	 * @param string $methodId Payment method ID.
	 * @return bool True if redirect required.
	 */
	public function requiresRedirect( string $methodId ): bool {
		// Methods that typically require redirect.
		$redirectMethods = array(
			'stripe',
			'paypal',
			'razorpay',
		);

		$requiresRedirect = in_array( $methodId, $redirectMethods, true );

		return apply_filters( 'wch_payment_requires_redirect', $requiresRedirect, $methodId );
	}
}
