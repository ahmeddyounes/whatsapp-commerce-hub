<?php
/**
 * Broadcast Service
 *
 * Handles broadcast campaign business logic.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.1.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Application\Services;

use WhatsAppCommerceHub\Contracts\Services\BroadcastServiceInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastService
 *
 * Business logic for broadcast campaign operations.
 */
class BroadcastService implements BroadcastServiceInterface {

	/**
	 * Option name for storing campaigns.
	 *
	 * @var string
	 */
	private const CAMPAIGNS_OPTION = 'wch_broadcast_campaigns';

	/**
	 * wpdb instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Template manager instance.
	 *
	 * @var \WCH_Template_Manager|null
	 */
	private ?\WCH_Template_Manager $template_manager;

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null                 $wpdb_instance WordPress database instance.
	 * @param \WCH_Template_Manager|null $template_manager Template manager instance.
	 */
	public function __construct( ?\wpdb $wpdb_instance = null, ?\WCH_Template_Manager $template_manager = null ) {
		if ( null === $wpdb_instance ) {
			global $wpdb;
			$this->wpdb = $wpdb;
		} else {
			$this->wpdb = $wpdb_instance;
		}
		$this->template_manager = $template_manager;
	}

	/**
	 * Get all campaigns.
	 *
	 * @return array List of campaigns.
	 */
	public function getCampaigns(): array {
		$campaigns = get_option( self::CAMPAIGNS_OPTION, array() );

		// Sort by created_at descending.
		usort(
			$campaigns,
			static function ( $a, $b ) {
				$time_a = strtotime( $a['created_at'] ?? '' );
				$time_b = strtotime( $b['created_at'] ?? '' );
				// strtotime returns false on failure, treat as 0.
				$time_a = false === $time_a ? 0 : $time_a;
				$time_b = false === $time_b ? 0 : $time_b;
				return $time_b - $time_a;
			}
		);

		return $campaigns;
	}

	/**
	 * Get a campaign by ID.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|null Campaign data or null if not found.
	 */
	public function getCampaign( int $campaign_id ): ?array {
		$campaigns = $this->getCampaigns();

		foreach ( $campaigns as $campaign ) {
			if ( (int) $campaign['id'] === $campaign_id ) {
				return $campaign;
			}
		}

		return null;
	}

	/**
	 * Save a campaign.
	 *
	 * @param array $campaign_data Campaign data.
	 * @return array Saved campaign data with ID.
	 */
	public function saveCampaign( array $campaign_data ): array {
		$campaigns   = $this->getCampaigns();
		$campaign_id = isset( $campaign_data['id'] ) ? absint( $campaign_data['id'] ) : 0;

		// Generate unique ID if not provided (use microtime + random to prevent collisions).
		if ( 0 === $campaign_id ) {
			$campaign_id = (int) ( microtime( true ) * 1000 ) + wp_rand( 0, 999 );
		}

		// Prepare campaign data with sanitization.
		$campaign = array(
			'id'              => $campaign_id,
			'name'            => sanitize_text_field( $campaign_data['name'] ?? '' ),
			'template_name'   => sanitize_text_field( $campaign_data['template_name'] ?? '' ),
			'template_data'   => $this->sanitizeNestedArray( $campaign_data['template_data'] ?? array() ),
			'audience'        => $this->sanitizeNestedArray( $campaign_data['audience'] ?? array() ),
			'audience_size'   => absint( $campaign_data['audience_size'] ?? 0 ),
			'personalization' => $this->sanitizeNestedArray( $campaign_data['personalization'] ?? array() ),
			'schedule'        => $this->sanitizeNestedArray( $campaign_data['schedule'] ?? array() ),
			'status'          => sanitize_key( $campaign_data['status'] ?? 'draft' ),
			'created_at'      => $campaign_data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		// Preserve stats if they exist (sanitize to ensure valid structure).
		if ( isset( $campaign_data['stats'] ) && is_array( $campaign_data['stats'] ) ) {
			$campaign['stats'] = array(
				'sent'      => absint( $campaign_data['stats']['sent'] ?? 0 ),
				'delivered' => absint( $campaign_data['stats']['delivered'] ?? 0 ),
				'read'      => absint( $campaign_data['stats']['read'] ?? 0 ),
				'failed'    => absint( $campaign_data['stats']['failed'] ?? 0 ),
				'errors'    => is_array( $campaign_data['stats']['errors'] ?? null )
					? array_slice( $campaign_data['stats']['errors'], 0, 100 ) // Cap at 100 errors
					: array(),
			);
		}

		// Update existing or add new.
		$found = false;
		foreach ( $campaigns as $index => $existing ) {
			if ( (int) $existing['id'] === $campaign['id'] ) {
				$campaigns[ $index ] = $campaign;
				$found               = true;
				break;
			}
		}

		if ( ! $found ) {
			$campaigns[] = $campaign;
		}

		update_option( self::CAMPAIGNS_OPTION, $campaigns );

		return $campaign;
	}

	/**
	 * Delete a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return bool True if deleted, false otherwise.
	 */
	public function deleteCampaign( int $campaign_id ): bool {
		$campaigns = $this->getCampaigns();
		$found     = false;

		foreach ( $campaigns as $index => $campaign ) {
			if ( (int) $campaign['id'] === $campaign_id ) {
				unset( $campaigns[ $index ] );
				$found = true;
				break;
			}
		}

		if ( $found ) {
			update_option( self::CAMPAIGNS_OPTION, array_values( $campaigns ) );
		}

		return $found;
	}

	/**
	 * Duplicate a campaign.
	 *
	 * @param int $campaign_id Campaign ID to duplicate.
	 * @return array|null Duplicated campaign data or null on failure.
	 */
	public function duplicateCampaign( int $campaign_id ): ?array {
		$campaign = $this->getCampaign( $campaign_id );

		if ( ! $campaign ) {
			return null;
		}

		// Create duplicate with new ID.
		$duplicate = $campaign;
		unset( $duplicate['id'] );
		$duplicate['name']       = sprintf(
			/* translators: %s: original campaign name */
			__( '%s (Copy)', 'whatsapp-commerce-hub' ),
			$campaign['name']
		);
		$duplicate['status']     = 'draft';
		$duplicate['created_at'] = gmdate( 'Y-m-d H:i:s' );
		unset( $duplicate['stats'], $duplicate['sent_at'], $duplicate['scheduled_at'] );

		return $this->saveCampaign( $duplicate );
	}

	/**
	 * Schedule and send a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array Result with status and campaign data.
	 */
	public function sendCampaign( int $campaign_id ): array {
		$campaign = $this->getCampaign( $campaign_id );

		if ( ! $campaign ) {
			return array(
				'success' => false,
				'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ),
			);
		}

		// Update campaign status based on schedule.
		$schedule = $campaign['schedule'] ?? array();

		if ( isset( $schedule['timing'] ) && 'scheduled' === $schedule['timing'] ) {
			$campaign['status']       = 'scheduled';
			$campaign['scheduled_at'] = $schedule['datetime'] ?? gmdate( 'Y-m-d H:i:s' );

			// Calculate delay.
			$scheduled_time = strtotime( $campaign['scheduled_at'] );
			$delay          = max( 0, $scheduled_time - time() );
		} else {
			$campaign['status']  = 'sending';
			$campaign['sent_at'] = gmdate( 'Y-m-d H:i:s' );
			$delay               = 0;
		}

		// Initialize stats.
		$campaign['stats'] = array(
			'sent'      => 0,
			'delivered' => 0,
			'read'      => 0,
			'failed'    => 0,
			'errors'    => array(),
		);

		// Save updated campaign.
		$this->saveCampaign( $campaign );

		// Schedule the broadcast.
		$schedule_result = $this->scheduleBroadcast( $campaign, $delay );

		if ( ! $schedule_result['success'] ) {
			// Update campaign status to failed if scheduling failed.
			$campaign['status'] = 'failed';
			$campaign['stats']['errors'][] = array(
				'error' => $schedule_result['message'],
				'time'  => gmdate( 'Y-m-d H:i:s' ),
			);
			$this->saveCampaign( $campaign );

			return array(
				'success'  => false,
				'message'  => $schedule_result['message'],
				'campaign' => $campaign,
			);
		}

		return array(
			'success'  => true,
			'message'  => __( 'Campaign scheduled successfully', 'whatsapp-commerce-hub' ),
			'campaign' => $campaign,
		);
	}

	/**
	 * Send a test broadcast message.
	 *
	 * @param array  $campaign_data Campaign data.
	 * @param string $phone_number Target phone number.
	 * @return bool True if sent successfully.
	 */
	public function sendTestBroadcast( array $campaign_data, string $phone_number ): bool {
		$message = $this->buildCampaignMessage( $campaign_data );

		// Validate message has required template_name and phone number is provided.
		if ( empty( $message['template_name'] ) || empty( $phone_number ) ) {
			return false;
		}

		try {
			// Get WhatsApp API client.
			if ( function_exists( 'wch_get_container' ) ) {
				$container = wch_get_container();
				if ( $container->has( 'wch.whatsapp' ) ) {
					$api_client = $container->get( 'wch.whatsapp' );
					return $api_client->sendTemplateMessage( $phone_number, $message );
				}
			}

			// Fallback to static API client.
			$api_client = \WCH_WhatsApp_API_Client::instance();
			return $api_client->sendTemplateMessage( $phone_number, $message );
		} catch ( \Throwable $e ) {
			\WCH_Logger::error(
				'Failed to send test broadcast',
				array(
					'category' => 'broadcasts',
					'phone'    => $phone_number,
					'error'    => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Calculate audience count based on criteria.
	 *
	 * @param array $criteria Audience criteria.
	 * @return int Number of recipients.
	 */
	public function calculateAudienceCount( array $criteria ): int {
		$table_name = $this->wpdb->prefix . 'wch_customer_profiles';

		// Build parameterized query parts.
		$where_clauses = array( 'opt_in_marketing = %d' );
		$where_values  = array( 1 );

		// Recent orders filter.
		if ( ! empty( $criteria['audience_recent_orders'] ) && ! empty( $criteria['recent_orders_days'] ) ) {
			$days           = absint( $criteria['recent_orders_days'] );
			$date_threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

			$where_clauses[] = 'last_order_date >= %s';
			$where_values[]  = $date_threshold;
		}

		// Cart abandoners filter.
		if ( ! empty( $criteria['audience_cart_abandoners'] ) ) {
			$carts_table     = $this->wpdb->prefix . 'wch_carts';
			$where_clauses[] = "phone IN (SELECT customer_phone FROM $carts_table WHERE status = %s)";
			$where_values[]  = 'abandoned';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = $this->wpdb->prepare(
			"SELECT COUNT(DISTINCT phone) FROM $table_name WHERE $where_sql",
			$where_values
		);

		$count = (int) $this->wpdb->get_var( $query );

		// Apply exclusions.
		if ( ! empty( $criteria['exclude_recent_broadcast'] ) && ! empty( $criteria['exclude_broadcast_days'] ) ) {
			$days             = absint( $criteria['exclude_broadcast_days'] );
			$broadcast_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
			$broadcasts_table = $this->wpdb->prefix . 'wch_broadcast_recipients';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$table_exists = $this->wpdb->get_var(
				$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $broadcasts_table )
			);

			if ( $table_exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$excluded_count = (int) $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(DISTINCT cp.phone) FROM $table_name cp
						INNER JOIN $broadcasts_table br ON cp.phone = br.phone
						WHERE cp.opt_in_marketing = %d AND br.sent_at >= %s",
						1,
						$broadcast_cutoff
					)
				);
				$count = max( 0, $count - $excluded_count );
			}
		}

		return max( 0, $count );
	}

	/**
	 * Get campaign recipients based on audience criteria.
	 *
	 * @param array $campaign Campaign data.
	 * @return array List of phone numbers.
	 */
	public function getCampaignRecipients( array $campaign ): array {
		$table_name = $this->wpdb->prefix . 'wch_customer_profiles';
		$audience   = $campaign['audience'] ?? array();

		// Build parameterized query parts.
		$where_clauses = array( 'opt_in_marketing = %d' );
		$where_values  = array( 1 );

		// Recent orders filter.
		if ( ! empty( $audience['audience_recent_orders'] ) && ! empty( $audience['recent_orders_days'] ) ) {
			$days           = absint( $audience['recent_orders_days'] );
			$date_threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

			$where_clauses[] = 'last_order_date >= %s';
			$where_values[]  = $date_threshold;
		}

		// Cart abandoners filter.
		if ( ! empty( $audience['audience_cart_abandoners'] ) ) {
			$carts_table     = $this->wpdb->prefix . 'wch_carts';
			$where_clauses[] = "phone IN (SELECT customer_phone FROM $carts_table WHERE status = %s)";
			$where_values[]  = 'abandoned';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Use pagination to fetch recipients.
		$all_recipients = array();
		$offset         = 0;
		$per_page       = 1000;
		$max_recipients = 100000;

		do {
			$batch_values = array_merge( $where_values, array( $per_page, $offset ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$batch = $this->wpdb->get_col(
				$this->wpdb->prepare(
					"SELECT phone FROM {$table_name} WHERE {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d",
					$batch_values
				)
			);

			if ( empty( $batch ) ) {
				break;
			}

			array_push( $all_recipients, ...$batch );
			$offset        += $per_page;

			if ( count( $all_recipients ) >= $max_recipients ) {
				\WCH_Logger::warning(
					'Broadcast recipients hit safety limit',
					array(
						'category'    => 'broadcasts',
						'fetched'     => count( $all_recipients ),
						'campaign_id' => $campaign['id'] ?? 'unknown',
					)
				);
				break;
			}
		} while ( count( $batch ) === $per_page );

		return $all_recipients;
	}

	/**
	 * Get campaign report/statistics.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array Campaign stats.
	 */
	public function getCampaignReport( int $campaign_id ): array {
		$campaign = $this->getCampaign( $campaign_id );

		if ( ! $campaign ) {
			return array();
		}

		return $campaign['stats'] ?? array(
			'sent'      => 0,
			'delivered' => 0,
			'read'      => 0,
			'failed'    => 0,
			'errors'    => array(),
		);
	}

	/**
	 * Get approved templates for broadcasts.
	 *
	 * @return array List of approved templates.
	 */
	public function getApprovedTemplates(): array {
		$template_manager = $this->getTemplateManager();

		if ( ! $template_manager ) {
			return array();
		}

		$all_templates      = $template_manager->get_templates();
		$approved_templates = array();

		if ( is_array( $all_templates ) ) {
			foreach ( $all_templates as $template ) {
				if ( isset( $template['status'] ) && 'APPROVED' === $template['status'] ) {
					$approved_templates[] = $template;
				}
			}
		}

		return $approved_templates;
	}

	/**
	 * Process a broadcast batch.
	 *
	 * @param array $batch Batch of recipients.
	 * @param int   $campaign_id Campaign ID.
	 * @param array $message Message data.
	 * @return array Result with sent, failed counts.
	 */
	public function processBroadcastBatch( array $batch, int $campaign_id, array $message ): array {
		$sent   = 0;
		$failed = 0;
		$errors = array();

		try {
			$api_client = null;

			if ( function_exists( 'wch_get_container' ) ) {
				$container = wch_get_container();
				if ( $container->has( 'wch.whatsapp' ) ) {
					$api_client = $container->get( 'wch.whatsapp' );
				}
			}

			if ( ! $api_client ) {
				$api_client = \WCH_WhatsApp_API_Client::instance();
			}

			foreach ( $batch as $phone ) {
				try {
					$result = $api_client->sendTemplateMessage( $phone, $message );
					if ( $result ) {
						++$sent;
					} else {
						++$failed;
						$errors[] = array(
							'recipient' => $phone,
							'error'     => 'Send failed',
						);
					}
				} catch ( \Throwable $e ) {
					++$failed;
					$errors[] = array(
						'recipient' => $phone,
						'error'     => $e->getMessage(),
					);
				}
			}
		} catch ( \Throwable $e ) {
			\WCH_Logger::error(
				'Broadcast batch processing failed',
				array(
					'category'    => 'broadcasts',
					'campaign_id' => $campaign_id,
					'error'       => $e->getMessage(),
				)
			);
		}

		// Update campaign stats.
		$this->updateCampaignStats( $campaign_id, $sent, $failed, $errors );

		return array(
			'sent'   => $sent,
			'failed' => $failed,
			'errors' => $errors,
		);
	}

	/**
	 * Schedule broadcast campaign.
	 *
	 * @param array $campaign Campaign data.
	 * @param int   $delay Delay in seconds.
	 * @return array Result with success status and message.
	 */
	private function scheduleBroadcast( array $campaign, int $delay = 0 ): array {
		$recipients = $this->getCampaignRecipients( $campaign );

		if ( empty( $recipients ) ) {
			\WCH_Logger::warning(
				'Broadcast has no recipients',
				array(
					'category'    => 'broadcasts',
					'campaign_id' => $campaign['id'] ?? 'unknown',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'No recipients found matching the audience criteria', 'whatsapp-commerce-hub' ),
			);
		}

		if ( ! class_exists( 'WCH_Job_Dispatcher' ) ) {
			\WCH_Logger::error(
				'WCH_Job_Dispatcher class not found - cannot schedule broadcast',
				array(
					'category'    => 'broadcasts',
					'campaign_id' => $campaign['id'] ?? 'unknown',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Job dispatcher not available - cannot schedule broadcast', 'whatsapp-commerce-hub' ),
			);
		}

		$message    = $this->buildCampaignMessage( $campaign );
		$batch_size = 50;
		$batches    = array_chunk( $recipients, $batch_size );

		foreach ( $batches as $batch_num => $batch ) {
			$args = array(
				'batch'       => $batch,
				'batch_num'   => $batch_num,
				'campaign_id' => $campaign['id'],
				'message'     => $message,
			);

			$batch_delay = $delay + ( $batch_num * 1 );

			\WCH_Job_Dispatcher::dispatch( 'wch_send_broadcast_batch', $args, $batch_delay );
		}

		return array(
			'success'          => true,
			'message'          => __( 'Broadcast scheduled', 'whatsapp-commerce-hub' ),
			'recipient_count'  => count( $recipients ),
			'batch_count'      => count( $batches ),
		);
	}

	/**
	 * Build campaign message from template.
	 *
	 * @param array $campaign Campaign data.
	 * @return array Message data.
	 */
	private function buildCampaignMessage( array $campaign ): array {
		$template_name = $campaign['template_name'] ?? '';
		$template_data = $campaign['template_data'] ?? array();

		return array(
			'template_name' => $template_name,
			'language'      => $template_data['language'] ?? 'en',
			'components'    => $template_data['components'] ?? array(),
		);
	}

	/**
	 * Update campaign statistics.
	 *
	 * @param int   $campaign_id Campaign ID.
	 * @param int   $sent Number sent.
	 * @param int   $failed Number failed.
	 * @param array $errors Error details.
	 */
	private function updateCampaignStats( int $campaign_id, int $sent, int $failed, array $errors ): void {
		$campaign = $this->getCampaign( $campaign_id );

		if ( ! $campaign ) {
			return;
		}

		$stats          = $campaign['stats'] ?? array(
			'sent'   => 0,
			'failed' => 0,
			'errors' => array(),
		);
		$stats['sent'] += $sent;
		$stats['failed'] += $failed;

		// Merge errors but cap at 100 to prevent unbounded growth.
		$merged_errors   = array_merge( $stats['errors'] ?? array(), $errors );
		$stats['errors'] = array_slice( $merged_errors, -100 ); // Keep last 100 errors.

		$campaign['stats'] = $stats;
		$this->saveCampaign( $campaign );
	}

	/**
	 * Recursively sanitize nested arrays.
	 *
	 * @param array $data Data to sanitize.
	 * @return array Sanitized data.
	 */
	private function sanitizeNestedArray( array $data ): array {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$sanitized_key = is_string( $key ) ? sanitize_key( $key ) : $key;

			if ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = $this->sanitizeNestedArray( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $sanitized_key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $sanitized_key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $sanitized_key ] = $value;
			} else {
				// Skip null and other types.
				$sanitized[ $sanitized_key ] = null;
			}
		}

		return $sanitized;
	}

	/**
	 * Get template manager instance.
	 *
	 * @return \WCH_Template_Manager|null
	 */
	private function getTemplateManager(): ?\WCH_Template_Manager {
		if ( $this->template_manager ) {
			return $this->template_manager;
		}

		if ( function_exists( 'wch_get_container' ) ) {
			try {
				$container = wch_get_container();
				if ( $container->has( \WCH_Template_Manager::class ) ) {
					return $container->get( \WCH_Template_Manager::class );
				}
			} catch ( \Throwable $e ) {
				// Fall through.
			}
		}

		if ( class_exists( 'WCH_Template_Manager' ) ) {
			return \WCH_Template_Manager::getInstance();
		}

		return null;
	}
}
