<?php
/**
 * Core Service Provider
 *
 * Registers core WordPress and plugin services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CoreServiceProvider
 *
 * Provides core WordPress services and plugin configuration.
 */
class CoreServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register WordPress database instance.
		$container->singleton(
			\wpdb::class,
			static function () {
				global $wpdb;
				return $wpdb;
			}
		);

		// Alias for convenience.
		$container->singleton(
			'wpdb',
			static fn( ContainerInterface $c ) => $c->get( \wpdb::class )
		);

		// Register plugin settings.
		$container->singleton(
			'wch.settings',
			static function () {
				$defaults = array(
					'phone_number_id'       => '',
					'business_account_id'   => '',
					'access_token'          => '',
					'verify_token'          => '',
					'webhook_secret'        => '',
					'openai_api_key'        => '',
					'enable_ai_chat'        => true,
					'ai_model'              => 'gpt-4o-mini',
					'store_currency'        => 'USD',
					'enable_cart_recovery'  => true,
					'cart_expiry_hours'     => 72,
					'reminder_1_delay'      => 1,
					'reminder_2_delay'      => 24,
					'reminder_3_delay'      => 72,
					'enable_order_tracking' => true,
					'enable_debug_logging'  => false,
				);

				$settings = get_option( 'wch_settings', array() );

				return array_merge( $defaults, $settings );
			}
		);

		// Register settings accessor.
		$container->singleton(
			'wch.setting',
			static function ( ContainerInterface $c ) {
				$settings = $c->get( 'wch.settings' );

				return static function ( string $key, mixed $default = null ) use ( $settings ) {
					return $settings[ $key ] ?? $default;
				};
			}
		);

		// Register logger interface.
		$container->singleton(
			'wch.logger',
			static function ( ContainerInterface $c ) {
				$settings = $c->get( 'wch.settings' );

				return new class( $settings['enable_debug_logging'] ?? false ) {
					private bool $debug_enabled;
					private string $log_file;

					public function __construct( bool $debug_enabled ) {
						$this->debug_enabled = $debug_enabled;
						$this->log_file      = WP_CONTENT_DIR . '/wch-debug.log';
					}

					public function debug( string $message, array $context = array() ): void {
						if ( $this->debug_enabled ) {
							$this->log( 'DEBUG', $message, $context );
						}
					}

					public function info( string $message, array $context = array() ): void {
						$this->log( 'INFO', $message, $context );
					}

					public function warning( string $message, array $context = array() ): void {
						$this->log( 'WARNING', $message, $context );
					}

					public function error( string $message, array $context = array() ): void {
						$this->log( 'ERROR', $message, $context );
					}

					private function log( string $level, string $message, array $context ): void {
						$timestamp = gmdate( 'Y-m-d H:i:s' );
						$context_str = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';

						$log_line = "[{$timestamp}] [{$level}] {$message}{$context_str}\n";

						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( $log_line, 3, $this->log_file );

						// Also use WordPress error logging in debug mode.
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
							error_log( "WCH [{$level}]: {$message}{$context_str}" );
						}
					}
				};
			}
		);

		// Register WordPress hooks helper.
		$container->singleton(
			'wch.hooks',
			static function () {
				return new class() {
					public function action( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
						add_action( $hook, $callback, $priority, $args );
					}

					public function filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
						add_filter( $hook, $callback, $priority, $args );
					}

					public function doAction( string $hook, mixed ...$args ): void {
						do_action( $hook, ...$args );
					}

					public function applyFilters( string $hook, mixed $value, mixed ...$args ): mixed {
						return apply_filters( $hook, $value, ...$args );
					}
				};
			}
		);

		// Register transient cache helper.
		$container->singleton(
			'wch.cache',
			static function () {
				return new class() {
					private const PREFIX = 'wch_';

					public function get( string $key, mixed $default = null ): mixed {
						$value = get_transient( self::PREFIX . $key );
						return false === $value ? $default : $value;
					}

					public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
						return set_transient( self::PREFIX . $key, $value, $ttl );
					}

					public function delete( string $key ): bool {
						return delete_transient( self::PREFIX . $key );
					}

					public function remember( string $key, callable $callback, int $ttl = 3600 ): mixed {
						$cached = $this->get( $key );

						if ( null !== $cached ) {
							return $cached;
						}

						$value = $callback();
						$this->set( $key, $value, $ttl );

						return $value;
					}

					public function flush( string $prefix = '' ): void {
						global $wpdb;

						$pattern = self::PREFIX . $prefix . '%';

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->query(
							$wpdb->prepare(
								"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
								'_transient_' . $pattern,
								'_transient_timeout_' . $pattern
							)
						);
					}
				};
			}
		);

		// Register encryption service placeholder.
		// This will be replaced by the Security layer.
		$container->singleton(
			'wch.encryption',
			static function () {
				if ( class_exists( 'WCH_Encryption' ) ) {
					return \WCH_Encryption::instance();
				}

				return null;
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
		// Initialize the logger with proper settings.
		$container->get( 'wch.logger' );
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			\wpdb::class,
			'wpdb',
			'wch.settings',
			'wch.setting',
			'wch.logger',
			'wch.hooks',
			'wch.cache',
			'wch.encryption',
		);
	}
}
