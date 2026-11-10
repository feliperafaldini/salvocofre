<?php
/**
 * Unit checks for the settings sanitizers in admin/settings.php.
 *
 * Run inside the WordPress container:
 *   php /tmp/salvocofre-tests/test-settings.php
 *
 * The retention option is changed once to prove the fallback behaviour and is
 * restored on shutdown, even when an assertion fails.
 *
 * @package Salvocofre
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

$GLOBALS['salvocofre_settings_test_retention'] = array(
	'snapshotted' => false,
	'restored'    => false,
	'exists'      => false,
	'value'       => '',
);

/**
 * Records the current retention option.
 *
 * @return void
 */
function salvocofre_test_settings_snapshot() {
	$original = get_option( 'salvocofre_retention_count', '__salvocofre_missing__' );

	$GLOBALS['salvocofre_settings_test_retention'] = array(
		'snapshotted' => true,
		'restored'    => false,
		'exists'      => '__salvocofre_missing__' !== $original,
		'value'       => $original,
	);
}

/**
 * Puts the retention option back to the value it had before the test.
 *
 * @return void
 */
function salvocofre_test_settings_restore() {
	$state = $GLOBALS['salvocofre_settings_test_retention'];

	if ( empty( $state['snapshotted'] ) || ! empty( $state['restored'] ) ) {
		return;
	}

	if ( $state['exists'] ) {
		update_option( 'salvocofre_retention_count', $state['value'] );
	} else {
		delete_option( 'salvocofre_retention_count' );
	}

	$GLOBALS['salvocofre_settings_test_retention']['restored'] = true;
}

register_shutdown_function( 'salvocofre_test_settings_restore' );

salvocofre_test_settings_snapshot();

echo "salvocofre_sanitize_retention_count()\n";

salvocofre_assert( 50 === salvocofre_sanitize_retention_count( 999 ), 'clamps 999 to the maximum of 50' );
salvocofre_assert( 0 === salvocofre_sanitize_retention_count( -3 ), 'clamps a negative count to 0' );
salvocofre_assert( 50 === salvocofre_sanitize_retention_count( 51 ), 'clamps 51 to the maximum of 50' );
salvocofre_assert( 0 === salvocofre_sanitize_retention_count( 0 ), 'accepts 0' );
salvocofre_assert( 5 === salvocofre_sanitize_retention_count( 5 ), 'accepts a value inside the range' );
salvocofre_assert( 12 === salvocofre_sanitize_retention_count( '12' ), 'accepts a numeric string' );
salvocofre_assert( 3 === salvocofre_sanitize_retention_count( 3.9 ), 'truncates a float' );
salvocofre_assert( 50 === salvocofre_sanitize_retention_count( '999' ), 'clamps a numeric string above the range' );

// With nothing stored, a non-numeric value falls back to the default.
delete_option( 'salvocofre_retention_count' );

salvocofre_assert(
	SALVOCOFRE_RETENTION_DEFAULT === salvocofre_sanitize_retention_count( 'abc' ),
	'a non-numeric value falls back to the default when nothing is stored'
);
salvocofre_assert(
	SALVOCOFRE_RETENTION_DEFAULT === salvocofre_sanitize_retention_count( '' ),
	'an empty value falls back to the default when nothing is stored'
);

// With a stored value, the sanitizer keeps it instead of resetting to default.
update_option( 'salvocofre_retention_count', 7 );

salvocofre_assert( 7 === salvocofre_sanitize_retention_count( 'abc' ), 'a non-numeric value keeps the stored value' );
salvocofre_assert( 7 === salvocofre_sanitize_retention_count( null ), 'null keeps the stored value' );
salvocofre_assert( 7 === salvocofre_sanitize_retention_count( array( 3 ) ), 'an array keeps the stored value' );

echo "\nsalvocofre_sanitize_schedule_frequency()\n";

salvocofre_assert(
	'salvocofre_weekly' === salvocofre_sanitize_schedule_frequency( 'salvocofre_weekly' ),
	'accepts the weekly slug used by the plugin'
);
salvocofre_assert( 'daily' === salvocofre_sanitize_schedule_frequency( 'daily' ), 'accepts daily' );
salvocofre_assert( 'hourly' === salvocofre_sanitize_schedule_frequency( 'hourly' ), 'accepts hourly' );
salvocofre_assert( 'twicedaily' === salvocofre_sanitize_schedule_frequency( 'twicedaily' ), 'accepts twicedaily' );
salvocofre_assert(
	'daily' === salvocofre_sanitize_schedule_frequency( 'nonsense' ),
	'an unknown frequency falls back to daily'
);
salvocofre_assert(
	'daily' === salvocofre_sanitize_schedule_frequency( 'weekly' ),
	'the bare weekly slug is not a plugin frequency and falls back to daily'
);
salvocofre_assert( 'daily' === salvocofre_sanitize_schedule_frequency( '' ), 'an empty frequency falls back to daily' );
salvocofre_assert( 'daily' === salvocofre_sanitize_schedule_frequency( null ), 'null falls back to daily' );
salvocofre_assert( 'daily' === salvocofre_sanitize_schedule_frequency( array( 'daily' ) ), 'an array falls back to daily' );
salvocofre_assert(
	'daily' === salvocofre_sanitize_schedule_frequency( 'DAILY' ),
	'the value is sanitized into a key before the lookup'
);

foreach ( array( 'hourly', 'twicedaily', 'daily', 'salvocofre_weekly' ) as $salvocofre_slug ) {
	$salvocofre_sanitized = salvocofre_sanitize_schedule_frequency( $salvocofre_slug );

	salvocofre_assert(
		isset( salvocofre_cron_frequencies()[ $salvocofre_sanitized ] ),
		'the sanitized ' . $salvocofre_slug . ' is a known frequency'
	);
}

echo "\nsalvocofre_sanitize_schedule_enabled()\n";

salvocofre_assert( true === salvocofre_sanitize_schedule_enabled( 1 ), 'accepts integer 1' );
salvocofre_assert( false === salvocofre_sanitize_schedule_enabled( 0 ), 'accepts integer 0' );
salvocofre_assert( true === salvocofre_sanitize_schedule_enabled( '1' ), 'accepts the string "1"' );
salvocofre_assert( false === salvocofre_sanitize_schedule_enabled( '0' ), 'accepts the string "0"' );
salvocofre_assert( true === salvocofre_sanitize_schedule_enabled( 'on' ), 'accepts "on" from the checkbox' );
salvocofre_assert( true === salvocofre_sanitize_schedule_enabled( true ), 'accepts a boolean true' );
salvocofre_assert( false === salvocofre_sanitize_schedule_enabled( '' ), 'rejects an empty value' );
salvocofre_assert( false === salvocofre_sanitize_schedule_enabled( 'nonsense' ), 'rejects an unknown value' );
salvocofre_assert( false === salvocofre_sanitize_schedule_enabled( array( 1 ) ), 'rejects an array' );
salvocofre_assert(
	is_bool( salvocofre_sanitize_schedule_enabled( '1' ) ) && is_bool( salvocofre_sanitize_schedule_enabled( '0' ) ),
	'returns a boolean, which WordPress stores as 1 or 0'
);

salvocofre_test_settings_restore();

salvocofre_assert(
	$GLOBALS['salvocofre_settings_test_retention']['value'] === get_option(
		'salvocofre_retention_count',
		'__salvocofre_missing__'
	),
	'restores the original retention option'
);

unset( $salvocofre_slug, $salvocofre_sanitized );

salvocofre_report();
