<?php
/**
 * Minimal assertion helpers for the Salvocofre test scripts.
 *
 * @package Salvocofre
 */

$GLOBALS['salvocofre_failures'] = 0;
$GLOBALS['salvocofre_checks']   = 0;

/**
 * Records a boolean assertion.
 *
 * @param bool   $condition Result to check.
 * @param string $message   Description shown on failure.
 * @return void
 */
function salvocofre_assert( $condition, $message ) {
	$GLOBALS['salvocofre_checks']++;

	if ( $condition ) {
		echo "  ok   {$message}\n";
		return;
	}

	$GLOBALS['salvocofre_failures']++;
	echo "  FAIL {$message}\n";
}

/**
 * Prints the summary and exits with a non-zero status when something failed.
 *
 * @return void
 */
function salvocofre_report() {
	$checks   = $GLOBALS['salvocofre_checks'];
	$failures = $GLOBALS['salvocofre_failures'];

	printf( "\n%d checks, %d failures\n", $checks, $failures );
	exit( $failures > 0 ? 1 : 0 );
}
