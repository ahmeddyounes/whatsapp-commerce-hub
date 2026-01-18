<?php
/**
 * Plugin Name: WhatsApp Commerce Hub
 * Description: Complete e-commerce ecosystem inside WhatsApp with WooCommerce sync
 * Version: 3.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * Author: WhatsApp Commerce Hub Team
 * Text Domain: whatsapp-commerce-hub
 * Domain Path: /languages
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WCH_VERSION', '3.0.0' );
define( 'WCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Container instance holder.
global $wch_container;
$wch_container = null;

/**
 * PSR-4 Autoloader for namespaced classes.
 *
 * Loads classes from the WhatsAppCommerceHub namespace.
 * Supports the new architecture with Container, Repositories, Entities, etc.
 *
 * @since 2.0.0
 * @param string $class_name The fully-qualified class name to load.
 * @return void
 */
function wch_psr4_autoloader( $class_name ) {
	$namespace = 'WhatsAppCommerceHub\\';

	// Only autoload classes in our namespace.
	if ( strpos( $class_name, $namespace ) !== 0 ) {
		return;
	}

	// Remove namespace prefix.
	$relative_class = substr( $class_name, strlen( $namespace ) );

	// Convert namespace separators to directory separators.
	$file = WCH_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

spl_autoload_register( 'wch_psr4_autoloader' );

/**
 * Get the DI container instance.
 *
 * Initializes the container on first call and registers all service providers.
 *
 * @since 2.0.0
 * @return \WhatsAppCommerceHub\Container\ContainerInterface The container instance.
 */
function wch_get_container(): \WhatsAppCommerceHub\Container\ContainerInterface {
	global $wch_container;

	if ( null === $wch_container ) {
		$wch_container = new \WhatsAppCommerceHub\Container\Container();

		// Register core service providers.
		// IMPORTANT: Order matters! Dependencies must be registered before dependents.
		// ResilienceServiceProvider must come before ApiClientServiceProvider (CircuitBreakerRegistry).
		$providers = [
			// Foundation layer.
			new \WhatsAppCommerceHub\Providers\CoreServiceProvider(),
			new \WhatsAppCommerceHub\Providers\ResilienceServiceProvider(),
			new \WhatsAppCommerceHub\Providers\SecurityServiceProvider(),

			// Infrastructure.
			new \WhatsAppCommerceHub\Providers\RepositoryServiceProvider(),
			new \WhatsAppCommerceHub\Providers\QueueServiceProvider(),

			// Core services.
			new \WhatsAppCommerceHub\Providers\ApiClientServiceProvider(),
			new \WhatsAppCommerceHub\Providers\BusinessServiceProvider(),

			// Feature services.
			new \WhatsAppCommerceHub\Providers\ActionServiceProvider(),
			new \WhatsAppCommerceHub\Providers\ProductSyncServiceProvider(),
			new \WhatsAppCommerceHub\Providers\ReengagementServiceProvider(),
			new \WhatsAppCommerceHub\Providers\NotificationServiceProvider(),
			new \WhatsAppCommerceHub\Providers\PaymentServiceProvider(),
			new \WhatsAppCommerceHub\Providers\CheckoutServiceProvider(),
			new \WhatsAppCommerceHub\Providers\BroadcastsServiceProvider(),
			new \WhatsAppCommerceHub\Providers\AdminSettingsServiceProvider(),

			// Orchestration.
			new \WhatsAppCommerceHub\Providers\SagaServiceProvider(),
			new \WhatsAppCommerceHub\Providers\EventServiceProvider(),
			new \WhatsAppCommerceHub\Providers\MonitoringServiceProvider(),

			// Controllers & Admin UI.
			new \WhatsAppCommerceHub\Providers\ControllerServiceProvider(),
			new \WhatsAppCommerceHub\Providers\AdminServiceProvider(),
		];

		foreach ( $providers as $provider ) {
			$wch_container->register( $provider );
		}

		// Allow plugins/themes to register additional providers.
		do_action( 'wch_container_registered', $wch_container );

		// Boot all providers using Container's boot method.
		// This ensures the booted flag is set and providers are booted consistently.
		$wch_container->boot();

		do_action( 'wch_container_booted', $wch_container );
	}

	return $wch_container;
}

/**
 * Resolve a service from the DI container.
 *
 * Helper function for quick service resolution.
 *
 * @since 2.0.0
 * @param string $abstract The abstract type or alias to resolve.
 * @return mixed The resolved service.
 */
function wch( string $abstract ): mixed {
	return wch_get_container()->get( $abstract );
}

/**
 * Check if WooCommerce is active and available.
 *
 * Use this in contexts where WC availability isn't guaranteed
 * (webhooks, REST API, background jobs) before calling WC functions.
 *
 * @since 2.0.0
 * @return bool True if WooCommerce is active.
 */
function wch_is_woocommerce_active(): bool {
	return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
}

// Initialize error handler early.
\WhatsAppCommerceHub\Core\ErrorHandler::init();

/**
 * Main plugin class using singleton pattern.
 *
 * Coordinates all plugin components including:
 * - REST API initialization
 * - Admin pages and dashboard
 * - Background job processing
 * - Product and order synchronization
 * - Payment gateway integration
 * - Abandoned cart recovery
 * - Customer re-engagement
 *
 * @since 1.0.0
 * @package WhatsApp_Commerce_Hub
 */
class WhatsAppCommerceHubPlugin {
	/**
	 * The single instance of the class.
	 *
	 * @var WhatsAppCommerceHubPlugin
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 * @return WhatsAppCommerceHubPlugin The singleton instance.
	 */
	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize the plugin.
	 *
	 * Sets up all core services, admin interfaces, and hooks.
	 * This method is called automatically when the singleton is instantiated.
	 *
	 * @since 1.0.0
	 */
	private function init() {
		// Load text domain for translations.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Check for database migrations on admin init.
		add_action( 'admin_init', [ $this, 'check_database_migrations' ] );

		// Initialize admin pages.
		if ( is_admin() ) {
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\InboxPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\LogsPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\JobsPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\TemplatesPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\SettingsPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\AnalyticsPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\CatalogSyncPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Pages\BroadcastsPage::class )->init();
			wch( \WhatsAppCommerceHub\Presentation\Admin\Widgets\DashboardWidgets::class )->init();
		}

		// Initialize background job queue.
		wch( \WhatsAppCommerceHub\Infrastructure\Queue\QueueManager::class );

		// Schedule inventory discrepancy check.
		wch( \WhatsAppCommerceHub\Application\Services\InventorySyncService::class )
			->schedule_discrepancy_check();

		// Initialize payment system.
		wch( \WhatsAppCommerceHub\Payments\PaymentGatewayRegistry::class );

		// Initialize refund handler.
		wch( \WhatsAppCommerceHub\Application\Services\RefundService::class );

		// Initialize order notifications.
		wch( \WhatsAppCommerceHub\Application\Services\NotificationService::class );

		// Initialize abandoned cart recovery system.
		wch( \WhatsAppCommerceHub\Features\AbandonedCart\RecoveryService::class )->init();

		// Initialize re-engagement service.
		wch( \WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementOrchestratorInterface::class )->init();

		// Hook into WooCommerce order creation for conversion tracking.
		add_action( 'woocommerce_checkout_order_created', [ $this, 'track_order_conversion' ], 10, 1 );
	}

	/**
	 * Track conversions when orders are created.
	 *
	 * Hooks into WooCommerce order creation to track conversions
	 * from WhatsApp conversations to completed purchases.
	 *
	 * @since 1.0.0
	 * @param WC_Order $order The WooCommerce order object.
	 */
	public function track_order_conversion( $order ) {
		$customer_phone = $order->get_billing_phone();

		if ( $customer_phone ) {
			wch( \WhatsAppCommerceHub\Contracts\Services\Reengagement\ReengagementAnalyticsInterface::class )
				->trackConversion( $customer_phone, $order->get_id() );
		}
	}

	/**
	 * Check and run database migrations if needed.
	 */
	public function check_database_migrations() {
		wch( \WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager::class )->run_migrations();
	}

	/**
	 * Load plugin text domain for translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'whatsapp-commerce-hub',
			false,
			dirname( WCH_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization of the instance.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}

/**
 * Check plugin dependencies and requirements.
 *
 * Validates:
 * - PHP version >= 8.1
 * - WordPress version >= 6.0
 * - WooCommerce is active and >= 8.0
 *
 * @since 1.0.0
 * @return bool|WP_Error True if all requirements met, WP_Error with error messages otherwise.
 */
function wch_check_requirements() {
	$errors = [];

	// Check PHP version.
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		$errors[] = sprintf(
			__( 'WhatsApp Commerce Hub requires PHP 8.1 or higher. You are running PHP %s.', 'whatsapp-commerce-hub' ),
			PHP_VERSION
		);
	}

	// Check WordPress version.
	global $wp_version;
	if ( version_compare( $wp_version, '6.0', '<' ) ) {
		$errors[] = sprintf(
			__( 'WhatsApp Commerce Hub requires WordPress 6.0 or higher. You are running WordPress %s.', 'whatsapp-commerce-hub' ),
			$wp_version
		);
	}

	// Check if WooCommerce is active.
	if ( ! class_exists( 'WooCommerce' ) ) {
		$errors[] = __( 'WhatsApp Commerce Hub requires WooCommerce to be installed and activated.', 'whatsapp-commerce-hub' );
	} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.0', '<' ) ) {
		$errors[] = sprintf(
			__( 'WhatsApp Commerce Hub requires WooCommerce 8.0 or higher. You are running WooCommerce %s.', 'whatsapp-commerce-hub' ),
			WC_VERSION
		);
	}

	if ( ! empty( $errors ) ) {
		return new WP_Error( 'wch_requirements_not_met', implode( '<br>', $errors ) );
	}

	return true;
}

/**
 * Plugin activation hook.
 *
 * Runs on plugin activation to:
 * - Verify system requirements
 * - Create database tables
 * - Set up default settings
 *
 * @since 1.0.0
 * @return void
 */
function wch_activate_plugin() {
	$requirements = wch_check_requirements();

	if ( is_wp_error( $requirements ) ) {
		// Deactivate the plugin.
		deactivate_plugins( WCH_PLUGIN_BASENAME );

		// Display error message.
		wp_die(
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Error message is safely constructed
			$requirements->get_error_message(),
			'Plugin Activation Error',
			[
				'back_link' => true,
			]
		);
	}

	// Run database installation.
	$db_manager = new \WhatsAppCommerceHub\Infrastructure\Database\DatabaseManager();
	$db_manager->install();
}

register_activation_hook( __FILE__, 'wch_activate_plugin' );

/**
 * Plugin deactivation hook.
 *
 * Cleanup tasks on deactivation such as:
 * - Clearing scheduled cron jobs
 * - Flushing rewrite rules
 * - Cleanup temporary data
 *
 * Note: Does NOT delete user data or settings.
 *
 * @since 1.0.0
 * @return void
 */
function wch_deactivate_plugin() {
	// Cleanup tasks on deactivation.
	// Clear any scheduled cron jobs, flush rewrite rules if needed, etc.

	// Placeholder for cleanup tasks.
}

register_deactivation_hook( __FILE__, 'wch_deactivate_plugin' );

/**
 * Initialize the plugin after all plugins are loaded.
 *
 * Priority 20 ensures it runs after WooCommerce (priority 10), allowing
 * us to safely access WooCommerce functionality during initialization.
 *
 * @since 1.0.0
 * @return void
 */
function wch_init_plugin() {
	// Check requirements again before initializing.
	$requirements = wch_check_requirements();

	if ( is_wp_error( $requirements ) ) {
		// Show admin notice if requirements not met.
		add_action( 'admin_notices', 'wch_requirements_notice' );
		return;
	}

	// Initialize the DI container.
	// This sets up all services, repositories, and providers.
	wch_get_container();

	// Initialize the main plugin class.
	WhatsAppCommerceHubPlugin::getInstance();
}

add_action( 'plugins_loaded', 'wch_init_plugin', 20 );

/**
 * Display admin notice when requirements are not met.
 *
 * Shows a dismissible error notice in the WordPress admin when
 * plugin requirements (PHP, WordPress, or WooCommerce versions) are not satisfied.
 *
 * @since 1.0.0
 * @return void
 */
function wch_requirements_notice() {
	$requirements = wch_check_requirements();

	if ( is_wp_error( $requirements ) ) {
		?>
		<div class="notice notice-error">
			<p><?php echo wp_kses_post( $requirements->get_error_message() ); ?></p>
		</div>
		<?php
	}
}
