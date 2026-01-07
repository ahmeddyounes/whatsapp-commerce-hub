<?php
/**
 * Admin Templates Page
 *
 * Displays and manages WhatsApp message templates in WordPress admin.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCH_Admin_Templates
 *
 * Provides admin UI for viewing and managing message templates.
 */
class WCH_Admin_Templates {

	/**
	 * Initialize admin templates page
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ), 55 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_post_wch_sync_templates', array( __CLASS__, 'handle_sync_templates' ) );
		add_action( 'wp_ajax_wch_preview_template', array( __CLASS__, 'ajax_preview_template' ) );
	}

	/**
	 * Add admin menu item
	 */
	public static function add_menu_item() {
		add_submenu_page(
			'woocommerce',
			__( 'Message Templates', 'whatsapp-commerce-hub' ),
			__( 'WCH Templates', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-templates',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wch-templates' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-templates',
			WCH_PLUGIN_URL . 'assets/css/admin-templates.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-templates',
			WCH_PLUGIN_URL . 'assets/js/admin-templates.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-templates',
			'wchTemplates',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wch_templates_nonce' ),
			)
		);
	}

	/**
	 * Handle template sync action
	 */
	public static function handle_sync_templates() {
		// Verify nonce
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wch_sync_templates' ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'whatsapp-commerce-hub' ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied', 'whatsapp-commerce-hub' ) );
		}

		try {
			$template_manager = WCH_Template_Manager::getInstance();
			$templates        = $template_manager->sync_templates();

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'   => 'wch-templates',
						'synced' => count( $templates ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		} catch ( Exception $e ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'wch-templates',
						'error' => urlencode( $e->getMessage() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * AJAX handler for template preview
	 */
	public static function ajax_preview_template() {
		check_ajax_referer( 'wch_templates_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$template_name = sanitize_text_field( $_POST['template_name'] ?? '' );
		$variables     = isset( $_POST['variables'] ) ? array_map( 'sanitize_text_field', $_POST['variables'] ) : array();

		if ( empty( $template_name ) ) {
			wp_send_json_error( array( 'message' => 'Template name required' ) );
		}

		try {
			$template_manager = WCH_Template_Manager::getInstance();
			$rendered         = $template_manager->render_template( $template_name, $variables );

			wp_send_json_success( array( 'template' => $rendered ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Render admin page
	 */
	public static function render_page() {
		$template_manager = WCH_Template_Manager::getInstance();
		$templates        = $template_manager->get_templates();
		$last_sync        = $template_manager->get_last_sync_time();
		$usage_stats      = $template_manager->get_all_usage_stats();

		// Convert usage stats to keyed array
		$stats_by_name = array();
		foreach ( $usage_stats as $stat ) {
			$stats_by_name[ $stat['template_name'] ] = $stat;
		}

		// Group templates by category
		$templates_by_category = array();
		foreach ( $templates as $template ) {
			$category = $template['mapped_category'] ?? $template['category'] ?? 'other';
			if ( ! isset( $templates_by_category[ $category ] ) ) {
				$templates_by_category[ $category ] = array();
			}
			$templates_by_category[ $category ][] = $template;
		}

		?>
		<div class="wrap wch-templates-page">
			<h1><?php esc_html_e( 'WhatsApp Message Templates', 'whatsapp-commerce-hub' ); ?></h1>

			<?php self::render_notices(); ?>

			<div class="wch-templates-header">
				<div class="wch-sync-info">
					<?php if ( $last_sync ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: time ago */
								esc_html__( 'Last synced: %s', 'whatsapp-commerce-hub' ),
								esc_html( human_time_diff( $last_sync, time() ) . ' ago' )
							);
							?>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Templates not yet synced', 'whatsapp-commerce-hub' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wch-sync-action">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wch_sync_templates' ); ?>
						<input type="hidden" name="action" value="wch_sync_templates">
						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Sync Templates', 'whatsapp-commerce-hub' ); ?>
						</button>
					</form>
				</div>
			</div>

			<?php if ( empty( $templates ) ) : ?>
				<div class="wch-no-templates">
					<p><?php esc_html_e( 'No templates found. Click "Sync Templates" to fetch templates from WhatsApp.', 'whatsapp-commerce-hub' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wch-templates-summary">
					<p>
						<?php
						printf(
							/* translators: %d: number of templates */
							esc_html( _n( '%d template available', '%d templates available', count( $templates ), 'whatsapp-commerce-hub' ) ),
							count( $templates )
						);
						?>
					</p>
				</div>

				<?php foreach ( $templates_by_category as $category => $category_templates ) : ?>
					<div class="wch-template-category">
						<h2><?php echo esc_html( ucwords( str_replace( '_', ' ', $category ) ) ); ?></h2>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'whatsapp-commerce-hub' ); ?></th>
									<th><?php esc_html_e( 'Language', 'whatsapp-commerce-hub' ); ?></th>
									<th><?php esc_html_e( 'Status', 'whatsapp-commerce-hub' ); ?></th>
									<th><?php esc_html_e( 'Usage', 'whatsapp-commerce-hub' ); ?></th>
									<th><?php esc_html_e( 'Preview', 'whatsapp-commerce-hub' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $category_templates as $template ) : ?>
									<?php
									$template_stats = $stats_by_name[ $template['name'] ] ?? null;
									$usage_count    = $template_stats ? $template_stats['usage_count'] : 0;
									?>
									<tr>
										<td>
											<strong><?php echo esc_html( $template['name'] ); ?></strong>
										</td>
										<td>
											<?php echo esc_html( $template['language'] ); ?>
										</td>
										<td>
											<?php
											$status       = strtolower( $template['status'] );
											$status_class = 'wch-status-' . $status;
											?>
											<span class="wch-template-status <?php echo esc_attr( $status_class ); ?>">
												<?php echo esc_html( ucfirst( $status ) ); ?>
											</span>
										</td>
										<td>
											<?php
											if ( $usage_count > 0 ) {
												printf(
													/* translators: %d: usage count */
													esc_html( _n( '%d time', '%d times', $usage_count, 'whatsapp-commerce-hub' ) ),
													$usage_count
												);
											} else {
												esc_html_e( 'Not used', 'whatsapp-commerce-hub' );
											}
											?>
										</td>
										<td>
											<button
												type="button"
												class="button wch-preview-template"
												data-template="<?php echo esc_attr( $template['name'] ); ?>"
											>
												<?php esc_html_e( 'Preview', 'whatsapp-commerce-hub' ); ?>
											</button>
										</td>
									</tr>
									<tr class="wch-template-preview-row" id="preview-<?php echo esc_attr( $template['name'] ); ?>" style="display: none;">
										<td colspan="5">
											<div class="wch-template-preview">
												<?php self::render_template_preview( $template ); ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render template preview
	 *
	 * @param array $template Template data.
	 */
	private static function render_template_preview( $template ) {
		?>
		<div class="wch-template-content">
			<?php if ( ! empty( $template['components'] ) ) : ?>
				<?php foreach ( $template['components'] as $component ) : ?>
					<?php
					$type = $component['type'] ?? '';
					$text = $component['text'] ?? '';
					?>
					<?php if ( 'HEADER' === $type ) : ?>
						<div class="wch-preview-header">
							<strong><?php echo esc_html( $text ); ?></strong>
						</div>
					<?php elseif ( 'BODY' === $type ) : ?>
						<div class="wch-preview-body">
							<?php echo nl2br( esc_html( $text ) ); ?>
						</div>
					<?php elseif ( 'FOOTER' === $type ) : ?>
						<div class="wch-preview-footer">
							<small><?php echo esc_html( $text ); ?></small>
						</div>
					<?php elseif ( 'BUTTONS' === $type && ! empty( $component['buttons'] ) ) : ?>
						<div class="wch-preview-buttons">
							<?php foreach ( $component['buttons'] as $button ) : ?>
								<button type="button" class="wch-preview-button" disabled>
									<?php echo esc_html( $button['text'] ?? '' ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No preview available', 'whatsapp-commerce-hub' ); ?></p>
			<?php endif; ?>

			<?php
			// Show variable placeholders if any
			$variables = self::extract_variables_from_template( $template );
			if ( ! empty( $variables ) ) :
				?>
				<div class="wch-preview-variables">
					<p><strong><?php esc_html_e( 'Variables:', 'whatsapp-commerce-hub' ); ?></strong></p>
					<ul>
						<?php foreach ( $variables as $var ) : ?>
							<li><code><?php echo esc_html( $var ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Extract variables from template
	 *
	 * @param array $template Template data.
	 * @return array List of variables found.
	 */
	private static function extract_variables_from_template( $template ) {
		$variables = array();

		if ( empty( $template['components'] ) ) {
			return $variables;
		}

		foreach ( $template['components'] as $component ) {
			$text = $component['text'] ?? '';
			preg_match_all( '/\{\{(\d+)\}\}/', $text, $matches );

			if ( ! empty( $matches[0] ) ) {
				$variables = array_merge( $variables, $matches[0] );
			}
		}

		return array_unique( $variables );
	}

	/**
	 * Render admin notices
	 */
	private static function render_notices() {
		if ( isset( $_GET['synced'] ) ) {
			$count = intval( $_GET['synced'] );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d: number of templates */
						esc_html( _n( '%d template synced successfully.', '%d templates synced successfully.', $count, 'whatsapp-commerce-hub' ) ),
						$count
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['error'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: error message */
						esc_html__( 'Error: %s', 'whatsapp-commerce-hub' ),
						esc_html( urldecode( $_GET['error'] ) )
					);
					?>
				</p>
			</div>
			<?php
		}
	}
}
