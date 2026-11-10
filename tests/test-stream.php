<?php
/**
 * Streaming checks for the SQL dump writer and the restore reader.
 *
 * Run inside the WordPress container:
 *   php /tmp/salvocofre-tests/test-stream.php
 *
 * The script creates a single temporary table, <prefix>salvocofre_stream_test,
 * and drops it from a shutdown handler so a failed assertion never leaves
 * data behind. No real table is ever dropped.
 *
 * @package Salvocofre
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

global $wpdb;

$GLOBALS['salvocofre_test_stream_table'] = $wpdb->prefix . 'salvocofre_stream_test';
$GLOBALS['salvocofre_test_stream_files'] = array();

/**
 * Removes every temporary file and table created by the test.
 *
 * Registered as a shutdown function as well, so the table is dropped even when
 * an assertion aborts the script.
 *
 * @return void
 */
function salvocofre_test_stream_cleanup() {
	global $wpdb;

	$table = (string) $GLOBALS['salvocofre_test_stream_table'];

	if ( '' !== $table ) {
		// The table name is built from $wpdb->prefix plus a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- test cleanup for a temporary table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	foreach ( (array) $GLOBALS['salvocofre_test_stream_files'] as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}

	$GLOBALS['salvocofre_test_stream_files'] = array();
}

register_shutdown_function( 'salvocofre_test_stream_cleanup' );

/**
 * Creates an empty temporary file and remembers it for cleanup.
 *
 * @return string|WP_Error Absolute path on success.
 */
function salvocofre_test_stream_temp_file() {
	$path = tempnam( get_temp_dir(), 'salvocofre-stream-test-' );

	if ( false === $path ) {
		return new WP_Error( 'salvocofre_test_temp_file', 'Could not create a temporary file.' );
	}

	$GLOBALS['salvocofre_test_stream_files'][] = $path;

	return $path;
}

/**
 * Tells whether the temporary table exists.
 *
 * @param string $table Table name.
 * @return bool
 */
function salvocofre_test_stream_table_exists( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test inspection of the temporary table.
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/**
 * Drops the temporary table before a test that recreates it.
 *
 * @param string $table Table name.
 * @return void
 */
function salvocofre_test_stream_drop_table( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the only table the test is allowed to drop.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	$wpdb->last_error = '';
}

/**
 * Reads one note column of the temporary table.
 *
 * @param string $table Table name.
 * @param int    $id    Row id.
 * @return string|null
 */
function salvocofre_test_stream_note( $table, $id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test inspection of the temporary table.
	return $wpdb->get_var( $wpdb->prepare( 'SELECT `note` FROM %i WHERE `id` = %d', $table, $id ) );
}

/**
 * Hides the timestamp of a dump so two runs can be compared.
 *
 * @param string $sql Dump contents.
 * @return string
 */
function salvocofre_test_stream_normalize_date( $sql ) {
	return (string) preg_replace( '/^-- Date: .*$/m', '-- Date: <normalized>', $sql );
}

/**
 * Fills the temporary table with a payload big enough to produce a 5 MB dump.
 *
 * @param string $table   Table name.
 * @param int    $rows    Number of rows.
 * @param string $payload Value stored in every row.
 * @return true|WP_Error True on success.
 */
function salvocofre_test_stream_fill_table( $table, $rows, $payload ) {
	global $wpdb;

	$table_sql  = $wpdb->prepare( '%i', $table );
	$batch_size = 100;

	for ( $start = 1; $start <= $rows; $start += $batch_size ) {
		$values = array();
		$end    = min( $rows, $start + $batch_size - 1 );

		for ( $id = $start; $id <= $end; $id++ ) {
			$values[] = $wpdb->prepare( '(%d, %s)', $id, $payload );
		}

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk fixture for the streaming test.
		$result = $wpdb->query( 'INSERT INTO ' . $table_sql . ' (`id`, `payload`) VALUES ' . implode( ', ', $values ) );

		if ( false === $result || '' !== $wpdb->last_error ) {
			return new WP_Error(
				'salvocofre_test_fill_failed',
				'' !== $wpdb->last_error ? $wpdb->last_error : 'The fixture INSERT failed.'
			);
		}
	}

	return true;
}

$missing_functions = array();

foreach ( array( 'salvocofre_write_sql_dump', 'salvocofre_build_sql_dump', 'salvocofre_restore_database_from_file' ) as $salvocofre_function ) {
	if ( ! function_exists( $salvocofre_function ) ) {
		$missing_functions[] = $salvocofre_function;
	}
}

if ( $missing_functions ) {
	echo "Missing contract functions: " . implode( ', ', $missing_functions ) . "\n";
	salvocofre_assert( false, 'the streaming contract functions are available' );
	salvocofre_report();
}

$salvocofre_table    = $GLOBALS['salvocofre_test_stream_table'];
$salvocofre_table_id = '`' . $salvocofre_table . '`';

echo "salvocofre_write_sql_dump()\n";

$salvocofre_dump_path = salvocofre_test_stream_temp_file();

salvocofre_assert( ! is_wp_error( $salvocofre_dump_path ), 'a temporary path is available' );

if ( is_wp_error( $salvocofre_dump_path ) ) {
	salvocofre_report();
}

$salvocofre_written = salvocofre_write_sql_dump( $salvocofre_dump_path );

salvocofre_assert( ! is_wp_error( $salvocofre_written ), 'the dump is written without a WP_Error' );

if ( is_wp_error( $salvocofre_written ) ) {
	printf( "  info %s: %s\n", $salvocofre_written->get_error_code(), $salvocofre_written->get_error_message() );
	salvocofre_report();
}

$salvocofre_size = (int) filesize( $salvocofre_dump_path );

salvocofre_assert( is_int( $salvocofre_written ) && $salvocofre_written > 0, 'returns a positive byte count' );
salvocofre_assert( $salvocofre_size > 0, 'the dump file is not empty' );
salvocofre_assert( $salvocofre_written === $salvocofre_size, 'the returned byte count matches the file size' );

$salvocofre_stream = (string) file_get_contents( $salvocofre_dump_path );

salvocofre_assert( str_starts_with( $salvocofre_stream, '-- Salvocofre Backup' ), 'the file starts with the dump header' );
salvocofre_assert( str_contains( $salvocofre_stream, "-- Site: " . home_url() . "\n" ), 'the header names the site' );
salvocofre_assert( 1 === preg_match( '/^-- Date: \S+$/m', $salvocofre_stream ), 'the header has a date line' );
salvocofre_assert( str_contains( $salvocofre_stream, "SET SESSION sql_mode = '';" ), 'the dump resets sql_mode' );
salvocofre_assert( 1 === preg_match( '/^SET NAMES \S+/m', $salvocofre_stream ), 'the dump declares the charset' );
salvocofre_assert( str_contains( $salvocofre_stream, 'SET FOREIGN_KEY_CHECKS = 0;' ), 'the dump disables foreign key checks' );
salvocofre_assert( str_contains( $salvocofre_stream, 'START TRANSACTION WITH CONSISTENT SNAPSHOT;' ), 'the dump opens a consistent snapshot' );
salvocofre_assert( str_ends_with( rtrim( $salvocofre_stream ), 'SET FOREIGN_KEY_CHECKS = 1;' ), 'the file ends by re-enabling foreign key checks' );
salvocofre_assert( str_contains( $salvocofre_stream, "\nCOMMIT;\n" ), 'the dump commits before finishing' );

$salvocofre_tables = $wpdb->get_col( 'SHOW TABLES' );

salvocofre_assert( ! $wpdb->last_error, 'the table list can be read' );
salvocofre_assert(
	count( $salvocofre_tables ) === substr_count( $salvocofre_stream, 'CREATE TABLE ' ),
	'there is one CREATE TABLE per database table (' . count( $salvocofre_tables ) . ')'
);

foreach ( $salvocofre_tables as $salvocofre_one_table ) {
	salvocofre_assert(
		str_contains( $salvocofre_stream, 'CREATE TABLE `' . $salvocofre_one_table . '`' ),
		'CREATE TABLE present for ' . $salvocofre_one_table
	);
	salvocofre_assert(
		str_contains( $salvocofre_stream, 'DROP TABLE IF EXISTS `' . $salvocofre_one_table . '`' ),
		'DROP TABLE IF EXISTS present for ' . $salvocofre_one_table
	);
}

echo "\nsalvocofre_write_sql_dump() and salvocofre_build_sql_dump() agree\n";

$salvocofre_built = salvocofre_build_sql_dump();

salvocofre_assert( is_string( $salvocofre_built ), 'the in-memory dump is a string' );

if ( is_string( $salvocofre_built ) ) {
	salvocofre_assert(
		salvocofre_test_stream_normalize_date( $salvocofre_stream ) === salvocofre_test_stream_normalize_date( $salvocofre_built ),
		'the file matches the in-memory dump except for the Date line'
	);
}

unset( $salvocofre_stream, $salvocofre_built );

echo "\nsalvocofre_restore_database_from_file()\n";

salvocofre_test_stream_drop_table( $salvocofre_table );

salvocofre_assert( ! salvocofre_test_stream_table_exists( $salvocofre_table ), 'the restore test starts without the temporary table' );

$salvocofre_minimal = "-- Minimal dump for the streaming restore test\n"
	. "SET NAMES utf8mb4;\n"
	. "SET FOREIGN_KEY_CHECKS = 0;\n"
	. "START TRANSACTION WITH CONSISTENT SNAPSHOT;\n\n"
	. 'DROP TABLE IF EXISTS ' . $salvocofre_table_id . ";\n"
	. 'CREATE TABLE ' . $salvocofre_table_id . " (\n"
	. "  `id` bigint unsigned NOT NULL,\n"
	. "  `note` varchar(100) NOT NULL,\n"
	. "  PRIMARY KEY (`id`)\n"
	. ");\n\n"
	. 'INSERT INTO ' . $salvocofre_table_id . " (`id`, `note`) VALUES (1, 'alpha'), (2, 'beta'), (3, 'gamma');\n\n"
	. "COMMIT;\n"
	. "SET FOREIGN_KEY_CHECKS = 1;\n";

$salvocofre_minimal_path = salvocofre_test_stream_temp_file();

if ( ! is_wp_error( $salvocofre_minimal_path ) ) {
	salvocofre_assert( false !== file_put_contents( $salvocofre_minimal_path, $salvocofre_minimal ), 'the minimal dump is written to /tmp' );
}

$salvocofre_result = is_wp_error( $salvocofre_minimal_path )
	? $salvocofre_minimal_path
	: salvocofre_restore_database_from_file( $salvocofre_minimal_path );

salvocofre_assert( true === $salvocofre_result, 'the minimal dump is restored from a file' );

if ( is_wp_error( $salvocofre_result ) ) {
	printf( "  info %s: %s\n", $salvocofre_result->get_error_code(), $salvocofre_result->get_error_message() );
}

salvocofre_assert( salvocofre_test_stream_table_exists( $salvocofre_table ), 'the dump created the temporary table' );

$salvocofre_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT `id`, `note` FROM %i ORDER BY `id`', $salvocofre_table ), ARRAY_A );

salvocofre_assert( 3 === count( $salvocofre_rows ), 'the dump inserted exactly three rows' );
salvocofre_assert(
	array( 'alpha', 'beta', 'gamma' ) === array_column( (array) $salvocofre_rows, 'note' ),
	'the three values were restored in order'
);

unset( $salvocofre_rows );

echo "\nBlocked statement in a dump file\n";

$salvocofre_note_before = salvocofre_test_stream_note( $salvocofre_table, 1 );
$salvocofre_blocked     = "-- the only statement in this dump is forbidden\n"
	. 'UPDATE ' . $salvocofre_table_id . " SET `note` = 'tampered' WHERE `id` = 1;\n";

$salvocofre_blocked_path = salvocofre_test_stream_temp_file();

if ( ! is_wp_error( $salvocofre_blocked_path ) ) {
	salvocofre_assert( false !== file_put_contents( $salvocofre_blocked_path, $salvocofre_blocked ), 'the blocked dump is written to /tmp' );
}

$salvocofre_blocked_result = is_wp_error( $salvocofre_blocked_path )
	? $salvocofre_blocked_path
	: salvocofre_restore_database_from_file( $salvocofre_blocked_path );

salvocofre_assert( is_wp_error( $salvocofre_blocked_result ), 'a dump with UPDATE is rejected' );
salvocofre_assert(
	is_wp_error( $salvocofre_blocked_result ) && 'salvocofre_restore_statement_blocked' === $salvocofre_blocked_result->get_error_code(),
	'the rejection uses the salvocofre_restore_statement_blocked code'
);
salvocofre_assert(
	$salvocofre_note_before === salvocofre_test_stream_note( $salvocofre_table, 1 ),
	'the blocked UPDATE did not change the row'
);
salvocofre_assert(
	3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $salvocofre_table ) ),
	'the blocked dump did not run any other statement either'
);

unset( $salvocofre_note_before, $salvocofre_blocked_result );

echo "\n5 MB dump stays under 64 MB of memory\n";

salvocofre_test_stream_drop_table( $salvocofre_table );

$salvocofre_create = $wpdb->query(
	$wpdb->prepare(
		'CREATE TABLE %i (`id` bigint unsigned NOT NULL, `payload` varchar(255) NOT NULL, PRIMARY KEY (`id`))',
		$salvocofre_table
	)
);

salvocofre_assert( false !== $salvocofre_create, 'the bulk fixture table is created' );

$salvocofre_row_count = 20000;
$salvocofre_payload   = str_repeat( 'stream-payload-', 15 );
$salvocofre_filled    = salvocofre_test_stream_fill_table( $salvocofre_table, $salvocofre_row_count, $salvocofre_payload );

salvocofre_assert( true === $salvocofre_filled, 'the bulk fixture rows are inserted' );

if ( is_wp_error( $salvocofre_filled ) ) {
	printf( "  info %s: %s\n", $salvocofre_filled->get_error_code(), $salvocofre_filled->get_error_message() );
}

$salvocofre_bulk_path = salvocofre_test_stream_temp_file();

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}

$salvocofre_bulk_written = is_wp_error( $salvocofre_bulk_path )
	? $salvocofre_bulk_path
	: salvocofre_write_sql_dump( $salvocofre_bulk_path );
$salvocofre_dump_peak    = memory_get_peak_usage( true );

salvocofre_assert( ! is_wp_error( $salvocofre_bulk_written ), 'the 5 MB dump is written' );

$salvocofre_bulk_size = is_wp_error( $salvocofre_bulk_path ) ? 0 : (int) filesize( $salvocofre_bulk_path );

salvocofre_assert(
	$salvocofre_bulk_size >= 5 * 1024 * 1024,
	sprintf( 'the streamed dump is at least 5 MB (%s)', size_format( $salvocofre_bulk_size, 2 ) )
);
salvocofre_assert(
	$salvocofre_dump_peak < 64 * 1024 * 1024,
	sprintf( 'writing the dump peaks below 64 MB (%.1f MB)', $salvocofre_dump_peak / 1024 / 1024 )
);

if ( function_exists( 'memory_reset_peak_usage' ) ) {
	memory_reset_peak_usage();
}

$salvocofre_bulk_result = is_wp_error( $salvocofre_bulk_path )
	? $salvocofre_bulk_path
	: salvocofre_restore_database_from_file( $salvocofre_bulk_path );
$salvocofre_peak        = memory_get_peak_usage( true );

salvocofre_assert( true === $salvocofre_bulk_result, 'the 5 MB dump is restored from a file' );

if ( is_wp_error( $salvocofre_bulk_result ) ) {
	printf( "  info %s: %s\n", $salvocofre_bulk_result->get_error_code(), $salvocofre_bulk_result->get_error_message() );
}

salvocofre_assert(
	$salvocofre_row_count === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $salvocofre_table ) ),
	'all 20000 fixture rows are restored'
);
salvocofre_assert(
	$salvocofre_peak < 64 * 1024 * 1024,
	sprintf( 'restoring the dump peaks below 64 MB (%.1f MB)', $salvocofre_peak / 1024 / 1024 )
);

salvocofre_test_stream_cleanup();

salvocofre_assert( ! salvocofre_test_stream_table_exists( $salvocofre_table ), 'the temporary table is removed' );

unset(
	$salvocofre_table,
	$salvocofre_table_id,
	$salvocofre_dump_path,
	$salvocofre_written,
	$salvocofre_size,
	$salvocofre_stream,
	$salvocofre_tables,
	$salvocofre_one_table,
	$salvocofre_minimal,
	$salvocofre_minimal_path,
	$salvocofre_result,
	$salvocofre_blocked,
	$salvocofre_blocked_path,
	$salvocofre_create,
	$salvocofre_payload,
	$salvocofre_filled,
	$salvocofre_bulk_path,
	$salvocofre_bulk_written,
	$salvocofre_dump_peak,
	$salvocofre_bulk_size,
	$salvocofre_bulk_result,
	$salvocofre_peak,
	$salvocofre_function,
	$salvocofre_missing_functions
);

salvocofre_report();
