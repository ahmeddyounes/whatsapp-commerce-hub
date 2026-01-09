<?php
/**
 * REST API Manager
 *
 * Manages REST API initialization and route registration.
 *
 * @package WhatsApp_Commerce_Hub
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCH_REST_API
 */
class WCH_REST_API {
	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'wch/v1';

	/**
	 * Controllers to register.
	 *
	 * @var array
	 */
	private $controllers = array();

	/**
	 * The single instance of the class.
	 *
	 * @var WCH_REST_API
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WCH_REST_API
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
	 * Initialize the REST API.
	 */
	private function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// SECURITY: API discovery endpoint requires admin authentication.
		// This prevents information disclosure about available endpoints and auth methods.
		register_rest_route(
			self::NAMESPACE,
			'/',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_api_info' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		// Load and register all controllers.
		$this->load_controllers();
		$this->register_controllers();
	}

	/**
	 * Check if the current user has admin permission.
	 *
	 * SECURITY: Used to protect API discovery endpoint from information disclosure.
	 *
	 * @return bool True if user has permission, false otherwise.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get API information.
	 *
	 * @return array
	 */
	public function get_api_info() {
		return array(
			'name'           => 'WhatsApp Commerce Hub API',
			'version'        => 'v1',
			'namespace'      => self::NAMESPACE,
			'description'    => __( 'REST API for WhatsApp Commerce Hub admin and external integrations.', 'whatsapp-commerce-hub' ),
			'endpoints'      => array(
				'/settings'                    => 'Settings management (GET/POST)',
				'/conversations'               => 'List conversations (GET/POST)',
				'/conversations/{id}'          => 'Single conversation (GET/PATCH)',
				'/conversations/{id}/messages' => 'Conversation messages (GET/POST)',
				'/customers'                   => 'List customers (GET)',
				'/customers/{phone}'           => 'Single customer (GET/PATCH)',
				'/analytics'                   => 'Analytics data (GET)',
				'/broadcasts'                  => 'Broadcast campaigns (GET/POST)',
				'/broadcasts/{id}'             => 'Single broadcast (GET/PATCH/DELETE)',
				'/webhook'                     => 'WhatsApp webhook (POST)',
			),
			'authentication' => array(
				'admin'   => 'WordPress authentication or X-WCH-API-Key header',
				'webhook' => 'X-Hub-Signature-256 header',
			),
		);
	}

	/**
	 * Load controller classes.
	 */
	private function load_controllers() {
		// Load built-in controllers.
		$built_in_controllers = array(
			'WCH_Webhook_Handler',
			'WCH_Payment_Webhook_Handler',
			'WCH_Analytics_Controller',
			'WCH_Conversations_Controller',
		);

		foreach ( $built_in_controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$this->controllers[] = new $controller_class();
			}
		}

		/**
		 * Filter to add custom controllers.
		 *
		 * @param array $controllers Array of controller class names.
		 */
		$custom_controllers = apply_filters( 'wch_rest_api_controllers', array() );

		foreach ( $custom_controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$this->controllers[] = new $controller_class();
			}
		}
	}

	/**
	 * Register all controllers.
	 */
	private function register_controllers() {
		foreach ( $this->controllers as $controller ) {
			if ( method_exists( $controller, 'register_routes' ) ) {
				$controller->register_routes();
			}
		}
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
