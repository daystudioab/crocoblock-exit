<?php
/**
 * Minimal WordPress-attrapp för integrationstester.
 *
 * Posts och postmeta ligger i en riktig SQLite-databas så att tilläggets
 * SQL-frågor körs på riktigt. Metafunktionerna efterliknar WordPress
 * slash-hantering (update/add_post_meta avslashar värdet).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

/* ---------- Databas ---------- */

class Test_WPDB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_title TEXT DEFAULT "", post_type TEXT, post_status TEXT, post_content TEXT DEFAULT "", post_author INTEGER DEFAULT 1)' );
		$this->pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	}

	protected function sql( $q ) {
		// MySQL → SQLite för de få skillnader tillägget träffar.
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", trim( $q ), $m ) ) {
			$like = str_replace( '\\_', '_', $m[1] );
			return "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '" . $like . "'";
		}
		return $q;
	}

	public function prepare( $q, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
			},
			$q
		);
	}

	public function esc_like( $t ) {
		return addcslashes( $t, '_%\\' );
	}

	public function get_col( $q ) {
		return $this->pdo->query( $this->sql( $q ) )->fetchAll( PDO::FETCH_COLUMN, 0 );
	}

	public function get_var( $q ) {
		$r = $this->pdo->query( $this->sql( $q ) )->fetch( PDO::FETCH_NUM );
		return $r ? $r[0] : null;
	}

	public function get_results( $q, $out = 'OBJECT' ) {
		$st = $this->pdo->query( $this->sql( $q ) );
		return 'ARRAY_A' === $out ? $st->fetchAll( PDO::FETCH_ASSOC ) : $st->fetchAll( PDO::FETCH_OBJ );
	}
}
$GLOBALS['wpdb'] = new Test_WPDB();

/* ---------- Grundfunktioner ---------- */

$GLOBALS['_opts']    = array();
$GLOBALS['_actions'] = array();
$GLOBALS['_terms']   = array();
$GLOBALS['_registered'] = array( 'pt' => array( 'post' => 1, 'page' => 1 ), 'tax' => array() );

function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_filters'][ $h ][] = $cb; }
function apply_filters( $h, $v ) { $args = array_slice( func_get_args(), 1 ); foreach ( $GLOBALS['_filters'][ $h ] ?? array() as $cb ) { $args[0] = call_user_func_array( $cb, $args ); } return $args[0]; }
function current_user_can( $c ) { return true; }
function wp_verify_nonce( $n, $a ) { return $n === wp_create_nonce( $a ); }
function nocache_headers() {}
function absint( $v ) { return abs( (int) $v ); }
function do_action( $h, ...$a ) { foreach ( $GLOBALS['_actions'][ $h ] ?? array() as $cb ) { call_user_func_array( $cb, $a ); } }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['_opts'] ) ? $GLOBALS['_opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['_opts'][ $k ] ); return true; }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function current_time( $t ) { return '2026-11-01 20:00:00'; }
function maybe_serialize( $v ) { return ( is_array( $v ) || is_object( $v ) ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) { if ( is_string( $v ) && preg_match( '/^(a|O|s|i|b|d):/', $v ) ) { $u = @unserialize( $v ); return false === $u && 'b:0;' !== $v ? $v : $u; } return $v; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function esc_sql( $s ) { return str_replace( "'", "''", $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s ); }
function wp_kses_post( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $s ) ); }
function sanitize_file_name( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://test.se/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://test.se/' . ltrim( $p, '/' ); }
function add_query_arg( $args, $url ) { return $url . ( strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function get_permalink( $id ) { return 'https://test.se/?p=' . $id; }
function clean_post_cache( $id ) {}
function get_bloginfo( $k ) { return '6.8'; }
function is_admin() { return false; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://test.se/wp-content/plugins/crocoblock-exit/'; }
function wp_get_attachment_url( $id ) { return 'https://test.se/uploads/' . $id . '.pdf'; }
function get_term( $id ) { return (object) array( 'term_id' => $id, 'term_taxonomy_id' => $id + 100 ); }
function wp_get_nav_menu_object( $m ) { return (object) array( 'slug' => 'meny-' . $m ); }
function post_type_exists( $s ) { return isset( $GLOBALS['_registered']['pt'][ $s ] ); }
function taxonomy_exists( $s ) { return isset( $GLOBALS['_registered']['tax'][ $s ] ); }
function register_post_type( $s, $a ) { $GLOBALS['_registered']['pt'][ $s ] = $a; }
function register_taxonomy( $s, $o, $a ) { $GLOBALS['_registered']['tax'][ $s ] = array( $o, $a ); }
function get_post_type_object( $t ) { return (object) array( 'labels' => (object) array( 'singular_name' => ucfirst( $t ) ) ); }
function is_plugin_active( $f ) { return in_array( $f, $GLOBALS['_active_plugins'] ?? array(), true ); }
function get_plugins() { return $GLOBALS['_plugins'] ?? array(); }
function wp_set_object_terms( $id, $t, $tax ) { $GLOBALS['_terms'][ $id ][ $tax ] = (array) $t; }
function deactivate_plugins( $l ) { $GLOBALS['_active_plugins'] = array_values( array_diff( $GLOBALS['_active_plugins'], (array) $l ) ); }
function flush_rewrite_rules() {}
function wp_date( $f, $t ) { return gmdate( $f, $t ); }

/* ---------- Poster ---------- */

class WP_Post {
	public $ID, $post_title, $post_type, $post_status, $post_content, $post_author, $post_parent = 0, $post_name = '';
	function __construct( $row ) { foreach ( (array) $row as $k => $v ) { $this->$k = $v; } $this->ID = (int) $this->ID; }
}

function get_post( $id ) {
	global $wpdb;
	$r = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM wp_posts WHERE ID = %d', $id ) );
	return $r ? new WP_Post( $r[0] ) : null;
}
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_posts( $a ) {
	global $wpdb;
	$st = "'" . implode( "','", (array) $a['post_status'] ) . "'";
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM wp_posts WHERE post_type = %s AND post_status IN ($st) ORDER BY ID", $a['post_type'] ) );
	return array_map( function ( $r ) { return new WP_Post( $r ); }, $rows );
}
function wp_insert_post( $a, $err = false ) {
	global $wpdb;
	$st = $wpdb->pdo->prepare( 'INSERT INTO wp_posts (post_title, post_type, post_status, post_content, post_author) VALUES (?,?,?,?,?)' );
	$st->execute( array( $a['post_title'] ?? '', $a['post_type'] ?? 'post', $a['post_status'] ?? 'publish', $a['post_content'] ?? '', $a['post_author'] ?? 1 ) );
	$id = (int) $wpdb->pdo->lastInsertId();
	foreach ( $a['meta_input'] ?? array() as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}
function wp_update_post( $a ) {
	global $wpdb;
	foreach ( array( 'post_status', 'post_type' ) as $col ) {
		if ( isset( $a[ $col ] ) ) {
			$wpdb->pdo->prepare( "UPDATE wp_posts SET $col = ? WHERE ID = ?" )->execute( array( $a[ $col ], $a['ID'] ) );
		}
	}
	return $a['ID'];
}
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function wp_delete_object_term_relationships( $id, $tax ) { unset( $GLOBALS['_terms'][ $id ][ $tax ] ); }
function wp_create_nonce( $a ) { return 'n' . substr( md5( $a ), 0, 9 ); }
function wp_trash_post( $id ) { wp_update_post( array( 'ID' => $id, 'post_status' => 'trash' ) ); return true; }

/* ---------- Postmeta (med WordPress slash-semantik) ---------- */

function get_post_meta( $id, $key, $single = false ) {
	global $wpdb;
	$st = $wpdb->pdo->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ? ORDER BY meta_id' );
	$st->execute( array( $id, $key ) );
	$vals = array_map( 'maybe_unserialize', $st->fetchAll( PDO::FETCH_COLUMN, 0 ) );
	return $single ? ( $vals ? $vals[0] : '' ) : $vals;
}
function add_post_meta( $id, $key, $val, $unique = false ) {
	global $wpdb;
	if ( $unique && '' !== get_post_meta( $id, $key, true ) ) {
		return false;
	}
	$wpdb->pdo->prepare( 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?,?,?)' )->execute( array( $id, $key, maybe_serialize( wp_unslash( $val ) ) ) );
	return true;
}
function update_post_meta( $id, $key, $val ) {
	global $wpdb;
	$wpdb->pdo->prepare( 'DELETE FROM wp_postmeta WHERE post_id = ? AND meta_key = ?' )->execute( array( $id, $key ) );
	return add_post_meta( $id, $key, $val );
}
function delete_post_meta( $id, $key ) {
	global $wpdb;
	$wpdb->pdo->prepare( 'DELETE FROM wp_postmeta WHERE post_id = ? AND meta_key = ?' )->execute( array( $id, $key ) );
	return true;
}

/* ---------- Filsystem, tema, övrigt ---------- */

$GLOBALS['_tmp'] = sys_get_temp_dir() . '/cbx-wp-' . getmypid();
foreach ( array( 'uploads/elementor/css', 'themes/m-tema', 'plugins/m-design/assets' ) as $d ) {
	@mkdir( $GLOBALS['_tmp'] . '/' . $d, 0777, true );
}
define( 'WP_PLUGIN_DIR', $GLOBALS['_tmp'] . '/plugins' );
register_shutdown_function( function () { exec( 'rm -rf ' . escapeshellarg( $GLOBALS['_tmp'] ) ); } );
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['_tmp'] . '/uploads', 'baseurl' => 'https://test.se/wp-content/uploads' ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function get_stylesheet_directory() { return $GLOBALS['_tmp'] . '/themes/m-tema'; }
function get_template_directory() { return $GLOBALS['_tmp'] . '/themes/m-tema'; }
function get_template() { return 'm-tema'; }
function wp_get_theme( $t = null ) { return new class { function get( $k ) { return 'M-tema'; } }; }
function wp_get_custom_css() { return $GLOBALS['_custom_css'] ?? ''; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function get_current_user_id() { return 1; }
function get_categories( $a = array() ) { return array( (object) array( 'term_id' => 4, 'count' => 9 ) ); }
function get_category_link( $id ) { return 'https://test.se/?cat=' . $id; }
function get_preview_post_link( $id ) { return 'https://test.se/?p=' . $id . '&preview=true'; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=x'; }
function wp_delete_post( $id, $force = false ) { global $wpdb; $wpdb->pdo->prepare( 'DELETE FROM wp_posts WHERE ID = ?' )->execute( array( $id ) ); $wpdb->pdo->prepare( 'DELETE FROM wp_postmeta WHERE post_id = ?' )->execute( array( $id ) ); return true; }

define( 'WP_CONTENT_DIR', $GLOBALS['_tmp'] );
function content_url() { return 'https://test.se/wp-content'; }
function get_stylesheet() { return 'm-tema'; }
function wp_remote_get( $u, $a = array() ) {
	$GLOBALS['_remote_log'][] = $u;
	foreach ( $GLOBALS['_remote_map'] ?? array() as $prefix => $res ) {
		if ( 0 === strpos( $u, $prefix ) ) {
			return $res;
		}
	}
	return $GLOBALS['_remote'] ?? new WP_Error( 'http', 'ingen loopback i test' );
}
function get_transient( $k ) { return get_option( '_transient_' . $k ); }
function set_transient( $k, $v, $t = 0 ) { return update_option( '_transient_' . $k, $v ); }
function delete_transient( $k ) { return delete_option( '_transient_' . $k ); }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function wpautop( $s ) { return '<p>' . str_replace( "\n\n", '</p><p>', $s ) . '</p>'; }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_update_custom_css_post( $css ) { $GLOBALS['_custom_css'] = $css; return true; }
function activate_plugin( $f ) { $GLOBALS['_active_plugins'][] = $f; return null; }
function activate_plugins( $l ) { foreach ( (array) $l as $f ) { activate_plugin( $f ); } return true; }

/* ---------- Ladda tillägget ---------- */

require dirname( __DIR__ ) . '/includes/class-cbx-mapper.php';
require dirname( __DIR__ ) . '/includes/class-cbx-css.php';
require dirname( __DIR__ ) . '/includes/class-cbx-plugin.php';
require dirname( __DIR__ ) . '/includes/class-cbx-scanner.php';
require dirname( __DIR__ ) . '/includes/class-cbx-converter.php';
require dirname( __DIR__ ) . '/includes/class-cbx-content-types.php';
require dirname( __DIR__ ) . '/includes/class-cbx-templates.php';
require dirname( __DIR__ ) . '/includes/class-cbx-styles.php';
require dirname( __DIR__ ) . '/includes/class-cbx-site-css.php';
require dirname( __DIR__ ) . '/includes/class-cbx-preview.php';
require dirname( __DIR__ ) . '/includes/class-cbx-queries.php';
require dirname( __DIR__ ) . '/includes/class-cbx-autopilot.php';
require dirname( __DIR__ ) . '/includes/class-cbx-stabilize.php';
require dirname( __DIR__ ) . '/includes/class-cbx-updater.php';
require dirname( __DIR__ ) . '/includes/class-cbx-csshero.php';
require dirname( __DIR__ ) . '/includes/class-cbx-admin.php';
define( 'CBX_FILE', dirname( __DIR__ ) . '/crocoblock-exit.php' );
define( 'CBX_URL', 'https://test.se/wp-content/plugins/crocoblock-exit/' );
// Samma version som tilläggets huvud, så att uppdateringskontrollen kan testas.
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', file_get_contents( CBX_FILE ), $cbx_v );
define( 'CBX_VERSION', $cbx_v[1] );
