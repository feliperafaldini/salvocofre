<?php
/**
 * Plugin Name:       Salvocofre Backup
 * Description:       Creates a backup of the WordPress database and files as a single ZIP archive.
 * Version:           0.2.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Felipe Albino
 * Author URI:        https://profiles.wordpress.org/feliperafaldini/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       salvocofre
 * Domain Path:       /languages
 *
 * @package Salvocofre
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SALVOCOFRE_VERSION' ) ) {
	define( 'SALVOCOFRE_VERSION', '0.2.1' );
}

if ( ! defined( 'SALVOCOFRE_DIR' ) ) {
	define( 'SALVOCOFRE_DIR', __DIR__ );
}

if ( ! defined( 'SALVOCOFRE_BACKUP_DIR' ) ) {
	define( 'SALVOCOFRE_BACKUP_DIR', WP_CONTENT_DIR . '/salvocofre-backups' );
}

if ( ! defined( 'SALVOCOFRE_RETENTION_DEFAULT' ) ) {
	define( 'SALVOCOFRE_RETENTION_DEFAULT', 5 );
}

require_once SALVOCOFRE_DIR . '/includes/storage.php';
require_once SALVOCOFRE_DIR . '/includes/limits.php';
require_once SALVOCOFRE_DIR . '/includes/notifications.php';
require_once SALVOCOFRE_DIR . '/includes/restore.php';
require_once SALVOCOFRE_DIR . '/includes/cron.php';
require_once SALVOCOFRE_DIR . '/includes/dump.php';
require_once SALVOCOFRE_DIR . '/admin/setup.php';
require_once SALVOCOFRE_DIR . '/admin/format.php';
require_once SALVOCOFRE_DIR . '/admin/partials.php';
require_once SALVOCOFRE_DIR . '/admin/actions.php';
require_once SALVOCOFRE_DIR . '/admin/menu.php';
require_once SALVOCOFRE_DIR . '/admin/backups-page.php';
require_once SALVOCOFRE_DIR . '/admin/download.php';
require_once SALVOCOFRE_DIR . '/admin/delete.php';
require_once SALVOCOFRE_DIR . '/admin/dashboard.php';
require_once SALVOCOFRE_DIR . '/admin/restore.php';
require_once SALVOCOFRE_DIR . '/admin/settings.php';
