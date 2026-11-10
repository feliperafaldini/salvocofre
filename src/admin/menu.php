<?php
/**
 * Admin menu registration and asset loading.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'salvocofre_register_menu' );
add_action( 'admin_enqueue_scripts', 'salvocofre_enqueue_admin_assets' );

/**
 * Registers the top level backups page and remembers its hook suffix.
 *
 * @return void
 */
function salvocofre_register_menu() {
	$hook = add_menu_page(
		__( 'Salvocofre Backups', 'salvocofre' ),
		__( 'Salvocofre', 'salvocofre' ),
		salvocofre_backup_capability(),
		'salvocofre-backups',
		'salvocofre_render_backups_page',
		'dashicons-backup'
	);

	$GLOBALS['salvocofre_menu_hook'] = $hook;
}

/**
 * Loads the admin script on the backups screen only.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 * @return void
 */
function salvocofre_enqueue_admin_assets( $hook_suffix ) {
	if ( ! isset( $GLOBALS['salvocofre_menu_hook'] ) || $GLOBALS['salvocofre_menu_hook'] !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script(
		'salvocofre-admin',
		plugins_url( 'assets/admin.js', SALVOCOFRE_DIR . '/salvocofre.php' ),
		array(),
		SALVOCOFRE_VERSION,
		true
	);
}

/**
 * Builds a URL that points at the backups page.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function salvocofre_backups_page_url( $args = array() ) {
	return add_query_arg(
		array_merge( array( 'page' => 'salvocofre-backups' ), $args ),
		admin_url( 'admin.php' )
	);
}
