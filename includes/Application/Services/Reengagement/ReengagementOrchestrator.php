<?php
/**
 * Reengagement Orchestrator
 *
 * Coordinates re-engagement campaigns and messaging.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Reengagement;

use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Contracts\Services\CustomerServiceInterface;
use WhatsAppCommerceHub\Contracts\Services\LoggerInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\InactiveCustomerIdentifierInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\CampaignTypeResolverInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementMessageBuilderInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\FrequencyCapManagerInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReengagementOrchestrator
 *
 * Coordinates all re-engagement services.
 */
class ReengagementOrchestrator implements ReengagementOrchestratorInterface {

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Inactive customer identifier.
	 *
	 * @var InactiveCustomerIdentifierInterface
	 */
	protected InactiveCustomerIdentifierInterface $customerIdentifier;

	/**
	 * Campaign type resolver.
	 *
	 * @var CampaignTypeResolverInterface
	 */
	protected CampaignTypeResolverInterface $campaignResolver;

	/**
	 * Message builder.
	 *
	 * @var ReengagementMessageBuilderInterface
	 */
	protected ReengagementMessageBuilderInterface $messageBuilder;

	/**
	 * Frequency cap manager.
	 *
	 * @var FrequencyCapManagerInterface
	 */
	protected FrequencyCapManagerInterface $frequencyCap;

	/**
	 * Customer service.
	 *
	 * @var CustomerServiceInterface
	 */
	protected CustomerServiceInterface $customerService;

	/**
	 * WhatsApp API client.
	 *
	 * @var WhatsAppApiClient|null
	 */
	protected ?WhatsAppApiClient $apiClient = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsInterface                   $settings           Settings service.
	 * @param InactiveCustomerIdentifierInterface $customerIdentifier Customer identifier.
	 * @param CampaignTypeResolverInterface       $campaignResolver   Campaign resolver.
	 * @param ReengagementMessageBuilderInterface $messageBuilder     Message builder.
	 * @param FrequencyCapManagerInterface        $frequencyCap       Frequency cap manager.
	 */
	public function __construct(
		SettingsInterface $settings,
		InactiveCustomerIdentifierInterface $customerIdentifier,
		CampaignTypeResolverInterface $campaignResolver,
		ReengagementMessageBuilderInterface $messageBuilder,
		FrequencyCapManagerInterface $frequencyCap
	) {
		$this->settings           = $settings;
		$this->customerIdentifier = $customerIdentifier;
		$this->campaignResolver   = $campaignResolver;
		$this->messageBuilder     = $messageBuilder;
		$this->frequencyCap       = $frequencyCap;
		$this->customerService    = wch( CustomerServiceInterface::class );
	}

	/**
	 * Initialize the re-engagement system.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize API client.
		add_action( 'init', [ $this, 'initApiClient' ] );

		// Schedule daily task to identify and engage inactive customers.
		if ( ! as_next_scheduled_action( 'wch_process_reengagement_campaigns', [], 'wch' ) ) {
			as_schedule_recurring_action(
				strtotime( 'tomorrow 9:00am' ),
				DAY_IN_SECONDS,
				'wch_process_reengagement_campaigns',
				[],
				'wch'
			);
		}

		// Schedule hourly task to check for back-in-stock notifications.
		if ( ! as_next_scheduled_action( 'wch_check_back_in_stock', [], 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_check_back_in_stock',
				[],
				'wch'
			);
		}

		// Schedule hourly task to check for price drops.
		if ( ! as_next_scheduled_action( 'wch_check_price_drops', [], 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_check_price_drops',
				[],
				'wch'
			);
		}
	}

	/**
	 * Initialize WhatsApp API client.
	 *
	 * @return void
	 */
	public function initApiClient(): void {
		try {
			$this->apiClient = wch( WhatsAppApiClient::class );
		} catch ( \Throwable $e ) {
			$this->log(
				'error',
				'Failed to initialize WhatsApp API client for re-engagement',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * Process re-engagement campaigns.
	 *
	 * @return int Number of messages queued.
	 */
	public function processCampaigns(): int {
		if ( ! $this->isEnabled() ) {
			return 0;
		}

		$this->log( 'info', 'Processing re-engagement campaigns' );

		$inactiveCustomers = $this->customerIdentifier->identify();

		$this->log(
			'info',
			'Found inactive customers',
			[ 'count' => count( $inactiveCustomers ) ]
		);

		$queued = 0;
		foreach ( $inactiveCustomers as $customer ) {
			if ( $this->queueMessage( $customer ) ) {
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Queue a re-engagement message for a customer.
	 *
	 * @param array $customer Customer data.
	 * @return bool True if queued.
	 */
	public function queueMessage( array $customer ): bool {
		$campaignType = $this->campaignResolver->resolve( $customer );

		wch( JobDispatcher::class )->dispatch(
			'wch_send_reengagement_message',
			[
				'customer_phone' => $customer['phone'],
				'campaign_type'  => $campaignType,
			],
			0
		);

		$this->log(
			'debug',
			'Queued re-engagement message',
			[
				'phone'         => $customer['phone'],
				'campaign_type' => $campaignType,
			]
		);

		return true;
	}

	/**
	 * Send a re-engagement message.
	 *
	 * @param array $args Job arguments with customer_phone and campaign_type.
	 * @return array Result with success status.
	 */
	public function sendMessage( array $args ): array {
		$customerPhone = $args['customer_phone'] ?? null;
		$campaignType  = $args['campaign_type'] ?? 'we_miss_you';

		if ( ! $customerPhone ) {
			$this->log(
				'error',
				'Missing customer phone in re-engagement job',
				[ 'args' => $args ]
			);
			return [
				'success' => false,
				'error'   => 'Missing customer phone',
			];
		}

		// Get customer profile.
		$customer = $this->customerService->getOrCreateProfile( $customerPhone );
		if ( ! $customer ) {
			$this->log(
				'error',
				'Customer profile not found for re-engagement',
				[ 'phone' => $customerPhone ]
			);
			return [
				'success' => false,
				'error'   => 'Customer not found',
			];
		}

		// Check frequency cap.
		if ( ! $this->frequencyCap->canSend( $customerPhone ) ) {
			$this->log(
				'info',
				'Skipping re-engagement due to frequency cap',
				[ 'phone' => $customerPhone ]
			);
			return [
				'success' => false,
				'error'   => 'Frequency cap reached',
			];
		}

		// Send the message.
		$result = $this->sendCampaignMessage( $customer, $campaignType );

		if ( $result['success'] ) {
			// Log the sent message.
			$this->frequencyCap->logMessage(
				$customerPhone,
				$campaignType,
				$result['message_id'] ?? null
			);

			$this->log(
				'info',
				'Re-engagement message sent successfully',
				[
					'phone'         => $customerPhone,
					'campaign_type' => $campaignType,
				]
			);
		} else {
			$this->log(
				'error',
				'Failed to send re-engagement message',
				[
					'phone'         => $customerPhone,
					'campaign_type' => $campaignType,
					'error'         => $result['error'] ?? 'Unknown error',
				]
			);
		}

		return $result;
	}

	/**
	 * Send campaign message via WhatsApp.
	 *
	 * @param object $customer Customer profile.
	 * @param string $campaignType Campaign type.
	 * @return array Result with 'success' key.
	 */
	protected function sendCampaignMessage( object $customer, string $campaignType ): array {
		if ( ! $this->apiClient ) {
			$this->initApiClient();
		}

		if ( ! $this->apiClient ) {
			return [
				'success' => false,
				'error'   => 'WhatsApp API client not initialized',
			];
		}

		$messageData = $this->messageBuilder->build( $customer, $campaignType );

		if ( ! $messageData ) {
			return [
				'success' => false,
				'error'   => 'Failed to build message content',
			];
		}

		try {
			$result = $this->apiClient->sendTextMessage(
				$customer->phone,
				$messageData['text'],
				true
			);

			return [
				'success'    => true,
				'message_id' => $result['messages'][0]['id'] ?? $result['message_id'] ?? null,
			];
		} catch ( \Exception $e ) {
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Check if re-engagement is enabled.
	 *
	 * @return bool True if enabled.
	 */
	public function isEnabled(): bool {
		return (bool) $this->settings->get( 'reengagement.enabled', false );
	}

	/**
	 * Log a re-engagement message.
	 *
	 * @param string $level Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	private function log( string $level, string $message, array $context = [] ): void {
		try {
			$logger = wch( LoggerInterface::class );
		} catch ( \Throwable $e ) {
			return;
		}

		$logger->log( $level, $message, 'reengagement', $context );
	}
}
