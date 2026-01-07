<?php
/**
 * Plugin Name: WhatsApp Commerce Hub
 * Description: Complete e-commerce ecosystem inside WhatsApp with WooCommerce sync
 * Version: 1.0.0
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
define( 'WCH_VERSION', '1.0.0' );
define( 'WCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for WCH_ prefixed classes.
 *
 * @param string $class_name The class name to load.
 */
function wch_autoloader( $class_name ) {
	// Only autoload classes with WCH_ prefix.
	if ( strpos( $class_name, 'WCH_' ) !== 0 ) {
		return;
	}

	// Convert class name to file path.
	$class_file = strtolower( str_replace( '_', '-', $class_name ) );

	// Check for payment gateway classes.
	if ( strpos( $class_name, 'WCH_Payment_' ) === 0 ) {
		$file_path = WCH_PLUGIN_DIR . 'includes/payments/class-' . $class_file . '.php';
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}

	// Check for interface files in payments.
	if ( $class_name === 'WCH_Payment_Gateway' ) {
		$file_path = WCH_PLUGIN_DIR . 'includes/payments/interface-wch-payment-gateway.php';
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}

	// Standard class loading.
	$file_path = WCH_PLUGIN_DIR . 'includes/class-' . $class_file . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

spl_autoload_register( 'wch_autoloader' );

// Initialize error handler early.
WCH_Error_Handler::init();

/**
 * Main plugin class using singleton pattern.
 */
class WCH_Plugin {
	/**
	 * The single instance of the class.
	 *
	 * @var WCH_Plugin
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WCH_Plugin
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
	 */
	private function init() {
		// Load text domain for translations.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Check for database migrations on admin init.
		add_action( 'admin_init', array( $this, 'check_database_migrations' ) );

		// Initialize admin pages.
		if ( is_admin() ) {
			WCH_Admin_Inbox::init();
			WCH_Admin_Logs::init();
			WCH_Admin_Jobs::init();
			WCH_Admin_Templates::init();
			WCH_Admin_Settings::init();
			WCH_Admin_Analytics::init();
			WCH_Dashboard_Widgets::init();
		}

		// Initialize REST API.
		WCH_REST_API::getInstance();

		// Initialize background job queue.
		WCH_Queue::getInstance();

		// Initialize product sync service.
		WCH_Product_Sync_Service::instance();

		// Initialize order sync service.
		WCH_Order_Sync_Service::instance();

		// Initialize inventory sync handler.
		WCH_Inventory_Sync_Handler::instance();

		// Schedule inventory discrepancy check.
		WCH_Inventory_Sync_Handler::schedule_discrepancy_check();

		// Initialize payment system.
		WCH_Payment_Manager::instance();

		// Initialize refund handler.
		WCH_Refund_Handler::instance();
	}

	/**
	 * Check and run database migrations if needed.
	 */
	public function check_database_migrations() {
		$db_manager = new WCH_Database_Manager();
		$db_manager->run_migrations();
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
 * @return bool|WP_Error True if all requirements met, WP_Error otherwise.
 */
function wch_check_requirements() {
	$errors = array();

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
 */
function wch_activate_plugin() {
	$requirements = wch_check_requirements();

	if ( is_wp_error( $requirements ) ) {
		// Deactivate the plugin.
		deactivate_plugins( WCH_PLUGIN_BASENAME );

		// Display error message.
		wp_die(
			$requirements->get_error_message(),
			'Plugin Activation Error',
			array(
				'back_link' => true,
			)
		);
	}

	// Run database installation.
	$db_manager = new WCH_Database_Manager();
	$db_manager->install();
}

register_activation_hook( __FILE__, 'wch_activate_plugin' );

/**
 * Plugin deactivation hook.
 */
function wch_deactivate_plugin() {
	// Cleanup tasks on deactivation.
	// Clear any scheduled cron jobs, flush rewrite rules if needed, etc.

	// Placeholder for cleanup tasks.
}

register_deactivation_hook( __FILE__, 'wch_deactivate_plugin' );

/**
 * Initialize the plugin after all plugins are loaded.
 * Priority 20 ensures it runs after WooCommerce (priority 10).
 */
function wch_init_plugin() {
	// Check requirements again before initializing.
	$requirements = wch_check_requirements();

	if ( is_wp_error( $requirements ) ) {
		// Show admin notice if requirements not met.
		add_action( 'admin_notices', 'wch_requirements_notice' );
		return;
	}

	// Initialize the main plugin class.
	WCH_Plugin::getInstance();
}

add_action( 'plugins_loaded', 'wch_init_plugin', 20 );

/**
 * Display admin notice when requirements are not met.
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
