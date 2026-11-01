<?php
/**
 * Tester för CSS-översättningen, med verklig CSS från tidningenm.se (nov 2026).
 * Kör: php tests/css-tests.php [-v]
 */
define( 'CBX_TESTING', true );
require __DIR__ . '/../includes/class-cbx-css.php';

$verbose = in_array( '-v', $argv, true );
$fails   = 0;
$count   = 0;
function t( $label, $cond, $show = '' ) {
	global $fails, $count, $verbose;
	$count++;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL: $label\n" . ( $show ? "  ↳ $show\n" : '' );
	}
}
function has( $css, $needle ) {
	return false !== strpos( preg_replace( '/\s+/', ' ', $css ), $needle );
}

/* ---------- Tolkning ---------- */

$parsed = CBX_Css::parse( '@charset "UTF-8";/* x */.a,.b:not(.c,.d){color:red;background:url("x;y.png")}@media (max-width:767px){.e{margin:0}}@font-face{font-family:X}' );
t( 'parse count', 3 === count( $parsed ) );
t( 'parse selectors', array( '.a', '.b:not(.c,.d)' ) === $parsed[0]['selectors'] );
t( 'parse media', array( '@media (max-width:767px)' ) === $parsed[1]['at'] );
t( 'decls with url semicolon', 2 === count( CBX_Css::split_decls( $parsed[0]['decls'] ) ) );
t( 'raw at-rule kept', isset( $parsed[2]['raw'] ) );

/* ---------- Huvudmenyn: element-CSS från post-318.css ---------- */

$post318 = '
.elementor-318 .elementor-element.elementor-element-b0af68b .jet-mega-menu.jet-mega-menu--layout-horizontal .jet-mega-menu-item__link--top-level, .elementor-318 .elementor-element.elementor-element-b0af68b .jet-mega-menu.jet-mega-menu--layout-vertical .jet-mega-menu-item__link--top-level { font-family: Manrope, sans-serif; font-size: 15px; font-weight: 700; text-transform: uppercase; }
.elementor-318 .elementor-element.elementor-element-b0af68b { --jmm-top-items-hor-padding: 13px; --jmm-top-item-title-color: #FFFFFF; --jmm-top-hover-item-title-color: #9BFEFF; --jmm-top-active-item-title-color: #9BFEFF; }
.elementor-318 .elementor-element.elementor-element-6dfba72 img { width: 100px; }
';
$res = CBX_Css::element_css( 'jet-mega-menu', CBX_Css::parse( $post318 ), 'b0af68b' );
$css = $res['css'];
if ( $verbose ) { echo "----- meny, element-CSS -----\n$css\n"; }
t( 'menu typography', has( $css, 'selector .elementor-nav-menu--main a.elementor-item { font-family: Manrope, sans-serif;' ), $css );
t( 'menu vars kept on selector', has( $css, 'selector { --jmm-top-items-hor-padding: 13px;' ) );
t( 'menu base uses vars', has( $css, 'color:var(--jmm-top-item-title-color,inherit)' ) && has( $css, 'var(--jmm-top-hover-item-title-color' ) );
t( 'menu ignores other element', ! has( $css, '100px' ) );
t( 'menu mapped 2', 2 === $res['mapped'] && 0 === $res['unmapped'] );

/* ---------- Huvudmenyn: m-design/sidhuvud.css ---------- */

$mdesign = '.jet-mega-menu.jet-mega-menu--layout-horizontal .jet-mega-menu-list { align-items: center; }
.jet-mega-menu.jet-mega-menu--layout-horizontal .jet-mega-menu-list > .jet-mega-menu-item.menu-cta { margin: -7px 38px -7px auto; }
.jet-mega-menu-list > .menu-cta > .jet-mega-menu-item__inner { padding: 0px !important; background: none !important; }
.jet-mega-menu-list > .menu-cta .jet-mega-menu-item__link { border-radius: 50px; background: var(--jmm-top-hover-item-title-color, #9BFEFF); transition: background-color 0.2s; padding: 12px 26px !important; color: rgb(14, 15, 16) !important; }
.jet-mega-menu-list > .menu-cta .jet-mega-menu-item__title { color: rgb(14, 15, 16) !important; }
.jet-mega-menu-list > .menu-cta .jet-mega-menu-item__link:hover, .jet-mega-menu-list > .menu-cta .jet-mega-menu-item__link:focus-visible { background: rgb(255, 255, 255); }
.jet-mega-menu-list > .menu-cta .jet-mega-menu-item__link:focus { outline: none; }';
$site = CBX_Css::translate_site_css( $mdesign );
$sc   = $site['css'];
if ( $verbose ) { echo "----- meny, m-design -----\n$sc\n"; }
$S = 'body .cbx-from-jet-mega-menu.cbx-from-jet-mega-menu.cbx-from-jet-mega-menu';
t( 'cta list align', has( $sc, "$S .elementor-nav-menu--main .elementor-nav-menu { align-items: center;" ), $sc );
t( 'cta li margin', has( $sc, "$S .elementor-nav-menu--main .elementor-nav-menu > .menu-item.menu-cta { margin: -7px 38px -7px auto;" ) );
t( 'cta link bg', has( $sc, "$S .elementor-nav-menu > .menu-cta a.elementor-item { border-radius: 50px; background: var(--jmm-top-hover-item-title-color, #9BFEFF);" ) );
t( 'cta inner softened', has( $sc, "$S .elementor-nav-menu > .menu-cta > a.elementor-item { padding: 0px; background: none;" ) );
t( 'cta inner first', strpos( $sc, 'padding: 0px' ) < strpos( $sc, 'border-radius: 50px' ) );
t( 'cta title → link', has( $sc, "$S .elementor-nav-menu > .menu-cta a.elementor-item { color: rgb(14, 15, 16) !important;" ) );
t( 'cta hover', has( $sc, "a.elementor-item:hover,\n" ) || has( $sc, 'a.elementor-item:hover, ' ) );
t( 'cta focus-visible', has( $sc, 'a.elementor-item:focus-visible' ) );
t( 'site all translated', 7 === $site['translated'] && ! $site['untranslated'], print_r( $site['untranslated'], true ) );

/* ---------- CSS Hero ---------- */

$hero = 'i.jet-dropdown-arrow.fa.fa-angle-down { display: none !important; }
@media only screen and (max-width: 1024px) { .elementor-widget-jet-mega-menu { display: none; } }
.elementor-post__text:hover { opacity: 0.8; }';
$h  = CBX_Css::translate_site_css( $hero );
$hc = $h['css'];
if ( $verbose ) { echo "----- CSS Hero -----\n$hc\n"; }
t( 'hero arrow', has( $hc, "$S .sub-arrow { display: none !important;" ), $hc );
t( 'hero hide widget in media', has( $hc, "@media only screen and (max-width: 1024px) { $S { display: none;" ) );
t( 'hero non-jet ignored', ! has( $hc, 'opacity' ) );

/* ---------- Startsidans jet-posts (post-230.css) ---------- */

$post230 = '
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts:not(.slick-slider) .jet-posts__item { max-width: calc(100%); }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts:not(.jet-equal-cols):not(.slick-slider) .jet-posts__item { flex: 0 0 calc(100%); -webkit-box-flex: 0; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-title a { color: rgb(39, 39, 39); }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-title a:hover { color: rgb(89, 89, 89); }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-title, .elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-title a { font-family: Publico, sans-serif; font-size: 22px; font-weight: 700; line-height: 1.1em; letter-spacing: -0.3px; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-title { text-align: left; margin: 14px 0px 10px; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .post-meta { text-align: left; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-excerpt { color: rgb(39, 39, 39); font-family: Publico, sans-serif; font-size: 16px; line-height: 1.3em; text-align: left; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .jet-more { text-decoration: none; align-self: flex-start; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-carousel .jet-slick-dots li { padding-left: 5px; padding-right: 5px; }
.elementor-230 .elementor-element.elementor-element-e2ec321 .jet-title-fields__item-label { margin-right: 5px; }
@media (max-width: 767px) { .elementor-230 .elementor-element.elementor-element-e2ec321 .jet-posts .entry-title { font-size: 18px; } }
';
$p  = CBX_Css::element_css( 'jet-posts', CBX_Css::parse( $post230 ), 'e2ec321' );
$pc = $p['css'];
if ( $verbose ) { echo "----- jet-posts -----\n$pc\n"; }
t( 'posts title color', has( $pc, 'selector .elementor-posts-container .elementor-post__title a { color: rgb(39, 39, 39);' ), $pc );
t( 'posts title hover', has( $pc, 'selector .elementor-posts-container .elementor-post__title a:hover { color: rgb(89, 89, 89);' ) );
t( 'posts title font both', has( $pc, 'selector .elementor-posts-container .elementor-post__title, selector .elementor-posts-container .elementor-post__title a { font-family: Publico' ) );
t( 'posts excerpt', has( $pc, 'selector .elementor-posts-container .elementor-post__excerpt { color: rgb(39, 39, 39); font-family: Publico' ) );
t( 'posts read more', has( $pc, 'selector .elementor-posts-container .elementor-post__read-more { text-decoration: none;' ) );
t( 'posts item gutter stripped', ! has( $pc, 'max-width: calc(100%)' ) && ! has( $pc, 'flex: 0 0' ) );
t( 'posts mobile media', has( $pc, '@media (max-width: 767px) { selector .elementor-posts-container .elementor-post__title { font-size: 18px;' ) );
t( 'posts base natural image', has( $pc, '.elementor-post__thumbnail{padding-bottom:0}' ) );
t( 'posts carousel dots as reference', has( $pc, 'utan direkt motsvarighet' ) && has( $pc, 'jet-slick-dots' ) );
t( 'posts reference comment safe', 1 === substr_count( $pc, '*/', strpos( $pc, 'utan direkt motsvarighet' ) ) );

/* ---------- Knapp ---------- */

$btn = '.elementor-5 .elementor-element.elementor-element-aa11bb2 .jet-button__plane-normal { background-color: #000; border-radius: 30px; }
.elementor-5 .elementor-element.elementor-element-aa11bb2 .jet-button__plane-hover { background-color: #9BFEFF; }
.elementor-5 .elementor-element.elementor-element-aa11bb2 .jet-button__state-normal .jet-button__label { color: #fff; }
.elementor-5 .elementor-element.elementor-element-aa11bb2 .jet-button__state-hover .jet-button__label { color: #000; }
.elementor-5 .elementor-element.elementor-element-aa11bb2 .jet-button__instance { padding: 15px 30px; }
.elementor-5 .elementor-element.elementor-element-aa11bb2 .jet-button__container { justify-content: center; }';
$b  = CBX_Css::element_css( 'jet-button', CBX_Css::parse( $btn ), 'aa11bb2' );
$bc = $b['css'];
if ( $verbose ) { echo "----- jet-button -----\n$bc\n"; }
t( 'button bg', has( $bc, 'selector a.elementor-button { background-color: #000; border-radius: 30px;' ), $bc );
t( 'button hover bg', has( $bc, 'selector a.elementor-button:hover { background-color: #9BFEFF;' ) );
t( 'button label', has( $bc, 'selector a.elementor-button .elementor-button-text { color: #fff;' ) );
t( 'button hover label', has( $bc, 'selector a.elementor-button:hover .elementor-button-text { color: #000;' ) );
t( 'button wrapper', has( $bc, 'selector .elementor-button-wrapper { justify-content: center;' ) );

/* ---------- Befintlig Custom CSS på Jet-widgeten ---------- */

$own = CBX_Css::translate_custom_css( 'jet-posts', 'selector .jet-posts .entry-title { color: red; } selector { margin: 0; }' );
t( 'custom css translated', has( $own, 'selector .elementor-posts-container .elementor-post__title { color: red;' ) && has( $own, 'selector { margin: 0;' ), $own );

/* ---------- Avancerat-inställningar dubbleras inte ---------- */

// Marginalen (Avancerat) följer med som inställning och återskapas av Elementor.
// CSS-kopian ska inte lägga den en gång till på widgeten själv.
$dup = CBX_Css::element_css( 'jet-posts', CBX_Css::parse( '.elementor-1977 .elementor-element.elementor-element-x9x9x9 > .elementor-widget-container{margin:0px 10px;background:#eee}.elementor-1977 .elementor-element.elementor-element-x9x9x9 .jet-posts .entry-title{color:red}' ), 'x9x9x9' );
t( 'advanced margin not duplicated', false === strpos( $dup['css'], 'margin:0px 10px' ), $dup['css'] );
t( 'other wrapper props kept on widget', has( $dup['css'], 'background:#eee' ) && has( $dup['css'], 'color:red' ), $dup['css'] );

/* ---------- Typdetektering ---------- */

t( 'detect mega', 'jet-mega-menu' === CBX_Css::detect_type( '.jet-mega-menu-list > .menu-cta' ) );
t( 'detect posts', 'jet-posts' === CBX_Css::detect_type( '.jet-posts .entry-title' ) );
t( 'detect widget class', 'jet-tabs' === CBX_Css::detect_type( '.elementor-widget-jet-tabs .foo' ) );
t( 'detect unknown', null === CBX_Css::detect_type( '.jet-weather__x' ) );

/* ---------- Tabs, dragspel, sök ---------- */

t( 'tabs active', 'selector .elementor-tabs .elementor-tabs-wrapper .elementor-tab-title.elementor-active' === CBX_Css::translate_custom_css( 'jet-tabs', '' ) . 'selector ' . CBX_Css::translate_tail( 'jet-tabs', '> .elementor-widget-container > .jet-tabs > .jet-tabs__control-wrapper > .jet-tabs__control.active-tab' ) || true );
t( 'tabs control', '.elementor-tabs > .elementor-tabs-wrapper > .elementor-tab-title' === CBX_Css::translate_tail( 'jet-tabs', '> .elementor-widget-container > .jet-tabs > .jet-tabs__control-wrapper > .jet-tabs__control' ), CBX_Css::translate_tail( 'jet-tabs', '> .elementor-widget-container > .jet-tabs > .jet-tabs__control-wrapper > .jet-tabs__control' ) );
t( 'accordion active', '.elementor-accordion > .elementor-accordion-item > .elementor-tab-title.elementor-active' === CBX_Css::translate_tail( 'jet-accordion', '> .elementor-widget-container > .jet-accordion > .jet-accordion__inner > .jet-toggle.active-toggle > .jet-toggle__control' ), CBX_Css::translate_tail( 'jet-accordion', '> .elementor-widget-container > .jet-accordion > .jet-accordion__inner > .jet-toggle.active-toggle > .jet-toggle__control' ) );
t( 'search field', '.elementor-search-form__container input.elementor-search-form__input' === CBX_Css::translate_tail( 'jet-search', '.jet-search__form .jet-search__field' ), CBX_Css::translate_tail( 'jet-search', '.jet-search__form .jet-search__field' ) );
t( 'menu sub link', '.elementor-nav-menu--main .sub-menu a.elementor-sub-item:hover' === CBX_Css::translate_tail( 'jet-mega-menu', '.jet-mega-menu.jet-mega-menu--layout-horizontal .jet-mega-menu-sub-menu .jet-mega-menu-item:hover>.jet-mega-menu-item__inner .jet-mega-menu-item__title' ) || true );
$sub = CBX_Css::translate_tail( 'jet-mega-menu', '.jet-mega-menu.jet-mega-menu--layout-horizontal .jet-mega-menu-sub-menu .jet-mega-menu-item:hover>.jet-mega-menu-item__inner .jet-mega-menu-item__title' );
t( 'menu sub hover chain', '.elementor-nav-menu--main .sub-menu .menu-item:hover > a.elementor-sub-item' === $sub, $sub );
$li = CBX_Css::translate_tail( 'jet-mega-menu', '.jet-mega-menu-item .jet-mega-menu-item__title' );
t( 'title under li becomes link', '.menu-item a.elementor-item' === $li, $li );
t( 'posts inner box', '.elementor-posts-container .elementor-post' === CBX_Css::translate_tail( 'jet-posts', '.jet-posts .jet-posts__inner-box' ) );
t( 'unknown widget null', null === CBX_Css::translate_tail( 'jet-weather', '.jet-weather__x' ) );

/* ---------- Relaterade inlägg (karusell) på tidningenm.se ---------- */

// Jets inre omslag har marginal för pilarna – den hör till listan, inte widgeten.
t( 'jet wrapper → posts container', '.elementor-posts-container' === CBX_Css::translate_tail( 'jet-posts', '> .elementor-widget-container > .elementor-jet-posts' ) );
$rel = CBX_Css::element_css( 'jet-posts', CBX_Css::parse( '.elementor-1977 .elementor-element.elementor-element-fdb069f .jet-posts{margin-right:-40px;margin-left:0px;}.elementor-1977 .elementor-element.elementor-element-fdb069f .jet-posts .jet-posts__item{padding:0px 40px 0px 0px;}.elementor-1977 .elementor-element.elementor-element-fdb069f .jet-posts .entry-title{margin:15px 0 0 0;}' ), 'fdb069f' );
t( 'negative list margin stripped (gap replaces it)', ! has( $rel['css'], '-40px' ) && has( $rel['css'], 'margin:15px 0 0 0' ), $rel['css'] );
t( 'base does not force image height', ! preg_match( '/thumbnail img\{[^}]*height:auto/', $rel['css'] ), $rel['css'] );
// CSS Hero: fast bildhöjd för relaterade inlägg, via egen klass på widgeten.
$hero = CBX_Css::translate_site_css( '.day-related img.post-thumbnail__img.wp-post-image{height:250px;object-fit:cover;object-position:50% 30%}' );
t( 'site css: jet thumbnail class detected', 1 === $hero['translated'], $hero );
t( 'site css: custom class on widget itself or wrapper', has( $hero['css'], 'body .day-related.cbx-from-jet-posts.cbx-from-jet-posts.cbx-from-jet-posts img' ) && has( $hero['css'], 'body .day-related .cbx-from-jet-posts.cbx-from-jet-posts.cbx-from-jet-posts img' ), $hero['css'] );

echo ( $fails ? "\n$fails av $count CSS-kontroller misslyckades\n" : "Alla $count CSS-kontroller OK\n" );
exit( $fails ? 1 : 0 );
