<?php
/**
 * Migrering av Crocoblocks mallar till Elementor Pro:
 *  - JetEngine-listningar  → Loop Item-mallar (används av Loop Grid)
 *  - JetPopup              → Elementor Pro-popuper
 *  - JetThemeCore          → Theme Builder-mallar (header, footer, single, archive)
 *
 * Mallarna skapas som kopior. Originalen rörs inte. Theme Builder-mallar och
 * popuper skapas som utkast och aktiveras först i sista steget, så att sajten
 * inte får dubbla sidhuvuden medan Crocoblock fortfarande är aktivt.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Templates {

	const OPT_LISTINGS = 'cbx_listing_map'; // jet id => [id, post_type]
	const OPT_POPUPS   = 'cbx_popup_map';   // jet id => new id
	const OPT_THEME    = 'cbx_theme_map';   // jet id => new id
	const PENDING      = '_cbx_pending_conditions';
	const FROM         = '_cbx_migrated_from';

	protected static $theme_types = array(
		'jet_header'  => 'header',
		'jet_footer'  => 'footer',
		'jet_single'  => 'single-post',
		'jet_archive' => 'archive',
		'jet_page'    => 'single-page',
		'jet_section' => 'section',
	);

	/* ---------- Inventering ---------- */

	protected static function posts_of( $type ) {
		return get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	public static function inventory() {
		$listing_map = (array) get_option( self::OPT_LISTINGS, array() );
		$popup_map   = (array) get_option( self::OPT_POPUPS, array() );
		$theme_map   = (array) get_option( self::OPT_THEME, array() );
		$out         = array( 'listings' => array(), 'popups' => array(), 'theme' => array(), 'menu_items' => 0, 'filters' => 0 );

		foreach ( self::posts_of( 'jet-engine' ) as $p ) {
			$data                    = get_post_meta( $p->ID, '_listing_data', true );
			$out['listings'][ $p->ID ] = array(
				'title'    => $p->post_title,
				'status'   => $p->post_status,
				'source'   => is_array( $data ) && ! empty( $data['source'] ) ? $data['source'] : 'posts',
				'type'     => get_post_meta( $p->ID, '_listing_type', true ),
				'migrated' => isset( $listing_map[ $p->ID ] ) ? $listing_map[ $p->ID ]['id'] : 0,
			);
		}
		foreach ( self::posts_of( 'jet-popup' ) as $p ) {
			$settings              = (array) get_post_meta( $p->ID, '_elementor_page_settings', true );
			$out['popups'][ $p->ID ] = array(
				'title'    => $p->post_title,
				'status'   => $p->post_status,
				'trigger'  => isset( $settings['jet_popup_open_trigger'] ) ? $settings['jet_popup_open_trigger'] : 'attach',
				'migrated' => isset( $popup_map[ $p->ID ] ) ? (int) $popup_map[ $p->ID ] : 0,
			);
		}
		foreach ( self::posts_of( 'jet-theme-core' ) as $p ) {
			$type                 = get_post_meta( $p->ID, '_elementor_template_type', true );
			$out['theme'][ $p->ID ] = array(
				'title'    => $p->post_title,
				'status'   => $p->post_status,
				'type'     => $type,
				'migrated' => isset( $theme_map[ $p->ID ] ) ? (int) $theme_map[ $p->ID ] : 0,
			);
		}
		global $wpdb;
		$out['menu_items'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'jet_menu_settings' AND meta_value LIKE '%enabled%'" ); // phpcs:ignore
		$out['filters']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'jet-smart-filters' AND post_status = 'publish'" ); // phpcs:ignore
		return $out;
	}

	/* ---------- Gemensamt ---------- */

	/**
	 * Skapar en Elementor-mall som kopia av en Crocoblock-mall.
	 */
	protected static function create_template( WP_Post $old, $type, $status, array $data, array $page_settings = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'   => 'elementor_library',
				'post_status' => $status,
				'post_title'  => $old->post_title ? $old->post_title : 'Migrerad mall #' . $old->ID,
				'post_author' => $old->post_author,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $id, '_elementor_template_type', $type );
		update_post_meta( $id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			update_post_meta( $id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
		}
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		if ( $page_settings ) {
			update_post_meta( $id, '_elementor_page_settings', $page_settings );
		}
		update_post_meta( $id, self::FROM, $old->ID );
		wp_set_object_terms( $id, $type, 'elementor_library_type' );
		CBX_Styles::store_free( $id, self::$pending_free );
		self::$pending_free = array();
		return $id;
	}

	/**
	 * Tar bort Crocoblock-nycklar ur sidinställningar (bakgrund, padding m.m. behålls).
	 */
	protected static function clean_page_settings( $settings ) {
		$out = array();
		foreach ( (array) $settings as $k => $v ) {
			if ( 0 === strpos( $k, 'jet_' ) || 0 === strpos( $k, 'conditions_' ) || 0 === strpos( $k, 'listing_' ) || 0 === strpos( $k, 'repeater_' ) ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/** @var array CSS per element för sajter utan Pro, väntar på mallens nya ID. */
	protected static $pending_free = array();

	protected static function convert_data( $post_id, $context ) {
		$data = CBX_Plugin::get_data( $post_id );
		if ( ! $data ) {
			return array( array(), array() );
		}
		list( $new, $log )        = CBX_Plugin::mapper()->convert_document( $data, $context );
		list( $new, $log, $free ) = CBX_Styles::apply( $post_id, $new, $log );
		self::$pending_free       = $free;
		return array( $new, $log );
	}

	/**
	 * Uppdaterar innehållet i en redan migrerad mall med senaste konverteringen.
	 * Mallens id, status och visningsvillkor rörs inte – bara innehåll och stil.
	 * Utan det här skulle mallar som migrerades med en äldre version av
	 * tillägget aldrig få senare konverteringsrättelser.
	 */
	protected static function refresh_template( $old_id, $new_id, $context ) {
		list( $data, $log ) = self::convert_data( $old_id, $context );
		if ( ! $data ) {
			return array( 'state' => 'skipped', 'notes' => array( 'Redan migrerad.' ), 'log' => array() );
		}
		CBX_Plugin::save_data( $new_id, $data );
		delete_post_meta( $new_id, CBX_Styles::META_FREE );
		CBX_Styles::store_free( $new_id, self::$pending_free );
		self::$pending_free = array();
		CBX_Styles::forget( $new_id );
		CBX_Plugin::clear_post_cache( $new_id );
		return array(
			'state' => 'refreshed',
			'notes' => array_merge( array( 'Redan migrerad – innehållet uppdaterat med senaste konverteringen.' ), self::manual_notes( $log ) ),
		);
	}

	protected static function manual_notes( array $log ) {
		$notes = array();
		foreach ( $log as $row ) {
			if ( 'manual' === $row['status'] ) {
				$notes[] = $row['from'] . ': ' . implode( ' ', $row['notes'] );
			}
		}
		return array_values( array_unique( $notes ) );
	}

	/* ---------- JetEngine-listningar ---------- */

	public static function migrate_listings() {
		if ( ! CBX_Plugin::has_pro() ) {
			return new WP_Error( 'cbx_pro', 'Loop Item-mallar kräver Elementor Pro (3.8 eller senare).' );
		}
		$map     = (array) get_option( self::OPT_LISTINGS, array() );
		$results = array();
		foreach ( self::posts_of( 'jet-engine' ) as $old ) {
			if ( isset( $map[ $old->ID ] ) && get_post( $map[ $old->ID ]['id'] ) ) {
				$r         = self::refresh_template( $old->ID, $map[ $old->ID ]['id'], 'listing' );
				$results[] = array( 'from' => $old->ID, 'to' => $map[ $old->ID ]['id'], 'title' => $old->post_title, 'state' => $r['state'], 'notes' => $r['notes'] );
				continue;
			}
			$notes = array();
			$type  = get_post_meta( $old->ID, '_listing_type', true );
			if ( $type && 'elementor' !== $type ) {
				$results[] = array( 'from' => $old->ID, 'to' => 0, 'title' => $old->post_title, 'state' => 'manual', 'notes' => array( sprintf( 'Listningen är byggd med "%s", inte Elementor – bygg om manuellt.', $type ) ) );
				continue;
			}
			$ldata     = get_post_meta( $old->ID, '_listing_data', true );
			$ldata     = is_array( $ldata ) ? $ldata : array();
			$source    = ! empty( $ldata['source'] ) ? $ldata['source'] : 'posts';
			$post_type = ! empty( $ldata['post_type'] ) ? $ldata['post_type'] : 'post';
			if ( 'posts' !== $source ) {
				$notes[] = sprintf( 'Listningen visar "%s" (inte inlägg). Loop Grid stöder främst inlägg – kontrollera resultatet.', $source );
			}
			// Mapper-kontexten måste känna till kartan för nästlade listningar.
			list( $data, $log ) = self::convert_data( $old->ID, 'listing' );
			$settings           = self::clean_page_settings( get_post_meta( $old->ID, '_elementor_page_settings', true ) );
			$settings['source'] = 'product' === $post_type ? 'product' : 'post';

			$new = self::create_template( $old, 'loop-item', 'publish', $data, $settings );
			if ( is_wp_error( $new ) ) {
				$results[] = array( 'from' => $old->ID, 'to' => 0, 'title' => $old->post_title, 'state' => 'error', 'notes' => array( $new->get_error_message() ) );
				continue;
			}
			$map[ $old->ID ] = array( 'id' => $new, 'post_type' => 'posts' === $source ? $post_type : '' );
			update_option( self::OPT_LISTINGS, $map, false );
			$results[] = array( 'from' => $old->ID, 'to' => $new, 'title' => $old->post_title, 'state' => 'migrated', 'notes' => array_merge( $notes, self::manual_notes( $log ) ) );
		}
		return $results;
	}

	/* ---------- JetPopup ---------- */

	public static function migrate_popups() {
		if ( ! CBX_Plugin::has_pro() ) {
			return new WP_Error( 'cbx_pro', 'Popuper kräver Elementor Pro.' );
		}
		$map        = (array) get_option( self::OPT_POPUPS, array() );
		$conditions = (array) get_option( 'jet_popup_conditions', array() );
		$results    = array();

		foreach ( self::posts_of( 'jet-popup' ) as $old ) {
			if ( isset( $map[ $old->ID ] ) && get_post( $map[ $old->ID ] ) ) {
				$r         = self::refresh_template( $old->ID, $map[ $old->ID ], 'popup' );
				$results[] = array( 'from' => $old->ID, 'to' => $map[ $old->ID ], 'title' => $old->post_title, 'state' => $r['state'], 'notes' => $r['notes'] );
				continue;
			}
			$settings = (array) get_post_meta( $old->ID, '_elementor_page_settings', true );
			$trigger  = isset( $settings['jet_popup_open_trigger'] ) ? $settings['jet_popup_open_trigger'] : 'attach';
			$notes    = array( 'Popupfönstrets utseende (bredd, position, överlägg) måste ses över i Elementors popupinställningar.' );
			$triggers = array();

			switch ( $trigger ) {
				case 'page-load':
					$triggers = array( 'page_load' => 'yes', 'page_load_delay' => (float) self::num( $settings, 'jet_popup_page_load_delay', 1 ) );
					break;
				case 'scroll-trigger':
					$triggers = array( 'scrolling' => 'yes', 'scrolling_direction' => 'down', 'scrolling_offset' => (int) self::num( $settings, 'jet_popup_scrolled_to_value', 10 ) );
					break;
				case 'user-inactive':
					$triggers = array( 'inactivity' => 'yes', 'inactivity_time' => (int) self::num( $settings, 'jet_popup_user_inactivity_time', 1 ) );
					$notes[]  = 'Kontrollera inaktivitetstiden (sekunder i Elementor).';
					break;
				case 'try-exit-trigger':
					$triggers = array( 'exit_intent' => 'yes' );
					break;
				case 'attach':
					$notes[] = 'Öppnas vid klick. Kopplingar på sidor konverteras automatiskt när sidorna konverteras (knappar, bilder, rubriker).';
					break;
				case 'custom-selector':
					$notes[] = sprintf( 'Öppnades via CSS-väljaren "%s". Koppla med en knapp/länk (Dynamisk → Åtgärder → Popup) i stället.', isset( $settings['jet_popup_custom_selector'] ) ? $settings['jet_popup_custom_selector'] : '' );
					break;
				default:
					$notes[] = sprintf( 'Utlösaren "%s" saknar motsvarighet – ställ in visningsregler manuellt.', $trigger );
			}

			$timing = array();
			if ( ! empty( $settings['jet_popup_show_once'] ) && 'yes' === $settings['jet_popup_show_once'] ) {
				$timing['times']       = 'yes';
				$timing['times_times'] = 1;
			}

			list( $data, $log ) = self::convert_data( $old->ID, 'popup' );
			$new                = self::create_template( $old, 'popup', 'draft', $data, self::clean_page_settings( $settings ) );
			if ( is_wp_error( $new ) ) {
				$results[] = array( 'from' => $old->ID, 'to' => 0, 'title' => $old->post_title, 'state' => 'error', 'notes' => array( $new->get_error_message() ) );
				continue;
			}
			update_post_meta( $new, '_elementor_popup_display_settings', array( 'triggers' => $triggers, 'timing' => $timing ) );

			if ( $triggers ) {
				$jet_cond               = isset( $conditions[ $old->ID ] ) ? $conditions[ $old->ID ] : null;
				list( $pending, $cnot ) = $jet_cond ? self::map_conditions( $jet_cond ) : array( array( 'include/general' ), array( 'Inga visningsvillkor hittades – sätts till hela webbplatsen.' ) );
				update_post_meta( $new, self::PENDING, $pending );
				$notes = array_merge( $notes, $cnot );
			}
			$map[ $old->ID ] = $new;
			update_option( self::OPT_POPUPS, $map, false );
			$results[] = array( 'from' => $old->ID, 'to' => $new, 'title' => $old->post_title, 'state' => 'migrated', 'notes' => array_merge( $notes, self::manual_notes( $log ) ) );
		}
		return $results;
	}

	protected static function num( array $s, $key, $default ) {
		if ( ! isset( $s[ $key ] ) || '' === $s[ $key ] ) {
			return $default;
		}
		$v = is_array( $s[ $key ] ) ? ( isset( $s[ $key ]['size'] ) ? $s[ $key ]['size'] : $default ) : $s[ $key ];
		return is_numeric( $v ) ? $v : $default;
	}

	/* ---------- JetThemeCore ---------- */

	public static function migrate_theme() {
		if ( ! CBX_Plugin::has_pro() ) {
			return new WP_Error( 'cbx_pro', 'Theme Builder-mallar kräver Elementor Pro.' );
		}
		$map        = (array) get_option( self::OPT_THEME, array() );
		$site_conds = (array) get_option( 'jet_site_conditions', array() );
		$results    = array();

		foreach ( self::posts_of( 'jet-theme-core' ) as $old ) {
			if ( isset( $map[ $old->ID ] ) && get_post( $map[ $old->ID ] ) ) {
				$r         = self::refresh_template( $old->ID, $map[ $old->ID ], 'theme' );
				$results[] = array( 'from' => $old->ID, 'to' => $map[ $old->ID ], 'title' => $old->post_title, 'state' => $r['state'], 'notes' => $r['notes'] );
				continue;
			}
			$jet_type = get_post_meta( $old->ID, '_elementor_template_type', true );
			$type     = isset( self::$theme_types[ $jet_type ] ) ? self::$theme_types[ $jet_type ] : 'section';
			$notes    = array();
			$pending  = array();

			if ( 'section' !== $type ) {
				if ( 'publish' === $old->post_status && isset( $site_conds[ $jet_type ][ $old->ID ] ) ) {
					list( $pending, $cnot ) = self::map_conditions( $site_conds[ $jet_type ][ $old->ID ] );
					$notes                  = array_merge( $notes, $cnot );
				} else {
					$notes[] = 'Mallen hade inga aktiva visningsvillkor i JetThemeCore – sätt villkor manuellt om den ska användas.';
				}
				if ( 'single-post' === $type && $pending ) {
					$all_404 = true;
					$all_pg  = true;
					foreach ( $pending as $c ) {
						$all_404 = $all_404 && 0 === strpos( $c, 'include/singular/not_found404' );
						$all_pg  = $all_pg && 0 === strpos( $c, 'include/singular/page' );
					}
					$type = $all_404 ? 'error-404' : ( $all_pg ? 'single-page' : $type );
				}
			}

			list( $data, $log ) = self::convert_data( $old->ID, 'theme' );
			$status             = 'section' === $type ? 'publish' : 'draft';
			$new                = self::create_template( $old, $type, $status, $data, self::clean_page_settings( get_post_meta( $old->ID, '_elementor_page_settings', true ) ) );
			if ( is_wp_error( $new ) ) {
				$results[] = array( 'from' => $old->ID, 'to' => 0, 'title' => $old->post_title, 'state' => 'error', 'notes' => array( $new->get_error_message() ) );
				continue;
			}
			if ( $pending ) {
				update_post_meta( $new, self::PENDING, $pending );
			}
			$map[ $old->ID ] = $new;
			update_option( self::OPT_THEME, $map, false );
			$results[] = array( 'from' => $old->ID, 'to' => $new, 'title' => $old->post_title, 'state' => 'migrated', 'type' => $type, 'notes' => array_merge( $notes, self::manual_notes( $log ) ) );
		}
		return $results;
	}

	/**
	 * Översätter JetThemeCores/JetPopups villkor till Elementor Pros villkorssträngar.
	 *
	 * Format in: array( 'main' => 'entire|singular|archive', '<villkors-id>' => args, ... )
	 *
	 * @return array{0: string[], 1: string[]} [villkor, anteckningar]
	 */
	public static function map_conditions( $cond ) {
		$cond  = (array) $cond;
		$main  = isset( $cond['main'] ) ? $cond['main'] : '';
		$out   = array();
		$notes = array();

		if ( 'entire' === $main ) {
			return array( array( 'include/general' ), array() );
		}
		foreach ( $cond as $id => $args ) {
			if ( 'main' === $id ) {
				continue;
			}
			list( $ids, $names ) = self::flatten_args( $args );
			switch ( $id ) {
				case 'singular-front-page':
					$out[] = 'include/singular/front_page';
					break;
				case 'singular-post':
				case 'singular-page':
					$pt = 'singular-post' === $id ? 'post' : 'page';
					if ( $ids ) {
						foreach ( $ids as $i ) {
							$out[] = "include/singular/$pt/$i";
						}
					} else {
						$out[] = "include/singular/$pt";
					}
					break;
				case 'singular-page-child':
					foreach ( $ids as $i ) {
						$out[] = "include/singular/child_of/$i";
					}
					break;
				case 'singular-post-type':
					foreach ( $names ? $names : array( 'post' ) as $pt ) {
						$out[] = "include/singular/$pt";
					}
					break;
				case 'singular-404':
					$out[] = 'include/singular/not_found404';
					break;
				case 'singular-post-from-cat':
					foreach ( $ids as $i ) {
						$out[] = "include/singular/in_category/$i";
					}
					break;
				case 'singular-post-from-tag':
					foreach ( $ids as $i ) {
						$out[] = "include/singular/in_post_tag/$i";
					}
					break;
				case 'singular-post-from-tax':
					$tax = $names ? reset( $names ) : '';
					if ( $tax && $ids ) {
						foreach ( $ids as $i ) {
							$out[] = "include/singular/in_$tax/$i";
						}
					} else {
						$notes[] = 'Villkoret "inlägg i taxonomi" kunde inte tolkas – ställ in manuellt.';
					}
					break;
				case 'archive-all':
					$out[] = 'include/archive';
					break;
				case 'archive-category':
				case 'archive-tag':
					$tax = 'archive-category' === $id ? 'category' : 'post_tag';
					if ( $ids ) {
						foreach ( $ids as $i ) {
							$out[] = "include/archive/$tax/$i";
						}
					} else {
						$out[] = "include/archive/$tax";
					}
					break;
				case 'archive-tax':
					$tax = $names ? reset( $names ) : '';
					if ( $tax ) {
						if ( $ids ) {
							foreach ( $ids as $i ) {
								$out[] = "include/archive/$tax/$i";
							}
						} else {
							$out[] = "include/archive/$tax";
						}
					} else {
						$notes[] = 'Arkivvillkor för taxonomi kunde inte tolkas – ställ in manuellt.';
					}
					break;
				case 'archive-search':
					$out[] = 'include/archive/search';
					break;
				case 'archive-post-type':
					foreach ( $names ? $names : array( 'post' ) as $pt ) {
						$out[] = "include/archive/{$pt}_archive";
					}
					break;
				default:
					$notes[] = sprintf( 'Villkoret "%s" har ingen automatisk motsvarighet – ställ in manuellt.', $id );
			}
		}
		if ( ! $out ) {
			if ( 'singular' === $main ) {
				$out[] = 'include/singular';
			} elseif ( 'archive' === $main ) {
				$out[] = 'include/archive';
			}
		}
		return array( array_values( array_unique( $out ) ), $notes );
	}

	protected static function flatten_args( $args ) {
		$ids   = array();
		$names = array();
		array_walk_recursive(
			$args,
			function ( $v ) use ( &$ids, &$names ) {
				if ( is_numeric( $v ) && (int) $v > 0 ) {
					$ids[] = (int) $v;
				} elseif ( is_string( $v ) && preg_match( '/^[a-z0-9_-]+$/', $v ) ) {
					$names[] = $v;
				}
			}
		);
		if ( ! is_array( $args ) ) {
			if ( is_numeric( $args ) ) {
				$ids[] = (int) $args;
			} elseif ( is_string( $args ) && '' !== $args ) {
				$names[] = $args;
			}
		}
		return array( array_values( array_unique( $ids ) ), array_values( array_unique( $names ) ) );
	}

	/* ---------- Aktivera / ångra ---------- */

	/**
	 * Publicerar migrerade Theme Builder-mallar och popuper och sätter villkoren.
	 * Körs i samband med att Crocoblock avaktiveras.
	 */
	public static function activate() {
		$ids = array_merge( array_values( (array) get_option( self::OPT_THEME, array() ) ), array_values( (array) get_option( self::OPT_POPUPS, array() ) ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$pending = get_post_meta( $id, self::PENDING, true );
			if ( is_array( $pending ) && $pending ) {
				update_post_meta( $id, '_elementor_conditions', $pending );
			}
			if ( 'draft' === $post->post_status ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
			}
			$n++;
		}
		self::regenerate_conditions();
		CBX_Plugin::clear_all_cache();
		update_option( 'cbx_templates_activated', current_time( 'mysql' ), false );
		return $n;
	}

	/**
	 * Motsatsen till activate(): migrerade mallar tillbaka till utkast (villkoren
	 * sparas för nästa försök). Används när avstängningen av Crocoblock ångras.
	 */
	public static function deactivate() {
		$ids = array_merge( array_values( (array) get_option( self::OPT_THEME, array() ) ), array_values( (array) get_option( self::OPT_POPUPS, array() ) ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$cond = get_post_meta( $id, '_elementor_conditions', true );
			if ( is_array( $cond ) && $cond ) {
				update_post_meta( $id, self::PENDING, $cond );
			}
			delete_post_meta( $id, '_elementor_conditions' );
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			$n++;
		}
		self::regenerate_conditions();
		CBX_Plugin::clear_all_cache();
		delete_option( 'cbx_templates_activated' );
		return $n;
	}

	public static function regenerate_conditions() {
		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			try {
				\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();
			} catch ( \Throwable $e ) {
				delete_option( 'elementor_pro_theme_builder_conditions' );
			}
		}
	}

	/**
	 * Lägger migrerade mallar i papperskorgen och glömmer kopplingarna.
	 */
	public static function undo() {
		$n = 0;
		foreach ( array( self::OPT_THEME, self::OPT_POPUPS ) as $opt ) {
			foreach ( (array) get_option( $opt, array() ) as $id ) {
				if ( get_post( $id ) && wp_trash_post( $id ) ) {
					$n++;
				}
			}
			delete_option( $opt );
		}
		foreach ( (array) get_option( self::OPT_LISTINGS, array() ) as $row ) {
			if ( isset( $row['id'] ) && get_post( $row['id'] ) && wp_trash_post( $row['id'] ) ) {
				$n++;
			}
		}
		delete_option( self::OPT_LISTINGS );
		delete_option( 'cbx_templates_activated' );
		self::regenerate_conditions();
		return $n;
	}

	/**
	 * Alla tre migreringarna i rätt ordning (listningar först, eftersom
	 * sidhuvuden och popuper kan innehålla listningar).
	 */
	public static function migrate_all() {
		$out = array();
		foreach ( array( 'listings' => 'migrate_listings', 'popups' => 'migrate_popups', 'theme' => 'migrate_theme' ) as $key => $method ) {
			$res         = self::$method();
			$out[ $key ] = is_wp_error( $res ) ? array( array( 'from' => 0, 'to' => 0, 'title' => '', 'state' => 'error', 'notes' => array( $res->get_error_message() ) ) ) : $res;
		}
		return $out;
	}
}
