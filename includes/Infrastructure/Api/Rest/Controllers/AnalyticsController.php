<?php
/**
 * Analytics REST Controller
 *
 * Handles REST API endpoints for analytics data.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 3.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Infrastructure\Api\Rest\Controllers;

use WhatsAppCommerceHub\Infrastructure\Api\Rest\RestController;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use Exception;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AnalyticsController
 *
 * REST API controller for analytics endpoints.
 */
class AnalyticsController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'analytics';

	/**
	 * Valid periods for summary endpoint.
	 *
	 * @var array
	 */
	private const VALID_PERIODS = array( 'today', 'week', 'month' );

	/**
	 * Maximum days allowed for date range queries.
	 *
	 * @var int
	 */
	private const MAX_DAYS = 365;

	/**
	 * Maximum items per page.
	 *
	 * @var int
	 */
	private const MAX_LIMIT = 100;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// Summary endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSummary' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'period' => array(
							'required'          => false,
							'default'           => 'today',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validatePeriod' ),
						),
					),
				),
			)
		);

		// Orders over time endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getOrders' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getDaysArgs( 30 ),
				),
			)
		);

		// Revenue by day endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/revenue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getRevenue' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getDaysArgs( 30 ),
				),
			)
		);

		// Top products endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getTopProducts' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array_merge(
						$this->getDaysArgs( 30 ),
						array(
							'limit' => array(
								'required'          => false,
								'default'           => 10,
								'sanitize_callback' => 'absint',
								'validate_callback' => array( $this, 'validateLimit' ),
							),
						)
					),
				),
			)
		);

		// Conversation heatmap endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getConversations' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getDaysArgs( 7 ),
				),
			)
		);

		// Detailed metrics endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/metrics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getMetrics' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getDaysArgs( 30 ),
				),
			)
		);

		// Customer insights endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/customers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getCustomerInsights' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getDaysArgs( 30 ),
				),
			)
		);

		// Funnel data endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/funnel',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getFunnel' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => $this->getDaysArgs( 30 ),
				),
			)
		);

		// Export endpoint.
		register_rest_route(
			$this->apiNamespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'exportData' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array_merge(
						$this->getDaysArgs( 30 ),
						array(
							'type' => array(
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
								'validate_callback' => array( $this, 'validateExportType' ),
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Get default days argument configuration.
	 *
	 * @param int $default Default number of days.
	 * @return array
	 */
	private function getDaysArgs( int $default = 30 ): array {
		return array(
			'days' => array(
				'required'          => false,
				'default'           => $default,
				'sanitize_callback' => 'absint',
				'validate_callback' => array( $this, 'validateDays' ),
			),
		);
	}

	/**
	 * Get analytics summary.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getSummary( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$period = $request->get_param( 'period' );

		try {
			$data = \WCH_Analytics_Data::get_summary( $period );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics summary error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get orders over time.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getOrders( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$days = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_orders_over_time( $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics orders error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get revenue by day.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getRevenue( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$days = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_revenue_by_day( $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics revenue error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get top products.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getTopProducts( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$limit = $request->get_param( 'limit' );
		$days  = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_top_products( $limit, $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics products error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get conversation heatmap.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getConversations( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$days = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_conversation_heatmap( $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics conversations error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get detailed metrics.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getMetrics( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$days = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_detailed_metrics( $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics metrics error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get customer insights.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getCustomerInsights( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$days = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_customer_insights( $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics customers error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get funnel data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getFunnel( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$days = $request->get_param( 'days' );

		try {
			$data = \WCH_Analytics_Data::get_funnel_data( $days );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => $data,
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics funnel error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Export analytics data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function exportData( WP_REST_Request $request ) {
		$rateLimitResult = $this->checkRateLimit( 'admin' );
		if ( is_wp_error( $rateLimitResult ) ) {
			return $rateLimitResult;
		}

		$type = $request->get_param( 'type' );
		$days = $request->get_param( 'days' );

		try {
			$data = $this->getExportData( $type, $days );

			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$filename = 'wch-analytics-' . $type . '-' . gmdate( 'Y-m-d-His' ) . '.csv';
			\WCH_Analytics_Data::export_to_csv( $data, $filename );

			return $this->prepareResponse(
				array(
					'success' => true,
					'data'    => array(
						'file_url' => wp_upload_dir()['url'] . '/' . $filename,
						'filename' => $filename,
					),
				),
				$request
			);
		} catch ( Exception $e ) {
			$this->log( 'Analytics export error: ' . $e->getMessage(), array(), 'error' );

			return $this->prepareError(
				'analytics_error',
				$e->getMessage(),
				array(),
				500
			);
		}
	}

	/**
	 * Get export data based on type.
	 *
	 * @param string $type Export type.
	 * @param int    $days Number of days.
	 * @return array|WP_Error
	 */
	private function getExportData( string $type, int $days ) {
		switch ( $type ) {
			case 'orders':
				return \WCH_Analytics_Data::get_orders_over_time( $days );
			case 'revenue':
				return \WCH_Analytics_Data::get_revenue_by_day( $days );
			case 'products':
				return \WCH_Analytics_Data::get_top_products( 50, $days );
			case 'metrics':
				return \WCH_Analytics_Data::get_detailed_metrics( $days );
			case 'funnel':
				return \WCH_Analytics_Data::get_funnel_data( $days );
			default:
				return $this->prepareError(
					'invalid_export_type',
					__( 'Invalid export type', 'whatsapp-commerce-hub' ),
					array(),
					400
				);
		}
	}

	/**
	 * Validate period parameter.
	 *
	 * @param string $value Value to validate.
	 * @return bool
	 */
	public function validatePeriod( string $value ): bool {
		return in_array( $value, self::VALID_PERIODS, true );
	}

	/**
	 * Validate days parameter.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool|WP_Error
	 */
	public function validateDays( $value, WP_REST_Request $request, string $param ) {
		$days = absint( $value );

		if ( $days < 1 ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s must be at least 1.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( $days > self::MAX_DAYS ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s cannot exceed 365 days.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate limit parameter.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 * @return bool|WP_Error
	 */
	public function validateLimit( $value, WP_REST_Request $request, string $param ) {
		$limit = absint( $value );

		if ( $limit < 1 ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s must be at least 1.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( $limit > self::MAX_LIMIT ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( '%s cannot exceed 100.', 'whatsapp-commerce-hub' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate export type parameter.
	 *
	 * @param string $value Value to validate.
	 * @return bool
	 */
	public function validateExportType( string $value ): bool {
		$validTypes = array( 'orders', 'revenue', 'products', 'metrics', 'funnel' );
		return in_array( $value, $validTypes, true );
	}

	/**
	 * Get the item schema for analytics.
	 *
	 * @return array
	 */
	public function getItemSchema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'analytics',
			'type'       => 'object',
			'properties' => array(
				'total_conversations' => array(
					'description' => __( 'Total number of conversations', 'whatsapp-commerce-hub' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_orders'        => array(
					'description' => __( 'Total number of orders', 'whatsapp-commerce-hub' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_revenue'       => array(
					'description' => __( 'Total revenue', 'whatsapp-commerce-hub' ),
					'type'        => 'number',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
	}
}
