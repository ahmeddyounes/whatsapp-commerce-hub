<?php
/**
 * Template Manager Class
 *
 * Manages WhatsApp message templates including syncing, caching, and rendering.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCH_Template_Manager
 *
 * Manages message templates from WhatsApp Business API.
 */
class WCH_Template_Manager {

	/**
	 * Singleton instance
	 *
	 * @var WCH_Template_Manager
	 */
	private static $instance = null;

	/**
	 * WhatsApp API client
	 *
	 * @var WCH_WhatsApp_API_Client
	 */
	private $api_client;

	/**
	 * Settings instance
	 *
	 * @var WCH_Settings
	 */
	private $settings;

	/**
	 * Option name for storing templates
	 */
	const TEMPLATES_OPTION = 'wch_message_templates';

	/**
	 * Option name for last sync timestamp
	 */
	const LAST_SYNC_OPTION = 'wch_templates_last_sync';

	/**
	 * Transient prefix for template usage stats
	 */
	const USAGE_STATS_TRANSIENT_PREFIX = 'wch_template_usage_';

	/**
	 * Supported template categories
	 */
	const SUPPORTED_CATEGORIES = array(
		'order_confirmation',
		'order_status_update',
		'shipping_update',
		'abandoned_cart',
		'promotional',
	);

	/**
	 * Get singleton instance
	 *
	 * @return WCH_Template_Manager
	 */
	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 */
	private function __construct() {
		$this->settings = WCH_Settings::getInstance();
		$this->init_api_client();
	}

	/**
	 * Initialize API client
	 */
	private function init_api_client() {
		try {
			$phone_number_id = $this->settings->get( 'api.phone_number_id' );
			$access_token    = $this->settings->get( 'api.access_token' );
			$api_version     = $this->settings->get( 'api.version', 'v18.0' );

			if ( $phone_number_id && $access_token ) {
				$this->api_client = new WCH_WhatsApp_API_Client(
					$phone_number_id,
					$access_token,
					$api_version
				);
			}
		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Failed to initialize API client for template manager',
				array(
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Sync templates from WhatsApp API
	 *
	 * Fetches all message templates from WhatsApp Business API and stores them.
	 *
	 * @throws WCH_Exception If API client is not initialized or API call fails.
	 * @return array Array of synced templates.
	 */
	public function sync_templates() {
		if ( ! $this->api_client ) {
			throw new WCH_Exception(
				'API client not initialized. Please configure WhatsApp API settings.',
				'TEMPLATE_SYNC_ERROR'
			);
		}

		try {
			// Get WABA ID from settings
			$waba_id = $this->settings->get( 'api.waba_id' );
			if ( empty( $waba_id ) ) {
				throw new WCH_Exception(
					'WhatsApp Business Account ID not configured',
					'TEMPLATE_SYNC_ERROR'
				);
			}

			WCH_Logger::info( 'Starting template sync from WhatsApp API' );

			// Fetch templates from API
			$templates = $this->fetch_templates_from_api( $waba_id );

			// Filter templates by supported categories
			$filtered_templates = $this->filter_templates_by_category( $templates );

			// Store templates in option
			update_option( self::TEMPLATES_OPTION, $filtered_templates, false );

			// Update last sync timestamp
			update_option( self::LAST_SYNC_OPTION, time(), false );

			WCH_Logger::info(
				'Template sync completed successfully',
				array(
					'total_templates'    => count( $templates ),
					'filtered_templates' => count( $filtered_templates ),
				)
			);

			return $filtered_templates;
		} catch ( Exception $e ) {
			WCH_Logger::error(
				'Template sync failed',
				array(
					'error' => $e->getMessage(),
				)
			);
			throw new WCH_Exception(
				'Failed to sync templates: ' . $e->getMessage(),
				'TEMPLATE_SYNC_ERROR',
				array( 'original_error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Fetch templates from WhatsApp API
	 *
	 * @param string $waba_id WhatsApp Business Account ID.
	 * @throws WCH_API_Exception If API call fails.
	 * @return array Raw templates from API.
	 */
	private function fetch_templates_from_api( $waba_id ) {
		$endpoint = "{$waba_id}/message_templates";
		$params   = array(
			'fields' => 'name,status,category,language,components',
			'limit'  => 100, // Max per page
		);

		$all_templates = array();
		$next_cursor   = null;

		do {
			if ( $next_cursor ) {
				$params['after'] = $next_cursor;
			}

			// Use the API client's base method to make request
			$response = $this->make_api_request( $endpoint, $params );

			if ( ! empty( $response['data'] ) ) {
				$all_templates = array_merge( $all_templates, $response['data'] );
			}

			// Check for pagination
			$next_cursor = $response['paging']['cursors']['after'] ?? null;
		} while ( $next_cursor );

		return $all_templates;
	}

	/**
	 * Make API request using the WhatsApp API client
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $params   Query parameters.
	 * @throws WCH_API_Exception If request fails.
	 * @return array API response.
	 */
	private function make_api_request( $endpoint, $params = array() ) {
		$access_token = $this->settings->get( 'api.access_token' );
		$api_version  = $this->settings->get( 'api.version', 'v18.0' );
		$base_url     = "https://graph.facebook.com/{$api_version}/";

		$url = $base_url . $endpoint;
		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new WCH_API_Exception(
				'API request failed: ' . $response->get_error_message(),
				'API_REQUEST_ERROR'
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['error'] ) ) {
			throw new WCH_API_Exception(
				$data['error']['message'] ?? 'Unknown API error',
				'API_ERROR',
				array(
					'error_code' => $data['error']['code'] ?? null,
					'error_type' => $data['error']['type'] ?? null,
				)
			);
		}

		return $data;
	}

	/**
	 * Filter templates by supported categories
	 *
	 * @param array $templates Raw templates from API.
	 * @return array Filtered templates.
	 */
	private function filter_templates_by_category( $templates ) {
		$filtered = array();

		foreach ( $templates as $template ) {
			$category = strtolower( $template['category'] ?? '' );

			// Map WhatsApp categories to our supported categories
			$mapped_category = $this->map_template_category( $category );

			if ( in_array( $mapped_category, self::SUPPORTED_CATEGORIES, true ) ) {
				$template['mapped_category'] = $mapped_category;
				$filtered[]                  = $template;
			}
		}

		return $filtered;
	}

	/**
	 * Map WhatsApp template category to our categories
	 *
	 * @param string $category WhatsApp category.
	 * @return string Mapped category or original if no mapping found.
	 */
	private function map_template_category( $category ) {
		$mapping = array(
			'transactional'      => 'order_confirmation',
			'order_details'      => 'order_status_update',
			'shipping_update'    => 'shipping_update',
			'issue_resolution'   => 'order_status_update',
			'appointment_update' => 'order_status_update',
			'auto_reply'         => 'abandoned_cart',
			'marketing'          => 'promotional',
		);

		return $mapping[ $category ] ?? $category;
	}

	/**
	 * Get all cached templates
	 *
	 * @return array Cached templates.
	 */
	public function get_templates() {
		$templates = get_option( self::TEMPLATES_OPTION, array() );
		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Get single template by name
	 *
	 * @param string $name Template name.
	 * @return array|null Template data or null if not found.
	 */
	public function get_template( $name ) {
		$templates = $this->get_templates();

		foreach ( $templates as $template ) {
			if ( $template['name'] === $name ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Render template with variable substitution
	 *
	 * Replaces {{1}}, {{2}}, etc. placeholders with provided values.
	 *
	 * @param string $name      Template name.
	 * @param array  $variables Array of variable values (indexed from 1).
	 * @throws WCH_Exception If template not found.
	 * @return array Rendered template components.
	 */
	public function render_template( $name, $variables = array() ) {
		$template = $this->get_template( $name );

		if ( ! $template ) {
			throw new WCH_Exception(
				"Template '{$name}' not found",
				'TEMPLATE_NOT_FOUND',
				array( 'template_name' => $name )
			);
		}

		// Track template usage
		$this->track_template_usage( $name );

		// Render components with variable substitution
		$rendered_components = array();

		foreach ( $template['components'] as $component ) {
			$rendered_component = $component;

			if ( 'HEADER' === $component['type'] && 'TEXT' === $component['format'] ) {
				$rendered_component['text'] = $this->replace_variables(
					$component['text'],
					$variables
				);
			} elseif ( 'BODY' === $component['type'] ) {
				$rendered_component['text'] = $this->replace_variables(
					$component['text'],
					$variables
				);
			} elseif ( 'FOOTER' === $component['type'] ) {
				// Footer doesn't have variables, but include it
				$rendered_component['text'] = $component['text'];
			}

			$rendered_components[] = $rendered_component;
		}

		return array(
			'name'       => $template['name'],
			'language'   => $template['language'],
			'status'     => $template['status'],
			'category'   => $template['mapped_category'] ?? $template['category'],
			'components' => $rendered_components,
		);
	}

	/**
	 * Replace variable placeholders in text
	 *
	 * @param string $text      Text with placeholders.
	 * @param array  $variables Variable values.
	 * @return string Text with variables replaced.
	 */
	private function replace_variables( $text, $variables ) {
		// Variables are 1-indexed
		foreach ( $variables as $index => $value ) {
			$placeholder = '{{' . ( $index + 1 ) . '}}';
			$text        = str_replace( $placeholder, $value, $text );
		}

		return $text;
	}

	/**
	 * Track template usage for analytics
	 *
	 * @param string $template_name Template name.
	 */
	private function track_template_usage( $template_name ) {
		$transient_key = self::USAGE_STATS_TRANSIENT_PREFIX . md5( $template_name );
		$stats         = get_transient( $transient_key );

		if ( false === $stats ) {
			$stats = array(
				'template_name' => $template_name,
				'usage_count'   => 0,
				'first_used'    => time(),
				'last_used'     => time(),
			);
		}

		++$stats['usage_count'];
		$stats['last_used'] = time();

		// Store for 30 days
		set_transient( $transient_key, $stats, 30 * DAY_IN_SECONDS );
	}

	/**
	 * Get template usage statistics
	 *
	 * @param string $template_name Template name.
	 * @return array|false Usage stats or false if not found.
	 */
	public function get_template_usage_stats( $template_name ) {
		$transient_key = self::USAGE_STATS_TRANSIENT_PREFIX . md5( $template_name );
		return get_transient( $transient_key );
	}

	/**
	 * Get all template usage statistics
	 *
	 * @return array Array of usage stats for all tracked templates.
	 */
	public function get_all_usage_stats() {
		$templates = $this->get_templates();
		$all_stats = array();

		foreach ( $templates as $template ) {
			$stats = $this->get_template_usage_stats( $template['name'] );
			if ( $stats ) {
				$all_stats[] = $stats;
			}
		}

		return $all_stats;
	}

	/**
	 * Get last sync timestamp
	 *
	 * @return int|false Timestamp or false if never synced.
	 */
	public function get_last_sync_time() {
		return get_option( self::LAST_SYNC_OPTION, false );
	}

	/**
	 * Clear cached templates
	 */
	public function clear_cache() {
		delete_option( self::TEMPLATES_OPTION );
		delete_option( self::LAST_SYNC_OPTION );
	}
}
