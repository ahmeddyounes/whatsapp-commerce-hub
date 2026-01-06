<?php
/**
 * Admin Jobs UI Class
 *
 * Provides admin interface for monitoring and managing background jobs.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Admin_Jobs
 */
class WCH_Admin_Jobs {
	/**
	 * Initialize the admin jobs UI.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 60 );
		add_action( 'admin_post_wch_retry_failed_job', array( __CLASS__, 'handle_retry_job' ) );
		add_action( 'admin_post_wch_trigger_cart_cleanup', array( __CLASS__, 'handle_trigger_cleanup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			__( 'WCH Background Jobs', 'whatsapp-commerce-hub' ),
			__( 'WCH Jobs', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-jobs',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
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
	 */
	public static function render_page() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$pending_counts = WCH_Job_Dispatcher::get_all_pending_counts();
		$failed_jobs    = WCH_Job_Dispatcher::get_failed_jobs( 20 );
		$cleanup_result = WCH_Cart_Cleanup_Handler::get_last_cleanup_result();
		$active_carts   = WCH_Cart_Cleanup_Handler::get_active_carts_count();
		$expired_carts  = WCH_Cart_Cleanup_Handler::get_expired_carts_count();

		?>
		<div class="wrap wch-jobs-page">
			<h1><?php esc_html_e( 'WhatsApp Commerce Hub - Background Jobs', 'whatsapp-commerce-hub' ); ?></h1>

			<?php self::render_notices(); ?>

			<div class="wch-jobs-grid">
				<!-- Pending Jobs -->
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
							<?php if ( empty( $pending_counts ) || array_sum( $pending_counts ) === 0 ) : ?>
								<tr>
									<td colspan="2"><?php esc_html_e( 'No pending jobs', 'whatsapp-commerce-hub' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $pending_counts as $hook => $count ) : ?>
									<tr>
										<td><code><?php echo esc_html( $hook ); ?></code></td>
										<td><strong><?php echo esc_html( $count ); ?></strong></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Cart Statistics -->
				<div class="wch-jobs-card">
					<h2><?php esc_html_e( 'Cart Statistics', 'whatsapp-commerce-hub' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Active Carts', 'whatsapp-commerce-hub' ); ?></td>
								<td><strong><?php echo esc_html( $active_carts ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Expired Carts (Pending Cleanup)', 'whatsapp-commerce-hub' ); ?></td>
								<td><strong><?php echo esc_html( $expired_carts ); ?></strong></td>
							</tr>
							<?php if ( $cleanup_result ) : ?>
								<tr>
									<td><?php esc_html_e( 'Last Cleanup', 'whatsapp-commerce-hub' ); ?></td>
									<td>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: count, 2: timestamp */
												__( '%1$d carts cleaned at %2$s', 'whatsapp-commerce-hub' ),
												$cleanup_result['deleted_count'],
												$cleanup_result['timestamp']
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
			</div>

			<!-- Failed Jobs -->
			<div class="wch-jobs-card">
				<h2><?php esc_html_e( 'Failed Jobs', 'whatsapp-commerce-hub' ); ?></h2>
				<?php if ( empty( $failed_jobs ) ) : ?>
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
							<?php foreach ( $failed_jobs as $job ) : ?>
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

			<!-- Job Processing Status -->
			<div class="wch-jobs-card">
				<h2><?php esc_html_e( 'Job Processing Status', 'whatsapp-commerce-hub' ); ?></h2>
				<p>
					<?php
					$status = function_exists( 'as_next_scheduled_action' )
						? '<span style="color: green;">✓ ' . esc_html__( 'Action Scheduler is active', 'whatsapp-commerce-hub' ) . '</span>'
						: '<span style="color: red;">✗ ' . esc_html__( 'Action Scheduler is not available', 'whatsapp-commerce-hub' ) . '</span>';
					echo wp_kses_post( $status );
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=action-scheduler' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'View Action Scheduler', 'whatsapp-commerce-hub' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 */
	private static function render_notices() {
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
				<p><?php echo esc_html( $_GET['error'] ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Handle retry job action.
	 */
	public static function handle_retry_job() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'whatsapp-commerce-hub' ) );
		}

		// Get job ID.
		$job_id = isset( $_POST['job_id'] ) ? intval( $_POST['job_id'] ) : 0;

		// Verify nonce.
		if ( ! isset( $_POST['wch_nonce'] ) || ! wp_verify_nonce( $_POST['wch_nonce'], 'wch_retry_job_' . $job_id ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'whatsapp-commerce-hub' ) );
		}

		if ( ! $job_id ) {
			wp_safe_redirect( add_query_arg( 'error', urlencode( 'Invalid job ID' ), admin_url( 'admin.php?page=wch-jobs' ) ) );
			exit;
		}

		// Retry the job.
		$new_action_id = WCH_Job_Dispatcher::retry_failed_job( $job_id );

		if ( $new_action_id ) {
			wp_safe_redirect( add_query_arg( 'job_retried', '1', admin_url( 'admin.php?page=wch-jobs' ) ) );
		} else {
			wp_safe_redirect( add_query_arg( 'error', urlencode( 'Failed to retry job' ), admin_url( 'admin.php?page=wch-jobs' ) ) );
		}
		exit;
	}

	/**
	 * Handle trigger cart cleanup action.
	 */
	public static function handle_trigger_cleanup() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'whatsapp-commerce-hub' ) );
		}

		// Verify nonce.
		if ( ! isset( $_POST['wch_nonce'] ) || ! wp_verify_nonce( $_POST['wch_nonce'], 'wch_trigger_cart_cleanup' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'whatsapp-commerce-hub' ) );
		}

		// Trigger cleanup.
		WCH_Cart_Cleanup_Handler::trigger_cleanup();

		wp_safe_redirect( add_query_arg( 'cleanup_triggered', '1', admin_url( 'admin.php?page=wch-jobs' ) ) );
		exit;
	}
}
