<?php
/**
 * Unit checks for salvocofre_create_backup() and salvocofre_create_zip().
 *
 * Run inside the WordPress container:
 *   php /var/www/html/wp-content/plugins/salvocofre/tests/test-zip.php
 *
 * @package Salvocofre
 */

require '/var/www/html/wp-load.php';
require __DIR__ . '/assert.php';

echo "salvocofre_create_backup()\n";

$result = salvocofre_create_backup();

salvocofre_assert( false === is_wp_error( $result ), 'returns no WP_Error' );

if ( is_wp_error( $result ) ) {
	echo '  -> ' . $result->get_error_message() . "\n";
	salvocofre_report();
}

salvocofre_assert( is_array( $result ), 'returns the result array' );
salvocofre_assert( file_exists( $result['sql_path'] ), 'SQL dump exists on disk' );
salvocofre_assert( file_exists( $result['zip_path'] ), 'ZIP archive exists on disk' );
salvocofre_assert( $result['sql_bytes'] > 1000, 'SQL dump is not empty (' . $result['sql_bytes'] . ' bytes)' );
salvocofre_assert( $result['zip_bytes'] > 1000, 'ZIP archive is not empty (' . $result['zip_bytes'] . ' bytes)' );

echo "\nsalvocofre_create_zip() contents\n";

$zip = new ZipArchive();

salvocofre_assert( true === $zip->open( $result['zip_path'], ZipArchive::CHECKCONS ), 'archive opens with a consistency check' );
salvocofre_assert( $zip->numFiles > 1000, 'archive holds the whole site (' . $zip->numFiles . ' entries)' );
$sql_name = basename( $result['sql_path'] );

salvocofre_assert( false !== $zip->locateName( $sql_name ), 'archive contains the SQL dump (' . $sql_name . ')' );
salvocofre_assert( false !== $zip->locateName( 'wp-config.php' ), 'archive contains wp-config.php' );
salvocofre_assert( false !== $zip->locateName( 'wp-includes/version.php' ), 'archive contains core files' );
salvocofre_assert( false !== $zip->locateName( 'wp-content/plugins/salvocofre/salvocofre.php' ), 'archive contains the plugin itself' );

$leaked = 0;

for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$name = $zip->getNameIndex( $i );

	if ( str_starts_with( $name, 'wp-content/salvocofre-backups' ) ) {
		$leaked++;
	}
}

salvocofre_assert( 0 === $leaked, 'no backup files inside the archive (found ' . $leaked . ')' );

$zip->close();

salvocofre_report();
