<?php
/**
 * Förhandsgranskning: en konverterad kopia av en sida som utkast, så att man
 * kan jämföra med originalet innan något ändras på riktigt.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Preview {

	const META = '_cbx_preview_of';
	const MODE = '_cbx_preview_mode';

	/** Pågående "på plats"-förhandsvisning: [källa => kopia]. */
	protected static $swap = array();

	/**
	 * Förhandsvisning på plats: originalsidans adress visas, men Elementor
	 * hämtar den konverterade kopian i stället för originalet (sida, sidhuvud,
	 * sidfot, inläggsmall …). Allt annat – sidans ID, body-klasser, dynamiskt
	 * innehåll, temat och andra tillägg – är exakt som för originalet. Kräver
	 * administratör och en giltig nonce i adressen.
	 */
	public static function init() {
		if ( is_admin() || empty( $_GET['cbx_swap'] ) ) { // phpcs:ignore
			return;
		}
		add_action( 'init', array( __CLASS__, 'maybe_swap' ), 1 );
	}

	public static function maybe_swap() {
		$raw   = sanitize_text_field( wp_unslash( $_GET['cbx_swap'] ) ); // phpcs:ignore
		$nonce = isset( $_GET['cbx_swap_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['cbx_swap_nonce'] ) ) : ''; // phpcs:ignore
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'cbx_swap_' . $raw ) ) {
			return;
		}
		foreach ( explode( '.', $raw ) as $pair ) {
			list( $src, $copy ) = array_map( 'absint', array_pad( explode( '-', $pair ), 2, 0 ) );
			if ( $src && $copy && (int) get_post_meta( $copy, self::META, true ) === self::source_of_swap( $src, $copy ) ) {
				self::$swap[ $src ] = $copy;
			}
		}
		if ( ! self::$swap ) {
			return;
		}
		nocache_headers();
		add_filter( 'elementor/documents/get/post_id', array( __CLASS__, 'swap_id' ) );
		add_filter( 'elementor/frontend/builder_content/before_enqueue_css_file', array( __CLASS__, 'swap_css' ) );
	}

	/** Kopians källa – för migrerade Jet-mallar är källan den migrerade mallen. */
	protected static function source_of_swap( $src, $copy ) {
		$of = (int) get_post_meta( $copy, self::META, true );
		if ( $of === $src ) {
			return $src;
		}
		$map = (array) get_option( CBX_Templates::OPT_THEME, array() );
		return isset( $map[ $src ] ) && (int) $map[ $src ] === $of ? $of : -1;
	}

	public static function is_swap() {
		return (bool) self::$swap;
	}

	public static function swap_id( $post_id ) {
		return isset( self::$swap[ (int) $post_id ] ) ? self::$swap[ (int) $post_id ] : $post_id;
	}

	public static function swap_css( $css_file ) {
		if ( is_object( $css_file ) && method_exists( $css_file, 'get_post_id' ) && isset( self::$swap[ (int) $css_file->get_post_id() ] ) && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return \Elementor\Core\Files\CSS\Post::create( self::$swap[ (int) $css_file->get_post_id() ] );
		}
		return $css_file;
	}

	/**
	 * Adress till originalsidan där $src byts mot kopian $copy.
	 *
	 * @param array<int,int> $pairs [källa => kopia]
	 */
	public static function swap_url( $url, array $pairs ) {
		$parts = array();
		foreach ( $pairs as $src => $copy ) {
			$parts[] = (int) $src . '-' . (int) $copy;
		}
		$raw = implode( '.', $parts );
		return add_query_arg( array( 'cbx_swap' => $raw, 'cbx_swap_nonce' => wp_create_nonce( 'cbx_swap_' . $raw ) ), $url );
	}

	/** Länk som skapar/uppdaterar förhandsvisningen och öppnar den. */
	public static function link( $post_id ) {
		// Rå URL (inte wp_nonce_url, som HTML-kodar & till &amp;). Den escapas där den skrivs ut.
		return add_query_arg(
			array(
				'action'   => 'cbx_preview',
				'post'     => (int) $post_id,
				'_wpnonce' => wp_create_nonce( 'cbx_preview_' . (int) $post_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Skapar eller uppdaterar förhandsvisningen.
	 *
	 * @param string $mode view = egen sida att öppna (mallar visas på en tom
	 *                     sida); swap = kopia av samma typ, för förhandsvisning
	 *                     på plats (swap_url).
	 * @return int|WP_Error Kopians ID.
	 */
	public static function create( $post_id, $mode = 'view' ) {
		$post = get_post( $post_id );
		$data = CBX_Plugin::get_data( $post_id );
		if ( ! $post || ! $data ) {
			return new WP_Error( 'cbx_preview', 'Sidan saknar Elementor-innehåll.' );
		}
		$mapper            = CBX_Plugin::mapper();
		list( $new, $log ) = $mapper->convert_document( $data, 'page' );
		list( $new, $log, $free ) = CBX_Styles::apply( $post_id, $new, $log );

		// Mallar (sidhuvud, sidfot, popup …) förhandsgranskas som en tom sida
		// (Elementor Canvas) – Elementor-mallar som utkast går inte att öppna
		// direkt på sajten.
		$swap        = 'swap' === $mode;
		$is_template = 'elementor_library' === $post->post_type && ! $swap;
		$type        = $is_template ? 'page' : $post->post_type;

		$existing = self::find( $post_id, $mode );
		if ( $existing && get_post_type( $existing ) !== $type ) {
			wp_update_post( array( 'ID' => $existing, 'post_type' => $type, 'post_status' => 'draft' ) );
			wp_delete_object_term_relationships( $existing, 'elementor_library_type' );
		}
		if ( $existing ) {
			$copy = $existing;
		} else {
			$copy = wp_insert_post(
				array(
					'post_type'   => $type,
					'post_status' => 'draft',
					'post_title'  => '[Förhandsvisning] ' . $post->post_title,
					'post_author' => get_current_user_id(),
					'post_parent' => $is_template ? 0 : $post->post_parent,
				),
				true
			);
			if ( is_wp_error( $copy ) ) {
				return $copy;
			}
		}
		foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_wp_page_template', '_elementor_page_settings' ) as $key ) {
			$val = get_post_meta( $post_id, $key, true );
			if ( '' !== $val ) {
				update_post_meta( $copy, $key, $val );
			}
		}
		if ( $swap && 'elementor_library' === $post->post_type ) {
			$tpl = get_post_meta( $post_id, '_elementor_template_type', true );
			wp_set_object_terms( $copy, $tpl ? $tpl : 'section', 'elementor_library_type' );
			delete_post_meta( $copy, '_elementor_conditions' );
		}
		if ( $is_template ) {
			update_post_meta( $copy, '_elementor_edit_mode', 'builder' );
			update_post_meta( $copy, '_elementor_template_type', 'wp-page' );
			update_post_meta( $copy, '_wp_page_template', 'elementor_canvas' );
			delete_post_meta( $copy, '_elementor_conditions' );
		}
		update_post_meta( $copy, '_elementor_data', wp_slash( wp_json_encode( $new ) ) );
		update_post_meta( $copy, self::META, $post_id );
		update_post_meta( $copy, self::MODE, $swap ? 'swap' : 'view' );
		delete_post_meta( $copy, CBX_Styles::META_FREE );
		CBX_Styles::store_free( $copy, $free );
		CBX_Plugin::clear_post_cache( $copy );
		return $copy;
	}

	public static function find( $post_id, $mode = 'view' ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META, (string) $post_id ) );
		foreach ( (array) $ids as $id ) {
			$m = get_post_meta( (int) $id, self::MODE, true );
			if ( ( 'swap' === $mode ) === ( 'swap' === $m ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	public static function all() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ) );
	}

	public static function url( $copy_id ) {
		return get_preview_post_link( $copy_id );
	}

	/**
	 * Raderar alla förhandsvisningar (bara kopior som det här tillägget skapat).
	 */
	public static function cleanup() {
		$n = 0;
		foreach ( self::all() as $id ) {
			if ( get_post_meta( $id, self::META, true ) && wp_delete_post( $id, true ) ) {
				$n++;
			}
		}
		return $n;
	}
}
