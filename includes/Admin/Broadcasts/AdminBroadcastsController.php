<?php
/**
 * Admin Broadcasts Controller
 *
 * Handles menu registration, routing, and page rendering for broadcasts.
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
 * Class AdminBroadcastsController
 *
 * Controls admin broadcasts page.
 */
class AdminBroadcastsController {

	/**
	 * Menu slug.
	 */
	public const MENU_SLUG = 'wch-broadcasts';

	/**
	 * Capability required.
	 */
	protected const CAPABILITY = 'manage_woocommerce';

	/**
	 * Campaign repository.
	 *
	 * @var CampaignRepositoryInterface
	 */
	protected CampaignRepositoryInterface $repository;

	/**
	 * Wizard renderer.
	 *
	 * @var BroadcastWizardRenderer
	 */
	protected BroadcastWizardRenderer $wizardRenderer;

	/**
	 * Report generator.
	 *
	 * @var CampaignReportGenerator
	 */
	protected CampaignReportGenerator $reportGenerator;

	/**
	 * AJAX handler.
	 *
	 * @var BroadcastsAjaxHandler
	 */
	protected BroadcastsAjaxHandler $ajaxHandler;

	/**
	 * Constructor.
	 *
	 * @param CampaignRepositoryInterface $repository      Campaign repository.
	 * @param BroadcastWizardRenderer     $wizardRenderer  Wizard renderer.
	 * @param CampaignReportGenerator     $reportGenerator Report generator.
	 * @param BroadcastsAjaxHandler       $ajaxHandler     AJAX handler.
	 */
	public function __construct(
		CampaignRepositoryInterface $repository,
		BroadcastWizardRenderer $wizardRenderer,
		CampaignReportGenerator $reportGenerator,
		BroadcastsAjaxHandler $ajaxHandler
	) {
		$this->repository      = $repository;
		$this->wizardRenderer  = $wizardRenderer;
		$this->reportGenerator = $reportGenerator;
		$this->ajaxHandler     = $ajaxHandler;
	}

	/**
	 * Initialize controller.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'addMenuItem' ), 52 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );

		// Register AJAX handlers.
		$this->ajaxHandler->register();
	}

	/**
	 * Add admin menu item.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Broadcast Campaigns', 'whatsapp-commerce-hub' ),
			__( 'Broadcasts', 'whatsapp-commerce-hub' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'renderPage' )
		);

		add_action( 'load-' . $hook, array( $this, 'addHelpTab' ) );
	}

	/**
	 * Add contextual help tab.
	 *
	 * @return void
	 */
	public function addHelpTab(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'wch_broadcasts_overview',
				'title'   => __( 'Overview', 'whatsapp-commerce-hub' ),
				'content' => '<p>' . __( 'Create and manage promotional broadcast campaigns to send WhatsApp messages to your customers.', 'whatsapp-commerce-hub' ) . '</p>',
			)
		);
	}

	/**
	 * Enqueue scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-broadcasts',
			WCH_PLUGIN_URL . 'assets/admin-broadcasts.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-broadcasts',
			WCH_PLUGIN_URL . 'assets/admin-broadcasts.js',
			array( 'jquery', 'wp-i18n' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-broadcasts',
			'wchBroadcasts',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wch_broadcasts_nonce' ),
				'strings' => $this->getLocalizedStrings(),
			)
		);
	}

	/**
	 * Get localized strings for JavaScript.
	 *
	 * @return array Localized strings.
	 */
	protected function getLocalizedStrings(): array {
		return array(
			'confirmDelete'     => __( 'Are you sure you want to delete this campaign?', 'whatsapp-commerce-hub' ),
			'confirmSend'       => __( 'Are you sure you want to send this campaign?', 'whatsapp-commerce-hub' ),
			'savingCampaign'    => __( 'Saving campaign...', 'whatsapp-commerce-hub' ),
			'sendingCampaign'   => __( 'Scheduling campaign...', 'whatsapp-commerce-hub' ),
			'deletingCampaign'  => __( 'Deleting campaign...', 'whatsapp-commerce-hub' ),
			'loadingReport'     => __( 'Loading report...', 'whatsapp-commerce-hub' ),
			'loadingAudience'   => __( 'Calculating audience...', 'whatsapp-commerce-hub' ),
			'errorOccurred'     => __( 'An error occurred. Please try again.', 'whatsapp-commerce-hub' ),
			'campaignSaved'     => __( 'Campaign saved successfully!', 'whatsapp-commerce-hub' ),
			'campaignScheduled' => __( 'Campaign scheduled successfully!', 'whatsapp-commerce-hub' ),
			'testSent'          => __( 'Test message sent!', 'whatsapp-commerce-hub' ),
		);
	}

	/**
	 * Render broadcasts page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$campaignId = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;

		?>
		<div class="wrap wch-broadcasts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Broadcast Campaigns', 'whatsapp-commerce-hub' ); ?></h1>

			<?php $this->renderPageAction( $action ); ?>

			<hr class="wp-header-end">

			<?php
			switch ( $action ) {
				case 'create':
				case 'edit':
					$this->wizardRenderer->render( $campaignId );
					break;
				case 'report':
					$this->reportGenerator->render( $campaignId );
					break;
				default:
					$this->renderCampaignsList();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render page action button.
	 *
	 * @param string $action Current action.
	 * @return void
	 */
	protected function renderPageAction( string $action ): void {
		if ( 'list' === $action ) {
			printf(
				'<a href="%s" class="page-title-action">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=create' ) ),
				esc_html__( 'Create Campaign', 'whatsapp-commerce-hub' )
			);
		} else {
			printf(
				'<a href="%s" class="page-title-action">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Back to Campaigns', 'whatsapp-commerce-hub' )
			);
		}
	}

	/**
	 * Render campaigns list.
	 *
	 * @return void
	 */
	protected function renderCampaignsList(): void {
		$campaigns = $this->repository->getAll();
		?>
		<div class="wch-campaigns-list">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Template', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Audience Size', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Status', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Statistics', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Date', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'whatsapp-commerce-hub' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $campaigns ) ) : ?>
						<?php $this->renderEmptyState(); ?>
					<?php else : ?>
						<?php foreach ( $campaigns as $campaign ) : ?>
							<?php $this->renderCampaignRow( $campaign ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render empty state message.
	 *
	 * @return void
	 */
	protected function renderEmptyState(): void {
		?>
		<tr>
			<td colspan="7" class="wch-no-campaigns">
				<p><?php esc_html_e( 'No campaigns found.', 'whatsapp-commerce-hub' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=create' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create Your First Campaign', 'whatsapp-commerce-hub' ); ?>
				</a>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a single campaign row.
	 *
	 * @param array $campaign Campaign data.
	 * @return void
	 */
	protected function renderCampaignRow( array $campaign ): void {
		?>
		<tr data-campaign-id="<?php echo esc_attr( $campaign['id'] ); ?>">
			<td><strong><?php echo esc_html( $campaign['name'] ?? '' ); ?></strong></td>
			<td><?php echo esc_html( $campaign['template_name'] ?? __( 'N/A', 'whatsapp-commerce-hub' ) ); ?></td>
			<td><?php echo esc_html( number_format_i18n( $campaign['audience_size'] ?? 0 ) ); ?></td>
			<td><?php echo wp_kses_post( $this->getStatusBadge( $campaign['status'] ?? 'draft' ) ); ?></td>
			<td><?php echo wp_kses_post( $this->formatCampaignStats( $campaign ) ); ?></td>
			<td><?php echo esc_html( $this->formatCampaignDate( $campaign ) ); ?></td>
			<td class="wch-campaign-actions">
				<?php echo wp_kses_post( $this->getCampaignActions( $campaign ) ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Get status badge HTML.
	 *
	 * @param string $status Status.
	 * @return string Badge HTML.
	 */
	protected function getStatusBadge( string $status ): string {
		$badges = array(
			'draft'     => '<span class="wch-badge wch-badge-draft">' . __( 'Draft', 'whatsapp-commerce-hub' ) . '</span>',
			'scheduled' => '<span class="wch-badge wch-badge-scheduled">' . __( 'Scheduled', 'whatsapp-commerce-hub' ) . '</span>',
			'sending'   => '<span class="wch-badge wch-badge-sending">' . __( 'Sending', 'whatsapp-commerce-hub' ) . '</span>',
			'completed' => '<span class="wch-badge wch-badge-completed">' . __( 'Completed', 'whatsapp-commerce-hub' ) . '</span>',
			'failed'    => '<span class="wch-badge wch-badge-failed">' . __( 'Failed', 'whatsapp-commerce-hub' ) . '</span>',
			'cancelled' => '<span class="wch-badge wch-badge-cancelled">' . __( 'Cancelled', 'whatsapp-commerce-hub' ) . '</span>',
		);

		return $badges[ $status ] ?? $status;
	}

	/**
	 * Format campaign statistics.
	 *
	 * @param array $campaign Campaign data.
	 * @return string Formatted stats.
	 */
	protected function formatCampaignStats( array $campaign ): string {
		if ( in_array( $campaign['status'] ?? '', array( 'draft', 'scheduled' ), true ) ) {
			return '-';
		}

		$stats     = $campaign['stats'] ?? array();
		$sent      = $stats['sent'] ?? 0;
		$delivered = $stats['delivered'] ?? 0;
		$read      = $stats['read'] ?? 0;

		return sprintf(
			'<span class="wch-stats-compact">%d / %d / %d</span>',
			$sent,
			$delivered,
			$read
		);
	}

	/**
	 * Format campaign date.
	 *
	 * @param array $campaign Campaign data.
	 * @return string Formatted date.
	 */
	protected function formatCampaignDate( array $campaign ): string {
		$dateField = in_array( $campaign['status'] ?? '', array( 'scheduled', 'draft' ), true )
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
	 * Get campaign actions HTML.
	 *
	 * @param array $campaign Campaign data.
	 * @return string Actions HTML.
	 */
	protected function getCampaignActions( array $campaign ): string {
		$actions = array();

		if ( 'draft' === $campaign['status'] ) {
			$actions[] = sprintf(
				'<a href="%s" class="button button-small">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=edit&campaign_id=' . $campaign['id'] ) ),
				__( 'Edit', 'whatsapp-commerce-hub' )
			);
		}

		$actions[] = sprintf(
			'<button type="button" class="button button-small wch-duplicate-campaign" data-campaign-id="%d">%s</button>',
			$campaign['id'],
			__( 'Duplicate', 'whatsapp-commerce-hub' )
		);

		if ( in_array( $campaign['status'], array( 'completed', 'failed', 'sending' ), true ) ) {
			$actions[] = sprintf(
				'<a href="%s" class="button button-small">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=report&campaign_id=' . $campaign['id'] ) ),
				__( 'View Report', 'whatsapp-commerce-hub' )
			);
		}

		$actions[] = sprintf(
			'<button type="button" class="button button-small button-link-delete wch-delete-campaign" data-campaign-id="%d">%s</button>',
			$campaign['id'],
			__( 'Delete', 'whatsapp-commerce-hub' )
		);

		return implode( ' ', $actions );
	}
}
