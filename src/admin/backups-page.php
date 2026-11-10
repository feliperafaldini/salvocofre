<?php
/**
 * Backups screen: list, empty state and notices.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the backups page.
 *
 * @return void
 */
function salvocofre_render_backups_page() {
	if ( ! current_user_can( salvocofre_backup_capability() ) ) {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to access this page.', 'salvocofre' ),
			'',
			array( 'response' => 403 )
		);
	}

	$filesystem = salvocofre_fs();
	$backups    = $filesystem ? salvocofre_get_backups() : array();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Backups', 'salvocofre' ); ?></h1>
		<?php
		if ( ! empty( $backups ) ) {
			salvocofre_render_backup_button();
		}
		?>
		<hr class="wp-header-end" />

		<?php salvocofre_render_notices(); ?>

		<?php if ( ! $filesystem ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The backup directory is not accessible to WordPress. Check the file permissions and the site log.', 'salvocofre' ); ?></p>
			</div>
		<?php elseif ( empty( $backups ) ) : ?>
			<p><?php esc_html_e( 'No backups yet.', 'salvocofre' ); ?></p>
			<p><?php esc_html_e( 'Create a backup of your database and files to get started.', 'salvocofre' ); ?></p>
			<?php salvocofre_render_backup_button(); ?>
		<?php else : ?>
			<?php salvocofre_render_backups_table( $backups ); ?>
			<?php salvocofre_render_backups_footer( $backups ); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Renders the fixed notices that follow a redirect.
 *
 * Only known codes are translated and printed; error messages from the
 * filesystem never reach the screen.
 *
 * @return void
 */
function salvocofre_render_notices() {
	$notice = isset( $_GET['salvocofre_notice'] ) ? sanitize_key( wp_unslash( $_GET['salvocofre_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code; it changes no state.

	$messages = array(
		'created'       => array( 'success', __( 'Backup created.', 'salvocofre' ) ),
		'deleted'       => array( 'success', __( 'Backup deleted.', 'salvocofre' ) ),
		'delete_failed' => array( 'error', __( 'The backup could not be deleted completely. Check the site log for details.', 'salvocofre' ) ),
		'error'         => array( 'error', __( 'The backup could not be created. Check the site log for details.', 'salvocofre' ) ),
		'busy'          => array( 'warning', __( 'Another backup is already running. Wait for it to finish and try again.', 'salvocofre' ) ),
	);

	// Known failures explain themselves; anything else falls back to the generic text.
	$error_code = isset( $_GET['salvocofre_error'] ) ? sanitize_key( wp_unslash( $_GET['salvocofre_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error code; it changes no state.

	$error_messages = array(
		'salvocofre_limit_memory'  => __( 'The backup was not created: the database is too large for the available PHP memory. Raise memory_limit or back up from a server with more memory.', 'salvocofre' ),
		'salvocofre_limit_disk'    => __( 'The backup was not created: there is not enough free disk space for the archive.', 'salvocofre' ),
		'salvocofre_limit_time'    => __( 'The backup was not created: the expected run time exceeds the PHP execution limit. Raise max_execution_time or run the backup from WP-Cron.', 'salvocofre' ),
		'salvocofre_no_ziparchive' => __( 'The backup was not created: the PHP zip extension is not available on this server.', 'salvocofre' ),
		'salvocofre_fs_unavailable' => __( 'The backup was not created: WordPress cannot write to the backup directory.', 'salvocofre' ),
		'salvocofre_write_failed'  => __( 'The backup was not created: the database dump could not be written to disk.', 'salvocofre' ),
		'salvocofre_zip_partial'   => __( 'The archive was written but some files could not be added. Check the site log for the list.', 'salvocofre' ),
		'salvocofre_no_tables'     => __( 'The backup was not created: no database tables were found.', 'salvocofre' ),
	);

	if ( 'error' === $notice && isset( $error_messages[ $error_code ] ) ) {
		$messages['error'][1] = $error_messages[ $error_code ];
	}

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $messages[ $notice ][0] ),
		esc_html( $messages[ $notice ][1] )
	);
}

/**
 * Renders the table of backup sets.
 *
 * @param array $backups Backup sets as returned by salvocofre_get_backups().
 * @return void
 */
function salvocofre_render_backups_table( $backups ) {
	?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'salvocofre' ); ?></th>
				<th scope="col" class="column-files"><?php esc_html_e( 'Files', 'salvocofre' ); ?></th>
				<th scope="col" class="column-size"><?php esc_html_e( 'Size', 'salvocofre' ); ?></th>
				<th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'salvocofre' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $backups as $backup ) : ?>
				<tr>
					<td>
						<?php echo esc_html( salvocofre_format_time( (int) $backup['time'] ) ); ?>
					</td>
					<td>
						<?php if ( ! empty( $backup['zip'] ) ) : ?>
							<code><?php echo esc_html( $backup['zip']['name'] ); ?></code><br />
						<?php endif; ?>
						<?php if ( ! empty( $backup['sql'] ) ) : ?>
							<code><?php echo esc_html( $backup['sql']['name'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! $backup['complete'] ) : ?>
							<p>
								<strong><?php esc_html_e( 'Incomplete', 'salvocofre' ); ?></strong><br />
								<span class="description"><?php esc_html_e( 'One file of this set is missing. It is kept until you delete it.', 'salvocofre' ); ?></span>
							</p>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( salvocofre_format_size( (int) $backup['size'] ) ); ?>
					</td>
					<td>
						<?php salvocofre_render_row_actions( $backup ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Renders the count and the total size under the table.
 *
 * @param array $backups Backup sets as returned by salvocofre_get_backups().
 * @return void
 */
function salvocofre_render_backups_footer( $backups ) {
	$count = count( $backups );
	$size  = salvocofre_get_backups_total_size();
	?>
	<p class="description">
		<?php
		printf(
			esc_html(
				/* translators: 1: number of backups, 2: total size on disk. */
				_n( '%1$s backup, %2$s on disk.', '%1$s backups, %2$s on disk.', $count, 'salvocofre' )
			),
			esc_html( number_format_i18n( $count ) ),
			esc_html( salvocofre_format_size( $size ) )
		);
		?>
	</p>
	<?php
}
