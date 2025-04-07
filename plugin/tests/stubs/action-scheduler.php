<?php
/**
 * Action Scheduler functions used by the plugin, for static analysis only.
 *
 * @package SupplierSync
 */

/**
 * @param string       $hook     Hook.
 * @param array<mixed> $args     Arguments.
 * @param string       $group    Group.
 * @param bool         $unique   Unique.
 * @param int          $priority Priority.
 */
function as_enqueue_async_action( $hook, $args = [], $group = '', $unique = false, $priority = 10 ): int {
	return 0;
}

/**
 * @param int          $timestamp Start.
 * @param int          $interval  Seconds.
 * @param string       $hook      Hook.
 * @param array<mixed> $args      Arguments.
 * @param string       $group     Group.
 * @param bool         $unique    Unique.
 * @param int          $priority  Priority.
 */
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = [], $group = '', $unique = false, $priority = 10 ): int {
	return 0;
}

/**
 * @param int          $timestamp Start.
 * @param string       $schedule  Cron expression.
 * @param string       $hook      Hook.
 * @param array<mixed> $args      Arguments.
 * @param string       $group     Group.
 * @param bool         $unique    Unique.
 * @param int          $priority  Priority.
 */
function as_schedule_cron_action( $timestamp, $schedule, $hook, $args = [], $group = '', $unique = false, $priority = 10 ): int {
	return 0;
}

/**
 * @param string            $hook  Hook.
 * @param array<mixed>|null $args  Arguments.
 * @param string            $group Group.
 */
function as_unschedule_all_actions( $hook, $args = [], $group = '' ): void {
}

/**
 * @param string            $hook  Hook.
 * @param array<mixed>|null $args  Arguments.
 * @param string            $group Group.
 * @return int|bool
 */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	return false;
}

/**
 * @param string            $hook  Hook.
 * @param array<mixed>|null $args  Arguments.
 * @param string            $group Group.
 */
function as_has_scheduled_action( $hook, $args = null, $group = '' ): bool {
	return false;
}
