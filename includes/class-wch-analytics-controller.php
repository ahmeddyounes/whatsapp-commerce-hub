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
						),
						'days'  => array(
							'required'          => false,
							'default'           => 30,
							'sanitize_callback' => 'absint',
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
}
