<?php
/**
 * Integrationstest av hela flödet mot WordPress-attrappen.
 * Kör: php tests/integration.php
 */
require __DIR__ . '/wp-harness.php';

define( 'ELEMENTOR_VERSION', '3.30.0' );
define( 'ELEMENTOR_PRO_VERSION', '3.30.0' );

$fails = 0;
$count = 0;
function ok( $label, $cond ) {
	global $fails, $count;
	$count++;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL: $label\n";
	}
}
function el_widget( $id, $type, $s = array() ) {
	return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $s, 'elements' => array() );
}
function el_section( $widgets, $s = array() ) {
	return array( array( 'id' => 's' . substr( md5( serialize( $widgets ) ), 0, 6 ), 'elType' => 'section', 'settings' => $s, 'elements' => array( array( 'id' => 'c1', 'elType' => 'column', 'settings' => array(), 'elements' => $widgets ) ) ) );
}
function make_post( $type, $title, $status, $data = null, $meta = array(), $content = '' ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'post_content' => $content ) );
	if ( null !== $data ) {
		// Så sparar Elementor: slashad JSON.
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	}
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}

$GLOBALS['_plugins'] = array(
	'jet-elements/jet-elements.php'     => array( 'Name' => 'JetElements For Elementor', 'Version' => '2.6', 'Author' => 'Crocoblock' ),
	'jet-engine/jet-engine.php'         => array( 'Name' => 'JetEngine', 'Version' => '3.4', 'Author' => 'Crocoblock' ),
	'jet-popup/jet-popup.php'           => array( 'Name' => 'JetPopup', 'Version' => '2.0', 'Author' => 'Crocoblock' ),
	'elementor/elementor.php'           => array( 'Name' => 'Elementor', 'Version' => '3.30', 'Author' => 'Elementor.com' ),
	'crocoblock-exit/crocoblock-exit.php' => array( 'Name' => 'Crocoblock-avveckling', 'Version' => '1.0', 'Author' => 'Studio Day' ),
);
$GLOBALS['_active_plugins'] = array( 'jet-elements/jet-elements.php', 'jet-engine/jet-engine.php', 'jet-popup/jet-popup.php', 'elementor/elementor.php', 'crocoblock-exit/crocoblock-exit.php' );

/* ---------- Bygg en sajt ---------- */

// Text med citattecken och snedstreck – testar slash-hanteringen.
$tricky = 'Hon sa "hej" och skrev C:\\mapp\\fil – å ä ö';

$start = make_post( 'page', 'Startsida', 'publish', el_section( array(
	el_widget( 'w1', 'posts', array( 'classic_columns' => '2' ) ),
	el_widget( 'w2', 'jet-posts', array( 'number' => 6 ) ),
	el_widget( 'w3', 'text-editor', array( 'editor' => '<p>' . $tricky . '</p>' ) ),
	el_widget( 'w4', 'button', array( 'text' => 'Läs', 'jet_attached_popup' => '0' ) ),
), array( 'jet_sticky_section' => 'yes' ) ) );

$listing = make_post( 'jet-engine', 'Evenemangskort', 'publish', el_section( array(
	el_widget( 'l1', 'jet-listing-dynamic-image', array() ),
	el_widget( 'l2', 'jet-listing-dynamic-field', array() ),
	el_widget( 'l3', 'jet-listing-dynamic-field', array( 'dynamic_field_source' => 'meta', 'dynamic_field_post_meta' => 'plats' ) ),
	el_widget( 'l4', 'jet-listing-dynamic-link', array( 'link_label' => 'Läs mer' ) ),
) ), array( '_listing_data' => array( 'source' => 'posts', 'post_type' => 'evenemang', 'tax' => '' ), '_listing_type' => 'elementor', '_elementor_page_settings' => array( 'listing_source' => 'posts', 'listing_post_type' => 'evenemang' ) ) );

$popup = make_post( 'jet-popup', 'Nyhetsbrev', 'publish', el_section( array( el_widget( 'p1', 'heading', array( 'title' => 'Prenumerera' ) ) ) ), array( '_elementor_page_settings' => array( 'jet_popup_open_trigger' => 'page-load', 'jet_popup_page_load_delay' => 3, 'background_color' => '#fff' ) ) );

$events = make_post( 'page', 'Evenemang', 'publish', el_section( array(
	el_widget( 'e1', 'jet-listing-grid', array( 'lisitng_id' => (string) $listing, 'posts_num' => 9, 'columns' => '3' ) ),
	el_widget( 'e2', 'jet-smart-filters-select', array( 'filter_id' => 12 ) ),
	el_widget( 'e3', 'button', array( 'text' => 'Nyhetsbrev', 'jet_attached_popup' => (string) $popup, 'jet_trigger_type' => 'click-self' ) ),
) ) );

$header = make_post( 'jet-theme-core', 'Sidhuvud', 'publish', el_section( array(
	el_widget( 'h1', 'jet-logo', array( 'logo_type' => 'image', 'logo_image' => array( 'id' => 5, 'url' => 'https://test.se/logo.png' ) ) ),
	el_widget( 'h2', 'jet-nav-menu', array( 'nav_menu' => '3' ) ),
	el_widget( 'h3', 'jet-search', array() ),
) ), array( '_elementor_template_type' => 'jet_header', '_elementor_page_settings' => array( 'conditions_top' => 'entire' ) ) );
$single = make_post( 'jet-theme-core', 'Artikel', 'publish', el_section( array( el_widget( 'x1', 'heading', array( 'title' => 'x' ) ) ) ), array( '_elementor_template_type' => 'jet_single' ) );
update_option( 'jet_site_conditions', array(
	'jet_header' => array( $header => array( 'main' => 'entire' ) ),
	'jet_single' => array( $single => array( 'main' => 'singular', 'singular-post-from-cat' => array( 'cats' => array( '4', '7' ) ) ) ),
) );

$draft   = make_post( 'page', 'Utkast', 'draft', el_section( array( el_widget( 'd1', 'jet-headline', array( 'first_part' => 'A', 'second_part' => 'B' ) ) ) ) );
$plain   = make_post( 'page', 'Om oss', 'publish', el_section( array( el_widget( 'o1', 'heading', array( 'title' => 'Om' ) ) ) ) );
$classic = make_post( 'post', 'Gammal artikel', 'publish', null, array(), 'Text [jet_engine component="meta_field" field="pris"] mer' );
$revision = make_post( 'revision', 'rev', 'inherit', el_section( array( el_widget( 'r1', 'jet-posts' ) ) ) );
$trashed  = make_post( 'page', 'Slängd', 'trash', el_section( array( el_widget( 't1', 'jet-posts' ) ) ) );

// JetEngine-tabeller.
$wpdb = $GLOBALS['wpdb'];
$wpdb->pdo->exec( 'CREATE TABLE wp_jet_post_types (id INTEGER PRIMARY KEY, slug TEXT, status TEXT, labels TEXT, args TEXT, meta_fields TEXT)' );
$wpdb->pdo->exec( 'CREATE TABLE wp_jet_taxonomies (id INTEGER PRIMARY KEY, slug TEXT, object_type TEXT, status TEXT, labels TEXT, args TEXT, meta_fields TEXT)' );
$wpdb->pdo->prepare( 'INSERT INTO wp_jet_post_types (slug,status,labels,args,meta_fields) VALUES (?,?,?,?,?)' )->execute( array(
	'evenemang', 'publish',
	serialize( array( 'name' => 'Evenemang', 'singular_name' => 'Evenemang' ) ),
	serialize( array( 'public' => true, 'show_ui' => 'true', 'has_archive' => 'true', 'rewrite_slug' => 'evenemang', 'supports' => array( 'title', 'editor', 'thumbnail' ), 'menu_position' => '20', 'admin_columns' => array( 'x' ), 'show_edit_link' => true, 'menu_icon' => 'dashicons-calendar' ) ),
	serialize( array( array( 'title' => 'Plats', 'name' => 'plats', 'type' => 'text', 'object_type' => 'field' ), array( 'title' => 'Datum', 'name' => 'datum', 'type' => 'date', 'is_timestamp' => true, 'object_type' => 'field' ), array( 'title' => 'Flik', 'name' => 'tab1', 'type' => '', 'object_type' => 'tab' ) ) ),
) );
$wpdb->pdo->prepare( 'INSERT INTO wp_jet_taxonomies (slug,object_type,status,labels,args,meta_fields) VALUES (?,?,?,?,?,?)' )->execute( array(
	'evenemangstyp', serialize( array( 'evenemang' ) ), 'publish', serialize( array( 'name' => 'Typer' ) ), serialize( array( 'hierarchical' => 'true', 'show_in_rest' => true ) ), '',
) );
make_post( 'evenemang', 'Konsert', 'publish' );

// Elementors CSS-fil för startsidan, så som Crocoblock genererat den.
file_put_contents( wp_upload_dir()['basedir'] . '/elementor/css/post-' . $start . '.css', '.elementor-' . $start . ' .elementor-element.elementor-element-w2 .jet-posts .entry-title a{color:#272727}.elementor-' . $start . ' .elementor-element.elementor-element-w2 .jet-posts .entry-title{font-family:Publico;font-size:22px}@media(max-width:767px){.elementor-' . $start . ' .elementor-element.elementor-element-w2 .jet-posts .entry-title{font-size:18px}}.elementor-' . $start . ' .elementor-element.elementor-element-w2 .jet-carousel .jet-slick-dots{gap:4px}' );
// Sajtens egen CSS mot Crocoblocks klasser: i ett eget tillägg och under Anpassa.
$GLOBALS['_active_plugins'][] = 'm-design/m-design.php';
update_option( 'active_plugins', $GLOBALS['_active_plugins'] );
file_put_contents( WP_PLUGIN_DIR . '/m-design/assets/sidhuvud.css', '.jet-mega-menu-list > .menu-cta .jet-mega-menu-item__link{border-radius:50px;background:#9BFEFF}.vanlig{color:red}' );
$GLOBALS['_custom_css'] = '.jet-posts .entry-excerpt{color:#333}';

/* ---------- 1. Inventering ---------- */

$report = CBX_Scanner::run_all();
$t      = CBX_Scanner::totals( $report );
ok( 'scan finds jet-posts', isset( $report['widgets']['jet-posts'] ) && 1 === $report['widgets']['jet-posts']['count'] );
ok( 'scan ignores revision/trash', ! in_array( $revision, $report['widgets']['jet-posts']['posts'], true ) && ! in_array( $trashed, $report['widgets']['jet-posts']['posts'], true ) );
ok( 'scan listing widgets in jet-engine post', isset( $report['widgets']['jet-listing-dynamic-field'] ) && 2 === $report['widgets']['jet-listing-dynamic-field']['count'] );
ok( 'scan listing grid manual before migration', 1 === $report['widgets']['jet-listing-grid']['manual'] );
ok( 'scan filters manual', 1 === $report['widgets']['jet-smart-filters-select']['manual'] );
ok( 'scan sticky ext', isset( $report['extensions']['Sticky sektion (JetBlocks)'] ) );
ok( 'scan popup ext', isset( $report['extensions']['Popup-koppling (JetPopup)'] ) && 1 === $report['extensions']['Popup-koppling (JetPopup)']['count'] );
ok( 'scan shortcode in classic post', isset( $report['shortcodes']['jet_engine'] ) && in_array( $classic, $report['shortcodes']['jet_engine']['posts'], true ) );
ok( 'scan plugins', 3 === count( array_filter( $report['plugins'], function ( $p ) { return $p['active']; } ) ) );
ok( 'scan templates', 1 === count( $report['templates']['listings'] ) && 1 === count( $report['templates']['popups'] ) && 2 === count( $report['templates']['theme'] ) );
ok( 'scan content types', isset( $report['content']['post_types']['evenemang'] ) && 1 === $report['content']['post_counts']['evenemang'] );
ok( 'plain page not in report', ! isset( $report['posts'][ $plain ] ) );
ok( 'csv rows', count( CBX_Scanner::csv_rows( $report ) ) > 10 );
$html = CBX_Admin::render_report( $report );
ok( 'report html', false !== strpos( $html, 'jet-listing-grid' ) && false !== strpos( $html, 'kräver handpåläggning' ) );

/* ---------- 2. Innehållstyper ---------- */

$snap = CBX_Content_Types::snapshot();
ok( 'snapshot ok', ! is_wp_error( $snap ) );
$args = $snap['post_types']['evenemang']['args'];
ok( 'pt bools', true === $args['show_ui'] && true === $args['has_archive'] && true === $args['public'] );
ok( 'pt rewrite', array( 'slug' => 'evenemang', 'with_front' => true ) === $args['rewrite'] );
ok( 'pt no junk', ! isset( $args['admin_columns'] ) && ! isset( $args['show_edit_link'] ) && ! isset( $args['rewrite_slug'] ) );
ok( 'pt menu position int', 20 === $args['menu_position'] );
ok( 'pt labels', 'Evenemang' === $args['labels']['name'] );
ok( 'meta fields skip tabs', 2 === count( $snap['post_types']['evenemang']['meta_fields'] ) );
ok( 'tax', array( 'evenemang' ) === $snap['taxonomies']['evenemangstyp']['object_type'] && true === $snap['taxonomies']['evenemangstyp']['args']['hierarchical'] );

// JetEngine "aktivt": inläggstypen finns redan → vi registrerar inget.
$GLOBALS['_registered']['pt']['evenemang'] = 'jet';
CBX_Content_Types::register();
ok( 'no double register', 'jet' === $GLOBALS['_registered']['pt']['evenemang'] );
// JetEngine avaktiverat: vi tar över.
unset( $GLOBALS['_registered']['pt']['evenemang'] );
CBX_Content_Types::register();
ok( 'takes over registration', is_array( $GLOBALS['_registered']['pt']['evenemang'] ) && isset( $GLOBALS['_registered']['tax']['evenemangstyp'] ) );

$php = CBX_Content_Types::php_export();
$tmp = sys_get_temp_dir() . '/cbx-export-' . getmypid() . '.php';
file_put_contents( $tmp, $php );
exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $rc );
ok( 'php export lints', 0 === $rc );
ok( 'php export content', false !== strpos( $php, "register_post_type( 'evenemang'" ) && false !== strpos( $php, "register_taxonomy( 'evenemangstyp'" ) );
unlink( $tmp );

/* ---------- 3. Mallar ---------- */

$res = CBX_Templates::migrate_all();
ok( 'listing migrated', 'migrated' === $res['listings'][0]['state'] );
$loop_id = $res['listings'][0]['to'];
$loop    = CBX_Plugin::get_data( $loop_id );
ok( 'loop item type', 'loop-item' === get_post_meta( $loop_id, '_elementor_template_type', true ) && 'publish' === get_post_status( $loop_id ) );
$types = array_map( function ( $w ) { return $w['widgetType']; }, $loop[0]['elements'][0]['elements'] );
ok( 'loop widgets converted', array( 'image', 'heading', 'text-editor', 'button' ) === $types );
ok( 'loop page settings clean', ! isset( get_post_meta( $loop_id, '_elementor_page_settings', true )['listing_source'] ) );
ok( 'listing map', array( 'id' => $loop_id, 'post_type' => 'evenemang' ) === get_option( CBX_Templates::OPT_LISTINGS )[ $listing ] );

ok( 'popup migrated', 'migrated' === $res['popups'][0]['state'] );
$popup_new = $res['popups'][0]['to'];
$disp      = get_post_meta( $popup_new, '_elementor_popup_display_settings', true );
ok( 'popup trigger', 'yes' === $disp['triggers']['page_load'] && 3.0 === $disp['triggers']['page_load_delay'] );
ok( 'popup draft + pending', 'draft' === get_post_status( $popup_new ) && array( 'include/general' ) === get_post_meta( $popup_new, CBX_Templates::PENDING, true ) );
ok( 'popup page settings', array( 'background_color' => '#fff' ) === get_post_meta( $popup_new, '_elementor_page_settings', true ) );

$theme = array();
foreach ( $res['theme'] as $r ) {
	$theme[ $r['from'] ] = $r;
}
ok( 'header migrated', 'header' === get_post_meta( $theme[ $header ]['to'], '_elementor_template_type', true ) );
ok( 'header conditions', array( 'include/general' ) === get_post_meta( $theme[ $header ]['to'], CBX_Templates::PENDING, true ) );
ok( 'header term', array( 'header' ) === $GLOBALS['_terms'][ $theme[ $header ]['to'] ]['elementor_library_type'] );
$hdata = CBX_Plugin::get_data( $theme[ $header ]['to'] );
$htypes = array_map( function ( $w ) { return $w['widgetType']; }, $hdata[0]['elements'][0]['elements'] );
ok( 'header widgets', array( 'image', 'nav-menu', 'search-form' ) === $htypes );
ok( 'menu slug via term', 'meny-3' === $hdata[0]['elements'][0]['elements'][1]['settings']['menu'] );
ok( 'single conditions', array( 'include/singular/in_category/4', 'include/singular/in_category/7' ) === get_post_meta( $theme[ $single ]['to'], CBX_Templates::PENDING, true ) );
ok( 'originals untouched', 'jet-logo' === CBX_Plugin::get_data( $header )[0]['elements'][0]['elements'][0]['widgetType'] );

$again = CBX_Templates::migrate_all();
ok( 'already migrated templates get refreshed content', 'refreshed' === $again['listings'][0]['state'] && 'refreshed' === $again['popups'][0]['state'] && 'refreshed' === $again['theme'][0]['state'] );
ok( 'refresh keeps id, conditions and converts content', $theme[ $header ]['to'] === $again['theme'][0]['to'] && array( 'include/general' ) === get_post_meta( $theme[ $header ]['to'], CBX_Templates::PENDING, true ) && 'image' === CBX_Plugin::get_data( $theme[ $header ]['to'] )[0]['elements'][0]['elements'][0]['widgetType'] );
// Simulera en mall migrerad med en äldre version: trasigt innehåll som rättas vid nästa körning.
CBX_Plugin::save_data( $theme[ $header ]['to'], array( array( 'id' => 'gammal', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) ) );
CBX_Templates::migrate_all();
ok( 'refresh repairs stale migration', 'image' === CBX_Plugin::get_data( $theme[ $header ]['to'] )[0]['elements'][0]['elements'][0]['widgetType'] );

/* ---------- 4. Provkörning ---------- */

$before_start = get_post_meta( $start, '_elementor_data', true );
CBX_Converter::start( 'dry' );
$rows = array();
do {
	$r    = CBX_Converter::step( 2 );
	$rows = array_merge( $rows, $r['rows'] );
} while ( ! $r['done'] );
ok( 'dry changes nothing', $before_start === get_post_meta( $start, '_elementor_data', true ) && ! CBX_Plugin::has_backup( $start ) );
$ids = array_map( function ( $x ) { return $x['id']; }, $rows );
ok( 'dry covers pages', in_array( $start, $ids, true ) && in_array( $events, $ids, true ) && in_array( $draft, $ids, true ) );
ok( 'dry skips retired', ! in_array( $listing, $ids, true ) && ! in_array( $header, $ids, true ) );
ok( 'dry skips plain', ! in_array( $plain, $ids, true ) );
ok( 'dry includes migrated templates? no changes', ! in_array( $loop_id, $ids, true ) );

/* ---------- 5. Konvertering ---------- */

CBX_Converter::start( 'convert' );
do {
	$r = CBX_Converter::step( 3 );
} while ( ! $r['done'] );
ok( 'summary changed', 3 === $r['summary']['changed'] );

$d = CBX_Plugin::get_data( $start );
$w = $d[0]['elements'][0]['elements'];
ok( 'jet-posts converted', 'posts' === $w[1]['widgetType'] && 6 === $w[1]['settings']['classic_posts_per_page'] );
ok( 'other widgets intact', 'posts' === $w[0]['widgetType'] && '2' === $w[0]['settings']['classic_columns'] );
ok( 'tricky text survives', '<p>' . $tricky . '</p>' === $w[2]['settings']['editor'] );
ok( 'sticky mapped', 'top' === $d[0]['settings']['sticky'] && ! isset( $d[0]['settings']['jet_sticky_section'] ) );
ok( 'backup stored', CBX_Plugin::has_backup( $start ) && $before_start === get_post_meta( $start, CBX_Plugin::BACKUP_META, true ) );

$e = CBX_Plugin::get_data( $events )[0]['elements'][0]['elements'];
ok( 'listing grid → loop grid', 'loop-grid' === $e[0]['widgetType'] && $loop_id === $e[0]['settings']['template_id'] && 'evenemang' === $e[0]['settings']['post_query_post_type'] );
ok( 'filter untouched', 'jet-smart-filters-select' === $e[1]['widgetType'] );
ok( 'popup link', false !== strpos( urldecode( $e[2]['settings']['__dynamic__']['link'] ), '"popup":"' . $popup_new . '"' ) );
ok( 'draft converted', 'heading' === CBX_Plugin::get_data( $draft )[0]['elements'][0]['elements'][0]['widgetType'] );

// Andra körningen: backupen ska fortfarande vara originalet.
CBX_Converter::start( 'convert' );
do {
	$r = CBX_Converter::step( 10 );
} while ( ! $r['done'] );
ok( 'second run no changes', 0 === $r['summary']['changed'] );
ok( 'backup still original', $before_start === get_post_meta( $start, CBX_Plugin::BACKUP_META, true ) );

$report2 = CBX_Scanner::run_all();
$t2      = CBX_Scanner::totals( $report2 );
ok( 'rescan pages only manual left', 1 === $report2['widgets']['jet-smart-filters-select']['count'] && ! isset( $report2['widgets']['jet-posts'] ) );

/* ---------- Stilöverföring ---------- */

$w2 = CBX_Plugin::get_data( $start )[0]['elements'][0]['elements'][1];
ok( 'style: class added', false !== strpos( $w2['settings']['_css_classes'], 'cbx-from-jet-posts' ) );
ok( 'style: custom css title', false !== strpos( $w2['settings']['custom_css'], 'selector .elementor-posts-container .elementor-post__title a' ) && false !== strpos( $w2['settings']['custom_css'], '#272727' ) );
ok( 'style: mobile media', false !== strpos( $w2['settings']['custom_css'], '@media(max-width:767px)' ) );
ok( 'style: base block', false !== strpos( $w2['settings']['custom_css'], 'padding-bottom:0' ) );
ok( 'style: unmapped as comment', false !== strpos( $w2['settings']['custom_css'], 'utan direkt motsvarighet' ) );
ok( 'style: other widgets untouched', ! isset( CBX_Plugin::get_data( $start )[0]['elements'][0]['elements'][0]['settings']['custom_css'] ) );
$logmeta = get_post_meta( $start, CBX_Plugin::LOG_META, true );
$notes   = implode( ' ', array_merge( ...array_map( function ( $r ) { return $r['notes']; }, end( $logmeta )['log'] ) ) );
ok( 'style: log note', false !== strpos( $notes, 'Stil överförd' ) );

// Utan Pro: CSS:en hamnar i postmeta i stället för Custom CSS.
$orig_data = json_decode( get_post_meta( $start, CBX_Plugin::BACKUP_META, true ), true );
list( $cd, $clog )            = CBX_Plugin::mapper( array( 'has_pro' => true ) )->convert_document( $orig_data );
list( $fd, $flog, $free )     = CBX_Styles::apply( $start, $cd, $clog, false );
ok( 'free: css in meta map', isset( $free['w2'] ) && false !== strpos( $free['w2'], 'elementor-post__title' ) );
ok( 'free: no custom_css', ! isset( $fd[0]['elements'][0]['elements'][1]['settings']['custom_css'] ) );

/* ---------- Sajtens egen CSS ---------- */

$sc = CBX_Site_CSS::build();
$labels = array_column( $sc['sources'], 'label' );
ok( 'site css: plugin file found', (bool) preg_grep( '/m-design/', $labels ) );
ok( 'site css: customizer found', in_array( 'Utseende → Anpassa → Ytterligare CSS', $labels, true ) );
ok( 'site css: menu cta', false !== strpos( $sc['css'], '.cbx-from-jet-mega-menu.cbx-from-jet-mega-menu.cbx-from-jet-mega-menu .elementor-nav-menu > .menu-cta a.elementor-item' ) );
ok( 'site css: excerpt', false !== strpos( $sc['css'], '.cbx-from-jet-posts.cbx-from-jet-posts.cbx-from-jet-posts .elementor-posts-container .elementor-post__excerpt' ) );
ok( 'site css: non-jet ignored', false === strpos( $sc['css'], '.vanlig' ) );
ok( 'site css: enabled', 1 === $sc['enabled'] );
ob_start();
CBX_Site_CSS::print_css();
$printed = ob_get_clean();
ok( 'site css: printed', false !== strpos( $printed, '<style id="cbx-site-css">' ) );
ok( 'report site css summary', ! empty( CBX_Scanner::run_all()['site_css'] ) );

/* ---------- Förhandsgranskning ---------- */

$plain_posts = make_post( 'page', 'Nyheter', 'publish', el_section( array( el_widget( 'np1', 'jet-posts', array( 'number' => 4 ) ) ) ) );
$copy        = CBX_Preview::create( $plain_posts );
ok( 'preview: copy created', is_int( $copy ) && 'draft' === get_post_status( $copy ) );
ok( 'preview: copy converted', 'posts' === CBX_Plugin::get_data( $copy )[0]['elements'][0]['elements'][0]['widgetType'] );
ok( 'preview: original untouched', 'jet-posts' === CBX_Plugin::get_data( $plain_posts )[0]['elements'][0]['elements'][0]['widgetType'] && ! CBX_Plugin::has_backup( $plain_posts ) );
ok( 'preview: reuse same copy', $copy === CBX_Preview::create( $plain_posts ) );
ok( 'preview: excluded from scan', ! in_array( $copy, CBX_Plugin::elementor_post_ids(), true ) );
ok( 'preview: url', false !== strpos( CBX_Preview::url( $copy ), 'preview=true' ) );
ok( 'preview: cleanup', 1 === CBX_Preview::cleanup() && null === get_post( $copy ) && null !== get_post( $plain_posts ) );

/* ---------- CSS Hero ---------- */

$GLOBALS['_plugins']['css-hero/css-hero-main.php'] = array( 'Name' => 'CSS Hero', 'Version' => '4.09', 'Author' => 'The CSS Hero Team' );
$GLOBALS['_active_plugins'][] = 'css-hero/css-hero-main.php';
@mkdir( wp_upload_dir()['basedir'] . '/2026/10', 0777, true );
// Verkliga regler från tidningenm.se (CSS Hero 4.09), plus en @import för att testa flytten av import.
$hero_css = "@import url('https://fonts.example/x.css');\nbody { -webkit-font-smoothing: antialiased; font-size: 18px; }\n.day-tag a { background: rgb(251, 248, 240); display: inline-block; margin-right: 8px; padding: 2px 10px 0px; }\ni.jet-dropdown-arrow.fa.fa-angle-down { display: none !important; }\n@media only screen and (max-width: 1024px) { .elementor-widget-jet-mega-menu { display: none; } }\nhtml > body > #content > div > div > section > div:nth-child(2) > div:nth-child(1) > div > div > div > div:nth-child(3) > a { background: rgb(0, 0, 0); color: rgb(255, 255, 255); }";
file_put_contents( wp_upload_dir()['basedir'] . '/2026/10/csshero-static-style-m-tema.css', $hero_css );
$GLOBALS['_custom_css'] = '.min-egen { color: blue; }';
update_option( 'active_plugins', $GLOBALS['_active_plugins'] );

$st = CBX_CSS_Hero::status();
ok( 'hero: found', $st && 'css-hero/css-hero-main.php' === $st['plugin']['file'] && $st['plugin']['active'] );
ok( 'hero: css via static file', false !== strpos( $st['css'], 'antialiased' ) && false !== strpos( implode( ' ', $st['sources'] ), 'csshero-static-style-m-tema.css' ) );
ok( 'hero: analysis', 5 === $st['analysis']['rules'] && 2 === $st['analysis']['jet'] && 1 === count( $st['analysis']['fragile'] ) && 1 === $st['analysis']['imports'] );

// Loopback: det som faktiskt levereras vinner.
$GLOBALS['_remote'] = array( 'code' => 200, 'body' => '<link rel="stylesheet" href="https://test.se/wp-content/uploads/2026/10/csshero-static-style-m-tema.css?ver=380"><style id="csshero-inline">.extra{color:red}</style>' );
$col = CBX_CSS_Hero::collect();
ok( 'hero: loopback link + inline', false !== strpos( $col['css'], 'antialiased' ) && false !== strpos( $col['css'], '.extra' ) && 2 === count( $col['sources'] ) );
unset( $GLOBALS['_remote'] );

$res = CBX_CSS_Hero::migrate();
ok( 'hero: migrated', ! is_wp_error( $res ) && 5 === $res['moved'] );
$custom = $GLOBALS['_custom_css'];
ok( 'hero: import first', 0 === strpos( $custom, "@import url('https://fonts.example/x.css');" ) );
ok( 'hero: own css kept', false !== strpos( $custom, '.min-egen { color: blue; }' ) );
ok( 'hero: block markers', false !== strpos( $custom, CBX_CSS_Hero::START ) && false !== strpos( $custom, CBX_CSS_Hero::END ) && false !== strpos( $custom, 'antialiased' ) );
ok( 'hero: deactivated', ! in_array( 'css-hero/css-hero-main.php', $GLOBALS['_active_plugins'], true ) );
ok( 'hero: backup', (bool) CBX_CSS_Hero::backup() );

// Steg 4 läser nu Ytterligare CSS (och inte CSS Heros gamla fil) och översätter Jet-reglerna.
$sc2 = CBX_Site_CSS::build();
ok( 'hero → site css translated', false !== strpos( $sc2['css'], '.cbx-from-jet-mega-menu.cbx-from-jet-mega-menu.cbx-from-jet-mega-menu .sub-arrow' ) );
ok( 'hero file skipped after move', ! preg_grep( '/csshero/', array_column( $sc2['sources'], 'label' ) ) );

// Ytterligare CSS redigeras efter flytten; ångra ska behålla det.
$GLOBALS['_custom_css'] .= "\n.senare { color: green; }";
CBX_CSS_Hero::undo();
$after = $GLOBALS['_custom_css'];
ok( 'hero undo: block removed', false === strpos( $after, 'antialiased' ) && false === strpos( $after, CBX_CSS_Hero::START ) && false === strpos( $after, 'fonts.example' ) );
ok( 'hero undo: own + later edits kept', false !== strpos( $after, '.min-egen' ) && false !== strpos( $after, '.senare' ) );
ok( 'hero undo: reactivated', in_array( 'css-hero/css-hero-main.php', $GLOBALS['_active_plugins'], true ) && ! CBX_CSS_Hero::backup() );

// Andra flytten ersätter blocket i stället för att dubblera det.
CBX_CSS_Hero::migrate();
CBX_CSS_Hero::migrate( false );
ok( 'hero: idempotent block', 1 === substr_count( $GLOBALS['_custom_css'], CBX_CSS_Hero::START ) && 1 === substr_count( $GLOBALS['_custom_css'], '@import' ) );
ok( 'report: csshero', ! empty( CBX_Scanner::run_all()['csshero'] ) );

/* ---------- 6. Byt ---------- */

$n = CBX_Templates::activate();
ok( 'activate count (header, single, popup)', 3 === $n );
ok( 'header published + conditions', 'publish' === get_post_status( $theme[ $header ]['to'] ) && array( 'include/general' ) === get_post_meta( $theme[ $header ]['to'], '_elementor_conditions', true ) );
ok( 'popup published', 'publish' === get_post_status( $popup_new ) );

/* ---------- 6b. Spärr, förhandsvisning av mall ---------- */

$rem = CBX_Scanner::remaining();
ok( 'remaining: converted start page has no jet', ! isset( $rem['posts'][ $start ] ) );

// En Elementor-mall (t.ex. sidhuvud) förhandsgranskas som Canvas-sida, inte som mallutkast.
$lib = wp_insert_post( array( 'post_title' => 'SIDHUVUD', 'post_type' => 'elementor_library', 'post_status' => 'publish' ) );
update_post_meta( $lib, '_elementor_edit_mode', 'builder' );
update_post_meta( $lib, '_elementor_template_type', 'header' );
update_post_meta( $lib, '_elementor_conditions', array( 'include/general' ) );
update_post_meta( $lib, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 's1', 'elType' => 'section', 'settings' => array(), 'elements' => array( array( 'id' => 'c1', 'elType' => 'column', 'settings' => array(), 'elements' => array( array( 'id' => 'm1', 'elType' => 'widget', 'widgetType' => 'jet-mega-menu', 'settings' => array( 'menu' => '26' ), 'elements' => array() ) ) ) ) ) ) ) ) );
$link = CBX_Preview::link( $lib );
ok( 'preview link not html-encoded', false === strpos( $link, '&amp;' ) && false !== strpos( $link, 'action=cbx_preview' ) && false !== strpos( $link, '_wpnonce=' ) );
$pv = CBX_Preview::create( $lib );
ok( 'template preview is page', 'page' === get_post_type( $pv ) && 'draft' === get_post_status( $pv ) );
ok( 'template preview canvas', 'elementor_canvas' === get_post_meta( $pv, '_wp_page_template', true ) && 'wp-page' === get_post_meta( $pv, '_elementor_template_type', true ) );
ok( 'template preview no conditions', '' === get_post_meta( $pv, '_elementor_conditions', true ) );
ok( 'template preview converted', 'nav-menu' === CBX_Plugin::get_data( $pv )[0]['elements'][0]['elements'][0]['widgetType'] );
ok( 'template preview url', false !== strpos( CBX_Preview::url( $pv ), 'preview=true' ) );
// En gammal förhandsvisning som skapades som mallutkast (v1.2) görs om till sida.
wp_update_post( array( 'ID' => $pv, 'post_type' => 'elementor_library' ) );
ok( 'old library preview reused as page', $pv === CBX_Preview::create( $lib ) && 'page' === get_post_type( $pv ) );
ok( 'remaining ignores preview copies', ! isset( CBX_Scanner::remaining()['posts'][ $pv ] ) );
ok( 'remaining finds unconverted template', isset( CBX_Scanner::remaining()['posts'][ $lib ]['jet-mega-menu'] ) );
CBX_Preview::cleanup();
wp_delete_post( $lib, true );

/* ---------- 7. Återställ ---------- */

// Crocoblock har avaktiverats av steg 7 – återställningen ska slå på det igen.
$GLOBALS['_plugins']['jet-elements/jet-elements.php'] = array( 'Name' => 'JetElements', 'Version' => '2.9' );
$GLOBALS['_active_plugins'] = array_values( array_diff( $GLOBALS['_active_plugins'] ?? array(), array( 'jet-elements/jet-elements.php' ) ) );
update_option( 'cbx_deactivated_plugins', array( 'jet-elements/jet-elements.php' ) );

CBX_Converter::start( 'restore' );
do {
	$r = CBX_Converter::step( 1 );
} while ( ! $r['done'] );
ok( 'restored 3', 3 === $r['summary']['restored'] );
ok( 'restore exact', $before_start === get_post_meta( $start, '_elementor_data', true ) );
ok( 'restore removes backup', ! CBX_Plugin::has_backup( $start ) && array() === CBX_Plugin::backup_post_ids() );
ok( 'restored widget', 'jet-posts' === CBX_Plugin::get_data( $start )[0]['elements'][0]['elements'][1]['widgetType'] );
ok( 'restore reactivates crocoblock', 1 === $r['summary']['reactivated'] && in_array( 'jet-elements/jet-elements.php', $GLOBALS['_active_plugins'], true ) && ! get_option( 'cbx_deactivated_plugins' ) );
$rem = CBX_Scanner::remaining();
ok( 'remaining after restore', $rem['count'] > 0 && isset( $rem['posts'][ $start ]['jet-posts'] ) );

$undone = CBX_Templates::undo();
ok( 'undo templates', 4 === $undone && 'trash' === get_post_status( $loop_id ) && ! get_option( CBX_Templates::OPT_LISTINGS ) );

/* ---------- 7b. Egna frågor ---------- */

ok( 'queries: decode slashed', array( 'post_type' => 'post' ) === CBX_Queries::decode( '{\\"post_type\\":\\"post\\"}' ) );
ok( 'queries: decode entities', array( 'cat' => 3 ) === CBX_Queries::decode( '{&quot;cat&quot;:3}' ) );
ok( 'queries: decode curly quotes', array( 'tag' => 'x' ) === CBX_Queries::decode( "{\u{201C}tag\u{201D}:\u{201C}x\u{201D}}" ) );
$qid = CBX_Queries::register( array( 'post_type' => 'post', 'posts_per_page' => 4 ) );
ok( 'queries: stable id', $qid === CBX_Queries::register( array( 'post_type' => 'post', 'posts_per_page' => 4 ) ) && 1 === count( CBX_Queries::all() ) );
$GLOBALS['_actions'] = array();
CBX_Queries::init();
$fake = new class { public $vars = array(); function set( $k, $v ) { $this->vars[ $k ] = $v; } };
foreach ( $GLOBALS['_actions'][ 'elementor/query/' . $qid ] as $cb ) { $cb( $fake ); }
ok( 'queries: hook sets args', 4 === $fake->vars['posts_per_page'] && 'post' === $fake->vars['post_type'] );
update_option( 'posts_per_page', 10 );
$fake2 = new class { public $vars = array(); function set( $k, $v ) { $this->vars[ $k ] = $v; } };
CBX_Queries::apply( $fake2, array( 'category_name' => 'kronikor' ) );
ok( 'queries: site default per page', 10 === $fake2->vars['posts_per_page'] );

/* ---------- 7c. Autopilot ---------- */

// En sida med Jet-karusell: blir medvetet ett rutnät och ska jämföras löst (bara stil).
$carousel = make_post( 'page', 'Karusellsida', 'publish', el_section( array( el_widget( 'k1', 'jet-posts', array( 'carousel_enabled' => 'yes', 'number' => 6 ) ) ) ) );

$ap_before = get_post_meta( $start, '_elementor_data', true );
$ap_log    = array();
$ap_msg    = array();
$r         = CBX_Autopilot::run_headless( 'test', array(), function ( $step, $res ) use ( &$ap_log, &$ap_msg ) { $ap_log[ $step ] = $res['status']; $ap_msg[ $step ] = $res['message']; } );
ok( 'autopilot templates message', 0 === strpos( $ap_msg['templates'], '4 mallar kopierade som utkast' ) );
ok( 'autopilot test: ok', $r['ok'] && ! $r['rolled_back'] );
ok( 'autopilot test: steps', array( 'scan', 'content_types', 'templates', 'site_css', 'dry', 'previews', 'stabilize', 'compare', 'cleanup' ) === array_keys( $ap_log ) && 'skip' === $ap_log['compare'] );
ok( 'autopilot test: site unchanged', $ap_before === get_post_meta( $start, '_elementor_data', true ) && ! CBX_Plugin::has_backup( $start ) );
ok( 'autopilot test: previews cleaned', array() === CBX_Preview::all() );
$ap_jobs = CBX_Autopilot::run()['jobs'];
$j       = $ap_jobs[ 'doc-' . $start ] ?? null;
ok( 'autopilot job: start page', $j && in_array( 'w2', $j['widgets'], true ) && 'https://test.se/?p=' . $start === $j['original_url'] && '[data-elementor-id="' . $start . '"]' === $j['original_root'] && 0 === strpos( $j['preview_url'], 'https://test.se/?p=' . $start . '&cbx_swap=' . $start . '-' ) && false !== strpos( $j['preview_url'], 'cbx_swap_nonce=' ) && 0 === strpos( $j['preview_root'], '[data-elementor-id="' ) );
ok( 'autopilot job: not draft', ! isset( $ap_jobs[ 'doc-' . $draft ] ) );
$kj = $ap_jobs[ 'doc-' . $carousel ] ?? null;
ok( 'autopilot job: carousel widget compared loosely', $kj && in_array( 'k1', $kj['loose'], true ) && false !== strpos( $kj['note'], 'rutnät' ) );
$k_data = CBX_Plugin::get_data( $carousel );
ok( 'autopilot job: carousel ancestors loose too', in_array( (string) $k_data[0]['id'], $kj['loose'], true ) );
ok( 'autopilot job: normal posts widget not loose', array() === $j['loose'] );
$hj = null;
foreach ( $ap_jobs as $job ) {
	if ( '[data-elementor-id="' . $header . '"]' === $job['original_root'] ) {
		$hj = $job;
	}
}
ok( 'autopilot job: migrated jet header compared on home page', $hj && 'https://test.se/' === $hj['original_url'] && 'header' === $hj['type'] && '[data-elementor-id="' . $hj['id'] . '"]' === $hj['verify_root_switch'] );
$sj = null;
foreach ( $ap_jobs as $job ) {
	if ( '[data-elementor-id="' . $single . '"]' === $job['original_root'] ) {
		$sj = $job;
	}
}
parse_str( (string) parse_url( $sj['original_url'] ?? '', PHP_URL_QUERY ), $sq );
ok( 'autopilot job: single template shown on a post, swapped in place', $sj && ! $sj['context'] && 'post' === get_post_type( (int) ( $sq['p'] ?? 0 ) ) && false !== strpos( $sj['preview_url'], 'cbx_swap=' . $single . '-' ) );

// Förhandsvisning på plats: adressen byter originalet mot kopian för Elementor.
$sw_copy = CBX_Preview::create( $start, 'swap' );
ok( 'swap copy: same post type, marked', 'page' === get_post_type( $sw_copy ) && 'swap' === get_post_meta( $sw_copy, CBX_Preview::MODE, true ) && $sw_copy !== CBX_Preview::create( $start ) );
ok( 'swap copy reused', $sw_copy === CBX_Preview::create( $start, 'swap' ) );
parse_str( (string) parse_url( CBX_Preview::swap_url( 'https://test.se/?p=' . $start, array( $start => $sw_copy ) ), PHP_URL_QUERY ), $q );
$_GET = $q;
CBX_Preview::maybe_swap();
ok( 'swap: document id swapped', $sw_copy === apply_filters( 'elementor/documents/get/post_id', $start ) && 77 === apply_filters( 'elementor/documents/get/post_id', 77 ) && CBX_Preview::is_swap() );
$GLOBALS['_filters'] = array();
$_GET = array( 'cbx_swap' => $start . '-' . $plain, 'cbx_swap_nonce' => wp_create_nonce( 'cbx_swap_' . $start . '-' . $plain ) );
$rp = new ReflectionProperty( 'CBX_Preview', 'swap' );
$rp->setAccessible( true );
$rp->setValue( null, array() );
CBX_Preview::maybe_swap();
ok( 'swap: refuses a post that is not a copy of the source', ! CBX_Preview::is_swap() );
$_GET = array();
CBX_Preview::cleanup();

// Mallens adress utifrån visningsvillkor.
ok( 'template url: general → home', 'https://test.se/' === CBX_Autopilot::template_url( 'header', array( 'include/general' ) ) );
ok( 'template url: specific page', 'https://test.se/?p=' . $plain === CBX_Autopilot::template_url( 'single-page', array( 'exclude/general', 'include/singular/page/' . $plain ) ) );
ok( 'template url: posts in category → a post, not the page with that id', 'post' === get_post_type( (int) substr( CBX_Autopilot::template_url( 'single', array( 'include/singular/in_category/' . $events ) ), strlen( 'https://test.se/?p=' ) ) ) );
ok( 'template url: no conditions, popup → none', '' === CBX_Autopilot::template_url( 'popup', array() ) );

// Positionsberoende CSS får stabila kopior.
$css_keep = $GLOBALS['_custom_css'];
$GLOBALS['_custom_css'] .= "\nhtml > body > #content > div > div > section > div:nth-child(2) > div:nth-child(1) > a:hover{color:#fff;font-family:Manrope}\n@media (max-width:767px){html > body > #content > div > div > section > div:nth-child(2) > p{margin:0}}\n.vanlig-klass{color:red}";
$sels = CBX_Stabilize::selectors();
$hov  = array_values( array_filter( $sels, function ( $x ) { return ':hover' === $x['pseudo']; } ) );
$mine = array_filter( $sels, function ( $x ) { return false !== strpos( $x['sel'], ':hover' ) || '> p' === substr( $x['sel'], -3 ); } );
ok( 'stabilize: fragile selectors found, pseudo split', 2 === count( $mine ) && $hov && 'html > body > #content > div > div > section > div:nth-child(2) > div:nth-child(1) > a' === $hov[0]['query'] );
$res = CBX_Stabilize::build( array(
	$hov[0]['sel'] => array( 'html body #content .elementor-element-w4.elementor-element-w4.elementor-element-w4 a.elementor-button' ),
	'html > body > #content > div > div > section > div:nth-child(2) > p' => array( 'html body .elementor-element-w3.elementor-element-w3.elementor-element-w3 p', 'x{}<script>' ),
) );
$st = CBX_Stabilize::data();
$res2 = CBX_Stabilize::build( array( $hov[0]['sel'] => array( array( 's' => 'html body .elementor-element-w4.elementor-element-w4.elementor-element-w4 a', 'media' => array( '(min-width: 1025px)', '(min-width: 768px) and (max-width: 1024px)', 'screen{}' ) ) ) ) );
$st2  = CBX_Stabilize::data();
ok( 'stabilize: only in widths where the original matched', 2 === $res2['rules'] && false !== strpos( $st2['css'], '@media (min-width: 1025px)' ) && false !== strpos( $st2['css'], '@media (min-width: 768px) and (max-width: 1024px)' ) && false === strpos( $st2['css'], 'screen' ) && false === strpos( $st2['css'], 'max-width: 767px' ), $st2['css'] );
$res = CBX_Stabilize::build( array(
	$hov[0]['sel'] => array( 'html body #content .elementor-element-w4.elementor-element-w4.elementor-element-w4 a.elementor-button' ),
	'html > body > #content > div > div > section > div:nth-child(2) > p' => array( 'html body .elementor-element-w3.elementor-element-w3.elementor-element-w3 p', 'x{}<script>' ),
) );
ok( 'stabilize: css built', 2 === $res['rules'] && false !== strpos( $st['css'], 'a.elementor-button:hover' ) && false !== strpos( $st['css'], 'font-family:Manrope' ) && false !== strpos( $st['css'], '@media (max-width:767px)' ) && false === strpos( $st['css'], 'script' ) );
ob_start();
CBX_Stabilize::print_css();
ok( 'stabilize: not printed before conversion', '' === ob_get_clean() );
CBX_Converter::start( 'convert' );
delete_option( CBX_Converter::OPT_PROGRESS );
ob_start();
CBX_Stabilize::print_css();
ok( 'stabilize: printed after conversion starts', false !== strpos( ob_get_clean(), 'elementor-element-w4' ) );
CBX_Stabilize::activate( false );
$GLOBALS['_custom_css'] = $css_keep;
$rep = CBX_Autopilot::last_report();
ok( 'autopilot report saved', $rep && 'test' === $rep['mode'] && ! empty( $rep['headless'] ) );

// Genomför – sidorna syntes före konverteringen men saknar sina rötter efteråt:
// det är ett riktigt fel, och allt ska återställas.
$all_roots = '';
for ( $i = 1; $i <= 80; $i++ ) {
	$all_roots .= '<div data-elementor-id="' . $i . '"></div>';
}
$GLOBALS['_remote'] = function ( $url ) use ( $all_roots ) {
	// Före konverteringen (inga backuper): rötterna finns. Efter: tom sida.
	$body = CBX_Plugin::backup_post_ids() ? 'tom' : $all_roots;
	return array( 'code' => 200, 'body' => '<html><body>' . $body . '</body></html>' );
};
$r = CBX_Autopilot::run_headless( 'apply' );
ok( 'autopilot apply: http check fails', ! $r['ok'] && 'fail' === $r['steps']['http_check']['status'] );
ok( 'autopilot apply: failure names the document', false !== strpos( $r['steps']['http_check']['message'], 'visas inte längre' ) );
ok( 'autopilot apply: rolled back', $r['rolled_back'] && $ap_before === get_post_meta( $start, '_elementor_data', true ) && ! CBX_Plugin::has_backup( $start ) );

// Servern kan inte nå sig själv: varning, inte fel.
unset( $GLOBALS['_remote'] );
ok( 'autopilot http: no loopback → null', null === CBX_Autopilot::http_check( $ap_jobs ) );

// PHP-fel på sidan = fel.
$GLOBALS['_remote'] = array( 'code' => 200, 'body' => 'There has been a critical error on this website.' );
ok( 'autopilot http: fatal error detected', (bool) CBX_Autopilot::http_check( $ap_jobs ) );

// Genomför med korrekt HTML och avstängning: Crocoblock får vara kvar eftersom manuella widgets finns.
$ids = array();
foreach ( $ap_jobs as $job ) {
	if ( preg_match( '/data-elementor-id="(\d+)"/', $job['original_root'], $rm ) ) {
		$ids[] = '<div data-elementor-id="' . $rm[1] . '">';
	}
	foreach ( $job['widgets'] as $wid ) {
		$ids[] = '<div class="elementor-element elementor-element-' . $wid . '"></div>';
	}
	$ids[] = '</div>';
}
$GLOBALS['_remote'] = array( 'code' => 200, 'body' => '<html><body>' . implode( '', $ids ) . '</body></html>' );
$r = CBX_Autopilot::run_headless( 'apply', array( 'switch' => true ) );
ok( 'autopilot apply: ok', $r['ok'] && ! $r['rolled_back'] );
ok( 'autopilot apply: converted', CBX_Plugin::has_backup( $start ) && 'posts' === CBX_Plugin::get_data( $start )[0]['elements'][0]['elements'][1]['widgetType'] );
ok( 'autopilot apply: switch refused while jet remains', 'warn' === $r['steps']['switch']['status'] && 'skip' === $r['steps']['http_check_switch']['status'] && in_array( 'jet-elements/jet-elements.php', $GLOBALS['_active_plugins'], true ) );
ok( 'autopilot rollback restores', in_array( '1 sidor återställda.', CBX_Autopilot::rollback(), true ) || ! CBX_Plugin::has_backup( $start ) );
ok( 'autopilot rollback exact', $ap_before === get_post_meta( $start, '_elementor_data', true ) );
unset( $GLOBALS['_remote'] );

/* ---------- 7c2. Dokument som inte syns på sin adress (FACIT, Logga in) ---------- */

$mk_job = function ( $key, $root, $widget, $url ) {
	return array(
		'key' => $key, 'id' => (int) $root, 'label' => $key, 'type' => 'page', 'widgets' => array( $widget ),
		'manual' => 0, 'context' => false, 'original_url' => $url, 'original_root' => '[data-elementor-id="' . $root . '"]',
		'preview_url' => $url, 'preview_root' => '', 'verify_root' => '', 'verify_root_switch' => '', 'note' => '',
	);
};
update_option( CBX_Autopilot::OPT_RUN, array(
	'mode' => 'apply', 'opts' => array(), 'steps' => array(), 'docs' => array(), 'flags' => array(), 'messages' => array(),
	'jobs' => array(
		'doc-901' => $mk_job( 'doc-901', 901, 'aa11aa1', 'https://test.se/sida/' ),
		'doc-902' => $mk_job( 'doc-902', 902, 'bb22bb2', 'https://test.se/sida/' ), // visas inte där (som FACIT/Logga in)
	),
) );
$page_good = array( 'code' => 200, 'body' => '<div data-elementor-id="901"><div class="elementor-element-aa11aa1"></div></div>' );
$GLOBALS['_remote'] = $page_good;
ok( 'unreachable: pre-check flags the invisible document', 1 === CBX_Autopilot::mark_unreachable() );
$jobs_now = array_values( CBX_Autopilot::run()['jobs'] );
ok( 'unreachable: flag set on the right job', empty( $jobs_now[0]['unreachable'] ) && ! empty( $jobs_now[1]['unreachable'] ) );
ok( 'http check: invisible document is skipped, visible one passes', array() === CBX_Autopilot::http_check( $jobs_now ) );
$GLOBALS['_remote'] = array( 'code' => 200, 'body' => '<div data-elementor-id="901">trasig</div>' );
$pr = CBX_Autopilot::http_check( $jobs_now );
ok( 'http check: missing widget still fails', 1 === count( $pr ) && false !== strpos( $pr[0], 'element saknas' ) );
$GLOBALS['_remote'] = array( 'code' => 200, 'body' => '<div>helt annat</div>' );
$pr = CBX_Autopilot::http_check( $jobs_now );
ok( 'http check: vanished root fails for previously visible doc only', 1 === count( $pr ) && false !== strpos( $pr[0], 'doc-901 visas inte längre' ), $pr );
unset( $GLOBALS['_remote'] );
delete_option( CBX_Autopilot::OPT_RUN );

/* ---------- 7d. Förenklad arbetsgång: Stäng av, Ångra allt ---------- */

ok( 'plan: switch', array( 'templates', 'live_jobs', 'baseline', 'switch', 'http_check_switch', 'verify_switch' ) === CBX_Autopilot::plan( 'switch' ) );
ok( 'plan: undo', array( 'undo' ) === CBX_Autopilot::plan( 'undo' ) );

// Konvertera på riktigt och mät upp jobben för avstängningen.
$GLOBALS['_remote'] = array( 'code' => 200, 'body' => '<html></html>' );
CBX_Converter::start( 'convert' );
do {
	$cr = CBX_Converter::step( 10 );
} while ( ! $cr['done'] );
CBX_Templates::migrate_all();
CBX_Autopilot::start( 'switch' );
$lj = CBX_Autopilot::step( 'live_jobs' );
$lkeys = array_column( $lj['data']['jobs'], 'key' );
ok( 'live jobs: converted pages', in_array( 'live-' . $start, $lkeys, true ) && 'https://test.se/?p=' . $start === $lj['data']['jobs'][ array_search( 'live-' . $start, $lkeys, true ) ]['original_url'] );
$hjob = null;
foreach ( $lj['data']['jobs'] as $job ) {
	if ( '[data-elementor-id="' . $header . '"]' === $job['original_root'] ) {
		$hjob = $job;
	}
}
ok( 'live jobs: jet header compared against its new template after switch', $hjob && '[data-elementor-id="' . $hjob['id'] . '"]' === $hjob['verify_root_switch'] && 'https://test.se/' === $hjob['original_url'] );
$sw = CBX_Autopilot::step( 'switch' );
ok( 'switch mode: guard is a hard stop', 'fail' === $sw['status'] );

// Avstängning som måste ångras: bara Crocoblock och mallarna – sidorna förblir konverterade.
$run = CBX_Autopilot::run();
CBX_Templates::activate();
CBX_Plugin::deactivate_jet();
$run['flags'] = array( 'switched' => true );
update_option( CBX_Autopilot::OPT_RUN, $run );
$hdr_new = $hjob['id'];
ok( 'switch done: template published', 'publish' === get_post_status( $hdr_new ) && ! in_array( 'jet-elements/jet-elements.php', $GLOBALS['_active_plugins'], true ) );
$rb = CBX_Autopilot::rollback();
ok( 'switch rollback: crocoblock on, templates draft, pages still converted', in_array( 'jet-elements/jet-elements.php', $GLOBALS['_active_plugins'], true ) && 'draft' === get_post_status( $hdr_new ) && CBX_Plugin::has_backup( $start ) && '' === get_post_meta( $hdr_new, '_elementor_conditions', true ) && get_post_meta( $hdr_new, CBX_Templates::PENDING, true ) );

// Ångra allt.
CBX_Stabilize::activate( true );
$msgs = CBX_Autopilot::undo_all();
ok( 'undo all: pages restored', ! CBX_Plugin::backup_post_ids() && $ap_before === get_post_meta( $start, '_elementor_data', true ) );
ok( 'undo all: templates removed', ! get_option( CBX_Templates::OPT_THEME ) && 'trash' === get_post_status( $hdr_new ) );
ok( 'undo all: message', false !== strpos( implode( ' ', $msgs ), 'sidor återställda' ) );
unset( $GLOBALS['_remote'] );

// Den förenklade sidan renderas.
if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	define( 'ELEMENTOR_PRO_VERSION', '4.3.2' );
}
$fm = new ReflectionMethod( 'CBX_Admin', 'flow' );
$fm->setAccessible( true );
ob_start();
$fm->invoke( null, array( 'jet-elements/jet-elements.php' => array( 'active' => true ) ) );
$html = ob_get_clean();
ok( 'flow: three steps and undo', 3 === substr_count( $html, '<li class="cbx-flow-step' ) && false !== strpos( $html, 'data-cbx-auto-run="test"' ) && false !== strpos( $html, 'data-cbx-auto-run="apply"' ) && false !== strpos( $html, 'data-cbx-auto-run="undo"' ) );
ok( 'flow: switch blocked while jet remains', (bool) preg_match( '/data-cbx-auto-run="switch" disabled/', $html ) && false !== strpos( $html, 'Går inte ännu' ) );

/* ---------- 7e. Uppdateringar från GitHub ---------- */

$api  = 'https://api.github.com/repos/daystudioab/crocoblock-exit/releases/latest';
$base = CBX_Updater::basename();
ok( 'updater: plugin header has Update URI on github.com', false !== strpos( file_get_contents( CBX_FILE ), 'Update URI:        https://github.com/daystudioab/crocoblock-exit' ) );
$rel = function ( $tag, $asset = 'crocoblock-exit.zip' ) {
	return array( 'code' => 200, 'body' => json_encode( array(
		'tag_name'     => $tag,
		'html_url'     => 'https://github.com/daystudioab/crocoblock-exit/releases/tag/' . $tag,
		'body'         => "Nytt:\n\n- Bättre",
		'published_at' => '2026-11-02T08:00:00Z',
		'assets'       => array( array( 'name' => $asset, 'browser_download_url' => 'https://github.com/daystudioab/crocoblock-exit/releases/download/' . $tag . '/' . $asset ) ),
	) ) );
};
list( $maj, $min, $pat ) = array_map( 'intval', explode( '.', CBX_VERSION ) );
$newer = 'v' . $maj . '.' . ( $min + 1 ) . '.0';
$GLOBALS['_remote_map'] = array( $api => $rel( $newer ) );
CBX_Updater::forget();
$u = CBX_Updater::check( false, array(), $base, array() );
ok( 'updater: newer release offered with package', is_array( $u ) && ltrim( $newer, 'v' ) === $u['version'] && false !== strpos( $u['package'], $newer . '/crocoblock-exit.zip' ) && 'crocoblock-exit' === $u['slug'] && 'github.com/daystudioab/crocoblock-exit' === $u['id'] );
ok( 'updater: other plugins untouched', 'orig' === CBX_Updater::check( 'orig', array(), 'annat/annat.php', array() ) );
$GLOBALS['_remote_log'] = array();
CBX_Updater::check( false, array(), $base, array() );
ok( 'updater: cached (GitHub asked once)', ! in_array( $api, $GLOBALS['_remote_log'], true ) );
$info = CBX_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'crocoblock-exit' ) );
ok( 'updater: details with changelog', is_object( $info ) && ltrim( $newer, 'v' ) === $info->version && false !== strpos( $info->sections['changelog'], 'Bättre' ) && false !== strpos( $info->download_link, '.zip' ) );
ok( 'updater: other plugin details untouched', 'x' === CBX_Updater::info( 'x', 'plugin_information', (object) array( 'slug' => 'annat' ) ) );
$GLOBALS['_remote_map'] = array( $api => $rel( 'v' . CBX_VERSION ) );
CBX_Updater::forget();
$u = CBX_Updater::check( false, array(), $base, array() );
ok( 'updater: same version → no package (WordPress lists it as up to date)', is_array( $u ) && CBX_VERSION === $u['version'] && '' === $u['package'] );
$GLOBALS['_remote_map'] = array( $api => $rel( $newer, 'source.zip' ) );
CBX_Updater::forget();
ok( 'updater: release without the plugin zip is ignored', false === CBX_Updater::check( false, array(), $base, array() ) );
// API:t onåbart (t.ex. delad IP som slagit i anropstaket) → reservvägen via
// github.com:s omdirigering av /releases/latest används i stället.
$GLOBALS['_remote_map'] = array(
	$api => array( 'code' => 403, 'body' => '{"message":"rate limited"}' ),
	'https://github.com/daystudioab/crocoblock-exit/releases/latest' => array( 'code' => 302, 'body' => '', 'headers' => array( 'location' => 'https://github.com/daystudioab/crocoblock-exit/releases/tag/' . $newer ) ),
);
CBX_Updater::forget();
$u = CBX_Updater::check( false, array(), $base, array() );
ok( 'updater: api blocked → redirect fallback finds release', is_array( $u ) && ltrim( $newer, 'v' ) === $u['version'] && 'https://github.com/daystudioab/crocoblock-exit/releases/download/' . $newer . '/crocoblock-exit.zip' === $u['package'] );
$GLOBALS['_remote_map'] = array( $api => array( 'code' => 403, 'body' => '{"message":"rate limited"}' ) );
CBX_Updater::forget();
ok( 'updater: GitHub error → no update, no crash', false === CBX_Updater::check( false, array(), $base, array() ) && array( 'version' => '' ) === get_transient( CBX_Updater::CACHE ) );
$GLOBALS['_remote_map'] = array();
CBX_Updater::forget();

/* ---------- 8. Utan Pro ---------- */

$mapper_free = CBX_Plugin::mapper( array( 'has_pro' => false ) );
list( , $log ) = $mapper_free->convert_document( CBX_Plugin::get_data( $start ) );
$statuses = array_column( $log, 'status' );
ok( 'free: jet-posts manual', in_array( 'manual', $statuses, true ) );

/* ---------- Dölj tomma dynamiska fält (CBX_Empty) ---------- */

// En ikonlista med två punkter: en dynamisk som blev tom (ingen text, ingen
// länk) och en med innehåll. Bara den tomma tas bort.
$il = '<ul class="elementor-icon-list-items">'
	. '<li class="elementor-icon-list-item"><span class="elementor-icon-list-icon"><svg></svg></span><span class="elementor-icon-list-text"></span></li>'
	. '<li class="elementor-icon-list-item"><a href="https://exempel.se/fil.pdf"><span class="elementor-icon-list-icon"><svg></svg></span><span class="elementor-icon-list-text">Rapport</span></a></li>'
	. '</ul>';
$r = CBX_Empty::strip_items( $il, array( true, true ) );
ok( 'empty: tom dynamisk punkt tas bort, fylld behålls', 1 === $r['left'] && 1 === $r['removed'] && false !== strpos( $r['html'], 'Rapport' ) );

// Samma lista men utan dynamisk koppling på den tomma punkten: inget tas bort.
$r = CBX_Empty::strip_items( $il, array( false, true ) );
ok( 'empty: statiskt tom punkt lämnas i fred', 2 === $r['left'] && 0 === $r['removed'] );

// Tom punkt med riktig länk (ikon som länkar) behålls.
$il2 = '<ul><li class="elementor-icon-list-item"><a href="https://exempel.se"><span class="elementor-icon-list-text"></span></a></li></ul>';
$r = CBX_Empty::strip_items( $il2, array( true ) );
ok( 'empty: tom text med riktig länk behålls', 1 === $r['left'] && 0 === $r['removed'] );

// Alla punkter tomma → inget kvar.
$il3 = '<ul><li class="elementor-icon-list-item"><span class="elementor-icon-list-text"> </span></li></ul>';
$r = CBX_Empty::strip_items( $il3, array( true ) );
ok( 'empty: helt tom lista ger left=0', 0 === $r['left'] );

// prune_html: dold widget tas bort, och den inre sektionen (rutan med ram)
// följer med när inga widgets finns kvar – men sektionen med innehåll står kvar.
$page = '<section class="elementor-section elementor-element elementor-element-aaa111"><div class="elementor-column elementor-element elementor-element-bbb222"><div class="elementor-widget-wrap">'
	. '<section class="elementor-section elementor-inner-section elementor-element elementor-element-box001"><div class="elementor-column elementor-element elementor-element-col001"><div class="elementor-widget-wrap">'
	. '<div class="elementor-element elementor-element-wid001 elementor-widget elementor-widget-icon-list"><div class="elementor-widget-container"></div></div>'
	. '</div></div></section>'
	. '<div class="elementor-element elementor-element-wid002 elementor-widget elementor-widget-heading"><div class="elementor-widget-container"><h2>Kvar</h2></div></div>'
	. '</div></div></section>';
$out = CBX_Empty::prune_html( $page, array( 'wid001' ) );
ok( 'empty: dold widget och dess tomma ruta försvinner', false === strpos( $out, 'wid001' ) && false === strpos( $out, 'box001' ) && false === strpos( $out, 'col001' ) );
ok( 'empty: sektion med innehåll kvar', false !== strpos( $out, 'aaa111' ) && false !== strpos( $out, 'Kvar' ) );

// Svenska tecken överlever DOM-rundturen.
$out = CBX_Empty::prune_html( '<p>Åsa äter räksmörgås</p><div class="elementor-element elementor-element-zzz999 elementor-widget"></div>', array( 'zzz999' ) );
ok( 'empty: utf-8 bevaras vid prune', false !== strpos( $out, 'räksmörgås' ) && false === strpos( $out, 'zzz999' ) );

// Okänt id ändrar ingenting.
$same = '<div class="elementor-element elementor-element-abc123 elementor-widget"><p>Hej</p></div>';
ok( 'empty: okänt id lämnar html oförändrad', false !== strpos( CBX_Empty::prune_html( $same, array( 'ffffff' ) ), 'Hej' ) );

echo ( $fails ? "\n$fails av $count kontroller misslyckades\n" : "Alla $count integrationskontroller OK\n" );
exit( $fails ? 1 : 0 );
