<?php
/**
 * Avveckling av CSS Hero.
 *
 * CSS Hero sparar sina ändringar och levererar dem som en statisk CSS-fil
 * (uploads/…/csshero-static-style-<tema>.css) eller som inbäddad <style>.
 * Den här klassen läser exakt den CSS besökarna får, flyttar den till
 * WordPress egen "Ytterligare CSS" (Utseende → Anpassa) och avaktiverar
 * CSS Hero – med backup och ångra.
 *
 * Regler som pekar på Crocoblocks klasser översätts sedan av steget
 * "Sajtens egen CSS", som läser Ytterligare CSS.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_CSS_Hero {

	const OPT_BACKUP = 'cbx_csshero_backup';
	const START      = '/* cbx:csshero:start';
	const END        = '/* cbx:csshero:end */';

	/* ---------- Status ---------- */

	/**
	 * @return array{file: string, name: string, version: string, active: bool}|null
	 */
	public static function plugin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( preg_match( '#^css-?hero#i', dirname( $file ) ) || 'CSS Hero' === $data['Name'] ) {
				return array( 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => is_plugin_active( $file ) );
			}
		}
		return null;
	}

	public static function backup() {
		$b = get_option( self::OPT_BACKUP, null );
		return is_array( $b ) ? $b : null;
	}

	/* ---------- Läsa CSS Heros CSS ---------- */

	/**
	 * Den CSS CSS Hero levererar till besökare.
	 *
	 * @return array{css: string, sources: string[]}
	 */
	public static function collect() {
		$sources = array();
		$css     = '';

		// 1. Det besökarna faktiskt får: startsidans HTML.
		$res = function_exists( 'wp_remote_get' ) ? wp_remote_get( home_url( '/' ), array( 'timeout' => 15, 'sslverify' => false ) ) : null;
		if ( $res && ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$html = (string) wp_remote_retrieve_body( $res );
			if ( preg_match_all( '#<link[^>]+href=["\']([^"\']*csshero[^"\']*\.css[^"\']*)["\']#i', $html, $m ) ) {
				foreach ( array_unique( $m[1] ) as $href ) {
					$file = self::url_to_path( html_entity_decode( $href ) );
					if ( $file && is_readable( $file ) ) {
						$css      .= file_get_contents( $file ) . "\n"; // phpcs:ignore
						$sources[] = self::short( $file );
					}
				}
			}
			if ( preg_match_all( '#<style[^>]+id=["\'][^"\']*csshero[^"\']*["\'][^>]*>(.*?)</style>#is', $html, $m ) ) {
				$css      .= implode( "\n", $m[1] ) . "\n";
				$sources[] = 'Inbäddad <style> från CSS Hero';
			}
		}

		// 2. Reserv: senaste statiska filen för det aktiva temat.
		if ( '' === trim( $css ) ) {
			$file = self::latest_static_file();
			if ( $file ) {
				$css       = (string) file_get_contents( $file ); // phpcs:ignore
				$sources[] = self::short( $file );
			}
		}
		return array( 'css' => trim( $css ), 'sources' => $sources );
	}

	protected static function latest_static_file() {
		$upload = wp_upload_dir();
		$theme  = function_exists( 'get_stylesheet' ) ? get_stylesheet() : '';
		$best   = null;
		$time   = 0;
		$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $upload['basedir'], FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$name = $f->getFilename();
			if ( 0 !== strpos( $name, 'csshero-static-style' ) || 'css' !== $f->getExtension() ) {
				continue;
			}
			$exact = $theme && 'csshero-static-style-' . $theme . '.css' === $name;
			$score = $f->getMTime() + ( $exact ? 10 ** 10 : 0 );
			if ( $score > $time ) {
				$time = $score;
				$best = $f->getPathname();
			}
		}
		return $best;
	}

	protected static function url_to_path( $url ) {
		$url    = preg_replace( '/\?.*$/', '', $url );
		$upload = wp_upload_dir();
		$map    = array(
			$upload['baseurl']   => $upload['basedir'],
			content_url()        => WP_CONTENT_DIR,
		);
		foreach ( $map as $u => $p ) {
			$u = preg_replace( '#^https?:#', '', $u );
			$s = preg_replace( '#^https?:#', '', $url );
			if ( 0 === strpos( $s, $u ) ) {
				return $p . substr( $s, strlen( $u ) );
			}
		}
		return null;
	}

	protected static function short( $path ) {
		return ltrim( str_replace( WP_CONTENT_DIR, 'wp-content', $path ), '/' );
	}

	/* ---------- Analys ---------- */

	/**
	 * @return array{rules: int, jet: int, fragile: string[], imports: int, fonts: int}
	 */
	public static function analyze( $css ) {
		$rules   = CBX_Css::parse( $css );
		$fragile = array();
		$jet     = 0;
		$n       = 0;
		$fonts   = 0;
		foreach ( $rules as $r ) {
			if ( isset( $r['raw'] ) ) {
				$fonts += ( 0 === stripos( $r['raw'], '@font-face' ) ) ? 1 : 0;
				continue;
			}
			$n++;
			foreach ( $r['selectors'] as $sel ) {
				if ( preg_match( '/jet[-_]/', $sel ) ) {
					$jet++;
					break;
				}
			}
			foreach ( $r['selectors'] as $sel ) {
				// Sökvägar genom sidans struktur slutar fungera så fort layouten ändras.
				if ( CBX_Css::is_fragile( $sel ) ) {
					$fragile[] = $sel;
				}
			}
		}
		return array(
			'rules'   => $n,
			'jet'     => $jet,
			'fragile' => array_values( array_unique( $fragile ) ),
			'imports' => preg_match_all( '/@import\s/i', $css ),
			'fonts'   => $fonts,
		);
	}

	/**
	 * Allt som visas i admin och inventering.
	 */
	public static function status( $light = false ) {
		$plugin = self::plugin();
		if ( ! $plugin && ! self::backup() ) {
			return null;
		}
		$data = array( 'css' => '', 'sources' => array() );
		if ( $plugin && $plugin['active'] ) {
			if ( $light ) {
				$file = self::latest_static_file();
				if ( $file ) {
					$data = array( 'css' => (string) file_get_contents( $file ), 'sources' => array( self::short( $file ) ) ); // phpcs:ignore
				}
			} else {
				$data = self::collect();
			}
		}
		return array(
			'plugin'   => $plugin,
			'sources'  => $data['sources'],
			'css'      => $data['css'],
			'analysis' => self::analyze( $data['css'] ),
			'backup'   => self::backup(),
		);
	}

	/* ---------- Flytta ---------- */

	/**
	 * Bygger det nya innehållet i Ytterligare CSS: befintligt innehåll plus ett
	 * avgränsat block med CSS Heros CSS. @import flyttas överst (krav i CSS).
	 */
	public static function merge_custom_css( $current, $hero_css, $time ) {
		$current = self::strip_block( (string) $current );
		$imports = array();
		$hero    = preg_replace_callback(
			'/@(import|charset)\s[^;]+;\s*/i',
			function ( $m ) use ( &$imports ) {
				if ( 0 === stripos( $m[0], '@import' ) ) {
					$imports[] = trim( $m[0] );
				}
				return '';
			},
			(string) $hero_css
		);
		$block = self::START . ' – flyttad från CSS Hero ' . $time . " */\n" . trim( $hero ) . "\n" . self::END;
		// Befintliga @import i Ytterligare CSS måste också ligga först.
		$current = preg_replace_callback(
			'/@import\s[^;]+;\s*/i',
			function ( $m ) use ( &$imports ) {
				$imports[] = trim( $m[0] );
				return '';
			},
			$current
		);
		$head = $imports ? implode( "\n", array_unique( $imports ) ) . "\n\n" : '';
		return trim( $head . ( '' !== trim( $current ) ? trim( $current ) . "\n\n" : '' ) . $block ) . "\n";
	}

	public static function strip_block( $css ) {
		$start = strpos( $css, self::START );
		$end   = strpos( $css, self::END );
		if ( false === $start || false === $end || $end < $start ) {
			return $css;
		}
		return rtrim( substr( $css, 0, $start ) ) . "\n" . ltrim( substr( $css, $end + strlen( self::END ) ) );
	}

	/**
	 * Flyttar CSS Heros CSS till Ytterligare CSS och avaktiverar CSS Hero.
	 *
	 * @return array|WP_Error
	 */
	public static function migrate( $deactivate = true ) {
		$plugin = self::plugin();
		if ( ! $plugin || ! $plugin['active'] ) {
			return new WP_Error( 'cbx_hero', 'CSS Hero är inte aktivt – det finns ingen CSS att läsa.' );
		}
		$data = self::collect();
		if ( '' === $data['css'] ) {
			return new WP_Error( 'cbx_hero', 'Hittade ingen CSS från CSS Hero. Kontrollera att CSS Hero har sparat sina ändringar.' );
		}
		$current = function_exists( 'wp_get_custom_css' ) ? wp_get_custom_css() : '';
		if ( ! self::backup() ) {
			update_option(
				self::OPT_BACKUP,
				array(
					'time'       => current_time( 'mysql' ),
					'custom_css' => $current,
					'plugin'     => $plugin['file'],
					'hero_css'   => $data['css'],
					'imports'    => preg_match_all( '/@import\s[^;]+;/i', $data['css'], $mm ) ? array_map( 'trim', $mm[0] ) : array(),
				),
				false
			);
		}
		$new = self::merge_custom_css( $current, $data['css'], current_time( 'mysql' ) );
		$res = wp_update_custom_css_post( $new );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $deactivate ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( $plugin['file'] );
		}
		CBX_Plugin::clear_all_cache();
		return array( 'moved' => self::analyze( $data['css'] )['rules'], 'sources' => $data['sources'], 'deactivated' => $deactivate );
	}

	/**
	 * Ångrar: återställer Ytterligare CSS och aktiverar CSS Hero igen.
	 */
	public static function undo() {
		$b = self::backup();
		if ( ! $b ) {
			return new WP_Error( 'cbx_hero', 'Det finns ingen CSS Hero-flytt att ångra.' );
		}
		// Ta bara bort CSS Hero-blocket, så att senare ändringar i Ytterligare CSS finns kvar.
		$current = self::strip_block( (string) wp_get_custom_css() );
		foreach ( isset( $b['imports'] ) ? (array) $b['imports'] : array() as $import ) {
			$current = str_replace( $import, '', $current );
		}
		wp_update_custom_css_post( trim( $current ) . "\n" );
		if ( ! empty( $b['plugin'] ) ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			activate_plugin( $b['plugin'] );
		}
		delete_option( self::OPT_BACKUP );
		CBX_Plugin::clear_all_cache();
		return true;
	}
}
