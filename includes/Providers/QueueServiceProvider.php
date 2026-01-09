<?php
/**
 * Queue Service Provider
 *
 * Registers queue-related services.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Providers;

use WhatsAppCommerceHub\Container\ContainerInterface;
use WhatsAppCommerceHub\Container\ServiceProviderInterface;
use WhatsAppCommerceHub\Queue\DeadLetterQueue;
use WhatsAppCommerceHub\Queue\JobMonitor;
use WhatsAppCommerceHub\Queue\PriorityQueue;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class QueueServiceProvider
 *
 * Provides queue and async processing services.
 */
class QueueServiceProvider implements ServiceProviderInterface {

	/**
	 * Queue priority groups.
	 */
	public const PRIORITY_CRITICAL    = 'wch-critical';
	public const PRIORITY_URGENT      = 'wch-urgent';
	public const PRIORITY_NORMAL      = 'wch-normal';
	public const PRIORITY_BULK        = 'wch-bulk';
	public const PRIORITY_MAINTENANCE = 'wch-maintenance';

	/**
	 * Register services.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @return void
	 */
	public function register( ContainerInterface $container ): void {
		// Register dead letter queue first (dependency of PriorityQueue).
		$container->singleton(
			DeadLetterQueue::class,
			static function ( ContainerInterface $c ): DeadLetterQueue {
				$wpdb = $c->get( \wpdb::class );
				return new DeadLetterQueue( $wpdb );
			}
		);

		// Register priority queue.
		$container->singleton(
			PriorityQueue::class,
			static function ( ContainerInterface $c ): PriorityQueue {
				$dlq = $c->get( DeadLetterQueue::class );
				return new PriorityQueue( $dlq );
			}
		);

		// Register job monitor.
		$container->singleton(
			JobMonitor::class,
			static function ( ContainerInterface $c ): JobMonitor {
				$priority_queue = $c->get( PriorityQueue::class );
				$dlq = $c->get( DeadLetterQueue::class );
				return new JobMonitor( $priority_queue, $dlq );
			}
		);

		// Convenience aliases.
		$container->singleton( 'wch.queue', fn( $c ) => $c->get( PriorityQueue::class ) );
		$container->singleton( 'wch.queue.dead_letter', fn( $c ) => $c->get( DeadLetterQueue::class ) );
		$container->singleton( 'wch.queue.monitor', fn( $c ) => $c->get( JobMonitor::class ) );

		// Register the legacy queue for backward compatibility.
		$container->singleton(
			'wch.queue.legacy',
			static function () {
				if ( class_exists( 'WCH_Queue' ) ) {
					return \WCH_Queue::instance();
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
		// Register queue action hooks.
		add_action( 'action_scheduler_failed_action', function ( int $action_id ) use ( $container ) {
			$this->handleFailedAction( $container, $action_id );
		} );

		// Schedule periodic cleanup of dead letter queue.
		add_action( 'wch_cleanup_dead_letter_queue', function () use ( $container ) {
			$dlq = $container->get( DeadLetterQueue::class );
			$deleted = $dlq->cleanup( 30 );
			if ( $deleted > 0 ) {
				do_action( 'wch_log_info', "Cleaned up {$deleted} old dead letter entries" );
			}
		} );

		// Schedule the cleanup if not already scheduled.
		if ( function_exists( 'as_next_scheduled_action' ) && ! as_next_scheduled_action( 'wch_cleanup_dead_letter_queue' ) ) {
			if ( function_exists( 'as_schedule_recurring_action' ) ) {
				as_schedule_recurring_action(
					time(),
					DAY_IN_SECONDS,
					'wch_cleanup_dead_letter_queue',
					array(),
					'wch-maintenance'
				);
			}
		}
	}

	/**
	 * Handle a failed action.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param int                $action_id The failed action ID.
	 * @return void
	 */
	private function handleFailedAction( ContainerInterface $container, int $action_id ): void {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return;
		}

		$store  = \ActionScheduler::store();
		$action = $store->fetch_action( $action_id );

		if ( ! $action || ! str_starts_with( $action->get_group(), 'wch-' ) ) {
			return;
		}

		$dead_letter = $container->get( DeadLetterQueue::class );

		$dead_letter->push(
			$action->get_hook(),
			$action->get_args(),
			DeadLetterQueue::REASON_MAX_RETRIES,
			'Action failed after maximum retries'
		);
	}

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array<string>
	 */
	public function provides(): array {
		return array(
			DeadLetterQueue::class,
			PriorityQueue::class,
			JobMonitor::class,
			'wch.queue',
			'wch.queue.dead_letter',
			'wch.queue.monitor',
			'wch.queue.legacy',
		);
	}
}
