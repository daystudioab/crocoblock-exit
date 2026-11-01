<?php
/**
 * Sajtens egen CSS som pekar på Crocoblock.
 *
 * Många sajter har stil skriven direkt mot Crocoblocks klasser – i temat, i
 * egna tillägg, i CSS Hero, under Utseende → Anpassa eller i Elementors
 * Custom CSS/Custom Code. När widgeten byts ut träffar de reglerna ingenting.
 *
 * Den här klassen letar upp sådana regler, översätter dem till de nya
 * widgetarnas markup och skriver ut kopiorna sist i <head>. Originalfilerna
 * rörs inte. Kopiorna är avgränsade till konverterade widgetar (.cbx-from-…)
 * och påverkar inget annat.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Site_CSS {

	const OPT = 'cbx_site_css';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 999 );
	}

	public static function data() {
		$d = get_option( self::OPT, array() );
		return array_merge( array( 'time' => '', 'enabled' => 0, 'css' => '', 'extra' => '', 'sources' => array() ), is_array( $d ) ? $d : array() );
	}

	public static function print_css() {
		$d   = self::data();
		$css = trim( ( $d['enabled'] ? $d['css'] : '' ) . "\n" . $d['extra'] );
		if ( '' === $css ) {
			return;
		}
		echo "\n<style id=\"cbx-site-css\">\n/* Crocoblock-avveckling: översatta kopior av sajtens egen CSS */\n" . wp_strip_all_tags( $css ) . "\n</style>\n"; // phpcs:ignore
	}

	/* ---------- Källor ---------- */

	/**
	 * @return array<int, array{label: string, kind: string, css: string}>
	 */
	public static function sources() {
		global $wpdb;
		$out = array();

		// Utseende → Anpassa → Ytterligare CSS.
		if ( function_exists( 'wp_get_custom_css' ) ) {
			$css = wp_get_custom_css();
			if ( $css ) {
				$out[] = array( 'label' => 'Utseende → Anpassa → Ytterligare CSS', 'kind' => 'db', 'css' => $css );
			}
		}

		// Elementors Custom CSS på sidnivå och i webbplatsinställningarna (kit).
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_page_settings' AND meta_value LIKE '%custom_css%' AND meta_value LIKE '%jet%'" ); // phpcs:ignore
		foreach ( (array) $rows as $row ) {
			$settings = maybe_unserialize( $row->meta_value );
			if ( is_array( $settings ) && ! empty( $settings['custom_css'] ) ) {
				$out[] = array( 'label' => 'Elementor Custom CSS: ' . get_the_title( $row->post_id ) . ' (#' . (int) $row->post_id . ')', 'kind' => 'db', 'css' => $settings['custom_css'] );
			}
		}

		// Elementor Pro Custom Code.
		$rows = $wpdb->get_results( "SELECT p.ID, m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'elementor_snippet' AND m.meta_key = '_elementor_code' AND m.meta_value LIKE '%jet%'" ); // phpcs:ignore
		foreach ( (array) $rows as $row ) {
			if ( preg_match_all( '#<style[^>]*>(.*?)</style>#is', $row->meta_value, $m ) ) {
				$out[] = array( 'label' => 'Elementor Custom Code: ' . get_the_title( $row->ID ), 'kind' => 'db', 'css' => implode( "\n", $m[1] ) );
			}
		}

		// Filer: tema, barntema, egna tillägg, uppladdningar (CSS Hero m.fl.).
		foreach ( self::css_files() as $file => $label ) {
			$css = (string) file_get_contents( $file ); // phpcs:ignore
			// Regler mot Crocoblock, eller positionsberoende regler (CSS Hero) som autopiloten stabiliserar.
			if ( preg_match( CBX_Css::JET_SEL, $css ) || preg_match( '/html\s*>\s*body/i', $css ) ) {
				$out[] = array( 'label' => $label, 'kind' => 'file', 'css' => $css );
			}
		}
		return $out;
	}

	/**
	 * CSS-filer som kan innehålla egen stil. Crocoblocks och Elementors egna
	 * filer hoppas över (de försvinner/genereras om), liksom tredjepartsbibliotek.
	 */
	protected static function css_files() {
		$dirs = array();
		$dirs[ get_stylesheet_directory() ] = 'Tema: ' . wp_get_theme()->get( 'Name' );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$dirs[ get_template_directory() ] = 'Föräldratema: ' . wp_get_theme( get_template() )->get( 'Name' );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$folder = dirname( $plugin );
			if ( '.' === $folder || preg_match( '/^(jet-|jetengine|elementor|crocoblock-exit|woocommerce|wordpress-seo|jetpack|wpforms|gravityforms)/', $folder ) ) {
				continue;
			}
			$dirs[ WP_PLUGIN_DIR . '/' . $folder ] = 'Tillägg: ' . $folder;
		}
		$upload                     = wp_upload_dir();
		$dirs[ $upload['basedir'] ] = 'Uppladdningar';

		$files = array();
		foreach ( $dirs as $dir => $label ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$n  = 0;
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ++$n > 5000 ) {
					break;
				}
				$path = $f->getPathname();
				if ( 'css' !== strtolower( $f->getExtension() ) || $f->getSize() > 2 * 1024 * 1024 ) {
					continue;
				}
				$rel = ltrim( str_replace( $dir, '', $path ), '/' );
				// CSS Heros fil räknas inte när den redan flyttats till Ytterligare CSS.
				if ( false !== strpos( $rel, 'csshero' ) && get_option( 'cbx_csshero_backup' ) ) {
					continue;
				}
				if ( preg_match( '#(^|/)(node_modules|vendor|vendors|lib|libs|bower_components)/|(^|/)elementor/(css|google-fonts)/|(^|/)jet-[\w-]+/|(^|/)cache/#', $rel ) ) {
					continue;
				}
				$files[ $path ] = $label . ' – ' . $rel;
			}
		}
		return $files;
	}

	/* ---------- Bygga översättningen ---------- */

	/**
	 * Letar upp, översätter och sparar. Aktiverar utskriften direkt –
	 * kopiorna träffar bara konverterade widgetar och är därför ofarliga
	 * innan konverteringen.
	 */
	public static function build() {
		$report = array();
		$all    = '';
		foreach ( self::sources() as $src ) {
			$res = CBX_Css::translate_site_css( $src['css'] );
			if ( ! $res['translated'] && ! $res['untranslated'] ) {
				continue;
			}
			$report[] = array(
				'label'        => $src['label'],
				'kind'         => $src['kind'],
				'translated'   => $res['translated'],
				'untranslated' => array_slice( $res['untranslated'], 0, 30 ),
			);
			if ( '' !== trim( $res['css'] ) ) {
				$all .= '/* Från: ' . str_replace( '*/', '', $src['label'] ) . " */\n" . $res['css'] . "\n";
			}
		}
		$d            = self::data();
		$d['time']    = current_time( 'mysql' );
		$d['css']     = $all;
		$d['sources'] = $report;
		$d['enabled'] = 1;
		update_option( self::OPT, $d, true );
		return $d;
	}

	public static function save_settings( $enabled, $extra ) {
		$d            = self::data();
		$d['enabled'] = $enabled ? 1 : 0;
		$d['extra']   = (string) $extra;
		update_option( self::OPT, $d, true );
	}

	/**
	 * Sammanfattning för inventeringen (utan att spara något).
	 */
	public static function summary() {
		$out = array();
		foreach ( self::sources() as $src ) {
			$res = CBX_Css::translate_site_css( $src['css'] );
			if ( $res['translated'] || $res['untranslated'] ) {
				$out[] = array( 'label' => $src['label'], 'translated' => $res['translated'], 'untranslated' => count( $res['untranslated'] ) );
			}
		}
		return $out;
	}
}
