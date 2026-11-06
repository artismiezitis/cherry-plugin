<?php
/** Remove test records before importing their export, keeping the uploaded source image. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'CHERRY_TEST_WP_ROOT' ) ) exit( 1 );
require getenv( 'CHERRY_TEST_WP_ROOT' ) . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) exit( 'A disposable local site is required.' );
$upload = wp_upload_dir();
$path = $upload['path'] . '/cherry-fixture.png';
$image = file_get_contents( $path );
global $wpdb;
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title IN ('Cherry fixture article', 'Cherry compatibility showcase', 'Cherry test image')" );
foreach ( $ids as $id ) {
	if ( get_post_type( $id ) === 'attachment' ) wp_delete_attachment( $id, true );
	else wp_delete_post( $id, true );
}
file_put_contents( $path, $image );
$menu = wp_get_nav_menu_object( 'Header Menu' );
if ( $menu ) wp_delete_nav_menu( $menu );
foreach ( array( 'category' => 'cherry-category', 'post_tag' => 'cherry-tag' ) as $taxonomy => $slug ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) wp_delete_term( $term->term_id, $taxonomy );
}
delete_option( 'widget_my-recent-comments' );
update_option( 'sidebars_widgets', array( 'wp_inactive_widgets' => array(), 'array_version' => 3 ) );
echo "Fixture records removed; source image preserved.\n";
