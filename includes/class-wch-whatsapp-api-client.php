<?php
/**
 * WhatsApp Business Cloud API Client for WhatsApp Commerce Hub.
 *
 * Handles all interactions with the WhatsApp Business Cloud API.
 *
 * @package WhatsApp_Commerce_Hub
 *
 * @deprecated 2.0.0 Use WhatsAppApiClient via DI container instead:
 *             `WhatsAppCommerceHub\Container\Container::getInstance()->get(WhatsAppClientInterface::class)`
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WhatsAppCommerceHub\Container\Container;
use WhatsAppCommerceHub\Contracts\Clients\WhatsAppClientInterface;
use WhatsAppCommerceHub\Resilience\CircuitBreaker;
use WhatsAppCommerceHub\Resilience\CircuitBreakerRegistry;

/**
 * Class WCH_WhatsApp_API_Client
 *
 * HTTP client for WhatsApp Business Cloud API.
 *
 * @deprecated 2.0.0 This class is a backward compatibility facade.
 *             Use WhatsAppClientInterface via DI container for new code.
 */
class WCH_WhatsApp_API_Client {

	/**
	 * Get the WhatsAppApiClient from the DI container.
	 *
	 * This is the recommended way to access WhatsApp functionality in new code.
	 * The DI-based client includes CircuitBreaker protection.
	 *
	 * @since 2.0.0
	 * @return WhatsAppClientInterface
	 */
	public static function getClient(): WhatsAppClientInterface {
		return Container::getInstance()->get( WhatsAppClientInterface::class );
	}
	/**
	 * Phone number ID.
	 *
	 * @var string
	 */
	private $phone_number_id;

	/**
	 * Access token.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * API version.
	 *
	 * @var string
	 */
	private $api_version;

	/**
	 * Base API URL.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Circuit breaker instance.
	 *
	 * @var CircuitBreaker|null
	 */
	private $circuit_breaker;

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param array $config Configuration array with keys: phone_number_id, access_token, api_version.
	 * @throws WCH_Exception If required config is missing.
	 */
	public function __construct( array $config ) {
		if ( empty( $config['phone_number_id'] ) ) {
			throw new WCH_Exception( 'Phone number ID is required', 'missing_phone_number_id', 400 );
		}

		if ( empty( $config['access_token'] ) ) {
			throw new WCH_Exception( 'Access token is required', 'missing_access_token', 400 );
		}

		$this->phone_number_id = $config['phone_number_id'];
		$this->access_token    = $config['access_token'];
		$this->api_version     = $config['api_version'] ?? 'v18.0';
		$this->base_url        = 'https://graph.facebook.com/' . $this->api_version . '/';

		// Initialize circuit breaker from DI container if available.
		$this->circuit_breaker = $this->initCircuitBreaker();
	}

	/**
	 * Initialize circuit breaker from DI container.
	 *
	 * @return CircuitBreaker|null
	 */
	private function initCircuitBreaker(): ?CircuitBreaker {
		try {
			$container = Container::getInstance();
			if ( $container->has( CircuitBreakerRegistry::class ) ) {
				$registry = $container->get( CircuitBreakerRegistry::class );
				return $registry->get( 'whatsapp' );
			}
		} catch ( \Throwable $e ) {
			// Circuit breaker not available, continue without protection.
			WCH_Logger::debug(
				'Circuit breaker not available for legacy client',
				array( 'error' => $e->getMessage() )
			);
		}

		return null;
	}

	/**
	 * Check if the WhatsApp API is available (circuit is not open).
	 *
	 * @since 2.0.0
	 * @return bool True if available.
	 */
	public function is_available(): bool {
		if ( null === $this->circuit_breaker ) {
			return true; // No circuit breaker, assume available.
		}

		return $this->circuit_breaker->isAvailable();
	}

	/**
	 * Get circuit breaker health status.
	 *
	 * @since 2.0.0
	 * @return array{healthy: bool, state: string, metrics: array}
	 */
	public function get_health_status(): array {
		if ( null === $this->circuit_breaker ) {
			return array(
				'healthy' => true,
				'state'   => 'no_circuit_breaker',
				'metrics' => array(),
			);
		}

		$metrics = $this->circuit_breaker->getMetrics();

		return array(
			'healthy' => $this->circuit_breaker->isAvailable(),
			'state'   => $metrics['state'] ?? 'unknown',
			'metrics' => $metrics,
		);
	}

	/**
	 * Validate phone number is in E.164 format.
	 *
	 * @param string $phone Phone number to validate.
	 * @return bool True if valid.
	 * @throws WCH_Exception If phone number is invalid.
	 */
	private function validate_phone_number( $phone ) {
		// E.164 format: + followed by 1-15 digits.
		if ( ! preg_match( '/^\+[1-9]\d{1,14}$/', $phone ) ) {
			throw new WCH_Exception(
				'Phone number must be in E.164 format (e.g., +1234567890)',
				'invalid_phone_number',
				400,
				array( 'phone' => $phone )
			);
		}

		return true;
	}

	/**
	 * Make HTTP request to WhatsApp API with retry logic.
	 *
	 * @param string $method   HTTP method (GET, POST, etc.).
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body data.
	 * @return array Response data.
	 * @throws WCH_API_Exception If API request fails.
	 */
	private function request( $method, $endpoint, $body = array() ) {
		// Use circuit breaker if available.
		if ( null !== $this->circuit_breaker ) {
			return $this->requestWithCircuitBreaker( $method, $endpoint, $body );
		}

		// Fallback to direct request without circuit breaker.
		return $this->executeRequest( $method, $endpoint, $body );
	}

	/**
	 * Make HTTP request with circuit breaker protection.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body data.
	 * @return array Response data.
	 * @throws WCH_API_Exception If API request fails.
	 */
	private function requestWithCircuitBreaker( $method, $endpoint, $body = array() ) {
		try {
			return $this->circuit_breaker->call(
				function () use ( $method, $endpoint, $body ) {
					return $this->executeRequest( $method, $endpoint, $body );
				},
				function () use ( $method, $endpoint ) {
					// Fallback when circuit is open.
					WCH_Logger::warning(
						'WhatsApp API circuit breaker open',
						array(
							'method'   => $method,
							'endpoint' => $endpoint,
						)
					);

					throw new WCH_API_Exception(
						'WhatsApp API is temporarily unavailable. Please try again later.',
						null,
						'circuit_open',
						null,
						503,
						array(
							'endpoint' => $endpoint,
							'circuit'  => 'open',
						)
					);
				}
			);
		} catch ( WCH_API_Exception $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			// Convert generic exceptions to API exceptions.
			throw new WCH_API_Exception(
				$e->getMessage(),
				null,
				'circuit_breaker_error',
				null,
				500,
				array( 'endpoint' => $endpoint )
			);
		}
	}

	/**
	 * Execute the actual HTTP request with retry logic.
	 *
	 * @param string $method   HTTP method (GET, POST, etc.).
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body data.
	 * @return array Response data.
	 * @throws WCH_API_Exception If API request fails.
	 */
	private function executeRequest( $method, $endpoint, $body = array() ) {
		$url     = $this->base_url . $endpoint;
		$attempt = 0;

		while ( $attempt < self::MAX_RETRIES ) {
			++$attempt;

			$args = array(
				'method'    => $method,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
				),
				'timeout'   => self::TIMEOUT,
				'sslverify' => true,
			);

			if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			// Log request.
			WCH_Logger::debug(
				'WhatsApp API Request',
				array(
					'method'   => $method,
					'endpoint' => $endpoint,
					'attempt'  => $attempt,
					'body'     => $body,
				)
			);

			$response = wp_remote_request( $url, $args );

			// Log response.
			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			WCH_Logger::debug(
				'WhatsApp API Response',
				array(
					'endpoint'      => $endpoint,
					'attempt'       => $attempt,
					'response_code' => $response_code,
					'response_body' => $response_body,
				)
			);

			// Handle errors.
			if ( is_wp_error( $response ) ) {
				// Network error - retry.
				if ( $attempt < self::MAX_RETRIES ) {
					$this->exponential_backoff( $attempt );
					continue;
				}

				throw new WCH_API_Exception(
					'Network error: ' . $response->get_error_message(),
					null,
					null,
					null,
					0,
					array(
						'endpoint'      => $endpoint,
						'error_code'    => $response->get_error_code(),
						'error_message' => $response->get_error_message(),
					)
				);
			}

			$data = json_decode( $response_body, true );

			// Validate JSON decode succeeded.
			if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
				throw new WCH_API_Exception(
					'Invalid JSON response from API',
					null,
					'json_decode_error',
					null,
					$response_code,
					array(
						'endpoint'   => $endpoint,
						'json_error' => json_last_error_msg(),
					)
				);
			}

			// Check for 5xx errors or rate limits - retry.
			if ( $response_code >= 500 || $response_code === 429 ) {
				if ( $attempt < self::MAX_RETRIES ) {
					WCH_Logger::warning(
						'WhatsApp API retry',
						array(
							'endpoint'      => $endpoint,
							'response_code' => $response_code,
							'attempt'       => $attempt,
						)
					);
					$this->exponential_backoff( $attempt );
					continue;
				}
			}

			// Check for API errors.
			if ( $response_code >= 400 ) {
				$error_message = 'API request failed';
				$error_code    = null;
				$error_type    = null;
				$error_subcode = null;

				if ( isset( $data['error'] ) ) {
					$error_message = $data['error']['message'] ?? $error_message;
					$error_code    = $data['error']['code'] ?? null;
					$error_type    = $data['error']['type'] ?? null;
					$error_subcode = $data['error']['error_subcode'] ?? null;
				}

				throw new WCH_API_Exception(
					$error_message,
					$error_code,
					$error_type,
					$error_subcode,
					$response_code,
					array(
						'endpoint'      => $endpoint,
						'response_data' => $data,
					)
				);
			}

			// Success.
			return $data;
		}

		// Should never reach here, but just in case.
		throw new WCH_API_Exception(
			'Max retries exceeded',
			null,
			null,
			null,
			500,
			array( 'endpoint' => $endpoint )
		);
	}

	/**
	 * Apply exponential backoff delay.
	 *
	 * @param int $attempt Current attempt number.
	 */
	private function exponential_backoff( $attempt ) {
		$delay = min( pow( 2, $attempt - 1 ), 8 );
		sleep( $delay );
	}

	/**
	 * Send text message.
	 *
	 * @param string $to          Recipient phone number in E.164 format.
	 * @param string $text        Message text.
	 * @param bool   $preview_url Whether to enable URL preview. Default false.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_text_message( $to, $text, $preview_url = false ) {
		$this->validate_phone_number( $to );

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array(
				'preview_url' => (bool) $preview_url,
				'body'        => $text,
			),
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send interactive list message.
	 *
	 * @param string $to          Recipient phone number in E.164 format.
	 * @param string $header      Header text.
	 * @param string $body        Body text.
	 * @param string $footer      Footer text.
	 * @param string $button_text Button text.
	 * @param array  $sections    Array of sections with rows.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_interactive_list( $to, $header, $body, $footer, $button_text, $sections ) {
		$this->validate_phone_number( $to );

		$interactive_body = array(
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

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $interactive_body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send interactive buttons message.
	 *
	 * @param string $to      Recipient phone number in E.164 format.
	 * @param string $header  Header text.
	 * @param string $body    Body text.
	 * @param string $footer  Footer text.
	 * @param array  $buttons Array of buttons.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_interactive_buttons( $to, $header, $body, $footer, $buttons ) {
		$this->validate_phone_number( $to );

		$interactive_body = array(
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

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $interactive_body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send template message.
	 *
	 * @param string $to            Recipient phone number in E.164 format.
	 * @param string $template_name Template name.
	 * @param string $language_code Language code (e.g., 'en_US').
	 * @param array  $components    Template components.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_template( $to, $template_name, $language_code, $components = array() ) {
		$this->validate_phone_number( $to );

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'template',
			'template'          => array(
				'name'       => $template_name,
				'language'   => array(
					'code' => $language_code,
				),
				'components' => $components,
			),
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send image message.
	 *
	 * @param string      $to             Recipient phone number in E.164 format.
	 * @param string      $image_url_or_id Image URL or media ID.
	 * @param string|null $caption        Optional caption.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_image( $to, $image_url_or_id, $caption = null ) {
		$this->validate_phone_number( $to );

		// Determine if URL or ID.
		$is_url = filter_var( $image_url_or_id, FILTER_VALIDATE_URL );

		$image_data = array();
		if ( $is_url ) {
			$image_data['link'] = $image_url_or_id;
		} else {
			$image_data['id'] = $image_url_or_id;
		}

		if ( $caption ) {
			$image_data['caption'] = $caption;
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'image',
			'image'             => $image_data,
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send document message.
	 *
	 * @param string      $to                Recipient phone number in E.164 format.
	 * @param string      $document_url_or_id Document URL or media ID.
	 * @param string|null $filename          Optional filename.
	 * @param string|null $caption           Optional caption.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_document( $to, $document_url_or_id, $filename = null, $caption = null ) {
		$this->validate_phone_number( $to );

		// Determine if URL or ID.
		$is_url = filter_var( $document_url_or_id, FILTER_VALIDATE_URL );

		$document_data = array();
		if ( $is_url ) {
			$document_data['link'] = $document_url_or_id;
		} else {
			$document_data['id'] = $document_url_or_id;
		}

		if ( $filename ) {
			$document_data['filename'] = $filename;
		}

		if ( $caption ) {
			$document_data['caption'] = $caption;
		}

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'document',
			'document'          => $document_data,
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send product message.
	 *
	 * @param string $to                  Recipient phone number in E.164 format.
	 * @param string $catalog_id          Catalog ID.
	 * @param string $product_retailer_id Product retailer ID.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_product_message( $to, $catalog_id, $product_retailer_id ) {
		$this->validate_phone_number( $to );

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

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Send product list message.
	 *
	 * @param string $to         Recipient phone number in E.164 format.
	 * @param string $catalog_id Catalog ID.
	 * @param string $header     Header text.
	 * @param string $body       Body text.
	 * @param array  $sections   Array of product sections.
	 * @return array Response with 'message_id' and 'status'.
	 * @throws WCH_API_Exception If send fails.
	 */
	public function send_product_list( $to, $catalog_id, $header, $body, $sections ) {
		$this->validate_phone_number( $to );

		$interactive_body = array(
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

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $interactive_body );

		return array(
			'message_id' => $response['messages'][0]['id'] ?? null,
			'status'     => 'sent',
		);
	}

	/**
	 * Mark message as read.
	 *
	 * @param string $message_id Message ID to mark as read.
	 * @return array Response with 'status'.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function mark_as_read( $message_id ) {
		$body = array(
			'messaging_product' => 'whatsapp',
			'status'            => 'read',
			'message_id'        => $message_id,
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/messages', $body );

		return array(
			'status' => $response['success'] ?? false,
		);
	}

	/**
	 * Get media URL from media ID.
	 *
	 * @param string $media_id Media ID.
	 * @return string Media URL.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function get_media_url( $media_id ) {
		$response = $this->request( 'GET', $media_id );

		if ( empty( $response['url'] ) ) {
			throw new WCH_API_Exception(
				'Media URL not found in response',
				null,
				null,
				null,
				500,
				array( 'media_id' => $media_id )
			);
		}

		return $response['url'];
	}

	/**
	 * Upload media file.
	 *
	 * @param string $file_path Path to file to upload.
	 * @param string $mime_type MIME type of the file.
	 * @return string Media ID.
	 * @throws WCH_API_Exception If upload fails.
	 */
	public function upload_media( $file_path, $mime_type ) {
		if ( ! file_exists( $file_path ) ) {
			throw new WCH_Exception(
				'File not found',
				'file_not_found',
				404,
				array( 'file_path' => $file_path )
			);
		}

		$url = $this->base_url . $this->phone_number_id . '/media';

		$boundary = wp_generate_password( 24, false );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file for upload.
		$file_data = file_get_contents( $file_path );

		if ( false === $file_data ) {
			throw new WCH_Exception(
				'Failed to read file',
				'file_read_error',
				500,
				array( 'file_path' => $file_path )
			);
		}

		$filename = basename( $file_path );

		$body  = "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"messaging_product\"\r\n\r\n";
		$body .= "whatsapp\r\n";
		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body .= "Content-Type: {$mime_type}\r\n\r\n";
		$body .= $file_data . "\r\n";
		$body .= "--{$boundary}--\r\n";

		$args = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $body,
			'timeout' => self::TIMEOUT,
		);

		WCH_Logger::debug(
			'WhatsApp API Media Upload',
			array(
				'file_path' => $file_path,
				'mime_type' => $mime_type,
			)
		);

		$response = wp_remote_post( $url, $args );

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		WCH_Logger::debug(
			'WhatsApp API Media Upload Response',
			array(
				'response_code' => $response_code,
				'response_body' => $response_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new WCH_API_Exception(
				'Network error: ' . $response->get_error_message(),
				null,
				null,
				null,
				0,
				array(
					'file_path'     => $file_path,
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
		}

		$data = json_decode( $response_body, true );

		// Validate JSON decode succeeded.
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			throw new WCH_API_Exception(
				'Invalid JSON response from media upload API',
				null,
				'json_decode_error',
				null,
				$response_code,
				array(
					'file_path'  => $file_path,
					'json_error' => json_last_error_msg(),
				)
			);
		}

		if ( $response_code >= 400 ) {
			$error_message = 'Media upload failed';
			$error_code    = null;
			$error_type    = null;

			if ( isset( $data['error'] ) ) {
				$error_message = $data['error']['message'] ?? $error_message;
				$error_code    = $data['error']['code'] ?? null;
				$error_type    = $data['error']['type'] ?? null;
			}

			throw new WCH_API_Exception(
				$error_message,
				$error_code,
				$error_type,
				null,
				$response_code,
				array(
					'file_path'     => $file_path,
					'response_data' => $data,
				)
			);
		}

		if ( empty( $data['id'] ) ) {
			throw new WCH_API_Exception(
				'Media ID not found in response',
				null,
				null,
				null,
				500,
				array( 'file_path' => $file_path )
			);
		}

		return $data['id'];
	}

	/**
	 * Get business profile.
	 *
	 * @return array Business profile data.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function get_business_profile() {
		$endpoint = $this->phone_number_id . '/whatsapp_business_profile?fields=about,address,description,email,profile_picture_url,websites,vertical';

		$response = $this->request( 'GET', $endpoint );

		return $response['data'][0] ?? array();
	}

	/**
	 * Update business profile.
	 *
	 * @param array $data Profile data to update.
	 * @return array Updated profile data.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function update_business_profile( $data ) {
		$body = array(
			'messaging_product'         => 'whatsapp',
			'whatsapp_business_profile' => $data,
		);

		$response = $this->request( 'POST', $this->phone_number_id . '/whatsapp_business_profile', $body );

		return $response;
	}

	/**
	 * Create or update a product in the WhatsApp catalog.
	 *
	 * @param string $catalog_id   Catalog ID.
	 * @param array  $product_data Product data in catalog format.
	 * @return array Response data with product ID.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function create_catalog_product( $catalog_id, $product_data ) {
		$endpoint = $catalog_id . '/products';

		$response = $this->request( 'POST', $endpoint, $product_data );

		return $response;
	}

	/**
	 * Update a product in the WhatsApp catalog.
	 *
	 * @param string $catalog_id      Catalog ID.
	 * @param string $product_id      Product retailer ID.
	 * @param array  $product_data    Product data to update.
	 * @return array Response data.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function update_catalog_product( $catalog_id, $product_id, $product_data ) {
		$endpoint = $catalog_id . '/products/' . $product_id;

		$response = $this->request( 'POST', $endpoint, $product_data );

		return $response;
	}

	/**
	 * Delete a product from the WhatsApp catalog.
	 *
	 * @param string $catalog_id Catalog ID.
	 * @param string $product_id Product retailer ID or catalog item ID.
	 * @return array Response data.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function delete_catalog_product( $catalog_id, $product_id ) {
		$endpoint = $catalog_id . '/products/' . $product_id;

		$response = $this->request( 'DELETE', $endpoint );

		return $response;
	}

	/**
	 * Get a product from the WhatsApp catalog.
	 *
	 * @param string $catalog_id Catalog ID.
	 * @param string $product_id Product retailer ID.
	 * @return array Product data.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function get_catalog_product( $catalog_id, $product_id ) {
		$endpoint = $catalog_id . '/products/' . $product_id;

		$response = $this->request( 'GET', $endpoint );

		return $response;
	}

	/**
	 * List products in the WhatsApp catalog.
	 *
	 * @param string $catalog_id Catalog ID.
	 * @param array  $params     Optional query parameters (limit, after, before).
	 * @return array Products list with paging info.
	 * @throws WCH_API_Exception If request fails.
	 */
	public function list_catalog_products( $catalog_id, $params = array() ) {
		$endpoint = $catalog_id . '/products';

		if ( ! empty( $params ) ) {
			$endpoint .= '?' . http_build_query( $params );
		}

		$response = $this->request( 'GET', $endpoint );

		return $response;
	}
}
