<?php
/**
 * Admin Analytics Page
 *
 * Provides admin interface for WhatsApp Commerce Analytics.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Pages;

use WhatsAppCommerceHub\Features\Analytics\AnalyticsData;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.MaxExceeded
// Long lines in inline CSS styles are acceptable for readability.

/**
 * Class AnalyticsPage
 *
 * Handles the admin interface for viewing WhatsApp Commerce analytics.
 */
class AnalyticsPage {

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private string $pageHook = '';

	/**
	 * Initialize the analytics page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'addMenuItem' ], 51 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueueScripts' ] );
		add_action( 'wp_ajax_wch_refresh_analytics', [ $this, 'ajaxRefreshAnalytics' ] );
	}

	/**
	 * Add menu item under WooCommerce.
	 *
	 * @return void
	 */
	public function addMenuItem(): void {
		$this->pageHook = add_submenu_page(
			'woocommerce',
			__( 'WhatsApp Analytics', 'whatsapp-commerce-hub' ),
			__( 'WhatsApp Analytics', 'whatsapp-commerce-hub' ),
			'manage_woocommerce',
			'wch-analytics',
			[ $this, 'renderPage' ]
		);

		add_action( 'load-' . $this->pageHook, [ $this, 'addHelpTab' ] );
	}

	/**
	 * Add contextual help tab.
	 *
	 * @return void
	 */
	public function addHelpTab(): void {
		$screen = get_current_screen();

		$screen->add_help_tab(
			[
				'id'      => 'wch_analytics_help',
				'title'   => __( 'Analytics Help', 'whatsapp-commerce-hub' ),
				'content' => '<p>' . __( 'View WhatsApp Commerce performance analytics. Metrics include orders, revenue, conversations, and customer insights.', 'whatsapp-commerce-hub' ) . '</p>' .
							'<ul>' .
							'<li>' . __( 'Data refreshes automatically every 5 minutes', 'whatsapp-commerce-hub' ) . '</li>' .
							'<li>' . __( 'Use filters to change date ranges and compare periods', 'whatsapp-commerce-hub' ) . '</li>' .
							'<li>' . __( 'Export data to CSV for detailed analysis', 'whatsapp-commerce-hub' ) . '</li>' .
							'</ul>',
			]
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( 'woocommerce_page_wch-analytics' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wch-admin-analytics',
			WCH_PLUGIN_URL . 'assets/css/admin-analytics.css',
			[],
			WCH_VERSION
		);

		wp_enqueue_script(
			'chart-js',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
			[],
			'4.4.0',
			true
		);

		wp_enqueue_script(
			'wch-admin-analytics',
			WCH_PLUGIN_URL . 'assets/js/admin-analytics.js',
			[ 'jquery', 'chart-js' ],
			WCH_VERSION,
			true
		);

		wp_localize_script(
			'wch-admin-analytics',
			'wchAnalytics',
			[
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'rest_url'   => rest_url( 'wch/v1/analytics/' ),
				'nonce'      => wp_create_nonce( 'wch_analytics_nonce' ),
				'currency'   => get_woocommerce_currency_symbol(),
				'dateFormat' => get_option( 'date_format' ),
				'strings'    => [
					'loading'        => __( 'Loading...', 'whatsapp-commerce-hub' ),
					'error'          => __( 'Error loading data', 'whatsapp-commerce-hub' ),
					'no_data'        => __( 'No data available', 'whatsapp-commerce-hub' ),
					'export_success' => __( 'Export successful', 'whatsapp-commerce-hub' ),
					'export_error'   => __( 'Export failed', 'whatsapp-commerce-hub' ),
				],
			]
		);
	}

	/**
	 * Render the analytics page.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'whatsapp-commerce-hub' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation is read-only.
		$activeTab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

		$this->renderPageHtml( $activeTab );
	}

	/**
	 * Render the page HTML.
	 *
	 * @param string $activeTab Active tab.
	 * @return void
	 */
	private function renderPageHtml( string $activeTab ): void {
		?>
		<div class="wrap wch-analytics-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php $this->renderNavTabs( $activeTab ); ?>
			<?php $this->renderControls(); ?>

			<div class="wch-analytics-content">
				<?php $this->renderTab( $activeTab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render navigation tabs.
	 *
	 * @param string $activeTab Active tab.
	 * @return void
	 */
	private function renderNavTabs( string $activeTab ): void {
		$tabs = [
			'overview'      => __( 'Overview', 'whatsapp-commerce-hub' ),
			'orders'        => __( 'Orders & Revenue', 'whatsapp-commerce-hub' ),
			'conversations' => __( 'Conversations', 'whatsapp-commerce-hub' ),
			'customers'     => __( 'Customers', 'whatsapp-commerce-hub' ),
			'products'      => __( 'Products', 'whatsapp-commerce-hub' ),
		];
		?>
		<nav class="nav-tab-wrapper wch-nav-tab-wrapper">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<a href="?page=wch-analytics&tab=<?php echo esc_attr( $tab ); ?>"
					class="nav-tab <?php echo esc_attr( $tab === $activeTab ? 'nav-tab-active' : '' ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render analytics controls.
	 *
	 * @return void
	 */
	private function renderControls(): void {
		?>
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
		<?php
	}

	/**
	 * Render the appropriate tab content.
	 *
	 * @param string $tab Tab name.
	 * @return void
	 */
	private function renderTab( string $tab ): void {
		switch ( $tab ) {
			case 'overview':
				$this->renderOverviewTab();
				break;
			case 'orders':
				$this->renderOrdersTab();
				break;
			case 'conversations':
				$this->renderConversationsTab();
				break;
			case 'customers':
				$this->renderCustomersTab();
				break;
			case 'products':
				$this->renderProductsTab();
				break;
			default:
				$this->renderOverviewTab();
		}
	}

	/**
	 * Render overview tab.
	 *
	 * @return void
	 */
	private function renderOverviewTab(): void {
		?>
		<div class="wch-analytics-tab" data-tab="overview">
			<div class="wch-metrics-grid">
				<?php $this->renderMetricCard( 'total-orders', 'total_orders', __( 'Total Orders', 'whatsapp-commerce-hub' ), __( 'via WhatsApp', 'whatsapp-commerce-hub' ) ); ?>
				<?php $this->renderMetricCard( 'revenue', 'total_revenue', __( 'Revenue', 'whatsapp-commerce-hub' ), __( 'from WhatsApp orders', 'whatsapp-commerce-hub' ) ); ?>
				<?php $this->renderMetricCard( 'conversations', 'active_conversations', __( 'Active Conversations', 'whatsapp-commerce-hub' ), __( 'currently open', 'whatsapp-commerce-hub' ) ); ?>
				<?php $this->renderMetricCard( 'conversion', 'conversion_rate', __( 'Conversion Rate', 'whatsapp-commerce-hub' ), __( 'orders / conversations', 'whatsapp-commerce-hub' ) ); ?>
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
	 * Render a metric card.
	 *
	 * @param string $id     Card ID.
	 * @param string $metric Metric name.
	 * @param string $title  Card title.
	 * @param string $label  Card label.
	 * @return void
	 */
	private function renderMetricCard( string $id, string $metric, string $title, string $label ): void {
		$icons = [
			'total-orders'  => '📦',
			'revenue'       => '💰',
			'conversations' => '💬',
			'conversion'    => '📈',
		];
		?>
		<div class="wch-metric-card" id="metric-<?php echo esc_attr( $id ); ?>">
			<div class="wch-metric-icon"><?php echo esc_html( $icons[ $id ] ?? '📊' ); ?></div>
			<div class="wch-metric-content">
				<h3><?php echo esc_html( $title ); ?></h3>
				<div class="wch-metric-value" data-metric="<?php echo esc_attr( $metric ); ?>">--</div>
				<div class="wch-metric-label"><?php echo esc_html( $label ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render orders tab.
	 *
	 * @return void
	 */
	private function renderOrdersTab(): void {
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
	 * Render conversations tab.
	 *
	 * @return void
	 */
	private function renderConversationsTab(): void {
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
	 * Render customers tab.
	 *
	 * @return void
	 */
	private function renderCustomersTab(): void {
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
	 * Render products tab.
	 *
	 * @return void
	 */
	private function renderProductsTab(): void {
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
	 * AJAX handler for refreshing analytics data.
	 *
	 * @return void
	 */
	public function ajaxRefreshAnalytics(): void {
		check_ajax_referer( 'wch_analytics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'whatsapp-commerce-hub' ) ] );
		}

		wch( AnalyticsData::class )->clearCache();

		wp_send_json_success( [ 'message' => __( 'Analytics cache cleared', 'whatsapp-commerce-hub' ) ] );
	}
}
