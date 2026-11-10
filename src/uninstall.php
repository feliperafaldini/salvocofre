<?php
/**
 * Uninstall routine.
 *
 * @package Salvocofre
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$salvocofre_options = array(
	'salvocofre_retention_count',
	'salvocofre_delete_backups_on_uninstall',
	'salvocofre_schedule_enabled',
	'salvocofre_schedule_frequency',
	'salvocofre_last_run',
	'salvocofre_last_result',
	'salvocofre_db_size_cache',
	'salvocofre_email_on_success',
);

$salvocofre_remove_backups = (bool) get_option( 'salvocofre_delete_backups_on_uninstall', false );

foreach ( $salvocofre_options as $salvocofre_option ) {
	delete_option( $salvocofre_option );
}

delete_transient( 'salvocofre_cron_lock' );

// Backups are only removed when the site owner explicitly opted in.
if ( ! $salvocofre_remove_backups ) {
	return;
}

$salvocofre_backup_dir = WP_CONTENT_DIR . '/salvocofre-backups';

if ( ! is_dir( $salvocofre_backup_dir ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( WP_Filesystem( false, $salvocofre_backup_dir, true ) ) {
	global $wp_filesystem;

	$wp_filesystem->delete( $salvocofre_backup_dir, true );
}
