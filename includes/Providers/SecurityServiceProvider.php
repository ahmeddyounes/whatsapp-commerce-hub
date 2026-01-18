<?php
/**
 * Security Service Provider
 *
 * Registers security-related services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Service provider closures don't need docblocks.

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Security\SecureVault;
use WhatsAppCommerceHub\Security\PIIEncryptor;
use WhatsAppCommerceHub\Security\RateLimiter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SecurityServiceProvider
 *
 * Provides security service bindings.
 */
class SecurityServiceProvider implements ServiceProviderInterface {

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register SecureVault.
		$container->singleton(
			SecureVault::class,
			static fn() => new SecureVault()
		);

		// Alias for convenience.
		$container->singleton(
			'wch.security.vault',
			static fn( ContainerInterface $c ) => $c->get( SecureVault::class )
		);

		// Register PIIEncryptor.
		$container->singleton(
			PIIEncryptor::class,
			static fn( ContainerInterface $c ) => new PIIEncryptor(
				$c->get( SecureVault::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			'wch.security.pii',
			static fn( ContainerInterface $c ) => $c->get( PIIEncryptor::class )
		);

		// Register RateLimiter.
		$container->singleton(
			RateLimiter::class,
			static fn( ContainerInterface $c ) => new RateLimiter(
				$c->get( \wpdb::class )
			)
		);

		// Alias for convenience.
		$container->singleton(
			'wch.security.rate_limiter',
			static fn( ContainerInterface $c ) => $c->get( RateLimiter::class )
		);

		// Register security logger.
		$container->singleton(
			'wch.security.logger',
			static function ( ContainerInterface $c ) {
				$wpdb   = $c->get( \wpdb::class );
				$logger = $c->get( 'wch.logger' );

				return new class( $wpdb, $logger ) {
					private \wpdb $wpdb;
					private object $logger;
					private string $table;
					private ?bool $table_exists = null;

					public function __construct( \wpdb $wpdb, object $logger ) {
						$this->wpdb   = $wpdb;
						$this->logger = $logger;
						$this->table  = $wpdb->prefix . 'wch_security_log';
					}

					private function tableExists(): bool {
						if ( null !== $this->table_exists ) {
							return $this->table_exists;
						}

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$result             = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
						$this->table_exists = ! empty( $result );
						return $this->table_exists;
					}

					/**
					 * Log a security event.
					 *
					 * @param string $event   The event type.
					 * @param array  $context Event context.
					 * @param string $level   Log level (info, warning, error).
					 * @return void
					 */
					public function log( string $event, array $context = [], string $level = 'info' ): void {
						// Always log to file.
						$this->logger->$level( "Security: {$event}", $context );

						// Log to database for important events.
						if ( in_array( $level, [ 'warning', 'error' ], true ) && $this->tableExists() ) {
							$this->wpdb->insert(
								$this->table,
								[
									'event'      => $event,
									'level'      => $level,
									'context'    => wp_json_encode( $context ),
									'ip_address' => $this->getClientIP(),
									'user_id'    => get_current_user_id() ?: null,
									'created_at' => current_time( 'mysql' ),
								],
								[ '%s', '%s', '%s', '%s', '%d', '%s' ]
							);
						}
					}

					/**
					 * Get security events.
					 *
					 * @param array $filters Filter criteria.
					 * @param int   $limit   Maximum events.
					 * @param int   $offset  Offset.
					 * @return array Security events.
					 */
					public function getEvents( array $filters = [], int $limit = 50, int $offset = 0 ): array {
						if ( ! $this->tableExists() ) {
							return [];
						}

						$where  = [ '1=1' ];
						$params = [];

						if ( isset( $filters['event'] ) ) {
							$where[]  = 'event = %s';
							$params[] = $filters['event'];
						}

						if ( isset( $filters['level'] ) ) {
							$where[]  = 'level = %s';
							$params[] = $filters['level'];
						}

						if ( isset( $filters['since'] ) ) {
							$where[]  = 'created_at >= %s';
							$params[] = $filters['since'];
						}

						$where_clause = implode( ' AND ', $where );
						$params[]     = $limit;
						$params[]     = $offset;

						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						return $this->wpdb->get_results(
							$this->wpdb->prepare(
								"SELECT * FROM {$this->table}
								WHERE {$where_clause}
								ORDER BY created_at DESC
								LIMIT %d OFFSET %d",
								...$params
							),
							ARRAY_A
						) ?: [];
					}

					/**
					 * Get client IP address.
					 *
					 * @return string The client IP.
					 */
					private function getClientIP(): string {
						$headers = [
							'HTTP_CF_CONNECTING_IP',
							'HTTP_X_FORWARDED_FOR',
							'HTTP_X_REAL_IP',
							'REMOTE_ADDR',
						];

						foreach ( $headers as $header ) {
							if ( ! empty( $_SERVER[ $header ] ) ) {
								$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
								// Handle comma-separated IPs.
								if ( str_contains( $ip, ',' ) ) {
									$ip = trim( explode( ',', $ip )[0] );
								}
								if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
									return $ip;
								}
							}
						}

						return '0.0.0.0';
					}

					/**
					 * Cleanup old log entries.
					 *
					 * @param int $days_old Entries older than this are deleted.
					 * @return int Number deleted.
					 */
					public function cleanup( int $days_old = 90 ): int {
						if ( ! $this->tableExists() ) {
							return 0;
						}

						$threshold = ( new \DateTimeImmutable() )
							->modify( "-{$days_old} days" )
							->format( 'Y-m-d H:i:s' );

						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$this->wpdb->query(
							$this->wpdb->prepare(
								"DELETE FROM {$this->table} WHERE created_at < %s",
								$threshold
							)
						);

						return $this->wpdb->rows_affected;
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
		// Register security log action.
		add_action(
			'wch_security_log',
			function ( string $event, array $context = [] ) use ( $container ) {
				$logger = $container->get( 'wch.security.logger' );
				$logger->log( $event, $context, 'info' );
			},
			10,
			2
		);

		// Schedule rate limit cleanup.
		if ( ! wp_next_scheduled( 'wch_rate_limit_cleanup' ) ) {
			wp_schedule_event( time(), 'hourly', 'wch_rate_limit_cleanup' );
		}

		add_action(
			'wch_rate_limit_cleanup',
			function () use ( $container ) {
				$rate_limiter = $container->get( RateLimiter::class );
				$rate_limiter->cleanup();
			}
		);

		// Schedule security log cleanup.
		if ( ! wp_next_scheduled( 'wch_security_log_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wch_security_log_cleanup' );
		}

		add_action(
			'wch_security_log_cleanup',
			function () use ( $container ) {
				$logger = $container->get( 'wch.security.logger' );
				$logger->cleanup( 90 );
			}
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	/**
	 * @return array<class-string<\WhatsAppCommerceHub\Container\ServiceProviderInterface>>
	 */
	public function dependsOn(): array {
		return [
			\WhatsAppCommerceHub\Providers\CoreServiceProvider::class,
		];
	}

	public function provides(): array {
		return [
			SecureVault::class,
			'wch.security.vault',
			PIIEncryptor::class,
			'wch.security.pii',
			RateLimiter::class,
			'wch.security.rate_limiter',
			'wch.security.logger',
		];
	}
}
