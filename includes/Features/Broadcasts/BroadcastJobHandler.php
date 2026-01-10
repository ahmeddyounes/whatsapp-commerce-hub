<?php
/**
 * Broadcast Job Handler
 *
 * Handles broadcast campaigns in batches to respect rate limits.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Features\Broadcasts;

use WhatsAppCommerceHub\Support\Logger;
use WhatsAppCommerceHub\Infrastructure\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Infrastructure\Queue\JobDispatcher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Broadcast Job Handler
 *
 * Processes broadcast campaigns in batches with rate limiting.
 */
class BroadcastJobHandler
{
    /**
     * Batch size for processing recipients
     */
    private const BATCH_SIZE = 50;

    /**
     * Delay between batches in seconds
     */
    private const BATCH_DELAY = 1;

    /**
     * Constructor
     *
     * @param Logger $logger Logger instance
     * @param WhatsAppApiClient $apiClient WhatsApp API client
     * @param JobDispatcher $jobDispatcher Job dispatcher
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly WhatsAppApiClient $apiClient,
        private readonly JobDispatcher $jobDispatcher
    ) {
    }

    /**
     * Process a broadcast batch job
     *
     * @param array<string, mixed> $args Job arguments
     */
    public function process(array $args): void
    {
        $batch = $args['batch'] ?? [];
        $batchNum = (int) ($args['batch_num'] ?? 0);
        $campaignId = (int) ($args['campaign_id'] ?? 0);
        $message = (string) ($args['message'] ?? '');

        $this->logger->info('Processing broadcast batch', [
            'campaign_id' => $campaignId,
            'batch_num' => $batchNum,
            'recipients' => count($batch),
        ]);

        if (empty($batch) || $campaignId === 0 || empty($message)) {
            $this->logger->error('Invalid broadcast batch parameters', [
                'campaign_id' => $campaignId,
                'batch_num' => $batchNum,
            ]);
            return;
        }

        $results = [
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        // Process each recipient in the batch
        foreach ($batch as $recipient) {
            $result = $this->sendBroadcastMessage($recipient, $message, $campaignId);

            if ($result['success']) {
                $results['sent']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'recipient' => $recipient,
                    'error' => $result['error'] ?? 'Unknown error',
                ];
            }
        }

        // Store batch result
        $this->storeBatchResult($campaignId, $batchNum, $results);

        $this->logger->info('Broadcast batch completed', [
            'campaign_id' => $campaignId,
            'batch_num' => $batchNum,
            'sent' => $results['sent'],
            'failed' => $results['failed'],
        ]);
    }

    /**
     * Send broadcast message to a single recipient
     *
     * @param string $recipient Phone number
     * @param string $message Message text
     * @param int $campaignId Campaign ID
     * @return array<string, mixed> Result with success status
     */
    private function sendBroadcastMessage(string $recipient, string $message, int $campaignId): array
    {
        try {
            $result = $this->apiClient->sendMessage($recipient, $message);

            return [
                'success' => true,
                'message_id' => $result['id'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send broadcast message', [
                'campaign_id' => $campaignId,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Store batch result
     *
     * @param int $campaignId Campaign ID
     * @param int $batchNum Batch number
     * @param array<string, mixed> $results Batch results
     */
    private function storeBatchResult(int $campaignId, int $batchNum, array $results): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_broadcast_batches';

        $wpdb->insert(
            $tableName,
            [
                'campaign_id' => $campaignId,
                'batch_num' => $batchNum,
                'sent' => $results['sent'],
                'failed' => $results['failed'],
                'errors' => wp_json_encode($results['errors']),
                'completed_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s']
        );

        // Update campaign summary
        $this->updateCampaignSummary($campaignId, $results);
    }

    /**
     * Update campaign summary
     *
     * @param int $campaignId Campaign ID
     * @param array<string, mixed> $results Batch results
     */
    private function updateCampaignSummary(int $campaignId, array $results): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_broadcast_campaigns';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$tableName} 
                SET sent = sent + %d, 
                    failed = failed + %d,
                    updated_at = %s
                WHERE id = %d",
                $results['sent'],
                $results['failed'],
                current_time('mysql'),
                $campaignId
            )
        );
    }

    /**
     * Dispatch campaign
     *
     * Splits recipients into batches and schedules jobs.
     *
     * @param int $campaignId Campaign ID
     * @param string $message Message text
     * @param array<int, string> $recipients Array of phone numbers
     */
    public function dispatchCampaign(int $campaignId, string $message, array $recipients): void
    {
        $batches = array_chunk($recipients, self::BATCH_SIZE);
        $batchCount = count($batches);

        $this->logger->info('Dispatching broadcast campaign', [
            'campaign_id' => $campaignId,
            'total_recipients' => count($recipients),
            'batch_count' => $batchCount,
        ]);

        foreach ($batches as $index => $batch) {
            $delay = $index * self::BATCH_DELAY;

            $this->jobDispatcher->dispatch(
                'wch_process_broadcast_batch',
                [
                    'campaign_id' => $campaignId,
                    'batch_num' => $index + 1,
                    'batch' => $batch,
                    'message' => $message,
                ],
                $delay
            );
        }

        // Update campaign status
        $this->updateCampaignStatus($campaignId, 'processing', $batchCount);
    }

    /**
     * Update campaign status
     *
     * @param int $campaignId Campaign ID
     * @param string $status Campaign status
     * @param int $totalBatches Total number of batches
     */
    private function updateCampaignStatus(int $campaignId, string $status, int $totalBatches): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_broadcast_campaigns';

        $wpdb->update(
            $tableName,
            [
                'status' => $status,
                'total_batches' => $totalBatches,
                'started_at' => current_time('mysql'),
            ],
            ['id' => $campaignId],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * Get campaign summary
     *
     * @param int $campaignId Campaign ID
     * @return array<string, mixed>|null Campaign summary or null
     */
    public function getCampaignSummary(int $campaignId): ?array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_broadcast_campaigns';

        $summary = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tableName} WHERE id = %d", $campaignId),
            ARRAY_A
        );

        return $summary ?: null;
    }

    /**
     * Get batch result
     *
     * @param int $campaignId Campaign ID
     * @param int $batchNum Batch number
     * @return array<string, mixed>|null Batch result or null
     */
    public function getBatchResult(int $campaignId, int $batchNum): ?array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'wch_broadcast_batches';

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tableName} WHERE campaign_id = %d AND batch_num = %d",
                $campaignId,
                $batchNum
            ),
            ARRAY_A
        );

        if ($result && isset($result['errors'])) {
            $result['errors'] = json_decode($result['errors'], true);
        }

        return $result ?: null;
    }
}
