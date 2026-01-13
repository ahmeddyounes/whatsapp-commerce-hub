<?php
/**
 * PHPStan stubs for Action Scheduler functions
 *
 * Action Scheduler is a WordPress plugin/library that provides
 * background job processing. These stubs define the function signatures.
 *
 * @package WhatsApp_Commerce_Hub
 * @see https://actionscheduler.org/
 */

/**
 * Schedule a recurring action.
 *
 * @param int    $timestamp           When the first instance should run.
 * @param int    $interval_in_seconds How often the action should repeat.
 * @param string $hook                The hook to trigger.
 * @param array  $args                Arguments to pass to the hook.
 * @param string $group               The group to assign this action to.
 * @param bool   $unique              Whether the action should be unique.
 * @param int    $priority            Lower values = higher priority.
 * @return int The action ID.
 */
function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 0;
}

/**
 * Schedule a single action.
 *
 * @param int    $timestamp When the action should run.
 * @param string $hook      The hook to trigger.
 * @param array  $args      Arguments to pass to the hook.
 * @param string $group     The group to assign this action to.
 * @param bool   $unique    Whether the action should be unique.
 * @param int    $priority  Lower values = higher priority.
 * @return int The action ID.
 */
function as_schedule_single_action( int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 0;
}

/**
 * Get the next scheduled action.
 *
 * @param string $hook  The hook to check.
 * @param array  $args  Arguments to match.
 * @param string $group The group to check.
 * @return int|false The timestamp of the next scheduled action, or false if none.
 */
function as_next_scheduled_action( string $hook, array $args = [], string $group = '' ) {
	return false;
}

/**
 * Unschedule an action.
 *
 * @param string $hook  The hook to unschedule.
 * @param array  $args  Arguments to match.
 * @param string $group The group to check.
 * @return int|null The action ID that was unscheduled, or null if none found.
 */
function as_unschedule_action( string $hook, array $args = [], string $group = '' ): ?int {
	return null;
}

/**
 * Unschedule all actions matching the given hook.
 *
 * @param string $hook  The hook to unschedule.
 * @param array  $args  Arguments to match.
 * @param string $group The group to check.
 * @return void
 */
function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
}

/**
 * Check if there is an existing action in the queue.
 *
 * @param string $hook  The hook to check.
 * @param array  $args  Arguments to match.
 * @param string $group The group to check.
 * @return bool Whether there is an existing action.
 */
function as_has_scheduled_action( string $hook, array $args = [], string $group = '' ): bool {
	return false;
}

/**
 * Enqueue an async action to run as soon as possible.
 *
 * @param string $hook     The hook to trigger.
 * @param array  $args     Arguments to pass to the hook.
 * @param string $group    The group to assign this action to.
 * @param bool   $unique   Whether the action should be unique.
 * @param int    $priority Lower values = higher priority.
 * @return int The action ID.
 */
function as_enqueue_async_action( string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10 ): int {
	return 0;
}

/**
 * Get scheduled actions.
 *
 * @param array  $args   Query arguments.
 * @param string $return Return format: 'ids', 'objects', or 'ARRAY_A'.
 * @return array Array of actions.
 */
function as_get_scheduled_actions( array $args = [], string $return = 'objects' ): array {
	return [];
}
