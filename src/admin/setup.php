<?php
/**
 * Creates and protects the backup directory.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

register_activation_hook( dirname( __DIR__ ) . '/salvocofre.php', 'salvocofre_prepare_backup_dir' );
add_action( 'admin_init', 'salvocofre_admin_init' );

/**
 * Creates the backup directory and its protective files.
 *
 * @return true|WP_Error True when the directory is ready.
 */
function salvocofre_prepare_backup_dir() {
	if ( ! wp_mkdir_p( SALVOCOFRE_BACKUP_DIR ) && ! is_dir( SALVOCOFRE_BACKUP_DIR ) ) {
		return new WP_Error( 'salvocofre_mkdir_failed', SALVOCOFRE_BACKUP_DIR );
	}

	$htaccess = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	$files = array(
		'.htaccess' => $htaccess,
		'index.php' => "<?php\n// Silence is golden.\n",
	);

	foreach ( $files as $name => $contents ) {
		$path = SALVOCOFRE_BACKUP_DIR . '/' . $name;

		if ( is_readable( $path ) && file_get_contents( $path ) === $contents ) {
			continue;
		}

		if ( false === file_put_contents( $path, $contents ) ) {
			return new WP_Error( 'salvocofre_protect_failed', $path );
		}
	}

	return true;
}

/**
 * Keeps the directory ready on every admin request and warns when it is not.
 *
 * @return void
 */
function salvocofre_admin_init() {
	$result = salvocofre_prepare_backup_dir();

	if ( ! is_wp_error( $result ) ) {
		return;
	}

	salvocofre_log( $result->get_error_code() . ': ' . $result->get_error_message() );

	$message = sprintf(
		/* translators: %s: directory path. */
		__( 'Salvocofre Backup cannot use the backup directory %s. Backups will fail until this is fixed.', 'salvocofre' ),
		$result->get_error_message()
	);

	add_action(
		'admin_notices',
		static function () use ( $message ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);
}
