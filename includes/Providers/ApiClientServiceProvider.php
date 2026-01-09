<?php
/**
 * API Client Service Provider
 *
 * Registers external API client services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Contracts\Clients\WhatsAppClientInterface;
use WhatsAppCommerceHub\Contracts\Clients\OpenAIClientInterface;
use WhatsAppCommerceHub\Clients\WhatsAppApiClient;
use WhatsAppCommerceHub\Clients\OpenAIClient;
use WhatsAppCommerceHub\Resilience\CircuitBreakerRegistry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ApiClientServiceProvider
 *
 * Provides API client bindings (WhatsApp, OpenAI, etc.).
 */
class ApiClientServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register WhatsApp API Client.
		$container->singleton(
			'wch.whatsapp.client',
			static function ( ContainerInterface $c ) {
				// Use existing singleton during transition (if it has instance() method).
				if ( class_exists( 'WCH_WhatsApp_API_Client' ) && method_exists( 'WCH_WhatsApp_API_Client', 'instance' ) ) {
					return \WCH_WhatsApp_API_Client::instance();
				}

				// Fall back to new DI-based client if available.
				if ( class_exists( 'WCH_WhatsApp_API_Client' ) && method_exists( 'WCH_WhatsApp_API_Client', 'getClient' ) ) {
					try {
						return \WCH_WhatsApp_API_Client::getClient();
					} catch ( \Throwable $e ) {
						// Client not configured yet.
						return null;
					}
				}

				return null;
			}
		);

		// Register OpenAI Client.
		$container->singleton(
			'wch.openai.client',
			static function ( ContainerInterface $c ) {
				// Use existing singleton during transition (if it has instance() method).
				if ( class_exists( 'WCH_OpenAI_Client' ) && method_exists( 'WCH_OpenAI_Client', 'instance' ) ) {
					return \WCH_OpenAI_Client::instance();
				}

				return null;
			}
		);

		// Register new WhatsApp Client with CircuitBreaker (interface binding).
		$container->singleton(
			WhatsAppClientInterface::class,
			static function ( ContainerInterface $c ) {
				$settings = $c->get( 'wch.settings' );
				$phone_number_id = $settings['phone_number_id'] ?? '';
				$access_token = $settings['access_token'] ?? '';

				if ( empty( $phone_number_id ) || empty( $access_token ) ) {
					throw new \RuntimeException( 'WhatsApp API credentials not configured' );
				}

				$registry = $c->get( CircuitBreakerRegistry::class );
				$circuit_breaker = $registry->get( 'whatsapp' );

				return new WhatsAppApiClient(
					$phone_number_id,
					$access_token,
					$circuit_breaker
				);
			}
		);

		// Alias for convenience.
		$container->singleton(
			WhatsAppApiClient::class,
			static fn( ContainerInterface $c ) => $c->get( WhatsAppClientInterface::class )
		);

		// Register new OpenAI Client with CircuitBreaker (interface binding).
		$container->singleton(
			OpenAIClientInterface::class,
			static function ( ContainerInterface $c ) {
				$settings = $c->get( 'wch.settings' );
				$api_key = $settings['openai_api_key'] ?? '';

				if ( empty( $api_key ) ) {
					throw new \RuntimeException( 'OpenAI API key not configured' );
				}

				$registry = $c->get( CircuitBreakerRegistry::class );
				$circuit_breaker = $registry->get( 'openai' );

				return new OpenAIClient(
					$api_key,
					$circuit_breaker
				);
			}
		);

		// Alias for convenience.
		$container->singleton(
			OpenAIClient::class,
			static fn( ContainerInterface $c ) => $c->get( OpenAIClientInterface::class )
		);

		// Register HTTP client wrapper.
		$container->singleton(
			'wch.http',
			static function () {
				return new class() {
					/**
					 * Make a GET request.
					 *
					 * @param string $url     The URL.
					 * @param array  $headers Request headers.
					 * @param array  $args    Additional args.
					 * @return array{success: bool, data: mixed, status_code: int, error: string|null}
					 */
					public function get( string $url, array $headers = array(), array $args = array() ): array {
						return $this->request( 'GET', $url, array(), $headers, $args );
					}

					/**
					 * Make a POST request.
					 *
					 * @param string $url     The URL.
					 * @param array  $data    Request body.
					 * @param array  $headers Request headers.
					 * @param array  $args    Additional args.
					 * @return array{success: bool, data: mixed, status_code: int, error: string|null}
					 */
					public function post( string $url, array $data = array(), array $headers = array(), array $args = array() ): array {
						return $this->request( 'POST', $url, $data, $headers, $args );
					}

					/**
					 * Make a PUT request.
					 *
					 * @param string $url     The URL.
					 * @param array  $data    Request body.
					 * @param array  $headers Request headers.
					 * @param array  $args    Additional args.
					 * @return array{success: bool, data: mixed, status_code: int, error: string|null}
					 */
					public function put( string $url, array $data = array(), array $headers = array(), array $args = array() ): array {
						return $this->request( 'PUT', $url, $data, $headers, $args );
					}

					/**
					 * Make a DELETE request.
					 *
					 * @param string $url     The URL.
					 * @param array  $headers Request headers.
					 * @param array  $args    Additional args.
					 * @return array{success: bool, data: mixed, status_code: int, error: string|null}
					 */
					public function delete( string $url, array $headers = array(), array $args = array() ): array {
						return $this->request( 'DELETE', $url, array(), $headers, $args );
					}

					/**
					 * Make an HTTP request.
					 *
					 * @param string $method  HTTP method.
					 * @param string $url     The URL.
					 * @param array  $data    Request body.
					 * @param array  $headers Request headers.
					 * @param array  $args    Additional args.
					 * @return array{success: bool, data: mixed, status_code: int, error: string|null}
					 */
					private function request(
						string $method,
						string $url,
						array $data = array(),
						array $headers = array(),
						array $args = array()
					): array {
						$default_args = array(
							'method'  => $method,
							'timeout' => 30,
							'headers' => array_merge(
								array( 'Content-Type' => 'application/json' ),
								$headers
							),
						);

						if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
							$default_args['body'] = wp_json_encode( $data );
						}

						$request_args = array_merge( $default_args, $args );

						$response = wp_remote_request( $url, $request_args );

						if ( is_wp_error( $response ) ) {
							return array(
								'success'     => false,
								'data'        => null,
								'status_code' => 0,
								'error'       => $response->get_error_message(),
							);
						}

						$status_code = wp_remote_retrieve_response_code( $response );
						$body        = wp_remote_retrieve_body( $response );
						$decoded     = json_decode( $body, true );

						return array(
							'success'     => $status_code >= 200 && $status_code < 300,
							'data'        => $decoded ?? $body,
							'status_code' => $status_code,
							'error'       => $status_code >= 400 ? ( $decoded['error']['message'] ?? 'Request failed' ) : null,
						);
					}
				};
			}
		);

		// Register WhatsApp message sender.
		$container->singleton(
			'wch.whatsapp.sender',
			static function ( ContainerInterface $c ) {
				$http     = $c->get( 'wch.http' );
				$settings = $c->get( 'wch.settings' );
				$logger   = $c->get( 'wch.logger' );

				return new class( $http, $settings, $logger ) {
					private object $http;
					private array $settings;
					private object $logger;
					private string $api_url = 'https://graph.facebook.com/v18.0';

					public function __construct( object $http, array $settings, object $logger ) {
						$this->http     = $http;
						$this->settings = $settings;
						$this->logger   = $logger;
					}

					/**
					 * Send a text message.
					 *
					 * @param string $to   Recipient phone number.
					 * @param string $text Message text.
					 * @return array{success: bool, message_id: string|null, error: string|null}
					 */
					public function sendText( string $to, string $text ): array {
						return $this->send(
							$to,
							array(
								'type' => 'text',
								'text' => array( 'body' => $text ),
							)
						);
					}

					/**
					 * Send an interactive message.
					 *
					 * @param string $to          Recipient phone number.
					 * @param array  $interactive Interactive message data.
					 * @return array{success: bool, message_id: string|null, error: string|null}
					 */
					public function sendInteractive( string $to, array $interactive ): array {
						return $this->send(
							$to,
							array(
								'type'        => 'interactive',
								'interactive' => $interactive,
							)
						);
					}

					/**
					 * Send a template message.
					 *
					 * @param string $to       Recipient phone number.
					 * @param string $template Template name.
					 * @param array  $params   Template parameters.
					 * @param string $language Language code.
					 * @return array{success: bool, message_id: string|null, error: string|null}
					 */
					public function sendTemplate(
						string $to,
						string $template,
						array $params = array(),
						string $language = 'en'
					): array {
						$template_data = array(
							'name'     => $template,
							'language' => array( 'code' => $language ),
						);

						if ( ! empty( $params ) ) {
							$template_data['components'] = $params;
						}

						return $this->send(
							$to,
							array(
								'type'     => 'template',
								'template' => $template_data,
							)
						);
					}

					/**
					 * Send a message.
					 *
					 * @param string $to      Recipient phone number.
					 * @param array  $message Message data.
					 * @return array{success: bool, message_id: string|null, error: string|null}
					 */
					private function send( string $to, array $message ): array {
						$phone_number_id = $this->settings['phone_number_id'] ?? '';
						$access_token    = $this->settings['access_token'] ?? '';

						if ( empty( $phone_number_id ) || empty( $access_token ) ) {
							return array(
								'success'    => false,
								'message_id' => null,
								'error'      => 'WhatsApp API not configured',
							);
						}

						$url = "{$this->api_url}/{$phone_number_id}/messages";

						$payload = array_merge(
							array(
								'messaging_product' => 'whatsapp',
								'recipient_type'    => 'individual',
								'to'                => $to,
							),
							$message
						);

						$response = $this->http->post(
							$url,
							$payload,
							array( 'Authorization' => "Bearer {$access_token}" )
						);

						if ( ! $response['success'] ) {
							$this->logger->error( 'WhatsApp send failed', array(
								'to'    => $to,
								'error' => $response['error'],
							) );

							return array(
								'success'    => false,
								'message_id' => null,
								'error'      => $response['error'],
							);
						}

						$message_id = $response['data']['messages'][0]['id'] ?? null;

						$this->logger->debug( 'WhatsApp message sent', array(
							'to'         => $to,
							'message_id' => $message_id,
						) );

						return array(
							'success'    => true,
							'message_id' => $message_id,
							'error'      => null,
						);
					}
				};
			}
		);
	}

	/**
	 * Boot services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function boot( ContainerInterface $container ): void {
		// API clients don't need initialization.
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			'wch.whatsapp.client',
			'wch.openai.client',
			WhatsAppClientInterface::class,
			WhatsAppApiClient::class,
			OpenAIClientInterface::class,
			OpenAIClient::class,
			'wch.http',
			'wch.whatsapp.sender',
		);
	}
}
