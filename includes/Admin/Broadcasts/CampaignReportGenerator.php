<?php
/**
 * Campaign Report Generator
 *
 * Handles rendering and generation of campaign reports.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Admin\Broadcasts;

use WhatsAppCommerceHub\Contracts\Services\Broadcasts\CampaignRepositoryInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignReportGenerator
 *
 * Generates campaign delivery reports.
 */
class CampaignReportGenerator {

	/**
	 * Constructor.
	 *
	 * @param CampaignRepositoryInterface $repository Campaign repository.
	 */
	public function __construct( protected CampaignRepositoryInterface $repository ) {
	}

	/**
	 * Render campaign report page.
	 *
	 * @param int $campaignId Campaign ID.
	 * @return void
	 */
	public function render( int $campaignId ): void {
		$campaign = $this->repository->getById( $campaignId );

		if ( null === $campaign ) {
			$this->renderNotFound();
			return;
		}

		$stats = $campaign['stats'] ?? $this->getDefaultStats();
		?>
		<div class="wch-campaign-report">
			<?php
			$this->renderHeader( $campaign );
			$this->renderDeliveryFunnel( $stats );
			$this->renderErrorsBreakdown( $stats );
			$this->renderActions( $campaignId );
			?>
		</div>
		<?php
	}

	/**
	 * Render not found message.
	 *
	 * @return void
	 */
	protected function renderNotFound(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Campaign not found.', 'whatsapp-commerce-hub' ) . '</p></div>';
	}

	/**
	 * Render report header.
	 *
	 * @param array $campaign Campaign data.
	 * @return void
	 */
	protected function renderHeader( array $campaign ): void {
		?>
		<div class="wch-report-header">
			<h2><?php echo esc_html( $campaign['name'] ?? __( 'Unnamed Campaign', 'whatsapp-commerce-hub' ) ); ?></h2>
			<p class="wch-report-meta">
				<?php
				printf(
					/* translators: %s: campaign date */
					esc_html__( 'Sent: %s', 'whatsapp-commerce-hub' ),
					esc_html( $this->formatCampaignDate( $campaign ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render delivery funnel visualization.
	 *
	 * @param array $stats Campaign statistics.
	 * @return void
	 */
	protected function renderDeliveryFunnel( array $stats ): void {
		$sent      = (int) ( $stats['sent'] ?? 0 );
		$delivered = (int) ( $stats['delivered'] ?? 0 );
		$read      = (int) ( $stats['read'] ?? 0 );

		$deliveryRate = $sent > 0 ? ( $delivered / $sent ) * 100 : 0;
		$readRate     = $delivered > 0 ? ( $read / $delivered ) * 100 : 0;
		?>
		<div class="wch-delivery-funnel">
			<h3><?php esc_html_e( 'Delivery Funnel', 'whatsapp-commerce-hub' ); ?></h3>
			<div class="wch-funnel-stats">
				<?php
				$this->renderFunnelItem(
					$sent,
					__( 'Sent', 'whatsapp-commerce-hub' ),
					100
				);

				$this->renderFunnelItem(
					$delivered,
					__( 'Delivered', 'whatsapp-commerce-hub' ),
					$deliveryRate,
					$sent > 0 ? $deliveryRate : null
				);

				$this->renderFunnelItem(
					$read,
					__( 'Read', 'whatsapp-commerce-hub' ),
					$sent > 0 ? ( $read / $sent ) * 100 : 0,
					$delivered > 0 ? $readRate : null
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a funnel item.
	 *
	 * @param int        $count      Count value.
	 * @param string     $label      Label text.
	 * @param float      $barWidth   Bar width percentage.
	 * @param float|null $percentage Optional percentage to display.
	 * @return void
	 */
	protected function renderFunnelItem( int $count, string $label, float $barWidth, ?float $percentage = null ): void {
		?>
		<div class="wch-funnel-item">
			<div class="wch-funnel-number"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
			<div class="wch-funnel-label">
				<?php echo esc_html( $label ); ?>
				<?php if ( null !== $percentage ) : ?>
					<span class="wch-percentage">
						(<?php echo esc_html( number_format( $percentage, 1 ) ); ?>%)
					</span>
				<?php endif; ?>
			</div>
			<div class="wch-funnel-bar" style="width: <?php echo esc_attr( $barWidth ); ?>%;"></div>
		</div>
		<?php
	}

	/**
	 * Render errors breakdown table.
	 *
	 * @param array $stats Campaign statistics.
	 * @return void
	 */
	protected function renderErrorsBreakdown( array $stats ): void {
		$errors = $stats['errors'] ?? [];

		if ( empty( $errors ) ) {
			return;
		}
		?>
		<div class="wch-errors-breakdown">
			<h3><?php esc_html_e( 'Errors Breakdown', 'whatsapp-commerce-hub' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Recipient', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Error', 'whatsapp-commerce-hub' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $errors as $error ) : ?>
						<tr>
							<td><?php echo esc_html( $error['recipient'] ?? '' ); ?></td>
							<td><?php echo esc_html( $error['error'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render report actions.
	 *
	 * @param int $campaignId Campaign ID.
	 * @return void
	 */
	protected function renderActions( int $campaignId ): void {
		?>
		<div class="wch-report-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-broadcasts' ) ); ?>" class="button">
				<?php esc_html_e( 'Back to Campaigns', 'whatsapp-commerce-hub' ); ?>
			</a>
			<button type="button" class="button" id="wch-export-report" data-campaign-id="<?php echo esc_attr( $campaignId ); ?>">
				<?php esc_html_e( 'Export Report', 'whatsapp-commerce-hub' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Get report data for export.
	 *
	 * @param int $campaignId Campaign ID.
	 * @return array|null Report data or null if not found.
	 */
	public function getReportData( int $campaignId ): ?array {
		$campaign = $this->repository->getById( $campaignId );

		if ( null === $campaign ) {
			return null;
		}

		$stats = $campaign['stats'] ?? $this->getDefaultStats();

		return [
			'campaign'    => [
				'id'       => $campaign['id'],
				'name'     => $campaign['name'],
				'template' => $campaign['template_name'],
				'status'   => $campaign['status'],
				'sent_at'  => $campaign['sent_at'] ?? null,
			],
			'statistics'  => [
				'total'     => $stats['total'] ?? 0,
				'sent'      => $stats['sent'] ?? 0,
				'delivered' => $stats['delivered'] ?? 0,
				'read'      => $stats['read'] ?? 0,
				'failed'    => $stats['failed'] ?? 0,
			],
			'rates'       => [
				'delivery_rate' => $stats['sent'] > 0
					? round( ( $stats['delivered'] / $stats['sent'] ) * 100, 2 )
					: 0,
				'read_rate'     => $stats['delivered'] > 0
					? round( ( $stats['read'] / $stats['delivered'] ) * 100, 2 )
					: 0,
			],
			'errors'      => $stats['errors'] ?? [],
			'exported_at' => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Format campaign date.
	 *
	 * @param array $campaign Campaign data.
	 * @return string Formatted date.
	 */
	protected function formatCampaignDate( array $campaign ): string {
		$dateField = in_array( $campaign['status'] ?? '', [ 'scheduled', 'draft' ], true )
			? 'scheduled_at'
			: 'sent_at';

		$date = $campaign[ $dateField ] ?? $campaign['created_at'] ?? '';

		if ( empty( $date ) ) {
			return '-';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $date )
		);
	}

	/**
	 * Get default statistics structure.
	 *
	 * @return array Default stats.
	 */
	protected function getDefaultStats(): array {
		return [
			'sent'      => 0,
			'delivered' => 0,
			'read'      => 0,
			'failed'    => 0,
			'errors'    => [],
		];
	}
}
