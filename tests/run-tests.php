<?php
/**
 * Fristående tester för konverteringskärnan.
 * Kör: php tests/run-tests.php
 */
define( 'CBX_TESTING', true );
require __DIR__ . '/../includes/class-cbx-mapper.php';

$fails = 0;
$count = 0;
function check( $label, $cond ) {
	global $fails, $count;
	$count++;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL: $label\n";
	}
}
function w( $type, array $settings = array(), $id = 'abc1234' ) {
	return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
}
function doc( array $widgets, array $section_settings = array() ) {
	return array(
		array(
			'id' => 'sec0001', 'elType' => 'section', 'settings' => $section_settings, 'elements' => array(
				array( 'id' => 'col0001', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ), 'elements' => $widgets ),
			),
		),
	);
}
function first_widget( array $doc ) {
	return $doc[0]['elements'][0]['elements'][0];
}

$ctx = array(
	'has_pro'        => true,
	'term_tt_id'     => function ( $id ) { return $id + 1000; },
	'attachment_url' => function ( $id ) { return 42 === $id ? 'https://ex.se/fil.pdf' : ''; },
	'menu_slug'      => function ( $m ) { return 7 == $m ? 'huvudmeny' : (string) $m; },
	'listing_map'    => function ( $id ) { return 555 === $id ? array( 'id' => 900, 'post_type' => 'evenemang' ) : null; },
	'popup_map'      => function ( $id ) { return 77 === $id ? 1077 : null; },
);
$pro  = new CBX_Mapper( $ctx );
$free = new CBX_Mapper( array_merge( $ctx, array( 'has_pro' => false ) ) );

/* jet-posts med bara standardvärden (som på tidningenm.se startsida) */
list( $out, $log ) = $pro->convert_document( doc( array( w( 'jet-posts', array( '_margin' => array( 'top' => '10' ) ) ) ) ) );
$wd = first_widget( $out );
check( 'jet-posts → posts', 'posts' === $wd['widgetType'] );
check( 'jet-posts default number 3', 3 === $wd['settings']['classic_posts_per_page'] );
check( 'jet-posts default columns 3', '3' === $wd['settings']['classic_columns'] );
check( 'jet-posts tablet/mobile inherit desktop', '3' === $wd['settings']['classic_columns_tablet'] && '3' === $wd['settings']['classic_columns_mobile'] );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-posts', array( 'columns' => '1' ) ) ) ) );
check( 'jet-posts 1 col stays 1 on tablet (tidningenm.se)', '1' === first_widget( $out )['settings']['classic_columns_tablet'] && '1' === first_widget( $out )['settings']['classic_columns_mobile'] );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-posts', array( 'columns' => '4', 'columns_tablet' => '2' ) ) ) ) );
check( 'jet-posts mobile inherits tablet', '2' === first_widget( $out )['settings']['classic_columns_mobile'] );
check( 'jet-posts row gap 20 (Jet column padding 10)', 20 === $wd['settings']['classic_row_gap']['size'] && 20 === $wd['settings']['classic_column_gap']['size'] );
check( 'jet-posts outer padding', '10' === $wd['settings']['_padding']['top'] && '10' === $wd['settings']['_padding']['bottom'] && 'px' === $wd['settings']['_padding']['unit'] );
check( 'jet-posts padding no empty sides', '0' === $wd['settings']['_padding']['left'] && '0' === $wd['settings']['_padding']['right'] );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-posts', array( 'column_padding' => array( 'unit' => 'px', 'top' => '0', 'right' => '15', 'bottom' => '25', 'left' => '15' ), 'column_padding_mobile' => array( 'unit' => 'px', 'top' => '5', 'right' => '0', 'bottom' => '5', 'left' => '0' ), '_padding' => array( 'unit' => 'px', 'top' => '4', 'right' => '2', 'bottom' => '0', 'left' => '2' ) ) ) ) ) );
$g = first_widget( $out )['settings'];
check( 'jet-posts custom gaps', 25 === (int) $g['classic_row_gap']['size'] && 30 === (int) $g['classic_column_gap']['size'] && 10 === (int) $g['classic_row_gap_mobile']['size'] && ! isset( $g['classic_row_gap_tablet'] ) );
check( 'jet-posts padding added to own', '4' === $g['_padding']['top'] && '25' === $g['_padding']['bottom'] && '2' === $g['_padding']['left'] );
check( 'jet-posts default image on', 'top' === $wd['settings']['classic_thumbnail'] );
check( 'jet-posts default meta all', array( 'author', 'date', 'comments' ) === $wd['settings']['classic_meta_data'] );
check( 'jet-posts keeps _margin', isset( $wd['settings']['_margin'] ) );
check( 'jet-posts post type post', 'post' === $wd['settings']['posts_post_type'] );
check( 'log converted', 'converted' === $log[0]['status'] );

/* jet-posts med kategori och avstängd bild */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-posts', array( 'number' => 8, 'posts_query' => 'category', 'post_cat' => array( '5', '9' ), 'show_image' => '', 'show_meta' => '', 'columns' => '2', 'columns_mobile' => '1' ) ) ) ) );
$wd = first_widget( $out );
check( 'cat include', array( 'terms' ) === $wd['settings']['posts_include'] );
check( 'cat tt ids', array( 1005, 1009 ) === $wd['settings']['posts_include_term_ids'] );
check( 'image off', 'none' === $wd['settings']['classic_thumbnail'] );
check( 'meta off', array() === $wd['settings']['classic_meta_data'] );
check( 'mobile cols', '1' === $wd['settings']['classic_columns_mobile'] );

/* jet-posts by id */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-posts', array( 'posts_query' => 'ids', 'post_ids' => '12, 13,x' ) ) ) ) );
$wd = first_widget( $out );
check( 'by_id', 'by_id' === $wd['settings']['posts_post_type'] && array( 12, 13 ) === $wd['settings']['posts_posts_ids'] );

/* Utan Pro blir jet-posts manuell och orörd */
list( $out, $log ) = $free->convert_document( doc( array( w( 'jet-posts' ) ) ) );
check( 'free jet-posts untouched', 'jet-posts' === first_widget( $out )['widgetType'] );
check( 'free jet-posts manual', 'manual' === $log[0]['status'] );

/* jet-tabs: mall-innehåll (standard) och editor-innehåll */
list( $out, $log ) = $pro->convert_document( doc( array( w( 'jet-tabs', array( 'tabs' => array(
	array( '_id' => 'a', 'item_label' => 'Ett', 'item_template_id' => '123' ),
	array( '_id' => 'b', 'item_label' => 'Två', 'content_type' => 'editor', 'item_editor_content' => '<p>Hej</p>' ),
) ) ) ) ) );
$wd = first_widget( $out );
check( 'tabs type', 'tabs' === $wd['widgetType'] );
check( 'tab template shortcode', '[elementor-template id="123"]' === $wd['settings']['tabs'][0]['tab_content'] );
check( 'tab editor', '<p>Hej</p>' === $wd['settings']['tabs'][1]['tab_content'] );
check( 'tab titles', 'Två' === $wd['settings']['tabs'][1]['tab_title'] );
check( 'tab _id', ! empty( $wd['settings']['tabs'][0]['_id'] ) );

/* jet-accordion collapsible → toggle */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-accordion', array( 'collapsible' => 'true', 'toggles' => array( array( 'item_label' => 'Q', 'content_type' => 'editor', 'item_editor_content' => 'A' ) ) ) ) ) ) );
$wd = first_widget( $out );
check( 'accordion collapsible → toggle', 'toggle' === $wd['widgetType'] && 'A' === $wd['settings']['tabs'][0]['tab_content'] );

/* jet-button */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-button', array( 'button_label_normal' => 'Prenumerera', 'button_url' => array( 'url' => '/prenumeration/', 'is_external' => '' ) ) ) ) ) );
$wd = first_widget( $out );
check( 'button text', 'button' === $wd['widgetType'] && 'Prenumerera' === $wd['settings']['text'] && '/prenumeration/' === $wd['settings']['link']['url'] );

/* jet-headline defaults */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-headline', array( 'first_part' => 'Välkommen', 'second_part' => 'hem' ) ) ) ) );
$wd = first_widget( $out );
check( 'headline', 'heading' === $wd['widgetType'] && 'Välkommen hem' === $wd['settings']['title'] && 'h2' === $wd['settings']['header_size'] );

/* jet-table */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-table', array(
	'table_header' => array( array( 'cell_text' => 'Namn' ), array( 'cell_text' => 'Pris' ) ),
	'table_body'   => array( array( 'action' => 'row' ), array( 'action' => 'cell', 'cell_text' => 'Kaffe' ), array( 'action' => 'cell', 'cell_text' => '25' ), array( 'action' => 'row' ), array( 'action' => 'cell', 'cell_text' => 'Te', 'col_span' => 2 ) ),
) ) ) ) );
$wd = first_widget( $out );
check( 'table html', false !== strpos( $wd['settings']['editor'], '<thead><tr><th>Namn</th><th>Pris</th></tr></thead><tbody><tr><td>Kaffe</td><td>25</td></tr><tr><td colspan="2">Te</td></tr></tbody>' ) );

/* jet-listing-grid med och utan migrerad listning (stavfelet lisitng_id) */
list( $out, $log ) = $pro->convert_document( doc( array( w( 'jet-listing-grid', array( 'lisitng_id' => '555', 'posts_num' => 9 ) ) ) ) );
$wd = first_widget( $out );
check( 'listing → loop-grid', 'loop-grid' === $wd['widgetType'] && 900 === $wd['settings']['template_id'] && 9 === $wd['settings']['posts_per_page'] && 'evenemang' === $wd['settings']['post_query_post_type'] );
list( $out, $log ) = $pro->convert_document( doc( array( w( 'jet-listing-grid', array( 'lisitng_id' => '556' ) ) ) ) );
check( 'unmigrated listing manual', 'manual' === $log[0]['status'] && 'jet-listing-grid' === first_widget( $out )['widgetType'] );

/* Dynamiskt fält: titel (standard) och metafält */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-field', array() ) ) ), 'listing' );
$wd = first_widget( $out );
check( 'dyn title', 'heading' === $wd['widgetType'] && false !== strpos( $wd['settings']['__dynamic__']['title'], 'name="post-title"' ) );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-field', array( 'dynamic_field_source' => 'meta', 'dynamic_field_post_meta' => 'plats' ) ) ) ) );
$wd  = first_widget( $out );
$tag = $wd['settings']['__dynamic__']['editor'];
preg_match( '/settings="([^"]*)"/', $tag, $m );
check( 'dyn meta key', array( 'key' => 'plats' ) === json_decode( urldecode( $m[1] ), true ) );

/* Dynamisk bild & länk */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-image', array( 'linked_image' => 'yes' ) ) ) ) );
$wd = first_widget( $out );
check( 'dyn image', 'image' === $wd['widgetType'] && false !== strpos( $wd['settings']['__dynamic__']['image'], 'post-featured-image' ) && false !== strpos( $wd['settings']['__dynamic__']['link'], 'post-url' ) );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-link', array() ) ) ) );
check( 'dyn link', false !== strpos( first_widget( $out )['settings']['__dynamic__']['link'], 'post-url' ) );

/* Sektionstillägg: sticky + parallax + övriga jet_-nycklar */
list( $out, $log ) = $pro->convert_document( doc( array( w( 'heading', array( 'title' => 'x' ) ) ), array( 'jet_sticky_section' => 'yes', 'jet_parallax_layout_list' => array( array( 'x' => 1 ) ), 'jet_sticky_section_z_index' => 99, 'background_color' => '#fff' ) ) );
$sec = $out[0]['settings'];
check( 'sticky mapped', 'top' === $sec['sticky'] );
check( 'jet keys removed', ! isset( $sec['jet_sticky_section'] ) && ! isset( $sec['jet_parallax_layout_list'] ) && ! isset( $sec['jet_sticky_section_z_index'] ) );
check( 'other kept', '#fff' === $sec['background_color'] );
check( 'heading untouched', 'heading' === first_widget( $out )['widgetType'] );

/* Popup-koppling på knapp */
list( $out ) = $pro->convert_document( doc( array( w( 'button', array( 'text' => 'Öppna', 'jet_attached_popup' => '77', 'jet_trigger_type' => 'click-self' ) ) ) ) );
$wd = first_widget( $out );
check( 'popup tag', false !== strpos( $wd['settings']['__dynamic__']['link'], 'name="popup"' ) && false !== strpos( urldecode( $wd['settings']['__dynamic__']['link'] ), '"popup":"1077"' ) );
check( 'popup keys removed', ! isset( $wd['settings']['jet_attached_popup'] ) );

/* JetEngine-tagg i vanlig widget */
$tag = '[elementor-tag id="1a2b3c4" name="jet-post-custom-field" settings="' . urlencode( json_encode( array( 'meta_field' => 'pris' ) ) ) . '"]';
list( $out, $log ) = $pro->convert_document( doc( array( w( 'heading', array( 'title' => 'x', '__dynamic__' => array( 'title' => $tag ) ) ) ) ) );
$wd = first_widget( $out );
check( 'jet tag converted', false !== strpos( $wd['settings']['__dynamic__']['title'], 'name="post-custom-field"' ) );
check( 'dynamic log', 'dynamic' === $log[0]['status'] );
$tag2 = '[elementor-tag id="1a2b3c4" name="jet-macros" settings="%7B%7D"]';
list( $out ) = $pro->convert_document( doc( array( w( 'heading', array( 'title' => 'x', '__dynamic__' => array( 'title' => $tag2 ) ) ) ) ) );
check( 'unknown jet tag removed', ! isset( first_widget( $out )['settings']['__dynamic__'] ) );

/* Okänd/omappad Jet-widget lämnas orörd men loggas */
list( $out, $log ) = $pro->convert_document( doc( array( w( 'jet-smart-filters-checkboxes', array( 'filter_id' => 3 ) ) ) ) );
check( 'filters manual', 'manual' === $log[0]['status'] && false !== strpos( $log[0]['notes'][0], 'Taxonomy Filter' ) );
check( 'filters untouched', 'jet-smart-filters-checkboxes' === first_widget( $out )['widgetType'] );

/* Inget Crocoblock = ingen ändring */
$plain = doc( array( w( 'heading', array( 'title' => 'x' ) ) ) );
list( $out, $log ) = $pro->convert_document( $plain );
check( 'no change', $out === $plain && array() === $log && ! CBX_Mapper::log_has_changes( $log ) );

/* Nästlad jet-widget i inner section + common __dynamic__ behålls */
$inner = doc( array( array( 'id' => 'inn0001', 'elType' => 'section', 'isInner' => true, 'settings' => array(), 'elements' => array(
	array( 'id' => 'col0002', 'elType' => 'column', 'settings' => array(), 'elements' => array( w( 'jet-progress-bar', array( 'percent' => 70, '__globals__' => array( '_background_color' => 'globals/colors?id=primary' ) ), 'deep001' ) ) ),
) ) ) );
list( $out ) = $pro->convert_document( $inner );
$deep = $out[0]['elements'][0]['elements'][0]['elements'][0]['elements'][0];
check( 'nested converted', 'progress' === $deep['widgetType'] && 70 === $deep['settings']['percent']['size'] );
check( 'globals kept', 'globals/colors?id=primary' === $deep['settings']['__globals__']['_background_color'] );

/* Hela mappningsuppsättningen ska köra utan PHP-fel med tomma inställningar */
$all = array( 'jet-animated-box', 'jet-animated-text', 'jet-audio', 'jet-banner', 'jet-brands', 'jet-button', 'jet-carousel', 'jet-circle-progress', 'jet-countdown-timer', 'jet-download-button', 'jet-headline', 'jet-images-layout', 'jet-inline-svg', 'jet-map', 'jet-portfolio', 'jet-posts', 'jet-price-list', 'jet-pricing-table', 'jet-progress-bar', 'jet-services', 'jet-slider', 'jet-table', 'jet-team-member', 'jet-testimonials', 'jet-timeline', 'jet-video', 'jet-tabs', 'jet-accordion', 'jet-switcher', 'jet-unfold', 'jet-logo', 'jet-nav-menu', 'jet-custom-menu', 'jet-mega-menu', 'jet-mobile-menu', 'jet-search', 'jet-ajax-search', 'jet-login', 'jet-blocks-cart', 'jet-blog-smart-listing', 'jet-blog-smart-tiles', 'jet-blog-posts-navigation', 'jet-popup-action-button', 'jet-listing-grid', 'jet-listing-dynamic-field', 'jet-listing-dynamic-image', 'jet-listing-dynamic-link', 'jet-listing-dynamic-terms', 'jet-listing-dynamic-meta', 'jet-weather', 'jet_single' );
foreach ( array( $pro, $free ) as $mapper ) {
	foreach ( $all as $type ) {
		set_error_handler( function ( $no, $str, $file, $line ) use ( $type ) { throw new ErrorException( "$type: $str ($line)" ); } );
		try {
			list( $out, $log ) = $mapper->convert_document( doc( array( w( $type ) ) ) );
			check( "$type has log", count( $log ) >= 1 );
			$wd = first_widget( $out );
			if ( 'converted' === $log[0]['status'] ) {
				check( "$type json", false !== json_encode( $out ) );
				check( "$type target not jet", ! CBX_Mapper::is_jet_type( $wd['widgetType'] ) );
			}
		} catch ( Throwable $e ) {
			check( 'no error: ' . $e->getMessage(), false );
		}
		restore_error_handler();
	}
}

/* Konkreta värden för några till */
list( $out ) = $pro->convert_document( doc( array( w( 'jet-download-button', array( 'download_file' => '42' ) ) ) ) );
check( 'download url', 'https://ex.se/fil.pdf' === first_widget( $out )['settings']['link']['url'] );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-nav-menu', array( 'nav_menu' => '7' ) ) ) ) );
check( 'menu slug', 'huvudmeny' === first_widget( $out )['settings']['menu'] );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-countdown-timer', array( 'due_date' => '2026-12-24 15:00', 'show_sec' => '' ) ) ) ) );
$wd = first_widget( $out );
check( 'countdown', '2026-12-24 15:00' === $wd['settings']['due_date'] && '' === $wd['settings']['show_seconds'] && 'yes' === $wd['settings']['show_days'] );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-circle-progress', array( 'percent_value' => array( 'unit' => '%', 'size' => 80 ) ) ) ) ) );
check( 'circle 80', 80 === first_widget( $out )['settings']['ending_number'] );

/* ACF-fält i dynamiskt fält → Elementor Pros ACF-tagg */
list( $out, $log ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-field', array( 'dynamic_field_source' => 'acf_field_groups', 'acf_field_key' => 'field_6151daf54a86a::geografiskt_omrade', 'field_tag' => 'div' ) ) ) ) );
$wd = first_widget( $out );
check( 'acf field → text-editor with acf-text tag', 'text-editor' === $wd['widgetType'] && false !== strpos( $wd['settings']['__dynamic__']['editor'], 'name="acf-text"' ) );
check( 'acf key uses single colon', false !== strpos( urldecode( $wd['settings']['__dynamic__']['editor'] ), 'field_6151daf54a86a:geografiskt_omrade' ) && false === strpos( urldecode( $wd['settings']['__dynamic__']['editor'] ), '::' ) );
list( $out ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-field', array( 'dynamic_field_source' => 'acf_field_groups', 'acf_field_key' => 'field_1::rubrik', 'field_tag' => 'h3' ) ) ) ) );
check( 'acf field with heading tag', 'heading' === first_widget( $out )['settings']['header_size'] ? false : 'h3' === first_widget( $out )['settings']['header_size'] && 'heading' === first_widget( $out )['widgetType'] );
list( , $log ) = $pro->convert_document( doc( array( w( 'jet-listing-dynamic-field', array( 'dynamic_field_source' => 'acf_field_groups' ) ) ) ) );
check( 'acf field without key stays manual', 'manual' === $log[0]['status'] );

/* Egen WP_Query (JSON) → Query ID */
$registered = array();
$pro_q      = new CBX_Mapper( array( 'has_pro' => true, 'register_query' => function ( array $a ) use ( &$registered ) { $registered[] = $a; return 'cbx_test'; } ) );
$json       = '{"post_type":"post","posts_per_page":4,"category_name":"debatt","orderby":"date"}';
list( $out, $log ) = $pro_q->convert_document( doc( array( w( 'jet-posts', array( 'use_custom_query' => 'true', 'custom_query' => $json, 'post_cat' => array( '5' ), 'posts_query' => 'category' ) ) ) ) );
$wd = first_widget( $out );
check( 'custom query → query id', 'cbx_test' === $wd['settings']['posts_query_id'] );
check( 'custom query registered', array( 'post_type' => 'post', 'posts_per_page' => 4, 'category_name' => 'debatt', 'orderby' => 'date' ) === $registered[0] );
check( 'custom query per page', 4 === $wd['settings']['classic_posts_per_page'] );
check( 'custom query drops widget terms', ! isset( $wd['settings']['posts_include_term_ids'] ) && 'post' === $wd['settings']['posts_post_type'] );
check( 'custom query converted not manual', 'converted' === $log[0]['status'] );
list( $out, $log ) = $pro_q->convert_document( doc( array( w( 'jet-blog-smart-listing', array( 'use_custom_query' => 'yes', 'custom_query' => '{"post_type":"nyhet","posts_per_page":6}' ) ) ) ) );
check( 'smart listing custom query', 'cbx_test' === first_widget( $out )['settings']['posts_query_id'] && 'nyhet' === first_widget( $out )['settings']['posts_post_type'] );
list( $out ) = $pro_q->convert_document( doc( array( w( 'jet-posts', array( 'use_custom_query' => 'true', 'custom_query' => '{"post_type":"post","category_name":"kronikor"}' ) ) ) ) );
check( 'custom query without per page → site default', 10 === first_widget( $out )['settings']['classic_posts_per_page'] );
list( $out, $log ) = $pro_q->convert_document( doc( array( w( 'jet-posts', array( 'use_custom_query' => 'true', 'custom_query' => '{trasig' ) ) ) ) );
check( 'broken custom query noted', ! isset( first_widget( $out )['settings']['posts_query_id'] ) && false !== strpos( implode( ' ', $log[0]['notes'] ), 'inte gick att läsa' ) );

echo ( $fails ? "\n$fails av $count kontroller misslyckades\n" : "Alla $count kontroller OK\n" );
exit( $fails ? 1 : 0 );
