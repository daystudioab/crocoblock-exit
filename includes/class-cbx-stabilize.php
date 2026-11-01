<?php
/**
 * Sköra CSS-regler (t.ex. från CSS Hero) pekar på en position i sidans
 * struktur: "html > body > #content > div > … > div:nth-child(2) > a". De
 * slutar gälla så fort strukturen ändras – och konverteringen ändrar den (t.ex.
 * försvinner JetElements parallax-lager, som låg först i sektionerna).
 *
 * Autopiloten slår upp vilka element reglerna träffar på originalsidorna (i
 * webbläsaren) och skapar stabila kopior som pekar på elementets Elementor-ID
 * i stället, med samma deklarationer och högre specificitet. Kopiorna skrivs
 * ut när sidorna konverteras (och alltid i förhandsvisningar på plats).
 *
 * @package CrocoblockExit
 */

defined( 'ABSPATH' ) || exit;

class CBX_Stabilize {

	const OPT = 'cbx_stable_css';

	/** Interaktiva pseudoklasser och pseudoelement som inte kan slås upp i DOM:en. */
	const DYNAMIC = '/((?::{1,2}(?:hover|focus|focus-within|focus-visible|active|visited|link|before|after|placeholder|first-letter|first-line|selection|marker)(?:\([^)]*\))?)+)\s*$/i';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 1000 );
	}

	public static function data() {
		$d = get_option( self::OPT, array() );
		return array_merge( array( 'time' => '', 'css' => '', 'rules' => 0, 'elements' => 0, 'active' => 0 ), is_array( $d ) ? $d : array() );
	}

	public static function print_css() {
		$d = self::data();
		if ( '' === trim( $d['css'] ) || ! ( $d['active'] || CBX_Preview::is_swap() ) ) {
			return;
		}
		echo "\n<style id=\"cbx-stable-css\">\n/* Crocoblock-avveckling: stabila kopior av positionsberoende regler */\n" . wp_strip_all_tags( $d['css'] ) . "\n</style>\n"; // phpcs:ignore
	}

	public static function activate( $on ) {
		$d = self::data();
		if ( '' === trim( $d['css'] ) && $on ) {
			return false;
		}
		$d['active'] = $on ? 1 : 0;
		update_option( self::OPT, $d, true );
		return true;
	}

	/**
	 * Alla regler med minst en skör selektor, ur sajtens egen CSS.
	 *
	 * @return array<int, array{at: string[], selectors: string[], decls: string, source: string}>
	 */
	public static function fragile_rules() {
		$out = array();
		foreach ( CBX_Site_CSS::sources() as $src ) {
			foreach ( CBX_Css::parse( $src['css'] ) as $r ) {
				if ( isset( $r['raw'] ) || '' === trim( $r['decls'] ) ) {
					continue;
				}
				foreach ( $r['selectors'] as $sel ) {
					if ( CBX_Css::is_fragile( $sel ) ) {
						$r['source'] = $src['label'];
						$out[]       = $r;
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Selektorerna som webbläsaren ska slå upp.
	 *
	 * @return array<int, array{sel: string, query: string, pseudo: string}>
	 */
	public static function selectors() {
		$seen = array();
		foreach ( self::fragile_rules() as $r ) {
			foreach ( $r['selectors'] as $sel ) {
				if ( ! CBX_Css::is_fragile( $sel ) || isset( $seen[ $sel ] ) ) {
					continue;
				}
				$pseudo       = preg_match( self::DYNAMIC, $sel, $m ) ? $m[1] : '';
				$seen[ $sel ] = array(
					'sel'    => $sel,
					'query'  => trim( '' !== $pseudo ? substr( $sel, 0, -strlen( $m[0] ) ) : $sel ),
					'pseudo' => trim( $pseudo ),
				);
			}
		}
		return array_values( $seen );
	}

	/**
	 * Bygger CSS:en av webbläsarens uppslag.
	 *
	 * @param array<string, string[]> $map Skör selektor => stabila selektorer (utan pseudodel).
	 * @return array{rules: int, elements: int}
	 */
	public static function build( array $map ) {
		$pseudo = array();
		foreach ( self::selectors() as $s ) {
			$pseudo[ $s['sel'] ] = $s['pseudo'];
		}
		$rules    = array();
		$elements = array();
		foreach ( self::fragile_rules() as $r ) {
			$groups = array(); // media ('' = alla bredder) => selektorer
			foreach ( $r['selectors'] as $sel ) {
				if ( empty( $map[ $sel ] ) || ! is_array( $map[ $sel ] ) ) {
					continue;
				}
				foreach ( $map[ $sel ] as $entry ) {
					// Sträng (alla bredder) eller {s, media[]} (bara där originalet träffade).
					$stable = is_array( $entry ) ? ( isset( $entry['s'] ) ? $entry['s'] : '' ) : $entry;
					$media  = is_array( $entry ) && ! empty( $entry['media'] ) ? (array) $entry['media'] : array( '' );
					$stable = trim( wp_strip_all_tags( (string) $stable ) );
					if ( '' === $stable || preg_match( '/[{};<]/', $stable ) ) {
						continue;
					}
					foreach ( $media as $m ) {
						$m = trim( (string) $m );
						if ( '' !== $m && ! preg_match( '/^\((min|max)-width:\s*\d+px\)(\s+and\s+\((min|max)-width:\s*\d+px\))?$/', $m ) ) {
							continue;
						}
						$groups[ $m ][] = $stable . ( isset( $pseudo[ $sel ] ) ? $pseudo[ $sel ] : '' );
					}
					$elements[ $stable ] = true;
				}
			}
			foreach ( $groups as $m => $sels ) {
				$rules[] = array(
					'at'        => '' === $m ? $r['at'] : array_merge( $r['at'], array( '@media ' . $m ) ),
					'selectors' => array_values( array_unique( $sels ) ),
					'decls'     => $r['decls'],
				);
			}
		}
		$d             = self::data();
		$d['time']     = current_time( 'mysql' );
		$d['css']      = CBX_Css::render( $rules );
		$d['rules']    = count( $rules );
		$d['elements'] = count( $elements );
		update_option( self::OPT, $d, true );
		return array( 'rules' => count( $rules ), 'elements' => count( $elements ) );
	}
}
