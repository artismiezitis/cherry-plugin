<?php
/** Run only against a disposable WordPress installation. See README.md. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'CHERRY_TEST_WP_ROOT' ) ) {
	exit( "Set CHERRY_TEST_WP_ROOT to a disposable WordPress installation.\n" );
}
$mode = $argv[1] ?? 'front';
if ( $mode === 'admin' ) {
	define( 'WP_ADMIN', true );
	// A separate importer plugin may own the WordPress importer class names.
	class WXR_Parser {}
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '8097';
$_SERVER['REQUEST_URI'] = '/';
$errors = array();
set_error_handler( function ( $severity, $message, $file, $line ) use ( &$errors ) {
	if ( error_reporting() & $severity ) {
		$errors[] = "$message at $file:$line";
	}
	return true;
} );
require rtrim( getenv( 'CHERRY_TEST_WP_ROOT' ), '/\\' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( $mode === 'admin' ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
}
$checks = 0;
$failures = array();
function cherry_test( $label, $callback ) {
	global $checks, $failures, $errors;
	$checks++;
	try {
		$callback();
	} catch ( Throwable $e ) {
		$failures[] = "$label: " . $e->getMessage();
	}
	foreach ( $errors as $error ) {
		$failures[] = "$label: $error";
	}
	$errors = array();
}
function cherry_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
cherry_test( 'plugin bootstrap', function () {
	cherry_assert( defined( 'CHERRY_PLUGIN_VERSION' ), 'Plugin did not load' );
	cherry_assert( shortcode_exists( 'posts_grid' ), 'Shortcodes missing in this context' );
	global $wp_widget_factory;
	cherry_assert( count( array_filter( array_keys( $wp_widget_factory->widgets ), function ( $class ) {
		return preg_match( '/^(MY_|My_|Cherry_|Ad_125)/', $class );
	} ) ) === 13, 'Expected 13 Cherry widgets' );
} );
wp_set_current_user( 1 );
register_post_type( 'team', array( 'public' => true ) );
register_post_type( 'testi', array( 'public' => true ) );
$post_id = wp_insert_post( array( 'post_title' => 'Cherry compatibility post', 'post_content' => 'One two three four five.', 'post_status' => 'publish' ) );
$page_id = wp_insert_post( array( 'post_title' => 'Cherry compatibility page', 'post_type' => 'page', 'post_status' => 'publish' ) );
$GLOBALS['post'] = get_post( $post_id );
setup_postdata( $GLOBALS['post'] );
$registered = array_filter( $shortcode_tags, function ( $callback ) {
	if ( ! is_string( $callback ) ) return false;
	return strpos( ( new ReflectionFunction( $callback ) )->getFileName(), 'cherry-plugin' ) !== false;
} );
foreach ( $registered as $tag => $callback ) {
	cherry_test( "shortcode [$tag] defaults", function () use ( $tag ) {
		do_shortcode( "[$tag]" );
	} );
	cherry_test( "shortcode [$tag] content", function () use ( $tag ) {
		do_shortcode( "[$tag title=\"Example\"]Example content[/$tag]" );
	} );
}
cherry_test( 'nested table', function () {
	$html = do_shortcode( '[table td1="Name" td2="Value"][td1]One[/td1][td2]Two[/td2][/table]' );
	cherry_assert( strpos( $html, '<th>Name</th>' ) !== false, 'Table heading missing' );
	cherry_assert( strpos( $html, 'Two' ) !== false, 'Table data missing' );
} );
global $wp_widget_factory;
foreach ( $wp_widget_factory->widgets as $class => $widget ) {
	if ( ! preg_match( '/^(MY_|My_|Cherry_|Ad_125)/', $class ) ) continue;
	$widget->_set( 2 );
	cherry_test( "$class empty form", function () use ( $widget ) {
		ob_start();
		try { $widget->form( array() ); } finally { ob_end_clean(); }
	} );
	cherry_test( "$class empty display", function () use ( $widget ) {
		ob_start();
		try {
			$widget->widget( array( 'before_widget' => '<aside>', 'after_widget' => '</aside>', 'before_title' => '<h2>', 'after_title' => '</h2>', 'widget_id' => $widget->id ), array() );
		} finally { ob_end_clean(); }
	} );
	cherry_test( "$class save form and display", function () use ( $widget ) {
		ob_start();
		$widget->form( array() );
		$html = ob_get_clean();
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$fields = array();
		foreach ( array( 'input', 'textarea', 'select' ) as $tag ) {
			foreach ( $document->getElementsByTagName( $tag ) as $element ) {
				if ( ! preg_match( '/\[2\]\[([^]]+)\]$/', $element->getAttribute( 'name' ), $match ) ) continue;
				if ( $element->getAttribute( 'type' ) === 'checkbox' && ! $element->hasAttribute( 'checked' ) ) continue;
				$value = $element->getAttribute( 'value' );
				if ( $tag === 'textarea' ) $value = $element->textContent;
				if ( $tag === 'select' ) {
					$options = $element->getElementsByTagName( 'option' );
					$value = $options->length ? $options->item( 0 )->getAttribute( 'value' ) : '';
					foreach ( $options as $option ) if ( $option->hasAttribute( 'selected' ) ) $value = $option->getAttribute( 'value' );
				}
				$fields[$match[1]] = $value;
			}
		}
		$instance = $widget->update( $fields, array() );
		// Browsers omit unchecked checkboxes from the submitted instance.
		foreach ( $document->getElementsByTagName( 'input' ) as $element ) {
			if ( $element->getAttribute( 'type' ) === 'checkbox' && preg_match( '/\[2\]\[([^]]+)\]$/', $element->getAttribute( 'name' ), $match ) ) unset( $fields[$match[1]] );
		}
		$widget->update( $fields, $instance );
		cherry_assert( is_array( $instance ), 'Widget save failed' );
		ob_start();
		try { $widget->widget( array( 'before_widget' => '<aside>', 'after_widget' => '</aside>', 'before_title' => '<h2>', 'after_title' => '</h2>', 'widget_id' => $widget->id ), $instance ); }
		finally { ob_end_clean(); }
	} );
}
cherry_test( 'widget rules', function () use ( $post_id, $page_id ) {
	global $wp_query, $wp_registered_widgets;
	$wp_query = new WP_Query( array( 'p' => $post_id ) );
	cherry_assert( cherry_widget_condition_matches( 'include', ',post:' . $post_id, 'all' ), 'Included post hidden' );
	cherry_assert( ! cherry_widget_condition_matches( 'exclude', ',post:' . $post_id, 'all' ), 'Excluded post visible' );
	cherry_assert( ! cherry_widget_condition_matches( 'include', ',page:' . $page_id, 'all' ), 'Wrong page visible' );
	cherry_assert( cherry_widget_condition_matches( 'all', ',unknown', 'administrator' ), 'Admin rule failed' );
	wp_set_current_user( 0 );
	cherry_assert( cherry_widget_condition_matches( 'all', '', 'guests' ), 'Guest rule failed' );
	cherry_assert( ! cherry_widget_condition_matches( 'all', '', 'registered' ), 'Member rule failed' );
	cherry_assert( ! cherry_widget_condition_matches( 'include', ",post:');throw new Exception();//", 'all' ), 'Invalid rule matched' );
	$wp_registered_widgets['cherry-test'] = array();
	cherry_assert( check_widget_visibility( 'cherry-test' ), 'Widget with no rules hidden' );
	wp_set_current_user( 1 );
} );
if ( $mode === 'admin' ) {
	// Remote service availability is outside the compatibility test. Exercise offline behavior.
	add_filter( 'pre_http_request', function () { return new WP_Error( 'offline', 'Test offline response' ); } );
	cherry_test( 'admin hooks', function () {
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		do_action( 'admin_init' );
		do_action( 'admin_menu' );
	} );
	foreach ( array( 'cherry_plugin_main_page', 'cherry_plugin_import_page', 'cherry_plugin_export_page', 'cherry_maintenance_mode_admin_page', 'cherry_plugin_shortcodes_page' ) as $page ) {
		cherry_test( $page, function () use ( $page ) {
			ob_start();
			try { $page(); } finally { ob_end_clean(); }
		} );
	}
	cherry_test( 'XML export and import parsers', function () use ( $post_id ) {
		$xml_file = cherry_plugin_export_xml();
		cherry_assert( is_file( $xml_file ), 'XML export missing' );
		$xml = file_get_contents( $xml_file );
		foreach ( array( 'Cherry_WXR_Parser_SimpleXML', 'Cherry_WXR_Parser_XML', 'Cherry_WXR_Parser_Regex' ) as $class ) {
			$parser = new $class();
			$data = $parser->parse( $class === 'Cherry_WXR_Parser_Regex' ? $xml_file : $xml );
			cherry_assert( ! is_wp_error( $data ), "$class failed" );
			cherry_assert( in_array( $post_id, array_column( $data['posts'], 'post_id' ) ), "$class lost exported post" );
		}
		$data = cherry_plugin_parse_xml( $xml_file );
		cherry_assert( ! is_wp_error( $data ), 'Import XML preparation failed' );
		cherry_assert( $data['site_settings']['blogname'] === get_option( 'blogname' ), 'Site title lost' );
		unlink( $xml_file );
	} );
	cherry_test( 'widget JSON export', function () {
		$file = cherry_plugin_export_json();
		$data = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		cherry_assert( count( $data ) === 3, 'Widget export format changed' );
		unlink( $file );
	} );
	cherry_test( 'invalid import files', function () {
		$file = wp_tempnam();
		foreach ( array( '', '<rss>broken', '<rss><channel/></rss>' ) as $content ) {
			file_put_contents( $file, $content );
			cherry_assert( is_wp_error( cherry_plugin_parse_xml( $file ) ), 'Invalid XML accepted' );
		}
		unlink( $file );
		cherry_assert( is_wp_error( cherry_plugin_parse_xml( $file ) ), 'Missing XML accepted' );
	} );
	cherry_test( 'updater empty transient', function () {
		cherry_assert( cherry_plugin_check_for_update( false ) === false, 'Invalid transient changed' );
	} );
}
cherry_test( 'local image resizing', function () {
	$uploads = wp_upload_dir();
	$file = $uploads['path'] . '/cherry-test.png';
	$im = imagecreatetruecolor( 160, 100 );
	imagepng( $im, $file );
	$url = $uploads['url'] . '/cherry-test.png';
	$resized = aq_resize( $url, 80, 50, true, false );
	cherry_assert( is_array( $resized ) && $resized[1] === 80 && $resized[2] === 50, 'Image resize failed' );
	cherry_assert( aq_resize( $url, 160, 100, true ) === $url, 'Exact size image failed' );
	cherry_assert( aq_resize( $url, 320, 200, true ) === $url, 'Oversized image fallback failed' );
	cherry_assert( aq_resize( 'https://example.test/image.png', 80, 50 ) === false, 'Remote image accepted' );
	cherry_assert( aq_resize( 'https://example.test/image.png', 80, 50, true, true, true ) === false, 'Remote upscale accepted' );
	cherry_assert( has_filter( 'image_resize_dimensions', 'aq_upscale' ) === false, 'Upscale filter leaked' );
	array_map( 'unlink', glob( $uploads['path'] . '/cherry-test*.png' ) );
} );
wp_delete_post( $post_id, true );
wp_delete_post( $page_id, true );
foreach ( $errors as $error ) $failures[] = $error;
echo json_encode( array( 'php' => PHP_VERSION, 'wordpress' => $wp_version, 'mode' => $mode, 'checks' => $checks, 'failures' => $failures ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $failures ? 1 : 0 );
