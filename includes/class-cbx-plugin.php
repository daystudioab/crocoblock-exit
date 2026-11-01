<?php
/**
 * Gemensamma hjälpare: Elementor-data, cache, Crocoblock-tillägg, mapper-kontext.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Plugin {

	const BACKUP_META  = '_cbx_backup_elementor_data';
	const BACKUP_TIME  = '_cbx_backup_time';
	const LOG_META     = '_cbx_log';
	const OPT_SETTINGS = 'cbx_settings';

	/**
	 * Crocoblocks egna posttyper. De konverteras inte som "sidor":
	 * mallarna migreras separat och resten försvinner med tilläggen.
	 */
	public static function retired_post_types() {
		return array( 'jet-theme-core', 'jet-popup', 'jet-engine', 'jet-menu', 'jet-smart-filters', 'jet_options_preset', 'jet-woo-builder', 'jet-engine-booking', 'jet-form-builder' );
	}

	public static function boot() {
		CBX_Content_Types::init();
		CBX_Styles::init();
		CBX_Site_CSS::init();
		CBX_Queries::init();
		CBX_Autopilot::init();
		CBX_Preview::init();
		CBX_Stabilize::init();
		CBX_Updater::init();
		if ( is_admin() ) {
			CBX_Admin::init();
		}
	}

	/* ---------- Inställningar ---------- */

	public static function settings() {
		$defaults = array(
			'clean_ext'      => 1,
			'include_drafts' => 1,
		);
		return array_merge( $defaults, (array) get_option( self::OPT_SETTINGS, array() ) );
	}

	/* ---------- Miljö ---------- */

	public static function has_elementor() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	public static function has_pro() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Aktiva (och inaktiva) Crocoblock-tillägg.
	 *
	 * @return array<string, array{name:string, version:string, active:bool}>
	 */
	public static function jet_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$folder = dirname( $file );
			$author = isset( $data['Author'] ) ? $data['Author'] : '';
			$is_jet = ( 0 === strpos( $folder, 'jet-' ) || 0 === strpos( $folder, 'jetengine' ) || false !== stripos( $author, 'crocoblock' ) || false !== stripos( $author, 'zemez' ) );
			if ( $is_jet && 'crocoblock-exit' !== $folder ) {
				$out[ $file ] = array(
					'name'    => $data['Name'],
					'version' => $data['Version'],
					'active'  => is_plugin_active( $file ),
				);
			}
		}
		return $out;
	}

	/* ---------- Elementor-data ---------- */

	/**
	 * ID:n för alla poster som har Elementor-data.
	 *
	 * @param array $args { include_retired: bool, only_retired: bool, include_drafts: bool, limit: int, offset: int, post_types: string[] }
	 */
	public static function elementor_post_ids( array $args = array() ) {
		global $wpdb;
		$args = array_merge(
			array(
				'include_retired' => false,
				'only_retired'    => false,
				'include_drafts'  => true,
				'limit'           => 0,
				'offset'          => 0,
				'post_types'      => array(),
			),
			$args
		);

		$where   = array( "m.meta_key = '_elementor_data'", "m.meta_value != ''", "m.meta_value != '[]'", "p.post_type != 'revision'", "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pv WHERE pv.post_id = p.ID AND pv.meta_key = '_cbx_preview_of')" );
		$retired = self::retired_post_types();
		$in      = "'" . implode( "','", array_map( 'esc_sql', $retired ) ) . "'";

		if ( $args['only_retired'] ) {
			$where[] = "p.post_type IN ($in)";
		} elseif ( ! $args['include_retired'] ) {
			$where[] = "p.post_type NOT IN ($in)";
		}
		if ( $args['post_types'] ) {
			$where[] = "p.post_type IN ('" . implode( "','", array_map( 'esc_sql', $args['post_types'] ) ) . "')";
		}
		$statuses = $args['include_drafts'] ? "'publish','draft','pending','private','future'" : "'publish','private','future'";
		$where[]  = "p.post_status IN ($statuses)";

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.ID ASC';
		if ( $args['limit'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}
		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_elementor_posts( array $args = array() ) {
		$args['limit'] = 0;
		return count( self::elementor_post_ids( $args ) );
	}

	/**
	 * Läser Elementor-data som array (null om ogiltig).
	 */
	public static function get_data( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Råvärdet, exakt som det ligger i databasen (för backup).
	 */
	public static function get_raw( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		return is_array( $raw ) ? wp_json_encode( $raw ) : (string) $raw;
	}

	public static function save_data( $post_id, array $data ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		self::clear_post_cache( $post_id );
	}

	public static function save_raw( $post_id, $raw ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( $raw ) );
		self::clear_post_cache( $post_id );
	}

	/**
	 * Sparar en backup – bara första gången, så att återställning alltid
	 * går tillbaka till originalet med Crocoblock.
	 */
	public static function backup( $post_id ) {
		if ( '' !== (string) get_post_meta( $post_id, self::BACKUP_META, true ) ) {
			return false;
		}
		add_post_meta( $post_id, self::BACKUP_META, wp_slash( self::get_raw( $post_id ) ), true );
		add_post_meta( $post_id, self::BACKUP_TIME, current_time( 'mysql' ), true );
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( $settings ) {
			add_post_meta( $post_id, '_cbx_backup_page_settings', $settings, true );
		}
		return true;
	}

	public static function has_backup( $post_id ) {
		return '' !== (string) get_post_meta( $post_id, self::BACKUP_META, true );
	}

	/**
	 * Aktiverar de Crocoblock-tillägg som det här tillägget har stängt av.
	 *
	 * @return int|WP_Error Antal aktiverade.
	 */
	public static function reactivate_jet() {
		$list = array_values( array_filter( (array) get_option( 'cbx_deactivated_plugins', array() ) ) );
		if ( ! $list ) {
			return 0;
		}
		if ( ! function_exists( 'activate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$res = activate_plugins( $list );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		delete_option( 'cbx_deactivated_plugins' );
		return count( $list );
	}

	/**
	 * Stänger av aktiva Crocoblock-tillägg och kommer ihåg vilka.
	 *
	 * @param string[]|null $files Bara de här (måste vara Crocoblock-tillägg).
	 * @return string[] Avstängda.
	 */
	public static function deactivate_jet( $files = null ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = array_keys( array_filter( self::jet_plugins(), function ( $p ) { return $p['active']; } ) );
		$chosen = null === $files ? $active : array_values( array_intersect( (array) $files, $active ) );
		if ( $chosen ) {
			deactivate_plugins( $chosen );
			$prev = (array) get_option( 'cbx_deactivated_plugins', array() );
			update_option( 'cbx_deactivated_plugins', array_values( array_unique( array_merge( $prev, $chosen ) ) ), false );
		}
		return $chosen;
	}

	public static function restore( $post_id ) {
		$raw = get_post_meta( $post_id, self::BACKUP_META, true );
		if ( '' === (string) $raw ) {
			return false;
		}
		self::save_raw( $post_id, $raw );
		$settings = get_post_meta( $post_id, '_cbx_backup_page_settings', true );
		if ( $settings ) {
			update_post_meta( $post_id, '_elementor_page_settings', $settings );
		}
		delete_post_meta( $post_id, self::BACKUP_META );
		delete_post_meta( $post_id, self::BACKUP_TIME );
		delete_post_meta( $post_id, '_cbx_backup_page_settings' );
		delete_post_meta( $post_id, self::LOG_META );
		delete_post_meta( $post_id, '_cbx_element_css' );
		return true;
	}

	public static function backup_post_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", self::BACKUP_META ) ) );
	}

	public static function clear_post_cache( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
		clean_post_cache( $post_id );
	}

	public static function clear_all_cache() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/* ---------- Mapper ---------- */

	public static function mapper( array $override = array() ) {
		$settings = self::settings();
		$ctx      = array(
			'has_pro'        => self::has_pro(),
			'clean_ext'      => (bool) $settings['clean_ext'],
			'attachment_url' => function ( $id ) {
				$url = wp_get_attachment_url( (int) $id );
				return $url ? $url : '';
			},
			'term_tt_id'     => function ( $term_id ) {
				$term = get_term( (int) $term_id );
				return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_taxonomy_id : (int) $term_id;
			},
			'menu_slug'      => function ( $menu ) {
				$obj = wp_get_nav_menu_object( is_numeric( $menu ) ? (int) $menu : $menu );
				return $obj ? $obj->slug : ( is_numeric( $menu ) ? '' : (string) $menu );
			},
			'listing_map'    => function ( $id ) {
				$map = (array) get_option( CBX_Templates::OPT_LISTINGS, array() );
				return isset( $map[ $id ] ) ? $map[ $id ] : null;
			},
			'popup_map'      => function ( $id ) {
				$map = (array) get_option( CBX_Templates::OPT_POPUPS, array() );
				return isset( $map[ $id ] ) ? (int) $map[ $id ] : null;
			},
			'register_query' => array( 'CBX_Queries', 'register' ),
			'per_page'       => (int) get_option( 'posts_per_page', 10 ),
		);
		return new CBX_Mapper( array_merge( $ctx, $override ) );
	}

	/* ---------- Övrigt ---------- */

	public static function post_label( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '#' . $post_id;
		}
		$title = '' !== $post->post_title ? $post->post_title : '(utan rubrik)';
		$type  = get_post_type_object( $post->post_type );
		return sprintf( '%s (%s, #%d%s)', $title, $type ? $type->labels->singular_name : $post->post_type, $post_id, 'publish' === $post->post_status ? '' : ', ' . $post->post_status );
	}

	public static function edit_link( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return add_query_arg( array( 'post' => $post_id, 'action' => 'elementor' ), admin_url( 'post.php' ) );
	}

	public static function view_link( $post_id ) {
		$link = get_permalink( $post_id );
		return $link ? $link : '';
	}
}
