<?php
/**
 * Checkout Step Interface
 *
 * Contract for checkout step handlers.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Checkout;

use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface StepInterface
 *
 * Defines the contract for checkout step handlers.
 */
interface StepInterface {

	/**
	 * Get the step identifier.
	 *
	 * @return string The step identifier (e.g., 'address', 'shipping', 'payment').
	 */
	public function getStepId(): string;

	/**
	 * Get the next step identifier.
	 *
	 * @return string|null The next step identifier or null if this is the final step.
	 */
	public function getNextStep(): ?string;

	/**
	 * Get the previous step identifier.
	 *
	 * @return string|null The previous step identifier or null if this is the first step.
	 */
	public function getPreviousStep(): ?string;

	/**
	 * Execute the step (render the step UI/prompt).
	 *
	 * @param array $context Checkout context including cart, customer, and state data.
	 * @return CheckoutResponse The step response with messages to send.
	 */
	public function execute( array $context ): CheckoutResponse;

	/**
	 * Process user input for this step.
	 *
	 * @param string $input   The user's input/selection.
	 * @param array  $context Checkout context including cart, customer, and state data.
	 * @return CheckoutResponse The response with next step or error messages.
	 */
	public function processInput( string $input, array $context ): CheckoutResponse;

	/**
	 * Validate the step data.
	 *
	 * @param array $data    The data to validate.
	 * @param array $context Checkout context.
	 * @return array{is_valid: bool, errors: array<string, string>}
	 */
	public function validate( array $data, array $context ): array;

	/**
	 * Check if this step can be skipped.
	 *
	 * @param array $context Checkout context.
	 * @return bool Whether the step can be skipped.
	 */
	public function canSkip( array $context ): bool;

	/**
	 * Get the step title for display.
	 *
	 * @return string The human-readable step title.
	 */
	public function getTitle(): string;
}
