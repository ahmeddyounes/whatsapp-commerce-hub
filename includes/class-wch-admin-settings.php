<?php
/**
 * Admin Settings Page
 *
 * Manages WhatsApp Commerce Hub settings in WordPress admin.
 *
 * @package WhatsApp_Commerce_Hub
 */

defined( 'ABSPATH' ) || exit;

class WCH_Admin_Settings {
	/**
	 * Initialize admin settings page
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ), 50 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_post_wch_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_wch_save_settings_ajax', array( __CLASS__, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wch_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wch_regenerate_verify_token', array( __CLASS__, 'ajax_regenerate_verify_token' ) );
		add_action( 'wp_ajax_wch_sync_catalog', array( __CLASS__, 'ajax_sync_catalog' ) );
		add_action( 'wp_ajax_wch_search_products', array( __CLASS__, 'ajax_search_products' ) );
		add_action( 'wp_ajax_wch_test_notification', array( __CLASS__, 'ajax_test_notification' ) );
		add_action( 'wp_ajax_wch_clear_logs', array( __CLASS__, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_wch_export_settings', array( __CLASS__, 'ajax_export_settings' ) );
		add_action( 'wp_ajax_wch_import_settings', array( __CLASS__, 'ajax_import_settings' ) );
		add_action( 'wp_ajax_wch_reset_settings', array( __CLASS__, 'ajax_reset_settings' ) );
	}

	/**
	 * Add admin menu item
	 */
	public static function add_menu_item() {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'WhatsApp Commerce Hub Settings', 'whatsapp-commerce-hub' ),
			__( 'WhatsApp Commerce Hub', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-settings',
			array( __CLASS__, 'render_page' )
		);

		add_action( 'load-' . $hook, array( __CLASS__, 'add_help_tab' ) );
	}

	/**
	 * Add contextual help tab
	 */
	public static function add_help_tab() {
		$screen = get_current_screen();

		$screen->add_help_tab(
			array(
				'id'      => 'wch_settings_help',
				'title'   => __( 'Settings Help', 'whatsapp-commerce-hub' ),
				'content' => '<p>' . __( 'Configure your WhatsApp Commerce Hub settings here. For detailed documentation, visit:', 'whatsapp-commerce-hub' ) . '</p>' .
							'<ul>' .
							'<li><a href="https://developers.facebook.com/docs/whatsapp/business-platform" target="_blank">' . __( 'WhatsApp Business Platform Documentation', 'whatsapp-commerce-hub' ) . '</a></li>' .
							'<li><a href="https://woocommerce.com/documentation/" target="_blank">' . __( 'WooCommerce Documentation', 'whatsapp-commerce-hub' ) . '</a></li>' .
							'</ul>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'whatsapp-commerce-hub' ) . '</strong></p>' .
			'<p><a href="https://developers.facebook.com/docs/whatsapp" target="_blank">' . __( 'WhatsApp Docs', 'whatsapp-commerce-hub' ) . '</a></p>'
		);
	}

	/**
	 * Enqueue scripts and styles
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wch-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-settings',
			WCH_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'wch-admin-settings',
			WCH_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-settings',
			'wchSettings',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wch_settings_nonce' ),
				'webhook_url' => rest_url( 'wch/v1/webhook' ),
				'strings'     => array(
					'testing'        => __( 'Testing...', 'whatsapp-commerce-hub' ),
					'syncing'        => __( 'Syncing...', 'whatsapp-commerce-hub' ),
					'success'        => __( 'Success', 'whatsapp-commerce-hub' ),
					'error'          => __( 'Error', 'whatsapp-commerce-hub' ),
					'copied'         => __( 'Copied!', 'whatsapp-commerce-hub' ),
					'confirm_reset'  => __( 'Are you sure you want to reset all settings to defaults? This cannot be undone.', 'whatsapp-commerce-hub' ),
					'confirm_clear'  => __( 'Are you sure you want to clear all logs?', 'whatsapp-commerce-hub' ),
					'settings_saved' => __( 'Settings saved successfully', 'whatsapp-commerce-hub' ),
					'settings_error' => __( 'Error saving settings', 'whatsapp-commerce-hub' ),
				),
			)
		);
	}

	/**
	 * Render settings page
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$settings     = WCH_Settings::getInstance();
		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
		$verify_token = $settings->get( 'api.webhook_verify_token' );

		if ( empty( $verify_token ) ) {
			$verify_token = wp_generate_password( 32, false );
			$settings->set( 'api.webhook_verify_token', $verify_token );
		}

		?>
		<div class="wrap wch-settings-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'wch_settings' ); ?>

			<nav class="nav-tab-wrapper wch-nav-tab-wrapper">
				<a href="?page=wch-settings&tab=connection" class="nav-tab <?php echo 'connection' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Connection', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-settings&tab=catalog" class="nav-tab <?php echo 'catalog' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Catalog', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-settings&tab=checkout" class="nav-tab <?php echo 'checkout' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Checkout', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-settings&tab=notifications" class="nav-tab <?php echo 'notifications' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Notifications', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-settings&tab=ai" class="nav-tab <?php echo 'ai' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'AI', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-settings&tab=advanced" class="nav-tab <?php echo 'advanced' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Advanced', 'whatsapp-commerce-hub' ); ?>
				</a>
			</nav>

			<div class="wch-settings-content">
				<form method="post" id="wch-settings-form">
					<?php wp_nonce_field( 'wch_save_settings', 'wch_settings_nonce' ); ?>
					<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

					<?php
					switch ( $active_tab ) {
						case 'connection':
							self::render_connection_tab( $settings );
							break;
						case 'catalog':
							self::render_catalog_tab( $settings );
							break;
						case 'checkout':
							self::render_checkout_tab( $settings );
							break;
						case 'notifications':
							self::render_notifications_tab( $settings );
							break;
						case 'ai':
							self::render_ai_tab( $settings );
							break;
						case 'advanced':
							self::render_advanced_tab( $settings );
							break;
					}
					?>

					<p class="submit">
						<button type="submit" class="button button-primary button-large" id="wch-save-settings">
							<?php esc_html_e( 'Save Settings', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<span class="wch-save-message"></span>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Connection tab
	 */
	private static function render_connection_tab( $settings ) {
		$phone_number_id = $settings->get( 'api.phone_number_id', '' );
		$business_id     = $settings->get( 'api.business_account_id', '' );
		$access_token    = $settings->get( 'api.access_token', '' );
		$verify_token    = $settings->get( 'api.webhook_verify_token', '' );
		$webhook_url     = rest_url( 'wch/v1/webhook' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="phone_number_id"><?php esc_html_e( 'WhatsApp Phone Number ID', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" name="api[phone_number_id]" id="phone_number_id" value="<?php echo esc_attr( $phone_number_id ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Your WhatsApp Business Account Phone Number ID', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="business_account_id"><?php esc_html_e( 'Business Account ID', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" name="api[business_account_id]" id="business_account_id" value="<?php echo esc_attr( $business_id ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Your WhatsApp Business Account ID', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="access_token"><?php esc_html_e( 'Access Token', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="password" name="api[access_token]" id="access_token" value="<?php echo esc_attr( $access_token ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your WhatsApp Business Platform Access Token (stored encrypted)', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="verify_token"><?php esc_html_e( 'Webhook Verify Token', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" id="verify_token" value="<?php echo esc_attr( $verify_token ); ?>" class="regular-text" readonly>
						<button type="button" class="button" id="regenerate-verify-token">
							<?php esc_html_e( 'Regenerate', 'whatsapp-commerce-hub' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Use this token when configuring webhooks in your WhatsApp Business Account', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="webhook_url"><?php esc_html_e( 'Webhook URL', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" id="webhook_url" value="<?php echo esc_url( $webhook_url ); ?>" class="regular-text" readonly>
						<button type="button" class="button" id="copy-webhook-url">
							<?php esc_html_e( 'Copy', 'whatsapp-commerce-hub' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Configure this URL in your WhatsApp Business Account webhook settings', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Test Connection', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="test-connection">
							<?php esc_html_e( 'Test Connection', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<div id="connection-status" class="wch-status-message"></div>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Catalog tab
	 */
	private static function render_catalog_tab( $settings ) {
		$sync_enabled = $settings->get( 'catalog.sync_enabled', false );
		$product_mode = $settings->get( 'catalog.product_selection', 'all' );
		$categories   = $settings->get( 'catalog.categories', array() );
		$products     = $settings->get( 'catalog.products', array() );
		$include_oos  = $settings->get( 'catalog.include_out_of_stock', false );
		$last_sync    = $settings->get( 'catalog.last_sync', '' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Enable Product Sync', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="catalog[sync_enabled]" value="1" <?php checked( $sync_enabled, true ); ?>>
							<?php esc_html_e( 'Enable automatic product catalog synchronization', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Product Selection', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="radio" name="catalog[product_selection]" value="all" <?php checked( $product_mode, 'all' ); ?>>
							<?php esc_html_e( 'All Products', 'whatsapp-commerce-hub' ); ?>
						</label><br>
						<label>
							<input type="radio" name="catalog[product_selection]" value="categories" <?php checked( $product_mode, 'categories' ); ?>>
							<?php esc_html_e( 'Specific Categories', 'whatsapp-commerce-hub' ); ?>
						</label><br>
						<label>
							<input type="radio" name="catalog[product_selection]" value="products" <?php checked( $product_mode, 'products' ); ?>>
							<?php esc_html_e( 'Specific Products', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr class="wch-product-categories" <?php echo 'categories' !== $product_mode ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="catalog_categories"><?php esc_html_e( 'Select Categories', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<?php
						$product_categories = get_terms(
							array(
								'taxonomy'   => 'product_cat',
								'hide_empty' => false,
							)
						);
						if ( ! empty( $product_categories ) && ! is_wp_error( $product_categories ) ) {
							echo '<select name="catalog[categories][]" id="catalog_categories" multiple class="wch-multiselect" style="width: 400px; height: 150px;">';
							foreach ( $product_categories as $category ) {
								$selected = in_array( $category->term_id, (array) $categories, true ) ? 'selected' : '';
								echo '<option value="' . esc_attr( $category->term_id ) . '" ' . $selected . '>' . esc_html( $category->name ) . '</option>';
							}
							echo '</select>';
						}
						?>
						<p class="description"><?php esc_html_e( 'Select which product categories to sync', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-product-products" <?php echo 'products' !== $product_mode ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="catalog_products"><?php esc_html_e( 'Select Products', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="text" id="catalog_products_search" placeholder="<?php esc_attr_e( 'Search products...', 'whatsapp-commerce-hub' ); ?>" class="regular-text">
						<div id="catalog_products_list" class="wch-product-list">
							<?php
							if ( ! empty( $products ) ) {
								foreach ( $products as $product_id ) {
									$product = wc_get_product( $product_id );
									if ( $product ) {
										echo '<div class="wch-product-item" data-id="' . esc_attr( $product_id ) . '">';
										echo esc_html( $product->get_name() );
										echo '<input type="hidden" name="catalog[products][]" value="' . esc_attr( $product_id ) . '">';
										echo '<button type="button" class="button-link-delete wch-remove-product">×</button>';
										echo '</div>';
									}
								}
							}
							?>
						</div>
						<p class="description"><?php esc_html_e( 'Search and select specific products to sync', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Include Out of Stock', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="catalog[include_out_of_stock]" value="1" <?php checked( $include_oos, true ); ?>>
							<?php esc_html_e( 'Include out of stock products in catalog', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Sync Status', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="sync-catalog-now">
							<?php esc_html_e( 'Sync Now', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<div id="sync-progress" class="wch-progress-bar" style="display:none;">
							<div class="wch-progress-fill"></div>
						</div>
						<?php if ( $last_sync ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: last sync date and time */
									esc_html__( 'Last synced: %s', 'whatsapp-commerce-hub' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sync ) ) )
								);
								?>
							</p>
						<?php endif; ?>
						<div id="sync-status" class="wch-status-message"></div>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Checkout tab
	 */
	private static function render_checkout_tab( $settings ) {
		$enabled_methods  = $settings->get( 'checkout.enabled_payment_methods', array() );
		$cod_enabled      = $settings->get( 'checkout.cod_enabled', false );
		$cod_extra_charge = $settings->get( 'checkout.cod_extra_charge', 0 );
		$min_order        = $settings->get( 'checkout.min_order_amount', 0 );
		$max_order        = $settings->get( 'checkout.max_order_amount', 0 );
		$phone_verify     = $settings->get( 'checkout.phone_verification', false );

		$payment_manager    = WCH_Payment_Manager::getInstance();
		$available_gateways = $payment_manager->get_available_gateways();
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Enabled Payment Methods', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<?php foreach ( $available_gateways as $gateway_id => $gateway ) : ?>
							<label>
								<input type="checkbox" name="checkout[enabled_payment_methods][]" value="<?php echo esc_attr( $gateway_id ); ?>" <?php checked( in_array( $gateway_id, (array) $enabled_methods, true ) ); ?>>
								<?php echo esc_html( $gateway->get_title() ); ?>
							</label><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Select which payment methods are available for WhatsApp checkout', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Cash on Delivery', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="checkout[cod_enabled]" value="1" <?php checked( $cod_enabled, true ); ?>>
							<?php esc_html_e( 'Enable Cash on Delivery', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr class="wch-cod-settings" <?php echo ! $cod_enabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="cod_extra_charge"><?php esc_html_e( 'COD Extra Charge', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="number" name="checkout[cod_extra_charge]" id="cod_extra_charge" value="<?php echo esc_attr( $cod_extra_charge ); ?>" step="0.01" min="0" class="small-text">
						<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<p class="description"><?php esc_html_e( 'Additional charge for Cash on Delivery orders', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Order Limits', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label for="min_order_amount"><?php esc_html_e( 'Minimum Order Amount:', 'whatsapp-commerce-hub' ); ?></label>
						<input type="number" name="checkout[min_order_amount]" id="min_order_amount" value="<?php echo esc_attr( $min_order ); ?>" step="0.01" min="0" class="small-text">
						<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<br><br>
						<label for="max_order_amount"><?php esc_html_e( 'Maximum Order Amount:', 'whatsapp-commerce-hub' ); ?></label>
						<input type="number" name="checkout[max_order_amount]" id="max_order_amount" value="<?php echo esc_attr( $max_order ); ?>" step="0.01" min="0" class="small-text">
						<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
						<p class="description"><?php esc_html_e( 'Set minimum and maximum order amounts (0 for no limit)', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Phone Verification', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="checkout[phone_verification]" value="1" <?php checked( $phone_verify, true ); ?>>
							<?php esc_html_e( 'Require phone number verification for checkout', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Notifications tab
	 */
	private static function render_notifications_tab( $settings ) {
		$notification_types = array(
			'order_confirmation' => __( 'Order Confirmation', 'whatsapp-commerce-hub' ),
			'status_updates'     => __( 'Order Status Updates', 'whatsapp-commerce-hub' ),
			'shipping'           => __( 'Shipping Notifications', 'whatsapp-commerce-hub' ),
			'abandoned_cart'     => __( 'Abandoned Cart Reminders', 'whatsapp-commerce-hub' ),
		);

		$cart_delay = $settings->get( 'notifications.abandoned_cart_delay', 24 );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Notification Types', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<?php foreach ( $notification_types as $type => $label ) : ?>
							<?php $enabled = $settings->get( "notifications.{$type}_enabled", true ); ?>
							<div class="wch-notification-row">
								<label>
									<input type="checkbox" name="notifications[<?php echo esc_attr( $type ); ?>_enabled]" value="1" <?php checked( $enabled, true ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
								<button type="button" class="button button-small wch-test-notification" data-type="<?php echo esc_attr( $type ); ?>">
									<?php esc_html_e( 'Test', 'whatsapp-commerce-hub' ); ?>
								</button>
								<span class="spinner"></span>
								<span class="wch-test-result"></span>
							</div>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="abandoned_cart_delay"><?php esc_html_e( 'Abandoned Cart Delay', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="number" name="notifications[abandoned_cart_delay]" id="abandoned_cart_delay" value="<?php echo esc_attr( $cart_delay ); ?>" min="1" max="168" class="small-text">
						<?php esc_html_e( 'hours', 'whatsapp-commerce-hub' ); ?>
						<p class="description"><?php esc_html_e( 'How long to wait before sending abandoned cart reminder (1-168 hours)', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render AI tab
	 */
	private static function render_ai_tab( $settings ) {
		$ai_enabled    = $settings->get( 'ai.enabled', false );
		$openai_key    = $settings->get( 'ai.openai_api_key', '' );
		$model         = $settings->get( 'ai.model', 'gpt-4' );
		$temperature   = $settings->get( 'ai.temperature', 0.7 );
		$system_prompt = $settings->get( 'ai.system_prompt', '' );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Enable AI Assistant', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="ai[enabled]" value="1" <?php checked( $ai_enabled, true ); ?>>
							<?php esc_html_e( 'Enable AI-powered customer assistant', 'whatsapp-commerce-hub' ); ?>
						</label>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $ai_enabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="password" name="ai[openai_api_key]" id="openai_api_key" value="<?php echo esc_attr( $openai_key ); ?>" class="regular-text" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Your OpenAI API key (stored encrypted)', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $ai_enabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ai_model"><?php esc_html_e( 'AI Model', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<select name="ai[model]" id="ai_model">
							<option value="gpt-4" <?php selected( $model, 'gpt-4' ); ?>>GPT-4</option>
							<option value="gpt-3.5-turbo" <?php selected( $model, 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo</option>
						</select>
						<p class="description"><?php esc_html_e( 'Select the OpenAI model to use', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $ai_enabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="ai_temperature"><?php esc_html_e( 'Temperature', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="range" name="ai[temperature]" id="ai_temperature" min="0" max="1" step="0.1" value="<?php echo esc_attr( $temperature ); ?>">
						<span id="temperature-value"><?php echo esc_html( $temperature ); ?></span>
						<p class="description"><?php esc_html_e( 'Controls randomness: 0 = focused, 1 = creative', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr class="wch-ai-settings" <?php echo ! $ai_enabled ? 'style="display:none;"' : ''; ?>>
					<th scope="row">
						<label for="system_prompt"><?php esc_html_e( 'Custom System Prompt', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<textarea name="ai[system_prompt]" id="system_prompt" rows="8" class="large-text"><?php echo esc_textarea( $system_prompt ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Custom instructions for the AI assistant behavior', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Advanced tab
	 */
	private static function render_advanced_tab( $settings ) {
		$debug_mode    = $settings->get( 'advanced.debug_mode', false );
		$log_retention = $settings->get( 'advanced.log_retention_days', 30 );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Debug Mode', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="advanced[debug_mode]" value="1" <?php checked( $debug_mode, true ); ?>>
							<?php esc_html_e( 'Enable debug logging', 'whatsapp-commerce-hub' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Log detailed information for troubleshooting', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="log_retention_days"><?php esc_html_e( 'Log Retention', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="number" name="advanced[log_retention_days]" id="log_retention_days" value="<?php echo esc_attr( $log_retention ); ?>" min="1" max="365" class="small-text">
						<?php esc_html_e( 'days', 'whatsapp-commerce-hub' ); ?>
						<p class="description"><?php esc_html_e( 'Number of days to keep log files', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Logs', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="clear-logs">
							<?php esc_html_e( 'Clear All Logs', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<p class="description"><?php esc_html_e( 'Remove all log files', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Export Settings', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button" id="export-settings">
							<?php esc_html_e( 'Export Settings', 'whatsapp-commerce-hub' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Download settings as JSON file', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="import-settings-file"><?php esc_html_e( 'Import Settings', 'whatsapp-commerce-hub' ); ?></label>
					</th>
					<td>
						<input type="file" id="import-settings-file" accept=".json">
						<button type="button" class="button" id="import-settings">
							<?php esc_html_e( 'Import Settings', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<p class="description"><?php esc_html_e( 'Upload and restore settings from JSON file', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Reset Settings', 'whatsapp-commerce-hub' ); ?>
					</th>
					<td>
						<button type="button" class="button button-secondary" id="reset-settings">
							<?php esc_html_e( 'Reset to Defaults', 'whatsapp-commerce-hub' ); ?>
						</button>
						<span class="spinner"></span>
						<p class="description"><?php esc_html_e( 'Reset all settings to default values', 'whatsapp-commerce-hub' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle settings form submission
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied', 'whatsapp-commerce-hub' ) );
		}

		check_admin_referer( 'wch_save_settings', 'wch_settings_nonce' );

		$settings   = WCH_Settings::getInstance();
		$active_tab = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : 'connection';

		// Process each tab's settings
		$sections = array( 'api', 'catalog', 'checkout', 'notifications', 'ai', 'advanced' );

		foreach ( $sections as $section ) {
			if ( isset( $_POST[ $section ] ) && is_array( $_POST[ $section ] ) ) {
				foreach ( $_POST[ $section ] as $key => $value ) {
					$setting_key = $section . '.' . sanitize_key( $key );
					$settings->set( $setting_key, self::sanitize_setting( $value, $key ) );
				}
			}
		}

		add_settings_error(
			'wch_settings',
			'settings_updated',
			__( 'Settings saved successfully.', 'whatsapp-commerce-hub' ),
			'success'
		);

		set_transient( 'settings_errors', get_settings_errors(), 30 );

		$redirect_url = add_query_arg(
			array(
				'page'             => 'wch-settings',
				'tab'              => $active_tab,
				'settings-updated' => 'true',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Sanitize setting value based on type
	 */
	private static function sanitize_setting( $value, $key ) {
		// Handle arrays
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}

		// Handle checkboxes
		if ( in_array(
			$key,
			array(
				'sync_enabled',
				'include_out_of_stock',
				'cod_enabled',
				'phone_verification',
				'enabled',
				'debug_mode',
				'order_confirmation_enabled',
				'status_updates_enabled',
				'shipping_enabled',
				'abandoned_cart_enabled',
			),
			true
		) ) {
			return (bool) $value;
		}

		// Handle numeric values
		if ( in_array(
			$key,
			array(
				'cod_extra_charge',
				'min_order_amount',
				'max_order_amount',
				'abandoned_cart_delay',
				'log_retention_days',
				'temperature',
			),
			true
		) ) {
			return floatval( $value );
		}

		// Handle text areas
		if ( 'system_prompt' === $key ) {
			return sanitize_textarea_field( $value );
		}

		// Default: sanitize as text
		return sanitize_text_field( $value );
	}

	/**
	 * AJAX: Save settings
	 */
	public static function ajax_save_settings() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$settings   = WCH_Settings::getInstance();
		$active_tab = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : 'connection';

		// Process each tab's settings
		$sections = array( 'api', 'catalog', 'checkout', 'notifications', 'ai', 'advanced' );

		foreach ( $sections as $section ) {
			if ( isset( $_POST[ $section ] ) && is_array( $_POST[ $section ] ) ) {
				foreach ( $_POST[ $section ] as $key => $value ) {
					$setting_key = $section . '.' . sanitize_key( $key );
					$settings->set( $setting_key, self::sanitize_setting( $value, $key ) );
				}
			}
		}

		wp_send_json_success( array( 'message' => __( 'Settings saved successfully.', 'whatsapp-commerce-hub' ) ) );
	}

	/**
	 * AJAX: Search products
	 */
	public static function ajax_search_products() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';

		if ( empty( $query ) ) {
			wp_send_json_error( array( 'message' => __( 'Search query is required', 'whatsapp-commerce-hub' ) ) );
		}

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => 20,
			's'              => $query,
			'post_status'    => 'publish',
		);

		$products_query = new WP_Query( $args );
		$products       = array();

		if ( $products_query->have_posts() ) {
			while ( $products_query->have_posts() ) {
				$products_query->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( $product ) {
					$products[] = array(
						'id'    => $product->get_id(),
						'name'  => $product->get_name(),
						'sku'   => $product->get_sku(),
						'price' => $product->get_price(),
					);
				}
			}
			wp_reset_postdata();
		}

		wp_send_json_success( array( 'products' => $products ) );
	}

	/**
	 * AJAX: Test WhatsApp connection
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$settings = WCH_Settings::getInstance();
		$api      = new WCH_WhatsApp_API_Client();

		try {
			$response = $api->get_business_profile();

			if ( $response && isset( $response['data'] ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'Connection successful!', 'whatsapp-commerce-hub' ),
						'profile' => $response['data'],
					)
				);
			} else {
				wp_send_json_error( array( 'message' => __( 'Connection failed. Please check your credentials.', 'whatsapp-commerce-hub' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Regenerate verify token
	 */
	public static function ajax_regenerate_verify_token() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$settings  = WCH_Settings::getInstance();
		$new_token = wp_generate_password( 32, false );

		$settings->set( 'api.webhook_verify_token', $new_token );

		wp_send_json_success( array( 'token' => $new_token ) );
	}

	/**
	 * AJAX: Sync catalog
	 */
	public static function ajax_sync_catalog() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			$catalog_sync = WCH_Product_Sync_Service::instance();
			$catalog_sync->sync_all_products();

			$settings = WCH_Settings::getInstance();
			$settings->set( 'catalog.last_sync', current_time( 'mysql' ) );

			wp_send_json_success(
				array(
					'message' => __( 'Product sync has been queued for processing', 'whatsapp-commerce-hub' ),
					'result'  => array(
						'timestamp' => current_time( 'mysql' ),
					),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Test notification
	 */
	public static function ajax_test_notification() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

		if ( empty( $type ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid notification type', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			$current_user = wp_get_current_user();
			$phone        = get_user_meta( $current_user->ID, 'billing_phone', true );

			if ( empty( $phone ) ) {
				wp_send_json_error( array( 'message' => __( 'No phone number found for current user', 'whatsapp-commerce-hub' ) ) );
			}

			$api     = new WCH_WhatsApp_API_Client();
			$message = sprintf(
				/* translators: %s: notification type */
				__( 'This is a test %s notification from WhatsApp Commerce Hub.', 'whatsapp-commerce-hub' ),
				str_replace( '_', ' ', $type )
			);

			$api->send_text_message( $phone, $message );

			wp_send_json_success( array( 'message' => __( 'Test notification sent successfully', 'whatsapp-commerce-hub' ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Clear logs
	 */
	public static function ajax_clear_logs() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			WCH_Logger::clear_all_logs();
			wp_send_json_success( array( 'message' => __( 'All logs cleared successfully', 'whatsapp-commerce-hub' ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Export settings
	 */
	public static function ajax_export_settings() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		$settings     = WCH_Settings::getInstance();
		$all_settings = $settings->get_all();

		// Remove sensitive data
		$export_settings = $all_settings;
		unset( $export_settings['api']['access_token'] );
		unset( $export_settings['api']['webhook_verify_token'] );
		unset( $export_settings['ai']['openai_api_key'] );

		wp_send_json_success(
			array(
				'settings' => $export_settings,
				'filename' => 'wch-settings-' . gmdate( 'Y-m-d-H-i-s' ) . '.json',
			)
		);
	}

	/**
	 * Get whitelist of allowed import settings per section.
	 *
	 * @return array Associative array of section => allowed keys.
	 */
	private static function get_importable_settings_whitelist() {
		return array(
			'general'       => array(
				'enable_bot',
				'business_name',
				'welcome_message',
				'fallback_message',
				'operating_hours',
				'timezone',
			),
			'catalog'       => array(
				'sync_enabled',
				'sync_products',
				'include_out_of_stock',
				'price_format',
				'currency_symbol',
			),
			'checkout'      => array(
				'enabled_payment_methods',
				'cod_enabled',
				'cod_extra_charge',
				'min_order_amount',
				'max_order_amount',
				'require_phone_verification',
			),
			'notifications' => array(
				'order_confirmation',
				'order_status_updates',
				'shipping_updates',
				'abandoned_cart_reminder',
				'abandoned_cart_delay_hours',
			),
			'inventory'     => array(
				'enable_realtime_sync',
				'low_stock_threshold',
				'notify_low_stock',
				'auto_fix_discrepancies',
			),
			'ai'            => array(
				'enable_ai',
				'ai_model',
				'ai_temperature',
				'ai_max_tokens',
				'ai_system_prompt',
				'monthly_budget_cap',
				// Note: openai_api_key is intentionally excluded (sensitive)
			),
			'recovery'      => array(
				'enabled',
				'delay_sequence_1',
				'delay_sequence_2',
				'delay_sequence_3',
				'template_sequence_1',
				'template_sequence_2',
				'template_sequence_3',
				'discount_enabled',
				'discount_type',
				'discount_amount',
			),
			// Note: 'api' section is intentionally excluded (contains sensitive credentials)
		);
	}

	/**
	 * AJAX: Import settings
	 */
	public static function ajax_import_settings() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		if ( ! isset( $_POST['settings'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No settings data provided', 'whatsapp-commerce-hub' ) ) );
		}

		$import_data = json_decode( wp_unslash( $_POST['settings'] ), true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON format', 'whatsapp-commerce-hub' ) ) );
		}

		// Get the whitelist of importable settings.
		$whitelist = self::get_importable_settings_whitelist();

		try {
			$settings        = WCH_Settings::getInstance();
			$imported_count  = 0;
			$skipped_count   = 0;
			$skipped_reasons = array();

			foreach ( $import_data as $section => $values ) {
				// Reject unknown sections.
				if ( ! isset( $whitelist[ $section ] ) ) {
					$skipped_count++;
					$skipped_reasons[] = sprintf(
						/* translators: %s is section name */
						__( 'Section "%s" is not allowed for import', 'whatsapp-commerce-hub' ),
						sanitize_text_field( $section )
					);
					continue;
				}

				if ( ! is_array( $values ) ) {
					$skipped_count++;
					continue;
				}

				$allowed_keys = $whitelist[ $section ];

				foreach ( $values as $key => $value ) {
					// Reject keys not in whitelist for this section.
					if ( ! in_array( $key, $allowed_keys, true ) ) {
						$skipped_count++;
						continue;
					}

					// Sanitize value based on expected type.
					$sanitized_value = self::sanitize_import_value( $section, $key, $value );

					if ( null !== $sanitized_value ) {
						$settings->set( $section . '.' . $key, $sanitized_value );
						$imported_count++;
					} else {
						$skipped_count++;
					}
				}
			}

			$message = sprintf(
				/* translators: 1: imported count, 2: skipped count */
				__( 'Settings imported: %1$d, Skipped: %2$d', 'whatsapp-commerce-hub' ),
				$imported_count,
				$skipped_count
			);

			wp_send_json_success( array( 'message' => $message ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Sanitize an import value based on expected type.
	 *
	 * @param string $section The settings section.
	 * @param string $key     The setting key.
	 * @param mixed  $value   The value to sanitize.
	 * @return mixed|null Sanitized value or null if invalid.
	 */
	private static function sanitize_import_value( $section, $key, $value ) {
		// Boolean fields.
		$boolean_fields = array(
			'enable_bot',
			'sync_enabled',
			'include_out_of_stock',
			'cod_enabled',
			'require_phone_verification',
			'order_confirmation',
			'order_status_updates',
			'shipping_updates',
			'abandoned_cart_reminder',
			'enable_realtime_sync',
			'notify_low_stock',
			'auto_fix_discrepancies',
			'enable_ai',
			'enabled',
			'discount_enabled',
		);

		// Numeric fields.
		$numeric_fields = array(
			'cod_extra_charge',
			'min_order_amount',
			'max_order_amount',
			'abandoned_cart_delay_hours',
			'low_stock_threshold',
			'ai_temperature',
			'ai_max_tokens',
			'monthly_budget_cap',
			'delay_sequence_1',
			'delay_sequence_2',
			'delay_sequence_3',
			'discount_amount',
		);

		// Array fields.
		$array_fields = array(
			'operating_hours',
			'enabled_payment_methods',
		);

		// Enum fields with allowed values.
		$enum_fields = array(
			'sync_products'  => array( 'all', 'published', 'selected' ),
			'ai_model'       => array( 'gpt-3.5-turbo', 'gpt-4', 'gpt-4-turbo' ),
			'discount_type'  => array( 'percent', 'fixed' ),
		);

		if ( in_array( $key, $boolean_fields, true ) ) {
			return (bool) $value;
		}

		if ( in_array( $key, $numeric_fields, true ) ) {
			if ( ! is_numeric( $value ) ) {
				return null;
			}
			return floatval( $value );
		}

		if ( in_array( $key, $array_fields, true ) ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			// Sanitize array values recursively.
			return array_map( 'sanitize_text_field', $value );
		}

		if ( isset( $enum_fields[ $key ] ) ) {
			if ( ! in_array( $value, $enum_fields[ $key ], true ) ) {
				return null;
			}
			return $value;
		}

		// Default: treat as string and sanitize.
		if ( is_string( $value ) ) {
			// Allow HTML in specific fields (like messages).
			$html_allowed_fields = array( 'welcome_message', 'fallback_message', 'ai_system_prompt' );
			if ( in_array( $key, $html_allowed_fields, true ) ) {
				return wp_kses_post( $value );
			}
			return sanitize_text_field( $value );
		}

		// Reject other types.
		return null;
	}

	/**
	 * AJAX: Reset settings to defaults
	 */
	public static function ajax_reset_settings() {
		check_ajax_referer( 'wch_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		try {
			delete_option( 'wch_settings' );
			delete_option( 'wch_settings_schema_version' );

			wp_send_json_success( array( 'message' => __( 'Settings reset to defaults successfully', 'whatsapp-commerce-hub' ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
