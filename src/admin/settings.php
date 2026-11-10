<?php
/**
 * Settings page: automatic backups, frequency and retention.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SALVOCOFRE_SETTINGS_GROUP' ) ) {
	define( 'SALVOCOFRE_SETTINGS_GROUP', 'salvocofre_settings' );
}

if ( ! defined( 'SALVOCOFRE_SETTINGS_PAGE' ) ) {
	define( 'SALVOCOFRE_SETTINGS_PAGE', 'salvocofre-settings' );
}

if ( ! defined( 'SALVOCOFRE_RETENTION_MIN' ) ) {
	define( 'SALVOCOFRE_RETENTION_MIN', 0 );
}

if ( ! defined( 'SALVOCOFRE_RETENTION_MAX' ) ) {
	define( 'SALVOCOFRE_RETENTION_MAX', 50 );
}

add_action( 'admin_menu', 'salvocofre_register_settings_page' );
add_action( 'admin_init', 'salvocofre_register_settings' );

/**
 * Adds the page under Settings.
 *
 * @return void
 */
function salvocofre_register_settings_page() {
	add_options_page(
		__( 'Salvocofre Backup', 'salvocofre' ),
		__( 'Salvocofre Backup', 'salvocofre' ),
		salvocofre_backup_capability(),
		SALVOCOFRE_SETTINGS_PAGE,
		'salvocofre_render_settings_page'
	);
}

/**
 * Registers the options, their sanitizers and the form sections.
 *
 * @return void
 */
function salvocofre_register_settings() {
	register_setting(
		SALVOCOFRE_SETTINGS_GROUP,
		'salvocofre_schedule_enabled',
		array(
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'salvocofre_sanitize_schedule_enabled',
		)
	);

	register_setting(
		SALVOCOFRE_SETTINGS_GROUP,
		'salvocofre_schedule_frequency',
		array(
			'type'              => 'string',
			'default'           => SALVOCOFRE_CRON_FREQUENCY_DEFAULT,
			'sanitize_callback' => 'salvocofre_sanitize_schedule_frequency',
		)
	);

	register_setting(
		SALVOCOFRE_SETTINGS_GROUP,
		'salvocofre_retention_count',
		array(
			'type'              => 'integer',
			'default'           => SALVOCOFRE_RETENTION_DEFAULT,
			'sanitize_callback' => 'salvocofre_sanitize_retention_count',
		)
	);

	add_settings_section(
		'salvocofre_schedule',
		__( 'Automatic backups', 'salvocofre' ),
		'salvocofre_render_schedule_section',
		SALVOCOFRE_SETTINGS_PAGE
	);

	add_settings_field(
		'salvocofre_schedule_enabled',
		__( 'Schedule', 'salvocofre' ),
		'salvocofre_render_schedule_enabled_field',
		SALVOCOFRE_SETTINGS_PAGE,
		'salvocofre_schedule',
		array( 'label_for' => 'salvocofre_schedule_enabled' )
	);

	add_settings_field(
		'salvocofre_schedule_frequency',
		__( 'Frequency', 'salvocofre' ),
		'salvocofre_render_frequency_field',
		SALVOCOFRE_SETTINGS_PAGE,
		'salvocofre_schedule',
		array( 'label_for' => 'salvocofre_schedule_frequency' )
	);

	add_settings_section(
		'salvocofre_storage',
		__( 'Storage', 'salvocofre' ),
		'salvocofre_render_storage_section',
		SALVOCOFRE_SETTINGS_PAGE
	);

	add_settings_field(
		'salvocofre_retention_count',
		__( 'Backups to keep', 'salvocofre' ),
		'salvocofre_render_retention_field',
		SALVOCOFRE_SETTINGS_PAGE,
		'salvocofre_storage',
		array( 'label_for' => 'salvocofre_retention_count' )
	);
}

/**
 * Sanitizes the "enable schedule" checkbox.
 *
 * @param mixed $value Raw submitted value.
 * @return bool
 */
function salvocofre_sanitize_schedule_enabled( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';

	return in_array( $value, array( '1', 'true', 'on', 'yes' ), true );
}

/**
 * Sanitizes the frequency, falling back to the default on an unknown value.
 *
 * @param mixed $value Raw submitted value.
 * @return string
 */
function salvocofre_sanitize_schedule_frequency( $value ) {
	$frequency = is_string( $value ) ? sanitize_key( $value ) : '';
	$known     = salvocofre_cron_frequencies();

	if ( ! isset( $known[ $frequency ] ) ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'salvocofre_schedule_frequency',
				'salvocofre_invalid_frequency',
				__( 'Unknown backup frequency. The daily schedule was kept.', 'salvocofre' ),
				'error'
			);
		}

		return SALVOCOFRE_CRON_FREQUENCY_DEFAULT;
	}

	return $frequency;
}

/**
 * Sanitizes the retention count, clamped to 0–50.
 *
 * @param mixed $value Raw submitted value.
 * @return int
 */
function salvocofre_sanitize_retention_count( $value ) {
	if ( ! is_numeric( $value ) ) {
		if ( function_exists( 'add_settings_error' ) ) {
			$message = __( 'The number of backups to keep must be a whole number. The previous value was kept.', 'salvocofre' );

			add_settings_error(
				'salvocofre_retention_count',
				'salvocofre_retention_invalid',
				$message,
				'error'
			);
		}

		return salvocofre_get_retention_count();
	}

	$count = (int) $value;

	if ( $count < SALVOCOFRE_RETENTION_MIN || $count > SALVOCOFRE_RETENTION_MAX ) {
		$clamped = min( SALVOCOFRE_RETENTION_MAX, max( SALVOCOFRE_RETENTION_MIN, $count ) );

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'salvocofre_retention_count',
				'salvocofre_retention_range',
				sprintf(
					/* translators: %d: number of backups that will be kept. */
					__( 'The number of backups to keep must be between 0 and 50. Using %d.', 'salvocofre' ),
					$clamped
				),
				'warning'
			);
		}

		return $clamped;
	}

	return $count;
}

/**
 * Renders the settings page.
 *
 * @return void
 */
function salvocofre_render_settings_page() {
	if ( ! current_user_can( salvocofre_backup_capability() ) ) {
		wp_die( esc_html__( 'You are not allowed to manage backup settings.', 'salvocofre' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Salvocofre Backup', 'salvocofre' ); ?></h1>
		<?php settings_errors(); ?>
		<?php salvocofre_render_cron_status(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( SALVOCOFRE_SETTINGS_GROUP ); ?>
			<?php do_settings_sections( SALVOCOFRE_SETTINGS_PAGE ); ?>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Explains how WP-Cron behaves.
 *
 * @return void
 */
function salvocofre_render_schedule_section() {
	$cron_note = __(
		'Scheduled backups rely on WP-Cron, which only runs when the site receives a visit.',
		'salvocofre'
	);
	$late_note = __(
		'A quiet site can run a backup late; the status below shows when the next run is due.',
		'salvocofre'
	);

	printf( '<p>%s %s</p>', esc_html( $cron_note ), esc_html( $late_note ) );
}

/**
 * Renders the schedule checkbox.
 *
 * @return void
 */
function salvocofre_render_schedule_enabled_field() {
	?>
	<input type="hidden" name="salvocofre_schedule_enabled" value="0" />
	<input
		type="checkbox"
		id="salvocofre_schedule_enabled"
		name="salvocofre_schedule_enabled"
		value="1"
		<?php checked( salvocofre_cron_enabled() ); ?>
	/>
	<p class="description">
		<?php esc_html_e( 'Run a backup automatically. Turning this off removes the scheduled event.', 'salvocofre' ); ?>
	</p>
	<?php
}

/**
 * Renders the frequency select.
 *
 * @return void
 */
function salvocofre_render_frequency_field() {
	$current     = salvocofre_cron_frequency();
	$frequencies = salvocofre_cron_frequencies();
	$description = __(
		'How often automatic backups run. Changing it never adds a second event.',
		'salvocofre'
	);
	?>
	<select id="salvocofre_schedule_frequency" name="salvocofre_schedule_frequency">
		<?php foreach ( $frequencies as $slug => $label ) : ?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<p class="description"><?php echo esc_html( $description ); ?></p>
	<?php
}

/**
 * Describes what retention does.
 *
 * @return void
 */
function salvocofre_render_storage_section() {
	$description = __(
		'Backups are stored in the site content directory and are not reachable from the web.',
		'salvocofre'
	);
	$retention   = __( 'The retention limit below is applied after every automatic backup.', 'salvocofre' );

	printf( '<p>%s %s</p>', esc_html( $description ), esc_html( $retention ) );
}

/**
 * Renders the retention input.
 *
 * @return void
 */
function salvocofre_render_retention_field() {
	?>
	<input
		type="number"
		id="salvocofre_retention_count"
		name="salvocofre_retention_count"
		value="<?php echo esc_attr( (string) salvocofre_get_retention_count() ); ?>"
		min="<?php echo esc_attr( (string) SALVOCOFRE_RETENTION_MIN ); ?>"
		max="<?php echo esc_attr( (string) SALVOCOFRE_RETENTION_MAX ); ?>"
		step="1"
		class="small-text"
	/>
	<p class="description">
		<?php esc_html_e( 'How many backup sets to keep. Use 0 to keep every backup.', 'salvocofre' ); ?>
	</p>
	<?php
}

/**
 * Shows the last run, the next run and any delay or misconfiguration.
 *
 * @return void
 */
function salvocofre_render_cron_status() {
	$status = salvocofre_cron_status();

	if ( $status['overdue'] ) {
		$message = sprintf(
			/* translators: %s: date and time the backup was due. */
			__( 'The scheduled backup is overdue. It was due on %s.', 'salvocofre' ),
			salvocofre_format_timestamp( $status['next_run'] )
		);
		$message .= ' ' . __( 'WP-Cron only runs with traffic, so a quiet site can be late.', 'salvocofre' );

		printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $message ) );
	} elseif ( $status['not_scheduled'] ) {
		$message  = __( 'Automatic backups are enabled, but no event is scheduled.', 'salvocofre' );
		$message .= ' ' . __( 'Save the settings to schedule it again.', 'salvocofre' );

		printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $message ) );
	}

	if ( $status['cron_disabled'] ) {
		$message  = __( 'DISABLE_WP_CRON is set, so WordPress does not start scheduled tasks.', 'salvocofre' );
		$message .= ' ' . __( 'Run wp-cron.php from a system cron job to keep backups on time.', 'salvocofre' );

		printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $message ) );
	}

	$next_run = __( 'Not scheduled', 'salvocofre' );

	if ( $status['next_run'] > 0 ) {
		$next_run = salvocofre_describe_next_run( $status['next_run'] );
	}

	$rows = array(
		array(
			__( 'Automatic backups', 'salvocofre' ),
			$status['enabled'] ? __( 'Enabled', 'salvocofre' ) : __( 'Disabled', 'salvocofre' ),
		),
		array(
			__( 'Last run', 'salvocofre' ),
			salvocofre_describe_last_run( $status['last_run'] ),
		),
		array(
			__( 'Last result', 'salvocofre' ),
			salvocofre_describe_last_result( $status['last_result'] ),
		),
		array(
			__( 'Next run', 'salvocofre' ),
			$next_run,
		),
	);

	echo '<table class="widefat striped"><tbody>';

	foreach ( $rows as $row ) {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $row[0] ),
			esc_html( $row[1] )
		);
	}

	echo '</tbody></table>';
}

/**
 * Formats a timestamp with the site date and time formats.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function salvocofre_format_timestamp( $timestamp ) {
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	return (string) wp_date( $format, (int) $timestamp );
}

/**
 * Describes the last run as a date plus a relative age.
 *
 * @param int $timestamp Unix timestamp, 0 when it never ran.
 * @return string
 */
function salvocofre_describe_last_run( $timestamp ) {
	if ( $timestamp <= 0 ) {
		return __( 'Never', 'salvocofre' );
	}

	return sprintf(
		/* translators: 1: date and time, 2: human readable age such as "5 mins". */
		__( '%1$s (%2$s ago)', 'salvocofre' ),
		salvocofre_format_timestamp( $timestamp ),
		human_time_diff( $timestamp )
	);
}

/**
 * Describes the next run as a date plus a relative wait.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function salvocofre_describe_next_run( $timestamp ) {
	if ( $timestamp < time() ) {
		return sprintf(
			/* translators: %s: date and time. */
			__( '%s (due now)', 'salvocofre' ),
			salvocofre_format_timestamp( $timestamp )
		);
	}

	return sprintf(
		/* translators: 1: date and time, 2: human readable wait such as "5 mins". */
		__( '%1$s (in %2$s)', 'salvocofre' ),
		salvocofre_format_timestamp( $timestamp ),
		human_time_diff( $timestamp )
	);
}

/**
 * Describes the stored outcome of the last scheduled run.
 *
 * @param array $result Normalised salvocofre_cron_last_result() value.
 * @return string
 */
function salvocofre_describe_last_result( $result ) {
	if ( 'ok' === $result['status'] ) {
		$text = __( 'Completed successfully.', 'salvocofre' );

		if ( $result['deleted'] > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of old backups removed by retention. */
				_n(
					'%d older backup was removed by retention.',
					'%d older backups were removed by retention.',
					$result['deleted'],
					'salvocofre'
				),
				$result['deleted']
			);
		}

		return $text;
	}

	if ( 'error' === $result['status'] ) {
		$text = __( 'Failed.', 'salvocofre' );

		if ( '' !== $result['code'] ) {
			$text .= ' ' . sprintf(
				/* translators: %s: error code. */
				__( 'Error code: %s.', 'salvocofre' ),
				$result['code']
			);
		}

		if ( '' !== $result['message'] ) {
			$text .= ' ' . $result['message'];
		}

		return $text;
	}

	return __( 'No automatic backup has run yet.', 'salvocofre' );
}
