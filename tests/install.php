<?php
/** Initial setup for tests/run.py; never run against an existing site. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'CHERRY_TEST_WP_ROOT' ) ) exit( 1 );
define( 'WP_INSTALLING', true );
require getenv( 'CHERRY_TEST_WP_ROOT' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( is_blog_installed() ) exit( 'Refusing to reinstall an existing site.' );
add_filter( 'pre_wp_mail', '__return_true' );
wp_install( 'Cherry compatibility', 'cherryadmin', 'cherry@example.test', true, '', 'Cherry-Disposable-Test-2026!' );
$result = activate_plugin( 'cherry-plugin/cherry-plugin.php' );
if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
echo 'Installed WordPress ' . $wp_version . "\n";
