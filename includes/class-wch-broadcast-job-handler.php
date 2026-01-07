<?php
/**
 * Broadcast Job Handler Class
 *
 * Handles broadcast campaigns in batches to respect rate limits.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Broadcast_Job_Handler
 */
class WCH_Broadcast_Job_Handler {
	/**
	 * Batch size for processing recipients.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Delay between batches in seconds.
	 *
	 * @var int
	 */
	const BATCH_DELAY = 1;

	/**
	 * Process a broadcast batch job.
	 *
	 * @param array $args Job arguments.
	 */
	public static function process( $args ) {
		$batch       = $args['batch'] ?? array();
		$batch_num   = $args['batch_num'] ?? 0;
		$campaign_id = $args['campaign_id'] ?? null;
		$message     = $args['message'] ?? '';

		WCH_Logger::log(
			'info',
			'Processing broadcast batch',
			'queue',
			array(
				'campaign_id' => $campaign_id,
				'batch_num'   => $batch_num,
				'recipients'  => count( $batch ),
			)
		);

		if ( empty( $batch ) || empty( $campaign_id ) || empty( $message ) ) {
			WCH_Logger::log(
				'error',
				'Invalid broadcast batch parameters',
				'queue',
				array(
					'campaign_id' => $campaign_id,
					'batch_num'   => $batch_num,
				)
			);
			return;
		}

		$results = array(
			'sent'   => 0,
			'failed' => 0,
			'errors' => array(),
		);

		// Process each recipient in the batch.
		foreach ( $batch as $recipient ) {
			$result = self::send_broadcast_message( $recipient, $message, $campaign_id );

			if ( $result['success'] ) {
				++$results['sent'];
			} else {
				++$results['failed'];
				$results['errors'][] = array(
					'recipient' => $recipient,
					'error'     => $result['error'] ?? 'Unknown error',
				);
			}
		}

		// Store batch result.
		self::store_batch_result( $campaign_id, $batch_num, $results );

		WCH_Logger::log(
			'info',
			'Broadcast batch completed',
			'queue',
			array(
				'campaign_id' => $campaign_id,
				'batch_num'   => $batch_num,
				'sent'        => $results['sent'],
				'failed'      => $results['failed'],
			)
		);
	}

	/**
	 * Send a broadcast message to a single recipient.
	 *
	 * @param string $recipient   Recipient phone number or user ID.
	 * @param string $message     Message to send.
	 * @param string $campaign_id Campaign ID.
	 * @return array Result array with 'success' key.
	 */
	private static function send_broadcast_message( $recipient, $message, $campaign_id ) {
		// Placeholder for actual message sending logic.
		// This will be implemented when the WhatsApp API integration is available.

		if ( empty( $recipient ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid recipient',
			);
		}

		// Simulate message sending.
		// In production, this would call the WhatsApp Business API.
		$success = true;

		if ( $success ) {
			return array(
				'success'     => true,
				'recipient'   => $recipient,
				'campaign_id' => $campaign_id,
				'sent_at'     => current_time( 'mysql' ),
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to send message',
		);
	}

	/**
	 * Store batch result in transient.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param int    $batch_num   Batch number.
	 * @param array  $results     Batch results.
	 */
	private static function store_batch_result( $campaign_id, $batch_num, $results ) {
		$key = 'wch_broadcast_result_' . $campaign_id . '_' . $batch_num;

		$batch_result = array(
			'campaign_id' => $campaign_id,
			'batch_num'   => $batch_num,
			'results'     => $results,
			'timestamp'   => current_time( 'timestamp' ),
		);

		set_transient( $key, $batch_result, HOUR_IN_SECONDS );

		// Update campaign summary.
		self::update_campaign_summary( $campaign_id, $results );
	}

	/**
	 * Update campaign summary with batch results.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param array  $results     Batch results.
	 */
	private static function update_campaign_summary( $campaign_id, $results ) {
		$summary_key = 'wch_broadcast_summary_' . $campaign_id;
		$summary     = get_transient( $summary_key );

		if ( ! $summary ) {
			$summary = array(
				'campaign_id'  => $campaign_id,
				'total_sent'   => 0,
				'total_failed' => 0,
				'batches'      => 0,
				'started_at'   => current_time( 'mysql' ),
				'last_updated' => current_time( 'mysql' ),
			);
		}

		$summary['total_sent']   += $results['sent'];
		$summary['total_failed'] += $results['failed'];
		$summary['batches']      += 1;
		$summary['last_updated']  = current_time( 'mysql' );

		set_transient( $summary_key, $summary, HOUR_IN_SECONDS );
	}

	/**
	 * Dispatch a broadcast campaign.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param string $message     Message to broadcast.
	 * @param array  $recipients  Array of recipient phone numbers.
	 * @return array Array of action IDs.
	 */
	public static function dispatch_campaign( $campaign_id, $message, $recipients ) {
		if ( empty( $campaign_id ) || empty( $message ) || empty( $recipients ) ) {
			WCH_Logger::log(
				'error',
				'Invalid campaign parameters',
				'queue',
				array( 'campaign_id' => $campaign_id )
			);
			return array();
		}

		WCH_Logger::log(
			'info',
			'Dispatching broadcast campaign',
			'queue',
			array(
				'campaign_id'      => $campaign_id,
				'total_recipients' => count( $recipients ),
			)
		);

		// Split recipients into batches and dispatch.
		$batches    = array_chunk( $recipients, self::BATCH_SIZE );
		$action_ids = array();
		$batch_num  = 0;

		foreach ( $batches as $batch ) {
			// Calculate delay: 1 second between batches.
			$delay = $batch_num * self::BATCH_DELAY;

			$action_id = WCH_Job_Dispatcher::dispatch(
				'wch_send_broadcast_batch',
				array(
					'batch'       => $batch,
					'batch_num'   => $batch_num,
					'campaign_id' => $campaign_id,
					'message'     => $message,
				),
				$delay
			);

			if ( $action_id ) {
				$action_ids[] = $action_id;
			}

			++$batch_num;
		}

		return $action_ids;
	}

	/**
	 * Get campaign summary.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @return array|null Campaign summary or null if not found.
	 */
	public static function get_campaign_summary( $campaign_id ) {
		return get_transient( 'wch_broadcast_summary_' . $campaign_id );
	}

	/**
	 * Get batch result.
	 *
	 * @param string $campaign_id Campaign ID.
	 * @param int    $batch_num   Batch number.
	 * @return array|null Batch result or null if not found.
	 */
	public static function get_batch_result( $campaign_id, $batch_num ) {
		return get_transient( 'wch_broadcast_result_' . $campaign_id . '_' . $batch_num );
	}
}
