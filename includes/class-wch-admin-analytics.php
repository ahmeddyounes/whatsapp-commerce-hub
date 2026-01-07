<?php
/**
 * Admin Analytics Page
 *
 * Manages WhatsApp Commerce Analytics dashboard in WordPress admin.
 *
 * @package WhatsApp_Commerce_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * WCH_Admin_Analytics class.
 */
class WCH_Admin_Analytics {

	/**
	 * Initialize admin analytics page
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_item' ), 51 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wch_refresh_analytics', array( __CLASS__, 'ajax_refresh_analytics' ) );
	}

	/**
	 * Add admin menu item
	 */
	public static function add_menu_item() {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'WhatsApp Analytics', 'whatsapp-commerce-hub' ),
			__( 'WhatsApp Analytics', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-analytics',
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
				'id'      => 'wch_analytics_help',
				'title'   => __( 'Analytics Help', 'whatsapp-commerce-hub' ),
				'content' => '<p>' . __( 'View WhatsApp Commerce performance analytics. Metrics include orders, revenue, conversations, and customer insights.', 'whatsapp-commerce-hub' ) . '</p>' .
							'<ul>' .
							'<li>' . __( 'Data refreshes automatically every 5 minutes', 'whatsapp-commerce-hub' ) . '</li>' .
							'<li>' . __( 'Use filters to change date ranges and compare periods', 'whatsapp-commerce-hub' ) . '</li>' .
							'<li>' . __( 'Export data to CSV for detailed analysis', 'whatsapp-commerce-hub' ) . '</li>' .
							'</ul>',
			)
		);
	}

	/**
	 * Enqueue scripts and styles
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wch-analytics' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-analytics',
			WCH_PLUGIN_URL . 'assets/css/admin-analytics.css',
			array(),
			WCH_VERSION
		);

		wp_enqueue_script(
			'chart-js',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
			array(),
			'4.4.0',
			true
		);

		wp_enqueue_script(
			'wch-admin-analytics',
			WCH_PLUGIN_URL . 'assets/js/admin-analytics.js',
			array( 'jquery', 'chart-js' ),
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-analytics',
			'wchAnalytics',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'rest_url'    => rest_url( 'wch/v1/analytics/' ),
				'nonce'       => wp_create_nonce( 'wch_analytics_nonce' ),
				'currency'    => get_woocommerce_currency_symbol(),
				'dateFormat'  => get_option( 'date_format' ),
				'strings'     => array(
					'loading'        => __( 'Loading...', 'whatsapp-commerce-hub' ),
					'error'          => __( 'Error loading data', 'whatsapp-commerce-hub' ),
					'no_data'        => __( 'No data available', 'whatsapp-commerce-hub' ),
					'export_success' => __( 'Export successful', 'whatsapp-commerce-hub' ),
					'export_error'   => __( 'Export failed', 'whatsapp-commerce-hub' ),
				),
			)
		);
	}

	/**
	 * Render analytics page
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		?>
		<div class="wrap wch-analytics-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper wch-nav-tab-wrapper">
				<a href="?page=wch-analytics&tab=overview" class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-analytics&tab=orders" class="nav-tab <?php echo 'orders' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Orders & Revenue', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-analytics&tab=conversations" class="nav-tab <?php echo 'conversations' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Conversations', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-analytics&tab=customers" class="nav-tab <?php echo 'customers' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Customers', 'whatsapp-commerce-hub' ); ?>
				</a>
				<a href="?page=wch-analytics&tab=products" class="nav-tab <?php echo 'products' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Products', 'whatsapp-commerce-hub' ); ?>
				</a>
			</nav>

			<div class="wch-analytics-controls">
				<div class="wch-date-filter">
					<select id="wch-period-select" class="wch-select">
						<option value="today"><?php esc_html_e( 'Today', 'whatsapp-commerce-hub' ); ?></option>
						<option value="week" selected><?php esc_html_e( 'Last 7 Days', 'whatsapp-commerce-hub' ); ?></option>
						<option value="month"><?php esc_html_e( 'Last 30 Days', 'whatsapp-commerce-hub' ); ?></option>
					</select>
				</div>

				<button type="button" class="button wch-refresh-btn" id="wch-refresh-analytics">
					<?php esc_html_e( 'Refresh', 'whatsapp-commerce-hub' ); ?>
				</button>

				<button type="button" class="button wch-export-btn" id="wch-export-analytics">
					<?php esc_html_e( 'Export CSV', 'whatsapp-commerce-hub' ); ?>
				</button>
			</div>

			<div class="wch-analytics-content">
				<?php
				switch ( $active_tab ) {
					case 'overview':
						self::render_overview_tab();
						break;
					case 'orders':
						self::render_orders_tab();
						break;
					case 'conversations':
						self::render_conversations_tab();
						break;
					case 'customers':
						self::render_customers_tab();
						break;
					case 'products':
						self::render_products_tab();
						break;
					default:
						self::render_overview_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render overview tab
	 */
	private static function render_overview_tab() {
		?>
		<div class="wch-analytics-tab" data-tab="overview">
			<div class="wch-metrics-grid">
				<div class="wch-metric-card" id="metric-total-orders">
					<div class="wch-metric-icon">📦</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Total Orders', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="total_orders">--</div>
						<div class="wch-metric-label"><?php esc_html_e( 'via WhatsApp', 'whatsapp-commerce-hub' ); ?></div>
					</div>
				</div>

				<div class="wch-metric-card" id="metric-revenue">
					<div class="wch-metric-icon">💰</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Revenue', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="total_revenue">--</div>
						<div class="wch-metric-label"><?php esc_html_e( 'from WhatsApp orders', 'whatsapp-commerce-hub' ); ?></div>
					</div>
				</div>

				<div class="wch-metric-card" id="metric-conversations">
					<div class="wch-metric-icon">💬</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Active Conversations', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="active_conversations">--</div>
						<div class="wch-metric-label"><?php esc_html_e( 'currently open', 'whatsapp-commerce-hub' ); ?></div>
					</div>
				</div>

				<div class="wch-metric-card" id="metric-conversion">
					<div class="wch-metric-icon">📈</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Conversion Rate', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="conversion_rate">--</div>
						<div class="wch-metric-label"><?php esc_html_e( 'orders / conversations', 'whatsapp-commerce-hub' ); ?></div>
					</div>
				</div>
			</div>

			<div class="wch-charts-row">
				<div class="wch-chart-container wch-chart-half">
					<h3><?php esc_html_e( 'Orders Over Time', 'whatsapp-commerce-hub' ); ?></h3>
					<canvas id="chart-orders-over-time"></canvas>
				</div>

				<div class="wch-chart-container wch-chart-half">
					<h3><?php esc_html_e( 'Revenue by Day', 'whatsapp-commerce-hub' ); ?></h3>
					<canvas id="chart-revenue-by-day"></canvas>
				</div>
			</div>

			<div class="wch-charts-row">
				<div class="wch-chart-container">
					<h3><?php esc_html_e( 'Conversion Funnel', 'whatsapp-commerce-hub' ); ?></h3>
					<canvas id="chart-funnel"></canvas>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render orders tab
	 */
	private static function render_orders_tab() {
		?>
		<div class="wch-analytics-tab" data-tab="orders">
			<div class="wch-metrics-grid">
				<div class="wch-metric-card">
					<div class="wch-metric-icon">📊</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Avg Order Value (WhatsApp)', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="avg_order_value">--</div>
					</div>
				</div>

				<div class="wch-metric-card">
					<div class="wch-metric-icon">🌐</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Avg Order Value (Website)', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="avg_order_value_web">--</div>
					</div>
				</div>

				<div class="wch-metric-card">
					<div class="wch-metric-icon">🛒</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Cart Abandonment Rate', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="cart_abandonment_rate">--</div>
					</div>
				</div>
			</div>

			<div class="wch-charts-row">
				<div class="wch-chart-container">
					<h3><?php esc_html_e( 'Orders & Revenue Trend', 'whatsapp-commerce-hub' ); ?></h3>
					<canvas id="chart-orders-revenue-trend"></canvas>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render conversations tab
	 */
	private static function render_conversations_tab() {
		?>
		<div class="wch-analytics-tab" data-tab="conversations">
			<div class="wch-metrics-grid">
				<div class="wch-metric-card">
					<div class="wch-metric-icon">⏱️</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Avg Response Time', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="avg_response_time">--</div>
						<div class="wch-metric-label"><?php esc_html_e( 'seconds', 'whatsapp-commerce-hub' ); ?></div>
					</div>
				</div>

				<div class="wch-metric-card">
					<div class="wch-metric-icon">📥</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Inbound Messages', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="message_volume_inbound">--</div>
					</div>
				</div>

				<div class="wch-metric-card">
					<div class="wch-metric-icon">📤</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Outbound Messages', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="message_volume_outbound">--</div>
					</div>
				</div>
			</div>

			<div class="wch-charts-row">
				<div class="wch-chart-container">
					<h3><?php esc_html_e( 'Conversation Volume Heatmap', 'whatsapp-commerce-hub' ); ?></h3>
					<div id="heatmap-conversations" class="wch-heatmap"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render customers tab
	 */
	private static function render_customers_tab() {
		?>
		<div class="wch-analytics-tab" data-tab="customers">
			<div class="wch-metrics-grid">
				<div class="wch-metric-card">
					<div class="wch-metric-icon">🆕</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'New Customers', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="new_customers">--</div>
					</div>
				</div>

				<div class="wch-metric-card">
					<div class="wch-metric-icon">🔄</div>
					<div class="wch-metric-content">
						<h3><?php esc_html_e( 'Returning Customers', 'whatsapp-commerce-hub' ); ?></h3>
						<div class="wch-metric-value" data-metric="returning_customers">--</div>
					</div>
				</div>
			</div>

			<div class="wch-charts-row">
				<div class="wch-chart-container wch-chart-half">
					<h3><?php esc_html_e( 'New vs Returning', 'whatsapp-commerce-hub' ); ?></h3>
					<canvas id="chart-customer-split"></canvas>
				</div>

				<div class="wch-chart-container wch-chart-half">
					<h3><?php esc_html_e( 'Top Customers', 'whatsapp-commerce-hub' ); ?></h3>
					<div id="top-customers-list" class="wch-table-container"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render products tab
	 */
	private static function render_products_tab() {
		?>
		<div class="wch-analytics-tab" data-tab="products">
			<div class="wch-charts-row">
				<div class="wch-chart-container">
					<h3><?php esc_html_e( 'Top Products via WhatsApp', 'whatsapp-commerce-hub' ); ?></h3>
					<canvas id="chart-top-products"></canvas>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Refresh analytics data
	 */
	public static function ajax_refresh_analytics() {
		check_ajax_referer( 'wch_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ) );
		}

		WCH_Analytics_Data::clear_caches();

		wp_send_json_success( array( 'message' => __( 'Analytics cache cleared', 'whatsapp-commerce-hub' ) ) );
	}
}
