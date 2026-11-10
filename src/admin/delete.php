<?php
/**
 * Handles backup deletion.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_salvocofre_delete_backup', 'salvocofre_handle_delete_backup' );

/**
 * Handles the delete form submission.
 *
 * @return void
 */
function salvocofre_handle_delete_backup() {
	if ( ! current_user_can( salvocofre_backup_capability() ) ) {
		wp_die( '', 403 );
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

	if ( 'POST' !== strtoupper( $method ) ) {
		wp_die( '', 405 );
	}

	$base = isset( $_POST['backup'] ) ? sanitize_key( wp_unslash( $_POST['backup'] ) ) : '';

	if ( ! salvocofre_is_valid_backup_name( $base ) ) {
		salvocofre_log( 'delete: invalid backup name' );
		salvocofre_redirect_after_delete( 'delete_failed', 'invalid_backup' );
	}

	// One nonce per set, so a stale link cannot delete a different backup.
	check_admin_referer( 'salvocofre_delete_backup_' . $base );

	$result = salvocofre_delete_backup( $base );

	if ( is_wp_error( $result ) ) {
		salvocofre_log( 'delete ' . $base . ': ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		salvocofre_redirect_after_delete( 'delete_failed', $result->get_error_code() );
	}

	salvocofre_redirect_after_delete( 'deleted' );
}

/**
 * Redirects back to the backups page with a notice and stops the request.
 *
 * @param string $notice Notice code understood by salvocofre_render_notices().
 * @param string $error  Optional internal error code, never displayed.
 * @return void
 */
function salvocofre_redirect_after_delete( $notice, $error = '' ) {
	$args = array( 'salvocofre_notice' => $notice );

	if ( '' !== $error ) {
		$args['salvocofre_error'] = $error;
	}

	wp_safe_redirect( salvocofre_backups_page_url( $args ) );
	exit;
}
