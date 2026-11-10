<?php
/**
 * Unit checks for scheduling: frequencies, intervals, the run lock and the
 * stored outcome of the last run.
 *
 * Run inside the WordPress container:
 *   php /tmp/salvocofre-tests/test-cron.php
 *
 * The script snapshots every option it touches and restores it on shutdown, so
 * a failed assertion never leaves a lock behind or a changed result.
 *
 * @package Salvocofre
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

$GLOBALS['salvocofre_cron_test_state'] = array(
	'snapshotted' => false,
	'restored'    => false,
	'lock'        => array(
		'exists' => false,
		'value'  => '',
	),
	'transient'   => array(
		'exists'  => false,
		'value'   => '',
		'timeout' => false,
	),
	'last_run'    => array(
		'exists' => false,
		'value'  => '',
	),
	'last_result' => array(
		'exists' => false,
		'value'  => '',
	),
);

/**
 * Records the current value of every option the test changes.
 *
 * @return void
 */
function salvocofre_test_cron_snapshot() {
	$state = $GLOBALS['salvocofre_cron_test_state'];

	$lock_option = get_option( SALVOCOFRE_CRON_LOCK_OPTION, '__salvocofre_missing__' );

	$state['lock'] = array(
		'exists' => '__salvocofre_missing__' !== $lock_option,
		'value'  => $lock_option,
	);

	$transient = get_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT );

	$state['transient'] = array(
		'exists'  => false !== $transient,
		'value'   => $transient,
		'timeout' => get_option( '_transient_timeout_' . SALVOCOFRE_CRON_LOCK_TRANSIENT, false ),
	);

	$last_run = get_option( 'salvocofre_last_run', '__salvocofre_missing__' );

	$state['last_run'] = array(
		'exists' => '__salvocofre_missing__' !== $last_run,
		'value'  => $last_run,
	);

	$last_result = get_option( 'salvocofre_last_result', '__salvocofre_missing__' );

	$state['last_result'] = array(
		'exists' => '__salvocofre_missing__' !== $last_result,
		'value'  => $last_result,
	);

	$state['snapshotted']            = true;
	$GLOBALS['salvocofre_cron_test_state'] = $state;
}

/**
 * Puts every touched option back to the value it had before the test.
 *
 * Also runs from a shutdown handler, so a failed assertion still releases the
 * lock and restores the stored result.
 *
 * @return void
 */
function salvocofre_test_cron_restore() {
	$state = $GLOBALS['salvocofre_cron_test_state'];

	if ( empty( $state['snapshotted'] ) || ! empty( $state['restored'] ) ) {
		return;
	}

	// Drop whatever the test created before restoring the original state.
	delete_option( SALVOCOFRE_CRON_LOCK_OPTION );
	delete_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT );

	if ( $state['lock']['exists'] ) {
		update_option( SALVOCOFRE_CRON_LOCK_OPTION, $state['lock']['value'], false );
	}

	if ( $state['transient']['exists'] ) {
		$ttl = 0;

		if ( false !== $state['transient']['timeout'] ) {
			$ttl = max( 1, (int) $state['transient']['timeout'] - time() );
		}

		set_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT, $state['transient']['value'], $ttl );
	}

	foreach ( array( 'last_run', 'last_result' ) as $option ) {
		$name = 'salvocofre_' . $option;

		if ( $state[ $option ]['exists'] ) {
			update_option( $name, $state[ $option ]['value'], false );
		} else {
			delete_option( $name );
		}
	}

	$GLOBALS['salvocofre_cron_test_state']['restored'] = true;
}

register_shutdown_function( 'salvocofre_test_cron_restore' );

salvocofre_test_cron_snapshot();

// Start from a clean lock: a stale lock from an earlier manual run must not
// make the first assertion fail.
salvocofre_cron_release_lock();

echo "salvocofre_cron_frequencies()\n";

$salvocofre_frequencies = salvocofre_cron_frequencies();

salvocofre_assert( is_array( $salvocofre_frequencies ), 'returns an array' );
salvocofre_assert( count( $salvocofre_frequencies ) >= 4, 'offers at least four frequencies' );

foreach ( array( 'hourly', 'twicedaily', 'daily', 'salvocofre_weekly' ) as $salvocofre_slug ) {
	salvocofre_assert( isset( $salvocofre_frequencies[ $salvocofre_slug ] ), 'contains the ' . $salvocofre_slug . ' frequency' );
}

echo "\nsalvocofre_cron_interval()\n";

foreach ( array_keys( $salvocofre_frequencies ) as $salvocofre_slug ) {
	$salvocofre_interval = salvocofre_cron_interval( $salvocofre_slug );

	salvocofre_assert(
		is_int( $salvocofre_interval ) && $salvocofre_interval > 0,
		'returns a positive interval for ' . $salvocofre_slug
	);
}

salvocofre_assert(
	salvocofre_cron_interval( 'nonsense' ) === HOUR_IN_SECONDS,
	'an unknown frequency falls back to one hour'
);
salvocofre_assert(
	salvocofre_cron_interval( '' ) === HOUR_IN_SECONDS,
	'an empty frequency falls back to one hour'
);
salvocofre_assert(
	salvocofre_cron_interval( 'salvocofre_weekly' ) === WEEK_IN_SECONDS,
	'the weekly frequency uses a week'
);

echo "\nsalvocofre_cron_acquire_lock()\n";

$salvocofre_first_lock = salvocofre_cron_acquire_lock();

salvocofre_assert( true === $salvocofre_first_lock, 'the first call acquires the lock' );
salvocofre_assert(
	false !== get_option( SALVOCOFRE_CRON_LOCK_OPTION, false ),
	'stores the lock as an option'
);
salvocofre_assert(
	false !== get_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT ),
	'stores the lock as a transient'
);

$salvocofre_second_lock = salvocofre_cron_acquire_lock();

salvocofre_assert( is_wp_error( $salvocofre_second_lock ), 'the second call is refused' );
salvocofre_assert(
	is_wp_error( $salvocofre_second_lock ) && 'salvocofre_cron_locked' === $salvocofre_second_lock->get_error_code(),
	'the refusal uses the salvocofre_cron_locked code'
);

salvocofre_cron_release_lock();

salvocofre_assert(
	false === get_option( SALVOCOFRE_CRON_LOCK_OPTION, false ),
	'release removes the lock option'
);
salvocofre_assert(
	false === get_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT ),
	'release removes the lock transient'
);

$salvocofre_third_lock = salvocofre_cron_acquire_lock();

salvocofre_assert( true === $salvocofre_third_lock, 'the lock can be acquired again after release' );

salvocofre_cron_release_lock();

echo "\nsalvocofre_cron_take_over_stale_lock()\n";

$salvocofre_stale = time() - salvocofre_cron_lock_timeout() - 60;

update_option( SALVOCOFRE_CRON_LOCK_OPTION, $salvocofre_stale, false );

salvocofre_assert( false === get_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT ), 'the stale setup has no transient' );
salvocofre_assert( true === salvocofre_cron_take_over_stale_lock(), 'takes over an abandoned option' );

$salvocofre_refreshed = (int) get_option( SALVOCOFRE_CRON_LOCK_OPTION, 0 );

salvocofre_assert( $salvocofre_refreshed > $salvocofre_stale, 'replaces the old timestamp with the current time' );
salvocofre_assert( false === salvocofre_cron_take_over_stale_lock(), 'does not take over a fresh lock' );

$salvocofre_blocked = salvocofre_cron_acquire_lock();

salvocofre_assert( is_wp_error( $salvocofre_blocked ), 'the refreshed lock still blocks a second run' );

salvocofre_cron_release_lock();

// A stale option also gets taken over through the acquire path.
update_option( SALVOCOFRE_CRON_LOCK_OPTION, $salvocofre_stale, false );

salvocofre_assert( true === salvocofre_cron_acquire_lock(), 'acquire takes over a stale lock' );
salvocofre_assert(
	(int) get_option( SALVOCOFRE_CRON_LOCK_OPTION, 0 ) > $salvocofre_stale,
	'acquire refreshes the stale timestamp'
);

salvocofre_cron_release_lock();

echo "\nlock ownership\n";

salvocofre_cron_release_lock( true );

salvocofre_assert( true === salvocofre_cron_acquire_lock(), 'the lock can be acquired for the ownership checks' );
salvocofre_assert(
	1 === preg_match( '/^\d+:[0-9a-f-]{36}$/', (string) get_option( SALVOCOFRE_CRON_LOCK_OPTION, '' ) ),
	'the lock stores a timestamp and a token'
);

// Another run took the lock over while this one was stalled.
update_option( SALVOCOFRE_CRON_LOCK_OPTION, time() . ':foreign-token', false );

salvocofre_cron_release_lock();

salvocofre_assert(
	false !== get_option( SALVOCOFRE_CRON_LOCK_OPTION, false ),
	'release leaves a lock owned by another run in place'
);

salvocofre_cron_release_lock( true );

salvocofre_assert(
	false === get_option( SALVOCOFRE_CRON_LOCK_OPTION, false ),
	'force release removes the foreign lock'
);

echo "\nsalvocofre_cron_store_result()\n";

salvocofre_cron_store_result( 'ok', '', '', 2 );

$salvocofre_last_run    = get_option( 'salvocofre_last_run', false );
$salvocofre_last_result = get_option( 'salvocofre_last_result', array() );

salvocofre_assert( is_numeric( $salvocofre_last_run ), 'stores the run timestamp' );
salvocofre_assert(
	is_numeric( $salvocofre_last_run ) && abs( time() - (int) $salvocofre_last_run ) <= 5,
	'the run timestamp is the current time'
);
salvocofre_assert( is_array( $salvocofre_last_result ), 'stores the result as an array' );
salvocofre_assert(
	is_array( $salvocofre_last_result ) && 'ok' === $salvocofre_last_result['status'],
	'stores the ok status'
);
salvocofre_assert(
	is_array( $salvocofre_last_result ) && 2 === $salvocofre_last_result['deleted'],
	'stores the number of deleted backups'
);
salvocofre_assert(
	is_array( $salvocofre_last_result ) && (int) $salvocofre_last_result['time'] === (int) $salvocofre_last_run,
	'uses the same timestamp for the run and the result'
);

salvocofre_cron_store_result( 'error', 'salvocofre_test_error', 'Something failed.', 0 );

$salvocofre_last_result = get_option( 'salvocofre_last_result', array() );

salvocofre_assert(
	is_array( $salvocofre_last_result ) && 'error' === $salvocofre_last_result['status'],
	'stores the error status'
);
salvocofre_assert(
	is_array( $salvocofre_last_result ) && 'salvocofre_test_error' === $salvocofre_last_result['code'],
	'stores the error code'
);
salvocofre_assert(
	is_array( $salvocofre_last_result ) && 'Something failed.' === $salvocofre_last_result['message'],
	'stores the error message'
);

$salvocofre_normalised = salvocofre_cron_last_result();

salvocofre_assert( 'error' === $salvocofre_normalised['status'], 'salvocofre_cron_last_result reads the error status' );
salvocofre_assert( 0 === $salvocofre_normalised['deleted'], 'salvocofre_cron_last_result reads the deleted count' );

salvocofre_cron_store_result( 'unexpected', 'salvocofre_test_error', 'Something failed.', 0 );

$salvocofre_last_result = get_option( 'salvocofre_last_result', array() );

salvocofre_assert(
	is_array( $salvocofre_last_result ) && 'error' === $salvocofre_last_result['status'],
	'any status other than ok is stored as error'
);

salvocofre_test_cron_restore();

salvocofre_assert(
	$GLOBALS['salvocofre_cron_test_state']['lock']['value'] === get_option(
		SALVOCOFRE_CRON_LOCK_OPTION,
		'__salvocofre_missing__'
	),
	'restores the original lock option'
);
salvocofre_assert(
	$GLOBALS['salvocofre_cron_test_state']['transient']['value'] === get_transient( SALVOCOFRE_CRON_LOCK_TRANSIENT ),
	'restores the original lock transient'
);

unset(
	$salvocofre_frequencies,
	$salvocofre_slug,
	$salvocofre_interval,
	$salvocofre_first_lock,
	$salvocofre_second_lock,
	$salvocofre_third_lock,
	$salvocofre_stale,
	$salvocofre_refreshed,
	$salvocofre_blocked,
	$salvocofre_last_run,
	$salvocofre_last_result,
	$salvocofre_normalised
);

salvocofre_report();
