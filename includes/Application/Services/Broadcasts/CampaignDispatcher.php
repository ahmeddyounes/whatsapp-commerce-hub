<?php
/**
 * Campaign Dispatcher Service
 *
 * Handles scheduling and dispatching broadcast campaigns.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Broadcasts;

use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignDispatcherInterface;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\AudienceCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\SettingsInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignDispatcher
 *
 * Manages campaign scheduling and dispatch.
 */
class CampaignDispatcher implements CampaignDispatcherInterface {

	/**
	 * Batch size for sending messages.
	 */
	protected const BATCH_SIZE = 50;

	/**
	 * Cost per message (in USD).
	 */
	protected const COST_PER_MESSAGE = 0.0058;

	/**
	 * Campaign repository.
	 *
	 * @var CampaignRepositoryInterface
	 */
	protected CampaignRepositoryInterface $repository;

	/**
	 * Audience calculator.
	 *
	 * @var AudienceCalculatorInterface
	 */
	protected AudienceCalculatorInterface $audienceCalculator;

	/**
	 * Settings service.
	 *
	 * @var SettingsInterface
	 */
	protected SettingsInterface $settings;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepositoryInterface $repository         Campaign repository.
	 * @param AudienceCalculatorInterface $audienceCalculator Audience calculator.
	 * @param SettingsInterface           $settings           Settings service.
	 */
	public function __construct(
		CampaignRepositoryInterface $repository,
		AudienceCalculatorInterface $audienceCalculator,
		SettingsInterface $settings
	) {
		$this->repository         = $repository;
		$this->audienceCalculator = $audienceCalculator;
		$this->settings           = $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function schedule( array $campaign, int $delay = 0 ): ?string {
		$audience   = $campaign['audience'] ?? array();
		$recipients = $this->audienceCalculator->getRecipients( $audience );

		if ( empty( $recipients ) ) {
			$this->log( 'warning', 'No recipients found for campaign', array( 'campaign_id' => $campaign['id'] ?? 'unknown' ) );
			return null;
		}

		// Build message from template.
		$message = $this->buildMessage( $campaign );

		// Batch recipients.
		$batches = array_chunk( $recipients, self::BATCH_SIZE );

		// Generate job ID.
		$jobId = 'broadcast_' . ( $campaign['id'] ?? time() ) . '_' . time();

		// Dispatch batches.
		foreach ( $batches as $batchNum => $batch ) {
			$args = array(
				'job_id'        => $jobId,
				'batch'         => $batch,
				'batch_num'     => $batchNum,
				'total_batches' => count( $batches ),
				'campaign_id'   => $campaign['id'] ?? 0,
				'message'       => $message,
			);

			// Delay each batch by 1 second to avoid rate limiting.
			$batchDelay = $delay + $batchNum;

			$this->dispatchBatch( $args, $batchDelay );
		}

		// Update campaign status.
		$campaignId = (int) ( $campaign['id'] ?? 0 );

		if ( $campaignId > 0 ) {
			$status    = $delay > 0 ? 'scheduled' : 'sending';
			$extraData = array(
				'job_id'        => $jobId,
				'total_batches' => count( $batches ),
			);

			if ( $delay > 0 ) {
				$extraData['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $delay );
			} else {
				$extraData['sent_at'] = gmdate( 'Y-m-d H:i:s' );
			}

			$this->repository->updateStatus( $campaignId, $status, $extraData );

			// Initialize stats.
			$this->repository->updateStats(
				$campaignId,
				array(
					'sent'      => 0,
					'delivered' => 0,
					'read'      => 0,
					'failed'    => 0,
					'errors'    => array(),
					'total'     => count( $recipients ),
				)
			);
		}

		$this->log(
			'info',
			'Campaign scheduled for sending',
			array(
				'campaign_id' => $campaignId,
				'job_id'      => $jobId,
				'recipients'  => count( $recipients ),
				'batches'     => count( $batches ),
				'delay'       => $delay,
			)
		);

		return $jobId;
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendTest( array $campaign, string $testPhone ): array {
		if ( empty( $testPhone ) ) {
			// Try to get from settings.
			$testPhone = $this->settings->get( 'api.test_phone', '' );
		}

		if ( empty( $testPhone ) ) {
			return array(
				'success' => false,
				'message' => __( 'No test phone number configured', 'whatsapp-commerce-hub' ),
			);
		}

		// Build message.
		$message = $this->buildMessage( $campaign );

		// Send via API.
		try {
			if ( ! class_exists( 'WCH_WhatsApp_API_Client' ) ) {
				return array(
					'success' => false,
					'message' => __( 'API client not available', 'whatsapp-commerce-hub' ),
				);
			}

			$api = new \WCH_WhatsApp_API_Client();

			if ( ! empty( $message['template_name'] ) ) {
				$api->send_template_message(
					$testPhone,
					$message['template_name'],
					$message['template_data'] ?? array(),
					$message['variables'] ?? array()
				);
			}

			$this->log(
				'info',
				'Test broadcast sent',
				array(
					'phone'    => substr( $testPhone, 0, 5 ) . '***',
					'template' => $message['template_name'] ?? 'unknown',
				)
			);

			return array(
				'success' => true,
				'message' => __( 'Test message sent successfully', 'whatsapp-commerce-hub' ),
			);
		} catch ( \Exception $e ) {
			$this->log(
				'error',
				'Test broadcast failed',
				array( 'error' => $e->getMessage() )
			);

			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function cancel( int $campaignId ): bool {
		$campaign = $this->repository->getById( $campaignId );

		if ( null === $campaign ) {
			return false;
		}

		// Can only cancel scheduled campaigns.
		if ( 'scheduled' !== $campaign['status'] ) {
			return false;
		}

		// Cancel scheduled jobs.
		$jobId = $campaign['job_id'] ?? '';
		if ( ! empty( $jobId ) && class_exists( 'WCH_Job_Dispatcher' ) ) {
			\WCH_Job_Dispatcher::cancel_by_prefix( $jobId );
		}

		// Update status.
		$this->repository->updateStatus( $campaignId, 'cancelled' );

		$this->log(
			'info',
			'Campaign cancelled',
			array( 'campaign_id' => $campaignId )
		);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildMessage( array $campaign ): array {
		$templateData    = $campaign['template_data'] ?? array();
		$personalization = $campaign['personalization'] ?? array();

		return array(
			'template_name' => $campaign['template_name'] ?? '',
			'template_data' => $templateData,
			'variables'     => $personalization,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getEstimatedCost( int $recipientCount ): float {
		return round( $recipientCount * self::COST_PER_MESSAGE, 2 );
	}

	/**
	 * Dispatch a batch for sending.
	 *
	 * @param array $args  Batch arguments.
	 * @param int   $delay Delay in seconds.
	 * @return void
	 */
	protected function dispatchBatch( array $args, int $delay = 0 ): void {
		if ( class_exists( 'WCH_Job_Dispatcher' ) ) {
			\WCH_Job_Dispatcher::dispatch( 'wch_send_broadcast_batch', $args, $delay );
		}
	}

	/**
	 * Log message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function log( string $level, string $message, array $context = array() ): void {
		$context['category'] = 'broadcasts';

		if ( class_exists( 'WCH_Logger' ) ) {
			\WCH_Logger::log( $message, $context, $level );
		}
	}
}
