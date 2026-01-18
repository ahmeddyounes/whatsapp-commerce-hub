<?php
/**
 * Abstract Checkout Step
 *
 * Base class for checkout step handlers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Checkout;

use WhatsAppCommerceHub\Application\Services\MessageBuilderFactory;
use WhatsAppCommerceHub\Contracts\Checkout\StepInterface;
use WhatsAppCommerceHub\Contracts\Services\AddressServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Support\Messaging\MessageBuilder;
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
	 * Constructor.
	 *
	 * @param MessageBuilderFactory   $message_builder Message builder factory.
	 * @param AddressServiceInterface $address_service Address service.
	 * @param LoggerInterface|null    $logger          Logger instance.
	 */
	public function __construct(
		protected MessageBuilderFactory $message_builder,
		protected AddressServiceInterface $address_service,
		protected ?LoggerInterface $logger = null
	) {
		$this->logger = $logger ?? wch( LoggerInterface::class );
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
		array $messages = [],
		array $data = [],
		?string $next_step = null,
		array $step_data = []
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
		array $messages = [],
		array $data = []
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
	 * @return MessageBuilder
	 */
	protected function errorMessage( string $message ): MessageBuilder {
		return $this->message_builder->text( '⚠️ ' . $message );
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
		return $context['checkout_data'] ?? [];
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
	protected function log( string $message, array $data = [] ): void {
		$data['step'] = $this->getStepId();

		$this->logger->info( $message, 'checkout', $data );
	}

	/**
	 * Log error.
	 *
	 * @param string $message Error message.
	 * @param array  $data    Additional log data.
	 * @return void
	 */
	protected function logError( string $message, array $data = [] ): void {
		$data['step'] = $this->getStepId();

		$this->logger->error( $message, 'checkout', $data );
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
