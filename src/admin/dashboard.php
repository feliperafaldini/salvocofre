<?php
/**
 * Dashboard widget: a summary of the backups.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', 'salvocofre_add_dashboard_widgets' );

/**
 * Registers the dashboard widget for users allowed to run backups.
 *
 * @return void
 */
function salvocofre_add_dashboard_widgets() {
	if ( ! current_user_can( salvocofre_backup_capability() ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'salvocofre_dashboard',
		esc_html__( 'Salvocofre Backup', 'salvocofre' ),
		'salvocofre_dashboard_widget_render'
	);
}

/**
 * Renders the dashboard summary: latest backup, disk usage and links.
 *
 * @return void
 */
function salvocofre_dashboard_widget_render() {
	if ( ! current_user_can( salvocofre_backup_capability() ) ) {
		return;
	}

	$backups = salvocofre_get_backups();
	$latest  = empty( $backups ) ? null : reset( $backups );
	$count   = count( $backups );
	$total   = salvocofre_get_backups_total_size();
	?>
	<?php if ( null !== $latest ) : ?>
		<p>
			<?php
			printf(
				/* translators: 1: backup date, 2: backup size. */
				esc_html__( 'Last backup: %1$s (%2$s).', 'salvocofre' ),
				esc_html( salvocofre_format_time( (int) $latest['time'] ) ),
				esc_html( salvocofre_format_size( (int) $latest['size'] ) )
			);
			?>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'No backups yet.', 'salvocofre' ); ?></p>
	<?php endif; ?>

	<p>
		<?php
		printf(
			esc_html(
				/* translators: 1: number of backups, 2: total size on disk. */
				_n( '%1$s backup, %2$s on disk.', '%1$s backups, %2$s on disk.', $count, 'salvocofre' )
			),
			esc_html( number_format_i18n( $count ) ),
			esc_html( salvocofre_format_size( $total ) )
		);
		?>
	</p>

	<p>
		<a href="<?php echo esc_url( salvocofre_backups_page_url() ); ?>">
			<?php esc_html_e( 'View all backups', 'salvocofre' ); ?>
		</a>
	</p>

	<?php salvocofre_render_backup_button(); ?>
	<?php
}
