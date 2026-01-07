<?php
/**
 * Dashboard Widgets for WhatsApp Commerce Hub.
 *
 * Handles dashboard widgets for displaying sync status and other metrics.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_Dashboard_Widgets
 *
 * Manages dashboard widgets.
 */
class WCH_Dashboard_Widgets {
	/**
	 * Initialize dashboard widgets.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widgets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Register dashboard widgets.
	 */
	public static function register_widgets() {
		wp_add_dashboard_widget(
			'wch_inventory_sync_widget',
			__( 'WhatsApp Inventory Sync Status', 'whatsapp-commerce-hub' ),
			array( __CLASS__, 'render_inventory_sync_widget' )
		);

		wp_add_dashboard_widget(
			'wch_analytics_widget',
			__( 'WhatsApp Commerce Analytics', 'whatsapp-commerce-hub' ),
			array( __CLASS__, 'render_analytics_widget' )
		);

		wp_add_dashboard_widget(
			'wch_abandoned_cart_recovery_widget',
			__( 'Abandoned Cart Recovery', 'whatsapp-commerce-hub' ),
			array( __CLASS__, 'render_abandoned_cart_recovery_widget' )
		);
	}

	/**
	 * Enqueue admin scripts and styles for widgets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		// Only load on dashboard page.
		if ( 'index.php' !== $hook ) {
			return;
		}

		// Inline CSS for the widget.
		$css = "
			.wch-widget-stat {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 10px 0;
				border-bottom: 1px solid #f0f0f0;
			}
			.wch-widget-stat:last-child {
				border-bottom: none;
			}
			.wch-widget-stat-label {
				font-weight: 500;
				color: #555;
			}
			.wch-widget-stat-value {
				font-weight: 600;
				font-size: 1.2em;
			}
			.wch-widget-stat-value.success {
				color: #46b450;
			}
			.wch-widget-stat-value.warning {
				color: #ffb900;
			}
			.wch-widget-stat-value.error {
				color: #dc3232;
			}
			.wch-widget-stat-value.neutral {
				color: #72aee6;
			}
			.wch-widget-timestamp {
				font-size: 0.9em;
				color: #888;
				margin-top: 10px;
				text-align: center;
			}
			.wch-widget-discrepancies {
				margin-top: 15px;
				padding: 10px;
				background: #f9f9f9;
				border-left: 3px solid #ffb900;
				max-height: 200px;
				overflow-y: auto;
			}
			.wch-widget-discrepancies h4 {
				margin: 0 0 10px 0;
				font-size: 0.95em;
			}
			.wch-widget-discrepancy-item {
				padding: 5px 0;
				font-size: 0.9em;
				border-bottom: 1px solid #e0e0e0;
			}
			.wch-widget-discrepancy-item:last-child {
				border-bottom: none;
			}
		";

		wp_add_inline_style( 'dashboard', $css );
	}

	/**
	 * Render inventory sync status widget.
	 */
	public static function render_inventory_sync_widget() {
		$sync_handler = WCH_Inventory_Sync_Handler::instance();
		$stats        = $sync_handler->get_sync_stats();

		$products_in_sync  = $stats['products_in_sync'] ?? 0;
		$out_of_sync_count = $stats['out_of_sync_count'] ?? 0;
		$last_sync_time    = $stats['last_sync_time'] ?? 0;
		$sync_errors       = $stats['sync_errors'] ?? 0;
		$discrepancies     = $stats['discrepancies'] ?? array();

		// Calculate sync percentage.
		$total_products = $products_in_sync + $out_of_sync_count;
		$sync_percentage = $total_products > 0 ? round( ( $products_in_sync / $total_products ) * 100, 1 ) : 100;

		// Determine status class.
		$sync_status_class = 'success';
		if ( $sync_percentage < 90 ) {
			$sync_status_class = 'error';
		} elseif ( $sync_percentage < 98 ) {
			$sync_status_class = 'warning';
		}

		$error_status_class = $sync_errors > 0 ? 'error' : 'success';

		// Format last sync time.
		$last_sync_display = $last_sync_time > 0
			? human_time_diff( $last_sync_time, time() ) . ' ' . __( 'ago', 'whatsapp-commerce-hub' )
			: __( 'Never', 'whatsapp-commerce-hub' );

		?>
		<div class="wch-inventory-sync-widget">
			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Products in Sync:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value neutral"><?php echo esc_html( $products_in_sync ); ?></span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Out of Sync:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $out_of_sync_count > 0 ? 'warning' : 'success' ); ?>">
					<?php echo esc_html( $out_of_sync_count ); ?>
				</span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Sync Accuracy:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $sync_status_class ); ?>">
					<?php echo esc_html( $sync_percentage ); ?>%
				</span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Sync Errors:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $error_status_class ); ?>">
					<?php echo esc_html( $sync_errors ); ?>
				</span>
			</div>

			<div class="wch-widget-timestamp">
				<?php
				printf(
					/* translators: %s: time since last sync */
					esc_html__( 'Last checked: %s', 'whatsapp-commerce-hub' ),
					esc_html( $last_sync_display )
				);
				?>
			</div>

			<?php if ( ! empty( $discrepancies ) && $out_of_sync_count > 0 ) : ?>
				<div class="wch-widget-discrepancies">
					<h4><?php esc_html_e( 'Recent Discrepancies:', 'whatsapp-commerce-hub' ); ?></h4>
					<?php
					$displayed = 0;
					foreach ( $discrepancies as $discrepancy ) :
						if ( $displayed >= 5 ) {
							break; // Limit to 5 items.
						}
						++$displayed;
						?>
						<div class="wch-widget-discrepancy-item">
							<strong><?php echo esc_html( $discrepancy['product_name'] ); ?></strong><br>
							<small>
								<?php
								printf(
									/* translators: 1: WooCommerce availability, 2: WhatsApp availability */
									esc_html__( 'WC: %1$s | WhatsApp: %2$s', 'whatsapp-commerce-hub' ),
									esc_html( $discrepancy['wc_availability'] ),
									esc_html( $discrepancy['whatsapp_availability'] )
								);
								?>
							</small>
						</div>
					<?php endforeach; ?>

					<?php if ( count( $discrepancies ) > 5 ) : ?>
						<div class="wch-widget-discrepancy-item">
							<small>
								<?php
								printf(
									/* translators: %d: number of additional discrepancies */
									esc_html__( '...and %d more', 'whatsapp-commerce-hub' ),
									count( $discrepancies ) - 5
								);
								?>
							</small>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render analytics widget.
	 */
	public static function render_analytics_widget() {
		$summary = WCH_Analytics_Data::get_summary( 'week' );

		$total_orders        = $summary['total_orders'] ?? 0;
		$total_revenue       = $summary['total_revenue'] ?? 0;
		$active_conversations = $summary['active_conversations'] ?? 0;
		$conversion_rate     = $summary['conversion_rate'] ?? 0;

		$revenue_class = $total_revenue > 0 ? 'success' : 'neutral';
		$conversion_class = $conversion_rate >= 5 ? 'success' : ( $conversion_rate >= 2 ? 'warning' : 'neutral' );
		?>
		<div class="wch-analytics-widget">
			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Orders (7 days):', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value neutral"><?php echo esc_html( $total_orders ); ?></span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Revenue (7 days):', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $revenue_class ); ?>">
					<?php echo esc_html( get_woocommerce_currency_symbol() . number_format( $total_revenue, 2 ) ); ?>
				</span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Active Conversations:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value neutral"><?php echo esc_html( $active_conversations ); ?></span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Conversion Rate:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $conversion_class ); ?>">
					<?php echo esc_html( number_format( $conversion_rate, 1 ) . '%' ); ?>
				</span>
			</div>

			<div class="wch-widget-timestamp">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-analytics' ) ); ?>">
					<?php esc_html_e( 'View Full Analytics →', 'whatsapp-commerce-hub' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render abandoned cart recovery widget.
	 */
	public static function render_abandoned_cart_recovery_widget() {
		if ( ! class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
			echo '<p>' . esc_html__( 'Abandoned cart recovery not available.', 'whatsapp-commerce-hub' ) . '</p>';
			return;
		}

		$recovery = WCH_Abandoned_Cart_Recovery::getInstance();
		$stats    = $recovery->get_recovery_stats( 7 );

		$abandoned_carts   = $stats['abandoned_carts'] ?? 0;
		$messages_sent     = $stats['messages_sent'] ?? 0;
		$carts_recovered   = $stats['carts_recovered'] ?? 0;
		$recovery_rate     = $stats['recovery_rate'] ?? 0;
		$revenue_recovered = $stats['revenue_recovered'] ?? 0;

		// Determine status classes.
		$recovery_rate_class = 'neutral';
		if ( $recovery_rate >= 15 ) {
			$recovery_rate_class = 'success';
		} elseif ( $recovery_rate >= 8 ) {
			$recovery_rate_class = 'warning';
		}

		$revenue_class = $revenue_recovered > 0 ? 'success' : 'neutral';
		?>
		<div class="wch-abandoned-cart-recovery-widget">
			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Abandoned Carts (7 days):', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value neutral"><?php echo esc_html( $abandoned_carts ); ?></span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Recovery Messages Sent:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value neutral"><?php echo esc_html( $messages_sent ); ?></span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Carts Recovered:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $carts_recovered > 0 ? 'success' : 'neutral' ); ?>">
					<?php echo esc_html( $carts_recovered ); ?>
				</span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Recovery Rate:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $recovery_rate_class ); ?>">
					<?php echo esc_html( number_format( $recovery_rate, 1 ) . '%' ); ?>
				</span>
			</div>

			<div class="wch-widget-stat">
				<span class="wch-widget-stat-label"><?php esc_html_e( 'Revenue Recovered:', 'whatsapp-commerce-hub' ); ?></span>
				<span class="wch-widget-stat-value <?php echo esc_attr( $revenue_class ); ?>">
					<?php echo esc_html( get_woocommerce_currency_symbol() . number_format( $revenue_recovered, 2 ) ); ?>
				</span>
			</div>

			<div class="wch-widget-timestamp">
				<?php esc_html_e( 'Last 7 days statistics', 'whatsapp-commerce-hub' ); ?>
			</div>
		</div>
		<?php
	}
}
