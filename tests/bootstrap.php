<?php
/**
 * Test bootstrap: minimal WordPress stubs backed by globals, then the plugin
 * classes under test. The REST controller and admin page are exercised
 * against a real WordPress in the pre-release integration smoke, not here.
 */

define( 'ABSPATH', '/tmp/wp/' );

function cc_reset_wp() {
	$GLOBALS['cc_meta']    = array(); // post_id => key => value
	$GLOBALS['cc_options'] = array();
	$GLOBALS['cc_posts']   = array(); // post_id => ['title' => ..., 'url' => ...]
	$GLOBALS['cc_urls']    = array(); // url => post_id
	$GLOBALS['cc_deny']    = array(); // post_ids current user may NOT edit
	$GLOBALS['cc_saved']   = array(); // wp_update_post calls
	$GLOBALS['cc_home']    = 'https://example.com';
}
cc_reset_wp();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function __( $text, $domain = null ) { return $text; }

function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['cc_meta'][ $post_id ][ $key ] ) ? $GLOBALS['cc_meta'][ $post_id ][ $key ] : '';
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['cc_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['cc_meta'][ $post_id ][ $key ] );
	return true;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['cc_options'] ) ? $GLOBALS['cc_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['cc_options'][ $name ] = $value;
	return true;
}

function get_post( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ] ) ? (object) array( 'ID' => $post_id ) : null;
}
function get_the_title( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ]['title'] ) ? $GLOBALS['cc_posts'][ $post_id ]['title'] : '';
}
function get_permalink( $post_id ) {
	return isset( $GLOBALS['cc_posts'][ $post_id ]['url'] ) ? $GLOBALS['cc_posts'][ $post_id ]['url'] : '';
}
function wp_update_post( $args ) {
	$GLOBALS['cc_saved'][] = $args['ID'];
	return $args['ID'];
}

function home_url() { return $GLOBALS['cc_home']; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function url_to_postid( $url ) {
	return isset( $GLOBALS['cc_urls'][ $url ] ) ? $GLOBALS['cc_urls'][ $url ] : 0;
}

function current_user_can( $cap, $post_id = null ) {
	if ( 'edit_post' === $cap ) {
		return ! in_array( (int) $post_id, $GLOBALS['cc_deny'], true );
	}
	return true;
}

function sanitize_text_field( $str ) {
	$str = strip_tags( (string) $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
}

function apply_filters( $tag, $value ) { return $value; }

/** Test helper: register a post with a resolvable URL. */
function cc_add_post( $post_id, $url, $title = 'A post' ) {
	$GLOBALS['cc_posts'][ $post_id ] = array( 'title' => $title, 'url' => $url );
	$GLOBALS['cc_urls'][ $url ]      = $post_id;
}

require __DIR__ . '/../includes/class-ccc-adapter.php';
require __DIR__ . '/../includes/class-ccc-change-log.php';
require __DIR__ . '/../includes/class-ccc-service.php';
