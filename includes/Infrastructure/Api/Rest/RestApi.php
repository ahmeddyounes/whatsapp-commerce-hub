<?php

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Api\Rest;

use WP_REST_Server;

/**
 * REST API Manager
 *
 * Manages REST API initialization and route registration for WhatsApp Commerce Hub.
 *
 * @package WhatsAppCommerceHub\Infrastructure\Api\Rest
 */
class RestApi {

	/**
	 * REST API namespace
	 */
	public const NAMESPACE = 'wch/v1';

	/**
	 * Registered controllers
	 *
	 * @var array<RestController>
	 */
	private array $controllers = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize the REST API
	 */
	private function init(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	/**
	 * Register REST API routes
	 */
	public function registerRoutes(): void {
		// SECURITY: API discovery endpoint requires admin authentication
		// This prevents information disclosure about available endpoints and auth methods
		register_rest_route(
			self::NAMESPACE,
			'/',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'getApiInfo' ],
				'permission_callback' => [ $this, 'checkAdminPermission' ],
			]
		);

		// Load and register all controllers
		$this->loadControllers();
		$this->registerControllers();
	}

	/**
	 * Check if the current user has admin permission
	 *
	 * SECURITY: Used to protect API discovery endpoint from information disclosure
	 */
	public function checkAdminPermission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get API information
	 */
	public function getApiInfo(): array {
		return [
			'name'           => 'WhatsApp Commerce Hub API',
			'version'        => 'v1',
			'namespace'      => self::NAMESPACE,
			'description'    => __( 'REST API for WhatsApp Commerce Hub admin and external integrations.', 'whatsapp-commerce-hub' ),
			'endpoints'      => [
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
			],
			'authentication' => [
				'admin'   => 'WordPress authentication or X-WCH-API-Key header',
				'webhook' => 'X-Hub-Signature-256 header',
			],
		];
	}

	/**
	 * Load controller classes
	 */
	private function loadControllers(): void {
		// Built-in controller class names
		$builtInControllers = [
			'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\WebhookController',
			'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\ConversationsController',
			'WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers\AnalyticsController',
		];

		foreach ( $builtInControllers as $controllerClass ) {
			if ( class_exists( $controllerClass ) ) {
				$this->controllers[] = new $controllerClass();
			}
		}

		/**
		 * Filter to add custom controllers
		 *
		 * @param array $controllers Array of controller instances
		 */
		$customControllers = apply_filters( 'wch_rest_api_controllers', [] );

		foreach ( $customControllers as $controller ) {
			if ( $controller instanceof RestController ) {
				$this->controllers[] = $controller;
			}
		}
	}

	/**
	 * Register all controllers
	 */
	private function registerControllers(): void {
		foreach ( $this->controllers as $controller ) {
			if ( method_exists( $controller, 'registerRoutes' ) ) {
				$controller->registerRoutes();
			}
		}
	}

	/**
	 * Get registered controllers
	 */
	public function getControllers(): array {
		return $this->controllers;
	}
}
