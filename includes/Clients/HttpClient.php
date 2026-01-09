<?php
/**
 * HTTP Client Implementation
 *
 * Generic HTTP client with circuit breaker and retry support.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Clients;

use WhatsAppCommerceHub\Contracts\Clients\HttpClientInterface;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;
use WhatsAppCommerceHub\Resilience\RetryPolicy;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HttpClient
 *
 * HTTP client with resilience features using WordPress HTTP API.
 */
class HttpClient implements HttpClientInterface {

	/**
	 * Default timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout = 30;

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	private int $max_retries = 3;

	/**
	 * Base URL for requests.
	 *
	 * @var string
	 */
	private string $base_url = '';

	/**
	 * Default headers.
	 *
	 * @var array
	 */
	private array $default_headers = array();

	/**
	 * SSL verification flag.
	 *
	 * @var bool
	 */
	private bool $ssl_verify = true;

	/**
	 * Request history.
	 *
	 * @var array
	 */
	private array $request_history = array();

	/**
	 * Maximum history size.
	 *
	 * @var int
	 */
	private int $max_history_size = 100;

	/**
	 * Last request information.
	 *
	 * @var array|null
	 */
	private ?array $last_request = null;

	/**
	 * Circuit breaker instance.
	 *
	 * @var CircuitBreaker|null
	 */
	private ?CircuitBreaker $circuit_breaker;

	/**
	 * Retry policy instance.
	 *
	 * @var RetryPolicy|null
	 */
	private ?RetryPolicy $retry_policy;

	/**
	 * Service name for circuit breaker.
	 *
	 * @var string
	 */
	private string $service_name;

	/**
	 * Constructor.
	 *
	 * @param string              $service_name    Service name for circuit breaker.
	 * @param CircuitBreaker|null $circuit_breaker Circuit breaker instance.
	 * @param RetryPolicy|null    $retry_policy    Retry policy instance.
	 */
	public function __construct(
		string $service_name = 'http_client',
		?CircuitBreaker $circuit_breaker = null,
		?RetryPolicy $retry_policy = null
	) {
		$this->service_name    = $service_name;
		$this->circuit_breaker = $circuit_breaker;
		$this->retry_policy    = $retry_policy;
	}

	/**
	 * Perform GET request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $headers Request headers.
	 * @param array  $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function get( string $url, array $headers = array(), array $options = array() ): array {
		return $this->request( 'GET', $url, null, $headers, $options );
	}

	/**
	 * Perform POST request.
	 *
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function post( string $url, $body = array(), array $headers = array(), array $options = array() ): array {
		return $this->request( 'POST', $url, $body, $headers, $options );
	}

	/**
	 * Perform PUT request.
	 *
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function put( string $url, $body = array(), array $headers = array(), array $options = array() ): array {
		return $this->request( 'PUT', $url, $body, $headers, $options );
	}

	/**
	 * Perform PATCH request.
	 *
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function patch( string $url, $body = array(), array $headers = array(), array $options = array() ): array {
		return $this->request( 'PATCH', $url, $body, $headers, $options );
	}

	/**
	 * Perform DELETE request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $headers Request headers.
	 * @param array  $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function delete( string $url, array $headers = array(), array $options = array() ): array {
		return $this->request( 'DELETE', $url, null, $headers, $options );
	}

	/**
	 * Perform generic HTTP request.
	 *
	 * @param string       $method  HTTP method.
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function request(
		string $method,
		string $url,
		$body = null,
		array $headers = array(),
		array $options = array()
	): array {
		// Build full URL.
		$full_url = $this->buildUrl( $url );

		// Check circuit breaker.
		if ( $this->circuit_breaker && ! $this->circuit_breaker->isAvailable( $this->service_name ) ) {
			throw new \RuntimeException(
				sprintf( 'Service %s is unavailable (circuit breaker open)', $this->service_name )
			);
		}

		// Merge headers.
		$merged_headers = array_merge( $this->default_headers, $headers );

		// Build request args.
		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => $options['timeout'] ?? $this->timeout,
			'headers'     => $merged_headers,
			'sslverify'   => $options['ssl_verify'] ?? $this->ssl_verify,
			'redirection' => $options['redirection'] ?? 5,
		);

		// Add body for methods that support it.
		if ( null !== $body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = is_array( $body ) ? wp_json_encode( $body ) : $body;

			// Auto-set content type for JSON.
			if ( is_array( $body ) && ! isset( $merged_headers['Content-Type'] ) ) {
				$args['headers']['Content-Type'] = 'application/json';
			}
		}

		// Execute with retry policy.
		$attempt       = 0;
		$max_attempts  = $this->retry_policy ? $this->max_retries : 1;
		$last_error    = null;
		$start_time    = microtime( true );

		while ( $attempt < $max_attempts ) {
			$attempt++;
			$attempt_start = microtime( true );

			try {
				$response = wp_remote_request( $full_url, $args );

				if ( is_wp_error( $response ) ) {
					throw new \RuntimeException( $response->get_error_message() );
				}

				$status_code = wp_remote_retrieve_response_code( $response );

				// Record success with circuit breaker.
				if ( $this->circuit_breaker ) {
					$this->circuit_breaker->recordSuccess( $this->service_name );
				}

				// Build result.
				$result = array(
					'status_code' => $status_code,
					'headers'     => wp_remote_retrieve_headers( $response )->getAll(),
					'body'        => wp_remote_retrieve_body( $response ),
				);

				// Record request info.
				$duration_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );
				$this->recordRequest( $full_url, $method, $status_code, $duration_ms );

				// Check for server errors that might be retryable.
				if ( $status_code >= 500 && $this->retry_policy && $attempt < $max_attempts ) {
					$delay = $this->retry_policy->getDelay( $attempt );
					usleep( (int) ( $delay * 1000000 ) );
					continue;
				}

				return $result;

			} catch ( \Exception $e ) {
				$last_error = $e;

				// Record failure with circuit breaker.
				if ( $this->circuit_breaker ) {
					$this->circuit_breaker->recordFailure( $this->service_name );
				}

				// Check if we should retry.
				if ( $this->retry_policy && $attempt < $max_attempts ) {
					$delay = $this->retry_policy->getDelay( $attempt );
					usleep( (int) ( $delay * 1000000 ) );
					continue;
				}

				break;
			}
		}

		// Record failed request.
		$duration_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );
		$this->recordRequest( $full_url, $method, 0, $duration_ms, true );

		throw new \RuntimeException(
			sprintf(
				'HTTP request failed after %d attempts: %s',
				$attempt,
				$last_error ? $last_error->getMessage() : 'Unknown error'
			)
		);
	}

	/**
	 * Perform multipart form data upload.
	 *
	 * @param string $url     Request URL.
	 * @param array  $fields  Form fields.
	 * @param array  $files   Files to upload.
	 * @param array  $headers Additional headers.
	 * @param array  $options Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If upload fails.
	 */
	public function upload(
		string $url,
		array $fields = array(),
		array $files = array(),
		array $headers = array(),
		array $options = array()
	): array {
		$full_url = $this->buildUrl( $url );

		// Generate boundary.
		$boundary = wp_generate_password( 24, false );

		// Build multipart body.
		$body = '';

		// Add fields.
		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= "{$value}\r\n";
		}

		// Add files.
		foreach ( $files as $name => $file ) {
			$file_path = $file['path'] ?? '';
			$mime_type = $file['mime_type'] ?? 'application/octet-stream';
			$filename  = $file['filename'] ?? basename( $file_path );

			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				throw new \RuntimeException( "File not found or not readable: {$file_path}" );
			}

			$file_contents = file_get_contents( $file_path );
			if ( false === $file_contents ) {
				throw new \RuntimeException( "Failed to read file: {$file_path}" );
			}

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
			$body .= "Content-Type: {$mime_type}\r\n\r\n";
			$body .= "{$file_contents}\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		// Merge headers with multipart content type.
		$merged_headers                 = array_merge( $this->default_headers, $headers );
		$merged_headers['Content-Type'] = "multipart/form-data; boundary={$boundary}";

		// Build request args.
		$args = array(
			'method'    => 'POST',
			'timeout'   => $options['timeout'] ?? max( $this->timeout, 120 ),
			'headers'   => $merged_headers,
			'body'      => $body,
			'sslverify' => $options['ssl_verify'] ?? $this->ssl_verify,
		);

		$start_time = microtime( true );
		$response   = wp_remote_request( $full_url, $args );

		if ( is_wp_error( $response ) ) {
			$duration_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );
			$this->recordRequest( $full_url, 'POST', 0, $duration_ms, true );

			if ( $this->circuit_breaker ) {
				$this->circuit_breaker->recordFailure( $this->service_name );
			}

			throw new \RuntimeException( $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$duration_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );
		$this->recordRequest( $full_url, 'POST', $status_code, $duration_ms );

		if ( $this->circuit_breaker ) {
			$this->circuit_breaker->recordSuccess( $this->service_name );
		}

		return array(
			'status_code' => $status_code,
			'headers'     => wp_remote_retrieve_headers( $response )->getAll(),
			'body'        => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Set default timeout.
	 *
	 * @param int $seconds Timeout in seconds.
	 */
	public function setTimeout( int $seconds ): void {
		$this->timeout = max( 1, $seconds );
	}

	/**
	 * Set maximum retry attempts.
	 *
	 * @param int $attempts Maximum attempts.
	 */
	public function setMaxRetries( int $attempts ): void {
		$this->max_retries = max( 1, $attempts );
	}

	/**
	 * Set base URL.
	 *
	 * @param string $base_url Base URL.
	 */
	public function setBaseUrl( string $base_url ): void {
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * Set default headers.
	 *
	 * @param array $headers Default headers.
	 */
	public function setDefaultHeaders( array $headers ): void {
		$this->default_headers = $headers;
	}

	/**
	 * Add default header.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 */
	public function addDefaultHeader( string $name, string $value ): void {
		$this->default_headers[ $name ] = $value;
	}

	/**
	 * Set SSL verification.
	 *
	 * @param bool $verify Whether to verify SSL.
	 */
	public function setSslVerification( bool $verify ): void {
		$this->ssl_verify = $verify;
	}

	/**
	 * Get last request information.
	 *
	 * @return array|null Last request info.
	 */
	public function getLastRequest(): ?array {
		return $this->last_request;
	}

	/**
	 * Get request history.
	 *
	 * @param int $limit Maximum requests to return.
	 * @return array Request history.
	 */
	public function getRequestHistory( int $limit = 10 ): array {
		return array_slice( $this->request_history, -$limit );
	}

	/**
	 * Clear request history.
	 */
	public function clearHistory(): void {
		$this->request_history = array();
		$this->last_request    = null;
	}

	/**
	 * Check if client is available.
	 *
	 * @return bool True if available.
	 */
	public function isAvailable(): bool {
		if ( ! $this->circuit_breaker ) {
			return true;
		}

		return $this->circuit_breaker->isAvailable( $this->service_name );
	}

	/**
	 * Get health status.
	 *
	 * @return array Health status.
	 */
	public function getHealthStatus(): array {
		$circuit_state    = 'closed';
		$average_latency  = null;

		if ( $this->circuit_breaker ) {
			$state = $this->circuit_breaker->getState( $this->service_name );
			$circuit_state = $state['state'] ?? 'closed';
		}

		// Calculate average latency from recent requests.
		$recent_requests = $this->getRequestHistory( 10 );
		if ( ! empty( $recent_requests ) ) {
			$total_duration = array_sum( array_column( $recent_requests, 'duration_ms' ) );
			$average_latency = (int) ( $total_duration / count( $recent_requests ) );
		}

		return array(
			'healthy'            => 'open' !== $circuit_state,
			'circuit_state'      => $circuit_state,
			'average_latency_ms' => $average_latency,
		);
	}

	/**
	 * Build full URL from relative path.
	 *
	 * @param string $url URL or path.
	 * @return string Full URL.
	 */
	private function buildUrl( string $url ): string {
		// If URL is already absolute, return as-is.
		if ( preg_match( '#^https?://#', $url ) ) {
			return $url;
		}

		// Prepend base URL.
		if ( $this->base_url ) {
			return $this->base_url . '/' . ltrim( $url, '/' );
		}

		return $url;
	}

	/**
	 * Record request information.
	 *
	 * @param string $url         Request URL.
	 * @param string $method      HTTP method.
	 * @param int    $status_code Response status code.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param bool   $failed      Whether request failed.
	 */
	private function recordRequest(
		string $url,
		string $method,
		int $status_code,
		int $duration_ms,
		bool $failed = false
	): void {
		$request_info = array(
			'url'         => $url,
			'method'      => $method,
			'status_code' => $status_code,
			'duration_ms' => $duration_ms,
			'failed'      => $failed,
			'timestamp'   => time(),
		);

		$this->last_request     = $request_info;
		$this->request_history[] = $request_info;

		// Trim history if too large.
		if ( count( $this->request_history ) > $this->max_history_size ) {
			$this->request_history = array_slice(
				$this->request_history,
				-$this->max_history_size
			);
		}
	}
}
