<?php
/**
 * CSS-motorn: tolkar CSS och översätter Crocoblocks klasser till motsvarande
 * Elementor-markup, så att utseendet följer med när en Jet-widget byts ut.
 *
 * Två lägen:
 *  - element: regler som Elementor genererat för en viss Jet-widget
 *    (".elementor-123 .elementor-element-abc .jet-posts .entry-title …")
 *    blir Custom CSS på den nya widgeten ("selector .elementor-post__title …").
 *  - site: sajtens egen CSS som pekar på Jet-klasser (tema, egna tillägg,
 *    CSS Hero, Anpassa) får översatta kopior, avgränsade till de konverterade
 *    widgetarna via klassen .cbx-from-<jet-typ>.
 *
 * Klassen är fri från WordPress-anrop och kan testas fristående.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'CBX_TESTING' ) ) {
	exit;
}

class CBX_Css {

	/** Selektorer som rör Crocoblock: jet-klasser och Jet-specifika BEM-klasser. */
	const JET_SEL = '/jet[-_]|\.post-thumbnail__(img|link)\b|\.post-meta__item\b/';

	/** Platshållare: "samma element som föregående steg". */
	const SAME = '§';

	/* ------------------------------------------------------------------ *
	 * Tolkning
	 * ------------------------------------------------------------------ */

	/**
	 * Tolkar CSS till en platt lista av regler.
	 *
	 * @return array<int, array{at: string[], selectors: string[], decls: string, raw?: string}>
	 */
	public static function parse( $css, array $at = array() ) {
		$css   = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		$rules = array();
		$len   = strlen( $css );
		$i     = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) {
				break;
			}
			$prelude = trim( substr( $css, $i, $open - $i ) );
			// Påståenden utan block (@charset, @import) före en regel.
			if ( false !== strpos( $prelude, ';' ) ) {
				$parts   = explode( ';', $prelude );
				$prelude = trim( end( $parts ) );
			}
			$depth = 1;
			$j     = $open + 1;
			$quote = '';
			while ( $j < $len && $depth > 0 ) {
				$c = $css[ $j ];
				if ( $quote ) {
					if ( $c === $quote && '\\' !== $css[ $j - 1 ] ) {
						$quote = '';
					}
				} elseif ( '"' === $c || "'" === $c ) {
					$quote = $c;
				} elseif ( '{' === $c ) {
					$depth++;
				} elseif ( '}' === $c ) {
					$depth--;
				}
				$j++;
			}
			$body = substr( $css, $open + 1, max( 0, $j - $open - 2 ) );
			$i    = $j;

			if ( '' === $prelude ) {
				continue;
			}
			if ( preg_match( '/^@(media|supports|container|layer)\b/i', $prelude ) ) {
				$rules = array_merge( $rules, self::parse( $body, array_merge( $at, array( $prelude ) ) ) );
			} elseif ( '@' === $prelude[0] ) {
				$rules[] = array( 'at' => $at, 'selectors' => array(), 'decls' => '', 'raw' => $prelude . '{' . $body . '}' );
			} else {
				$rules[] = array( 'at' => $at, 'selectors' => self::split_selectors( $prelude ), 'decls' => trim( $body ) );
			}
		}
		return $rules;
	}

	/**
	 * Pekar selektorn på en position i sidans struktur (och slutar därmed
	 * gälla när strukturen ändras)? T.ex. CSS Heros "html > body > … > div:nth-child(2)".
	 */
	public static function is_fragile( $sel ) {
		return (bool) preg_match( '/^\s*html\s*>\s*body|(:nth-(child|of-type)\([^)]*\).*){2,}|(>\s*div\s*){3,}/i', $sel );
	}

	/** Delar en selektorlista på kommatecken utanför parenteser. */
	public static function split_selectors( $prelude ) {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $prelude );
		for ( $k = 0; $k < $len; $k++ ) {
			$c = $prelude[ $k ];
			if ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth--;
			}
			if ( ',' === $c && 0 === $depth ) {
				$out[] = trim( $cur );
				$cur   = '';
				continue;
			}
			$cur .= $c;
		}
		if ( '' !== trim( $cur ) ) {
			$out[] = trim( $cur );
		}
		return $out;
	}

	/** Delar deklarationer på semikolon utanför parenteser och citat. */
	public static function split_decls( $decls ) {
		$out   = array();
		$depth = 0;
		$quote = '';
		$cur   = '';
		$len   = strlen( $decls );
		for ( $k = 0; $k < $len; $k++ ) {
			$c = $decls[ $k ];
			if ( $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c ) {
				$depth++;
			} elseif ( ')' === $c ) {
				$depth--;
			} elseif ( ';' === $c && 0 === $depth ) {
				if ( '' !== trim( $cur ) ) {
					$out[] = trim( $cur );
				}
				$cur = '';
				continue;
			}
			$cur .= $c;
		}
		if ( '' !== trim( $cur ) ) {
			$out[] = trim( $cur );
		}
		return $out;
	}

	/**
	 * Skriver ut regler, grupperade per @media.
	 */
	public static function render( array $rules ) {
		$out = '';
		foreach ( $rules as $r ) {
			if ( isset( $r['raw'] ) ) {
				$block = $r['raw'];
			} else {
				if ( ! $r['selectors'] || '' === trim( $r['decls'] ) ) {
					continue;
				}
				$block = implode( ",\n", $r['selectors'] ) . " {\n\t" . implode( ";\n\t", self::split_decls( $r['decls'] ) ) . ";\n}";
			}
			foreach ( array_reverse( $r['at'] ) as $at ) {
				$block = $at . " {\n" . $block . "\n}";
			}
			$out .= $block . "\n";
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Översättningstabeller
	 * ------------------------------------------------------------------ */

	/**
	 * Per Jet-widget: hur dess klasser motsvaras i den nya Elementor-widgeten.
	 *
	 *  prefixes  Klassprefix som identifierar widgeten i sajtens egen CSS.
	 *  map       Regex → ersättning, i ordning. SAME ("§") = samma element som
	 *            föregående steg; "§x" = samma, eller "x" om inget föregår.
	 *  inner     Regex för "inre" element som slås ihop med länken/knappen.
	 *            Deras !important tas bort och de skrivs ut före övriga regler.
	 *  strip     Regex på ämnet → egenskaper som tas bort (t.ex. kolumnavstånd).
	 *  base      Grundstil som efterliknar Crocoblocks egen stilmall.
	 */
	public static function maps() {
		$same = self::SAME;
		$nav  = array(
			'base' => <<<CSS
selector .elementor-nav-menu--main .elementor-nav-menu{justify-content:var(--jmm-top-items-hor-align,flex-start);gap:var(--jmm-top-items-gap,0);background-color:var(--jmm-top-menu-bg-color,transparent);flex:1 1 auto}
selector .elementor-nav-menu--main a.elementor-item{padding:var(--jmm-top-items-ver-padding,5px) var(--jmm-top-items-hor-padding,10px);color:var(--jmm-top-item-title-color,inherit);background-color:var(--jmm-top-item-bg-color,transparent);font-family:inherit;font-size:inherit;font-weight:inherit;line-height:inherit;letter-spacing:inherit;text-transform:inherit}
selector .elementor-nav-menu--main a.elementor-item:hover,selector .elementor-nav-menu--main a.elementor-item:focus,selector .elementor-nav-menu--main a.elementor-item.highlighted{color:var(--jmm-top-hover-item-title-color,var(--jmm-top-item-title-color,inherit));background-color:var(--jmm-top-hover-item-bg-color,var(--jmm-top-item-bg-color,transparent))}
selector .elementor-nav-menu--main a.elementor-item.elementor-item-active{color:var(--jmm-top-active-item-title-color,var(--jmm-top-item-title-color,inherit));background-color:var(--jmm-top-active-item-bg-color,var(--jmm-top-item-bg-color,transparent))}
selector .elementor-nav-menu--main .sub-arrow{color:var(--jmm-top-item-dropdown-icon-color,inherit)}
selector .elementor-nav-menu--main .sub-menu{background-color:var(--jmm-sub-menu-bg-color,#fff)}
selector .elementor-nav-menu--main .sub-menu a.elementor-sub-item{padding:var(--jmm-sub-items-ver-padding,5px) var(--jmm-sub-items-hor-padding,10px);color:var(--jmm-sub-item-title-color,inherit);background-color:var(--jmm-sub-item-bg-color,transparent)}
selector .elementor-nav-menu--main .sub-menu a.elementor-sub-item:hover,selector .elementor-nav-menu--main .sub-menu a.elementor-sub-item:focus{color:var(--jmm-sub-hover-item-title-color,var(--jmm-sub-item-title-color,inherit));background-color:var(--jmm-sub-hover-item-bg-color,var(--jmm-sub-item-bg-color,transparent))}
selector .elementor-menu-toggle{color:var(--jmm-dropdown-toggle-color,inherit);background-color:var(--jmm-dropdown-toggle-bg-color,transparent)}
selector .elementor-nav-menu--dropdown.elementor-nav-menu__container{background-color:var(--jmm-dropdown-bg-color,#fff)}
selector .elementor-nav-menu--dropdown.elementor-nav-menu__container a{color:var(--jmm-dropdown-item-title-color,inherit);background-color:var(--jmm-dropdown-item-bg-color,transparent)}
CSS
,
		);

		return array(
			// JetMenu (ny och äldre markup) → Nav Menu.
			'jet-mega-menu'  => array(
				'prefixes' => array( '.jet-mega-menu', '.jet-menu', '.jet-sub-mega-menu', '.jet-sub-menu', '.jet-dropdown-arrow', '.top-level-link', '.sub-level-link' ),
				'map'      => array(
					'/\.jet-mega-menu(?![\w-])(\.jet-mega-menu--[\w-]+)*/' => '.elementor-nav-menu--main',
					'/\.jet-mega-menu-container\b/'                        => $same,
					'/\.jet-mega-menu-list\b/'                             => '.elementor-nav-menu',
					'/\.jet-mega-menu-sub-menu__list\b/'                   => '.sub-menu',
					'/\.jet-mega-menu-sub-menu\b/'                         => '.sub-menu',
					'/\.jet-mega-menu-mega-container(__inner)?\b/'         => '.sub-menu',
					'/\.jet-mega-menu-item__link--sub-level\b/'            => 'a.elementor-sub-item',
					'/\.jet-mega-menu-item__link(--top-level)?\b/'         => 'a',
					'/\.jet-mega-menu-item__inner\b/'                      => 'a',
					'/\.jet-mega-menu-item__(title|label)\b/'              => $same . 'a',
					'/\.jet-mega-menu-item__dropdown\b/'                   => '.sub-arrow',
					'/\.jet-mega-current-menu-item\b/'                     => '.current-menu-item',
					'/\.jet-mega-menu-item--hover\b/'                      => ':hover',
					'/\.jet-mega-menu-item--(top-level|sub-level|default|mega)\b/' => '',
					'/\.jet-mega-menu-item(?![\w-])/'                      => '.menu-item',
					'/\.jet-mega-menu-toggle[\w-]*/'                       => '.elementor-menu-toggle',
					// Äldre JetMenu-markup.
					'/\.jet-menu-container\b|\.jet-menu-inner\b/'          => $same,
					'/\.jet-menu(?![\w-])(\.jet-menu--[\w-]+)*/'           => '.elementor-nav-menu',
					'/\.jet-sub-menu\b|\.jet-sub-mega-menu\b/'             => '.sub-menu',
					'/\.top-level-link\b/'                                 => 'a',
					'/\.sub-level-link\b/'                                 => 'a.elementor-sub-item',
					'/\.jet-menu-item-wrapper\b|\.jet-menu-title\b/'       => $same . 'a',
					'/\.jet-dropdown-arrow\b/'                             => '.sub-arrow',
					'/\.jet-menu-item(?![\w-])/'                           => '.menu-item',
					'/\.jet-current-menu-item\b/'                          => '.current-menu-item',
				),
				'inner'    => '/(\.jet-mega-menu-item__inner|\.jet-menu-item-wrapper)\s*$/',
				'base'     => $nav['base'],
			),
			// JetBlocks Nav Menu → Nav Menu.
			'jet-nav-menu'   => array(
				'prefixes' => array( '.jet-nav', '.menu-item-link', '.jet-nav-arrow' ),
				'map'      => array(
					'/\.jet-nav-wrap\b/'                       => '.elementor-nav-menu--main',
					'/\.jet-nav(?![\w-])(\.jet-nav--[\w-]+)*/' => '.elementor-nav-menu',
					'/\.jet-nav__sub\b/'                       => '.sub-menu',
					'/\.jet-nav__item\b|\.jet-nav-item-sub\b|\.jet-nav-depth-\d+\b/' => '.menu-item',
					'/\.menu-item-link-top\b/'                 => 'a',
					'/\.menu-item-link-sub\b/'                 => 'a.elementor-sub-item',
					'/\.menu-item-link\b/'                     => 'a',
					'/\.jet-nav-link-text\b/'                  => $same . 'a',
					'/\.jet-nav-arrow\b/'                      => '.sub-arrow',
					'/\.jet-nav__mobile-trigger\b/'            => '.elementor-menu-toggle',
				),
				'base'     => $nav['base'],
			),
			// JetMenu Custom (vertikal) Menu → Nav Menu.
			'jet-custom-menu' => array(
				'prefixes' => array( '.jet-custom-nav' ),
				'map'      => array(
					'/\.jet-custom-nav(?![\w-])(\.jet-custom-nav--[\w-]+)*/' => '.elementor-nav-menu',
					'/\.jet-custom-nav__item\b/'               => '.menu-item',
					'/\.jet-custom-nav__sub\b/'                => '.sub-menu',
					'/\.hover-state\b/'                        => ':hover',
					'/\.jet-custom-item-label\b|\.jet-menu-link-text\b/' => $same . 'a',
					'/\.jet-dropdown-arrow\b/'                 => '.sub-arrow',
				),
				'base'     => $nav['base'],
			),
			// JetElements Posts → Posts (classic).
			'jet-posts'      => array(
				'prefixes' => array( '.jet-posts', '.jet-more' ),
				// Jet-specifika klasser som sajtens CSS kan använda utan .jet-posts framför.
				'markers'  => array( '.post-thumbnail__img', '.post-thumbnail__link', '.post-meta__item' ),
				// Regler mot Jets inre omslag (.elementor-jet-posts) gäller listan, inte widgeten.
				'wrapper'  => '.elementor-posts-container',
				'map'      => array(
					'/\.wp-post-image\b/'            => '',
					'/\.jet-posts__item\b/'          => '.elementor-post',
					'/\.jet-posts__inner-box\b/'     => $same . '.elementor-post',
					'/\.jet-posts__inner-content\b/' => '.elementor-post__text',
					'/\.jet-posts(?![\w-])(\.[\w-]+)*/' => '.elementor-posts-container',
					'/\.post-thumbnail__link\b/'     => '.elementor-post__thumbnail__link',
					'/\.post-thumbnail__img\b/'      => 'img',
					'/\.post-thumbnail\b/'           => '.elementor-post__thumbnail',
					'/\.entry-title\b/'              => '.elementor-post__title',
					'/\.post-meta__item\b/'          => '.elementor-post__meta-data > span',
					'/\.post-meta\b/'                => '.elementor-post__meta-data',
					'/\.entry-excerpt\b/'            => '.elementor-post__excerpt',
					'/\.jet-more-wrap\b/'            => $same,
					'/\.jet-more-icon\b/'            => '.cbx-unmapped',
					'/\.jet-more\b/'                 => '.elementor-post__read-more',
				),
				// Jets kolumnavstånd (padding på inläggen, negativ marginal på listan) blir Elementors gap.
				'strip'    => array(
					'/\.jet-posts__item\b[^\s>]*$/'    => '/^(padding|margin|max-width|flex|-webkit-box-flex|-ms-flex)/',
					'/\.jet-posts(?![\w-])[^\s>]*$/'   => '/^margin/',
				),
				'base'     => <<<CSS
selector .elementor-posts-container .elementor-post__thumbnail__link{margin-bottom:0;width:100%}
selector .elementor-posts-container .elementor-post__thumbnail{padding-bottom:0}
selector .elementor-posts-container .elementor-post__thumbnail img{position:static;transform:none;top:auto;left:auto;width:100%}
selector .elementor-posts-container .elementor-post__excerpt{margin-bottom:0}
selector .elementor-posts-container .elementor-post__excerpt p{margin:0;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit}
CSS
,
			),
			// JetElements Testimonials → Testimonial Carousel.
			'jet-testimonials' => array(
				'prefixes' => array( '.jet-testimonials' ),
				'map'      => array(
					'/\.jet-testimonials__comment\b/'  => '.elementor-testimonial__text',
					'/\.jet-testimonials__name\b/'     => '.elementor-testimonial__name',
					'/\.jet-testimonials__position\b/' => '.elementor-testimonial__title',
					'/\.jet-testimonials__content\b/'  => '.elementor-testimonial__content',
					// Betyget ritas i stället med CSS av konverteringen; figur/ikon har ingen motsvarighet.
					'/\.jet-testimonials__(rating|figure|tag|icon)\b[^\s>]*/' => '.cbx-unmapped',
					'/\.jet-testimonials__item-inner\b/' => '.elementor-testimonial',
					'/\.jet-testimonials__item(?![\w-])/' => '.swiper-slide',
					'/\.jet-testimonials__instance\b/' => $same,
					'/\.jet-testimonials(?![\w-])(\.[\w-]+)*/' => $same,
				),
				'base'     => '',
			),
			// JetBlog Smart Listing → Posts (classic).
			'jet-blog-smart-listing' => array(
				'prefixes' => array( '.jet-smart-listing' ),
				'map'      => array(
					'/\.jet-smart-listing__posts\b/'          => '.elementor-posts-container',
					'/\.jet-smart-listing__post-wrapper\b/'   => '.elementor-post',
					'/\.jet-smart-listing__post-content\b/'   => '.elementor-post__text',
					'/\.jet-smart-listing__post(?![\w-])/'    => '.elementor-post',
					'/\.jet-smart-listing__post-thumbnail(\.[\w-]+)*/' => '.elementor-post__thumbnail',
					'/\.post-title-simple\b/'                 => '.elementor-post__title',
					'/\.post-excerpt-simple\b/'               => '.elementor-post__excerpt',
					'/\.jet-smart-listing__meta-item\b/'      => '.elementor-post__meta-data > span',
					'/\.jet-smart-listing__meta\b/'           => '.elementor-post__meta-data',
					'/\.jet-smart-listing__more\b/'           => '.elementor-post__read-more',
					'/\.jet-smart-listing(?![\w-])/'          => $same,
				),
				'base'     => <<<CSS
selector .elementor-posts-container .elementor-post__excerpt p{margin:0;color:inherit;font:inherit;letter-spacing:inherit;text-transform:inherit}
CSS
,
			),
			// JetElements Button → Button.
			'jet-button'     => array(
				'prefixes' => array( '.jet-button' ),
				'map'      => array(
					'/\.jet-button__container\b/'    => '.elementor-button-wrapper',
					'/\.jet-button__instance\b/'     => 'a.elementor-button',
					'/\.jet-button__plane-hover\b/'  => $same . 'a.elementor-button' . ':hover',
					'/\.jet-button__plane(-normal)?\b/' => $same . 'a.elementor-button',
					'/\.jet-button__state-hover\b/'  => $same . 'a.elementor-button' . ':hover',
					'/\.jet-button__state(-normal)?\b/' => $same . 'a.elementor-button',
					'/\.jet-button__label\b/'        => '.elementor-button-text',
					'/\.jet-button__icon\b/'         => '.elementor-button-icon',
				),
				'base'     => '',
			),
			// JetElements Headline → Heading.
			'jet-headline'   => array(
				'prefixes' => array( '.jet-headline' ),
				'map'      => array(
					'/\.jet-headline__second\b.*$/'  => '.cbx-unmapped',
					'/\.jet-headline__divider\b/'    => '.cbx-unmapped',
					'/\.jet-headline__first\b/'      => $same . '.elementor-heading-title',
					'/\.jet-headline__label\b/'      => $same . '.elementor-heading-title',
					'/\.jet-headline(?![\w-])/'      => '.elementor-heading-title',
				),
				'base'     => '',
			),
			// JetTabs Tabs → Tabs.
			'jet-tabs'       => array(
				'prefixes' => array( '.jet-tabs' ),
				'map'      => array(
					'/\.jet-tabs__control-wrapper\b/'  => '.elementor-tabs-wrapper',
					'/\.jet-tabs__control\.active-tab\b/' => '.elementor-tab-title.elementor-active',
					'/\.jet-tabs__control(?![\w-])(\.jet-tabs__control-icon-[\w-]+)?/' => '.elementor-tab-title',
					'/\.active-tab\b/'                 => '.elementor-active',
					'/\.jet-tabs__control-inner\b/'    => $same,
					'/\.jet-tabs__label-text\b/'       => 'a',
					'/\.jet-tabs__label-icon\b/'       => '.cbx-unmapped',
					'/\.jet-tabs__content-wrapper\b/'  => '.elementor-tabs-content-wrapper',
					'/\.jet-tabs__content\b/'          => '.elementor-tab-content',
					'/\.jet-tabs(?![\w-])(\.jet-tabs-[\w-]+)*/' => '.elementor-tabs',
				),
				'base'     => '',
			),
			// JetTabs Accordion → Accordion.
			'jet-accordion'  => array(
				'prefixes' => array( '.jet-accordion', '.jet-toggle' ),
				'map'      => array(
					'/\.jet-toggle\.active-toggle\s*>\s*\.jet-toggle__control\b/' => '.elementor-accordion-item > .elementor-tab-title.elementor-active',
					'/\.jet-accordion__inner\b/'       => $same,
					'/\.jet-toggle__control\b/'        => '.elementor-tab-title',
					'/\.jet-toggle__content\b/'        => '.elementor-tab-content',
					'/\.jet-toggle__label-text\b/'     => '.elementor-accordion-title',
					'/\.jet-toggle__label-icon\b|\.jet-toggle__icon\b/' => '.elementor-accordion-icon',
					'/\.jet-toggle(?![\w-])(\.active-toggle)?/' => '.elementor-accordion-item',
					'/\.jet-accordion(?![\w-])/'       => '.elementor-accordion',
				),
				'base'     => '',
			),
			// JetBlocks Search / JetSearch → Search Form.
			// JetBlocks Search → [cbx_search] (samma uppbyggnad, egna klasser).
			'jet-search'     => array(
				'prefixes' => array( '.jet-search' ),
				'map'      => array(
					'/\.jet-blocks-icon\b/'        => '',
					'/\.jet-search__([\w-]+)/'     => '.cbx-search__$1',
					'/\.jet-search(?![\w-])/'      => '.cbx-search',
				),
				'base'     => '',
			),
			// JetTricks Unfold → Mall/Text med CBX_Frontends hopfällning.
			'jet-unfold'     => array(
				'prefixes' => array( '.jet-unfold' ),
				'map'      => array(
					'/\.jet-tricks-icon\b/'        => '',
					'/\.jet-unfold-state\b/'       => '.cbx-unfold-state',
					'/\.jet-unfold__([\w-]+)/'     => '.cbx-unfold__$1',
					'/\.jet-unfold(?![\w-])/'      => '.cbx-unfold',
				),
				'base'     => '',
			),
			// JetBlog Posts Pagination → [cbx_posts_pagination].
			'jet-blog-posts-pagination' => array(
				'prefixes' => array( '.jet-blog-arrow' ),
				'map'      => array(
					'/\.jet-blog-arrow\b/'         => '.cbx-blog-arrow',
					'/\.jet-arrow-(prev|next)\b/'  => '.cbx-arrow-$1',
					'/\.jet-blog(?![\w-])/'        => $same,
				),
				'base'     => '',
			),
			// JetSearch Ajax Search → Elementor Pros sökruta.
			'jet-ajax-search' => array(
				'prefixes' => array( '.jet-ajax-search' ),
				'map'      => array(
					'/\.jet-(ajax-)?search__form\b/'          => '.elementor-search-form__container',
					'/\.jet-(ajax-)?search__field\b/'         => 'input.elementor-search-form__input',
					'/\.jet-(ajax-)?search__submit-icon\b/'   => '.elementor-search-form__submit i',
					'/\.jet-(ajax-)?search__submit(-label)?\b/' => '.elementor-search-form__submit',
					'/\.jet-(ajax-)?search(?![\w-])/'         => '.elementor-search-form',
				),
				'base'     => '',
			),
			// JetBlocks Logo → Image / Site Title.
			'jet-logo'       => array(
				'prefixes' => array( '.jet-logo' ),
				'map'      => array(
					'/\.jet-logo__img\b/'  => 'img',
					'/\.jet-logo__link\b/' => 'a',
					'/\.jet-logo__text\b/' => '.elementor-heading-title',
					'/\.jet-logo(?![\w-])(\.jet-logo-[\w-]+)*/' => $same,
				),
				'base'     => '',
			),
			// JetElements Banner → Image Box.
			'jet-banner'     => array(
				'prefixes' => array( '.jet-banner' ),
				'map'      => array(
					'/\.jet-banner__title\b/'   => '.elementor-image-box-title',
					'/\.jet-banner__text\b/'    => '.elementor-image-box-description',
					'/\.jet-banner__content\b/' => '.elementor-image-box-content',
					'/\.jet-banner__img\b/'     => '.elementor-image-box-img img',
					'/\.jet-banner(?![\w-])/'   => '.elementor-image-box-wrapper',
				),
				'base'     => '',
			),
			// JetElements Services → Icon Box / Image Box.
			'jet-services'   => array(
				'prefixes' => array( '.jet-services' ),
				'map'      => array(
					'/\.jet-services__title-text\b/' => $same . '[class*="-box-title"]',
					'/\.jet-services__title\b/'      => '[class*="-box-title"]',
					'/\.jet-services__desc\b/'       => '[class*="-box-description"]',
					'/\.jet-services__content\b/'    => '[class*="-box-content"]',
					'/\.jet-services__icon\b/'       => '.elementor-icon',
					'/\.jet-services__inner\b/'      => $same,
					'/\.jet-services(?![\w-])/'      => '[class*="-box-wrapper"]',
				),
				'base'     => '',
			),
		);
	}

	/**
	 * Vilken mapp gäller för en Jet-typ (alias för widgets som delar markup).
	 */
	public static function map_for( $type ) {
		$alias = array(
			'jet-mobile-menu'       => 'jet-mega-menu',
			'jet-blog-smart-tiles'  => 'jet-blog-smart-listing',
			'jet-team-member'       => 'jet-banner',
		);
		$maps = self::maps();
		$key  = isset( $alias[ $type ] ) ? $alias[ $type ] : $type;
		return isset( $maps[ $key ] ) ? $maps[ $key ] : null;
	}

	/* ------------------------------------------------------------------ *
	 * Selektoröversättning
	 * ------------------------------------------------------------------ */

	/**
	 * Översätter en selektorsvans (det som står efter widgetens omslag).
	 *
	 * @return string|null Ny svans, '' om svansen är widgeten själv, null om den inte kan översättas.
	 */
	public static function translate_tail( $type, $tail ) {
		$map = self::map_for( $type );
		if ( ! $map ) {
			return null;
		}
		$t = ' ' . $tail;

		// Elementors omslag (finns inte i alla versioner) och Jet-wrappers.
		$t = preg_replace( '/\s*>?\s*\.elementor-widget-container(\s*>\s*div(?![\w.:#\[-]))?/', '', $t );
		if ( ! empty( $map['wrapper'] ) && preg_match( '/^\s*>?\s*\.elementor-jet-[\w-]+(\.jet-elements)?\s*$/', $t ) ) {
			return self::normalize( $map['wrapper'] );
		}
		$t = preg_replace( '/(?<![\w-])[a-z]+[1-6]?(?=\.post-thumbnail__img)/', '', $t ); // "img.post-thumbnail__img" → ".post-thumbnail__img"
		$t = preg_replace( '/\s*>?\s*\.elementor-jet-[\w-]+(\.jet-elements)?/', '', $t );
		$t = preg_replace( '/(?<![\w-])[a-z]+(?=\.jet-)/', '', $t ); // "i.jet-dropdown-arrow" → ".jet-dropdown-arrow"
		$t = preg_replace( '/\.(fa|fas|far|fab|fa-[\w-]+)(?![\w-])/', '', $t );
		$t = preg_replace( '/\.(col-row|jet-equal-cols|slick-slider)\b/', '', $t );
		$t = str_replace( ':not()', '', $t );

		foreach ( $map['map'] as $re => $rep ) {
			$t = preg_replace( $re, $rep, $t );
		}

		if ( preg_match( '/\.jet-|\.cbx-unmapped|jet_/', $t ) ) {
			return null;
		}

		return self::normalize( self::resolve_same( $t ) );
	}

	/**
	 * Delar en selektor i steg: sammansatta selektorer och kombinatorer.
	 */
	protected static function tokens( $t ) {
		$t = preg_replace( '/\s*([>+~])\s*/', ' $1 ', (string) $t );
		return array_values( array_filter( preg_split( '/\s+/', trim( $t ) ), 'strlen' ) );
	}

	protected static function is_comb( $tok ) {
		return in_array( $tok, array( '>', '+', '~' ), true );
	}

	/** Grundelementet i ett steg, utan pseudoklasser: "a.elementor-item:hover" → "a.elementor-item". */
	protected static function base_of( $tok ) {
		return preg_replace( '/:.*$/', '', $tok );
	}

	/**
	 * "§X" = samma element som föregående steg om det redan är X, annars X.
	 * "§"  = alltid samma element som föregående steg.
	 */
	protected static function resolve_same( $t ) {
		$out = array();
		foreach ( self::tokens( $t ) as $tok ) {
			if ( false === strpos( $tok, self::SAME ) ) {
				$out[] = $tok;
				continue;
			}
			$x      = str_replace( self::SAME, '', $tok );
			$x_base = self::base_of( $x );
			$x_pse  = (string) substr( $x, strlen( $x_base ) );
			$k      = count( $out ) - 1;
			while ( $k >= 0 && self::is_comb( $out[ $k ] ) ) {
				$k--;
			}
			$prev  = $k >= 0 ? $out[ $k ] : null;
			$pbase = null !== $prev ? self::base_of( $prev ) : '';
			$merge = null !== $prev && ( '' === $x_base || self::extends_sel( $pbase, $x_base ) || self::extends_sel( $x_base, $pbase ) );
			if ( $merge ) {
				$out       = array_slice( $out, 0, $k + 1 );
				$base      = strlen( $x_base ) > strlen( $pbase ) ? $x_base : $pbase;
				$out[ $k ] = $base . substr( $prev, strlen( $pbase ) ) . $x_pse;
			} elseif ( '' !== $x ) {
				$out[] = $x;
			}
		}
		return implode( ' ', $out );
	}

	/** Är $a samma sammansatta selektor som $b, eventuellt med fler klasser? ("a.x" utökar "a"). */
	protected static function extends_sel( $a, $b ) {
		if ( '' === $b || 0 !== strpos( $a, $b ) ) {
			return false;
		}
		$next = substr( $a, strlen( $b ), 1 );
		return '' === $next || in_array( $next, array( '.', ':', '[', '#' ), true );
	}

	protected static function normalize( $t ) {
		$tokens = self::tokens( $t );
		while ( $tokens && self::is_comb( $tokens[0] ) ) {
			array_shift( $tokens );
		}
		while ( $tokens && self::is_comb( end( $tokens ) ) ) {
			array_pop( $tokens );
		}
		$out = array();
		foreach ( $tokens as $tok ) {
			$n = count( $out );
			if ( $n && self::is_comb( $tok ) && self::is_comb( $out[ $n - 1 ] ) ) {
				$out[ $n - 1 ] = $tok;
				continue;
			}
			if ( ! self::is_comb( $tok ) ) {
				$k = $n - 1;
				if ( $k >= 0 && self::is_comb( $out[ $k ] ) ) {
					$k--;
				}
				$tb = self::base_of( $tok );
				$pb = $k >= 0 ? self::base_of( $out[ $k ] ) : null;
				$link_pair = null !== $pb && preg_match( '/^a(\.|$)/', $tb ) && preg_match( '/^a(\.|$)/', $pb );
				// Upprepat steg ("a a", "x > x") är samma element.
				if ( null !== $pb && ( $pb === $tb || $link_pair ) ) {
					$out  = array_slice( $out, 0, $k + 1 );
					$base = strlen( $tb ) > strlen( $pb ) ? $tb : $pb;
					$pse  = substr( $out[ $k ], strlen( $pb ) ) . substr( $tok, strlen( $tb ) );
					$out[ $k ] = $base . $pse;
					continue;
				}
			}
			$out[] = $tok;
		}
		// Länkar får Elementors klasser (toppnivå vs undermeny).
		$seen_sub = false;
		foreach ( $out as $i => $tok ) {
			if ( false !== strpos( $tok, '.sub-menu' ) ) {
				$seen_sub = true;
			}
			if ( preg_match( '/^a(?=$|[:\[])/', $tok ) ) {
				$out[ $i ] = 'a.elementor-' . ( $seen_sub ? 'sub-' : '' ) . 'item' . substr( $tok, 1 );
			} elseif ( $seen_sub && preg_match( '/^a\.elementor-item\b/', $tok ) ) {
				$out[ $i ] = preg_replace( '/^a\.elementor-item/', 'a.elementor-sub-item', $tok );
			}
		}
		$t = implode( ' ', $out );
		$t = preg_replace( '/(:hover)+/', ':hover', $t );
		return trim( $t );
	}

	/** Gäller länk-ersättningen bara menyer? Andra widgets behåller "a". */
	protected static function fix_links_for_type( $type, $tail ) {
		if ( in_array( $type, array( 'jet-mega-menu', 'jet-nav-menu', 'jet-custom-menu', 'jet-mobile-menu' ), true ) ) {
			return $tail;
		}
		return preg_replace( '/a\.elementor-(sub-)?item\b/', 'a', $tail );
	}

	/** Tar bort !important. */
	protected static function soften( $decls ) {
		return preg_replace( '/\s*!important/i', '', $decls );
	}

	protected static function strip_props( $decls, $prop_re ) {
		$keep = array();
		foreach ( self::split_decls( $decls ) as $d ) {
			$prop = strtolower( trim( strstr( $d, ':', true ) ) );
			if ( ! preg_match( $prop_re, $prop ) ) {
				$keep[] = $d;
			}
		}
		return implode( ';', $keep );
	}

	/* ------------------------------------------------------------------ *
	 * Elementnivå
	 * ------------------------------------------------------------------ */

	/**
	 * Bygger Custom CSS för en konverterad widget.
	 *
	 * @param string $type       Jet-widgetens typ.
	 * @param array  $rules      Tolkade regler från postens genererade CSS.
	 * @param string $element_id Elementets ID.
	 * @param string $existing   Widgetens befintliga Custom CSS (från Elementor Pro).
	 * @return array{css: string, mapped: int, unmapped: int}
	 */
	public static function element_css( $type, array $rules, $element_id, $existing = '' ) {
		$map      = self::map_for( $type );
		$low      = array();
		$normal   = array();
		$skipped  = array();
		$mapped   = 0;
		$unmapped = 0;
		$needle   = '.elementor-element-' . $element_id;

		foreach ( $rules as $r ) {
			if ( isset( $r['raw'] ) ) {
				continue;
			}
			$sels_low  = array();
			$sels      = array();
			$sels_self = array();
			$lost      = array();
			foreach ( $r['selectors'] as $sel ) {
				$pos = strpos( $sel, $needle );
				if ( false === $pos ) {
					continue;
				}
				$tail = substr( $sel, $pos + strlen( $needle ) );
				$res  = self::element_tail( $type, $tail );
				if ( null === $res ) {
					$lost[] = 'selector' . $tail;
					continue;
				}
				if ( 'selector' === $res ) {
					// Regeln gäller widgeten själv (eller dess inre omslag).
					$sels_self[] = $res;
				} elseif ( $map && ! empty( $map['inner'] ) && preg_match( $map['inner'], $tail ) ) {
					$sels_low[] = $res;
				} else {
					$sels[] = $res;
				}
			}
			$decls = $r['decls'];
			if ( $map && ! empty( $map['strip'] ) ) {
				foreach ( $map['strip'] as $subject => $props ) {
					foreach ( $r['selectors'] as $sel ) {
						if ( preg_match( $subject, $sel ) ) {
							$decls = self::strip_props( $decls, $props );
							break;
						}
					}
				}
			}
			if ( $sels ) {
				$normal[] = array( 'at' => $r['at'], 'selectors' => array_values( array_unique( $sels ) ), 'decls' => $decls );
				$mapped++;
			}
			if ( $sels_self ) {
				// Avancerat-inställningarna (marginal, padding, ram, skugga) följer
				// med som inställningar och återskapas av Elementor för den nya
				// widgeten – kopieras CSS:en också blir de dubbla (widgeten OCH
				// dess inre omslag). Behåll bara övriga egenskaper.
				$self_decls = self::strip_props( $decls, '/^(margin|padding|border|box-shadow)/' );
				if ( '' !== trim( $self_decls ) ) {
					$normal[] = array( 'at' => $r['at'], 'selectors' => array( 'selector' ), 'decls' => $self_decls );
					$mapped++;
				}
			}
			if ( $sels_low ) {
				$low[] = array( 'at' => $r['at'], 'selectors' => array_values( array_unique( $sels_low ) ), 'decls' => self::soften( $decls ) );
				$mapped++;
			}
			if ( $lost ) {
				$skipped[] = array( 'at' => $r['at'], 'selectors' => $lost, 'decls' => $r['decls'] );
				$unmapped++;
			}
		}

		$css = '';
		if ( $map && '' !== trim( $map['base'] ) && ( $mapped || $unmapped ) ) {
			$css .= "/* Grundstil som efterliknar Crocoblock ({$type}) */\n" . trim( $map['base'] ) . "\n";
		}
		if ( $low || $normal ) {
			$css .= "/* Stil överförd från {$type} */\n" . self::render( array_merge( $low, $normal ) );
		}
		if ( '' !== trim( (string) $existing ) ) {
			$css .= "/* Widgetens egen Custom CSS, översatt */\n" . self::translate_custom_css( $type, $existing );
		}
		if ( $skipped ) {
			$css .= "/* Värden från {$type} utan direkt motsvarighet (för referens):\n" . str_replace( '*/', '* /', self::render( $skipped ) ) . "*/\n";
		}
		return array( 'css' => $css, 'mapped' => $mapped, 'unmapped' => $unmapped );
	}

	/**
	 * Svansen efter elementets ID → "selector …" (eller null).
	 */
	protected static function element_tail( $type, $tail ) {
		$tail = (string) $tail;
		// Pseudo/klass direkt på elementet (":hover", ".elementor-widget-…").
		$own = '';
		if ( preg_match( '/^((?:\.[\w-]+|:[\w-]+(?:\([^)]*\))?)*)(.*)$/s', $tail, $m ) ) {
			$own  = preg_replace( '/\.elementor-widget-[\w-]+|\.elementor-element\b/', '', $m[1] );
			$tail = $m[2];
		}
		if ( '' === trim( $tail ) ) {
			return 'selector' . $own;
		}
		$res = self::translate_tail( $type, $tail );
		if ( null === $res ) {
			return null;
		}
		$res = self::fix_links_for_type( $type, $res );
		return 'selector' . $own . ( '' === $res ? '' : ' ' . $res );
	}

	/**
	 * Översätter Custom CSS som redan låg på Jet-widgeten ("selector .jet-…").
	 */
	public static function translate_custom_css( $type, $css ) {
		$rules = self::parse( $css );
		$out   = array();
		foreach ( $rules as $r ) {
			if ( isset( $r['raw'] ) ) {
				$out[] = $r;
				continue;
			}
			$sels = array();
			foreach ( $r['selectors'] as $sel ) {
				if ( 0 === strpos( $sel, 'selector' ) && false !== strpos( $sel, 'jet' ) ) {
					$res = self::element_tail( $type, substr( $sel, 8 ) );
					$sels[] = null === $res ? $sel : $res;
				} else {
					$sels[] = $sel;
				}
			}
			$r['selectors'] = $sels;
			$out[]          = $r;
		}
		return self::render( $out );
	}

	/**
	 * Custom CSS på ett element som inte själv konverteras (t.ex. en sektion)
	 * men som pekar på Jet-klasser inuti: "selector .jet-posts .entry-title".
	 * Originalselektorerna behålls och översatta läggs till.
	 */
	public static function translate_scoped_custom_css( $css ) {
		$out = array();
		foreach ( self::parse( $css ) as $r ) {
			if ( isset( $r['raw'] ) ) {
				$out[] = $r;
				continue;
			}
			$sels = $r['selectors'];
			foreach ( $r['selectors'] as $sel ) {
				if ( 0 !== strpos( ltrim( $sel ), 'selector' ) || ! preg_match( '/jet[-_]/', $sel ) ) {
					continue;
				}
				$after = substr( ltrim( $sel ), 8 );
				$type  = self::detect_type( $after );
				$res   = $type ? self::site_selector( $type, $after ) : null;
				if ( null !== $res ) {
					$sels[] = 'selector ' . preg_replace( '/^body\s+/', '', $res );
				}
			}
			$r['selectors'] = array_values( array_unique( $sels ) );
			$out[]          = $r;
		}
		return self::render( $out );
	}

	/* ------------------------------------------------------------------ *
	 * Sajtnivå
	 * ------------------------------------------------------------------ */

	/**
	 * Vilken Jet-typ pekar en selektor på?
	 */
	public static function detect_type( $selector ) {
		if ( preg_match( '/\.elementor-widget-(jet-[\w-]+)/', $selector, $m ) && self::map_for( $m[1] ) ) {
			return $m[1];
		}
		$best = null;
		$len  = 0;
		foreach ( self::maps() as $type => $map ) {
			foreach ( array_merge( $map['prefixes'], isset( $map['markers'] ) ? $map['markers'] : array() ) as $prefix ) {
				if ( preg_match( '/' . preg_quote( $prefix, '/' ) . '(?![\w])/', $selector ) || false !== strpos( $selector, $prefix . '-' ) || false !== strpos( $selector, $prefix . '_' ) ) {
					if ( strlen( $prefix ) > $len ) {
						$best = $type;
						$len  = strlen( $prefix );
					}
				}
			}
		}
		return $best;
	}

	/**
	 * Översätter sajtens egen CSS. Bara regler som rör Crocoblock tas med.
	 *
	 * @return array{css: string, translated: int, untranslated: string[]}
	 */
	public static function translate_site_css( $css ) {
		$rules        = self::parse( $css );
		$low          = array();
		$normal       = array();
		$untranslated = array();
		$count        = 0;

		foreach ( $rules as $r ) {
			if ( isset( $r['raw'] ) || ! preg_match( self::JET_SEL, implode( ',', $r['selectors'] ) ) ) {
				continue;
			}
			$sels     = array();
			$sels_low = array();
			foreach ( $r['selectors'] as $sel ) {
				if ( ! preg_match( self::JET_SEL, $sel ) ) {
					continue; // Andra selektorer i samma regel gäller redan.
				}
				$type = self::detect_type( $sel );
				$res  = $type ? self::site_selector( $type, $sel ) : null;
				if ( null === $res ) {
					$untranslated[] = $sel;
					continue;
				}
				$map = self::map_for( $type );
				if ( ! empty( $map['inner'] ) && preg_match( $map['inner'], $sel ) ) {
					$sels_low[] = $res;
				} else {
					$sels[] = $res;
				}
			}
			if ( $sels ) {
				$normal[] = array( 'at' => $r['at'], 'selectors' => array_values( array_unique( $sels ) ), 'decls' => $r['decls'] );
				$count++;
			}
			if ( $sels_low ) {
				$low[] = array( 'at' => $r['at'], 'selectors' => array_values( array_unique( $sels_low ) ), 'decls' => self::soften( $r['decls'] ) );
				$count++;
			}
		}
		return array(
			'css'          => self::render( array_merge( $low, $normal ) ),
			'translated'   => $count,
			'untranslated' => array_values( array_unique( $untranslated ) ),
		);
	}

	/**
	 * En selektor från sajtens CSS → avgränsad till konverterade widgetar.
	 */
	public static function site_selector( $type, $sel ) {
		$scope  = '.cbx-from-' . $type;
		$strong = $scope . $scope . $scope; // väger tyngre än widgetens egen CSS

		// Widgetklassen själv: ".elementor-widget-jet-mega-menu" → scope.
		$widget = '.elementor-widget-' . $type;
		if ( false !== strpos( $sel, $widget ) ) {
			list( $before, $after ) = array_pad( explode( $widget, $sel, 2 ), 2, '' );
			$after = preg_replace( '/^((?:\.[\w-]+|:[\w-]+(?:\([^)]*\))?)*)/', '', $after, 1, $n );
			$own   = '';
			if ( preg_match( '/^((?:\.[\w-]+|:[\w-]+(?:\([^)]*\))?)*)/', substr( $sel, strpos( $sel, $widget ) + strlen( $widget ) ), $m ) ) {
				$own = $m[1];
			}
			if ( '' === trim( $after ) ) {
				return self::join( $before, $strong . $own );
			}
			$res = self::translate_tail( $type, $after );
			return null === $res ? null : self::join( $before, $strong . $own . ' ' . self::fix_links_for_type( $type, $res ) );
		}

		// Annars: dela vid första Jet-klassen; det före behålls som det är.
		if ( ! preg_match( '/(^|[\s>+~])([\w-]*\.(?:jet[-_]|top-level-link|sub-level-link|menu-item-link|post-thumbnail__|post-meta__)[^\s>+~]*)/', $sel, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$cut    = $m[2][1];
		$before = substr( $sel, 0, $cut );
		$after  = substr( $sel, $cut );
		if ( preg_match( '/jet[-_]/', $before ) ) {
			return null;
		}
		$res = self::translate_tail( $type, $after );
		if ( null === $res ) {
			return null;
		}
		$res = self::fix_links_for_type( $type, $res );
		$out = self::join( $before, $strong . ' ' . $res );
		// Slutar delen före på en egen klass (".day-related img.post-thumbnail__img") kan
		// klassen sitta på widgeten själv – träffa både den och ett omslag runt den.
		if ( preg_match( '/^(.*?)(\S*\.[\w-]+)\s*$/', rtrim( $before ), $bm ) && ! preg_match( '/[>+~]$/', $bm[2] ) ) {
			$out .= ', ' . self::join( $bm[1], $bm[2] . $strong . ' ' . $res );
		}
		return $out;
	}

	protected static function join( $before, $rest ) {
		$before = trim( preg_replace( '/^\s*(html\b[^\s>+~]*\s*>?\s*)?(body\b[^\s>+~]*)?\s*>?\s*/', '', (string) $before ) );
		return trim( 'body ' . ( '' !== $before ? $before . ' ' : '' ) . $rest );
	}
}
