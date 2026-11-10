<?php
/**
 * Scheduling: the WP-Cron event, the run lock and the run bookkeeping.
 *
 * WP-Cron only fires when the site receives traffic. The status helpers in this
 * file expose "nothing scheduled" and "already overdue" so the settings page can
 * warn the site owner instead of failing silently.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SALVOCOFRE_CRON_HOOK' ) ) {
	define( 'SALVOCOFRE_CRON_HOOK', 'salvocofre_cron_backup' );
}

if ( ! defined( 'SALVOCOFRE_CRON_LOCK_OPTION' ) ) {
	define( 'SALVOCOFRE_CRON_LOCK_OPTION', 'salvocofre_cron_lock' );
}

if ( ! defined( 'SALVOCOFRE_CRON_LOCK_TRANSIENT' ) ) {
	define( 'SALVOCOFRE_CRON_LOCK_TRANSIENT', 'salvocofre_cron_lock' );
}

if ( ! defined( 'SALVOCOFRE_CRON_LOCK_TIMEOUT' ) ) {
	define( 'SALVOCOFRE_CRON_LOCK_TIMEOUT', 15 * MINUTE_IN_SECONDS );
}

if ( ! defined( 'SALVOCOFRE_CRON_FREQUENCY_DEFAULT' ) ) {
	define( 'SALVOCOFRE_CRON_FREQUENCY_DEFAULT', 'daily' );
}

register_activation_hook( dirname( __DIR__ ) . '/salvocofre.php', 'salvocofre_cron_activate' );
register_deactivation_hook( dirname( __DIR__ ) . '/salvocofre.php', 'salvocofre_cron_deactivate' );

add_action( 'cron_schedules', 'salvocofre_cron_schedules' );
add_action( SALVOCOFRE_CRON_HOOK, 'salvocofre_cron_run' );

// Saving the schedule options must immediately create, move or remove the event.
add_action( 'add_option_salvocofre_schedule_enabled', 'salvocofre_cron_sync_schedule' );
add_action( 'update_option_salvocofre_schedule_enabled', 'salvocofre_cron_sync_schedule' );
add_action( 'add_option_salvocofre_schedule_frequency', 'salvocofre_cron_sync_schedule' );
add_action( 'update_option_salvocofre_schedule_frequency', 'salvocofre_cron_sync_schedule' );

/**
 * Re-creates a missing event on every admin request.
 *
 * The install can lose its cron option (database restore, broken migration);
 * this keeps automatic backups alive without waiting for a manual save.
 *
 * @return void
 */
function salvocofre_cron_maybe_schedule() {
	$result = salvocofre_cron_schedule();

	if ( is_wp_error( $result ) ) {
		salvocofre_log( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

add_action( 'admin_init', 'salvocofre_cron_maybe_schedule' );

/**
 * Registers the weekly recurrence used by the frequency setting.
 *
 * @param array<string,array> $schedules Schedules registered so far.
 * @return array<string,array>
 */
function salvocofre_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['salvocofre_weekly'] ) ) {
		$schedules['salvocofre_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly', 'salvocofre' ),
		);
	}

	return $schedules;
}

/**
 * Frequencies offered to the site owner: slug => label.
 *
 * @return array<string,string>
 */
function salvocofre_cron_frequencies() {
	$frequencies = array(
		'hourly'          => __( 'Hourly', 'salvocofre' ),
		'twicedaily'      => __( 'Twice daily', 'salvocofre' ),
		'daily'           => __( 'Daily', 'salvocofre' ),
		'salvocofre_weekly' => __( 'Weekly', 'salvocofre' ),
	);

	return (array) apply_filters( 'salvocofre_cron_frequencies', $frequencies );
}

/**
 * Current frequency, always one of salvocofre_cron_frequencies().
 *
 * @return string
 */
function salvocofre_cron_frequency() {
	$frequency = (string) get_option( 'salvocofre_schedule_frequency', SALVOCOFRE_CRON_FREQUENCY_DEFAULT );
	$known     = salvocofre_cron_frequencies();

	if ( ! isset( $known[ $frequency ] ) ) {
		return SALVOCOFRE_CRON_FREQUENCY_DEFAULT;
	}

	return $frequency;
}

/**
 * Tells whether automatic backups are enabled.
 *
 * @return bool
 */
function salvocofre_cron_enabled() {
	return (bool) apply_filters( 'salvocofre_cron_enabled', (bool) get_option( 'salvocofre_schedule_enabled', true ) );
}

/**
 * Interval of a recurrence in seconds.
 *
 * @param string $frequency Recurrence slug.
 * @return int
 */
function salvocofre_cron_interval( $frequency ) {
	$schedules = wp_get_schedules();

	if ( isset( $schedules[ $frequency ]['interval'] ) ) {
		return (int) $schedules[ $frequency ]['interval'];
	}

	return HOUR_IN_SECONDS;
}

/**
 * Makes sure exactly one event exists, or none when scheduling is disabled.
 *
 * Calling this twice never creates a second event: the existing event is kept
 * when it already uses the configured frequency, and replaced otherwise.
 *
 * @return true|WP_Error
 */
function salvocofre_cron_schedule() {
	if ( ! salvocofre_cron_enabled() ) {
		salvocofre_cron_unschedule();

		return true;
	}

	$frequency = salvocofre_cron_frequency();
	$next_run  = wp_next_scheduled( SALVOCOFRE_CRON_HOOK );

	if ( $next_run ) {
		if ( wp_get_schedule( SALVOCOFRE_CRON_HOOK ) === $frequency ) {
			return true;
		}

		// The frequency changed: drop the old event before creating the new one.
		salvocofre_cron_unschedule();
	}

	$timestamp = time() + salvocofre_cron_interval( $frequency );
	$scheduled = wp_schedule_event( $timestamp, $frequency, SALVOCOFRE_CRON_HOOK );

	if ( is_wp_error( $scheduled ) ) {
		return $scheduled;
	}

	if ( true !== $scheduled ) {
		return new WP_Error(
			'salvocofre_cron_schedule_failed',
			__( 'Could not schedule the automatic backup.', 'salvocofre' )
		);
	}

	return true;
}

/**
 * Removes every event for the backup hook.
 *
 * @return void
 */
function salvocofre_cron_unschedule() {
	wp_clear_scheduled_hook( SALVOCOFRE_CRON_HOOK );
}

/**
 * Applies the current settings to the cron event.
 *
 * Registered on the option hooks, so saving the settings page is enough.
 *
 * @return void
 */
function salvocofre_cron_sync_schedule() {
	$result = salvocofre_cron_schedule();

	if ( is_wp_error( $result ) ) {
		salvocofre_log( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

/**
 * Activation hook: stores the defaults and schedules the first event.
 *
 * @return void
 */
function salvocofre_cron_activate() {
	add_option( 'salvocofre_schedule_enabled', true );
	add_option( 'salvocofre_schedule_frequency', SALVOCOFRE_CRON_FREQUENCY_DEFAULT );

	salvocofre_cron_sync_schedule();
}

/**
 * Deactivation hook: removes the event and any leftover lock.
 *
 * @return void
 */
function salvocofre_cron_deactivate() {
	salvocofre_cron_unschedule();
	salvocofre_cron_release_lock();
}

/**
 * Number of seconds after which a lock is considered abandoned.
 *
 * @return int
 */
function salvocofre_cron_lock_timeout() {
	return max( 1, (int) apply_filters( 'salvocofre_cron_lock_timeout', SALVOCOFRE_CRON_LOCK_TIMEOUT ) );
}

/**
 * Takes the run lock so two scheduled backups never run at the same time.
 *
 * The transient gives a cheap expiry; add_option() is the race guard because
 * the database unique key only lets one request create the row.
 *
 * @return true|WP_Error
 */
function salvocofre_cron_acquire_lock() {
	if ( false !== get_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT ) ) {
		return new WP_Error( 'salvocofre_cron_locked', __( 'A scheduled backup is already running.', 'salvocofre' ) );
	}

	$token = wp_generate_uuid4();
	$value = time() . ':' . $token;

	if ( add_option( SALVOCOFRE_CRON_LOCK_OPTION, $value, '', false ) ) {
		$GLOBALS['salvocofre_cron_lock_value'] = $value;
	} elseif ( ! salvocofre_cron_take_over_stale_lock( $token ) ) {
		return new WP_Error( 'salvocofre_cron_locked', __( 'A scheduled backup is already running.', 'salvocofre' ) );
	}

	set_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT, time(), salvocofre_cron_lock_timeout() );

	return true;
}

/**
 * Replaces a lock left behind by a run that died without releasing it.
 *
 * The UPDATE only matches the exact value that was read, so two requests
 * cannot both steal the same lock: the database picks a single winner.
 *
 * @param string $token Optional lock token; generated when empty.
 * @return bool True when this request now owns the lock.
 */
function salvocofre_cron_take_over_stale_lock( $token = '' ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The raw value is required for the compare-and-swap below; get_option() would hand back a cached copy that another request may already have replaced.
	$raw = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s',
			$wpdb->options,
			SALVOCOFRE_CRON_LOCK_OPTION
		)
	);

	if ( ! is_string( $raw ) || '' === $raw ) {
		return false;
	}

	// The stored value is "timestamp:token"; older locks hold a bare timestamp.
	$started = (int) $raw;

	if ( $started <= 0 || ( time() - $started ) < salvocofre_cron_lock_timeout() ) {
		return false;
	}

	if ( '' === $token ) {
		$token = wp_generate_uuid4();
	}

	$value = time() . ':' . $token;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update: the options API has no compare-and-swap, and the WHERE clause is what keeps two requests from taking the same lock.
	$updated = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
			$wpdb->options,
			$value,
			SALVOCOFRE_CRON_LOCK_OPTION,
			$raw
		)
	);

	wp_cache_delete( SALVOCOFRE_CRON_LOCK_OPTION, 'options' );

	if ( 1 !== (int) $updated ) {
		return false;
	}

	$GLOBALS['salvocofre_cron_lock_value'] = $value;

	salvocofre_log( 'cron: took over a stale backup lock' );

	return true;
}

/**
 * Releases the run lock, but only while this request still owns it.
 *
 * A run that stalls past the timeout loses the lock to another request; when
 * it finally ends it must not delete the lock that run legitimately took.
 *
 * @param bool $force Delete the lock even when this request does not own it.
 * @return void
 */
function salvocofre_cron_release_lock( $force = false ) {
	global $wpdb;

	$value = isset( $GLOBALS['salvocofre_cron_lock_value'] ) ? (string) $GLOBALS['salvocofre_cron_lock_value'] : '';

	if ( ! $force && '' !== $value ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete, so a run that lost its lock cannot remove the lock another run is holding.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				SALVOCOFRE_CRON_LOCK_OPTION,
				$value
			)
		);

		wp_cache_delete( SALVOCOFRE_CRON_LOCK_OPTION, 'options' );

		if ( 1 !== (int) $deleted ) {
			salvocofre_log( 'cron: lock belongs to another run, leaving it in place' );
			return;
		}
	} else {
		delete_option( SALVOCOFRE_CRON_LOCK_OPTION );
	}

	unset( $GLOBALS['salvocofre_cron_lock_value'] );

	delete_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT );
}

/**
 * Cron callback. Never lets an error escape into WP-Cron.
 *
 * @return void
 */
function salvocofre_cron_run() {
	$result = salvocofre_cron_execute();

	if ( is_wp_error( $result ) ) {
		salvocofre_log( $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

/**
 * Runs one scheduled backup under the lock and records the outcome.
 *
 * @return true|WP_Error
 */
function salvocofre_cron_execute() {
	$lock = salvocofre_cron_acquire_lock();

	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		$backup = salvocofre_create_backup();

		if ( is_wp_error( $backup ) ) {
			salvocofre_cron_store_result( 'error', $backup->get_error_code(), $backup->get_error_message() );

			return $backup;
		}

		$deleted = salvocofre_apply_retention();

		salvocofre_cron_store_result( 'ok', '', '', count( $deleted ) );

		return true;
	} catch ( Throwable $exception ) {
		salvocofre_cron_store_result( 'error', 'salvocofre_cron_exception', $exception->getMessage() );

		return new WP_Error( 'salvocofre_cron_exception', $exception->getMessage() );
	} finally {
		salvocofre_cron_release_lock();
	}
}

/**
 * Stores the timestamp and the outcome of the last run.
 *
 * @param string $status  Either "ok" or "error".
 * @param string $code    Error code, empty on success.
 * @param string $message Error message, empty on success.
 * @param int    $deleted Number of old backups removed by retention.
 * @return void
 */
function salvocofre_cron_store_result( $status, $code = '', $message = '', $deleted = 0 ) {
	$now = time();

	update_option( 'salvocofre_last_run', $now, false );
	update_option(
		'salvocofre_last_result',
		array(
			'status'  => ( 'ok' === $status ) ? 'ok' : 'error',
			'code'    => (string) $code,
			'message' => (string) $message,
			'time'    => $now,
			'deleted' => (int) $deleted,
		),
		false
	);
}

/**
 * Timestamp of the last scheduled run, 0 when it never ran.
 *
 * @return int
 */
function salvocofre_cron_last_run() {
	return (int) get_option( 'salvocofre_last_run', 0 );
}

/**
 * Outcome of the last scheduled run, normalised.
 *
 * @return array{status:string,code:string,message:string,time:int,deleted:int}
 */
function salvocofre_cron_last_result() {
	$stored = get_option( 'salvocofre_last_result', array() );

	$result = wp_parse_args(
		is_array( $stored ) ? $stored : array(),
		array(
			'status'  => '',
			'code'    => '',
			'message' => '',
			'time'    => 0,
			'deleted' => 0,
		)
	);

	$result['status']  = in_array( $result['status'], array( 'ok', 'error' ), true ) ? $result['status'] : '';
	$result['code']    = (string) $result['code'];
	$result['message'] = (string) $result['message'];
	$result['time']    = (int) $result['time'];
	$result['deleted'] = (int) $result['deleted'];

	return $result;
}

/**
 * Snapshot of the schedule used by the settings page.
 *
 * "Overdue" means the event was due in the past and WP-Cron has not run it yet,
 * which is the visible symptom of a site without traffic.
 *
 * @return array<string,mixed>
 */
function salvocofre_cron_status() {
	$enabled  = salvocofre_cron_enabled();
	$next_run = (int) wp_next_scheduled( SALVOCOFRE_CRON_HOOK );

	return array(
		'enabled'       => $enabled,
		'frequency'     => salvocofre_cron_frequency(),
		'next_run'      => $next_run,
		'overdue'       => $next_run > 0 && $next_run < time(),
		'not_scheduled' => $enabled && 0 === $next_run,
		'last_run'      => salvocofre_cron_last_run(),
		'last_result'   => salvocofre_cron_last_result(),
		'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
	);
}
