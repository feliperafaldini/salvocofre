<?php
/**
 * Unit checks for the storage layer: names, paths, listing, retention and atomic writes.
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/salvocofre/tests/test-storage.php
 *
 * @package Salvocofre
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

$GLOBALS['salvocofre_storage_test_files'] = array();
$GLOBALS['salvocofre_storage_test_dirs']  = array();

$salvocofre_original_retention = get_option( 'salvocofre_retention_count', '__salvocofre_missing__' );

$GLOBALS['salvocofre_storage_test_retention'] = array(
	'exists'   => '__salvocofre_missing__' !== $salvocofre_original_retention,
	'value'    => $salvocofre_original_retention,
	'restored' => false,
);

unset( $salvocofre_original_retention );

/**
 * Deletes every file created by the test.
 *
 * @return void
 */
function salvocofre_test_cleanup_files() {
	foreach ( (array) $GLOBALS['salvocofre_storage_test_files'] as $file ) {
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	$GLOBALS['salvocofre_storage_test_files'] = array();
}

/**
 * Deletes every temporary directory created by the test.
 *
 * @return void
 */
function salvocofre_test_cleanup_dirs() {
	foreach ( (array) $GLOBALS['salvocofre_storage_test_dirs'] as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}

		@rmdir( $dir );
	}

	$GLOBALS['salvocofre_storage_test_dirs'] = array();
}

/**
 * Puts the retention option back to the value it had before the test.
 *
 * @return void
 */
function salvocofre_test_restore_retention() {
	$state = $GLOBALS['salvocofre_storage_test_retention'];

	if ( ! empty( $state['restored'] ) ) {
		return;
	}

	if ( $state['exists'] ) {
		update_option( 'salvocofre_retention_count', $state['value'] );
	} else {
		delete_option( 'salvocofre_retention_count' );
	}

	$GLOBALS['salvocofre_storage_test_retention']['restored'] = true;
}

/**
 * Cleans up even when a test fails and exits early.
 *
 * @return void
 */
function salvocofre_test_shutdown() {
	salvocofre_test_cleanup_files();
	salvocofre_test_cleanup_dirs();
	salvocofre_test_restore_retention();
}

register_shutdown_function( 'salvocofre_test_shutdown' );

/**
 * Writes a file, sets its modification time and tracks it for cleanup.
 *
 * @param string $path     Absolute file path.
 * @param string $contents File contents.
 * @param int    $mtime    Modification time as a Unix timestamp.
 * @return true|WP_Error
 */
function salvocofre_test_write_file( $path, $contents, $mtime ) {
	if ( false === file_put_contents( $path, $contents ) ) {
		return new WP_Error( 'salvocofre_test_write_failed', 'Could not write ' . $path . '.' );
	}

	if ( ! touch( $path, $mtime ) ) {
		@unlink( $path );

		return new WP_Error( 'salvocofre_test_touch_failed', 'Could not set the modification time of ' . $path . '.' );
	}

	$GLOBALS['salvocofre_storage_test_files'][] = $path;

	clearstatcache( true, $path );

	return true;
}

/**
 * Creates both files of a backup set.
 *
 * @param string      $directory     Directory that holds the files.
 * @param string      $base          Backup base name.
 * @param int         $mtime         Modification time as a Unix timestamp.
 * @param string      $zip_contents  ZIP file contents.
 * @param string|null $sql_contents  SQL file contents, or null to leave the set incomplete.
 * @return true|WP_Error
 */
function salvocofre_test_create_set( $directory, $base, $mtime, $zip_contents, $sql_contents ) {
	$created = salvocofre_test_write_file( $directory . '/' . $base . '.zip', $zip_contents, $mtime );

	if ( is_wp_error( $created ) ) {
		return $created;
	}

	if ( null === $sql_contents ) {
		return true;
	}

	return salvocofre_test_write_file( $directory . '/' . $base . '.sql', $sql_contents, $mtime );
}

/**
 * Creates an isolated temporary directory and tracks it for cleanup.
 *
 * @return string|WP_Error
 */
function salvocofre_test_temp_dir() {
	$directory = rtrim( sys_get_temp_dir(), '/\\' ) . '/salvocofre-storage-' . getmypid() . '-' . uniqid();

	if ( ! mkdir( $directory, 0700 ) ) {
		return new WP_Error( 'salvocofre_test_mkdir_failed', 'Could not create ' . $directory . '.' );
	}

	$GLOBALS['salvocofre_storage_test_dirs'][] = $directory;

	return $directory;
}

echo "salvocofre_is_valid_backup_name()\n";

salvocofre_assert( salvocofre_is_valid_backup_name( 'salvocofre-20261024-153000' ), 'accepts a plain base name' );
salvocofre_assert( salvocofre_is_valid_backup_name( 'salvocofre-20261024-153000-2' ), 'accepts a duplicate suffix' );

$salvocofre_invalid_names = array(
	'../x'                                             => 'path traversal',
	'salvocofre-2026-153000'                             => 'a short date',
	''                                                 => 'an empty string',
	'/var/www/html/salvocofre-20261024-153000'           => 'an absolute path',
	'salvocofre-20261024-153000-' . str_repeat( '9', 20 ) => 'a name longer than 40 characters',
	'salvocofre-20261024-153000.zip'                     => 'a file extension',
);

foreach ( $salvocofre_invalid_names as $salvocofre_name => $salvocofre_reason ) {
	salvocofre_assert( ! salvocofre_is_valid_backup_name( $salvocofre_name ), 'rejects ' . $salvocofre_reason );
}

unset( $salvocofre_invalid_names, $salvocofre_name, $salvocofre_reason );

echo "\nsalvocofre_backup_path()\n";

$salvocofre_base      = 'salvocofre-20261024-153000';
$salvocofre_directory = rtrim( salvocofre_backup_dir(), '/\\' );

foreach ( array( 'zip', 'sql' ) as $salvocofre_extension ) {
	$salvocofre_path = salvocofre_backup_path( $salvocofre_base, $salvocofre_extension );

	salvocofre_assert( ! is_wp_error( $salvocofre_path ), 'accepts the ' . $salvocofre_extension . ' extension' );
	salvocofre_assert(
		is_string( $salvocofre_path ) && $salvocofre_directory . '/' . $salvocofre_base . '.' . $salvocofre_extension === $salvocofre_path,
		'builds the ' . $salvocofre_extension . ' path inside the backup directory'
	);
}

$salvocofre_invalid_path = salvocofre_backup_path( '../x', 'zip' );

salvocofre_assert( is_wp_error( $salvocofre_invalid_path ), 'returns WP_Error for an invalid name' );
salvocofre_assert(
	is_wp_error( $salvocofre_invalid_path ) && 'salvocofre_invalid_backup' === $salvocofre_invalid_path->get_error_code(),
	'uses the invalid backup error code'
);

foreach ( array( 'php', 'txt', 'zip.php', '' ) as $salvocofre_extension ) {
	$salvocofre_path = salvocofre_backup_path( $salvocofre_base, $salvocofre_extension );

	salvocofre_assert( is_wp_error( $salvocofre_path ), 'returns WP_Error for the "' . $salvocofre_extension . '" extension' );
}

unset( $salvocofre_base, $salvocofre_directory, $salvocofre_extension, $salvocofre_path, $salvocofre_invalid_path );

echo "\nsalvocofre_get_backups()\n";

$salvocofre_directory = rtrim( salvocofre_backup_dir(), '/\\' );

salvocofre_assert( is_dir( $salvocofre_directory ), 'backup directory exists' );
salvocofre_assert( false !== salvocofre_fs(), 'initializes the WordPress filesystem' );

$salvocofre_newest     = 'salvocofre-19990101-000004';
$salvocofre_middle     = 'salvocofre-19990101-000003';
$salvocofre_incomplete = 'salvocofre-19990101-000002';
$salvocofre_empty_zip  = 'salvocofre-19990101-000001';
$salvocofre_mtime      = 1000000000;

$salvocofre_sets = array(
	$salvocofre_newest     => array( str_repeat( 'z', 32 ), str_repeat( 's', 8 ), $salvocofre_mtime + 400 ),
	$salvocofre_middle     => array( str_repeat( 'z', 16 ), str_repeat( 's', 4 ), $salvocofre_mtime + 300 ),
	$salvocofre_incomplete => array( str_repeat( 'z', 12 ), null, $salvocofre_mtime + 200 ),
	$salvocofre_empty_zip  => array( '', str_repeat( 's', 3 ), $salvocofre_mtime + 100 ),
);

foreach ( $salvocofre_sets as $salvocofre_base => $salvocofre_set ) {
	list( $salvocofre_zip_contents, $salvocofre_sql_contents, $salvocofre_set_mtime ) = $salvocofre_set;

	$salvocofre_created = salvocofre_test_create_set(
		$salvocofre_directory,
		$salvocofre_base,
		$salvocofre_set_mtime,
		$salvocofre_zip_contents,
		$salvocofre_sql_contents
	);

	salvocofre_assert( ! is_wp_error( $salvocofre_created ), 'creates the files for ' . $salvocofre_base );
}

$salvocofre_backups = salvocofre_get_backups();

salvocofre_assert( is_array( $salvocofre_backups ), 'returns an array' );

foreach ( array_keys( $salvocofre_sets ) as $salvocofre_base ) {
	salvocofre_assert( isset( $salvocofre_backups[ $salvocofre_base ] ), 'lists the set ' . $salvocofre_base );
}

salvocofre_assert(
	$salvocofre_newest . '.zip' === $salvocofre_backups[ $salvocofre_newest ]['zip']['name'],
	'groups the zip file under its base name'
);
salvocofre_assert(
	$salvocofre_newest . '.sql' === $salvocofre_backups[ $salvocofre_newest ]['sql']['name'],
	'groups the sql file under its base name'
);
salvocofre_assert( true === $salvocofre_backups[ $salvocofre_newest ]['complete'], 'marks a set with zip and sql as complete' );
salvocofre_assert( true === $salvocofre_backups[ $salvocofre_middle ]['complete'], 'marks another complete set as complete' );
salvocofre_assert( false === $salvocofre_backups[ $salvocofre_incomplete ]['complete'], 'marks a set without sql as incomplete' );
salvocofre_assert( false === $salvocofre_backups[ $salvocofre_empty_zip ]['complete'], 'marks a set with an empty zip as incomplete' );

foreach ( $salvocofre_sets as $salvocofre_base => $salvocofre_set ) {
	$salvocofre_expected_size = strlen( $salvocofre_set[0] );

	if ( null !== $salvocofre_set[1] ) {
		$salvocofre_expected_size += strlen( $salvocofre_set[1] );
	}

	salvocofre_assert(
		$salvocofre_expected_size === $salvocofre_backups[ $salvocofre_base ]['size'],
		'sums the size of both files for ' . $salvocofre_base
	);
}

$salvocofre_expected_order = array_keys( $salvocofre_sets );
$salvocofre_actual_order   = array_values( array_intersect( array_keys( $salvocofre_backups ), $salvocofre_expected_order ) );

salvocofre_assert( $salvocofre_expected_order === $salvocofre_actual_order, 'lists the sets newest first' );

$salvocofre_previous   = null;
$salvocofre_descending = true;

foreach ( $salvocofre_backups as $salvocofre_backup ) {
	if ( null !== $salvocofre_previous && $salvocofre_backup['time'] > $salvocofre_previous ) {
		$salvocofre_descending = false;
	}

	$salvocofre_previous = (int) $salvocofre_backup['time'];
}

salvocofre_assert( $salvocofre_descending, 'keeps every listed set in descending time order' );

salvocofre_test_cleanup_files();

foreach ( array_keys( $salvocofre_sets ) as $salvocofre_base ) {
	salvocofre_assert(
		! file_exists( $salvocofre_directory . '/' . $salvocofre_base . '.zip' )
		&& ! file_exists( $salvocofre_directory . '/' . $salvocofre_base . '.sql' ),
		'removes the temporary files of ' . $salvocofre_base
	);
}

unset(
	$salvocofre_directory,
	$salvocofre_newest,
	$salvocofre_middle,
	$salvocofre_incomplete,
	$salvocofre_empty_zip,
	$salvocofre_mtime,
	$salvocofre_sets,
	$salvocofre_set,
	$salvocofre_base,
	$salvocofre_zip_contents,
	$salvocofre_sql_contents,
	$salvocofre_set_mtime,
	$salvocofre_created,
	$salvocofre_backups,
	$salvocofre_expected_size,
	$salvocofre_expected_order,
	$salvocofre_actual_order,
	$salvocofre_previous,
	$salvocofre_descending,
	$salvocofre_backup
);

echo "\nsalvocofre_apply_retention()\n";

$salvocofre_oldest     = 'salvocofre-19990201-000001';
$salvocofre_second     = 'salvocofre-19990201-000002';
$salvocofre_third      = 'salvocofre-19990201-000003';
$salvocofre_newest     = 'salvocofre-19990201-000004';
$salvocofre_mtime      = 1000001000;

$salvocofre_retention_dir = salvocofre_test_temp_dir();

salvocofre_assert( ! is_wp_error( $salvocofre_retention_dir ), 'creates a temporary backup directory' );

if ( ! is_wp_error( $salvocofre_retention_dir ) ) {
	$salvocofre_retention_filter = static function ( $directory ) use ( $salvocofre_retention_dir ) {
		return $salvocofre_retention_dir;
	};

	add_filter( 'salvocofre_backup_dir', $salvocofre_retention_filter );
	update_option( 'salvocofre_retention_count', 2 );

	foreach ( array( $salvocofre_oldest, $salvocofre_second, $salvocofre_third, $salvocofre_newest ) as $salvocofre_index => $salvocofre_base ) {
		$salvocofre_created = salvocofre_test_create_set(
			$salvocofre_retention_dir,
			$salvocofre_base,
			$salvocofre_mtime + ( 100 * $salvocofre_index ),
			'zip:' . $salvocofre_base,
			'sql:' . $salvocofre_base
		);

		salvocofre_assert( ! is_wp_error( $salvocofre_created ), 'creates the set ' . $salvocofre_base );
	}

	$salvocofre_deleted = salvocofre_apply_retention( $salvocofre_newest );

	salvocofre_assert( is_array( $salvocofre_deleted ), 'returns the deleted base names' );

	$salvocofre_expected_deleted = array( $salvocofre_oldest, $salvocofre_second );
	$salvocofre_actual_deleted   = is_array( $salvocofre_deleted ) ? $salvocofre_deleted : array();

	sort( $salvocofre_expected_deleted );
	sort( $salvocofre_actual_deleted );

	salvocofre_assert( $salvocofre_expected_deleted === $salvocofre_actual_deleted, 'deletes the two oldest sets' );
	salvocofre_assert( file_exists( $salvocofre_retention_dir . '/' . $salvocofre_newest . '.zip' ), 'keeps the keep_base set' );
	salvocofre_assert( ! file_exists( $salvocofre_retention_dir . '/' . $salvocofre_oldest . '.zip' ), 'removes the oldest zip file' );
	salvocofre_assert( ! file_exists( $salvocofre_retention_dir . '/' . $salvocofre_second . '.sql' ), 'removes the second oldest sql file' );

	remove_filter( 'salvocofre_backup_dir', $salvocofre_retention_filter );
	salvocofre_test_cleanup_files();
	salvocofre_test_cleanup_dirs();
}

$salvocofre_protected_dir = salvocofre_test_temp_dir();

salvocofre_assert( ! is_wp_error( $salvocofre_protected_dir ), 'creates a second temporary backup directory' );

if ( ! is_wp_error( $salvocofre_protected_dir ) ) {
	$salvocofre_protected_filter = static function ( $directory ) use ( $salvocofre_protected_dir ) {
		return $salvocofre_protected_dir;
	};

	add_filter( 'salvocofre_backup_dir', $salvocofre_protected_filter );
	update_option( 'salvocofre_retention_count', 1 );

	foreach ( array( $salvocofre_oldest, $salvocofre_second, $salvocofre_third, $salvocofre_newest ) as $salvocofre_index => $salvocofre_base ) {
		$salvocofre_created = salvocofre_test_create_set(
			$salvocofre_protected_dir,
			$salvocofre_base,
			$salvocofre_mtime + ( 100 * $salvocofre_index ),
			'zip:' . $salvocofre_base,
			'sql:' . $salvocofre_base
		);

		salvocofre_assert( ! is_wp_error( $salvocofre_created ), 'creates the protected set ' . $salvocofre_base );
	}

	$salvocofre_deleted = salvocofre_apply_retention( $salvocofre_oldest );

	$salvocofre_expected_deleted = array( $salvocofre_second, $salvocofre_third );
	$salvocofre_actual_deleted   = is_array( $salvocofre_deleted ) ? $salvocofre_deleted : array();

	sort( $salvocofre_expected_deleted );
	sort( $salvocofre_actual_deleted );

	salvocofre_assert( $salvocofre_expected_deleted === $salvocofre_actual_deleted, 'deletes the sets beyond the limit' );
	salvocofre_assert( file_exists( $salvocofre_protected_dir . '/' . $salvocofre_oldest . '.sql' ), 'never deletes the keep_base set' );

	remove_filter( 'salvocofre_backup_dir', $salvocofre_protected_filter );
	salvocofre_test_cleanup_files();
	salvocofre_test_cleanup_dirs();
}

salvocofre_test_restore_retention();

$salvocofre_restored = get_option( 'salvocofre_retention_count', '__salvocofre_missing__' );

salvocofre_assert(
	$GLOBALS['salvocofre_storage_test_retention']['value'] === $salvocofre_restored,
	'restores the original retention option'
);

unset(
	$salvocofre_oldest,
	$salvocofre_second,
	$salvocofre_third,
	$salvocofre_newest,
	$salvocofre_mtime,
	$salvocofre_retention_dir,
	$salvocofre_retention_filter,
	$salvocofre_protected_dir,
	$salvocofre_protected_filter,
	$salvocofre_index,
	$salvocofre_base,
	$salvocofre_created,
	$salvocofre_deleted,
	$salvocofre_expected_deleted,
	$salvocofre_actual_deleted,
	$salvocofre_restored
);

echo "\nsalvocofre_write_file_atomic()\n";

$salvocofre_directory = rtrim( salvocofre_backup_dir(), '/\\' );
$salvocofre_path      = $salvocofre_directory . '/salvocofre-write-atomic-' . getmypid() . '.tmp';
$salvocofre_contents  = "salvocofre atomic write\n";
$salvocofre_bytes     = salvocofre_write_file_atomic( $salvocofre_path, $salvocofre_contents );

salvocofre_assert( ! is_wp_error( $salvocofre_bytes ), 'returns no WP_Error' );
salvocofre_assert( is_int( $salvocofre_bytes ) && strlen( $salvocofre_contents ) === $salvocofre_bytes, 'returns the number of bytes written' );
salvocofre_assert( file_exists( $salvocofre_path ), 'writes the destination file' );
salvocofre_assert( $salvocofre_contents === file_get_contents( $salvocofre_path ), 'writes the exact contents' );
salvocofre_assert( ! file_exists( $salvocofre_path . '.part' ), 'leaves no .part file behind' );

$GLOBALS['salvocofre_storage_test_files'][] = $salvocofre_path;

salvocofre_test_cleanup_files();

salvocofre_assert( ! file_exists( $salvocofre_path ), 'removes the temporary file' );

unset( $salvocofre_directory, $salvocofre_path, $salvocofre_contents, $salvocofre_bytes );

salvocofre_report();
