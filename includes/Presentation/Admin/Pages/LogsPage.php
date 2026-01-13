<?php
/**
 * Admin Logs Page
 *
 * Provides admin UI for viewing WCH logs.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

use WhatsAppCommerceHub\Core\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.MaxExceeded
// Long lines in inline CSS styles are acceptable for readability.

/**
 * Class LogsPage
 *
 * Handles the admin interface for viewing WCH logs.
 */
class LogsPage {

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private string $pageHook = '';

	/**
	 * Initialize the admin logs viewer.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'addMenuItem' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_action( 'wp_ajax_wch_delete_old_logs', [ $this, 'ajaxDeleteOldLogs' ] );
	}

	/**
	 * Add menu item under WooCommerce.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		$this->pageHook = add_submenu_page(
			'woocommerce',
			__( 'WCH Logs', 'whatsapp-commerce-hub' ),
			__( 'WCH Logs', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-logs',
			[ $this, 'renderPage' ]
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_wch-logs' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wch-admin-logs', false, [], WCH_VERSION );
		wp_add_inline_style( 'wch-admin-logs', $this->getInlineStyles() );
	}

	/**
	 * Get inline CSS styles.
	 *
	 * @return string
	 */
	private function getInlineStyles(): string {
		return '
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
		';
	}

	/**
	 * Render the logs page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$logger   = wch( Logger::class );
		$logFiles = $this->normalizeLogFiles( $logger->getLogFiles() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only log file selection.
		$currentLog = isset( $_GET['log'] ) ? sanitize_file_name( wp_unslash( $_GET['log'] ) ) : '';

		if ( empty( $currentLog ) && ! empty( $logFiles ) ) {
			$currentLog = $logFiles[0]['name'];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only log level filter.
		$logLevel   = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
		$logEntries = [];

		if ( ! empty( $currentLog ) ) {
			$logContent = $logger->readLog( $currentLog, 0 );
			$logEntries = $this->parseLogEntries( $logContent, $logLevel );
		}

		$totalFiles = count( $logFiles );
		$totalSize  = array_sum( array_column( $logFiles, 'size' ) );

		$this->renderPageHtml( $logFiles, $currentLog, $logLevel, $logEntries, $totalFiles, $totalSize );
	}

	/**
	 * Render the page HTML.
	 *
	 * @param array  $logFiles    Log files.
	 * @param string $currentLog  Current log file.
	 * @param string $logLevel    Log level filter.
	 * @param array  $logEntries  Log entries.
	 * @param int    $totalFiles  Total files count.
	 * @param int    $totalSize   Total size.
	 * @return void
	 */
	private function renderPageHtml( array $logFiles, string $currentLog, string $logLevel, array $logEntries, int $totalFiles, int $totalSize ): void {
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
					<div class="wch-log-stat-value"><?php echo esc_html( $totalFiles ); ?></div>
					<div class="wch-log-stat-label"><?php esc_html_e( 'Log Files', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-log-stat">
					<div class="wch-log-stat-value"><?php echo esc_html( size_format( $totalSize ) ); ?></div>
					<div class="wch-log-stat-label"><?php esc_html_e( 'Total Size', 'whatsapp-commerce-hub' ); ?></div>
				</div>
				<div class="wch-log-stat">
					<div class="wch-log-stat-value"><?php echo esc_html( count( $logEntries ) ); ?></div>
					<div class="wch-log-stat-label"><?php esc_html_e( 'Entries Shown', 'whatsapp-commerce-hub' ); ?></div>
				</div>
			</div>

			<div class="wch-logs-controls">
				<label for="wch-log-file"><?php esc_html_e( 'Log File:', 'whatsapp-commerce-hub' ); ?></label>
				<select id="wch-log-file" name="log">
					<option value=""><?php esc_html_e( '-- Select Log File --', 'whatsapp-commerce-hub' ); ?></option>
					<?php foreach ( $logFiles as $file ) : ?>
						<option value="<?php echo esc_attr( $file['name'] ); ?>" <?php selected( $currentLog, $file['name'] ); ?>>
							<?php echo esc_html( sprintf( '%s (%s - %s)', $file['name'], size_format( $file['size'] ), gmdate( 'Y-m-d H:i:s', $file['modified'] ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="wch-log-level"><?php esc_html_e( 'Level:', 'whatsapp-commerce-hub' ); ?></label>
				<select id="wch-log-level" name="level">
					<option value=""><?php esc_html_e( 'All Levels', 'whatsapp-commerce-hub' ); ?></option>
					<option value="debug" <?php selected( $logLevel, 'debug' ); ?>><?php esc_html_e( 'Debug', 'whatsapp-commerce-hub' ); ?></option>
					<option value="info" <?php selected( $logLevel, 'info' ); ?>><?php esc_html_e( 'Info', 'whatsapp-commerce-hub' ); ?></option>
					<option value="warning" <?php selected( $logLevel, 'warning' ); ?>><?php esc_html_e( 'Warning', 'whatsapp-commerce-hub' ); ?></option>
					<option value="error" <?php selected( $logLevel, 'error' ); ?>><?php esc_html_e( 'Error', 'whatsapp-commerce-hub' ); ?></option>
					<option value="critical" <?php selected( $logLevel, 'critical' ); ?>><?php esc_html_e( 'Critical', 'whatsapp-commerce-hub' ); ?></option>
				</select>

				<button type="button" class="button button-secondary" id="wch-refresh-logs">
					<?php esc_html_e( 'Refresh', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>

			<div class="wch-log-viewer">
				<?php $this->renderLogEntries( $logFiles, $currentLog, $logEntries ); ?>
			</div>
		</div>

		<?php $this->renderInlineScript(); ?>
		<?php
	}

	/**
	 * Render log entries.
	 *
	 * @param array  $logFiles   Log files.
	 * @param string $currentLog Current log file.
	 * @param array  $logEntries Log entries.
	 * @return void
	 */
	private function renderLogEntries( array $logFiles, string $currentLog, array $logEntries ): void {
		if ( empty( $logEntries ) ) {
			$message = empty( $logFiles )
				? __( 'No log files found.', 'whatsapp-commerce-hub' )
				: ( empty( $currentLog )
					? __( 'Please select a log file to view.', 'whatsapp-commerce-hub' )
					: __( 'No log entries found matching the selected filters.', 'whatsapp-commerce-hub' ) );
			?>
			<div class="wch-no-logs"><?php echo esc_html( $message ); ?></div>
			<?php
			return;
		}

		foreach ( $logEntries as $entry ) {
			$entryLevel = 'info';
			if ( preg_match( '/\[([A-Z]+)\]/', $entry, $matches ) ) {
				$entryLevel = strtolower( $matches[1] );
			}
			?>
			<div class="wch-log-entry level-<?php echo esc_attr( $entryLevel ); ?>">
				<?php echo esc_html( $entry ); ?>
			</div>
			<?php
		}
	}

	/**
	 * Render inline JavaScript.
	 *
	 * @return void
	 */
	private function renderInlineScript(): void {
		$adminUrl = esc_url( admin_url( 'admin.php?page=wch-logs' ) );
		$nonce    = esc_js( wp_create_nonce( 'wch-delete-old-logs' ) );
		?>
		<script>
		jQuery(document).ready(function($) {
			$('#wch-log-file, #wch-log-level').on('change', function() {
				var log = $('#wch-log-file').val();
				var level = $('#wch-log-level').val();
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied above. ?>
				var url = '<?php echo $adminUrl; ?>';
				if (log) url += '&log=' + encodeURIComponent(log);
				if (level) url += '&level=' + encodeURIComponent(level);
				window.location.href = url;
			});

			$('#wch-refresh-logs').on('click', function() { location.reload(); });

			$('#wch-delete-old-logs').on('click', function() {
				if (!confirm('<?php esc_html_e( 'Are you sure you want to delete log files older than 30 days?', 'whatsapp-commerce-hub' ); ?>')) return;

				var button = $(this);
				button.prop('disabled', true).text('<?php esc_html_e( 'Deleting...', 'whatsapp-commerce-hub' ); ?>');

				$.post(ajaxurl, {
					action: 'wch_delete_old_logs',
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js() applied above. ?>
					nonce: '<?php echo $nonce; ?>'
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
	 *
	 * @return void
	 */
	public function ajaxDeleteOldLogs(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wch-delete-old-logs' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'whatsapp-commerce-hub' ) ] );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'whatsapp-commerce-hub' ) ] );
		}

		$deleted = $this->deleteOldLogs( 30 );

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: number of files deleted */
					_n( 'Deleted %d log file.', 'Deleted %d log files.', $deleted, 'whatsapp-commerce-hub' ),
					$deleted
				),
			]
		);
	}

	/**
	 * Normalize log file metadata for UI.
	 *
	 * @param array<int, array{filename: string, size: int, modified: int}> $files Log files.
	 * @return array<int, array{name: string, size: int, modified: int}> Normalized files.
	 */
	private function normalizeLogFiles( array $files ): array {
		return array_map(
			static fn( array $file ): array => [
				'name'     => $file['filename'] ?? '',
				'size'     => (int) ( $file['size'] ?? 0 ),
				'modified' => (int) ( $file['modified'] ?? 0 ),
			],
			$files
		);
	}

	/**
	 * Parse log content into filtered entries.
	 *
	 * @param string $content  Log file content.
	 * @param string $logLevel Optional log level filter.
	 * @return array<int, string> Log entries.
	 */
	private function parseLogEntries( string $content, string $logLevel ): array {
		if ( '' === $content ) {
			return [];
		}

		$lines = preg_split( "/\r\n|\n|\r/", $content );
		if ( ! $lines ) {
			return [];
		}

		$lines = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== trim( $line ) ) );

		if ( '' === $logLevel ) {
			return $lines;
		}

		$levelTag = '[' . strtoupper( $logLevel ) . ']';

		return array_values(
			array_filter(
				$lines,
				static fn( string $line ): bool => str_contains( $line, $levelTag )
			)
		);
	}

	/**
	 * Delete log files older than the given number of days.
	 *
	 * @param int $days Days threshold.
	 * @return int Number of deleted files.
	 */
	private function deleteOldLogs( int $days ): int {
		$logger  = wch( Logger::class );
		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$deleted = 0;

		foreach ( $logger->getLogFiles() as $file ) {
			$modified = (int) ( $file['modified'] ?? 0 );
			if ( $modified > 0 && $modified < $cutoff ) {
				if ( $logger->deleteLog( $file['filename'] ?? '' ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;
	}
}
