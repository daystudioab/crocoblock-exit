<?php
/**
 * Stilöverföring: Crocoblocks utseende följer med till de nya widgetarna.
 *
 * Elementor genererar CSS för varje widget utifrån dess inställningar. Medan
 * Crocoblock fortfarande är aktivt läser vi den CSS:en för Jet-widgeten,
 * översätter klasserna (CBX_Css) och lägger resultatet på den nya widgeten:
 *  - med Elementor Pro som widgetens egen Custom CSS (syns och kan redigeras
 *    under Avancerat → Custom CSS),
 *  - utan Pro i postmeta som läggs till i Elementors CSS-fil för sidan.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Styles {

	const META_FREE = '_cbx_element_css';

	/** @var array<int, array> Cache: post-ID → tolkade regler. */
	protected static $cache = array();

	public static function init() {
		// Utan Pro: lägg in sparad CSS när Elementor bygger sidans CSS-fil.
		add_action( 'elementor/element/parse_css', array( __CLASS__, 'inject_free_css' ), 20, 2 );
	}

	/* ---------- Läsa Crocoblocks CSS ---------- */

	/**
	 * Tolkade CSS-regler för en post, så som Elementor genererar dem just nu.
	 */
	public static function source_rules( $post_id ) {
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}
		$css = self::generate_css( $post_id );
		if ( '' === $css ) {
			$css = self::stored_css( $post_id );
		}
		self::$cache[ $post_id ] = '' === $css ? array() : CBX_Css::parse( $css );
		return self::$cache[ $post_id ];
	}

	public static function forget( $post_id ) {
		unset( self::$cache[ $post_id ] );
	}

	/**
	 * Låter Elementor generera postens CSS på nytt (kräver att Crocoblock är aktivt,
	 * annars känner Elementor inte igen Jet-widgetarnas stilinställningar).
	 */
	protected static function generate_css( $post_id ) {
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return '';
		}
		require_once __DIR__ . '/class-cbx-post-css.php';
		try {
			$file = new CBX_Post_CSS( $post_id );
			return (string) $file->cbx_generate();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Reserv: den CSS-fil eller inbäddade CSS Elementor redan har sparat.
	 */
	protected static function stored_css( $post_id ) {
		$meta = get_post_meta( $post_id, '_elementor_css', true );
		if ( is_array( $meta ) && ! empty( $meta['css'] ) ) {
			return (string) $meta['css'];
		}
		$upload = wp_upload_dir();
		$file   = trailingslashit( $upload['basedir'] ) . 'elementor/css/post-' . (int) $post_id . '.css';
		if ( is_readable( $file ) ) {
			return (string) file_get_contents( $file ); // phpcs:ignore
		}
		return '';
	}

	/* ---------- Tillämpa på konverterad data ---------- */

	/**
	 * Lägger översatt stil på de konverterade widgetarna.
	 *
	 * @param int   $source_id Posten vars Jet-CSS ska läsas.
	 * @param array $data      Konverterad Elementor-data.
	 * @param array $log       Mapperns logg (kompletteras med anteckningar).
	 * @param bool  $has_pro   Använd Elementor Pros Custom CSS.
	 * @return array{0: array, 1: array, 2: array} [data, logg, css per element (utan Pro)]
	 */
	public static function apply( $source_id, array $data, array $log, $has_pro = null ) {
		$has_pro = null === $has_pro ? CBX_Plugin::has_pro() : $has_pro;
		$rules   = self::source_rules( $source_id );
		$types   = array();
		foreach ( $log as $i => $row ) {
			if ( 'converted' === $row['status'] && '' !== $row['id'] ) {
				$types[ $row['id'] ] = $i;
			}
		}
		$free = array();
		$data = self::walk( $data, $types, $rules, $log, $free, $has_pro );
		return array( $data, $log, $free );
	}

	protected static function walk( array $elements, array $types, array $rules, array &$log, array &$free, $has_pro ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id       = isset( $el['id'] ) ? (string) $el['id'] : '';
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$existing = isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '';

			if ( isset( $types[ $id ] ) ) {
				$i    = $types[ $id ];
				$type = $log[ $i ]['from'];
				$res  = CBX_Css::element_css( $type, $rules, $id, $existing );
				if ( '' !== trim( $res['css'] ) ) {
					if ( $has_pro ) {
						$settings['custom_css'] = $res['css'];
					} else {
						unset( $settings['custom_css'] );
						$free[ $id ] = $res['css'];
					}
					$el['settings'] = $settings;
				}
				if ( $res['mapped'] ) {
					$log[ $i ]['notes'][] = sprintf( 'Stil överförd (%d regler)%s.', $res['mapped'], $has_pro ? ' – syns under Avancerat → Custom CSS' : '' );
				}
				if ( $res['unmapped'] ) {
					$log[ $i ]['notes'][] = sprintf( '%d stilregler saknar motsvarighet; värdena ligger som kommentar i widgetens CSS.', $res['unmapped'] );
				}
				if ( ! $res['mapped'] && ! $res['unmapped'] && ! CBX_Css::map_for( $type ) ) {
					$log[ $i ]['notes'][] = 'Ingen stilöversättning finns för den här widgettypen – kontrollera utseendet.';
				}
			} elseif ( '' !== $existing && preg_match( '/jet[-_]/', $existing ) ) {
				// Egen Custom CSS på t.ex. en sektion som pekar på Jet-klasser inuti.
				$el['settings']['custom_css'] = CBX_Css::translate_scoped_custom_css( $existing );
				$log[] = array( 'id' => $id, 'from' => isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : 'element' ), 'to' => '', 'status' => 'cleaned', 'notes' => array( 'Elementets Custom CSS pekade på Crocoblock-klasser och har fått översatta kopior.' ) );
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk( $el['elements'], $types, $rules, $log, $free, $has_pro );
			}
		}
		unset( $el );
		return $elements;
	}

	/**
	 * Sparar CSS för sajter utan Pro och nollställer Elementors CSS-cache för posten.
	 */
	public static function store_free( $post_id, array $free ) {
		if ( $free ) {
			$current = get_post_meta( $post_id, self::META_FREE, true );
			$current = is_array( $current ) ? $current : array();
			update_post_meta( $post_id, self::META_FREE, array_merge( $current, $free ) );
		}
	}

	/* ---------- Utan Pro: in i Elementors CSS-fil ---------- */

	public static function inject_free_css( $post_css, $element ) {
		if ( CBX_Plugin::has_pro() || ! is_object( $post_css ) || ! method_exists( $post_css, 'get_post_id' ) ) {
			return;
		}
		$map = get_post_meta( $post_css->get_post_id(), self::META_FREE, true );
		$id  = method_exists( $element, 'get_id' ) ? $element->get_id() : '';
		if ( ! is_array( $map ) || empty( $map[ $id ] ) ) {
			return;
		}
		$css = str_replace( 'selector', $post_css->get_element_unique_selector( $element ), $map[ $id ] );
		$post_css->get_stylesheet()->add_raw_css( $css );
	}
}
