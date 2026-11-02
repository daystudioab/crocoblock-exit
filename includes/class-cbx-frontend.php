<?php
/**
 * Byggstenar på sajten för Crocoblock-funktioner som Elementor saknar.
 *
 * - Hopfällning ("Visa fler", från jet-unfold): Elementor Pros Mall-widget
 *   med markeringen cbx-unfold-host lindas in i samma struktur som Jets
 *   (mask, avdelare, knapp) och fälls ut/ihop med ett litet skript.
 * - [cbx_posts_pagination] (från jet-blog-posts-pagination): WordPress egen
 *   sidnumrering med Jets markup och pilar.
 * - [cbx_search] (från jet-search): sökformulär med Jets uppbyggnad. Elementor
 *   Pros sökruta nollställer temats fältstil, så den här behåller utseendet.
 *
 * Klassnamnen motsvarar Jets (jet-unfold__mask → cbx-unfold__mask osv.), så
 * att sajtens CSS och widgetarnas genererade CSS kan översättas rakt av
 * (se CBX_Css::maps()). Grundstilen – den som tidigare kom från Jets egna
 * CSS-filer – skrivs ut en gång per sida, första gången den behövs.
 *
 * @package CrocoblockExit
 */

defined( 'ABSPATH' ) || exit;

class CBX_Frontend {

	/** Grundstil/skript som redan skrivits ut på sidan. */
	protected static $printed = array();

	public static function init() {
		add_shortcode( 'cbx_posts_pagination', array( __CLASS__, 'sc_pagination' ) );
		add_shortcode( 'cbx_search', array( __CLASS__, 'sc_search' ) );
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'unfold_content' ), 40, 2 );
	}

	public static function reset() {
		self::$printed = array();
	}

	/* ---------- Grundstil (motsvarar Jets egna CSS-filer) ---------- */

	public static function base_css( $part ) {
		$css = array(
			'unfold'     => '.cbx-unfold{transition:all 300ms ease;overflow:hidden}'
				. '.cbx-unfold__mask{overflow:hidden;position:relative}'
				. '.cbx-unfold__mask-gradient{mask-image:linear-gradient(to top,transparent 0%,black var(--cbx-unfold-mask-height,100px));-webkit-mask-image:linear-gradient(to top,transparent 0%,black var(--cbx-unfold-mask-height,100px))}'
				. '.cbx-unfold__separator{position:absolute;display:block;width:100%;height:30px;bottom:0;opacity:0;transition:opacity 100ms ease;pointer-events:none}'
				. '.cbx-unfold:not(.cbx-unfold-state) .cbx-unfold__separator{opacity:1}'
				. '.cbx-unfold__trigger{display:flex;flex-flow:column;justify-content:center;align-items:center;margin-top:30px}'
				. '.cbx-unfold__button{display:flex;flex-flow:row nowrap;justify-content:center;align-items:center;padding:15px 30px;font-size:16px;line-height:1;border-radius:4px;background-color:#6ec1e4;color:#fff;cursor:pointer;text-decoration:none;transition:all .3s ease}'
				. '.cbx-unfold__button:hover{background-color:rgb(75.6,178.3,221.6);color:#fff}'
				. '.cbx-unfold__button.elementor-button{display:flex;flex-flow:row nowrap}'
				. '.cbx-unfold__button:not(:empty){gap:5px}'
				. '.cbx-unfold__button .cbx-unfold__button-text,.cbx-unfold__button .cbx-unfold__button-icon{color:inherit}'
				. '.cbx-unfold__button-icon:empty{display:none}'
				. '.cbx-unfold-state .cbx-unfold__mask{height:auto;mask-image:none;-webkit-mask-image:none}',
			'pagination' => '.cbx-posts-pagination .navigation.pagination .nav-links{display:flex;align-items:center}'
				. '.cbx-posts-pagination .navigation.pagination .nav-links .page-numbers{display:block;text-align:center}'
				. '.cbx-posts-pagination .navigation.pagination .nav-links .page-numbers.next,.cbx-posts-pagination .navigation.pagination .nav-links .page-numbers.prev{display:flex;align-items:center;justify-content:center}'
				. '.cbx-posts-pagination .cbx-blog-arrow{width:20px;height:20px;text-align:center;cursor:pointer;display:flex;justify-content:center;align-items:center;z-index:999}'
				. '.cbx-posts-pagination .cbx-blog-arrow svg{width:100%;height:100%}',
			'search'     => '.cbx-search{position:relative}'
				. '.cbx-search__label{display:block;flex:1 1 auto;margin:0}'
				. '.cbx-search__form{display:flex;align-items:center;flex:1 1 auto}'
				. '.cbx-search__field{width:100%}'
				. '.cbx-search__field[type=search]{box-sizing:border-box;-webkit-appearance:none;-moz-appearance:none;appearance:none;transition:none}'
				. '.cbx-search__submit{margin-left:10px;display:flex;align-items:center;border:none}'
				. '.cbx-search__submit-icon+.cbx-search__submit-label{margin-left:4px}',
		);
		return isset( $css[ $part ] ) ? $css[ $part ] : '';
	}

	/** Grundstilen (och för hopfällningen skriptet) – bara första gången. */
	protected static function once( $part ) {
		if ( isset( self::$printed[ $part ] ) ) {
			return '';
		}
		self::$printed[ $part ] = true;
		$out = '<style id="cbx-' . $part . '-css">' . self::base_css( $part ) . '</style>';
		if ( 'unfold' === $part ) {
			$out .= '<script id="cbx-unfold-js">' . self::unfold_js() . '</script>';
		}
		return $out;
	}

	/* ---------- Hopfällning (jet-unfold) ---------- */

	/**
	 * Filter: elementor/widget/render_content. Lindar in innehållet i en
	 * widget som markerats som hopfällbar.
	 */
	public static function unfold_content( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_data' ) ) {
			return $content;
		}
		$settings = (array) $widget->get_data( 'settings' );
		if ( empty( $settings['cbx_unfold'] ) || ! is_array( $settings['cbx_unfold'] ) ) {
			return $content;
		}
		$id = method_exists( $widget, 'get_id' ) ? (string) $widget->get_id() : '';
		return self::unfold_html( (string) $content, $settings['cbx_unfold'], $id );
	}

	/**
	 * @param array $c Inställningar: height, height_tablet, height_mobile (px),
	 *                 unfold_text, fold_text, open, gradient, ud/fd (ms), ue/fe (easing).
	 */
	public static function unfold_html( $content, array $c, $element_id ) {
		$open     = ! empty( $c['open'] );
		$gradient = ! empty( $c['gradient'] );
		$ut       = isset( $c['unfold_text'] ) ? (string) $c['unfold_text'] : '';
		$ft       = isset( $c['fold_text'] ) ? (string) $c['fold_text'] : '';
		$js       = array(
			'ut' => $ut,
			'ft' => $ft,
			'ud' => isset( $c['ud'] ) ? (int) $c['ud'] : 800,
			'fd' => isset( $c['fd'] ) ? (int) $c['fd'] : 300,
			'ue' => isset( $c['ue'] ) ? (string) $c['ue'] : 'easeInOutSine',
			'fe' => isset( $c['fe'] ) ? (string) $c['fe'] : 'easeOutSine',
		);

		// Hopfälld höjd per skärmstorlek (som Jets inline-höjd, men responsiv).
		$css = '';
		if ( preg_match( '/^[0-9a-z]+$/i', $element_id ) ) {
			$sel = '.elementor-element-' . $element_id . ' .cbx-unfold:not(.cbx-unfold-state) .cbx-unfold__mask';
			$bps = self::breakpoints();
			foreach ( array( '' => '', '_tablet' => $bps['tablet'], '_mobile' => $bps['mobile'] ) as $suffix => $max ) {
				if ( ! isset( $c[ 'height' . $suffix ] ) || '' === $c[ 'height' . $suffix ] || ! is_numeric( $c[ 'height' . $suffix ] ) ) {
					continue;
				}
				$rule = $sel . '{height:' . (float) $c[ 'height' . $suffix ] . 'px}';
				$css .= '' === $max ? $rule : '@media(max-width:' . (int) $max . 'px){' . $rule . '}';
			}
		}

		return self::once( 'unfold' )
			. ( '' !== $css ? '<style>' . $css . '</style>' : '' )
			. '<div class="cbx-unfold' . ( $open ? ' cbx-unfold-state' : '' ) . '" data-cbx-unfold="' . esc_attr( wp_json_encode( $js ) ) . '">'
			. '<div class="cbx-unfold__inner">'
			. '<div class="cbx-unfold__mask' . ( $gradient ? ' cbx-unfold__mask-gradient' : '' ) . '">'
			. '<div class="cbx-unfold__content"><div class="cbx-unfold__content-inner">' . $content . '</div></div>'
			. ( $gradient ? '' : '<div class="cbx-unfold__separator"></div>' )
			. '</div>'
			. '<div class="cbx-unfold__trigger"><div class="cbx-unfold__button elementor-button elementor-size-md" tabindex="0" role="button" aria-expanded="' . ( $open ? 'true' : 'false' ) . '">'
			. '<span class="cbx-unfold__button-icon"></span><span class="cbx-unfold__button-text">' . esc_html( $open ? $ft : $ut ) . '</span>'
			. '</div></div>'
			. '</div></div>';
	}

	protected static function breakpoints() {
		$bp = array( 'tablet' => 1024, 'mobile' => 767 );
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->breakpoints ) ) {
			try {
				foreach ( \Elementor\Plugin::$instance->breakpoints->get_active_breakpoints() as $name => $b ) {
					if ( isset( $bp[ $name ] ) ) {
						$bp[ $name ] = (int) $b->get_value();
					}
				}
			} catch ( \Throwable $e ) {
				// Standardvärdena gäller.
			}
		}
		return $bp;
	}

	/** Skript: fäll ut/ihop (samma tider och kurvor som Jet). */
	protected static function unfold_js() {
		return <<<'JS'
(function(){if(window.cbxUnfold)return;window.cbxUnfold=1;
var E={linear:'linear',easeInSine:'cubic-bezier(0.12,0,0.39,0)',easeOutSine:'cubic-bezier(0.61,1,0.88,1)',easeInOutSine:'cubic-bezier(0.37,0,0.63,1)',easeInQuad:'cubic-bezier(0.11,0,0.5,0)',easeOutQuad:'cubic-bezier(0.5,1,0.89,1)',easeInOutQuad:'cubic-bezier(0.45,0,0.55,1)',easeInCubic:'cubic-bezier(0.32,0,0.67,0)',easeOutCubic:'cubic-bezier(0.33,1,0.68,1)',easeInOutCubic:'cubic-bezier(0.65,0,0.35,1)'};
function toggle(root){var c={};try{c=JSON.parse(root.getAttribute('data-cbx-unfold')||'{}')}catch(e){}
var m=root.querySelector('.cbx-unfold__mask'),ct=root.querySelector('.cbx-unfold__content'),b=root.querySelector('.cbx-unfold__button'),t=root.querySelector('.cbx-unfold__button-text');if(!m||!ct)return;
var open=root.classList.contains('cbx-unfold-state'),from=m.getBoundingClientRect().height,ms;
m.style.transition='none';m.style.height=from+'px';void m.offsetHeight;
if(!open){ms=c.ud||800;root.classList.add('cbx-unfold-state');m.style.transition='height '+ms+'ms '+(E[c.ue]||'ease');m.style.height=ct.scrollHeight+'px';if(t)t.textContent=c.ft||'';if(b)b.setAttribute('aria-expanded','true');
setTimeout(function(){if(root.classList.contains('cbx-unfold-state')){m.style.transition='none';m.style.height='';}},ms+30);}
else{ms=c.fd||300;root.classList.remove('cbx-unfold-state');m.style.height='';var to=m.getBoundingClientRect().height;m.style.height=from+'px';void m.offsetHeight;m.style.transition='height '+ms+'ms '+(E[c.fe]||'ease');m.style.height=to+'px';if(t)t.textContent=c.ut||'';if(b)b.setAttribute('aria-expanded','false');
setTimeout(function(){if(!root.classList.contains('cbx-unfold-state')){m.style.transition='';m.style.height='';}},ms+30);}}
document.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.cbx-unfold__button');if(!b)return;e.preventDefault();toggle(b.closest('.cbx-unfold'));});
document.addEventListener('keydown',function(e){if(e.key!=='Enter'&&e.key!==' ')return;var b=e.target.closest&&e.target.closest('.cbx-unfold__button');if(!b)return;e.preventDefault();toggle(b.closest('.cbx-unfold'));});})();
JS;
	}

	/* ---------- Sidnumrering (jet-blog-posts-pagination) ---------- */

	/** Jet Blogs pil (pekar åt höger; föregående spegelvänds). */
	protected static function arrow_svg( $dir ) {
		$flip = 'prev' === $dir ? ' style="transform:scaleX(-1)"' : '';
		return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"' . $flip . '><path d="M9.37501 18.6001L7.70001 16.9251L12.65 11.9751L7.70001 7.0251L9.37501 5.3501L16 11.9751L9.37501 18.6001Z" fill="#0F172A"/></svg>';
	}

	public static function sc_pagination( $atts ) {
		$a    = shortcode_atts( array( 'prev' => 'Previous', 'next' => 'Next' ), (array) $atts, 'cbx_posts_pagination' );
		$prev = '<i class="cbx-arrow-prev cbx-blog-arrow">' . self::arrow_svg( 'prev' ) . '</i>' . esc_html( $a['prev'] );
		$next = esc_html( $a['next'] ) . '<i class="cbx-arrow-next cbx-blog-arrow">' . self::arrow_svg( 'next' ) . '</i>';
		$nav  = function_exists( 'get_the_posts_pagination' ) ? get_the_posts_pagination( array( 'prev_text' => $prev, 'next_text' => $next ) ) : '';
		return self::once( 'pagination' ) . '<div class="cbx-posts-pagination">' . $nav . '</div>';
	}

	/* ---------- Sökformulär (jet-search) ---------- */

	public static function sc_search( $atts ) {
		$a     = shortcode_atts(
			array(
				'placeholder' => 'Sök …',
				'submit'      => '1',
				'label'       => '',
				'icon'        => '',
			),
			(array) $atts,
			'cbx_search'
		);
		$value = function_exists( 'get_search_query' ) ? get_search_query() : '';
		$html  = '<div class="cbx-search"><form role="search" method="get" class="cbx-search__form" action="' . esc_url( home_url( '/' ) ) . '">'
			. '<label class="cbx-search__label"><span class="screen-reader-text">' . esc_html( $a['placeholder'] ) . '</span>'
			. '<input type="search" class="cbx-search__field" placeholder="' . esc_attr( $a['placeholder'] ) . '" value="' . esc_attr( $value ) . '" name="s" aria-label="' . esc_attr( $a['placeholder'] ) . '"></label>';
		if ( '1' === (string) $a['submit'] ) {
			$icon = '';
			$spec = json_decode( rawurldecode( (string) $a['icon'] ), true );
			if ( is_array( $spec ) && ! empty( $spec['value'] ) && class_exists( '\\Elementor\\Icons_Manager' ) ) {
				ob_start();
				\Elementor\Icons_Manager::render_icon( $spec, array( 'aria-hidden' => 'true' ) );
				$icon = (string) ob_get_clean();
			}
			$html .= '<button type="submit" class="cbx-search__submit" aria-label="' . esc_attr( '' !== $a['label'] ? $a['label'] : $a['placeholder'] ) . '">'
				. ( '' !== $icon ? '<span class="cbx-search__submit-icon">' . $icon . '</span>' : '' )
				. ( '' !== $a['label'] ? '<span class="cbx-search__submit-label">' . esc_html( $a['label'] ) . '</span>' : '' )
				. '</button>';
		}
		$html .= '</form></div>';
		return self::once( 'search' ) . $html;
	}
}
