<?php
/**
 * Abstract Checkout Step
 *
 * Base class for checkout step handlers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Checkout;

use WhatsAppCommerceHub\Contracts\Checkout\StepInterface;
use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;
use WhatsAppCommerceHub\Services\MessageBuilderFactory;
use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractStep
 *
 * Base implementation for checkout step handlers.
 */
abstract class AbstractStep implements StepInterface {

	/**
	 * Message builder factory.
	 *
	 * @var MessageBuilderFactory
	 */
	protected MessageBuilderFactory $message_builder;

	/**
	 * Address service.
	 *
	 * @var AddressServiceInterface
	 */
	protected AddressServiceInterface $address_service;

	/**
	 * Constructor.
	 *
	 * @param MessageBuilderFactory   $message_builder Message builder factory.
	 * @param AddressServiceInterface $address_service Address service.
	 */
	public function __construct(
		MessageBuilderFactory $message_builder,
		AddressServiceInterface $address_service
	) {
		$this->message_builder = $message_builder;
		$this->address_service = $address_service;
	}

	/**
	 * By default, steps cannot be skipped.
	 *
	 * @param array $context Checkout context.
	 * @return bool
	 */
	public function canSkip( array $context ): bool {
		return false;
	}

	/**
	 * Create a success response for this step.
	 *
	 * @param array       $messages  Messages to send.
	 * @param array       $data      Additional data.
	 * @param string|null $next_step Next step override.
	 * @param array       $step_data Step-specific data.
	 * @return CheckoutResponse
	 */
	protected function success(
		array $messages = array(),
		array $data = array(),
		?string $next_step = null,
		array $step_data = array()
	): CheckoutResponse {
		return CheckoutResponse::success(
			$this->getStepId(),
			$messages,
			$data,
			$next_step ?? $this->getNextStep(),
			$step_data
		);
	}

	/**
	 * Create a failure response for this step.
	 *
	 * @param string      $error      Error message.
	 * @param string|null $error_code Error code.
	 * @param array       $messages   Messages to send to customer.
	 * @param array       $data       Additional data.
	 * @return CheckoutResponse
	 */
	protected function failure(
		string $error,
		?string $error_code = null,
		array $messages = array(),
		array $data = array()
	): CheckoutResponse {
		return CheckoutResponse::failure(
			$this->getStepId(),
			$error,
			$error_code,
			$messages,
			$data
		);
	}

	/**
	 * Create an error message to send to the customer.
	 *
	 * @param string $message The error message text.
	 * @return \WCH_Message_Builder
	 */
	protected function errorMessage( string $message ): \WCH_Message_Builder {
		return $this->message_builder->text( "⚠️ " . $message );
	}

	/**
	 * Get cart from context.
	 *
	 * @param array $context Checkout context.
	 * @return array|null
	 */
	protected function getCart( array $context ): ?array {
		return $context['cart'] ?? null;
	}

	/**
	 * Get customer from context.
	 *
	 * @param array $context Checkout context.
	 * @return object|null
	 */
	protected function getCustomer( array $context ) {
		return $context['customer'] ?? null;
	}

	/**
	 * Get checkout data from context.
	 *
	 * @param array $context Checkout context.
	 * @return array
	 */
	protected function getCheckoutData( array $context ): array {
		return $context['checkout_data'] ?? array();
	}

	/**
	 * Get customer phone from context.
	 *
	 * @param array $context Checkout context.
	 * @return string
	 */
	protected function getCustomerPhone( array $context ): string {
		return $context['customer_phone'] ?? '';
	}

	/**
	 * Log step action.
	 *
	 * @param string $message Log message.
	 * @param array  $data    Additional log data.
	 * @return void
	 */
	protected function log( string $message, array $data = array() ): void {
		$data['step'] = $this->getStepId();

		if ( class_exists( '\WCH_Logger' ) ) {
			\WCH_Logger::info( $message, 'checkout', $data );
		}
	}

	/**
	 * Log error.
	 *
	 * @param string $message Error message.
	 * @param array  $data    Additional log data.
	 * @return void
	 */
	protected function logError( string $message, array $data = array() ): void {
		$data['step'] = $this->getStepId();

		if ( class_exists( '\WCH_Logger' ) ) {
			\WCH_Logger::error( $message, 'checkout', $data );
		}
	}

	/**
	 * Format currency amount.
	 *
	 * @param float $amount The amount to format.
	 * @return string Formatted amount.
	 */
	protected function formatPrice( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) );
		}

		return '$' . number_format( $amount, 2 );
	}
}
