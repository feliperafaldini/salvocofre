<?php
/**
 * Checks the extension points: lifecycle actions and dump/archive filters.
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/salvocofre/tests/test-hooks.php
 *
 * @package Salvocofre
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

global $wpdb;

$fired   = array(
	'started'   => array(),
	'completed' => array(),
	'failed'    => array(),
	'deleted'   => array(),
);
$created = array();

$listener_started = static function ( $base ) use ( &$fired ) {
	$fired['started'][] = $base;
};
$listener_completed = static function ( $result ) use ( &$fired ) {
	$fired['completed'][] = $result;
};
$listener_failed = static function ( $error, $base ) use ( &$fired ) {
	$fired['failed'][] = array( $error, $base );
};
$listener_deleted = static function ( $base ) use ( &$fired ) {
	$fired['deleted'][] = $base;
};

add_action( 'salvocofre_backup_started', $listener_started );
add_action( 'salvocofre_backup_completed', $listener_completed );
add_action( 'salvocofre_backup_failed', $listener_failed, 10, 2 );
add_action( 'salvocofre_backup_deleted', $listener_deleted );

$skip_includes = static function ( $paths, $root ) {
	$paths[] = rtrim( $root, '/' ) . '/wp-includes';

	return $paths;
};

add_filter( 'salvocofre_backup_excluded_paths', $skip_includes, 10, 2 );

register_shutdown_function(
	static function () use ( $listener_started, $listener_completed, $listener_failed, $listener_deleted, $skip_includes, &$created ) {
		remove_action( 'salvocofre_backup_started', $listener_started );
		remove_action( 'salvocofre_backup_completed', $listener_completed );
		remove_action( 'salvocofre_backup_failed', $listener_failed, 10 );
		remove_action( 'salvocofre_backup_deleted', $listener_deleted );
		remove_filter( 'salvocofre_backup_excluded_paths', $skip_includes, 10 );

		foreach ( $created as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				@unlink( $path );
			}
		}
	}
);

echo "lifecycle actions\n";

$invalid = salvocofre_create_backup( 'not a valid name!' );

salvocofre_assert( is_wp_error( $invalid ), 'an invalid base name fails' );
salvocofre_assert( 1 === count( $fired['failed'] ), 'salvocofre_backup_failed fired once' );
salvocofre_assert(
	isset( $fired['failed'][0][0] ) && is_wp_error( $fired['failed'][0][0] ),
	'the failure hook receives the WP_Error'
);
salvocofre_assert( 0 === count( $fired['completed'] ), 'no completion hook for a failed run' );
salvocofre_assert( 1 === count( $fired['started'] ), 'salvocofre_backup_started fired for the failed run' );

$result = salvocofre_create_backup();

salvocofre_assert( ! is_wp_error( $result ), 'a valid run succeeds' );

if ( is_wp_error( $result ) ) {
	echo '  -> ' . $result->get_error_message() . "\n";
	salvocofre_report();
}

$created[] = $result['sql_path'];
$created[] = $result['zip_path'];

salvocofre_assert( 2 === count( $fired['started'] ), 'salvocofre_backup_started fired again' );
salvocofre_assert( 1 === count( $fired['completed'] ), 'salvocofre_backup_completed fired once' );
salvocofre_assert(
	isset( $fired['completed'][0]['base'] ) && $result['base'] === $fired['completed'][0]['base'],
	'the completion hook receives the new set'
);

echo "\nsalvocofre_backup_excluded_paths filter\n";

$zip = new ZipArchive();

salvocofre_assert( true === $zip->open( $result['zip_path'] ), 'archive opens' );

$included_core = 0;

for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = (string) $zip->getNameIndex( $i );

	if ( str_starts_with( $name, 'wp-includes/' ) ) {
		$included_core++;
	}
}

salvocofre_assert( 0 === $included_core, 'the filtered directory is absent from the archive' );
salvocofre_assert( false !== $zip->locateName( 'wp-config.php' ), 'the rest of the site is still archived' );

$zip->close();

echo "\nsalvocofre_backup_deleted action\n";

// Retention also deletes sets and fires the same hook, so measure the delta.
$before_delete = count( $fired['deleted'] );
$deleted       = salvocofre_delete_backup( $result['base'] );

salvocofre_assert( true === $deleted, 'the set is deleted' );
salvocofre_assert(
	count( $fired['deleted'] ) === $before_delete + 1,
	'salvocofre_backup_deleted fired once for this deletion'
);
salvocofre_assert(
	$fired['deleted'] && end( $fired['deleted'] ) === $result['base'],
	'the deletion hook receives the base name'
);

$created = array();

echo "\nsalvocofre_dump_tables filter\n";

$drop_table = static function ( $tables ) use ( $wpdb ) {
	return array_values( array_diff( $tables, array( $wpdb->users ) ) );
};

add_filter( 'salvocofre_dump_tables', $drop_table );

$dump = salvocofre_build_sql_dump();

salvocofre_assert( is_string( $dump ), 'the in-memory dump still builds' );
salvocofre_assert( ! str_contains( $dump, 'CREATE TABLE `' . $wpdb->users . '`' ), 'the filtered table is excluded' );
salvocofre_assert( str_contains( $dump, 'CREATE TABLE `' . $wpdb->posts . '`' ), 'other tables are kept' );

$stream_path = wp_tempnam( 'salvocofre-hooks' );
$written     = salvocofre_write_sql_dump( $stream_path );
$stream      = is_int( $written ) ? (string) file_get_contents( $stream_path ) : '';

$created[] = $stream_path;

salvocofre_assert( is_int( $written ), 'the streaming dump writes the file' );
salvocofre_assert( '' !== $stream && ! str_contains( $stream, 'CREATE TABLE `' . $wpdb->users . '`' ), 'the streaming dump honours the same filter' );

remove_filter( 'salvocofre_dump_tables', $drop_table );

salvocofre_report();
