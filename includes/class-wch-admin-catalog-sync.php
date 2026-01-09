<?php
/**
 * Admin Catalog Sync Page
 *
 * Manages product catalog synchronization with WhatsApp.
 *
 * @package WhatsApp_Commerce_Hub
 */

defined( 'ABSPATH' ) || exit;

class WCH_Admin_Catalog_Sync {
	/**
	 * Initialize admin catalog sync page
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ), 51 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wch_get_products', array( __CLASS__, 'ajax_get_products' ) );
		add_action( 'wp_ajax_wch_bulk_sync', array( __CLASS__, 'ajax_bulk_sync' ) );
		add_action( 'wp_ajax_wch_sync_product', array( __CLASS__, 'ajax_sync_product' ) );
		add_action( 'wp_ajax_wch_remove_from_catalog', array( __CLASS__, 'ajax_remove_from_catalog' ) );
		add_action( 'wp_ajax_wch_get_sync_history', array( __CLASS__, 'ajax_get_sync_history' ) );
		add_action( 'wp_ajax_wch_get_sync_status', array( __CLASS__, 'ajax_get_sync_status' ) );
		add_action( 'wp_ajax_wch_save_sync_settings', array( __CLASS__, 'ajax_save_sync_settings' ) );
		add_action( 'wp_ajax_wch_dry_run_sync', array( __CLASS__, 'ajax_dry_run_sync' ) );
		add_action( 'wp_ajax_wch_retry_failed', array( __CLASS__, 'ajax_retry_failed' ) );
		add_action( 'wp_ajax_wch_get_bulk_sync_progress', array( __CLASS__, 'ajax_get_bulk_sync_progress' ) );
		add_action( 'wp_ajax_wch_retry_failed_bulk', array( __CLASS__, 'ajax_retry_failed_bulk' ) );
		add_action( 'wp_ajax_wch_clear_sync_progress', array( __CLASS__, 'ajax_clear_sync_progress' ) );
	}

	/**
	 * Add admin menu item
	 */
	public static function add_menu_item() {
		add_submenu_page(
			'woocommerce',
			__( 'Catalog Sync', 'whatsapp-commerce-hub' ),
			__( 'Catalog Sync', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-catalog-sync',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wch-catalog-sync' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-catalog-sync',
			WCH_PLUGIN_URL . 'assets/css/admin-catalog-sync.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-catalog-sync',
			WCH_PLUGIN_URL . 'assets/js/admin-catalog-sync.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-catalog-sync',
			'wchCatalogSync',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wch_catalog_sync_nonce' ),
				'strings'  => array(
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
				),
			)
		);
	}

	/**
	 * Render catalog sync page
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$settings    = WCH_Settings::getInstance();
		$catalog_id  = $settings->get( 'api.catalog_id', '' );
		$business_id = $settings->get( 'api.business_account_id', '' );

		// Get sync status overview
		$sync_status = self::get_sync_status_overview();

		?>
		<div class="wrap wch-catalog-sync-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<!-- Sync Status Overview -->
			<div class="wch-sync-overview">
				<div class="wch-status-cards">
					<div class="wch-status-card">
						<div class="wch-status-card-value"><?php echo esc_html( $sync_status['total_synced'] ); ?></div>
						<div class="wch-status-card-label"><?php esc_html_e( 'Total Products Synced', 'whatsapp-commerce-hub' ); ?></div>
					</div>

					<div class="wch-status-card">
						<div class="wch-status-card-value">
							<?php
							if ( $sync_status['last_sync'] ) {
								echo esc_html( human_time_diff( strtotime( $sync_status['last_sync'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'whatsapp-commerce-hub' ) );
							} else {
								esc_html_e( 'Never', 'whatsapp-commerce-hub' );
							}
							?>
						</div>
						<div class="wch-status-card-label"><?php esc_html_e( 'Last Full Sync', 'whatsapp-commerce-hub' ); ?></div>
					</div>

					<div class="wch-status-card wch-status-card-errors" data-error-count="<?php echo esc_attr( $sync_status['error_count'] ); ?>">
						<div class="wch-status-card-value"><?php echo esc_html( $sync_status['error_count'] ); ?></div>
						<div class="wch-status-card-label">
							<a href="#" id="show-sync-errors"><?php esc_html_e( 'Sync Errors', 'whatsapp-commerce-hub' ); ?></a>
						</div>
					</div>

					<div class="wch-status-card">
						<div class="wch-status-card-value">
							<?php if ( $catalog_id ) : ?>
								<a href="<?php echo esc_url( "https://business.facebook.com/{$business_id}/commerce/catalogs/{$catalog_id}" ); ?>" target="_blank">
									<?php echo esc_html( $catalog_id ); ?>
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

			<!-- Product Selection Table -->
			<div class="wch-product-selection">
				<h2><?php esc_html_e( 'Product Selection', 'whatsapp-commerce-hub' ); ?></h2>

				<div class="wch-table-controls">
					<div class="wch-filters">
						<select id="filter-category" class="wch-filter">
							<option value=""><?php esc_html_e( 'All Categories', 'whatsapp-commerce-hub' ); ?></option>
							<?php
							$categories = get_terms(
								array(
									'taxonomy'   => 'product_cat',
									'hide_empty' => false,
								)
							);
							foreach ( $categories as $category ) {
								echo '<option value="' . esc_attr( $category->term_id ) . '">' . esc_html( $category->name ) . '</option>';
							}
							?>
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
			</div>

			<!-- Sync History -->
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

			<!-- Settings Panel -->
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
										<option value="manual" <?php selected( $settings->get( 'sync.mode', 'manual' ), 'manual' ); ?>>
											<?php esc_html_e( 'Manual Only', 'whatsapp-commerce-hub' ); ?>
										</option>
										<option value="on_change" <?php selected( $settings->get( 'sync.mode', 'manual' ), 'on_change' ); ?>>
											<?php esc_html_e( 'On Product Change', 'whatsapp-commerce-hub' ); ?>
										</option>
										<option value="scheduled" <?php selected( $settings->get( 'sync.mode', 'manual' ), 'scheduled' ); ?>>
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
										<option value="hourly" <?php selected( $settings->get( 'sync.frequency', 'daily' ), 'hourly' ); ?>>
											<?php esc_html_e( 'Hourly', 'whatsapp-commerce-hub' ); ?>
										</option>
										<option value="twicedaily" <?php selected( $settings->get( 'sync.frequency', 'daily' ), 'twicedaily' ); ?>>
											<?php esc_html_e( 'Twice Daily', 'whatsapp-commerce-hub' ); ?>
										</option>
										<option value="daily" <?php selected( $settings->get( 'sync.frequency', 'daily' ), 'daily' ); ?>>
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
										<?php
										$included_categories = $settings->get( 'sync.categories_include', array() );
										foreach ( $categories as $category ) {
											$selected = in_array( $category->term_id, (array) $included_categories, true ) ? 'selected' : '';
											echo '<option value="' . esc_attr( $category->term_id ) . '" ' . $selected . '>' . esc_html( $category->name ) . '</option>';
										}
										?>
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
										<?php
										$excluded_categories = $settings->get( 'sync.categories_exclude', array() );
										foreach ( $categories as $category ) {
											$selected = in_array( $category->term_id, (array) $excluded_categories, true ) ? 'selected' : '';
											echo '<option value="' . esc_attr( $category->term_id ) . '" ' . $selected . '>' . esc_html( $category->name ) . '</option>';
										}
										?>
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
		</div>

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
	 * Get sync status overview
	 */
	private static function get_sync_status_overview() {
		global $wpdb;

		$settings = WCH_Settings::getInstance();

		// Count synced products
		$total_synced = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'synced'"
		);

		// Get last sync time
		$last_sync = $settings->get( 'sync.last_full_sync', '' );

		// Count errors
		$error_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'error'"
		);

		return array(
			'total_synced' => (int) $total_synced,
			'last_sync'    => $last_sync,
			'error_count'  => (int) $error_count,
		);
	}

	/**
	 * AJAX: Get products
	 */
	public static function ajax_get_products() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$page        = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page    = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
		$search      = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		$category    = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
		$stock       = isset( $_POST['stock'] ) ? sanitize_text_field( $_POST['stock'] ) : '';
		$sync_status = isset( $_POST['sync_status'] ) ? sanitize_text_field( $_POST['sync_status'] ) : '';

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => 'publish',
			's'              => $search,
		);

		if ( $category ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category,
				),
			);
		}

		if ( $stock ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_stock_status',
					'value' => $stock,
				),
			);
		}

		if ( $sync_status ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}
			$args['meta_query'][] = array(
				'key'   => '_wch_sync_status',
				'value' => $sync_status,
			);
		}

		$query    = new WP_Query( $args );
		$products = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );

				if ( $product ) {
					$sync_status = get_post_meta( $product->get_id(), '_wch_sync_status', true );
					$last_synced = get_post_meta( $product->get_id(), '_wch_last_synced', true );
					$sync_error  = get_post_meta( $product->get_id(), '_wch_sync_error', true );

					$products[] = array(
						'id'          => $product->get_id(),
						'name'        => $product->get_name(),
						'sku'         => $product->get_sku(),
						'price'       => $product->get_price_html(),
						'stock'       => $product->get_stock_status(),
						'sync_status' => $sync_status ?: 'not_selected',
						'last_synced' => $last_synced ? human_time_diff( strtotime( $last_synced ), current_time( 'timestamp' ) ) . ' ago' : '-',
						'image_url'   => get_the_post_thumbnail_url( $product->get_id(), 'thumbnail' ),
						'error'       => $sync_error,
					);
				}
			}
			wp_reset_postdata();
		}

		wp_send_json_success(
			array(
				'products'    => $products,
				'total'       => $query->found_posts,
				'total_pages' => $query->max_num_pages,
			)
		);
	}

	/**
	 * AJAX: Bulk sync
	 */
	public static function ajax_bulk_sync() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
		$sync_all    = isset( $_POST['sync_all'] ) ? (bool) $_POST['sync_all'] : false;

		if ( empty( $product_ids ) && ! $sync_all ) {
			wp_send_json_error( array( 'message' => __( 'No products selected', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			$sync_service = WCH_Product_Sync_Service::instance();

			if ( $sync_all ) {
				$sync_service->sync_all_products();
			} else {
				foreach ( $product_ids as $product_id ) {
					update_post_meta( $product_id, '_wch_sync_status', 'pending' );
				}
				// Queue sync jobs
				WCH_Queue::getInstance()->schedule_bulk_action( 'wch_sync_product', $product_ids );
			}

			// Record sync history
			self::record_sync_history( count( $product_ids ), 'manual' );

			wp_send_json_success(
				array(
					'message' => __( 'Products queued for sync', 'whatsapp-commerce-hub' ),
					'count'   => $sync_all ? 'all' : count( $product_ids ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Sync single product
	 */
	public static function ajax_sync_product() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			$sync_service = WCH_Product_Sync_Service::instance();
			$result       = $sync_service->sync_product( $product_id );

			if ( $result ) {
				update_post_meta( $product_id, '_wch_sync_status', 'synced' );
				update_post_meta( $product_id, '_wch_last_synced', current_time( 'mysql' ) );
				delete_post_meta( $product_id, '_wch_sync_error' );

				wp_send_json_success( array( 'message' => __( 'Product synced successfully', 'whatsapp-commerce-hub' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Sync failed', 'whatsapp-commerce-hub' ) ) );
			}
		} catch ( Exception $e ) {
			update_post_meta( $product_id, '_wch_sync_status', 'error' );
			update_post_meta( $product_id, '_wch_sync_error', $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Remove from catalog
	 */
	public static function ajax_remove_from_catalog() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();

		if ( empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No products selected', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			foreach ( $product_ids as $product_id ) {
				delete_post_meta( $product_id, '_wch_sync_status' );
				delete_post_meta( $product_id, '_wch_last_synced' );
				delete_post_meta( $product_id, '_wch_sync_error' );
			}

			wp_send_json_success(
				array(
					'message' => __( 'Products removed from catalog', 'whatsapp-commerce-hub' ),
					'count'   => count( $product_ids ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Get sync history
	 */
	public static function ajax_get_sync_history() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;

		$history = get_option( 'wch_sync_history', array() );
		$total   = count( $history );

		// Sort by timestamp descending
		usort(
			$history,
			function ( $a, $b ) {
				return strtotime( $b['timestamp'] ) - strtotime( $a['timestamp'] );
			}
		);

		// Paginate
		$offset  = ( $page - 1 ) * $per_page;
		$history = array_slice( $history, $offset, $per_page );

		wp_send_json_success(
			array(
				'history'     => $history,
				'total'       => $total,
				'total_pages' => ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * AJAX: Get sync status
	 */
	public static function ajax_get_sync_status() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$status = self::get_sync_status_overview();
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: Save sync settings
	 */
	public static function ajax_save_sync_settings() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$settings = WCH_Settings::getInstance();

		$sync_mode          = isset( $_POST['sync_mode'] ) ? sanitize_text_field( $_POST['sync_mode'] ) : 'manual';
		$sync_frequency     = isset( $_POST['sync_frequency'] ) ? sanitize_text_field( $_POST['sync_frequency'] ) : 'daily';
		$categories_include = isset( $_POST['categories_include'] ) ? array_map( 'absint', (array) $_POST['categories_include'] ) : array();
		$categories_exclude = isset( $_POST['categories_exclude'] ) ? array_map( 'absint', (array) $_POST['categories_exclude'] ) : array();

		$settings->set( 'sync.mode', $sync_mode );
		$settings->set( 'sync.frequency', $sync_frequency );
		$settings->set( 'sync.categories_include', $categories_include );
		$settings->set( 'sync.categories_exclude', $categories_exclude );

		wp_send_json_success( array( 'message' => __( 'Settings saved successfully', 'whatsapp-commerce-hub' ) ) );
	}

	/**
	 * AJAX: Dry run sync
	 */
	public static function ajax_dry_run_sync() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		// Get products that would be synced
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);

		$query       = new WP_Query( $args );
		$product_ids = $query->posts;

		$products_info = array();
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$products_info[] = array(
					'id'   => $product->get_id(),
					'name' => $product->get_name(),
					'sku'  => $product->get_sku(),
				);
			}
		}

		wp_send_json_success(
			array(
				'count'    => count( $product_ids ),
				'products' => $products_info,
			)
		);
	}

	/**
	 * AJAX: Retry failed products
	 */
	public static function ajax_retry_failed() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		global $wpdb;

		// Get all products with error status
		$product_ids = $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->prefix}postmeta
			WHERE meta_key = '_wch_sync_status'
			AND meta_value = 'error'"
		);

		if ( empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No failed products to retry', 'whatsapp-commerce-hub' ) ) );
		}

		// Reset status to pending
		foreach ( $product_ids as $product_id ) {
			update_post_meta( $product_id, '_wch_sync_status', 'pending' );
			delete_post_meta( $product_id, '_wch_sync_error' );
		}

		// Queue sync jobs
		WCH_Queue::getInstance()->schedule_bulk_action( 'wch_sync_product', $product_ids );

		wp_send_json_success(
			array(
				'message' => __( 'Failed products queued for retry', 'whatsapp-commerce-hub' ),
				'count'   => count( $product_ids ),
			)
		);
	}

	/**
	 * Record sync history
	 */
	private static function record_sync_history( $product_count, $triggered_by = 'manual', $status = 'success', $duration = 0, $errors = array() ) {
		$history = get_option( 'wch_sync_history', array() );

		$entry = array(
			'timestamp'      => current_time( 'mysql' ),
			'products_count' => $product_count,
			'status'         => $status,
			'duration'       => $duration,
			'error_count'    => count( $errors ),
			'errors'         => $errors,
			'triggered_by'   => $triggered_by,
		);

		array_unshift( $history, $entry );

		// Keep only last 100 entries
		$history = array_slice( $history, 0, 100 );

		update_option( 'wch_sync_history', $history );
	}

	/**
	 * AJAX: Get bulk sync progress
	 *
	 * Returns real-time progress of an ongoing bulk sync operation.
	 */
	public static function ajax_get_bulk_sync_progress() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$sync_service = WCH_Product_Sync_Service::instance();
		$progress     = $sync_service->get_sync_progress();

		if ( ! $progress ) {
			wp_send_json_success(
				array(
					'has_progress' => false,
					'message'      => __( 'No sync operation in progress', 'whatsapp-commerce-hub' ),
				)
			);
		}

		// Format timestamps for display.
		$progress['started_at_formatted'] = ! empty( $progress['started_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $progress['started_at'] ) )
			: '';

		$progress['completed_at_formatted'] = ! empty( $progress['completed_at'] )
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $progress['completed_at'] ) )
			: '';

		// Format elapsed time.
		if ( isset( $progress['elapsed_seconds'] ) ) {
			$progress['elapsed_formatted'] = self::format_duration( $progress['elapsed_seconds'] );
		}

		// Format ETA.
		if ( isset( $progress['estimated_remaining_seconds'] ) ) {
			$progress['eta_formatted'] = self::format_duration( $progress['estimated_remaining_seconds'] );
		}

		$progress['has_progress'] = true;

		wp_send_json_success( $progress );
	}

	/**
	 * AJAX: Retry failed items from bulk sync
	 *
	 * Uses the new retry_failed_items method from WCH_Product_Sync_Service.
	 */
	public static function ajax_retry_failed_bulk() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$sync_service = WCH_Product_Sync_Service::instance();
		$sync_id      = $sync_service->retry_failed_items();

		if ( ! $sync_id ) {
			wp_send_json_error(
				array( 'message' => __( 'No failed items to retry', 'whatsapp-commerce-hub' ) )
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Retry sync started', 'whatsapp-commerce-hub' ),
				'sync_id' => $sync_id,
			)
		);
	}

	/**
	 * AJAX: Clear sync progress data
	 */
	public static function ajax_clear_sync_progress() {
		check_ajax_referer( 'wch_catalog_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$sync_service = WCH_Product_Sync_Service::instance();

		// Atomically check and clear (handles race conditions internally).
		$cleared = $sync_service->clear_sync_progress();

		if ( ! $cleared ) {
			wp_send_json_error(
				array( 'message' => __( 'Cannot clear progress while sync is in progress', 'whatsapp-commerce-hub' ) )
			);
		}

		wp_send_json_success(
			array( 'message' => __( 'Sync progress cleared', 'whatsapp-commerce-hub' ) )
		);
	}

	/**
	 * Format duration in seconds to human-readable string.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Formatted duration.
	 */
	private static function format_duration( int $seconds ): string {
		// Handle negative or zero values.
		if ( $seconds <= 0 ) {
			return __( '0 seconds', 'whatsapp-commerce-hub' );
		}

		if ( $seconds < 60 ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'whatsapp-commerce-hub' ), $seconds );
		}

		$minutes = floor( $seconds / 60 );
		$remaining_seconds = $seconds % 60;

		if ( $minutes < 60 ) {
			if ( $remaining_seconds > 0 ) {
				/* translators: 1: number of minutes, 2: number of seconds */
				return sprintf(
					__( '%1$d min %2$d sec', 'whatsapp-commerce-hub' ),
					$minutes,
					$remaining_seconds
				);
			}
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'whatsapp-commerce-hub' ), $minutes );
		}

		$hours = floor( $minutes / 60 );
		$remaining_minutes = $minutes % 60;

		/* translators: 1: number of hours, 2: number of minutes */
		return sprintf(
			__( '%1$dh %2$dm', 'whatsapp-commerce-hub' ),
			$hours,
			$remaining_minutes
		);
	}
}
