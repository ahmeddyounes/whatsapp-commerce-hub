<?php
/**
 * Broadcast Batch Processor
 *
 * Sends broadcast campaign batches and updates campaign stats.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services\Broadcasts;

use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignRepositoryInterface;
use WhatsAppCommerceHub\Core\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastBatchProcessor
 *
 * Processes a batch of recipients for a broadcast campaign.
 */
class BroadcastBatchProcessor {

	/**
	 * WhatsApp API client.
	 *
	 * @var WhatsAppApiClient
	 */
	private WhatsAppApiClient $apiClient;

	/**
	 * WordPress database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepositoryInterface $repository       Campaign repository.
	 * @param BroadcastTemplateBuilder    $templateBuilder  Template builder.
	 * @param WhatsAppApiClient|null      $apiClient        WhatsApp API client.
	 * @param \wpdb|null                  $wpdb             WordPress database instance.
	 */
	public function __construct(
		private CampaignRepositoryInterface $repository,
		private BroadcastTemplateBuilder $templateBuilder,
		?WhatsAppApiClient $apiClient = null,
		?\wpdb $wpdb = null
	) {
		$this->apiClient = $apiClient ?? wch( WhatsAppApiClient::class );

		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Handle a broadcast batch job.
	 *
	 * @param array $args Job arguments.
	 * @return void
	 */
	public function handle( array $args ): void {
		$batch      = $args['batch'] ?? [];
		$campaignId = (int) ( $args['campaign_id'] ?? 0 );
		$message    = $args['message'] ?? [];

		if ( empty( $batch ) || 0 === $campaignId || ! is_array( $message ) ) {
			Logger::instance()->warning(
				'Invalid broadcast batch payload',
				[
					'campaign_id' => $campaignId,
					'batch_size'  => is_array( $batch ) ? count( $batch ) : 0,
				]
			);
			return;
		}

		$templateName    = (string) ( $message['template_name'] ?? '' );
		$templateData    = is_array( $message['template_data'] ?? null ) ? $message['template_data'] : [];
		$personalization = is_array( $message['variables'] ?? null ) ? $message['variables'] : [];
		$languageCode    = $this->templateBuilder->getLanguageCode( $templateData );

		if ( '' === $templateName ) {
			Logger::instance()->warning(
				'Broadcast batch missing template name',
				[ 'campaign_id' => $campaignId ]
			);
			return;
		}

		$profiles     = $this->getRecipientProfiles( $batch );
		$tableName    = $this->wpdb->prefix . 'wch_broadcast_recipients';
		$tableExists  = $this->tableExists( $tableName );

		$sent   = 0;
		$failed = 0;
		$errors = [];

		foreach ( $batch as $phone ) {
			if ( ! is_string( $phone ) || '' === $phone ) {
				++$failed;
				$errors[] = [
					'recipient' => '',
					'error'     => 'Missing phone number',
				];
				continue;
			}

			$recipient = [
				'phone' => $phone,
				'name'  => $profiles[ $phone ]['name'] ?? 'there',
			];

			$components = $this->templateBuilder->buildComponents( $templateData, $personalization, $recipient );

			try {
				$result = $this->apiClient->sendTemplate( $phone, $templateName, $languageCode, $components );
				$messageId = $result['message_id'] ?? $result['messages'][0]['id'] ?? null;

				if ( ! $messageId ) {
					throw new \RuntimeException( 'Send failed' );
				}

				++$sent;

				if ( $tableExists ) {
					$this->recordRecipient( $tableName, $campaignId, $phone, (string) $messageId );
				}
			} catch ( \Throwable $e ) {
				++$failed;
				$errors[] = [
					'recipient' => $phone,
					'error'     => $e->getMessage(),
				];
			}
		}

		$this->updateCampaignStats( $campaignId, $sent, $failed, $errors, $args );
	}

	/**
	 * Update campaign statistics and status.
	 *
	 * @param int   $campaignId Campaign ID.
	 * @param int   $sent       Sent count.
	 * @param int   $failed     Failed count.
	 * @param array $errors     Error entries.
	 * @param array $args       Batch job arguments.
	 * @return void
	 */
	private function updateCampaignStats( int $campaignId, int $sent, int $failed, array $errors, array $args ): void {
		$campaign = $this->repository->getById( $campaignId );

		if ( null === $campaign ) {
			Logger::instance()->warning(
				'Broadcast campaign not found for stats update',
				[ 'campaign_id' => $campaignId ]
			);
			return;
		}

		$stats = $campaign['stats'] ?? [
			'sent'      => 0,
			'delivered' => 0,
			'read'      => 0,
			'failed'    => 0,
			'errors'    => [],
			'total'     => (int) ( $campaign['audience_size'] ?? 0 ),
		];

		$stats['sent']   = (int) ( $stats['sent'] ?? 0 ) + $sent;
		$stats['failed'] = (int) ( $stats['failed'] ?? 0 ) + $failed;
		$stats['errors'] = array_slice( array_merge( $stats['errors'] ?? [], $errors ), -100 );

		$this->repository->updateStats( $campaignId, $stats );

		if ( 'scheduled' === ( $campaign['status'] ?? '' ) ) {
			$this->repository->updateStatus(
				$campaignId,
				'sending',
				[ 'sent_at' => gmdate( 'Y-m-d H:i:s' ) ]
			);
		}

		$batchNum     = (int) ( $args['batch_num'] ?? 0 );
		$totalBatches = (int) ( $args['total_batches'] ?? 0 );

		if ( $totalBatches > 0 && ( $batchNum + 1 ) >= $totalBatches ) {
			$this->repository->updateStatus(
				$campaignId,
				'completed',
				[ 'completed_at' => gmdate( 'Y-m-d H:i:s' ) ]
			);
		}
	}

	/**
	 * Fetch recipient profiles for personalization.
	 *
	 * @param array $phones Recipient phone numbers.
	 * @return array<string, array{name: string}>
	 */
	private function getRecipientProfiles( array $phones ): array {
		$phones = array_values(
			array_unique(
				array_filter(
					$phones,
					static fn( $phone ) => is_string( $phone ) && '' !== $phone
				)
			)
		);

		if ( empty( $phones ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $phones ), '%s' ) );
		$tableName    = $this->wpdb->prefix . 'wch_customer_profiles';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder count is dynamic.
		$query = $this->wpdb->prepare(
			"SELECT phone, name FROM {$tableName} WHERE phone IN ({$placeholders})",
			$phones
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query prepared above.
		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		$profiles = [];
		foreach ( $rows as $row ) {
			$phone = (string) ( $row['phone'] ?? '' );
			if ( '' === $phone ) {
				continue;
			}
			$profiles[ $phone ] = [
				'name' => (string) ( $row['name'] ?? '' ),
			];
		}

		return $profiles;
	}

	/**
	 * Record a broadcast recipient.
	 *
	 * @param string $tableName  Recipients table name.
	 * @param int    $campaignId Campaign ID.
	 * @param string $phone      Recipient phone.
	 * @param string $messageId  WhatsApp message ID.
	 * @return void
	 */
	private function recordRecipient( string $tableName, int $campaignId, string $phone, string $messageId ): void {
		$now = current_time( 'mysql' );

		$this->wpdb->replace(
			$tableName,
			[
				'campaign_id'   => $campaignId,
				'phone'         => $phone,
				'wa_message_id' => $messageId,
				'status'        => 'sent',
				'sent_at'       => $now,
				'created_at'    => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Check if a table exists.
	 *
	 * @param string $tableName Table name.
	 * @return bool
	 */
	private function tableExists( string $tableName ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName )
		);

		return ! empty( $result );
	}
}
