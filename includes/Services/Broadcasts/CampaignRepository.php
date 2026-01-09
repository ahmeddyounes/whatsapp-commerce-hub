<?php
/**
 * Campaign Repository Service
 *
 * Handles CRUD operations for broadcast campaigns.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Services\Broadcasts;

use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignRepository
 *
 * Manages campaign data persistence.
 */
class CampaignRepository implements CampaignRepositoryInterface {

	/**
	 * Option name for campaigns storage.
	 */
	protected const OPTION_NAME = 'wch_broadcast_campaigns';

	/**
	 * Valid campaign statuses.
	 *
	 * @var array<string>
	 */
	protected array $validStatuses = array(
		'draft',
		'scheduled',
		'sending',
		'completed',
		'failed',
		'cancelled',
	);

	/**
	 * {@inheritdoc}
	 */
	public function getAll(): array {
		$campaigns = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $campaigns ) ) {
			return array();
		}

		// Sort by created_at descending.
		usort(
			$campaigns,
			function ( $a, $b ) {
				$timeA = strtotime( $a['created_at'] ?? '0' );
				$timeB = strtotime( $b['created_at'] ?? '0' );
				return $timeB - $timeA;
			}
		);

		return $campaigns;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getById( int $campaignId ): ?array {
		$campaigns = $this->getAll();

		foreach ( $campaigns as $campaign ) {
			if ( isset( $campaign['id'] ) && (int) $campaign['id'] === $campaignId ) {
				return $campaign;
			}
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function save( array $campaignData ): array {
		$campaigns  = $this->getAll();
		$campaignId = isset( $campaignData['id'] ) ? absint( $campaignData['id'] ) : 0;

		// Prepare campaign data.
		$campaign = $this->sanitizeCampaignData( $campaignData );

		// Generate new ID if needed.
		if ( 0 === $campaignId ) {
			$campaign['id']         = $this->generateCampaignId();
			$campaign['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$campaign['status']     = 'draft';
		} else {
			$campaign['id'] = $campaignId;
		}

		$campaign['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// Update existing or add new.
		$found = false;
		foreach ( $campaigns as $index => $existing ) {
			if ( (int) $existing['id'] === $campaign['id'] ) {
				// Preserve fields that shouldn't be overwritten.
				$campaign['created_at'] = $existing['created_at'] ?? $campaign['created_at'];
				$campaign['stats']      = $campaignData['stats'] ?? $existing['stats'] ?? array();

				$campaigns[ $index ] = $campaign;
				$found               = true;
				break;
			}
		}

		if ( ! $found ) {
			$campaigns[] = $campaign;
		}

		update_option( self::OPTION_NAME, $campaigns );

		return $campaign;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( int $campaignId ): bool {
		$campaigns = $this->getAll();
		$updated   = array();

		foreach ( $campaigns as $campaign ) {
			if ( (int) $campaign['id'] !== $campaignId ) {
				$updated[] = $campaign;
			}
		}

		// Check if anything was deleted.
		if ( count( $updated ) === count( $campaigns ) ) {
			return false;
		}

		update_option( self::OPTION_NAME, $updated );

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function duplicate( int $campaignId ): ?array {
		$original = $this->getById( $campaignId );

		if ( null === $original ) {
			return null;
		}

		// Create duplicate.
		$duplicate               = $original;
		$duplicate['id']         = $this->generateCampaignId();
		$duplicate['name']       = $original['name'] . ' (Copy)';
		$duplicate['status']     = 'draft';
		$duplicate['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$duplicate['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// Remove execution-related fields.
		unset( $duplicate['sent_at'] );
		unset( $duplicate['scheduled_at'] );
		unset( $duplicate['stats'] );

		$campaigns   = $this->getAll();
		$campaigns[] = $duplicate;
		update_option( self::OPTION_NAME, $campaigns );

		return $duplicate;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateStatus( int $campaignId, string $status, array $extraData = array() ): bool {
		if ( ! in_array( $status, $this->validStatuses, true ) ) {
			return false;
		}

		$campaigns = $this->getAll();

		foreach ( $campaigns as $index => $campaign ) {
			if ( (int) $campaign['id'] === $campaignId ) {
				$campaigns[ $index ]['status']     = $status;
				$campaigns[ $index ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

				// Merge extra data.
				foreach ( $extraData as $key => $value ) {
					$campaigns[ $index ][ $key ] = $value;
				}

				update_option( self::OPTION_NAME, $campaigns );
				return true;
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateStats( int $campaignId, array $stats ): bool {
		$campaigns = $this->getAll();

		foreach ( $campaigns as $index => $campaign ) {
			if ( (int) $campaign['id'] === $campaignId ) {
				$campaigns[ $index ]['stats']      = $stats;
				$campaigns[ $index ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

				update_option( self::OPTION_NAME, $campaigns );
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate a unique campaign ID.
	 *
	 * @return int Campaign ID.
	 */
	protected function generateCampaignId(): int {
		return (int) ( microtime( true ) * 1000 );
	}

	/**
	 * Sanitize campaign data.
	 *
	 * @param array $data Raw campaign data.
	 * @return array Sanitized campaign data.
	 */
	protected function sanitizeCampaignData( array $data ): array {
		return array(
			'id'              => isset( $data['id'] ) ? absint( $data['id'] ) : 0,
			'name'            => sanitize_text_field( $data['name'] ?? '' ),
			'template_name'   => sanitize_text_field( $data['template_name'] ?? '' ),
			'template_data'   => $this->sanitizeTemplateData( $data['template_data'] ?? array() ),
			'audience'        => $this->sanitizeAudienceData( $data['audience'] ?? array() ),
			'audience_size'   => absint( $data['audience_size'] ?? 0 ),
			'personalization' => $this->sanitizePersonalization( $data['personalization'] ?? array() ),
			'schedule'        => $this->sanitizeScheduleData( $data['schedule'] ?? array() ),
			'status'          => sanitize_key( $data['status'] ?? 'draft' ),
			'created_at'      => $data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Sanitize template data.
	 *
	 * @param array $data Template data.
	 * @return array Sanitized data.
	 */
	protected function sanitizeTemplateData( array $data ): array {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$sanitizedKey = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$sanitized[ $sanitizedKey ] = $this->sanitizeTemplateData( $value );
			} else {
				$sanitized[ $sanitizedKey ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize audience data.
	 *
	 * @param array $data Audience criteria.
	 * @return array Sanitized data.
	 */
	protected function sanitizeAudienceData( array $data ): array {
		return array(
			'audience_all'              => ! empty( $data['audience_all'] ),
			'audience_recent_orders'    => ! empty( $data['audience_recent_orders'] ),
			'recent_orders_days'        => absint( $data['recent_orders_days'] ?? 30 ),
			'audience_category'         => ! empty( $data['audience_category'] ),
			'category_id'               => absint( $data['category_id'] ?? 0 ),
			'audience_cart_abandoners'  => ! empty( $data['audience_cart_abandoners'] ),
			'exclude_recent_broadcast'  => ! empty( $data['exclude_recent_broadcast'] ),
			'exclude_broadcast_days'    => absint( $data['exclude_broadcast_days'] ?? 7 ),
		);
	}

	/**
	 * Sanitize personalization data.
	 *
	 * @param array $data Personalization data.
	 * @return array Sanitized data.
	 */
	protected function sanitizePersonalization( array $data ): array {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize schedule data.
	 *
	 * @param array $data Schedule data.
	 * @return array Sanitized data.
	 */
	protected function sanitizeScheduleData( array $data ): array {
		return array(
			'timing'   => in_array( $data['timing'] ?? 'now', array( 'now', 'scheduled' ), true )
				? $data['timing']
				: 'now',
			'date'     => sanitize_text_field( $data['date'] ?? '' ),
			'time'     => sanitize_text_field( $data['time'] ?? '' ),
			'timezone' => sanitize_text_field( $data['timezone'] ?? 'UTC' ),
			'datetime' => sanitize_text_field( $data['datetime'] ?? '' ),
		);
	}
}
