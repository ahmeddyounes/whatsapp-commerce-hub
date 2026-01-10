<?php
/**
 * HTTP Client Interface
 *
 * Contract for generic HTTP operations with resilience features.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Contracts\Clients;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface HttpClientInterface
 *
 * Defines the contract for HTTP client operations with circuit breaker
 * and retry policy support.
 */
interface HttpClientInterface {

	/**
	 * Perform GET request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $headers Request headers.
	 * @param array  $options Additional options (timeout, etc.).
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function get( string $url, array $headers = array(), array $options = array() ): array;

	/**
	 * Perform POST request.
	 *
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options (timeout, etc.).
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function post( string $url, $body = array(), array $headers = array(), array $options = array() ): array;

	/**
	 * Perform PUT request.
	 *
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options (timeout, etc.).
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function put( string $url, $body = array(), array $headers = array(), array $options = array() ): array;

	/**
	 * Perform PATCH request.
	 *
	 * @param string       $url     Request URL.
	 * @param array|string $body    Request body.
	 * @param array        $headers Request headers.
	 * @param array        $options Additional options (timeout, etc.).
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function patch( string $url, $body = array(), array $headers = array(), array $options = array() ): array;

	/**
	 * Perform DELETE request.
	 *
	 * @param string $url     Request URL.
	 * @param array  $headers Request headers.
	 * @param array  $options Additional options (timeout, etc.).
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If request fails after retries.
	 */
	public function delete( string $url, array $headers = array(), array $options = array() ): array;

	/**
	 * Perform generic HTTP request.
	 *
	 * @param string       $method  HTTP method (GET, POST, PUT, PATCH, DELETE).
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
	): array;

	/**
	 * Perform multipart form data upload.
	 *
	 * @param string $url      Request URL.
	 * @param array  $fields   Form fields.
	 * @param array  $files    Files to upload (key => ['path' => ..., 'mime_type' => ...]).
	 * @param array  $headers  Additional headers.
	 * @param array  $options  Additional options.
	 * @return array{status_code: int, headers: array, body: string}
	 * @throws \RuntimeException If upload fails.
	 */
	public function upload(
		string $url,
		array $fields = array(),
		array $files = array(),
		array $headers = array(),
		array $options = array()
	): array;

	/**
	 * Set default timeout for requests.
	 *
	 * @param int $seconds Timeout in seconds.
	 * @return void
	 */
	public function setTimeout( int $seconds ): void;

	/**
	 * Set maximum retry attempts.
	 *
	 * @param int $attempts Maximum retry attempts.
	 * @return void
	 */
	public function setMaxRetries( int $attempts ): void;

	/**
	 * Set base URL for all requests.
	 *
	 * @param string $base_url Base URL.
	 * @return void
	 */
	public function setBaseUrl( string $base_url ): void;

	/**
	 * Set default headers for all requests.
	 *
	 * @param array $headers Default headers.
	 * @return void
	 */
	public function setDefaultHeaders( array $headers ): void;

	/**
	 * Add default header.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function addDefaultHeader( string $name, string $value ): void;

	/**
	 * Enable or disable SSL verification.
	 *
	 * @param bool $verify Whether to verify SSL certificates.
	 * @return void
	 */
	public function setSslVerification( bool $verify ): void;

	/**
	 * Get last request information.
	 *
	 * @return array{url: string, method: string, duration_ms: int, status_code: int}|null
	 */
	public function getLastRequest(): ?array;

	/**
	 * Get request history.
	 *
	 * @param int $limit Maximum requests to return.
	 * @return array Array of request information.
	 */
	public function getRequestHistory( int $limit = 10 ): array;

	/**
	 * Clear request history.
	 *
	 * @return void
	 */
	public function clearHistory(): void;

	/**
	 * Check if client is available (circuit breaker state).
	 *
	 * @return bool True if available.
	 */
	public function isAvailable(): bool;

	/**
	 * Get health status.
	 *
	 * @return array{healthy: bool, circuit_state: string, average_latency_ms: int|null}
	 */
	public function getHealthStatus(): array;
}
