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

namespace WhatsAppCommerceHub\Services\Reengagement;

use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\InactiveCustomerIdentifierInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\CampaignTypeResolverInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementMessageBuilderInterface;
use WhatsAppCommerceHub\Contracts\Services\Reengagement\FrequencyCapManagerInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

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
	 * @var \WCH_Customer_Service
	 */
	protected \WCH_Customer_Service $customerService;

	/**
	 * WhatsApp API client.
	 *
	 * @var \WCH_WhatsApp_API_Client|null
	 */
	protected ?\WCH_WhatsApp_API_Client $apiClient = null;

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
		$this->customerService    = \WCH_Customer_Service::instance();
	}

	/**
	 * Initialize the re-engagement system.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize API client.
		add_action( 'init', array( $this, 'initApiClient' ) );

		// Schedule daily task to identify and engage inactive customers.
		if ( ! as_next_scheduled_action( 'wch_process_reengagement_campaigns', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				strtotime( 'tomorrow 9:00am' ),
				DAY_IN_SECONDS,
				'wch_process_reengagement_campaigns',
				array(),
				'wch'
			);
		}

		// Schedule hourly task to check for back-in-stock notifications.
		if ( ! as_next_scheduled_action( 'wch_check_back_in_stock', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_check_back_in_stock',
				array(),
				'wch'
			);
		}

		// Schedule hourly task to check for price drops.
		if ( ! as_next_scheduled_action( 'wch_check_price_drops', array(), 'wch' ) ) {
			as_schedule_recurring_action(
				time(),
				HOUR_IN_SECONDS,
				'wch_check_price_drops',
				array(),
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
		$phoneNumberId = $this->settings->get( 'api.phone_number_id' );
		$accessToken   = $this->settings->get( 'api.access_token' );
		$apiVersion    = $this->settings->get( 'api.version', 'v18.0' );

		if ( $phoneNumberId && $accessToken ) {
			try {
				$this->apiClient = new \WCH_WhatsApp_API_Client(
					array(
						'phone_number_id' => $phoneNumberId,
						'access_token'    => $accessToken,
						'api_version'     => $apiVersion,
					)
				);
			} catch ( \Exception $e ) {
				\WCH_Logger::error(
					'Failed to initialize WhatsApp API client for re-engagement',
					array( 'error' => $e->getMessage() )
				);
			}
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

		\WCH_Logger::info( 'Processing re-engagement campaigns', 'reengagement' );

		$inactiveCustomers = $this->customerIdentifier->identify();

		\WCH_Logger::info(
			'Found inactive customers',
			'reengagement',
			array( 'count' => count( $inactiveCustomers ) )
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

		\WCH_Job_Dispatcher::dispatch(
			'wch_send_reengagement_message',
			array(
				'customer_phone' => $customer['phone'],
				'campaign_type'  => $campaignType,
			),
			0
		);

		\WCH_Logger::debug(
			'Queued re-engagement message',
			array(
				'phone'         => $customer['phone'],
				'campaign_type' => $campaignType,
			)
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
			\WCH_Logger::error(
				'Missing customer phone in re-engagement job',
				array( 'args' => $args )
			);
			return array(
				'success' => false,
				'error'   => 'Missing customer phone',
			);
		}

		// Get customer profile.
		$customer = $this->customerService->get_or_create_profile( $customerPhone );
		if ( ! $customer ) {
			\WCH_Logger::error(
				'Customer profile not found for re-engagement',
				array( 'phone' => $customerPhone )
			);
			return array(
				'success' => false,
				'error'   => 'Customer not found',
			);
		}

		// Check frequency cap.
		if ( ! $this->frequencyCap->canSend( $customerPhone ) ) {
			\WCH_Logger::info(
				'Skipping re-engagement due to frequency cap',
				array( 'phone' => $customerPhone )
			);
			return array(
				'success' => false,
				'error'   => 'Frequency cap reached',
			);
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

			\WCH_Logger::info(
				'Re-engagement message sent successfully',
				array(
					'phone'         => $customerPhone,
					'campaign_type' => $campaignType,
				)
			);
		} else {
			\WCH_Logger::error(
				'Failed to send re-engagement message',
				array(
					'phone'         => $customerPhone,
					'campaign_type' => $campaignType,
					'error'         => $result['error'] ?? 'Unknown error',
				)
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
			return array(
				'success' => false,
				'error'   => 'WhatsApp API client not initialized',
			);
		}

		$messageData = $this->messageBuilder->build( $customer, $campaignType );

		if ( ! $messageData ) {
			return array(
				'success' => false,
				'error'   => 'Failed to build message content',
			);
		}

		try {
			$result = $this->apiClient->send_text_message(
				$customer->phone,
				$messageData['text'],
				true
			);

			return array(
				'success'    => true,
				'message_id' => $result['message_id'] ?? null,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
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
}
