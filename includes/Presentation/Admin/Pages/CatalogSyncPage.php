<?php
/**
 * Admin Catalog Sync Page
 *
 * Provides admin interface for managing product catalog synchronization with WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

use WhatsAppCommerceHub\Contracts\Services\ProductSync\ProductSyncOrchestratorInterface;
use WhatsAppCommerceHub\Contracts\Services\ProductSync\SyncProgressTrackerInterface;
use WhatsAppCommerceHub\Infrastructure\Configuration\SettingsManager;
use WhatsAppCommerceHub\Infrastructure\Queue\QueueManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.MaxExceeded
// Long lines in inline CSS styles are acceptable for readability.

/**
 * Class CatalogSyncPage
 *
 * Handles the admin interface for product catalog synchronization.
 */
class CatalogSyncPage {

	/**
	 * Initialize the catalog sync page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'addMenuItem' ], 51 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		$this->registerAjaxHandlers();
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	private function registerAjaxHandlers(): void {
		$handlers = [
			'wch_get_products'           => 'ajaxGetProducts',
			'wch_bulk_sync'              => 'ajaxBulkSync',
			'wch_sync_product'           => 'ajaxSyncProduct',
			'wch_remove_from_catalog'    => 'ajaxRemoveFromCatalog',
			'wch_get_sync_history'       => 'ajaxGetSyncHistory',
			'wch_get_sync_status'        => 'ajaxGetSyncStatus',
			'wch_save_sync_settings'     => 'ajaxSaveSyncSettings',
			'wch_dry_run_sync'           => 'ajaxDryRunSync',
			'wch_retry_failed'           => 'ajaxRetryFailed',
			'wch_get_bulk_sync_progress' => 'ajaxGetBulkSyncProgress',
			'wch_retry_failed_bulk'      => 'ajaxRetryFailedBulk',
			'wch_clear_sync_progress'    => 'ajaxClearSyncProgress',
		];

		foreach ( $handlers as $action => $method ) {
			add_action( 'wp_ajax_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Add menu item under WooCommerce.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Catalog Sync', 'whatsapp-commerce-hub' ),
			__( 'Catalog Sync', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-catalog-sync',
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
		if ( 'woocommerce_page_wch-catalog-sync' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-catalog-sync',
			WCH_PLUGIN_URL . 'assets/css/admin-catalog-sync.css',
			[],
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-catalog-sync',
			WCH_PLUGIN_URL . 'assets/js/admin-catalog-sync.js',
			[ 'jquery' ],
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-catalog-sync',
			'wchCatalogSync',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wch_catalog_sync_nonce' ),
				'strings'  => $this->getLocalizedStrings(),
			]
		);
	}

	/**
	 * Get localized strings for JavaScript.
	 *
	 * @return array
	 */
	private function getLocalizedStrings(): array {
		return [
			'syncing'            => __( 'Syncing...', 'whatsapp-commerce-hub' ),
			'success'            => __( 'Success', 'whatsapp-commerce-hub' ),
			'error'              => __( 'Error', 'whatsapp-commerce-hub' ),
			'confirm_remove'     => __( 'Are you sure you want to remove selected products from WhatsApp catalog?', 'whatsapp-commerce-hub' ),
			'confirm_retry'      => __( 'Retry failed products?', 'whatsapp-commerce-hub' ),
			'no_products'        => __( 'No products selected', 'whatsapp-commerce-hub' ),
			'processing'         => __( 'Processing...', 'whatsapp-commerce-hub' ),
			'items_processed'    => __( 'items processed', 'whatsapp-commerce-hub' ),
			'errors_encountered' => __( 'errors encountered', 'whatsapp-commerce-hub' ),
			'estimated_time'     => __( 'Estimated time remaining:', 'whatsapp-commerce-hub' ),
			'cancel_sync'        => __( 'Cancel Sync', 'whatsapp-commerce-hub' ),
		];
	}

	/**
	 * Render the catalog sync page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$settings   = wch( SettingsManager::class );
		$catalogId  = $settings->get( 'api.catalog_id', '' );
		$businessId = $settings->get( 'api.business_account_id', '' );
		$syncStatus = $this->getSyncStatusOverview();
		$categories = $this->getProductCategories();

		$this->renderPageHtml( $catalogId, $businessId, $syncStatus, $settings, $categories );
	}

	/**
	 * Get product categories.
	 *
	 * @return array
	 */
	private function getProductCategories(): array {
		return get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);
	}

	/**
	 * Get sync status overview.
	 *
	 * @return array
	 */
	private function getSyncStatusOverview(): array {
		global $wpdb;

		$settings = wch( SettingsManager::class );

		$totalSynced = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'synced'"
		);

		$lastSync = $settings->get( 'sync.last_full_sync', '' );

		$errorCount = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'error'"
		);

		return [
			'total_synced' => $totalSynced,
			'last_sync'    => $lastSync,
			'error_count'  => $errorCount,
		];
	}

	/**
	 * Render the page HTML.
	 *
	 * @param string $catalogId  Catalog ID.
	 * @param string $businessId Business ID.
	 * @param array  $syncStatus Sync status overview.
	 * @param object $settings   Settings instance.
	 * @param array  $categories Product categories.
	 * @return void
	 */
	private function renderPageHtml( string $catalogId, string $businessId, array $syncStatus, $settings, array $categories ): void {
		?>
		<div class="wrap wch-catalog-sync-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php $this->renderSyncOverview( $catalogId, $businessId, $syncStatus ); ?>
			<?php $this->renderProductSelection( $categories ); ?>
			<?php $this->renderSyncHistory(); ?>
			<?php $this->renderSyncSettings( $settings, $categories ); ?>
			<?php $this->renderModals(); ?>
		</div>
		<?php
	}

	/**
	 * Render sync overview section.
	 *
	 * @param string $catalogId  Catalog ID.
	 * @param string $businessId Business ID.
	 * @param array  $syncStatus Sync status overview.
	 * @return void
	 */
	private function renderSyncOverview( string $catalogId, string $businessId, array $syncStatus ): void {
		?>
		<div class="wch-sync-overview">
			<div class="wch-status-cards">
				<div class="wch-status-card">
					<div class="wch-status-card-value"><?php echo esc_html( $syncStatus['total_synced'] ); ?></div>
					<div class="wch-status-card-label"><?php esc_html_e( 'Total Products Synced', 'whatsapp-commerce-hub' ); ?></div>
				</div>

				<div class="wch-status-card">
					<div class="wch-status-card-value">
						<?php
						if ( $syncStatus['last_sync'] ) {
							echo esc_html( human_time_diff( strtotime( $syncStatus['last_sync'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'whatsapp-commerce-hub' ) );
						} else {
							esc_html_e( 'Never', 'whatsapp-commerce-hub' );
						}
						?>
					</div>
					<div class="wch-status-card-label"><?php esc_html_e( 'Last Full Sync', 'whatsapp-commerce-hub' ); ?></div>
				</div>

				<div class="wch-status-card wch-status-card-errors" data-error-count="<?php echo esc_attr( $syncStatus['error_count'] ); ?>">
					<div class="wch-status-card-value"><?php echo esc_html( $syncStatus['error_count'] ); ?></div>
					<div class="wch-status-card-label">
						<a href="#" id="show-sync-errors"><?php esc_html_e( 'Sync Errors', 'whatsapp-commerce-hub' ); ?></a>
					</div>
				</div>

				<div class="wch-status-card">
					<div class="wch-status-card-value">
						<?php if ( $catalogId ) : ?>
							<a href="<?php echo esc_url( "https://business.facebook.com/{$businessId}/commerce/catalogs/{$catalogId}" ); ?>" target="_blank">
								<?php echo esc_html( $catalogId ); ?>
							</a>
						<?php else : ?>
							<?php esc_html_e( 'Not Set', 'whatsapp-commerce-hub' ); ?>
						<?php endif; ?>
					</div>
					<div class="wch-status-card-label"><?php esc_html_e( 'WhatsApp Catalog ID', 'whatsapp-commerce-hub' ); ?></div>
				</div>
			</div>

			<div class="wch-sync-actions">
				<button type="button" class="button button-primary" id="sync-all-now">
					<?php esc_html_e( 'Sync All Now', 'whatsapp-commerce-hub' ); ?>
				</button>
				<button type="button" class="button" id="sync-selected">
					<?php esc_html_e( 'Sync Selected', 'whatsapp-commerce-hub' ); ?>
				</button>
				<button type="button" class="button" id="refresh-status">
					<?php esc_html_e( 'Refresh Status', 'whatsapp-commerce-hub' ); ?>
				</button>
				<button type="button" class="button" id="dry-run">
					<?php esc_html_e( 'Dry Run', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render product selection section.
	 *
	 * @param array $categories Product categories.
	 * @return void
	 */
	private function renderProductSelection( array $categories ): void {
		?>
		<div class="wch-product-selection">
			<h2><?php esc_html_e( 'Product Selection', 'whatsapp-commerce-hub' ); ?></h2>

			<div class="wch-table-controls">
				<div class="wch-filters">
					<select id="filter-category" class="wch-filter">
						<option value=""><?php esc_html_e( 'All Categories', 'whatsapp-commerce-hub' ); ?></option>
						<?php foreach ( $categories as $category ) : ?>
							<option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
						<?php endforeach; ?>
					</select>

					<select id="filter-stock" class="wch-filter">
						<option value=""><?php esc_html_e( 'All Stock Status', 'whatsapp-commerce-hub' ); ?></option>
						<option value="instock"><?php esc_html_e( 'In Stock', 'whatsapp-commerce-hub' ); ?></option>
						<option value="outofstock"><?php esc_html_e( 'Out of Stock', 'whatsapp-commerce-hub' ); ?></option>
						<option value="onbackorder"><?php esc_html_e( 'On Backorder', 'whatsapp-commerce-hub' ); ?></option>
					</select>

					<select id="filter-sync-status" class="wch-filter">
						<option value=""><?php esc_html_e( 'All Sync Status', 'whatsapp-commerce-hub' ); ?></option>
						<option value="synced"><?php esc_html_e( 'Synced', 'whatsapp-commerce-hub' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'whatsapp-commerce-hub' ); ?></option>
						<option value="error"><?php esc_html_e( 'Error', 'whatsapp-commerce-hub' ); ?></option>
						<option value="not_selected"><?php esc_html_e( 'Not Selected', 'whatsapp-commerce-hub' ); ?></option>
					</select>

					<input type="search" id="search-products" class="wch-search" placeholder="<?php esc_attr_e( 'Search products...', 'whatsapp-commerce-hub' ); ?>">
				</div>

				<div class="wch-bulk-actions">
					<select id="bulk-action">
						<option value=""><?php esc_html_e( 'Bulk Actions', 'whatsapp-commerce-hub' ); ?></option>
						<option value="add"><?php esc_html_e( 'Add to WhatsApp Catalog', 'whatsapp-commerce-hub' ); ?></option>
						<option value="remove"><?php esc_html_e( 'Remove from Catalog', 'whatsapp-commerce-hub' ); ?></option>
						<option value="retry"><?php esc_html_e( 'Retry Failed', 'whatsapp-commerce-hub' ); ?></option>
					</select>
					<button type="button" class="button" id="apply-bulk-action">
						<?php esc_html_e( 'Apply', 'whatsapp-commerce-hub' ); ?>
					</button>
				</div>
			</div>

			<?php $this->renderProductsTable(); ?>
		</div>
		<?php
	}

	/**
	 * Render products table.
	 *
	 * @return void
	 */
	private function renderProductsTable(): void {
		?>
		<div class="wch-products-table-container">
			<table class="wp-list-table widefat fixed striped wch-products-table">
				<thead>
					<tr>
						<th class="check-column">
							<input type="checkbox" id="select-all-products">
						</th>
						<th><?php esc_html_e( 'Image', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Name', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Price', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Stock Status', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Sync Status', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Last Synced', 'whatsapp-commerce-hub' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'whatsapp-commerce-hub' ); ?></th>
					</tr>
				</thead>
				<tbody id="products-table-body">
					<tr>
						<td colspan="9" class="wch-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading products...', 'whatsapp-commerce-hub' ); ?>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="wch-table-pagination">
				<div class="wch-pagination-info"></div>
				<div class="wch-pagination-controls">
					<button type="button" class="button" id="prev-page" disabled>
						<?php esc_html_e( 'Previous', 'whatsapp-commerce-hub' ); ?>
					</button>
					<span class="wch-page-number"></span>
					<button type="button" class="button" id="next-page">
						<?php esc_html_e( 'Next', 'whatsapp-commerce-hub' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sync history section.
	 *
	 * @return void
	 */
	private function renderSyncHistory(): void {
		?>
		<div class="wch-sync-history">
			<h2><?php esc_html_e( 'Sync History', 'whatsapp-commerce-hub' ); ?></h2>

			<div class="wch-history-table-container">
				<table class="wp-list-table widefat fixed striped wch-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Timestamp', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Products Affected', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Status', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Triggered By', 'whatsapp-commerce-hub' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'whatsapp-commerce-hub' ); ?></th>
						</tr>
					</thead>
					<tbody id="history-table-body">
						<tr>
							<td colspan="7" class="wch-loading">
								<span class="spinner is-active"></span>
								<?php esc_html_e( 'Loading history...', 'whatsapp-commerce-hub' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="wch-history-pagination">
					<button type="button" class="button" id="history-prev-page" disabled>
						<?php esc_html_e( 'Previous', 'whatsapp-commerce-hub' ); ?>
					</button>
					<span class="wch-history-page-number"></span>
					<button type="button" class="button" id="history-next-page">
						<?php esc_html_e( 'Next', 'whatsapp-commerce-hub' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sync settings section.
	 *
	 * @param object $settings   Settings instance.
	 * @param array  $categories Product categories.
	 * @return void
	 */
	private function renderSyncSettings( $settings, array $categories ): void {
		$syncMode           = $settings->get( 'sync.mode', 'manual' );
		$syncFrequency      = $settings->get( 'sync.frequency', 'daily' );
		$includedCategories = $settings->get( 'sync.categories_include', [] );
		$excludedCategories = $settings->get( 'sync.categories_exclude', [] );
		?>
		<div class="wch-sync-settings">
			<h2><?php esc_html_e( 'Sync Settings', 'whatsapp-commerce-hub' ); ?></h2>

			<form id="sync-settings-form">
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="sync-mode"><?php esc_html_e( 'Sync Mode', 'whatsapp-commerce-hub' ); ?></label>
							</th>
							<td>
								<select name="sync_mode" id="sync-mode">
									<option value="manual" <?php selected( $syncMode, 'manual' ); ?>>
										<?php esc_html_e( 'Manual Only', 'whatsapp-commerce-hub' ); ?>
									</option>
									<option value="on_change" <?php selected( $syncMode, 'on_change' ); ?>>
										<?php esc_html_e( 'On Product Change', 'whatsapp-commerce-hub' ); ?>
									</option>
									<option value="scheduled" <?php selected( $syncMode, 'scheduled' ); ?>>
										<?php esc_html_e( 'Scheduled', 'whatsapp-commerce-hub' ); ?>
									</option>
								</select>
							</td>
						</tr>

						<tr class="sync-schedule-row" style="display: none;">
							<th scope="row">
								<label for="sync-frequency"><?php esc_html_e( 'Schedule Frequency', 'whatsapp-commerce-hub' ); ?></label>
							</th>
							<td>
								<select name="sync_frequency" id="sync-frequency">
									<option value="hourly" <?php selected( $syncFrequency, 'hourly' ); ?>>
										<?php esc_html_e( 'Hourly', 'whatsapp-commerce-hub' ); ?>
									</option>
									<option value="twicedaily" <?php selected( $syncFrequency, 'twicedaily' ); ?>>
										<?php esc_html_e( 'Twice Daily', 'whatsapp-commerce-hub' ); ?>
									</option>
									<option value="daily" <?php selected( $syncFrequency, 'daily' ); ?>>
										<?php esc_html_e( 'Daily', 'whatsapp-commerce-hub' ); ?>
									</option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="categories-include"><?php esc_html_e( 'Categories to Include', 'whatsapp-commerce-hub' ); ?></label>
							</th>
							<td>
								<select name="categories_include[]" id="categories-include" multiple class="wch-multiselect">
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php echo in_array( $category->term_id, (array) $includedCategories, true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Leave empty to include all categories', 'whatsapp-commerce-hub' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="categories-exclude"><?php esc_html_e( 'Categories to Exclude', 'whatsapp-commerce-hub' ); ?></label>
							</th>
							<td>
								<select name="categories_exclude[]" id="categories-exclude" multiple class="wch-multiselect">
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php echo in_array( $category->term_id, (array) $excludedCategories, true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'whatsapp-commerce-hub' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render modals.
	 *
	 * @return void
	 */
	private function renderModals(): void {
		?>
		<!-- Progress Modal -->
		<div id="sync-progress-modal" class="wch-modal" style="display: none;">
			<div class="wch-modal-content">
				<div class="wch-modal-header">
					<h2><?php esc_html_e( 'Sync Progress', 'whatsapp-commerce-hub' ); ?></h2>
				</div>
				<div class="wch-modal-body">
					<div class="wch-progress-info">
						<div class="wch-progress-stat">
							<strong><?php esc_html_e( 'Products Processed:', 'whatsapp-commerce-hub' ); ?></strong>
							<span id="progress-processed">0</span> / <span id="progress-total">0</span>
						</div>
						<div class="wch-progress-stat">
							<strong><?php esc_html_e( 'Current Product:', 'whatsapp-commerce-hub' ); ?></strong>
							<span id="progress-current-product">-</span>
						</div>
						<div class="wch-progress-stat">
							<strong><?php esc_html_e( 'Errors Encountered:', 'whatsapp-commerce-hub' ); ?></strong>
							<span id="progress-errors">0</span>
						</div>
						<div class="wch-progress-stat">
							<strong><?php esc_html_e( 'Estimated Time Remaining:', 'whatsapp-commerce-hub' ); ?></strong>
							<span id="progress-eta">-</span>
						</div>
					</div>
					<div class="wch-progress-bar">
						<div class="wch-progress-fill" style="width: 0%;"></div>
					</div>
					<div class="wch-progress-errors-list" style="display: none;">
						<h3><?php esc_html_e( 'Errors:', 'whatsapp-commerce-hub' ); ?></h3>
						<ul id="progress-error-list"></ul>
					</div>
				</div>
				<div class="wch-modal-footer">
					<button type="button" class="button" id="cancel-sync">
						<?php esc_html_e( 'Cancel', 'whatsapp-commerce-hub' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Error Details Modal -->
		<div id="error-details-modal" class="wch-modal" style="display: none;">
			<div class="wch-modal-content">
				<div class="wch-modal-header">
					<h2><?php esc_html_e( 'Sync Errors', 'whatsapp-commerce-hub' ); ?></h2>
					<button type="button" class="wch-modal-close">&times;</button>
				</div>
				<div class="wch-modal-body">
					<div id="error-details-content"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Get products.
	 *
	 * @return void
	 */
	public function ajaxGetProducts(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$page       = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$perPage    = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$category   = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
		$stock      = isset( $_POST['stock'] ) ? sanitize_text_field( wp_unslash( $_POST['stock'] ) ) : '';
		$syncStatus = isset( $_POST['sync_status'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_status'] ) ) : '';

		$args = [
			'post_type'      => 'product',
			'posts_per_page' => $perPage,
			'paged'          => $page,
			'post_status'    => 'publish',
			's'              => $search,
		];

		if ( $category ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category,
				],
			];
		}

		if ( $stock ) {
			$args['meta_query'] = [
				[
					'key'   => '_stock_status',
					'value' => $stock,
				],
			];
		}

		if ( $syncStatus ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = [];
			}
			$args['meta_query'][] = [
				'key'   => '_wch_sync_status',
				'value' => $syncStatus,
			];
		}

		$query    = new \WP_Query( $args );
		$products = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );

				if ( $product ) {
					$productSyncStatus = get_post_meta( $product->get_id(), '_wch_sync_status', true );
					$lastSynced        = get_post_meta( $product->get_id(), '_wch_last_synced', true );
					$syncError         = get_post_meta( $product->get_id(), '_wch_sync_error', true );

					$products[] = [
						'id'          => $product->get_id(),
						'name'        => $product->get_name(),
						'sku'         => $product->get_sku(),
						'price'       => $product->get_price_html(),
						'stock'       => $product->get_stock_status(),
						'sync_status' => $productSyncStatus ?: 'not_selected',
						'last_synced' => $lastSynced ? human_time_diff( strtotime( $lastSynced ), current_time( 'timestamp' ) ) . ' ago' : '-',
						'image_url'   => get_the_post_thumbnail_url( $product->get_id(), 'thumbnail' ),
						'error'       => $syncError,
					];
				}
			}
			wp_reset_postdata();
		}

		wp_send_json_success(
			[
				'products'    => $products,
				'total'       => $query->found_posts,
				'total_pages' => $query->max_num_pages,
			]
		);
	}

	/**
	 * AJAX: Bulk sync.
	 *
	 * @return void
	 */
	public function ajaxBulkSync(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$productIds = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : [];
		$syncAll    = isset( $_POST['sync_all'] ) ? (bool) $_POST['sync_all'] : false;

		if ( empty( $productIds ) && ! $syncAll ) {
			wp_send_json_error( [ 'message' => __( 'No products selected', 'whatsapp-commerce-hub' ) ] );
		}

		try {
			$syncService = wch( ProductSyncOrchestratorInterface::class );

			if ( $syncAll ) {
				$syncService->syncAllProducts();
			} else {
				foreach ( $productIds as $productId ) {
					update_post_meta( $productId, '_wch_sync_status', 'pending' );
				}
				wch( QueueManager::class )->schedule_bulk_action( 'wch_sync_single_product', $productIds );
			}

			$this->recordSyncHistory( count( $productIds ), 'manual' );

			wp_send_json_success(
				[
					'message' => __( 'Products queued for sync', 'whatsapp-commerce-hub' ),
					'count'   => $syncAll ? 'all' : count( $productIds ),
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX: Sync single product.
	 *
	 * @return void
	 */
	public function ajaxSyncProduct(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$productId = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $productId ) {
			wp_send_json_error( [ 'message' => __( 'Invalid product ID', 'whatsapp-commerce-hub' ) ] );
		}

		try {
			$syncService = wch( ProductSyncOrchestratorInterface::class );
			$result      = $syncService->syncProduct( $productId );

			if ( ! empty( $result['success'] ) ) {
				update_post_meta( $productId, '_wch_sync_status', 'synced' );
				update_post_meta( $productId, '_wch_last_synced', current_time( 'mysql' ) );
				delete_post_meta( $productId, '_wch_sync_error' );

				wp_send_json_success( [ 'message' => __( 'Product synced successfully', 'whatsapp-commerce-hub' ) ] );
			} else {
				wp_send_json_error( [ 'message' => __( 'Sync failed', 'whatsapp-commerce-hub' ) ] );
			}
		} catch ( \Exception $e ) {
			update_post_meta( $productId, '_wch_sync_status', 'error' );
			update_post_meta( $productId, '_wch_sync_error', $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX: Remove from catalog.
	 *
	 * @return void
	 */
	public function ajaxRemoveFromCatalog(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$productIds = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : [];

		if ( empty( $productIds ) ) {
			wp_send_json_error( [ 'message' => __( 'No products selected', 'whatsapp-commerce-hub' ) ] );
		}

		try {
			foreach ( $productIds as $productId ) {
				delete_post_meta( $productId, '_wch_sync_status' );
				delete_post_meta( $productId, '_wch_last_synced' );
				delete_post_meta( $productId, '_wch_sync_error' );
			}

			wp_send_json_success(
				[
					'message' => __( 'Products removed from catalog', 'whatsapp-commerce-hub' ),
					'count'   => count( $productIds ),
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX: Get sync history.
	 *
	 * @return void
	 */
	public function ajaxGetSyncHistory(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$perPage = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;

		$history = get_option( 'wch_sync_history', [] );
		$total   = count( $history );

		usort(
			$history,
			function ( $a, $b ) {
				return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
			}
		);

		$offset  = ( $page - 1 ) * $perPage;
		$history = array_slice( $history, $offset, $perPage );

		wp_send_json_success(
			[
				'history'     => $history,
				'total'       => $total,
				'total_pages' => ceil( $total / $perPage ),
			]
		);
	}

	/**
	 * AJAX: Get sync status.
	 *
	 * @return void
	 */
	public function ajaxGetSyncStatus(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$status = $this->getSyncStatusOverview();
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: Save sync settings.
	 *
	 * @return void
	 */
	public function ajaxSaveSyncSettings(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$settings = wch( SettingsManager::class );

		$syncMode          = isset( $_POST['sync_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_mode'] ) ) : 'manual';
		$syncFrequency     = isset( $_POST['sync_frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_frequency'] ) ) : 'daily';
		$categoriesInclude = isset( $_POST['categories_include'] ) ? array_map( 'absint', (array) $_POST['categories_include'] ) : [];
		$categoriesExclude = isset( $_POST['categories_exclude'] ) ? array_map( 'absint', (array) $_POST['categories_exclude'] ) : [];

		$settings->set( 'sync.mode', $syncMode );
		$settings->set( 'sync.frequency', $syncFrequency );
		$settings->set( 'sync.categories_include', $categoriesInclude );
		$settings->set( 'sync.categories_exclude', $categoriesExclude );

		wp_send_json_success( [ 'message' => __( 'Settings saved successfully', 'whatsapp-commerce-hub' ) ] );
	}

	/**
	 * AJAX: Dry run sync.
	 *
	 * @return void
	 */
	public function ajaxDryRunSync(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$args = [
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		];

		$query        = new \WP_Query( $args );
		$productIds   = $query->posts;
		$productsInfo = [];

		foreach ( $productIds as $productId ) {
			$product = wc_get_product( $productId );
			if ( $product ) {
				$productsInfo[] = [
					'id'   => $product->get_id(),
					'name' => $product->get_name(),
					'sku'  => $product->get_sku(),
				];
			}
		}

		wp_send_json_success(
			[
				'count'    => count( $productIds ),
				'products' => $productsInfo,
			]
		);
	}

	/**
	 * AJAX: Retry failed products.
	 *
	 * @return void
	 */
	public function ajaxRetryFailed(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		global $wpdb;

		$productIds = $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'error'"
		);

		if ( empty( $productIds ) ) {
			wp_send_json_error( [ 'message' => __( 'No failed products to retry', 'whatsapp-commerce-hub' ) ] );
		}

		foreach ( $productIds as $productId ) {
			update_post_meta( $productId, '_wch_sync_status', 'pending' );
			delete_post_meta( $productId, '_wch_sync_error' );
		}

		wch( QueueManager::class )->schedule_bulk_action( 'wch_sync_single_product', $productIds );

		wp_send_json_success(
			[
				'message' => __( 'Failed products queued for retry', 'whatsapp-commerce-hub' ),
				'count'   => count( $productIds ),
			]
		);
	}

	/**
	 * AJAX: Get bulk sync progress.
	 *
	 * @return void
	 */
	public function ajaxGetBulkSyncProgress(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$progressTracker = wch( SyncProgressTrackerInterface::class );
		$progress        = $progressTracker->getProgress();

		if ( ! $progress ) {
			wp_send_json_success(
				[
					'has_progress' => false,
					'message'      => __( 'No sync operation in progress', 'whatsapp-commerce-hub' ),
				]
			);
		}

		$progress['started_at_formatted'] = ! empty( $progress['started_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $progress['started_at'] ) )
			: '';

		$progress['completed_at_formatted'] = ! empty( $progress['completed_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $progress['completed_at'] ) )
			: '';

		if ( isset( $progress['elapsed_seconds'] ) ) {
			$progress['elapsed_formatted'] = $this->formatDuration( $progress['elapsed_seconds'] );
		}

		if ( isset( $progress['estimated_remaining_seconds'] ) ) {
			$progress['eta_formatted'] = $this->formatDuration( $progress['estimated_remaining_seconds'] );
		}

		$progress['has_progress'] = true;

		wp_send_json_success( $progress );
	}

	/**
	 * AJAX: Retry failed items from bulk sync.
	 *
	 * @return void
	 */
	public function ajaxRetryFailedBulk(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$syncService = wch( ProductSyncOrchestratorInterface::class );
		$syncId      = $syncService->retryFailedItems();

		if ( ! $syncId ) {
			wp_send_json_error(
				[ 'message' => __( 'No failed items to retry', 'whatsapp-commerce-hub' ) ]
			);
		}

		wp_send_json_success(
			[
				'message' => __( 'Retry sync started', 'whatsapp-commerce-hub' ),
				'sync_id' => $syncId,
			]
		);
	}

	/**
	 * AJAX: Clear sync progress data.
	 *
	 * @return void
	 */
	public function ajaxClearSyncProgress(): void {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		$progressTracker = wch( SyncProgressTrackerInterface::class );
		$cleared         = $progressTracker->clearProgress();

		if ( ! $cleared ) {
			wp_send_json_error(
				[ 'message' => __( 'Cannot clear progress while sync is in progress', 'whatsapp-commerce-hub' ) ]
			);
		}

		wp_send_json_success(
			[ 'message' => __( 'Sync progress cleared', 'whatsapp-commerce-hub' ) ]
		);
	}

	/**
	 * Record sync history.
	 *
	 * @param int    $productCount Product count.
	 * @param string $triggeredBy  Who triggered the sync.
	 * @param string $status       Sync status.
	 * @param int    $duration     Duration in seconds.
	 * @param array  $errors       List of errors.
	 * @return void
	 */
	private function recordSyncHistory( int $productCount, string $triggeredBy = 'manual', string $status = 'success', int $duration = 0, array $errors = [] ): void {
		$history = get_option( 'wch_sync_history', [] );

		$entry = [
			'timestamp'      => current_time( 'mysql' ),
			'products_count' => $productCount,
			'status'         => $status,
			'duration'       => $duration,
			'error_count'    => count( $errors ),
			'errors'         => $errors,
			'triggered_by'   => $triggeredBy,
		];

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, 100 );

		update_option( 'wch_sync_history', $history );
	}

	/**
	 * Format duration in seconds to human-readable string.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
	 */
	private function formatDuration( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return __( '0 seconds', 'whatsapp-commerce-hub' );
		}

		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'whatsapp-commerce-hub' ), $seconds );
		}

		$minutes          = floor( $seconds / 60 );
		$remainingSeconds = $seconds % 60;

		if ( $minutes < 60 ) {
			if ( $remainingSeconds > 0 ) {
				/* translators: 1: number of minutes, 2: number of seconds */
				return sprintf(
					__( '%1$d min %2$d sec', 'whatsapp-commerce-hub' ),
					$minutes,
					$remainingSeconds
				);
			}
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'whatsapp-commerce-hub' ), $minutes );
		}

		$hours            = floor( $minutes / 60 );
		$remainingMinutes = $minutes % 60;

		/* translators: 1: number of hours, 2: number of minutes */
		return sprintf(
			__( '%1$dh %2$dm', 'whatsapp-commerce-hub' ),
			$hours,
			$remainingMinutes
		);
	}
}
