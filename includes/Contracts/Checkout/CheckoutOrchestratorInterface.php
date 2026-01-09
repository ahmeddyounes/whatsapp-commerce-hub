<?php
/**
 * Checkout Orchestrator Interface
 *
 * Contract for checkout flow orchestration.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Contracts\Checkout;

use WhatsAppCommerceHub\ValueObjects\CheckoutResponse;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CheckoutOrchestratorInterface
 *
 * Defines the contract for checkout flow orchestration.
 */
interface CheckoutOrchestratorInterface {

	/**
	 * Start a new checkout process.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return CheckoutResponse The initial checkout response.
	 */
	public function startCheckout( string $customer_phone ): CheckoutResponse;

	/**
	 * Process user input for the current checkout step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $input          User's input/selection.
	 * @param string $current_step   Current step identifier.
	 * @param array  $state_data     Current checkout state data.
	 * @return CheckoutResponse The response with next step or error messages.
	 */
	public function processInput( string $customer_phone, string $input, string $current_step, array $state_data ): CheckoutResponse;

	/**
	 * Get the current checkout step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return string|null Current step identifier or null if not in checkout.
	 */
	public function getCurrentStep( string $customer_phone ): ?string;

	/**
	 * Navigate to a specific step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $step_id        Target step identifier.
	 * @param array  $state_data     Current checkout state data.
	 * @return CheckoutResponse The step response.
	 */
	public function goToStep( string $customer_phone, string $step_id, array $state_data ): CheckoutResponse;

	/**
	 * Go back to the previous step.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @param string $current_step   Current step identifier.
	 * @param array  $state_data     Current checkout state data.
	 * @return CheckoutResponse The previous step response.
	 */
	public function goBack( string $customer_phone, string $current_step, array $state_data ): CheckoutResponse;

	/**
	 * Cancel the checkout process.
	 *
	 * @param string $customer_phone Customer phone number.
	 * @return CheckoutResponse The cancellation response.
	 */
	public function cancelCheckout( string $customer_phone ): CheckoutResponse;

	/**
	 * Get all available steps.
	 *
	 * @return array<string, StepInterface> Array of step handlers keyed by step ID.
	 */
	public function getSteps(): array;

	/**
	 * Get a specific step handler.
	 *
	 * @param string $step_id Step identifier.
	 * @return StepInterface|null The step handler or null if not found.
	 */
	public function getStep( string $step_id ): ?StepInterface;
}
