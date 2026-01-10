<?php
/**
 * WhatsApp API Client
 *
 * WhatsApp Business Cloud API client with circuit breaker resilience.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

namespace WhatsAppCommerceHub\Clients;

use WhatsAppCommerceHub\Contracts\Clients\WhatsAppClientInterface;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;
use WhatsAppCommerceHub\Resilience\CircuitOpenException;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WhatsAppApiClient
 *
 * Implements WhatsApp API operations with circuit breaker protection.
 */
class WhatsAppApiClient implements WhatsAppClientInterface {

	/**
	 * Phone number ID.
	 *
	 * @var string
	 */
	private string $phone_number_id;

	/**
	 * Access token.
	 *
	 * @var string
	 */
	private string $access_token;

	/**
	 * API version.
	 *
	 * @var string
	 */
	private string $api_version;

	/**
	 * Base API URL.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Circuit breaker instance.
	 *
	 * @var CircuitBreaker
	 */
	private CircuitBreaker $circuit_breaker;

	/**
	 * Request timeout in seconds.
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
	 * Last request information.
	 *
	 * @var array|null
	 */
	private ?array $last_request = null;

	/**
	 * Constructor.
	 *
	 * @param string         $phone_number_id Phone number ID.
	 * @param string         $access_token    Access token.
	 * @param CircuitBreaker $circuit_breaker Circuit breaker instance.
	 * @param string         $api_version     API version (default: v18.0).
	 */
	public function __construct(
		string $phone_number_id,
		string $access_token,
		CircuitBreaker $circuit_breaker,
		string $api_version = 'v18.0'
	) {
		$this->phone_number_id = $phone_number_id;
		$this->access_token    = $access_token;
		$this->circuit_breaker = $circuit_breaker;
		$this->api_version     = $api_version;
		$this->base_url        = 'https://graph.facebook.com/' . $this->api_version . '/';
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendTextMessage( string $to, string $text, bool $preview_url = false ): array {
		$this->validatePhoneNumber( $to );

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array(
				'preview_url' => $preview_url,
				'body'        => $text,
			),
		);

		return $this->sendMessage( $body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendInteractiveList(
		string $to,
		string $header,
		string $body,
		string $footer,
		string $button_text,
		array $sections
	): array {
		$this->validatePhoneNumber( $to );

		$message_body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'interactive',
			'interactive'       => array(
				'type'   => 'list',
				'header' => array(
					'type' => 'text',
					'text' => $header,
				),
				'body'   => array(
					'text' => $body,
				),
				'footer' => array(
					'text' => $footer,
				),
				'action' => array(
					'button'   => $button_text,
					'sections' => $sections,
				),
			),
		);

		return $this->sendMessage( $message_body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendInteractiveButtons(
		string $to,
		string $header,
		string $body,
		string $footer,
		array $buttons
	): array {
		$this->validatePhoneNumber( $to );

		$message_body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'interactive',
			'interactive'       => array(
				'type'   => 'button',
				'header' => array(
					'type' => 'text',
					'text' => $header,
				),
				'body'   => array(
					'text' => $body,
				),
				'footer' => array(
					'text' => $footer,
				),
				'action' => array(
					'buttons' => $buttons,
				),
			),
		);

		return $this->sendMessage( $message_body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendTemplate(
		string $to,
		string $template_name,
		string $language_code,
		array $components = []
	): array {
		$this->validatePhoneNumber( $to );

		$template = array(
			'name'     => $template_name,
			'language' => array(
				'code' => $language_code,
			),
		);

		if ( ! empty( $components ) ) {
			$template['components'] = $components;
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'template',
			'template'          => $template,
		);

		return $this->sendMessage( $body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendImage( string $to, string $image_url_or_id, ?string $caption = null ): array {
		$this->validatePhoneNumber( $to );

		$image = [];
		if ( $this->isMediaId( $image_url_or_id ) ) {
			$image['id'] = $image_url_or_id;
		} else {
			$image['link'] = $image_url_or_id;
		}

		if ( $caption ) {
			$image['caption'] = $caption;
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'image',
			'image'             => $image,
		);

		return $this->sendMessage( $body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendDocument(
		string $to,
		string $document_url_or_id,
		?string $filename = null,
		?string $caption = null
	): array {
		$this->validatePhoneNumber( $to );

		$document = [];
		if ( $this->isMediaId( $document_url_or_id ) ) {
			$document['id'] = $document_url_or_id;
		} else {
			$document['link'] = $document_url_or_id;
		}

		if ( $filename ) {
			$document['filename'] = $filename;
		}
		if ( $caption ) {
			$document['caption'] = $caption;
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'document',
			'document'          => $document,
		);

		return $this->sendMessage( $body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendProductMessage( string $to, string $catalog_id, string $product_retailer_id ): array {
		$this->validatePhoneNumber( $to );

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'interactive',
			'interactive'       => array(
				'type'   => 'product',
				'action' => array(
					'catalog_id'          => $catalog_id,
					'product_retailer_id' => $product_retailer_id,
				),
			),
		);

		return $this->sendMessage( $body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendProductList(
		string $to,
		string $catalog_id,
		string $header,
		string $body,
		array $sections
	): array {
		$this->validatePhoneNumber( $to );

		$message_body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'interactive',
			'interactive'       => array(
				'type'   => 'product_list',
				'header' => array(
					'type' => 'text',
					'text' => $header,
				),
				'body'   => array(
					'text' => $body,
				),
				'action' => array(
					'catalog_id' => $catalog_id,
					'sections'   => $sections,
				),
			),
		);

		return $this->sendMessage( $message_body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function markAsRead( string $message_id ): array {
		$body = array(
			'messaging_product' => 'whatsapp',
			'status'            => 'read',
			'message_id'        => $message_id,
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array( 'status' => true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMediaUrl( string $media_id ): string {
		$response = $this->request( 'GET', $media_id );

		if ( empty( $response['url'] ) ) {
			throw new \RuntimeException( 'Media URL not found in response' );
		}

		return $response['url'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function uploadMedia( string $file_path, string $mime_type ): string {
		if ( ! file_exists( $file_path ) ) {
			throw new \InvalidArgumentException( 'File not found: ' . $file_path );
		}

		$boundary = wp_generate_password( 24, false );
		$body     = $this->buildMultipartBody( $file_path, $mime_type, $boundary );

		$response = $this->circuit_breaker->call(
			function () use ( $body, $boundary ) {
				$url  = $this->base_url . $this->phone_number_id . '/media';
				$args = array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->access_token,
						'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					),
					'body'    => $body,
					'timeout' => 60,
				);

				$response = wp_remote_request( $url, $args );

				if ( is_wp_error( $response ) ) {
					throw new \RuntimeException( $response->get_error_message() );
				}

				$response_code = wp_remote_retrieve_response_code( $response );
				$raw_body      = wp_remote_retrieve_body( $response );
				$response_body = json_decode( $raw_body, true );

				// Validate JSON decode succeeded.
				if ( ! is_array( $response_body ) ) {
					throw new \RuntimeException(
						sprintf( 'Invalid JSON response from WhatsApp API (HTTP %d)', $response_code )
					);
				}

				if ( $response_code >= 400 ) {
					$error_msg = $response_body['error']['message'] ?? 'Upload failed';
					throw new \RuntimeException( $error_msg );
				}

				return $response_body;
			}
		);

		if ( empty( $response['id'] ) ) {
			throw new \RuntimeException( 'Media ID not found in response' );
		}

		return $response['id'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function getBusinessProfile(): array {
		$fields   = 'about,address,description,email,profile_picture_url,websites,vertical';
		$response = $this->request( 'GET', $this->phone_number_id . '/whatsapp_business_profile?fields=' . $fields );

		return $response['data'][0] ?? [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateBusinessProfile( array $data ): array {
		$response = $this->request(
			'POST',
			$this->phone_number_id . '/whatsapp_business_profile',
			$data
		);

		return $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function createCatalogProduct( string $catalog_id, array $product_data ): array {
		return $this->request( 'POST', $catalog_id . '/products', $product_data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function updateCatalogProduct( string $catalog_id, string $product_id, array $product_data ): array {
		return $this->request(
			'POST',
			$catalog_id . '/products',
			array_merge(
				$product_data,
				array( 'retailer_id' => $product_id )
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function deleteCatalogProduct( string $catalog_id, string $product_id ): array {
		return $this->request(
			'DELETE',
			$catalog_id . '/products',
			array(
				'requests' => array(
					array(
						'method'      => 'DELETE',
						'retailer_id' => $product_id,
					),
				),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCatalogProduct( string $catalog_id, string $product_id ): array {
		$response = $this->request(
			'GET',
			$catalog_id . '/products?filter=' . rawurlencode( '{"retailer_id":"' . $product_id . '"}' )
		);

		return $response['data'][0] ?? [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function listCatalogProducts( string $catalog_id, array $params = [] ): array {
		$query = http_build_query( $params );
		$url   = $catalog_id . '/products' . ( $query ? '?' . $query : '' );

		return $this->request( 'GET', $url );
	}

	/**
	 * {@inheritdoc}
	 */
	public function validatePhoneNumber( string $phone ): bool {
		if ( ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			throw new \InvalidArgumentException(
				'Phone number must be in E.164 format (e.g., +1234567890)'
			);
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool {
		return $this->circuit_breaker->isAvailable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getHealthStatus(): array {
		$metrics = $this->circuit_breaker->getMetrics();

		return array(
			'healthy'    => $this->isAvailable(),
			'latency_ms' => $this->last_request['duration_ms'] ?? null,
			'last_error' => $metrics['last_failure']['message'] ?? null,
		);
	}

	/**
	 * Send a message via WhatsApp API.
	 *
	 * @param array $body Message body.
	 * @return array Response with message_id and status.
	 */
	private function sendMessage( array $body ): array {
		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		$message_id = null;
		if ( ! empty( $response['messages'][0]['id'] ) ) {
			$message_id = $response['messages'][0]['id'];
		}

		return array(
			'message_id' => $message_id,
			'status'     => 'sent',
		);
	}

	/**
	 * Make HTTP request to WhatsApp API with circuit breaker.
	 *
	 * When the circuit is open, the request is queued for later retry and a
	 * queued response is returned instead of throwing an exception. This allows
	 * graceful degradation - messages will be sent when the API recovers.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @return array Response data.
	 * @throws \RuntimeException If request fails and cannot be queued.
	 */
	private function request( string $method, string $endpoint, array $body = [] ): array {
		return $this->circuit_breaker->call(
			function () use ( $method, $endpoint, $body ) {
				return $this->executeRequest( $method, $endpoint, $body );
			},
			function () use ( $method, $endpoint, $body ) {
				// Fallback: queue for later retry if circuit is open.
				$queued_id = $this->queueRequest( $method, $endpoint, $body );

				// Fire action for monitoring/logging.
				do_action( 'wch_whatsapp_request_queued', $method, $endpoint, $queued_id );

				// Return a queued response instead of throwing.
				// This allows the caller to continue gracefully.
				return array(
					'status'    => 'queued',
					'queued_id' => $queued_id,
					'message'   => 'WhatsApp API temporarily unavailable. Request queued for retry.',
					'messages'  => array(
						array(
							'id'     => 'queued_' . $queued_id,
							'status' => 'queued',
						),
					),
				);
			},
			false // Don't throw when circuit is open - use fallback.
		);
	}

	/**
	 * Queue a request for later retry.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @return string The queued request ID.
	 */
	private function queueRequest( string $method, string $endpoint, array $body ): string {
		$queued_id = wp_generate_uuid4();

		// Schedule the request for retry using Action Scheduler.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + 60, // Retry in 60 seconds.
				'wch_retry_whatsapp_request',
				array(
					'queued_id' => $queued_id,
					'method'    => $method,
					'endpoint'  => $endpoint,
					'body'      => $body,
				),
				'wch-whatsapp-retry'
			);
		} else {
			// Fallback to WordPress cron.
			wp_schedule_single_event(
				time() + 60,
				'wch_retry_whatsapp_request',
				array(
					array(
						'queued_id' => $queued_id,
						'method'    => $method,
						'endpoint'  => $endpoint,
						'body'      => $body,
					),
				)
			);
		}

		return $queued_id;
	}

	/**
	 * Execute the actual HTTP request with retry logic.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @return array Response data.
	 * @throws \RuntimeException If request fails after retries.
	 */
	private function executeRequest( string $method, string $endpoint, array $body = [] ): array {
		$url     = $this->base_url . $endpoint;
		$attempt = 0;
		$start   = microtime( true );

		while ( $attempt < $this->max_retries ) {
			++$attempt;

			$args = array(
				'method'    => $method,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
				),
				'timeout'   => $this->timeout,
				'sslverify' => true,
			);

			if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );

			$duration_ms        = (int) ( ( microtime( true ) - $start ) * 1000 );
			$this->last_request = array(
				'url'         => $url,
				'method'      => $method,
				'duration_ms' => $duration_ms,
				'status_code' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ),
			);

			// Handle network errors.
			if ( is_wp_error( $response ) ) {
				if ( $attempt < $this->max_retries ) {
					$this->exponentialBackoff( $attempt );
					continue;
				}

				throw new \RuntimeException(
					'Network error: ' . $response->get_error_message()
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

			// Validate JSON decode succeeded.
			if ( null === $response_body && JSON_ERROR_NONE !== json_last_error() ) {
				throw new \RuntimeException(
					sprintf( 'Invalid JSON response from WhatsApp API (HTTP %d): %s', $response_code, json_last_error_msg() )
				);
			}

			// Retry on 5xx or rate limit.
			if ( $response_code >= 500 || $response_code === 429 ) {
				if ( $attempt < $this->max_retries ) {
					$this->exponentialBackoff( $attempt );
					continue;
				}
			}

			// Handle API errors.
			if ( $response_code >= 400 ) {
				$error_message = $response_body['error']['message'] ?? 'API request failed';
				$error_code    = $response_body['error']['code'] ?? $response_code;

				throw new \RuntimeException(
					sprintf( 'WhatsApp API error (%s): %s', $error_code, $error_message )
				);
			}

			return $response_body ?? [];
		}

		throw new \RuntimeException( 'Max retries exceeded' );
	}

	/**
	 * Perform exponential backoff.
	 *
	 * @param int $attempt Current attempt number.
	 * @return void
	 */
	private function exponentialBackoff( int $attempt ): void {
		$delay = min( pow( 2, $attempt ) * 100000, 3000000 ); // Max 3 seconds.
		usleep( $delay );
	}

	/**
	 * Check if string is a media ID vs URL.
	 *
	 * @param string $value Value to check.
	 * @return bool True if media ID.
	 */
	private function isMediaId( string $value ): bool {
		// Media IDs are numeric strings.
		return preg_match( '/^\d+$/', $value ) === 1;
	}

	/**
	 * Build multipart form body for file upload.
	 *
	 * @param string $file_path File path.
	 * @param string $mime_type MIME type.
	 * @param string $boundary  Boundary string.
	 * @return string Multipart body.
	 */
	private function buildMultipartBody( string $file_path, string $mime_type, string $boundary ): string {
		$filename = basename( $file_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file for upload.
		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			throw new \RuntimeException(
				sprintf( 'Failed to read file for upload: %s', $file_path )
			);
		}

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="messaging_product"' . "\r\n\r\n";
		$body .= 'whatsapp' . "\r\n";

		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $content . "\r\n";

		$body .= '--' . $boundary . '--';

		return $body;
	}
}
