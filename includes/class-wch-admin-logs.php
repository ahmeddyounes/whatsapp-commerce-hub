<?php
/**
 * Admin logs viewer for WhatsApp Commerce Hub.
 *
 * Adds a log viewer page under WooCommerce > Status > Logs.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Admin_Logs
 *
 * Handles the admin interface for viewing WCH logs.
 */
class WCH_Admin_Logs {
	/**
	 * Initialize the admin logs viewer.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wch_delete_old_logs', array( __CLASS__, 'ajax_delete_old_logs' ) );
	}

	/**
	 * Add menu item under WooCommerce > Status.
	 */
	public static function add_menu_item() {
		add_submenu_page(
			'woocommerce',
			__( 'WCH Logs', 'whatsapp-commerce-hub' ),
			__( 'WCH Logs', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-logs',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wch-logs' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wch-admin-logs', false, array(), WCH_VERSION );
		wp_add_inline_style(
			'wch-admin-logs',
			'
			.wch-logs-page { margin: 20px 20px 20px 0; }
			.wch-logs-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
			.wch-logs-controls { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; background: #fff; padding: 15px; border: 1px solid #ccd0d4; }
			.wch-logs-controls label { font-weight: 600; margin-right: 5px; }
			.wch-logs-controls select { margin-right: 15px; }
			.wch-log-viewer { background: #fff; border: 1px solid #ccd0d4; padding: 20px; }
			.wch-log-entry { font-family: monospace; font-size: 12px; padding: 8px; border-bottom: 1px solid #f0f0f1; white-space: pre-wrap; word-break: break-all; }
			.wch-log-entry:hover { background: #f6f7f7; }
			.wch-log-entry.level-debug { color: #888; }
			.wch-log-entry.level-info { color: #2271b1; }
			.wch-log-entry.level-warning { color: #dba617; }
			.wch-log-entry.level-error { color: #d63638; }
			.wch-log-entry.level-critical { color: #d63638; font-weight: 600; background: #fcf0f1; }
			.wch-no-logs { padding: 40px; text-align: center; color: #666; }
			.wch-log-stats { display: flex; gap: 20px; margin-bottom: 20px; }
			.wch-log-stat { background: #fff; padding: 15px; border: 1px solid #ccd0d4; flex: 1; }
			.wch-log-stat-value { font-size: 24px; font-weight: 600; color: #2271b1; }
			.wch-log-stat-label { color: #666; font-size: 13px; }
			'
		);
	}

	/**
	 * Render the logs page.
	 */
	public static function render_page() {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub' ) );
		}

		// Get log files.
		$log_files = WCH_Logger::get_log_files();

		// Get current log file from request.
		$current_log = isset( $_GET['log'] ) ? sanitize_file_name( wp_unslash( $_GET['log'] ) ) : '';

		// If no log selected and files exist, select the most recent.
		if ( empty( $current_log ) && ! empty( $log_files ) ) {
			$current_log = $log_files[0]['name'];
		}

		// Get log level filter.
		$log_level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';

		// Read log entries.
		$log_entries = array();
		if ( ! empty( $current_log ) ) {
			$log_entries = WCH_Logger::read_log( $current_log, $log_level ? strtoupper( $log_level ) : null );
		}

		// Calculate stats.
		$total_files = count( $log_files );
		$total_size  = 0;
		foreach ( $log_files as $file ) {
			$total_size += $file['size'];
		}

		?>
		<div class="wrap wch-logs-page">
			<div class="wch-logs-header">
				<h1><?php esc_html_e( 'WhatsApp Commerce Hub - Logs', 'whatsapp-commerce-hub' ); ?></h1>
				<button type="button" class="button button-secondary" id="wch-delete-old-logs">
					<?php esc_html_e( 'Delete Old Logs (30+ days)', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>

			<div class="wch-log-stats">
				<div class="wch-log-stat">
					<div class="wch-log-stat-value"><?php echo esc_html( $total_files ); ?></div>
					<div class="wch-log-stat-label"><?php esc_html_e( 'Log Files', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-log-stat">
					<div class="wch-log-stat-value"><?php echo esc_html( size_format( $total_size ) ); ?></div>
					<div class="wch-log-stat-label"><?php esc_html_e( 'Total Size', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-log-stat">
					<div class="wch-log-stat-value"><?php echo esc_html( count( $log_entries ) ); ?></div>
					<div class="wch-log-stat-label"><?php esc_html_e( 'Entries Shown', 'whatsapp-commerce-hub' ); ?></div>
				</div>
			</div>

			<div class="wch-logs-controls">
				<label for="wch-log-file"><?php esc_html_e( 'Log File:', 'whatsapp-commerce-hub' ); ?></label>
				<select id="wch-log-file" name="log">
					<option value=""><?php esc_html_e( '-- Select Log File --', 'whatsapp-commerce-hub' ); ?></option>
					<?php foreach ( $log_files as $file ) : ?>
						<option value="<?php echo esc_attr( $file['name'] ); ?>" <?php selected( $current_log, $file['name'] ); ?>>
							<?php
							echo esc_html(
								sprintf(
									'%s (%s - %s)',
									$file['name'],
									size_format( $file['size'] ),
									gmdate( 'Y-m-d H:i:s', $file['modified'] )
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="wch-log-level"><?php esc_html_e( 'Level:', 'whatsapp-commerce-hub' ); ?></label>
				<select id="wch-log-level" name="level">
					<option value=""><?php esc_html_e( 'All Levels', 'whatsapp-commerce-hub' ); ?></option>
					<option value="debug" <?php selected( $log_level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'whatsapp-commerce-hub' ); ?></option>
					<option value="info" <?php selected( $log_level, 'info' ); ?>><?php esc_html_e( 'Info', 'whatsapp-commerce-hub' ); ?></option>
					<option value="warning" <?php selected( $log_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'whatsapp-commerce-hub' ); ?></option>
					<option value="error" <?php selected( $log_level, 'error' ); ?>><?php esc_html_e( 'Error', 'whatsapp-commerce-hub' ); ?></option>
					<option value="critical" <?php selected( $log_level, 'critical' ); ?>><?php esc_html_e( 'Critical', 'whatsapp-commerce-hub' ); ?></option>
				</select>

				<button type="button" class="button button-secondary" id="wch-refresh-logs">
					<?php esc_html_e( 'Refresh', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>

			<div class="wch-log-viewer">
				<?php if ( empty( $log_entries ) ) : ?>
					<div class="wch-no-logs">
						<?php
						if ( empty( $log_files ) ) {
							esc_html_e( 'No log files found.', 'whatsapp-commerce-hub' );
						} elseif ( empty( $current_log ) ) {
							esc_html_e( 'Please select a log file to view.', 'whatsapp-commerce-hub' );
						} else {
							esc_html_e( 'No log entries found matching the selected filters.', 'whatsapp-commerce-hub' );
						}
						?>
					</div>
				<?php else : ?>
					<?php foreach ( $log_entries as $entry ) : ?>
						<?php
						// Determine log level from entry.
						$entry_level = 'info';
						if ( preg_match( '/\[([A-Z]+)\]/', $entry, $matches ) ) {
							$entry_level = strtolower( $matches[1] );
						}
						?>
						<div class="wch-log-entry level-<?php echo esc_attr( $entry_level ); ?>">
							<?php echo esc_html( $entry ); ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Handle log file and level change.
			$('#wch-log-file, #wch-log-level').on('change', function() {
				var log = $('#wch-log-file').val();
				var level = $('#wch-log-level').val();
				var url = '<?php echo esc_url( admin_url( 'admin.php?page=wch-logs' ) ); ?>';
				if (log) url += '&log=' + encodeURIComponent(log);
				if (level) url += '&level=' + encodeURIComponent(level);
				window.location.href = url;
			});

			// Handle refresh button.
			$('#wch-refresh-logs').on('click', function() {
				location.reload();
			});

			// Handle delete old logs button.
			$('#wch-delete-old-logs').on('click', function() {
				if (!confirm('<?php esc_html_e( 'Are you sure you want to delete log files older than 30 days?', 'whatsapp-commerce-hub' ); ?>')) {
					return;
				}

				var button = $(this);
				button.prop('disabled', true).text('<?php esc_html_e( 'Deleting...', 'whatsapp-commerce-hub' ); ?>');

				$.post(ajaxurl, {
					action: 'wch_delete_old_logs',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wch-delete-old-logs' ) ); ?>'
				}, function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert(response.data.message || '<?php esc_html_e( 'Failed to delete old logs.', 'whatsapp-commerce-hub' ); ?>');
						button.prop('disabled', false).text('<?php esc_html_e( 'Delete Old Logs (30+ days)', 'whatsapp-commerce-hub' ); ?>');
					}
				}).fail(function() {
					alert('<?php esc_html_e( 'Failed to delete old logs.', 'whatsapp-commerce-hub' ); ?>');
					button.prop('disabled', false).text('<?php esc_html_e( 'Delete Old Logs (30+ days)', 'whatsapp-commerce-hub' ); ?>');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler to delete old logs.
	 */
	public static function ajax_delete_old_logs() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wch-delete-old-logs' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'whatsapp-commerce-hub' ) ) );
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'whatsapp-commerce-hub' ) ) );
		}

		// Delete old logs.
		$deleted = WCH_Logger::delete_old_logs( 30 );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of files deleted */
					_n( 'Deleted %d log file.', 'Deleted %d log files.', $deleted, 'whatsapp-commerce-hub' ),
					$deleted
				),
			)
		);
	}
}
