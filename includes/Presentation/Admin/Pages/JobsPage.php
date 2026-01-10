<?php
/**
 * Admin Jobs Page
 *
 * Provides admin interface for monitoring and managing background jobs.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.MaxExceeded
// Long lines in inline CSS styles are acceptable for readability.

/**
 * Class JobsPage
 *
 * Handles the admin interface for managing background jobs.
 */
class JobsPage {

	/**
	 * Initialize the admin jobs UI.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'addMenuPage' ), 60 );
		add_action( 'admin_post_wch_retry_failed_job', array( $this, 'handleRetryJob' ) );
		add_action( 'admin_post_wch_trigger_cart_cleanup', array( $this, 'handleTriggerCleanup' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
	}

	/**
	 * Add menu page.
	 *
	 * @return void
	 */
	public function addMenuPage(): void {
		add_submenu_page(
			'woocommerce',
			__( 'WCH Background Jobs', 'whatsapp-commerce-hub' ),
			__( 'WCH Jobs', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-jobs',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_wch-jobs' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-jobs',
			WCH_PLUGIN_URL . 'assets/css/admin-jobs.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-jobs',
			WCH_PLUGIN_URL . 'assets/js/admin-jobs.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);
	}

	/**
	 * Render the jobs monitoring page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$pendingCounts = \WCH_Job_Dispatcher::get_all_pending_counts();
		$failedJobs    = \WCH_Job_Dispatcher::get_failed_jobs( 20 );
		$cleanupResult = \WCH_Cart_Cleanup_Handler::get_last_cleanup_result();
		$activeCarts   = \WCH_Cart_Cleanup_Handler::get_active_carts_count();
		$expiredCarts  = \WCH_Cart_Cleanup_Handler::get_expired_carts_count();

		$this->renderPageHtml( $pendingCounts, $failedJobs, $cleanupResult, $activeCarts, $expiredCarts );
	}

	/**
	 * Render page HTML.
	 *
	 * @param array      $pendingCounts Pending job counts.
	 * @param array      $failedJobs    Failed jobs.
	 * @param array|null $cleanupResult Cleanup result.
	 * @param int        $activeCarts   Active carts count.
	 * @param int        $expiredCarts  Expired carts count.
	 * @return void
	 */
	private function renderPageHtml( array $pendingCounts, array $failedJobs, ?array $cleanupResult, int $activeCarts, int $expiredCarts ): void {
		?>
		<div class="wrap wch-jobs-page">
			<h1><?php esc_html_e( 'WhatsApp Commerce Hub - Background Jobs', 'whatsapp-commerce-hub' ); ?></h1>

			<?php $this->renderNotices(); ?>

			<div class="wch-jobs-grid">
				<?php $this->renderPendingJobsCard( $pendingCounts ); ?>
				<?php $this->renderCartStatisticsCard( $activeCarts, $expiredCarts, $cleanupResult ); ?>
			</div>

			<?php $this->renderFailedJobsCard( $failedJobs ); ?>
			<?php $this->renderJobStatusCard(); ?>
		</div>
		<?php
	}

	/**
	 * Render pending jobs card.
	 *
	 * @param array $pendingCounts Pending job counts.
	 * @return void
	 */
	private function renderPendingJobsCard( array $pendingCounts ): void {
		?>
		<div class="wch-jobs-card">
			<h2><?php esc_html_e( 'Pending Jobs by Type', 'whatsapp-commerce-hub' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job Type', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Pending Count', 'whatsapp-commerce-hub' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $pendingCounts ) || array_sum( $pendingCounts ) === 0 ) : ?>
						<tr>
							<td colspan="2"><?php esc_html_e( 'No pending jobs', 'whatsapp-commerce-hub' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $pendingCounts as $hook => $count ) : ?>
							<tr>
								<td><code><?php echo esc_html( $hook ); ?></code></td>
								<td><strong><?php echo esc_html( $count ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render cart statistics card.
	 *
	 * @param int        $activeCarts   Active carts count.
	 * @param int        $expiredCarts  Expired carts count.
	 * @param array|null $cleanupResult Cleanup result.
	 * @return void
	 */
	private function renderCartStatisticsCard( int $activeCarts, int $expiredCarts, ?array $cleanupResult ): void {
		?>
		<div class="wch-jobs-card">
			<h2><?php esc_html_e( 'Cart Statistics', 'whatsapp-commerce-hub' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Active Carts', 'whatsapp-commerce-hub' ); ?></td>
						<td><strong><?php echo esc_html( $activeCarts ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Expired Carts (Pending Cleanup)', 'whatsapp-commerce-hub' ); ?></td>
						<td><strong><?php echo esc_html( $expiredCarts ); ?></strong></td>
					</tr>
					<?php if ( $cleanupResult ) : ?>
						<tr>
							<td><?php esc_html_e( 'Last Cleanup', 'whatsapp-commerce-hub' ); ?></td>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: count, 2: timestamp */
										__( '%1$d carts cleaned at %2$s', 'whatsapp-commerce-hub' ),
										$cleanupResult['deleted_count'],
										$cleanupResult['timestamp']
									)
								);
								?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
					<?php wp_nonce_field( 'wch_trigger_cart_cleanup', 'wch_nonce' ); ?>
					<input type="hidden" name="action" value="wch_trigger_cart_cleanup">
					<button type="submit" class="button button-secondary">
						<?php esc_html_e( 'Trigger Cart Cleanup Now', 'whatsapp-commerce-hub' ); ?>
					</button>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Render failed jobs card.
	 *
	 * @param array $failedJobs Failed jobs.
	 * @return void
	 */
	private function renderFailedJobsCard( array $failedJobs ): void {
		?>
		<div class="wch-jobs-card">
			<h2><?php esc_html_e( 'Failed Jobs', 'whatsapp-commerce-hub' ); ?></h2>
			<?php if ( empty( $failedJobs ) ) : ?>
				<p><?php esc_html_e( 'No failed jobs found.', 'whatsapp-commerce-hub' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job ID', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Hook', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Scheduled', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Arguments', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Action', 'whatsapp-commerce-hub' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $failedJobs as $job ) : ?>
							<tr>
								<td><?php echo esc_html( $job['id'] ); ?></td>
								<td><code><?php echo esc_html( $job['hook'] ); ?></code></td>
								<td><?php echo esc_html( $job['scheduled'] ); ?></td>
								<td><code><?php echo esc_html( wp_json_encode( $job['args'] ) ); ?></code></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<?php wp_nonce_field( 'wch_retry_job_' . $job['id'], 'wch_nonce' ); ?>
										<input type="hidden" name="action" value="wch_retry_failed_job">
										<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>">
										<button type="submit" class="button button-small button-primary">
											<?php esc_html_e( 'Retry', 'whatsapp-commerce-hub' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render job processing status card.
	 *
	 * @return void
	 */
	private function renderJobStatusCard(): void {
		$hasActionScheduler = function_exists( 'as_next_scheduled_action' );
		?>
		<div class="wch-jobs-card">
			<h2><?php esc_html_e( 'Job Processing Status', 'whatsapp-commerce-hub' ); ?></h2>
			<p>
				<?php if ( $hasActionScheduler ) : ?>
					<span style="color: green;">&#10003; <?php esc_html_e( 'Action Scheduler is active', 'whatsapp-commerce-hub' ); ?></span>
				<?php else : ?>
					<span style="color: red;">&#10007; <?php esc_html_e( 'Action Scheduler is not available', 'whatsapp-commerce-hub' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=action-scheduler' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'View Action Scheduler', 'whatsapp-commerce-hub' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function renderNotices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notices.
		if ( isset( $_GET['job_retried'] ) && '1' === $_GET['job_retried'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Job has been scheduled for retry.', 'whatsapp-commerce-hub' ); ?></p>
			</div>
			<?php
		}

		if ( isset( $_GET['cleanup_triggered'] ) && '1' === $_GET['cleanup_triggered'] ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Cart cleanup has been triggered.', 'whatsapp-commerce-hub' ); ?></p>
			</div>
			<?php
		}

		if ( isset( $_GET['error'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></p>
			</div>
			<?php
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handle retry job action.
	 *
	 * @return void
	 */
	public function handleRetryJob(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'whatsapp-commerce-hub' ) );
		}

		$jobId = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;

		if ( ! isset( $_POST['wch_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wch_nonce'] ) ), 'wch_retry_job_' . $jobId ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'whatsapp-commerce-hub' ) );
		}

		if ( ! $jobId ) {
			wp_safe_redirect( add_query_arg( 'error', urlencode( 'Invalid job ID' ), admin_url( 'admin.php?page=wch-jobs' ) ) );
			exit;
		}

		$newActionId = \WCH_Job_Dispatcher::retry_failed_job( $jobId );

		if ( $newActionId ) {
			wp_safe_redirect( add_query_arg( 'job_retried', '1', admin_url( 'admin.php?page=wch-jobs' ) ) );
		} else {
			wp_safe_redirect( add_query_arg( 'error', urlencode( 'Failed to retry job' ), admin_url( 'admin.php?page=wch-jobs' ) ) );
		}
		exit;
	}

	/**
	 * Handle trigger cart cleanup action.
	 *
	 * @return void
	 */
	public function handleTriggerCleanup(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'whatsapp-commerce-hub' ) );
		}

		if ( ! isset( $_POST['wch_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wch_nonce'] ) ), 'wch_trigger_cart_cleanup' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'whatsapp-commerce-hub' ) );
		}

		\WCH_Cart_Cleanup_Handler::trigger_cleanup();

		wp_safe_redirect( add_query_arg( 'cleanup_triggered', '1', admin_url( 'admin.php?page=wch-jobs' ) ) );
		exit;
	}
}
