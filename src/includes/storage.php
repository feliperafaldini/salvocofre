<?php
/**
 * Storage layer: backup names, listing, deletion and retention.
 *
 * Every backup is a set of two files sharing a base name:
 *   salvocofre-YYYYMMDD-HHMMSS.zip  (site files + the SQL dump)
 *   salvocofre-YYYYMMDD-HHMMSS.sql  (database dump on its own)
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SALVOCOFRE_RETENTION_DEFAULT' ) ) {
	define( 'SALVOCOFRE_RETENTION_DEFAULT', 5 );
}

/**
 * Directory that holds the backups.
 *
 * @return string
 */
function salvocofre_backup_dir() {
	return (string) apply_filters( 'salvocofre_backup_dir', SALVOCOFRE_BACKUP_DIR );
}

/**
 * Shared WP_Filesystem instance rooted at the backup directory.
 *
 * @return WP_Filesystem_Base|false False when the host requires credentials.
 */
function salvocofre_fs() {
	static $filesystem = null;

	if ( null !== $filesystem ) {
		return $filesystem;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$filesystem = WP_Filesystem( false, salvocofre_backup_dir(), true ) ? $GLOBALS['wp_filesystem'] : false;

	return $filesystem;
}

/**
 * Validates a backup base name coming from a request.
 *
 * @param string $name Candidate name.
 * @return bool
 */
function salvocofre_is_valid_backup_name( $name ) {
	if ( ! is_string( $name ) || strlen( $name ) > 40 ) {
		return false;
	}

	return 1 === preg_match( '/^salvocofre-[0-9]{8}-[0-9]{6}(-[0-9]{1,2})?$/', $name );
}

/**
 * Builds the absolute path of one file of a backup set.
 *
 * @param string $base Backup base name.
 * @param string $ext  Either "zip" or "sql".
 * @return string|WP_Error
 */
function salvocofre_backup_path( $base, $ext ) {
	if ( ! salvocofre_is_valid_backup_name( $base ) || ! in_array( $ext, array( 'zip', 'sql' ), true ) ) {
		return new WP_Error( 'salvocofre_invalid_backup', __( 'Invalid backup name.', 'salvocofre' ) );
	}

	return salvocofre_backup_dir() . '/' . $base . '.' . $ext;
}

/**
 * Lists every backup set, newest first.
 *
 * @return array<string,array>
 */
function salvocofre_get_backups() {
	$filesystem = salvocofre_fs();
	$directory  = salvocofre_backup_dir();

	if ( ! $filesystem || ! $filesystem->is_dir( $directory ) ) {
		return array();
	}

	clearstatcache( true, $directory );

	$entries = $filesystem->dirlist( $directory, false );
	$sets    = array();

	if ( ! is_array( $entries ) ) {
		return array();
	}

	foreach ( $entries as $filename => $entry ) {
		if ( ! preg_match( '/^(salvocofre-[0-9]{8}-[0-9]{6}(?:-[0-9]{1,2})?)\.(zip|sql)$/', $filename, $matches ) ) {
			continue;
		}

		$base = $matches[1];
		$ext  = $matches[2];

		if ( ! isset( $sets[ $base ] ) ) {
			$sets[ $base ] = array(
				'base'     => $base,
				'time'     => 0,
				'size'     => 0,
				'complete' => false,
				'zip'      => null,
				'sql'      => null,
			);
		}

		$sets[ $base ][ $ext ] = array(
			'name'  => $filename,
			'size'  => (int) $entry['size'],
			'mtime' => (int) $entry['lastmodunix'],
		);

		$sets[ $base ]['size'] += (int) $entry['size'];
	}

	foreach ( $sets as $base => $set ) {
		$reference = $set['zip'] ? $set['zip'] : $set['sql'];

		$sets[ $base ]['time']     = (int) $reference['mtime'];
		$sets[ $base ]['complete'] = null !== $set['zip'] && $set['zip']['size'] > 0 && null !== $set['sql'];
	}

	uasort(
		$sets,
		static function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		}
	);

	return $sets;
}

/**
 * Returns one backup set.
 *
 * @param string $base Backup base name.
 * @return array|WP_Error
 */
function salvocofre_get_backup( $base ) {
	if ( ! salvocofre_is_valid_backup_name( $base ) ) {
		return new WP_Error( 'salvocofre_invalid_backup', __( 'Invalid backup name.', 'salvocofre' ) );
	}

	$backups = salvocofre_get_backups();

	if ( ! isset( $backups[ $base ] ) ) {
		return new WP_Error( 'salvocofre_backup_not_found', __( 'Backup not found.', 'salvocofre' ) );
	}

	return $backups[ $base ];
}

/**
 * Deletes both files of a backup set.
 *
 * @param string $base Backup base name.
 * @return true|WP_Error
 */
function salvocofre_delete_backup( $base ) {
	$backup = salvocofre_get_backup( $base );

	if ( is_wp_error( $backup ) ) {
		return $backup;
	}

	$filesystem = salvocofre_fs();

	if ( ! $filesystem ) {
		return new WP_Error( 'salvocofre_fs_unavailable', __( 'The filesystem is not writable by WordPress.', 'salvocofre' ) );
	}

	$failed = array();

	foreach ( array( 'zip', 'sql' ) as $ext ) {
		if ( empty( $backup[ $ext ] ) ) {
			continue;
		}

		$path = salvocofre_backup_path( $base, $ext );

		if ( is_wp_error( $path ) || ! $filesystem->delete( $path ) ) {
			$failed[] = $ext;
		}
	}

	if ( $failed ) {
		return new WP_Error(
			'salvocofre_delete_partial',
			sprintf(
				/* translators: %s: comma separated file types. */
				__( 'Could not delete: %s.', 'salvocofre' ),
				implode( ', ', $failed )
			)
		);
	}

	/**
	 * Fires after a backup set is deleted.
	 *
	 * @param string $base   Backup base name.
	 * @param array  $backup Files that belonged to the set.
	 */
	do_action( 'salvocofre_backup_deleted', $base, $backup );

	return true;
}

/**
 * Number of backups to keep (0 disables retention).
 *
 * @return int
 */
function salvocofre_get_retention_count() {
	$count = (int) apply_filters(
		'salvocofre_retention_count',
		(int) get_option( 'salvocofre_retention_count', SALVOCOFRE_RETENTION_DEFAULT )
	);

	if ( $count < 0 ) {
		return 0;
	}

	return min( $count, 50 );
}

/**
 * Deletes backup sets beyond the retention limit.
 *
 * @param string $keep_base Backup created by the current run, never deleted.
 * @return string[] Base names that were deleted.
 */
function salvocofre_apply_retention( $keep_base = '' ) {
	$limit = salvocofre_get_retention_count();

	if ( 0 === $limit ) {
		return array();
	}

	$deleted = array();
	$kept    = 0;

	foreach ( salvocofre_get_backups() as $base => $backup ) {
		if ( ! $backup['complete'] ) {
			continue;
		}

		$kept++;

		if ( $kept <= $limit || $base === $keep_base ) {
			continue;
		}

		$result = salvocofre_delete_backup( $base );

		if ( is_wp_error( $result ) ) {
			salvocofre_log( 'retention: ' . $base . ': ' . $result->get_error_message() );
			continue;
		}

		$deleted[] = $base;
	}

	if ( $deleted ) {
		/**
		 * Fires after retention removed old backup sets.
		 *
		 * @param string[] $deleted Base names that were removed.
		 */
		do_action( 'salvocofre_retention_deleted', $deleted );
	}

	return $deleted;
}

/**
 * Total size of every backup file on disk.
 *
 * @return int
 */
function salvocofre_get_backups_total_size() {
	$total = 0;

	foreach ( salvocofre_get_backups() as $backup ) {
		$total += (int) $backup['size'];
	}

	return $total;
}

/**
 * Writes a file atomically: temp file first, move into place last.
 *
 * @param string $path     Destination path.
 * @param string $contents File contents.
 * @return int|WP_Error Bytes written.
 */
function salvocofre_write_file_atomic( $path, $contents ) {
	$temp = $path . '.part';

	$bytes = file_put_contents( $temp, $contents );

	if ( false === $bytes ) {
		return new WP_Error(
			'salvocofre_write_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not write %s.', 'salvocofre' ),
				$temp
			)
		);
	}

	$filesystem = salvocofre_fs();

	if ( ! $filesystem ) {
		wp_delete_file( $temp );

		return new WP_Error(
			'salvocofre_fs_unavailable',
			__( 'The filesystem is not writable by WordPress.', 'salvocofre' )
		);
	}

	if ( ! $filesystem->move( $temp, $path, true ) ) {
		wp_delete_file( $temp );

		return new WP_Error(
			'salvocofre_rename_failed',
			sprintf(
				/* translators: %s: file path. */
				__( 'Could not move %s into place.', 'salvocofre' ),
				$path
			)
		);
	}

	return $bytes;
}
