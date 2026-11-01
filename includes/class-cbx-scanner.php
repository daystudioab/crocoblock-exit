<?php
/**
 * Inventering: vad på sajten använder Crocoblock?
 *
 * Körs i omgångar (batch) för att klara stora sajter. Resultatet ackumuleras
 * i en option och den färdiga rapporten sparas i OPT_REPORT.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Scanner {

	const OPT_PROGRESS = 'cbx_scan_progress';
	const OPT_REPORT   = 'cbx_last_report';

	/** Grupper för Crocoblock-tillägg på element, efter nyckelprefix. */
	protected static $ext_groups = array(
		'jet_sticky_section'         => 'Sticky sektion (JetBlocks)',
		'jet_tricks_column_sticky'   => 'Sticky kolumn (JetTricks)',
		'jet_parallax_layout'        => 'Parallaxlager (JetElements)',
		'jet_tricks_widget_parallax' => 'Widget-parallax (JetTricks)',
		'jet_tricks_widget_tooltip'  => 'Tooltip (JetTricks)',
		'jet_tricks_widget_satellite'=> 'Satellit (JetTricks)',
		'jet_attached_popup'         => 'Popup-koppling (JetPopup)',
		'jedv_'                      => 'Dynamisk synlighet (JetEngine)',
		'jet_'                       => 'Övriga Crocoblock-inställningar',
	);

	/* ---------- Batch-API ---------- */

	public static function start() {
		$total = CBX_Plugin::count_elementor_posts( array( 'include_retired' => true ) );
		update_option(
			self::OPT_PROGRESS,
			array(
				'started' => current_time( 'mysql' ),
				'total'   => $total,
				'offset'  => 0,
				'acc'     => self::empty_acc(),
			),
			false
		);
		return $total;
	}

	/**
	 * Kör en omgång. Returnerar progress-info.
	 */
	public static function step( $limit = 25 ) {
		$progress = get_option( self::OPT_PROGRESS );
		if ( ! $progress ) {
			self::start();
			$progress = get_option( self::OPT_PROGRESS );
		}
		$ids = CBX_Plugin::elementor_post_ids(
			array(
				'include_retired' => true,
				'limit'           => $limit,
				'offset'          => $progress['offset'],
			)
		);
		$mapper = CBX_Plugin::mapper();
		foreach ( $ids as $id ) {
			self::scan_post( $id, $mapper, $progress['acc'] );
		}
		$progress['offset'] += count( $ids );
		$done                = count( $ids ) < $limit;

		if ( $done ) {
			$report = self::finish( $progress );
			delete_option( self::OPT_PROGRESS );
			return array( 'done' => true, 'processed' => $progress['offset'], 'total' => $progress['total'], 'report' => $report );
		}
		update_option( self::OPT_PROGRESS, $progress, false );
		return array( 'done' => false, 'processed' => $progress['offset'], 'total' => $progress['total'] );
	}

	/**
	 * Hela inventeringen i ett svep (WP-CLI).
	 */
	public static function run_all() {
		self::start();
		do {
			$res = self::step( 50 );
		} while ( ! $res['done'] );
		return $res['report'];
	}

	/**
	 * Färsk kontroll: vilka Crocoblock-widgets finns kvar i innehållet just nu?
	 * Används som spärr innan Crocoblock avaktiveras – en Jet-widget som finns
	 * kvar när tillägget stängs av visas inte alls.
	 *
	 * @return array{count: int, posts: array<int, array<string, int>>, templates: string[]}
	 */
	public static function remaining() {
		$out = array( 'count' => 0, 'posts' => array(), 'templates' => array() );
		// Bara det som syns på sajten – utkast går sönder tyst men påverkar inte besökarna.
		foreach ( CBX_Plugin::elementor_post_ids( array( 'include_drafts' => false ) ) as $id ) {
			$data = CBX_Plugin::get_data( $id );
			if ( ! $data ) {
				continue;
			}
			$found = array();
			self::count_jet( $data, $found );
			if ( $found ) {
				$out['posts'][ $id ] = $found;
				$out['count']       += array_sum( $found );
			}
		}
		// Crocoblock-mallar som är i bruk men inte migrerade slutar också visas.
		$inv = CBX_Templates::inventory();
		foreach ( array( 'theme' => 'JetThemeCore', 'popups' => 'JetPopup' ) as $key => $label ) {
			foreach ( $inv[ $key ] as $tid => $row ) {
				if ( 'publish' === $row['status'] && ! $row['migrated'] ) {
					$out['templates'][] = $label . ': ' . $row['title'] . ' (#' . (int) $tid . ')';
				}
			}
		}
		return $out;
	}

	protected static function count_jet( array $elements, array &$found ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['widgetType'] ) && CBX_Mapper::is_jet_type( $el['widgetType'] ) ) {
				$found[ $el['widgetType'] ] = isset( $found[ $el['widgetType'] ] ) ? $found[ $el['widgetType'] ] + 1 : 1;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::count_jet( $el['elements'], $found );
			}
		}
	}

	public static function last_report() {
		return get_option( self::OPT_REPORT, null );
	}

	/* ---------- Själva skanningen ---------- */

	protected static function empty_acc() {
		return array(
			'scanned'    => 0,
			'widgets'    => array(), // type => [count, auto, manual, posts[]]
			'extensions' => array(), // group => [count, posts[]]
			'tags'       => array(), // name => [count, posts[]]
			'shortcodes' => array(), // tag => [count, posts[]]
			'posts'      => array(), // id => [widgets, ext, tags, shortcodes, auto, manual, retired]
		);
	}

	protected static function scan_post( $post_id, CBX_Mapper $mapper, array &$acc ) {
		$acc['scanned']++;
		$data = CBX_Plugin::get_data( $post_id );
		if ( ! $data ) {
			return;
		}
		$found = array( 'widgets' => array(), 'ext' => 0, 'tags' => 0, 'shortcodes' => 0, 'auto' => 0, 'manual' => 0 );
		self::walk( $data, $post_id, $acc, $found );

		if ( ! $found['widgets'] && ! $found['ext'] && ! $found['tags'] && ! $found['shortcodes'] ) {
			return;
		}

		// Klassa varje widget som automatisk eller manuell genom en provkörning.
		list( , $log ) = $mapper->convert_document( $data, 'scan' );
		foreach ( $log as $row ) {
			if ( ! CBX_Mapper::is_jet_type( $row['from'] ) || ! in_array( $row['status'], array( 'converted', 'manual' ), true ) ) {
				continue;
			}
			$key = 'converted' === $row['status'] ? 'auto' : 'manual';
			if ( isset( $acc['widgets'][ $row['from'] ] ) ) {
				$acc['widgets'][ $row['from'] ][ $key ]++;
			}
			$found[ $key ]++;
		}

		$post    = get_post( $post_id );
		$retired = $post && in_array( $post->post_type, CBX_Plugin::retired_post_types(), true );
		$acc['posts'][ $post_id ] = array(
			'widgets'    => $found['widgets'],
			'ext'        => $found['ext'],
			'tags'       => $found['tags'],
			'shortcodes' => $found['shortcodes'],
			'auto'       => $found['auto'],
			'manual'     => $found['manual'],
			'retired'    => $retired,
			'migrated'   => CBX_Plugin::has_backup( $post_id ),
		);
	}

	protected static function walk( array $elements, $post_id, array &$acc, array &$found ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type     = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

			if ( CBX_Mapper::is_jet_type( $type ) ) {
				if ( ! isset( $acc['widgets'][ $type ] ) ) {
					$acc['widgets'][ $type ] = array( 'count' => 0, 'auto' => 0, 'manual' => 0, 'posts' => array() );
				}
				$acc['widgets'][ $type ]['count']++;
				$acc['widgets'][ $type ]['posts'][ $post_id ] = true;
				$found['widgets'][ $type ] = isset( $found['widgets'][ $type ] ) ? $found['widgets'][ $type ] + 1 : 1;
			}

			// Tillägg: en träff per grupp och element.
			$groups = array();
			foreach ( $settings as $key => $val ) {
				if ( 0 !== strpos( $key, 'jet_' ) && 0 !== strpos( $key, 'jedv_' ) ) {
					continue;
				}
				if ( '' === $val || array() === $val || null === $val || 'false' === $val || '0' === $val || 0 === $val || false === $val ) {
					continue;
				}
				foreach ( self::$ext_groups as $prefix => $label ) {
					if ( 0 === strpos( $key, $prefix ) ) {
						$groups[ $label ] = true;
						break;
					}
				}
			}
			// Innehållsinställningar i Jet-widgets räknas inte som tillägg.
			if ( CBX_Mapper::is_jet_type( $type ) ) {
				unset( $groups['Övriga Crocoblock-inställningar'] );
			}
			foreach ( array_keys( $groups ) as $label ) {
				if ( ! isset( $acc['extensions'][ $label ] ) ) {
					$acc['extensions'][ $label ] = array( 'count' => 0, 'posts' => array() );
				}
				$acc['extensions'][ $label ]['count']++;
				$acc['extensions'][ $label ]['posts'][ $post_id ] = true;
				$found['ext']++;
			}

			// Dynamiska taggar och kortkoder i alla strängvärden.
			array_walk_recursive(
				$settings,
				function ( $val ) use ( $post_id, &$acc, &$found ) {
					if ( ! is_string( $val ) || false === strpos( $val, 'jet' ) ) {
						return;
					}
					if ( preg_match_all( '/name="(jet-[a-z0-9-]+)"/', $val, $m ) ) {
						foreach ( $m[1] as $name ) {
							if ( ! isset( $acc['tags'][ $name ] ) ) {
								$acc['tags'][ $name ] = array( 'count' => 0, 'posts' => array() );
							}
							$acc['tags'][ $name ]['count']++;
							$acc['tags'][ $name ]['posts'][ $post_id ] = true;
							$found['tags']++;
						}
					}
					if ( preg_match_all( '/\[(jet[_-][a-z0-9_-]+)/i', $val, $m ) ) {
						foreach ( $m[1] as $tag ) {
							self::add_shortcode( $acc, $tag, $post_id );
							$found['shortcodes']++;
						}
					}
				}
			);

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $post_id, $acc, $found );
			}
		}
	}

	protected static function add_shortcode( array &$acc, $tag, $post_id ) {
		$tag = strtolower( $tag );
		if ( ! isset( $acc['shortcodes'][ $tag ] ) ) {
			$acc['shortcodes'][ $tag ] = array( 'count' => 0, 'posts' => array() );
		}
		$acc['shortcodes'][ $tag ]['count']++;
		$acc['shortcodes'][ $tag ]['posts'][ $post_id ] = true;
	}

	/* ---------- Avslut: kortkoder i vanligt innehåll, mallar, innehållstyper ---------- */

	protected static function finish( array $progress ) {
		global $wpdb;
		$acc = $progress['acc'];

		// Kortkoder i post_content (klassiska redigeraren, Gutenberg, widgets).
		$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft','inherit') AND post_type != 'revision' AND post_content LIKE '%[jet%'" ); // phpcs:ignore
		foreach ( $rows as $row ) {
			if ( preg_match_all( '/\[(jet[_-][a-z0-9_-]+)/i', $row->post_content, $m ) ) {
				foreach ( $m[1] as $tag ) {
					self::add_shortcode( $acc, $tag, (int) $row->ID );
				}
				if ( ! isset( $acc['posts'][ (int) $row->ID ] ) ) {
					$acc['posts'][ (int) $row->ID ] = array( 'widgets' => array(), 'ext' => 0, 'tags' => 0, 'shortcodes' => count( $m[1] ), 'auto' => 0, 'manual' => 0, 'retired' => false, 'migrated' => false );
				}
			}
		}
		// Gutenberg-block från Crocoblock (jet-engine/…, jet-smart-filters/…).
		$blocks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft','inherit') AND post_type != 'revision' AND post_content LIKE '%<!-- wp:jet-%'" ); // phpcs:ignore

		// Sidopaneler/widgets i WordPress-widgetområden.
		$wp_widgets = array();
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $area => $list ) {
			if ( ! is_array( $list ) || 'wp_inactive_widgets' === $area ) {
				continue;
			}
			foreach ( $list as $widget_id ) {
				if ( false !== stripos( $widget_id, 'jet' ) ) {
					$wp_widgets[] = $area . ': ' . $widget_id;
				}
			}
		}

		$widgets = $acc['widgets'];
		ksort( $widgets );
		foreach ( $widgets as $type => &$w ) {
			$w['posts'] = array_keys( $w['posts'] );
			$w['hint']  = $w['manual'] ? CBX_Mapper::manual_hint( $type ) : '';
		}
		unset( $w );
		foreach ( array( 'extensions', 'tags', 'shortcodes' ) as $group ) {
			foreach ( $acc[ $group ] as &$row ) {
				$row['posts'] = array_keys( $row['posts'] );
			}
			unset( $row );
		}

		$report = array(
			'time'         => current_time( 'mysql' ),
			'environment'  => array(
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'pro'       => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			),
			'plugins'      => CBX_Plugin::jet_plugins(),
			'scanned'      => $acc['scanned'],
			'widgets'      => $widgets,
			'extensions'   => $acc['extensions'],
			'tags'         => $acc['tags'],
			'shortcodes'   => $acc['shortcodes'],
			'blocks'       => $blocks,
			'wp_widgets'   => $wp_widgets,
			'posts'        => $acc['posts'],
			'templates'    => CBX_Templates::inventory(),
			'site_css'     => CBX_Site_CSS::summary(),
			'csshero'      => self::hero_summary(),
			'content'      => CBX_Content_Types::inventory(),
		);
		update_option( self::OPT_REPORT, $report, false );
		return $report;
	}

	protected static function hero_summary() {
		$st = CBX_CSS_Hero::status( true );
		if ( ! $st ) {
			return null;
		}
		return array(
			'active'  => $st['plugin'] ? $st['plugin']['active'] : false,
			'rules'   => $st['analysis']['rules'],
			'jet'     => $st['analysis']['jet'],
			'fragile' => count( $st['analysis']['fragile'] ),
			'moved'   => (bool) $st['backup'],
		);
	}

	/* ---------- Sammanfattning ---------- */

	public static function totals( array $report ) {
		$t = array( 'widgets' => 0, 'auto' => 0, 'manual' => 0, 'pages' => 0, 'ext' => 0, 'tags' => 0, 'shortcodes' => 0 );
		foreach ( $report['widgets'] as $w ) {
			$t['widgets'] += $w['count'];
			$t['auto']    += $w['auto'];
			$t['manual']  += $w['manual'];
		}
		foreach ( $report['posts'] as $p ) {
			if ( empty( $p['retired'] ) ) {
				$t['pages']++;
			}
		}
		foreach ( $report['extensions'] as $e ) {
			$t['ext'] += $e['count'];
		}
		foreach ( $report['tags'] as $e ) {
			$t['tags'] += $e['count'];
		}
		foreach ( $report['shortcodes'] as $e ) {
			$t['shortcodes'] += $e['count'];
		}
		return $t;
	}

	/**
	 * Rapporten som CSV-rader.
	 */
	public static function csv_rows( array $report ) {
		$rows   = array();
		$rows[] = array( 'Typ', 'Namn', 'Antal', 'Automatiskt', 'Manuellt', 'Sidor (ID)', 'Råd' );
		foreach ( $report['widgets'] as $type => $w ) {
			$rows[] = array( 'Widget', $type, $w['count'], $w['auto'], $w['manual'], implode( ' ', $w['posts'] ), $w['hint'] );
		}
		foreach ( $report['extensions'] as $label => $e ) {
			$rows[] = array( 'Tillägg', $label, $e['count'], '', '', implode( ' ', $e['posts'] ), '' );
		}
		foreach ( $report['tags'] as $name => $e ) {
			$rows[] = array( 'Dynamisk tagg', $name, $e['count'], '', '', implode( ' ', $e['posts'] ), '' );
		}
		foreach ( $report['shortcodes'] as $name => $e ) {
			$rows[] = array( 'Kortkod', $name, $e['count'], '', '', implode( ' ', $e['posts'] ), 'Ersätts manuellt' );
		}
		$rows[] = array();
		$rows[] = array( 'Sida', 'Rubrik', 'Jet-widgets', 'Automatiskt', 'Manuellt', 'Länk', 'Status' );
		foreach ( $report['posts'] as $id => $p ) {
			$rows[] = array( 'Sida', CBX_Plugin::post_label( $id ), array_sum( $p['widgets'] ), $p['auto'], $p['manual'], CBX_Plugin::view_link( $id ), $p['migrated'] ? 'Konverterad' : ( $p['retired'] ? 'Crocoblock-mall' : '' ) );
		}
		return $rows;
	}
}
