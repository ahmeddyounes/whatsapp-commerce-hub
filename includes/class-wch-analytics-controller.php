<?php
/**
 * Analytics REST Controller
 *
 * Handles REST API endpoints for analytics data.
 *
 * @package WhatsApp_Commerce_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * WCH_Analytics_Controller class.
 */
class WCH_Analytics_Controller extends WCH_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wch/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'analytics';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'period' => array(
							'required'          => false,
							'default'           => 'today',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_period' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/orders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_orders' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/revenue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_revenue' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_top_products' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'limit' => array(
							'required'          => false,
							'default'           => 10,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_limit' ),
						),
						'days'  => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversations' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'required'          => false,
							'default'           => 7,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/metrics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_metrics' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/customers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_customer_insights' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/funnel',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_funnel' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'export_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'days' => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_days' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Get analytics summary
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_summary( $request ) {
		$period = $request->get_param( 'period' );

		try {
			$data = WCH_Analytics_Data::get_summary( $period );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics summary error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get orders over time
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_orders( $request ) {
		$days = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_orders_over_time( $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics orders error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get revenue by day
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_revenue( $request ) {
		$days = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_revenue_by_day( $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics revenue error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get top products
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_top_products( $request ) {
		$limit = $request->get_param( 'limit' );
		$days  = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_top_products( $limit, $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics products error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get conversation heatmap
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversations( $request ) {
		$days = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_conversation_heatmap( $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics conversations error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get detailed metrics
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_metrics( $request ) {
		$days = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_detailed_metrics( $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics metrics error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get customer insights
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_customer_insights( $request ) {
		$days = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_customer_insights( $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics customers error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get funnel data
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_funnel( $request ) {
		$days = $request->get_param( 'days' );

		try {
			$data = WCH_Analytics_Data::get_funnel_data( $days );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics funnel error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Export analytics data
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_data( $request ) {
		$type = $request->get_param( 'type' );
		$days = $request->get_param( 'days' );

		try {
			$data = array();

			switch ( $type ) {
				case 'orders':
					$data = WCH_Analytics_Data::get_orders_over_time( $days );
					break;
				case 'revenue':
					$data = WCH_Analytics_Data::get_revenue_by_day( $days );
					break;
				case 'products':
					$data = WCH_Analytics_Data::get_top_products( 50, $days );
					break;
				case 'metrics':
					$data = WCH_Analytics_Data::get_detailed_metrics( $days );
					break;
				case 'funnel':
					$data = WCH_Analytics_Data::get_funnel_data( $days );
					break;
				default:
					return new WP_Error(
						'invalid_export_type',
						'Invalid export type',
						array( 'status' => 400 )
					);
			}

			$filename  = 'wch-analytics-' . $type . '-' . gmdate( 'Y-m-d-His' ) . '.csv';
			$file_path = WCH_Analytics_Data::export_to_csv( $data, $filename );

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'file_url' => wp_upload_dir()['url'] . '/' . $filename,
						'filename' => $filename,
					),
				),
				200
			);
		} catch ( Exception $e ) {
			WCH_Logger::log( 'Analytics export error: ' . $e->getMessage(), 'error' );

			return new WP_Error(
				'analytics_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Check if user has permission to access analytics
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Validate period parameter
	 *
	 * @param string $value Value to validate.
	 * @return bool
	 */
	public function validate_period( $value ) {
		return in_array( $value, array( 'today', 'week', 'month' ), true );
	}

	/**
	 * Validate days parameter (1-365 range).
	 *
	 * Prevents excessive date ranges that could cause expensive database queries
	 * or potential DoS attacks via resource exhaustion.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   Parameter name.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_days( $value, $request, $param ) {
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

		if ( $days > 365 ) {
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
	 * Validate limit parameter (1-100 range).
	 *
	 * Prevents excessively large result sets that could cause memory exhaustion
	 * or slow response times.
	 *
	 * @param mixed           $value   Value to validate.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   Parameter name.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_limit( $value, $request, $param ) {
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

		if ( $limit > 100 ) {
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
	 * Get the schema for analytics summary items.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'analytics',
			'type'       => 'object',
			'properties' => array(
				'total_conversations' => array(
					'description' => 'Total number of conversations',
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_orders'        => array(
					'description' => 'Total number of orders',
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_revenue'       => array(
					'description' => 'Total revenue',
					'type'        => 'number',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);
	}
}
