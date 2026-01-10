<?php
/**
 * Dashboard Widgets
 *
 * Handles dashboard widgets for displaying sync status and other metrics.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Presentation\Admin\Widgets;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DashboardWidgets
 *
 * Manages WordPress dashboard widgets for WhatsApp Commerce Hub.
 */
class DashboardWidgets {

	/**
	 * Initialize dashboard widgets.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'registerWidgets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
	}

	/**
	 * Register dashboard widgets.
	 *
	 * @return void
	 */
	public function registerWidgets(): void {
		wp_add_dashboard_widget(
			'wch_inventory_sync_widget',
			__( 'WhatsApp Inventory Sync Status', 'whatsapp-commerce-hub' ),
			array( $this, 'renderInventorySyncWidget' )
		);

		wp_add_dashboard_widget(
			'wch_analytics_widget',
			__( 'WhatsApp Commerce Analytics', 'whatsapp-commerce-hub' ),
			array( $this, 'renderAnalyticsWidget' )
		);

		wp_add_dashboard_widget(
			'wch_abandoned_cart_recovery_widget',
			__( 'Abandoned Cart Recovery', 'whatsapp-commerce-hub' ),
			array( $this, 'renderAbandonedCartRecoveryWidget' )
		);
	}

	/**
	 * Enqueue admin scripts and styles for widgets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		// Only load on dashboard page.
		if ( 'index.php' !== $hook ) {
			return;
		}

		wp_add_inline_style( 'dashboard', $this->getWidgetStyles() );
	}

	/**
	 * Get widget CSS styles.
	 *
	 * @return string
	 */
	private function getWidgetStyles(): string {
		return '
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
		';
	}

	/**
	 * Render inventory sync status widget.
	 *
	 * @return void
	 */
	public function renderInventorySyncWidget(): void {
		$syncHandler = \WCH_Inventory_Sync_Handler::instance();
		$stats       = $syncHandler->get_sync_stats();

		$productsInSync = $stats['products_in_sync'] ?? 0;
		$outOfSyncCount = $stats['out_of_sync_count'] ?? 0;
		$lastSyncTime   = $stats['last_sync_time'] ?? 0;
		$syncErrors     = $stats['sync_errors'] ?? 0;
		$discrepancies  = $stats['discrepancies'] ?? array();

		// Calculate sync percentage.
		$totalProducts  = $productsInSync + $outOfSyncCount;
		$syncPercentage = $totalProducts > 0 ? round( ( $productsInSync / $totalProducts ) * 100, 1 ) : 100;

		// Determine status classes.
		$syncStatusClass  = $this->getSyncStatusClass( $syncPercentage );
		$errorStatusClass = $syncErrors > 0 ? 'error' : 'success';

		// Format last sync time.
		$lastSyncDisplay = $lastSyncTime > 0
			? human_time_diff( $lastSyncTime, time() ) . ' ' . __( 'ago', 'whatsapp-commerce-hub' )
			: __( 'Never', 'whatsapp-commerce-hub' );

		$this->renderInventorySyncHtml(
			$productsInSync,
			$outOfSyncCount,
			$syncPercentage,
			$syncStatusClass,
			$syncErrors,
			$errorStatusClass,
			$lastSyncDisplay,
			$discrepancies
		);
	}

	/**
	 * Get sync status CSS class.
	 *
	 * @param float $percentage Sync percentage.
	 * @return string
	 */
	private function getSyncStatusClass( float $percentage ): string {
		if ( $percentage < 90 ) {
			return 'error';
		} elseif ( $percentage < 98 ) {
			return 'warning';
		}
		return 'success';
	}

	/**
	 * Render inventory sync widget HTML.
	 *
	 * @param int    $productsInSync  Products in sync count.
	 * @param int    $outOfSyncCount  Out of sync count.
	 * @param float  $syncPercentage  Sync percentage.
	 * @param string $syncStatusClass Sync status CSS class.
	 * @param int    $syncErrors      Sync errors count.
	 * @param string $errorStatusClass Error status CSS class.
	 * @param string $lastSyncDisplay Last sync display text.
	 * @param array  $discrepancies   Discrepancies array.
	 * @return void
	 */
	private function renderInventorySyncHtml(
		int $productsInSync,
		int $outOfSyncCount,
		float $syncPercentage,
		string $syncStatusClass,
		int $syncErrors,
		string $errorStatusClass,
		string $lastSyncDisplay,
		array $discrepancies
	): void {
		?>
		<div class="wch-inventory-sync-widget">
			<?php
			$this->renderWidgetStat(
				__( 'Products in Sync:', 'whatsapp-commerce-hub' ),
				(string) $productsInSync,
				'neutral'
			);
			$this->renderWidgetStat(
				__( 'Out of Sync:', 'whatsapp-commerce-hub' ),
				(string) $outOfSyncCount,
				$outOfSyncCount > 0 ? 'warning' : 'success'
			);
			$this->renderWidgetStat(
				__( 'Sync Accuracy:', 'whatsapp-commerce-hub' ),
				$syncPercentage . '%',
				$syncStatusClass
			);
			$this->renderWidgetStat(
				__( 'Sync Errors:', 'whatsapp-commerce-hub' ),
				(string) $syncErrors,
				$errorStatusClass
			);
			?>

			<div class="wch-widget-timestamp">
				<?php
				printf(
					/* translators: %s: time since last sync */
					esc_html__( 'Last checked: %s', 'whatsapp-commerce-hub' ),
					esc_html( $lastSyncDisplay )
				);
				?>
			</div>

			<?php $this->renderDiscrepancies( $discrepancies, $outOfSyncCount ); ?>
		</div>
		<?php
	}

	/**
	 * Render a single widget stat row.
	 *
	 * @param string $label      Stat label.
	 * @param string $value      Stat value.
	 * @param string $valueClass CSS class for value.
	 * @return void
	 */
	private function renderWidgetStat( string $label, string $value, string $valueClass ): void {
		?>
		<div class="wch-widget-stat">
			<span class="wch-widget-stat-label"><?php echo esc_html( $label ); ?></span>
			<span class="wch-widget-stat-value <?php echo esc_attr( $valueClass ); ?>">
				<?php echo esc_html( $value ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render discrepancies section.
	 *
	 * @param array $discrepancies  Discrepancies array.
	 * @param int   $outOfSyncCount Out of sync count.
	 * @return void
	 */
	private function renderDiscrepancies( array $discrepancies, int $outOfSyncCount ): void {
		if ( empty( $discrepancies ) || $outOfSyncCount <= 0 ) {
			return;
		}
		?>
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
		<?php
	}

	/**
	 * Render analytics widget.
	 *
	 * @return void
	 */
	public function renderAnalyticsWidget(): void {
		$summary = \WCH_Analytics_Data::get_summary( 'week' );

		$totalOrders         = $summary['total_orders'] ?? 0;
		$totalRevenue        = $summary['total_revenue'] ?? 0;
		$activeConversations = $summary['active_conversations'] ?? 0;
		$conversionRate      = $summary['conversion_rate'] ?? 0;

		$revenueClass    = $totalRevenue > 0 ? 'success' : 'neutral';
		$conversionClass = $this->getConversionRateClass( $conversionRate );

		$this->renderAnalyticsHtml(
			$totalOrders,
			$totalRevenue,
			$activeConversations,
			$conversionRate,
			$revenueClass,
			$conversionClass
		);
	}

	/**
	 * Get conversion rate CSS class.
	 *
	 * @param float $rate Conversion rate.
	 * @return string
	 */
	private function getConversionRateClass( float $rate ): string {
		if ( $rate >= 5 ) {
			return 'success';
		} elseif ( $rate >= 2 ) {
			return 'warning';
		}
		return 'neutral';
	}

	/**
	 * Render analytics widget HTML.
	 *
	 * @param int    $totalOrders         Total orders count.
	 * @param float  $totalRevenue        Total revenue.
	 * @param int    $activeConversations Active conversations count.
	 * @param float  $conversionRate      Conversion rate.
	 * @param string $revenueClass        Revenue CSS class.
	 * @param string $conversionClass     Conversion CSS class.
	 * @return void
	 */
	private function renderAnalyticsHtml(
		int $totalOrders,
		float $totalRevenue,
		int $activeConversations,
		float $conversionRate,
		string $revenueClass,
		string $conversionClass
	): void {
		$currencySymbol = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '$';
		?>
		<div class="wch-analytics-widget">
			<?php
			$this->renderWidgetStat(
				__( 'Orders (7 days):', 'whatsapp-commerce-hub' ),
				(string) $totalOrders,
				'neutral'
			);
			$this->renderWidgetStat(
				__( 'Revenue (7 days):', 'whatsapp-commerce-hub' ),
				$currencySymbol . number_format( $totalRevenue, 2 ),
				$revenueClass
			);
			$this->renderWidgetStat(
				__( 'Active Conversations:', 'whatsapp-commerce-hub' ),
				(string) $activeConversations,
				'neutral'
			);
			$this->renderWidgetStat(
				__( 'Conversion Rate:', 'whatsapp-commerce-hub' ),
				number_format( $conversionRate, 1 ) . '%',
				$conversionClass
			);
			?>

			<div class="wch-widget-timestamp">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wch-analytics' ) ); ?>">
					<?php esc_html_e( 'View Full Analytics', 'whatsapp-commerce-hub' ); ?> &rarr;
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render abandoned cart recovery widget.
	 *
	 * @return void
	 */
	public function renderAbandonedCartRecoveryWidget(): void {
		if ( ! class_exists( 'WCH_Abandoned_Cart_Recovery' ) ) {
			echo '<p>' . esc_html__( 'Abandoned cart recovery not available.', 'whatsapp-commerce-hub' ) . '</p>';
			return;
		}

		$recovery = \WCH_Abandoned_Cart_Recovery::getInstance();
		$stats    = $recovery->get_recovery_stats( 7 );

		$abandonedCarts   = $stats['abandoned_carts'] ?? 0;
		$messagesSent     = $stats['messages_sent'] ?? 0;
		$cartsRecovered   = $stats['carts_recovered'] ?? 0;
		$recoveryRate     = $stats['recovery_rate'] ?? 0;
		$revenueRecovered = $stats['revenue_recovered'] ?? 0;

		$recoveryRateClass = $this->getRecoveryRateClass( $recoveryRate );
		$revenueClass      = $revenueRecovered > 0 ? 'success' : 'neutral';

		$this->renderAbandonedCartHtml(
			$abandonedCarts,
			$messagesSent,
			$cartsRecovered,
			$recoveryRate,
			$revenueRecovered,
			$recoveryRateClass,
			$revenueClass
		);
	}

	/**
	 * Get recovery rate CSS class.
	 *
	 * @param float $rate Recovery rate.
	 * @return string
	 */
	private function getRecoveryRateClass( float $rate ): string {
		if ( $rate >= 15 ) {
			return 'success';
		} elseif ( $rate >= 8 ) {
			return 'warning';
		}
		return 'neutral';
	}

	/**
	 * Render abandoned cart widget HTML.
	 *
	 * @param int    $abandonedCarts    Abandoned carts count.
	 * @param int    $messagesSent      Messages sent count.
	 * @param int    $cartsRecovered    Carts recovered count.
	 * @param float  $recoveryRate      Recovery rate.
	 * @param float  $revenueRecovered  Revenue recovered.
	 * @param string $recoveryRateClass Recovery rate CSS class.
	 * @param string $revenueClass      Revenue CSS class.
	 * @return void
	 */
	private function renderAbandonedCartHtml(
		int $abandonedCarts,
		int $messagesSent,
		int $cartsRecovered,
		float $recoveryRate,
		float $revenueRecovered,
		string $recoveryRateClass,
		string $revenueClass
	): void {
		$currencySymbol = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '$';
		?>
		<div class="wch-abandoned-cart-recovery-widget">
			<?php
			$this->renderWidgetStat(
				__( 'Abandoned Carts (7 days):', 'whatsapp-commerce-hub' ),
				(string) $abandonedCarts,
				'neutral'
			);
			$this->renderWidgetStat(
				__( 'Recovery Messages Sent:', 'whatsapp-commerce-hub' ),
				(string) $messagesSent,
				'neutral'
			);
			$this->renderWidgetStat(
				__( 'Carts Recovered:', 'whatsapp-commerce-hub' ),
				(string) $cartsRecovered,
				$cartsRecovered > 0 ? 'success' : 'neutral'
			);
			$this->renderWidgetStat(
				__( 'Recovery Rate:', 'whatsapp-commerce-hub' ),
				number_format( $recoveryRate, 1 ) . '%',
				$recoveryRateClass
			);
			$this->renderWidgetStat(
				__( 'Revenue Recovered:', 'whatsapp-commerce-hub' ),
				$currencySymbol . number_format( $revenueRecovered, 2 ),
				$revenueClass
			);
			?>

			<div class="wch-widget-timestamp">
				<?php esc_html_e( 'Last 7 days statistics', 'whatsapp-commerce-hub' ); ?>
			</div>
		</div>
		<?php
	}
}
