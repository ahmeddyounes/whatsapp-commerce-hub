<?php
/**
 * Job Monitor
 *
 * Provides monitoring and metrics for the queue system.
 *
 * @package WhatsApp_Commerce_Hub
 * @since 2.0.0
 */

namespace WhatsAppCommerceHub\Queue;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JobMonitor
 *
 * Monitors queue health, collects metrics, and provides alerts.
 */
class JobMonitor {

	/**
	 * Priority queue instance.
	 *
	/**
	 * Alert thresholds.
	 *
	 * @var array<string, int>
	 */
	private array $thresholds = array(
		'pending_critical' => 10,     // Alert if > 10 critical jobs pending.
		'pending_total'    => 1000,   // Alert if > 1000 total jobs pending.
		'dlq_pending'      => 50,     // Alert if > 50 items in DLQ.
		'failed_per_hour'  => 100,    // Alert if > 100 failures per hour.
		'avg_wait_seconds' => 300,    // Alert if avg wait > 5 minutes.
	);

	/**
	 * Constructor.
	 *
	 * @param PriorityQueue   $priority_queue   Priority queue instance.
	 * @param DeadLetterQueue $dead_letter_queue Dead letter queue instance.
	 */
	public function __construct(
		private PriorityQueue $priority_queue,
		private DeadLetterQueue $dead_letter_queue
	) {
	}

	/**
	 * Get comprehensive queue health status.
	 *
	 * @return array<string, mixed> Health status.
	 */
	public function getHealthStatus(): array {
		$queue_stats = $this->priority_queue->getStats();
		$dlq_stats   = $this->dead_letter_queue->getStats();

		$totals = $this->calculateTotals( $queue_stats );
		$alerts = $this->checkAlerts( $totals, $dlq_stats );

		return array(
			'status'      => empty( $alerts ) ? 'healthy' : 'warning',
			'timestamp'   => current_time( 'mysql', true ),
			'queue'       => array(
				'by_priority' => $queue_stats,
				'totals'      => $totals,
			),
			'dead_letter' => $dlq_stats,
			'alerts'      => $alerts,
			'throughput'  => $this->getThroughputMetrics(),
		);
	}

	/**
	 * Get throughput metrics.
	 *
	 * @return array<string, mixed> Throughput data.
	 */
	public function getThroughputMetrics(): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'actionscheduler_actions';
		$hour_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		$day_ago  = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		// Get completed in last hour.
		$completed_hour = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status = 'complete'
				 AND last_attempt_gmt > %s",
				$hour_ago
			)
		);

		// Get completed in last day.
		$completed_day = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status = 'complete'
				 AND last_attempt_gmt > %s",
				$day_ago
			)
		);

		// Get failed in last hour.
		$failed_hour = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status = 'failed'
				 AND last_attempt_gmt > %s",
				$hour_ago
			)
		);

		// Calculate average wait time for completed jobs.
		$avg_wait = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(TIMESTAMPDIFF(SECOND, scheduled_date_gmt, last_attempt_gmt))
				 FROM {$table}
				 WHERE status = 'complete'
				 AND last_attempt_gmt > %s",
				$hour_ago
			)
		);

		return array(
			'completed_last_hour' => $completed_hour,
			'completed_last_day'  => $completed_day,
			'failed_last_hour'    => $failed_hour,
			'avg_wait_seconds'    => (float) ( $avg_wait ?? 0 ),
			'jobs_per_minute'     => round( $completed_hour / 60, 2 ),
			'success_rate'        => $completed_hour + $failed_hour > 0
				? round( ( $completed_hour / ( $completed_hour + $failed_hour ) ) * 100, 2 )
				: 100,
		);
	}

	/**
	 * Get failed jobs with details.
	 *
	 * @param int $limit Number of jobs to return.
	 *
	 * @return array<object> Failed jobs.
	 */
	public function getFailedJobs( int $limit = 50 ): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table = $wpdb->prefix . 'actionscheduler_logs';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					a.action_id,
					a.hook,
					a.args,
					a.scheduled_date_gmt,
					a.last_attempt_gmt,
					a.attempts,
					g.slug as group_name,
					l.message as last_error
				 FROM {$table} a
				 LEFT JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id
				 LEFT JOIN {$logs_table} l ON a.action_id = l.action_id
				 WHERE a.status = 'failed'
				 AND g.slug LIKE 'wch-%%'
				 ORDER BY a.last_attempt_gmt DESC
				 LIMIT %d",
				$limit
			)
		);

		return array_map(
			function ( $row ) {
				$row->args = json_decode( $row->args, true );
				return $row;
			},
			$results
		);
	}

	/**
	 * Get job history for a specific hook.
	 *
	 * @param string $hook  The action hook name.
	 * @param int    $limit Number of jobs to return.
	 *
	 * @return array<object> Job history.
	 */
	public function getJobHistory( string $hook, int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					action_id,
					status,
					scheduled_date_gmt,
					last_attempt_gmt,
					attempts,
					args
				 FROM {$table}
				 WHERE hook = %s
				 ORDER BY scheduled_date_gmt DESC
				 LIMIT %d",
				$hook,
				$limit
			)
		);
	}

	/**
	 * Calculate totals from queue stats.
	 *
	 * @param array<string, array> $queue_stats Queue statistics.
	 *
	 * @return array<string, int> Totals.
	 */
	private function calculateTotals( array $queue_stats ): array {
		$totals = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
		);

		foreach ( $queue_stats as $stats ) {
			$totals['pending']   += $stats['pending'];
			$totals['running']   += $stats['running'];
			$totals['completed'] += $stats['completed'];
			$totals['failed']    += $stats['failed'];
		}

		return $totals;
	}

	/**
	 * Check for alert conditions.
	 *
	 * @param array<string, int> $totals    Queue totals.
	 * @param array<string, int> $dlq_stats DLQ statistics.
	 *
	 * @return array<string> Active alerts.
	 */
	private function checkAlerts( array $totals, array $dlq_stats ): array {
		$alerts     = array();
		$throughput = $this->getThroughputMetrics();

		// Check pending total.
		if ( $totals['pending'] > $this->thresholds['pending_total'] ) {
			$alerts[] = sprintf(
				'High queue backlog: %d pending jobs (threshold: %d)',
				$totals['pending'],
				$this->thresholds['pending_total']
			);
		}

		// Check DLQ.
		if ( ( $dlq_stats['pending'] ?? 0 ) > $this->thresholds['dlq_pending'] ) {
			$alerts[] = sprintf(
				'Dead letter queue has %d pending items (threshold: %d)',
				$dlq_stats['pending'],
				$this->thresholds['dlq_pending']
			);
		}

		// Check failure rate.
		if ( $throughput['failed_last_hour'] > $this->thresholds['failed_per_hour'] ) {
			$alerts[] = sprintf(
				'High failure rate: %d failures in last hour (threshold: %d)',
				$throughput['failed_last_hour'],
				$this->thresholds['failed_per_hour']
			);
		}

		// Check average wait time.
		if ( $throughput['avg_wait_seconds'] > $this->thresholds['avg_wait_seconds'] ) {
			$alerts[] = sprintf(
				'High queue latency: %.1f seconds average wait (threshold: %d)',
				$throughput['avg_wait_seconds'],
				$this->thresholds['avg_wait_seconds']
			);
		}

		return $alerts;
	}

	/**
	 * Set alert threshold.
	 *
	 * @param string $key   Threshold key.
	 * @param int    $value Threshold value.
	 *
	 * @return void
	 */
	public function setThreshold( string $key, int $value ): void {
		if ( array_key_exists( $key, $this->thresholds ) ) {
			$this->thresholds[ $key ] = $value;
		}
	}

	/**
	 * Export metrics in Prometheus format.
	 *
	 * @return string Prometheus-formatted metrics.
	 */
	public function exportPrometheusMetrics(): string {
		$health = $this->getHealthStatus();
		$lines  = array();

		// Queue metrics.
		$lines[] = '# HELP wch_queue_pending Number of pending jobs by priority';
		$lines[] = '# TYPE wch_queue_pending gauge';
		foreach ( $health['queue']['by_priority'] as $priority => $stats ) {
			$lines[] = sprintf(
				'wch_queue_pending{priority="%s"} %d',
				$priority,
				$stats['pending']
			);
		}

		// Throughput metrics.
		$lines[] = '# HELP wch_queue_completed_total Total completed jobs';
		$lines[] = '# TYPE wch_queue_completed_total counter';
		$lines[] = sprintf(
			'wch_queue_completed_total %d',
			$health['throughput']['completed_last_day']
		);

		$lines[] = '# HELP wch_queue_success_rate Success rate percentage';
		$lines[] = '# TYPE wch_queue_success_rate gauge';
		$lines[] = sprintf(
			'wch_queue_success_rate %f',
			$health['throughput']['success_rate']
		);

		// DLQ metrics.
		$lines[] = '# HELP wch_dlq_pending Dead letter queue pending items';
		$lines[] = '# TYPE wch_dlq_pending gauge';
		$lines[] = sprintf(
			'wch_dlq_pending %d',
			$health['dead_letter']['pending'] ?? 0
		);

		return implode( "\n", $lines ) . "\n";
	}
}
