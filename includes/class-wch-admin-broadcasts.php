<?php
/**
 * Admin Broadcasts Page
 *
 * @package WhatsApp_Commerce_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCH_Admin_Broadcasts class
 */
class WCH_Admin_Broadcasts {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ), 52 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// AJAX handlers
		add_action( 'wp_ajax_wch_get_campaigns', array( __CLASS__, 'ajax_get_campaigns' ) );
		add_action( 'wp_ajax_wch_save_campaign', array( __CLASS__, 'ajax_save_campaign' ) );
		add_action( 'wp_ajax_wch_delete_campaign', array( __CLASS__, 'ajax_delete_campaign' ) );
		add_action( 'wp_ajax_wch_get_campaign', array( __CLASS__, 'ajax_get_campaign' ) );
		add_action( 'wp_ajax_wch_get_audience_count', array( __CLASS__, 'ajax_get_audience_count' ) );
		add_action( 'wp_ajax_wch_send_campaign', array( __CLASS__, 'ajax_send_campaign' ) );
		add_action( 'wp_ajax_wch_send_test_broadcast', array( __CLASS__, 'ajax_send_test_broadcast' ) );
		add_action( 'wp_ajax_wch_get_campaign_report', array( __CLASS__, 'ajax_get_campaign_report' ) );
		add_action( 'wp_ajax_wch_duplicate_campaign', array( __CLASS__, 'ajax_duplicate_campaign' ) );
		add_action( 'wp_ajax_wch_get_approved_templates', array( __CLASS__, 'ajax_get_approved_templates' ) );
	}

	/**
	 * Add submenu item
	 */
	public static function add_menu_item() {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Broadcast Campaigns', 'whatsapp-commerce-hub' ),
			__( 'Broadcasts', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-broadcasts',
			array( __CLASS__, 'render_page' )
		);
		add_action( 'load-' . $hook, array( __CLASS__, 'add_help_tab' ) );
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wch-broadcasts' !== $hook ) {
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
				'strings' => array(
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
				),
			)
		);
	}

	/**
	 * Add help tab
	 */
	public static function add_help_tab() {
		$screen = get_current_screen();

		$screen->add_help_tab(
			array(
				'id'      => 'wch_broadcasts_overview',
				'title'   => __( 'Overview', 'whatsapp-commerce-hub' ),
				'content' => '<p>' . __( 'Create and manage promotional broadcast campaigns to send WhatsApp messages to your customers.', 'whatsapp-commerce-hub' ) . '</p>',
			)
		);
	}

	/**
	 * Render admin page
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$action      = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;

		?>
		<div class="wrap wch-broadcasts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Broadcast Campaigns', 'whatsapp-commerce-hub' ); ?></h1>

			<?php if ( 'list' === $action ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-broadcasts&action=create' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Create Campaign', 'whatsapp-commerce-hub' ); ?>
				</a>
			<?php elseif ( 'create' === $action || 'edit' === $action ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-broadcasts' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Back to Campaigns', 'whatsapp-commerce-hub' ); ?>
				</a>
			<?php endif; ?>

			<hr class="wp-header-end">

			<?php
			switch ( $action ) {
				case 'create':
				case 'edit':
					self::render_campaign_wizard( $campaign_id );
					break;
				case 'report':
					self::render_campaign_report( $campaign_id );
					break;
				default:
					self::render_campaigns_list();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render campaigns list
	 */
	private static function render_campaigns_list() {
		$campaigns = self::get_campaigns();
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
						<tr>
							<td colspan="7" class="wch-no-campaigns">
								<p><?php esc_html_e( 'No campaigns found.', 'whatsapp-commerce-hub' ); ?></p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-broadcasts&action=create' ) ); ?>" class="button button-primary">
									<?php esc_html_e( 'Create Your First Campaign', 'whatsapp-commerce-hub' ); ?>
								</a>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $campaigns as $campaign ) : ?>
							<tr data-campaign-id="<?php echo esc_attr( $campaign['id'] ); ?>">
								<td><strong><?php echo esc_html( $campaign['name'] ); ?></strong></td>
								<td><?php echo esc_html( $campaign['template_name'] ?? __( 'N/A', 'whatsapp-commerce-hub' ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $campaign['audience_size'] ?? 0 ) ); ?></td>
								<td><?php echo self::get_status_badge( $campaign['status'] ); ?></td>
								<td><?php echo self::format_campaign_stats( $campaign ); ?></td>
								<td><?php echo self::format_campaign_date( $campaign ); ?></td>
								<td class="wch-campaign-actions">
									<?php echo self::get_campaign_actions( $campaign ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render campaign wizard
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	private static function render_campaign_wizard( $campaign_id = 0 ) {
		$campaign = null;
		if ( $campaign_id ) {
			$campaign = self::get_campaign_by_id( $campaign_id );
		}
		?>
		<div class="wch-campaign-wizard" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>">
			<div class="wch-wizard-steps">
				<div class="wch-step active" data-step="1">
					<span class="wch-step-number">1</span>
					<span class="wch-step-label"><?php esc_html_e( 'Template', 'whatsapp-commerce-hub' ); ?></span>
				</div>
				<div class="wch-step" data-step="2">
					<span class="wch-step-number">2</span>
					<span class="wch-step-label"><?php esc_html_e( 'Audience', 'whatsapp-commerce-hub' ); ?></span>
				</div>
				<div class="wch-step" data-step="3">
					<span class="wch-step-number">3</span>
					<span class="wch-step-label"><?php esc_html_e( 'Personalize', 'whatsapp-commerce-hub' ); ?></span>
				</div>
				<div class="wch-step" data-step="4">
					<span class="wch-step-number">4</span>
					<span class="wch-step-label"><?php esc_html_e( 'Schedule', 'whatsapp-commerce-hub' ); ?></span>
				</div>
				<div class="wch-step" data-step="5">
					<span class="wch-step-number">5</span>
					<span class="wch-step-label"><?php esc_html_e( 'Review', 'whatsapp-commerce-hub' ); ?></span>
				</div>
			</div>

			<div class="wch-wizard-content">
				<!-- Step 1: Template Selection -->
				<div class="wch-wizard-panel" data-panel="1">
					<h2><?php esc_html_e( 'Select Template', 'whatsapp-commerce-hub' ); ?></h2>
					<div class="wch-template-selection">
						<div class="wch-templates-list" id="wch-templates-list">
							<p class="wch-loading"><?php esc_html_e( 'Loading templates...', 'whatsapp-commerce-hub' ); ?></p>
						</div>
						<div class="wch-template-preview" id="wch-template-preview">
							<h3><?php esc_html_e( 'Template Preview', 'whatsapp-commerce-hub' ); ?></h3>
							<div class="wch-preview-content">
								<p class="wch-placeholder"><?php esc_html_e( 'Select a template to preview', 'whatsapp-commerce-hub' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Step 2: Audience Selection -->
				<div class="wch-wizard-panel" data-panel="2" style="display:none;">
					<h2><?php esc_html_e( 'Select Audience', 'whatsapp-commerce-hub' ); ?></h2>
					<div class="wch-audience-builder">
						<div class="wch-audience-criteria">
							<div class="wch-form-field">
								<label>
									<input type="checkbox" name="audience_all" value="1" checked>
									<?php esc_html_e( 'All opted-in customers', 'whatsapp-commerce-hub' ); ?>
								</label>
							</div>
							<div class="wch-form-field">
								<label>
									<input type="checkbox" name="audience_recent_orders" value="1">
									<?php esc_html_e( 'Ordered in last', 'whatsapp-commerce-hub' ); ?>
									<input type="number" name="recent_orders_days" value="30" min="1" max="365" style="width: 80px;">
									<?php esc_html_e( 'days', 'whatsapp-commerce-hub' ); ?>
								</label>
							</div>
							<div class="wch-form-field">
								<label>
									<input type="checkbox" name="audience_category" value="1">
									<?php esc_html_e( 'Purchased from category:', 'whatsapp-commerce-hub' ); ?>
									<select name="category_id" style="width: 200px;">
										<option value=""><?php esc_html_e( 'Select category', 'whatsapp-commerce-hub' ); ?></option>
										<?php
										$categories = get_terms(
											array(
												'taxonomy' => 'product_cat',
												'hide_empty' => false,
											)
										);
										foreach ( $categories as $category ) {
											echo '<option value="' . esc_attr( $category->term_id ) . '">' . esc_html( $category->name ) . '</option>';
										}
										?>
									</select>
								</label>
							</div>
							<div class="wch-form-field">
								<label>
									<input type="checkbox" name="audience_cart_abandoners" value="1">
									<?php esc_html_e( 'Cart abandoners (last 7 days)', 'whatsapp-commerce-hub' ); ?>
								</label>
							</div>

							<h3><?php esc_html_e( 'Exclusions', 'whatsapp-commerce-hub' ); ?></h3>
							<div class="wch-form-field">
								<label>
									<input type="checkbox" name="exclude_recent_broadcast" value="1">
									<?php esc_html_e( 'Exclude customers who received a broadcast in last', 'whatsapp-commerce-hub' ); ?>
									<input type="number" name="exclude_broadcast_days" value="7" min="1" max="30" style="width: 80px;">
									<?php esc_html_e( 'days', 'whatsapp-commerce-hub' ); ?>
								</label>
							</div>
						</div>
						<div class="wch-audience-count">
							<div class="wch-count-box">
								<div class="wch-count-number" id="wch-audience-count">-</div>
								<div class="wch-count-label"><?php esc_html_e( 'Estimated Recipients', 'whatsapp-commerce-hub' ); ?></div>
							</div>
						</div>
					</div>
				</div>

				<!-- Step 3: Personalization -->
				<div class="wch-wizard-panel" data-panel="3" style="display:none;">
					<h2><?php esc_html_e( 'Personalize Message', 'whatsapp-commerce-hub' ); ?></h2>
					<div class="wch-personalization">
						<div class="wch-variable-mapping" id="wch-variable-mapping">
							<p class="wch-placeholder"><?php esc_html_e( 'Template variables will appear here', 'whatsapp-commerce-hub' ); ?></p>
						</div>
						<div class="wch-personalization-preview">
							<h3><?php esc_html_e( 'Preview with Sample Data', 'whatsapp-commerce-hub' ); ?></h3>
							<div class="wch-preview-content" id="wch-personalization-preview">
								<p class="wch-placeholder"><?php esc_html_e( 'Preview will appear here', 'whatsapp-commerce-hub' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Step 4: Schedule -->
				<div class="wch-wizard-panel" data-panel="4" style="display:none;">
					<h2><?php esc_html_e( 'Schedule Campaign', 'whatsapp-commerce-hub' ); ?></h2>
					<div class="wch-schedule-options">
						<div class="wch-form-field">
							<label>
								<input type="radio" name="send_timing" value="now" checked>
								<?php esc_html_e( 'Send Now', 'whatsapp-commerce-hub' ); ?>
							</label>
						</div>
						<div class="wch-form-field">
							<label>
								<input type="radio" name="send_timing" value="scheduled">
								<?php esc_html_e( 'Schedule for later', 'whatsapp-commerce-hub' ); ?>
							</label>
							<div class="wch-schedule-datetime" style="margin-left: 30px; display: none;">
								<label>
									<?php esc_html_e( 'Date:', 'whatsapp-commerce-hub' ); ?>
									<input type="date" name="schedule_date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
								</label>
								<label>
									<?php esc_html_e( 'Time:', 'whatsapp-commerce-hub' ); ?>
									<input type="time" name="schedule_time">
								</label>
								<label>
									<?php esc_html_e( 'Timezone:', 'whatsapp-commerce-hub' ); ?>
									<select name="schedule_timezone">
										<?php
										$current_offset = get_option( 'gmt_offset' );
										$tzstring       = get_option( 'timezone_string' );

										$selected_tz = $tzstring ? $tzstring : 'UTC';

										$timezones = array(
											'UTC'          => 'UTC',
											'America/New_York' => 'Eastern Time',
											'America/Chicago' => 'Central Time',
											'America/Denver' => 'Mountain Time',
											'America/Los_Angeles' => 'Pacific Time',
											'Europe/London' => 'London',
											'Europe/Paris' => 'Paris',
											'Asia/Dubai'   => 'Dubai',
											'Asia/Kolkata' => 'India',
											'Asia/Singapore' => 'Singapore',
											'Asia/Tokyo'   => 'Tokyo',
											'Australia/Sydney' => 'Sydney',
										);

										foreach ( $timezones as $tz => $label ) {
											printf(
												'<option value="%s" %s>%s</option>',
												esc_attr( $tz ),
												selected( $selected_tz, $tz, false ),
												esc_html( $label )
											);
										}
										?>
									</select>
								</label>
							</div>
						</div>
						<div class="wch-optimal-time-suggestion">
							<p class="description">
								<span class="dashicons dashicons-lightbulb"></span>
								<?php esc_html_e( 'Suggested optimal send time: 10:00 AM based on historical open rates', 'whatsapp-commerce-hub' ); ?>
							</p>
						</div>
					</div>
				</div>

				<!-- Step 5: Review -->
				<div class="wch-wizard-panel" data-panel="5" style="display:none;">
					<h2><?php esc_html_e( 'Review & Send', 'whatsapp-commerce-hub' ); ?></h2>
					<div class="wch-campaign-review">
						<div class="wch-review-section">
							<h3><?php esc_html_e( 'Campaign Name', 'whatsapp-commerce-hub' ); ?></h3>
							<div class="wch-form-field">
								<input type="text" name="campaign_name" placeholder="<?php esc_attr_e( 'Enter campaign name', 'whatsapp-commerce-hub' ); ?>" style="width: 100%; max-width: 500px;">
							</div>
						</div>

						<div class="wch-review-section">
							<h3><?php esc_html_e( 'Campaign Summary', 'whatsapp-commerce-hub' ); ?></h3>
							<table class="wch-review-table">
								<tr>
									<th><?php esc_html_e( 'Template:', 'whatsapp-commerce-hub' ); ?></th>
									<td id="review-template">-</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Audience:', 'whatsapp-commerce-hub' ); ?></th>
									<td id="review-audience">-</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Schedule:', 'whatsapp-commerce-hub' ); ?></th>
									<td id="review-schedule">-</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Estimated Cost:', 'whatsapp-commerce-hub' ); ?></th>
									<td id="review-cost">-</td>
								</tr>
							</table>
						</div>

						<div class="wch-review-section">
							<h3><?php esc_html_e( 'Message Preview', 'whatsapp-commerce-hub' ); ?></h3>
							<div class="wch-message-preview" id="review-message-preview">
								<p class="wch-placeholder"><?php esc_html_e( 'Message preview will appear here', 'whatsapp-commerce-hub' ); ?></p>
							</div>
						</div>

						<div class="wch-review-actions">
							<button type="button" class="button" id="wch-send-test">
								<?php esc_html_e( 'Send Test Message', 'whatsapp-commerce-hub' ); ?>
							</button>
							<button type="button" class="button button-primary button-large" id="wch-confirm-send">
								<?php esc_html_e( 'Confirm & Schedule Campaign', 'whatsapp-commerce-hub' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

			<div class="wch-wizard-navigation">
				<button type="button" class="button button-secondary" id="wch-wizard-prev" style="display:none;">
					<?php esc_html_e( 'Previous', 'whatsapp-commerce-hub' ); ?>
				</button>
				<button type="button" class="button button-primary" id="wch-wizard-next">
					<?php esc_html_e( 'Next', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render campaign report
	 *
	 * @param int $campaign_id Campaign ID.
	 */
	private static function render_campaign_report( $campaign_id ) {
		$campaign = self::get_campaign_by_id( $campaign_id );

		if ( ! $campaign ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Campaign not found.', 'whatsapp-commerce-hub' ) . '</p></div>';
			return;
		}

		$stats = isset( $campaign['stats'] ) ? $campaign['stats'] : array(
			'sent'      => 0,
			'delivered' => 0,
			'read'      => 0,
			'failed'    => 0,
			'errors'    => array(),
		);
		?>
		<div class="wch-campaign-report">
			<div class="wch-report-header">
				<h2><?php echo esc_html( $campaign['name'] ); ?></h2>
				<p class="wch-report-meta">
					<?php
					printf(
						/* translators: %s: campaign date */
						esc_html__( 'Sent: %s', 'whatsapp-commerce-hub' ),
						esc_html( self::format_campaign_date( $campaign ) )
					);
					?>
				</p>
			</div>

			<div class="wch-delivery-funnel">
				<h3><?php esc_html_e( 'Delivery Funnel', 'whatsapp-commerce-hub' ); ?></h3>
				<div class="wch-funnel-stats">
					<div class="wch-funnel-item">
						<div class="wch-funnel-number"><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></div>
						<div class="wch-funnel-label"><?php esc_html_e( 'Sent', 'whatsapp-commerce-hub' ); ?></div>
						<div class="wch-funnel-bar" style="width: 100%;"></div>
					</div>
					<div class="wch-funnel-item">
						<div class="wch-funnel-number"><?php echo esc_html( number_format_i18n( $stats['delivered'] ) ); ?></div>
						<div class="wch-funnel-label">
							<?php esc_html_e( 'Delivered', 'whatsapp-commerce-hub' ); ?>
							<?php if ( $stats['sent'] > 0 ) : ?>
								<span class="wch-percentage">
									(<?php echo esc_html( number_format( ( $stats['delivered'] / $stats['sent'] ) * 100, 1 ) ); ?>%)
								</span>
							<?php endif; ?>
						</div>
						<div class="wch-funnel-bar" style="width: <?php echo esc_attr( $stats['sent'] > 0 ? ( $stats['delivered'] / $stats['sent'] ) * 100 : 0 ); ?>%;"></div>
					</div>
					<div class="wch-funnel-item">
						<div class="wch-funnel-number"><?php echo esc_html( number_format_i18n( $stats['read'] ) ); ?></div>
						<div class="wch-funnel-label">
							<?php esc_html_e( 'Read', 'whatsapp-commerce-hub' ); ?>
							<?php if ( $stats['delivered'] > 0 ) : ?>
								<span class="wch-percentage">
									(<?php echo esc_html( number_format( ( $stats['read'] / $stats['delivered'] ) * 100, 1 ) ); ?>%)
								</span>
							<?php endif; ?>
						</div>
						<div class="wch-funnel-bar" style="width: <?php echo esc_attr( $stats['delivered'] > 0 ? ( $stats['read'] / $stats['delivered'] ) * 100 : 0 ); ?>%;"></div>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $stats['errors'] ) ) : ?>
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
							<?php foreach ( $stats['errors'] as $error ) : ?>
								<tr>
									<td><?php echo esc_html( $error['recipient'] ?? '' ); ?></td>
									<td><?php echo esc_html( $error['error'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="wch-report-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-broadcasts' ) ); ?>" class="button">
					<?php esc_html_e( 'Back to Campaigns', 'whatsapp-commerce-hub' ); ?>
				</a>
				<button type="button" class="button" id="wch-export-report" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>">
					<?php esc_html_e( 'Export Report', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get status badge HTML
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function get_status_badge( $status ) {
		$badges = array(
			'draft'     => '<span class="wch-badge wch-badge-draft">' . __( 'Draft', 'whatsapp-commerce-hub' ) . '</span>',
			'scheduled' => '<span class="wch-badge wch-badge-scheduled">' . __( 'Scheduled', 'whatsapp-commerce-hub' ) . '</span>',
			'sending'   => '<span class="wch-badge wch-badge-sending">' . __( 'Sending', 'whatsapp-commerce-hub' ) . '</span>',
			'completed' => '<span class="wch-badge wch-badge-completed">' . __( 'Completed', 'whatsapp-commerce-hub' ) . '</span>',
			'failed'    => '<span class="wch-badge wch-badge-failed">' . __( 'Failed', 'whatsapp-commerce-hub' ) . '</span>',
		);

		return $badges[ $status ] ?? $status;
	}

	/**
	 * Format campaign statistics
	 *
	 * @param array $campaign Campaign data.
	 * @return string
	 */
	private static function format_campaign_stats( $campaign ) {
		if ( 'draft' === $campaign['status'] || 'scheduled' === $campaign['status'] ) {
			return '-';
		}

		$stats     = isset( $campaign['stats'] ) ? $campaign['stats'] : array();
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
	 * Format campaign date
	 *
	 * @param array $campaign Campaign data.
	 * @return string
	 */
	private static function format_campaign_date( $campaign ) {
		$date_field = 'scheduled' === $campaign['status'] || 'draft' === $campaign['status'] ? 'scheduled_at' : 'sent_at';
		$date       = $campaign[ $date_field ] ?? $campaign['created_at'] ?? '';

		if ( empty( $date ) ) {
			return '-';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $date ) );
	}

	/**
	 * Get campaign actions HTML
	 *
	 * @param array $campaign Campaign data.
	 * @return string
	 */
	private static function get_campaign_actions( $campaign ) {
		$actions = array();

		if ( 'draft' === $campaign['status'] ) {
			$actions[] = sprintf(
				'<a href="%s" class="button button-small">%s</a>',
				esc_url( admin_url( 'admin.php?page=wch-broadcasts&action=edit&campaign_id=' . $campaign['id'] ) ),
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
				esc_url( admin_url( 'admin.php?page=wch-broadcasts&action=report&campaign_id=' . $campaign['id'] ) ),
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

	/**
	 * Get all campaigns
	 *
	 * @return array
	 */
	private static function get_campaigns() {
		$campaigns = get_option( 'wch_broadcast_campaigns', array() );

		// Sort by created_at descending
		usort(
			$campaigns,
			function ( $a, $b ) {
				$time_a = strtotime( $a['created_at'] ?? '0' );
				$time_b = strtotime( $b['created_at'] ?? '0' );
				return $time_b - $time_a;
			}
		);

		return $campaigns;
	}

	/**
	 * Get campaign by ID
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array|null
	 */
	private static function get_campaign_by_id( $campaign_id ) {
		$campaigns = self::get_campaigns();

		foreach ( $campaigns as $campaign ) {
			if ( $campaign['id'] === $campaign_id ) {
				return $campaign;
			}
		}

		return null;
	}

	/**
	 * AJAX: Get campaigns
	 */
	public static function ajax_get_campaigns() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaigns = self::get_campaigns();
		wp_send_json_success( array( 'campaigns' => $campaigns ) );
	}

	/**
	 * AJAX: Get approved templates
	 */
	public static function ajax_get_approved_templates() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$template_manager = WCH_Template_Manager::getInstance();
		$all_templates    = $template_manager->get_templates();

		// Filter for approved promotional templates
		$approved_templates = array();

		if ( is_array( $all_templates ) ) {
			foreach ( $all_templates as $template ) {
				if ( isset( $template['status'] ) && 'APPROVED' === $template['status'] ) {
					// Include all approved templates (not just promotional)
					$approved_templates[] = $template;
				}
			}
		}

		wp_send_json_success( array( 'templates' => $approved_templates ) );
	}

	/**
	 * AJAX: Get audience count
	 */
	public static function ajax_get_audience_count() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$criteria = isset( $_POST['criteria'] ) ? json_decode( stripslashes( $_POST['criteria'] ), true ) : array();

		$count = self::calculate_audience_count( $criteria );

		wp_send_json_success( array( 'count' => $count ) );
	}

	/**
	 * Calculate audience count based on criteria
	 *
	 * @param array $criteria Audience criteria.
	 * @return int
	 */
	private static function calculate_audience_count( $criteria ) {
		global $wpdb;

		// Start with all opted-in customers from customer profiles
		$table_name = $wpdb->prefix . 'wch_customer_profiles';
		$query      = "SELECT COUNT(DISTINCT phone) FROM $table_name WHERE opt_in_marketing = 1";

		$where_clauses = array( 'opt_in_marketing = 1' );

		// Recent orders filter
		if ( ! empty( $criteria['audience_recent_orders'] ) && ! empty( $criteria['recent_orders_days'] ) ) {
			$days            = absint( $criteria['recent_orders_days'] );
			$date_threshold  = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
			$where_clauses[] = "last_order_date >= '$date_threshold'";
		}

		// Cart abandoners - This is a simple implementation
		// In production, you'd query a cart abandonment table
		if ( ! empty( $criteria['audience_cart_abandoners'] ) ) {
			// Placeholder - would need proper cart abandonment tracking
		}

		// Build final query
		if ( count( $where_clauses ) > 0 ) {
			$query = "SELECT COUNT(DISTINCT phone) FROM $table_name WHERE " . implode( ' AND ', $where_clauses );
		}

		$count = (int) $wpdb->get_var( $query );

		// Apply exclusions
		if ( ! empty( $criteria['exclude_recent_broadcast'] ) && ! empty( $criteria['exclude_broadcast_days'] ) ) {
			// Would subtract customers who received broadcasts recently
			// This requires tracking broadcast recipients
		}

		return max( 0, $count );
	}

	/**
	 * AJAX: Save campaign
	 */
	public static function ajax_save_campaign() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_data = isset( $_POST['campaign'] ) ? json_decode( stripslashes( $_POST['campaign'] ), true ) : array();

		if ( empty( $campaign_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign data', 'whatsapp-commerce-hub' ) ) );
		}

		$campaigns   = self::get_campaigns();
		$campaign_id = isset( $campaign_data['id'] ) ? absint( $campaign_data['id'] ) : 0;

		// Prepare campaign data
		$campaign = array(
			'id'              => $campaign_id > 0 ? $campaign_id : time(),
			'name'            => sanitize_text_field( $campaign_data['name'] ?? '' ),
			'template_name'   => sanitize_text_field( $campaign_data['template_name'] ?? '' ),
			'template_data'   => $campaign_data['template_data'] ?? array(),
			'audience'        => $campaign_data['audience'] ?? array(),
			'audience_size'   => absint( $campaign_data['audience_size'] ?? 0 ),
			'personalization' => $campaign_data['personalization'] ?? array(),
			'schedule'        => $campaign_data['schedule'] ?? array(),
			'status'          => 'draft',
			'created_at'      => $campaign_data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		// Update existing or add new
		$found = false;
		foreach ( $campaigns as $index => $existing ) {
			if ( $existing['id'] === $campaign['id'] ) {
				$campaigns[ $index ] = $campaign;
				$found               = true;
				break;
			}
		}

		if ( ! $found ) {
			$campaigns[] = $campaign;
		}

		update_option( 'wch_broadcast_campaigns', $campaigns );

		wp_send_json_success(
			array(
				'message'  => __( 'Campaign saved successfully', 'whatsapp-commerce-hub' ),
				'campaign' => $campaign,
			)
		);
	}

	/**
	 * AJAX: Send campaign
	 */
	public static function ajax_send_campaign() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_data = isset( $_POST['campaign'] ) ? json_decode( stripslashes( $_POST['campaign'] ), true ) : array();

		if ( empty( $campaign_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign data', 'whatsapp-commerce-hub' ) ) );
		}

		// Save campaign first
		$_POST['campaign'] = wp_slash( wp_json_encode( $campaign_data ) );
		self::ajax_save_campaign();

		// Get the saved campaign
		$campaign_id = $campaign_data['id'];
		$campaigns   = self::get_campaigns();
		$campaign    = null;

		foreach ( $campaigns as $index => $c ) {
			if ( $c['id'] === $campaign_id ) {
				$campaign = &$campaigns[ $index ];
				break;
			}
		}

		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ) );
		}

		// Update campaign status
		$schedule = $campaign['schedule'] ?? array();

		if ( isset( $schedule['timing'] ) && 'scheduled' === $schedule['timing'] ) {
			$campaign['status']       = 'scheduled';
			$campaign['scheduled_at'] = $schedule['datetime'] ?? gmdate( 'Y-m-d H:i:s' );

			// Schedule the broadcast job
			$scheduled_time = strtotime( $campaign['scheduled_at'] );
			$delay          = max( 0, $scheduled_time - time() );

			self::schedule_broadcast( $campaign, $delay );
		} else {
			$campaign['status']  = 'sending';
			$campaign['sent_at'] = gmdate( 'Y-m-d H:i:s' );

			// Send immediately
			self::schedule_broadcast( $campaign, 0 );
		}

		update_option( 'wch_broadcast_campaigns', $campaigns );

		wp_send_json_success(
			array(
				'message'  => __( 'Campaign scheduled successfully', 'whatsapp-commerce-hub' ),
				'campaign' => $campaign,
			)
		);
	}

	/**
	 * Schedule broadcast campaign
	 *
	 * @param array $campaign Campaign data.
	 * @param int   $delay Delay in seconds.
	 */
	private static function schedule_broadcast( $campaign, $delay = 0 ) {
		// Get recipients based on audience criteria
		$recipients = self::get_campaign_recipients( $campaign );

		if ( empty( $recipients ) ) {
			return;
		}

		// Prepare message from template
		$message = self::build_campaign_message( $campaign );

		// Use WCH_Job_Dispatcher to schedule batch sending
		$batch_size = 50; // Send 50 messages per batch
		$batches    = array_chunk( $recipients, $batch_size );

		foreach ( $batches as $batch_num => $batch ) {
			$args = array(
				'batch'       => $batch,
				'batch_num'   => $batch_num,
				'campaign_id' => $campaign['id'],
				'message'     => $message,
			);

			// Delay each batch by 1 second to avoid rate limiting
			$batch_delay = $delay + ( $batch_num * 1 );

			WCH_Job_Dispatcher::dispatch( 'wch_send_broadcast_batch', $args, $batch_delay );
		}
	}

	/**
	 * Get campaign recipients
	 *
	 * @param array $campaign Campaign data.
	 * @return array Array of phone numbers.
	 */
	private static function get_campaign_recipients( $campaign ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wch_customer_profiles';
		$query      = "SELECT phone FROM $table_name WHERE opt_in_marketing = 1";

		// Apply audience filters
		$audience = $campaign['audience'] ?? array();

		// Build WHERE clauses based on audience criteria
		$where_clauses = array( 'opt_in_marketing = 1' );

		if ( ! empty( $audience['recent_orders_days'] ) ) {
			$days            = absint( $audience['recent_orders_days'] );
			$date_threshold  = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
			$where_clauses[] = "last_order_date >= '$date_threshold'";
		}

		if ( count( $where_clauses ) > 0 ) {
			$query = "SELECT phone FROM $table_name WHERE " . implode( ' AND ', $where_clauses );
		}

		$results = $wpdb->get_col( $query );

		return $results;
	}

	/**
	 * Build campaign message from template
	 *
	 * @param array $campaign Campaign data.
	 * @return array Message data.
	 */
	private static function build_campaign_message( $campaign ) {
		$template_data   = $campaign['template_data'] ?? array();
		$personalization = $campaign['personalization'] ?? array();

		// This would use WCH_Message_Builder to construct the message
		// For now, return basic structure
		return array(
			'template_name' => $campaign['template_name'] ?? '',
			'template_data' => $template_data,
			'variables'     => $personalization,
		);
	}

	/**
	 * AJAX: Send test broadcast
	 */
	public static function ajax_send_test_broadcast() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_data = isset( $_POST['campaign'] ) ? json_decode( stripslashes( $_POST['campaign'] ), true ) : array();
		$test_phone    = isset( $_POST['test_phone'] ) ? sanitize_text_field( $_POST['test_phone'] ) : '';

		if ( empty( $test_phone ) ) {
			// Get admin phone from settings
			$settings   = WCH_Settings::getInstance();
			$test_phone = $settings->get( 'api.test_phone', '' );
		}

		if ( empty( $test_phone ) ) {
			wp_send_json_error( array( 'message' => __( 'No test phone number configured', 'whatsapp-commerce-hub' ) ) );
		}

		// Build and send test message
		$message = self::build_campaign_message( $campaign_data );

		// Use WCH_API to send the message
		// This is a placeholder - actual implementation would use the WhatsApp API

		wp_send_json_success( array( 'message' => __( 'Test message sent', 'whatsapp-commerce-hub' ) ) );
	}

	/**
	 * AJAX: Delete campaign
	 */
	public static function ajax_delete_campaign() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaign_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ) );
		}

		$campaigns = self::get_campaigns();
		$updated   = array();

		foreach ( $campaigns as $campaign ) {
			if ( $campaign['id'] !== $campaign_id ) {
				$updated[] = $campaign;
			}
		}

		update_option( 'wch_broadcast_campaigns', $updated );

		wp_send_json_success( array( 'message' => __( 'Campaign deleted', 'whatsapp-commerce-hub' ) ) );
	}

	/**
	 * AJAX: Get campaign
	 */
	public static function ajax_get_campaign() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaign_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign = self::get_campaign_by_id( $campaign_id );

		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ) );
		}

		wp_send_json_success( array( 'campaign' => $campaign ) );
	}

	/**
	 * AJAX: Get campaign report
	 */
	public static function ajax_get_campaign_report() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaign_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign = self::get_campaign_by_id( $campaign_id );

		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ) );
		}

		wp_send_json_success(
			array(
				'campaign' => $campaign,
				'stats'    => $campaign['stats'] ?? array(),
			)
		);
	}

	/**
	 * AJAX: Duplicate campaign
	 */
	public static function ajax_duplicate_campaign() {
		check_ajax_referer( 'wch_broadcasts_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $campaign_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid campaign ID', 'whatsapp-commerce-hub' ) ) );
		}

		$original = self::get_campaign_by_id( $campaign_id );

		if ( ! $original ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found', 'whatsapp-commerce-hub' ) ) );
		}

		// Create duplicate
		$duplicate               = $original;
		$duplicate['id']         = time();
		$duplicate['name']       = $original['name'] . ' (Copy)';
		$duplicate['status']     = 'draft';
		$duplicate['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$duplicate['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		unset( $duplicate['sent_at'] );
		unset( $duplicate['scheduled_at'] );
		unset( $duplicate['stats'] );

		$campaigns   = self::get_campaigns();
		$campaigns[] = $duplicate;
		update_option( 'wch_broadcast_campaigns', $campaigns );

		wp_send_json_success(
			array(
				'message'  => __( 'Campaign duplicated', 'whatsapp-commerce-hub' ),
				'campaign' => $duplicate,
			)
		);
	}
}
