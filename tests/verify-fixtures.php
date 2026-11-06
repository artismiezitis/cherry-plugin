<?php
if ( PHP_SAPI !== 'cli' || ! getenv( 'CHERRY_TEST_WP_ROOT' ) ) exit( 1 );
require getenv( 'CHERRY_TEST_WP_ROOT' ) . '/wp-load.php';
function verify_cherry( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
$posts = get_posts( array( 'title' => 'Cherry fixture article', 'numberposts' => 1 ) );
verify_cherry( count( $posts ) === 1, 'Imported article missing' );
$post = $posts[0];
verify_cherry( has_category( 'cherry-category', $post ) && has_tag( 'cherry-tag', $post ), 'Imported terms missing' );
verify_cherry( get_comments( array( 'post_id' => $post->ID, 'count' => true ) ) > 0, 'Imported comments missing' );
$thumbnail = get_post_thumbnail_id( $post );
verify_cherry( $thumbnail && is_file( get_attached_file( $thumbnail ) ), 'Imported thumbnail missing' );
verify_cherry( ! empty( wp_get_attachment_metadata( $thumbnail )['sizes'] ), 'Image metadata missing' );
$menu = wp_get_nav_menu_object( 'Header Menu' );
verify_cherry( $menu && count( wp_get_nav_menu_items( $menu ) ) > 0, 'Imported menu missing' );
$widgets = get_option( 'widget_my-recent-comments' );
verify_cherry( ( $widgets[2]['title'] ?? '' ) === 'Test comments', 'Imported widget settings missing' );
verify_cherry( in_array( 'my-recent-comments-2', get_option( 'sidebars_widgets' )['sidebar-1'] ?? array() ), 'Imported sidebar assignment missing' );
verify_cherry( ( get_option( 'cherry_widget_responsive' )['my-recent-comments-2'] ?? '' ) === 'visible-all-devices', 'Imported responsive rule missing' );
verify_cherry( ( get_option( 'cherry_widget_custom_class' )['my-recent-comments-2'] ?? '' ) === 'cherry-fixture-widget', 'Imported custom class missing' );
echo "PASS: imported post, terms, comment, thumbnail, image metadata, menu and widget settings\n";
