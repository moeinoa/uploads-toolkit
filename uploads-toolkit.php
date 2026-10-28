<?php
/**
 * Plugin Name: Uploads Toolkit
 * Plugin URI: https://github.com/moeinoa/uploads-toolkit
 * GitHub Plugin URI: moeinoa/uploads-toolkit
 * Description: ممیزی، گروه‌بندی و پاکسازی امن پوشه‌ی uploads: فایل‌های بلااستفاده، نسخه‌های اضافه‌ی thumbnail، فایل‌های تکراری (هش)، سطل بازیافت و بازگردانی.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Text Domain: uploads-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UTK_VERSION', '1.0.0' );
define( 'UTK_DIR', __DIR__ );
define( 'UTK_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'util', 'store', 'scan', 'query', 'ops', 'admin', 'cli' ) as $utk_f ) {
	require_once UTK_DIR . '/inc/' . $utk_f . '.php';
}

UTK_Admin::init();
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	UTK_CLI::init();
}

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'utk_daily' );
} );
