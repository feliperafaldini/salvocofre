<?php
/**
 * Status e-mails for backup runs.
 *
 * A failed run is always reported; a successful one only when the site owner
 * opted in through the "salvocofre_email_on_success" option.
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the status e-mail for one backup run.
 *
 * @param array|WP_Error $result   Value returned by salvocofre_create_backup().
 * @param float|null     $duration Seconds the run took; falls back to $result['duration'].
 * @return bool True when WordPress handed the message to the mailer.
 */
function salvocofre_send_status_email( $result, $duration = null ) {
	$failed = is_wp_error( $result );

	if ( ! $failed && ! get_option( 'salvocofre_email_on_success', false ) ) {
		return false;
	}

	$recipient = (string) get_option( 'admin_email' );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		salvocofre_status_email_log( 'no valid admin_email, status e-mail skipped' );

		return false;
	}

	/**
	 * Filters the recipient of the backup status e-mail.
	 *
	 * @param string         $recipient Administrator e-mail address.
	 * @param array|WP_Error $result    Backup result.
	 */
	$recipient = (string) apply_filters( 'salvocofre_status_email_recipient', $recipient, $result );

	if ( '' === $recipient || ! is_email( $recipient ) ) {
		salvocofre_status_email_log( 'the recipient filter returned an invalid address' );

		return false;
	}

	$subject = salvocofre_status_email_subject( $failed );
	$body    = salvocofre_status_email_body( $result, $duration );
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	$sent = wp_mail( $recipient, $subject, $body, $headers );

	if ( ! $sent ) {
		salvocofre_status_email_log( 'wp_mail() refused the status e-mail for ' . $recipient );
	}

	return (bool) $sent;
}

/**
 * Site name used in the subject line.
 *
 * @return string
 */
function salvocofre_status_email_site_name() {
	$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

	if ( '' !== $name ) {
		return $name;
	}

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	return ( '' !== $host ) ? $host : home_url();
}

/**
 * Builds the subject line.
 *
 * @param bool $failed Whether the run failed.
 * @return string
 */
function salvocofre_status_email_subject( $failed ) {
	return sprintf(
		/* translators: 1: site name, 2: backup status ("failed" or "completed"). */
		__( '[%1$s] Salvocofre Backup %2$s', 'salvocofre' ),
		salvocofre_status_email_site_name(),
		$failed ? __( 'failed', 'salvocofre' ) : __( 'completed', 'salvocofre' )
	);
}

/**
 * Builds the plain-text body.
 *
 * Sizes come from the result array; the duration is shown when the caller
 * measured it. A failed run lists the error code and message instead.
 *
 * @param array|WP_Error $result   Value returned by salvocofre_create_backup().
 * @param float|null     $duration Seconds the run took.
 * @return string
 */
function salvocofre_status_email_body( $result, $duration = null ) {
	if ( null === $duration && is_array( $result ) && isset( $result['duration'] ) ) {
		$duration = $result['duration'];
	}

	$lines   = array();
	$lines[] = sprintf(
		/* translators: %s: site URL. */
		__( 'Site: %s', 'salvocofre' ),
		home_url()
	);
	$lines[] = '';

	if ( is_wp_error( $result ) ) {
		$lines[] = __( 'Status: failed', 'salvocofre' );
		$lines[] = sprintf(
			/* translators: 1: error code, 2: error message. */
			__( 'Error: %1$s (%2$s)', 'salvocofre' ),
			$result->get_error_code(),
			$result->get_error_message()
		);

		// The pre-flight check normally leaves this in the option cache.
		if ( function_exists( 'salvocofre_estimate_database_size' ) ) {
			$estimated = salvocofre_estimate_database_size();

			if ( ! is_wp_error( $estimated ) ) {
				$lines[] = sprintf(
					/* translators: %s: estimated database size. */
					__( 'Database (estimated): %s', 'salvocofre' ),
					size_format( max( 0, (int) $estimated ), 1 )
				);
			}
		}
	} else {
		$lines[] = __( 'Status: completed', 'salvocofre' );

		if ( is_array( $result ) ) {
			if ( isset( $result['sql_bytes'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: database dump size. */
					__( 'Database dump: %s', 'salvocofre' ),
					size_format( max( 0, (int) $result['sql_bytes'] ), 1 )
				);
			}

			if ( isset( $result['zip_bytes'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: archive size. */
					__( 'Archive: %s', 'salvocofre' ),
					size_format( max( 0, (int) $result['zip_bytes'] ), 1 )
				);
			}

			if ( isset( $result['zip_path'] ) ) {
				$lines[] = sprintf(
					/* translators: %s: archive path. */
					__( 'Archive file: %s', 'salvocofre' ),
					(string) $result['zip_path']
				);
			}
		}
	}

	if ( null !== $duration ) {
		$lines[] = sprintf(
			/* translators: %s: duration in seconds. */
			__( 'Duration: %s seconds', 'salvocofre' ),
			number_format_i18n( max( 0, (float) $duration ), 1 )
		);
	}

	if ( is_wp_error( $result ) ) {
		$lines[] = '';
		$lines[] = __( 'Check the site log for more details.', 'salvocofre' );
	}

	return implode( "\n", $lines ) . "\n";
}

/**
 * Logs a delivery problem without turning it into a backup failure.
 *
 * @param string $message Message to log.
 * @return void
 */
function salvocofre_status_email_log( $message ) {
	if ( function_exists( 'salvocofre_log' ) ) {
		salvocofre_log( 'status email: ' . $message );
	}
}
