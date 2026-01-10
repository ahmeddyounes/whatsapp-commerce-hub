<?php
/**
 * Broadcasts AJAX Handler
 *
 * Handles AJAX operations for broadcast campaigns.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Broadcasts;

use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignRepositoryInterface;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\AudienceCalculatorInterface;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignDispatcherInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastsAjaxHandler
 *
 * Handles AJAX operations for broadcasts.
 */
class BroadcastsAjaxHandler {

	/**
	 * Nonce action name.
	 */
	protected const NONCE_ACTION = 'wch_broadcasts_nonce';

	/**
	 * Constructor.
	 *
	 * @param CampaignRepositoryInterface $repository         Campaign repository.
	 * @param AudienceCalculatorInterface $audienceCalculator Audience calculator.
	 * @param CampaignDispatcherInterface $dispatcher         Campaign dispatcher.
	 * @param CampaignReportGenerator     $reportGenerator    Report generator.
	 */
	public function __construct(
		protected CampaignRepositoryInterface $repository,
		protected AudienceCalculatorInterface $audienceCalculator,
		protected CampaignDispatcherInterface $dispatcher,
		protected CampaignReportGenerator $reportGenerator
	) {
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_wch_get_campaigns', [ $this, 'handleGetCampaigns' ] );
		add_action( 'wp_ajax_wch_save_campaign', [ $this, 'handleSaveCampaign' ] );
		add_action( 'wp_ajax_wch_delete_campaign', [ $this, 'handleDeleteCampaign' ] );
		add_action( 'wp_ajax_wch_get_campaign', [ $this, 'handleGetCampaign' ] );
		add_action( 'wp_ajax_wch_get_audience_count', [ $this, 'handleGetAudienceCount' ] );
		add_action( 'wp_ajax_wch_send_campaign', [ $this, 'handleSendCampaign' ] );
		add_action( 'wp_ajax_wch_send_test_broadcast', [ $this, 'handleSendTestBroadcast' ] );
		add_action( 'wp_ajax_wch_get_campaign_report', [ $this, 'handleGetCampaignReport' ] );
		add_action( 'wp_ajax_wch_duplicate_campaign', [ $this, 'handleDuplicateCampaign' ] );
		add_action( 'wp_ajax_wch_get_approved_templates', [ $this, 'handleGetApprovedTemplates' ] );
	}

	/**
	 * Verify AJAX request and permissions.
	 *
	 * @return bool True if valid, sends error and exits otherwise.
	 */
	protected function verifyRequest(): bool {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
			return false;
		}

		return true;
	}

	/**
	 * Parse JSON from POST data.
	 *
	 * @param string $key POST key to parse.
	 * @return array Parsed data or empty array.
	 */
	protected function parseJsonPost( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest().
		if ( ! isset( $_POST[ $key ] ) ) {
			return [];
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		// JSON data is decoded and validated after, nonce verified in verifyRequest().
		$decoded = json_decode( stripslashes( $_POST[ $key ] ), true );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Handle get campaigns AJAX request.
	 *
	 * @return void
	 */
	public function handleGetCampaigns(): void {
		$this->verifyRequest();

		$campaigns = $this->repository->getAll();
		wp_send_json_success( [ 'campaigns' => $campaigns ] );
	}

	/**
	 * Handle get single campaign AJAX request.
	 *
	 * @return void
	 */
	public function handleGetCampaign(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		$campaignId = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaignId ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ] );
		}

		$campaign = $this->repository->getById( $campaignId );

		if ( null === $campaign ) {
			wp_send_json_error( [ 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ] );
		}

		wp_send_json_success( [ 'campaign' => $campaign ] );
	}

	/**
	 * Handle save campaign AJAX request.
	 *
	 * @return void
	 */
	public function handleSaveCampaign(): void {
		$this->verifyRequest();

		$campaignData = $this->parseJsonPost( 'campaign' );

		if ( empty( $campaignData ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign data', 'whatsapp-commerce-hub' ) ] );
		}

		$campaign = $this->repository->save( $campaignData );

		wp_send_json_success(
			[
				'message'  => __( 'Campaign saved successfully', 'whatsapp-commerce-hub' ),
				'campaign' => $campaign,
			]
		);
	}

	/**
	 * Handle delete campaign AJAX request.
	 *
	 * @return void
	 */
	public function handleDeleteCampaign(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		$campaignId = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaignId ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ] );
		}

		$deleted = $this->repository->delete( $campaignId );

		if ( ! $deleted ) {
			wp_send_json_error( [ 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ] );
		}

		wp_send_json_success( [ 'message' => __( 'Campaign deleted', 'whatsapp-commerce-hub' ) ] );
	}

	/**
	 * Handle duplicate campaign AJAX request.
	 *
	 * @return void
	 */
	public function handleDuplicateCampaign(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		$campaignId = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaignId ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ] );
		}

		$duplicate = $this->repository->duplicate( $campaignId );

		if ( null === $duplicate ) {
			wp_send_json_error( [ 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ] );
		}

		wp_send_json_success(
			[
				'message'  => __( 'Campaign duplicated', 'whatsapp-commerce-hub' ),
				'campaign' => $duplicate,
			]
		);
	}

	/**
	 * Handle get audience count AJAX request.
	 *
	 * @return void
	 */
	public function handleGetAudienceCount(): void {
		$this->verifyRequest();

		$criteria = $this->parseJsonPost( 'criteria' );

		$count = $this->audienceCalculator->calculateCount( $criteria );

		wp_send_json_success( [ 'count' => $count ] );
	}

	/**
	 * Handle send campaign AJAX request.
	 *
	 * @return void
	 */
	public function handleSendCampaign(): void {
		$this->verifyRequest();

		$campaignData = $this->parseJsonPost( 'campaign' );

		if ( empty( $campaignData ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign data', 'whatsapp-commerce-hub' ) ] );
		}

		// Save campaign first.
		$campaign = $this->repository->save( $campaignData );

		// Determine delay based on schedule.
		$schedule = $campaign['schedule'] ?? [];
		$delay    = 0;

		if ( isset( $schedule['timing'] ) && 'scheduled' === $schedule['timing'] ) {
			$scheduledTime = strtotime( $schedule['datetime'] ?? '' );
			if ( $scheduledTime ) {
				$delay = max( 0, $scheduledTime - time() );
			}
		}

		// Schedule the campaign.
		$jobId = $this->dispatcher->schedule( $campaign, $delay );

		if ( null === $jobId ) {
			wp_send_json_error( [ 'message' => __( 'No recipients found for this campaign', 'whatsapp-commerce-hub' ) ] );
		}

		// Get updated campaign.
		$updatedCampaign = $this->repository->getById( (int) $campaign['id'] );

		wp_send_json_success(
			[
				'message'  => __( 'Campaign scheduled successfully', 'whatsapp-commerce-hub' ),
				'campaign' => $updatedCampaign,
				'job_id'   => $jobId,
			]
		);
	}

	/**
	 * Handle send test broadcast AJAX request.
	 *
	 * @return void
	 */
	public function handleSendTestBroadcast(): void {
		$this->verifyRequest();

		$campaignData = $this->parseJsonPost( 'campaign' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest().
		$testPhone = isset( $_POST['test_phone'] )
			? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->dispatcher->sendTest( $campaignData, $testPhone );

		if ( $result['success'] ) {
			wp_send_json_success( [ 'message' => $result['message'] ] );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	/**
	 * Handle get campaign report AJAX request.
	 *
	 * @return void
	 */
	public function handleGetCampaignReport(): void {
		$this->verifyRequest(); // Calls check_ajax_referer()

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verifyRequest() above.
		$campaignId = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaignId ) {
			wp_send_json_error( [ 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ] );
		}

		$reportData = $this->reportGenerator->getReportData( $campaignId );

		if ( null === $reportData ) {
			wp_send_json_error( [ 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ] );
		}

		wp_send_json_success( $reportData );
	}

	/**
	 * Handle get approved templates AJAX request.
	 *
	 * @return void
	 */
	public function handleGetApprovedTemplates(): void {
		$this->verifyRequest();

		$approvedTemplates = [];

		if ( class_exists( 'WCH_Template_Manager' ) ) {
			$templateManager = \WCH_Template_Manager::getInstance();
			$allTemplates    = $templateManager->get_templates();

			if ( is_array( $allTemplates ) ) {
				foreach ( $allTemplates as $template ) {
					if ( isset( $template['status'] ) && 'APPROVED' === $template['status'] ) {
						$approvedTemplates[] = $template;
					}
				}
			}
		}

		wp_send_json_success( [ 'templates' => $approvedTemplates ] );
	}
}
