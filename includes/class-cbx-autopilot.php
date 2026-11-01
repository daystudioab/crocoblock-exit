<?php
/**
 * Autopilot: kör alla steg i följd och kontrollerar resultatet utan manuell
 * inblandning.
 *
 * Servern gör stegen (inventering, innehållstyper, mallar, sajt-CSS, CSS Hero,
 * provkörning, förhandsvisningar, konvertering, avstängning, återställning).
 * Den visuella jämförelsen görs i webbläsaren (assets/autopilot.js): original
 * och konverterad version laddas i osynliga ramar i tre skärmbredder och
 * jämförs element för element. Servern kontrollerar dessutom den publicerade
 * HTML:en (HTTP-kontrollen), som också är det WP-CLI använder.
 *
 * Lägen:
 *  - test:  ändrar ingenting som besökare ser. Mallar migreras som utkast och
 *           förhandsvisningar skapas som utkast (tas bort efteråt).
 *  - apply: som test, och om allt är grönt: konvertera, kontrollera sajten mot
 *           originalet och återställ automatiskt vid avvikelse. Valfritt även
 *           CSS Hero-flytt och avstängning av Crocoblock.
 *
 * @package CrocoblockExit
 */

defined( 'ABSPATH' ) || exit;

class CBX_Autopilot {

	const OPT_RUN    = 'cbx_autopilot_run';     // pågående körning (tillstånd)
	const OPT_REPORT = 'cbx_autopilot_report';  // senaste rapport
	const BATCH      = 8;

	public static function init() {
		add_action( 'wp_ajax_cbx_auto', array( __CLASS__, 'ajax' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Plan och tillstånd
	 * ------------------------------------------------------------------ */

	/**
	 * Stegen i ordning. JS kör dem ett i taget; vissa steg (dry, previews,
	 * convert, verify) körs i omgångar.
	 */
	public static function plan( $mode, array $opts = array() ) {
		if ( 'switch' === $mode ) {
			// Sajten mäts som den är (konverterad, Crocoblock på), stängs av och mäts igen.
			return array( 'live_jobs', 'baseline', 'switch', 'http_check_switch', 'verify_switch' );
		}
		if ( 'undo' === $mode ) {
			return array( 'undo' );
		}
		$steps = array( 'scan', 'content_types', 'templates', 'site_css', 'dry', 'previews', 'stabilize', 'compare' );
		if ( 'apply' === $mode ) {
			if ( ! empty( $opts['csshero'] ) ) {
				$steps[] = 'csshero';
			}
			$steps[] = 'convert';
			$steps[] = 'http_check';
			$steps[] = 'verify';
			if ( ! empty( $opts['switch'] ) ) {
				$steps[] = 'switch';
				$steps[] = 'http_check_switch';
				$steps[] = 'verify_switch';
			}
		}
		$steps[] = 'cleanup';
		return $steps;
	}

	public static function labels() {
		return array(
			'scan'              => 'Inventering',
			'content_types'     => 'Innehållstyper (JetEngine)',
			'templates'         => 'Mallar (som utkast)',
			'site_css'          => 'Sajtens egen CSS',
			'dry'               => 'Provkörning',
			'previews'          => 'Förhandsvisningar',
			'stabilize'         => 'Positionsberoende CSS (t.ex. CSS Hero)',
			'compare'           => 'Jämför original och konverterat',
			'csshero'           => 'CSS Hero',
			'convert'           => 'Konvertera',
			'http_check'        => 'Kontrollera publicerade sidor (HTML)',
			'verify'            => 'Jämför publicerade sidor med originalet',
			'switch'            => 'Stäng av Crocoblock',
			'http_check_switch' => 'Kontrollera sidorna utan Crocoblock (HTML)',
			'verify_switch'     => 'Jämför sidorna utan Crocoblock med originalet',
			'cleanup'           => 'Städa förhandsvisningar',
			'live_jobs'         => 'Sidor att kontrollera',
			'baseline'          => 'Mät sajten före avstängningen',
			'undo'              => 'Ångra allt',
			'rollback'          => 'Återställ',
		);
	}

	public static function run() {
		$run = get_option( self::OPT_RUN, array() );
		return is_array( $run ) ? $run : array();
	}

	protected static function save_run( array $run ) {
		update_option( self::OPT_RUN, $run, false );
	}

	public static function start( $mode, array $opts = array() ) {
		$mode = in_array( $mode, array( 'test', 'apply', 'switch', 'undo' ), true ) ? $mode : 'test';
		$opts = array(
			'csshero'       => ! empty( $opts['csshero'] ) && CBX_CSS_Hero::plugin() && ! CBX_CSS_Hero::backup(),
			'switch'        => ! empty( $opts['switch'] ),
			'allow_warn'    => ! empty( $opts['allow_warn'] ),
		);
		$run  = array(
			'mode'     => $mode,
			'opts'     => $opts,
			'started'  => current_time( 'mysql' ),
			'steps'    => self::plan( $mode, $opts ),
			'docs'     => array(),
			'jobs'     => array(),
			'done'     => array(),
			'flags'    => array(),
			'messages' => array(),
		);
		self::save_run( $run );
		return $run;
	}

	/* ------------------------------------------------------------------ *
	 * Stegen
	 * ------------------------------------------------------------------ */

	/**
	 * Kör ett steg (eller en omgång av det). Returnerar
	 * array{status: ok|warn|fail, message: string, done: bool, data?: mixed}.
	 */
	public static function step( $step, $offset = 0 ) {
		$run = self::run();
		if ( ! $run ) {
			return self::res( 'fail', 'Ingen körning är startad.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		switch ( $step ) {
			case 'scan':
				$report = CBX_Scanner::run_all();
				$t      = CBX_Scanner::totals( $report );
				$status = $t['manual'] || $t['shortcodes'] || ! empty( $report['blocks'] ) ? 'warn' : 'ok';
				$msg    = sprintf( '%d Crocoblock-widgets, %d ersätts automatiskt, %d kräver handpåläggning.', $t['widgets'], $t['auto'], $t['manual'] );
				if ( $t['shortcodes'] ) {
					$msg .= sprintf( ' %d kortkoder.', $t['shortcodes'] );
				}
				return self::res( $status, $msg );

			case 'content_types':
				$inv = CBX_Content_Types::inventory();
				if ( empty( $inv['post_types'] ) && empty( $inv['taxonomies'] ) && empty( $inv['meta_boxes'] ) ) {
					return self::res( 'ok', 'Inga JetEngine-innehållstyper.' );
				}
				$snap = CBX_Content_Types::snapshot();
				if ( is_wp_error( $snap ) ) {
					return self::res( 'warn', $snap->get_error_message() );
				}
				return self::res( 'ok', sprintf( 'Ögonblicksbild sparad: %d inläggstyper, %d taxonomier.', count( $inv['post_types'] ), count( $inv['taxonomies'] ) ) );

			case 'templates':
				$res   = CBX_Templates::migrate_all();
				$n     = 0;
				$had   = 0;
				$bad   = array();
				foreach ( $res as $rows ) {
					foreach ( (array) $rows as $r ) {
						if ( 'skipped' === $r['state'] ) {
							$had++;
						} elseif ( 'migrated' === $r['state'] ) {
							$n++;
						} elseif ( in_array( $r['state'], array( 'manual', 'error' ), true ) ) {
							$bad[] = $r['title'] . ': ' . implode( ' ', (array) $r['notes'] );
						}
					}
				}
				return self::res( $bad ? 'warn' : 'ok', sprintf( '%d mallar kopierade som utkast', $n ) . ( $had ? sprintf( ', %d fanns redan', $had ) : '' ) . '.' . ( $bad ? ' Kräver handpåläggning: ' . implode( '; ', $bad ) : '' ) );

			case 'site_css':
				$css = CBX_Site_CSS::build();
				$n   = isset( $css['sources'] ) ? array_sum( array_map( function ( $s ) { return isset( $s['translated'] ) ? (int) $s['translated'] : 0; }, $css['sources'] ) ) : 0;
				return self::res( 'ok', sprintf( '%d regler översatta.', $n ) );

			case 'dry':
				return self::step_dry( $run, (int) $offset );

			case 'previews':
				return self::step_previews( $run, (int) $offset );

			case 'stabilize':
				$sels = CBX_Stabilize::selectors();
				return self::res( 'ok', $sels ? sprintf( '%d positionsberoende selektorer att slå upp.', count( $sels ) ) : 'Inga positionsberoende regler.', true, array( 'selectors' => $sels ) );

			case 'live_jobs':
				$run['jobs'] = array();
				foreach ( self::live_docs() as $doc ) {
					$job = self::job_live( $doc );
					if ( $job ) {
						$run['jobs'][ $job['key'] ] = $job;
					}
				}
				self::save_run( $run );
				$n = count( array_filter( $run['jobs'], function ( $j ) { return '' !== $j['original_url']; } ) );
				return self::res( $n ? 'ok' : 'warn', sprintf( '%d sidor och mallar kontrolleras.', $n ), true, array( 'jobs' => array_values( $run['jobs'] ) ) );

			case 'undo':
				return self::res( 'ok', implode( ' ', self::undo_all() ) );

			case 'baseline':
			case 'compare':
			case 'verify':
			case 'verify_switch':
				// Görs i webbläsaren. Servern lämnar ut jobben.
				return self::res( 'ok', '', true, array( 'jobs' => array_values( $run['jobs'] ) ) );

			case 'csshero':
				$res = CBX_CSS_Hero::migrate( true );
				if ( is_wp_error( $res ) ) {
					return self::res( 'fail', $res->get_error_message() );
				}
				CBX_Site_CSS::build();
				self::flag( 'csshero' );
				return self::res( 'ok', sprintf( '%d regler flyttade till Ytterligare CSS.', (int) $res['moved'] ) );

			case 'convert':
				return self::step_convert( $run, (int) $offset );

			case 'http_check':
			case 'http_check_switch':
				$problems = self::http_check( $run['jobs'] );
				if ( null === $problems ) {
					return self::res( 'warn', 'Servern kan inte hämta sina egna sidor (loopback). Den visuella jämförelsen gäller.' );
				}
				return $problems ? self::res( 'fail', implode( ' ', $problems ) ) : self::res( 'ok', 'Alla sidor svarar och innehåller de nya widgetarna.' );

			case 'switch':
				$rem = CBX_Scanner::remaining();
				if ( $rem['count'] || $rem['templates'] ) {
					return self::res( 'switch' === $run['mode'] ? 'fail' : 'warn', sprintf( 'Crocoblock stängdes inte av: %d widgets på %d sidor och %d mallar används fortfarande.', $rem['count'], count( $rem['posts'] ), count( $rem['templates'] ) ), true, array( 'skip_rest' => true ) );
				}
				$n    = CBX_Templates::activate();
				$list = CBX_Plugin::deactivate_jet();
				self::flag( 'switched' );
				return self::res( 'ok', sprintf( '%d mallar publicerade, %d Crocoblock-tillägg avstängda.', $n, count( $list ) ) );

			case 'cleanup':
				$n = CBX_Preview::cleanup();
				return self::res( 'ok', sprintf( '%d förhandsvisningar borttagna.', $n ) );

			case 'rollback':
				return self::res( 'ok', implode( ' ', self::rollback() ) );
		}
		return self::res( 'fail', 'Okänt steg: ' . $step );
	}

	protected static function res( $status, $message, $done = true, $data = null ) {
		$out = array( 'status' => $status, 'message' => $message, 'done' => (bool) $done );
		if ( null !== $data ) {
			$out['data'] = $data;
		}
		return $out;
	}

	protected static function flag( $name ) {
		$run                   = self::run();
		$run['flags'][ $name ] = true;
		self::save_run( $run );
	}

	/** Provkörning i omgångar: vilka dokument ändras, och vilka element. */
	protected static function step_dry( array $run, $offset ) {
		$ids    = CBX_Plugin::elementor_post_ids( array( 'include_drafts' => false, 'limit' => self::BATCH, 'offset' => $offset ) );
		$mapper = CBX_Plugin::mapper();
		if ( 0 === $offset ) {
			$run['docs'] = array();
		}
		foreach ( $ids as $id ) {
			$res = CBX_Converter::process_post( $id, true, $mapper );
			if ( ! $res || ! $res['changed'] ) {
				if ( $res && self::count_status( $res['log'], 'manual' ) ) {
					$run['docs'][ $id ] = self::doc_row( $id, $res['log'], false );
				}
				continue;
			}
			$run['docs'][ $id ] = self::doc_row( $id, $res['log'], true );
		}
		self::save_run( $run );
		$done = count( $ids ) < self::BATCH;
		if ( ! $done ) {
			return self::res( 'ok', '', false, array( 'next' => $offset + self::BATCH, 'processed' => $offset + count( $ids ) ) );
		}
		// Migrerade JetThemeCore-mallar jämförs också (original = Jet-mallen).
		foreach ( (array) get_option( CBX_Templates::OPT_THEME, array() ) as $jet_id => $new_id ) {
			if ( $new_id && get_post( $new_id ) ) {
				$run['docs'][ 'theme-' . $jet_id ] = array(
					'id'       => (int) $new_id,
					'source'   => (int) $jet_id,
					'label'    => CBX_Plugin::post_label( $new_id ),
					'changed'  => true,
					'widgets'  => array(),
					'manual'   => 0,
					'migrated' => true,
				);
			}
		}
		self::save_run( $run );
		$docs    = $run['docs'];
		$changed = count( array_filter( $docs, function ( $d ) { return $d['changed']; } ) );
		$manual  = array_sum( array_column( $docs, 'manual' ) );
		return self::res(
			$manual ? 'warn' : 'ok',
			sprintf( '%d dokument ändras.', $changed ) . ( $manual ? sprintf( ' %d widgets kan inte konverteras automatiskt och ligger kvar som Crocoblock.', $manual ) : '' ),
			true,
			array( 'docs' => array_values( $docs ) )
		);
	}

	protected static function count_status( array $log, $status ) {
		return count( array_filter( $log, function ( $r ) use ( $status ) { return $r['status'] === $status; } ) );
	}

	protected static function doc_row( $id, array $log, $changed ) {
		$widgets = array();
		$manual  = array();
		$loose   = array();
		foreach ( $log as $r ) {
			if ( in_array( $r['status'], array( 'converted', 'cleaned', 'dynamic' ), true ) && '' !== (string) $r['id'] ) {
				$widgets[] = (string) $r['id'];
				// Avsiktligt ändrad layout (karusell → rutnät, Smart Listing/Tiles → rutnät):
				// originalet kan inte jämföras ruta för ruta, bara till stilen.
				foreach ( (array) $r['notes'] as $note ) {
					if ( preg_match( '/karusell|Smart Listing|Smart Tiles/i', (string) $note ) ) {
						$loose[] = (string) $r['id'];
						break;
					}
				}
			} elseif ( 'manual' === $r['status'] ) {
				$manual[] = $r['from'];
			}
		}
		if ( $loose ) {
			// Sektionerna och kolumnerna runt en karusell mäter samma rullande
			// innehåll – de måste också jämföras löst.
			$data = CBX_Plugin::get_data( $id );
			if ( $data ) {
				$loose = array_merge( $loose, self::loose_ancestors( $data, $loose ) );
			}
		}
		return array(
			'id'          => (int) $id,
			'label'       => CBX_Plugin::post_label( $id ),
			'changed'     => (bool) $changed,
			'widgets'     => array_values( array_unique( $widgets ) ),
			'loose'       => array_values( array_unique( $loose ) ),
			'manual'      => count( $manual ),
			'manual_list' => array_values( array_unique( $manual ) ),
		);
	}

	/** Id:n för alla element som innehåller något av $loose-elementen. */
	protected static function loose_ancestors( array $elements, array $loose, array $path = array() ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id = isset( $el['id'] ) ? (string) $el['id'] : '';
			if ( in_array( $id, $loose, true ) ) {
				$out = array_merge( $out, $path );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$out = array_merge( $out, self::loose_ancestors( $el['elements'], $loose, array_merge( $path, array( $id ) ) ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Skapar förhandsvisningar och bygger jämförelsejobben. */
	protected static function step_previews( array $run, $offset ) {
		$docs  = array_values( array_filter( $run['docs'], function ( $d ) { return $d['changed']; } ) );
		$slice = array_slice( $docs, $offset, self::BATCH );
		if ( 0 === $offset ) {
			$run['jobs'] = array();
		}
		foreach ( $slice as $doc ) {
			$job = self::job_for( $doc );
			if ( $job ) {
				$run['jobs'][ $job['key'] ] = $job;
			}
		}
		self::save_run( $run );
		$done = $offset + self::BATCH >= count( $docs );
		if ( ! $done ) {
			return self::res( 'ok', '', false, array( 'next' => $offset + self::BATCH, 'processed' => $offset + count( $slice ), 'total' => count( $docs ) ) );
		}
		$jobs    = $run['jobs'];
		$visual  = count( array_filter( $jobs, function ( $j ) { return '' !== $j['original_url']; } ) );
		return self::res( 'ok', sprintf( '%d förhandsvisningar, %d kan jämföras visuellt.', count( $jobs ), $visual ), true, array( 'jobs' => array_values( $jobs ) ) );
	}

	/**
	 * Ett jämförelsejobb: var originalet visas, vilka element som ska jämföras
	 * och adressen där samma sida visas med den konverterade kopian på plats
	 * (CBX_Preview::swap_url). Original och kopia visas alltså på exakt samma
	 * sida – samma inlägg, samma body-klasser, samma dynamiska innehåll.
	 */
	public static function job_for( array $doc ) {
		$id     = (int) $doc['id'];
		$source = isset( $doc['source'] ) ? (int) $doc['source'] : $id; // Jet-mallen för migrerade mallar.
		$post   = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$job = array(
			'key'                => 'doc-' . $id,
			'id'                 => $id,
			'label'              => $doc['label'],
			'type'               => 'elementor_library' === $post->post_type ? (string) get_post_meta( $id, '_elementor_template_type', true ) : $post->post_type,
			'widgets'            => array_values( $doc['widgets'] ),
			'loose'              => isset( $doc['loose'] ) ? array_values( $doc['loose'] ) : array(),
			'manual'             => (int) $doc['manual'],
			'context'            => false,
			'original_url'       => '',
			'original_root'      => '[data-elementor-id="' . $source . '"]',
			'preview_url'        => '',
			'preview_root'       => '',
			'verify_root'        => '[data-elementor-id="' . $source . '"]',
			'verify_root_switch' => '[data-elementor-id="' . ( empty( $doc['migrated'] ) ? $source : $id ) . '"]',
			'note'               => '',
		);
		$copy = CBX_Preview::create( $id, 'swap' );
		if ( is_wp_error( $copy ) ) {
			$job['note'] = $copy->get_error_message();
			return $job;
		}
		$job['preview_root'] = '[data-elementor-id="' . $copy . '"]';

		if ( 'elementor_library' !== $post->post_type ) {
			if ( 'publish' === $post->post_status ) {
				$job['original_url'] = get_permalink( $id );
			}
		} else {
			$conds = get_post_meta( $id, '_elementor_conditions', true );
			if ( ! $conds && class_exists( 'CBX_Templates' ) ) {
				$conds = get_post_meta( $id, CBX_Templates::PENDING, true );
			}
			$job['original_url'] = self::template_url( $job['type'], is_array( $conds ) ? $conds : array() );
			if ( '' === $job['original_url'] ) {
				$job['note'] = 'Mallen visas inte på någon egen sida (t.ex. popup, loop-mall eller sparad sektion) och jämförs inte visuellt.';
			}
		}
		if ( '' !== $job['original_url'] ) {
			$job['preview_url'] = CBX_Preview::swap_url( $job['original_url'], array( $source => $copy ) );
		}
		if ( $job['loose'] ) {
			$job['note'] = trim( $job['note'] . ' En karusell/särskild layout blir ett rutnät här – innehållet rullar i originalet, så det elementet jämförs bara till stilen.' );
		}
		return $job;
	}

	/**
	 * En sida där mallen visas, utifrån dess visningsvillkor
	 * (t.ex. include/singular/post, include/archive/category/5).
	 */
	public static function template_url( $type, array $conds ) {
		foreach ( $conds as $cond ) {
			$cond = (string) $cond;
			if ( 0 !== strpos( $cond, 'include/' ) ) {
				continue;
			}
			$p   = explode( '/', substr( $cond, 8 ) );
			$url = self::condition_url( $p );
			if ( $url ) {
				return $url;
			}
		}
		// Utan (läsbara) villkor: gissa utifrån malltypen.
		switch ( $type ) {
			case 'header':
			case 'footer':
				return home_url( '/' );
			case 'single':
			case 'single-post':
				return self::latest( 'post' );
			case 'single-page':
				return self::latest( 'page' );
			case 'archive':
				return self::condition_url( array( 'archive' ) );
			case 'search-results':
				return home_url( '/?s=a' );
			case 'error-404':
				return home_url( '/cbx-saknas-' . time() . '/' );
		}
		return '';
	}

	protected static function condition_url( array $p ) {
		$a = isset( $p[0] ) ? $p[0] : '';
		$b = isset( $p[1] ) ? $p[1] : '';
		$c = isset( $p[2] ) ? $p[2] : '';
		if ( 'general' === $a ) {
			return home_url( '/' );
		}
		if ( 'singular' === $a ) {
			if ( '' === $b ) {
				return self::latest( 'post' );
			}
			if ( 'front_page' === $b ) {
				return home_url( '/' );
			}
			if ( 'not_found404' === $b ) {
				return home_url( '/cbx-saknas-' . time() . '/' );
			}
			if ( 0 === strpos( $b, 'in_' ) ) {
				// Inlägg i en kategori/term (in_category/5, in_post_tag/7 …).
				$tax = substr( $b, 3 );
				return is_numeric( $c ) && taxonomy_exists( $tax ) ? self::latest( 'post', array( array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => (int) $c ) ) ) : self::latest( 'post' );
			}
			if ( is_numeric( $c ) && 'publish' === get_post_status( (int) $c ) ) {
				return get_permalink( (int) $c );
			}
			if ( post_type_exists( $b ) ) {
				return self::latest( $b );
			}
			return self::latest( 'post' );
		}
		if ( 'archive' === $a ) {
			if ( '' === $b || 'post_archive' === $b ) {
				$blog = (int) get_option( 'page_for_posts' );
				if ( $blog ) {
					return get_permalink( $blog );
				}
				$b = 'category';
			}
			if ( 'search' === $b ) {
				return home_url( '/?s=a' );
			}
			if ( preg_match( '/^(.+)_archive$/', $b, $m ) && function_exists( 'get_post_type_archive_link' ) ) {
				$link = get_post_type_archive_link( $m[1] );
				return is_string( $link ) ? $link : '';
			}
			if ( function_exists( 'get_term_link' ) && taxonomy_exists( $b ) ) {
				if ( is_numeric( $c ) ) {
					$link = get_term_link( (int) $c, $b );
					return is_string( $link ) ? $link : '';
				}
				$terms = get_terms( array( 'taxonomy' => $b, 'orderby' => 'count', 'order' => 'DESC', 'number' => 1, 'hide_empty' => true ) );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$link = get_term_link( $terms[0] );
					return is_string( $link ) ? $link : '';
				}
			}
		}
		return '';
	}

	protected static function latest( $post_type, array $tax_query = array() ) {
		$args = array( 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC' );
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore
		}
		$posts = get_posts( $args );
		if ( ! $posts && $tax_query ) {
			return self::latest( $post_type );
		}
		return $posts ? get_permalink( $posts[0]->ID ) : '';
	}

	/** Konvertering i omgångar (samma kod som steg 6). */
	protected static function step_convert( array $run, $offset ) {
		if ( 0 === $offset ) {
			// Innan något ändras: vilka dokument syns inte ens nu på sin adress?
			// (T.ex. en mall vars sida inte gick att gissa, eller en sida vars
			// adress går till inloggningen.) De kan inte HTML-kontrolleras
			// efteråt heller, och ska inte stoppa körningen.
			self::mark_unreachable();
			CBX_Converter::start( 'convert' );
			self::flag( 'converted' );
		}
		$res = CBX_Converter::step( 10 );
		if ( is_wp_error( $res ) ) {
			return self::res( 'fail', $res->get_error_message() );
		}
		if ( ! $res['done'] ) {
			return self::res( 'ok', '', false, array( 'next' => $offset + 1, 'processed' => $res['processed'], 'total' => $res['total'] ) );
		}
		$s = $res['summary'];
		return self::res( 'ok', sprintf( '%d dokument ändrade, %d widgets ersatta, %d kvar manuellt.', $s['changed'], $s['converted'], $s['manual'] ) );
	}

	/**
	 * Hämtar de publicerade sidorna och kontrollerar att de svarar och att de
	 * nya widgetarna finns i HTML:en. Returnerar null om servern inte kan hämta
	 * sina egna sidor.
	 *
	 * @return string[]|null Problem.
	 */
	/**
	 * Flaggar jobb vars dokument inte syns på sin adress redan FÖRE
	 * konverteringen. HTML-kontrollen efteråt hoppar över dem – frånvaron
	 * beror på adressen, inte på konverteringen.
	 *
	 * @return int Antal flaggade.
	 */
	public static function mark_unreachable() {
		$run = self::run();
		if ( empty( $run['jobs'] ) ) {
			return 0;
		}
		$pages = array();
		foreach ( $run['jobs'] as $key => $job ) {
			if ( '' !== $job['original_url'] && ! empty( $job['widgets'] ) ) {
				$pages[ $job['original_url'] ][] = $key;
			}
		}
		$n = 0;
		foreach ( $pages as $url => $keys ) {
			$res = wp_remote_get( add_query_arg( array( 'cbx_check' => time() ), $url ), array( 'timeout' => 15, 'redirection' => 3, 'sslverify' => false ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$html = (string) wp_remote_retrieve_body( $res );
			foreach ( $keys as $key ) {
				if ( ! self::root_in_html( $run['jobs'][ $key ]['original_root'], $html ) ) {
					$run['jobs'][ $key ]['unreachable'] = true;
					$n++;
				}
			}
		}
		self::save_run( $run );
		return $n;
	}

	/** Finns dokumentets rot (data-elementor-id) i HTML-koden? */
	protected static function root_in_html( $root_sel, $html ) {
		if ( ! preg_match( '/data-elementor-id="?(\d+)/', (string) $root_sel, $m ) ) {
			return true;
		}
		return (bool) preg_match( '/data-elementor-id=["\']?' . $m[1] . '(?!\d)/', $html );
	}

	public static function http_check( array $jobs ) {
		$problems = array();
		$pages    = array();
		foreach ( $jobs as $job ) {
			if ( '' === $job['original_url'] ) {
				continue;
			}
			$pages[ $job['original_url'] ][] = $job;
		}
		$reached = 0;
		foreach ( $pages as $url => $page_jobs ) {
			$res = wp_remote_get( add_query_arg( array( 'cbx_check' => time() ), $url ), array( 'timeout' => 20, 'redirection' => 3, 'sslverify' => false ) );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$reached++;
			$code = (int) wp_remote_retrieve_response_code( $res );
			$html = (string) wp_remote_retrieve_body( $res );
			if ( $code >= 400 ) {
				$problems[] = sprintf( '%s svarar med HTTP %d.', $url, $code );
				continue;
			}
			if ( preg_match( '/(critical error|kritiskt fel|Fatal error)/i', $html ) ) {
				$problems[] = sprintf( '%s visar ett PHP-fel.', $url );
				continue;
			}
			foreach ( $page_jobs as $job ) {
				if ( $job['context'] || ! empty( $job['unreachable'] ) || empty( $job['widgets'] ) ) {
					// Innehållsberoende mall, eller ett dokument som inte syntes
					// på adressen ens före konverteringen – bara svars- och
					// felkontrollen ovan gäller.
					continue;
				}
				if ( ! self::root_in_html( $job['original_root'], $html ) ) {
					$problems[] = sprintf( '%s visas inte längre på %s.', $job['label'], $url );
					continue;
				}
				$missing = array();
				foreach ( $job['widgets'] as $wid ) {
					if ( false === strpos( $html, 'elementor-element-' . $wid ) ) {
						$missing[] = $wid;
					}
				}
				if ( $missing ) {
					$problems[] = sprintf( '%s: %d element saknas på %s (%s).', $job['label'], count( $missing ), $url, implode( ', ', array_slice( $missing, 0, 5 ) ) );
				}
			}
		}
		if ( $pages && 0 === $reached ) {
			return null;
		}
		return $problems;
	}

	/** Konverterade dokument och migrerade Jet-mallar – det som syns när Crocoblock stängs av. */
	protected static function live_docs() {
		$docs = array();
		foreach ( CBX_Plugin::backup_post_ids() as $id ) {
			$docs[] = array( 'id' => (int) $id, 'label' => CBX_Plugin::post_label( $id ), 'widgets' => array(), 'manual' => 0 );
		}
		foreach ( (array) get_option( CBX_Templates::OPT_THEME, array() ) as $jet_id => $new_id ) {
			if ( $new_id && get_post( $new_id ) ) {
				$docs[] = array( 'id' => (int) $new_id, 'source' => (int) $jet_id, 'label' => CBX_Plugin::post_label( $new_id ), 'widgets' => array(), 'manual' => 0, 'migrated' => true );
			}
		}
		return $docs;
	}

	/** Jobb för den publicerade sajten (ingen kopia): hela dokumentet jämförs. */
	public static function job_live( array $doc ) {
		$id     = (int) $doc['id'];
		$source = isset( $doc['source'] ) ? (int) $doc['source'] : $id;
		$post   = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$type = 'elementor_library' === $post->post_type ? (string) get_post_meta( $id, '_elementor_template_type', true ) : $post->post_type;
		$url  = '';
		if ( 'elementor_library' !== $post->post_type ) {
			$url = 'publish' === $post->post_status ? get_permalink( $id ) : '';
		} else {
			$conds = get_post_meta( $id, '_elementor_conditions', true );
			if ( ! $conds ) {
				$conds = get_post_meta( $id, CBX_Templates::PENDING, true );
			}
			$url = self::template_url( $type, is_array( $conds ) ? $conds : array() );
		}
		return array(
			'key'                => 'live-' . $id,
			'id'                 => $id,
			'label'              => $doc['label'],
			'type'               => $type,
			'widgets'            => array(),
			'manual'             => 0,
			'context'            => false,
			'original_url'       => $url,
			'original_root'      => '[data-elementor-id="' . $source . '"]',
			'preview_url'        => $url,
			'preview_root'       => '[data-elementor-id="' . $source . '"]',
			'verify_root'        => '[data-elementor-id="' . $source . '"]',
			'verify_root_switch' => '[data-elementor-id="' . ( empty( $doc['migrated'] ) ? $source : $id ) . '"]',
			'note'               => '' === $url ? 'Visas inte på någon egen sida och jämförs inte visuellt.' : '',
		);
	}

	/**
	 * Ångra allt: originalsidorna tillbaka, Crocoblock på, migrerade mallar och
	 * CSS Hero-flytten bort, stabila CSS-kopior av, förhandsvisningar bort.
	 *
	 * @return string[]
	 */
	public static function undo_all() {
		$msgs = array();
		$n    = 0;
		foreach ( CBX_Plugin::backup_post_ids() as $id ) {
			$n += CBX_Plugin::restore( $id ) ? 1 : 0;
		}
		$msgs[] = sprintf( '%d sidor återställda.', $n );
		$re     = CBX_Plugin::reactivate_jet();
		if ( is_wp_error( $re ) ) {
			$msgs[] = 'Crocoblock kunde inte slås på: ' . $re->get_error_message();
		} elseif ( $re ) {
			$msgs[] = sprintf( '%d Crocoblock-tillägg på igen.', $re );
		}
		$t = CBX_Templates::undo();
		if ( $t ) {
			$msgs[] = sprintf( '%d migrerade mallar i papperskorgen.', $t );
		}
		if ( CBX_CSS_Hero::backup() ) {
			$hero   = CBX_CSS_Hero::undo();
			$msgs[] = is_wp_error( $hero ) ? 'CSS Hero: ' . $hero->get_error_message() : 'CSS Hero på igen.';
		}
		CBX_Stabilize::activate( false );
		CBX_Site_CSS::build();
		CBX_Preview::cleanup();
		CBX_Plugin::clear_all_cache();
		self::save_run( array_merge( self::run(), array( 'flags' => array() ) ) );
		return $msgs;
	}

	/** Återställer allt som den här körningen ändrade. */
	public static function rollback() {
		$run   = self::run();
		$flags = isset( $run['flags'] ) ? $run['flags'] : array();
		$msgs  = array();
		$n     = 0;
		if ( isset( $run['mode'] ) && 'switch' === $run['mode'] ) {
			// Bara avstängningen ångras – sidorna är fortfarande konverterade.
			$re     = CBX_Plugin::reactivate_jet();
			$msgs[] = is_wp_error( $re ) ? 'Crocoblock kunde inte slås på: ' . $re->get_error_message() : sprintf( '%d Crocoblock-tillägg på igen.', (int) $re );
			if ( ! empty( $flags['switched'] ) ) {
				$msgs[] = sprintf( '%d nya mallar tillbaka som utkast.', CBX_Templates::deactivate() );
			}
			$run['flags'] = array();
			self::save_run( $run );
			return $msgs;
		}
		foreach ( CBX_Plugin::backup_post_ids() as $id ) {
			$n += CBX_Plugin::restore( $id ) ? 1 : 0;
		}
		if ( $n ) {
			$msgs[] = sprintf( '%d sidor återställda.', $n );
		}
		$re = CBX_Plugin::reactivate_jet();
		if ( is_wp_error( $re ) ) {
			$msgs[] = 'Crocoblock kunde inte aktiveras igen: ' . $re->get_error_message();
		} elseif ( $re ) {
			$msgs[] = sprintf( '%d Crocoblock-tillägg aktiva igen.', $re );
		}
		if ( ! empty( $flags['switched'] ) ) {
			$msgs[] = sprintf( '%d migrerade mallar till papperskorgen.', CBX_Templates::undo() );
		}
		if ( ! CBX_Plugin::backup_post_ids() ) {
			CBX_Stabilize::activate( false );
		}
		if ( ! empty( $flags['csshero'] ) ) {
			$hero   = CBX_CSS_Hero::undo();
			$msgs[] = is_wp_error( $hero ) ? 'CSS Hero: ' . $hero->get_error_message() : 'CSS Hero aktivt igen.';
			CBX_Site_CSS::build();
		}
		CBX_Plugin::clear_all_cache();
		$run['flags'] = array();
		self::save_run( $run );
		return $msgs ? $msgs : array( 'Inget behövde återställas.' );
	}

	/**
	 * Kör ett steg till slut (alla omgångar). Används av WP-CLI och tester.
	 */
	public static function run_step( $step ) {
		$offset = 0;
		$guard  = 0;
		do {
			$res    = self::step( $step, $offset );
			$offset = isset( $res['data']['next'] ) ? (int) $res['data']['next'] : $offset + 1;
		} while ( ! $res['done'] && ++$guard < 10000 );
		return $res;
	}

	/**
	 * Hela körningen utan webbläsare (WP-CLI). Den visuella jämförelsen hoppas
	 * över; HTML-kontrollen av de publicerade sidorna gäller i stället.
	 *
	 * @param callable|null $log fn( string $step, array $res )
	 * @return array{ok: bool, steps: array, rolled_back: string[]}
	 */
	public static function run_headless( $mode, array $opts = array(), $log = null ) {
		$run     = self::start( $mode, $opts );
		$results = array();
		$ok      = true;
		$undo    = false;
		$skip_sw = false;
		foreach ( $run['steps'] as $step ) {
			if ( in_array( $step, array( 'compare', 'verify', 'verify_switch' ), true ) ) {
				$res = self::res( 'skip', 'Visuell jämförelse görs i webbläsaren (Verktyg → Crocoblock-avveckling → Autopilot).' );
			} elseif ( $skip_sw && false !== strpos( $step, 'switch' ) ) {
				$res = self::res( 'skip', 'Hoppades över.' );
			} elseif ( ! $ok && 'cleanup' !== $step ) {
				$res = self::res( 'skip', 'Hoppades över.' );
			} else {
				$res = self::run_step( $step );
			}
			$results[ $step ] = $res;
			if ( $log ) {
				call_user_func( $log, $step, $res );
			}
			if ( 'switch' === $step && ! empty( $res['data']['skip_rest'] ) ) {
				$skip_sw = true;
			}
			if ( 'fail' === $res['status'] ) {
				$ok   = false;
				$undo = $undo || in_array( $step, array( 'csshero', 'convert', 'http_check', 'switch', 'http_check_switch' ), true );
			}
		}
		$rolled = $undo ? self::rollback() : array();
		self::save_report(
			array(
				'mode'         => $mode,
				'started'      => $run['started'],
				'finished'     => current_time( 'mysql' ),
				'headless'     => true,
				'steps'        => array_map( function ( $r ) { return $r['status']; }, $results ),
				'jobs'         => array(),
				'verdictLevel' => $ok ? 'ok' : 'bad',
				'verdict'      => $ok ? 'Klart (WP-CLI, utan visuell jämförelse).' : 'Stoppat (WP-CLI).' . ( $rolled ? ' Återställt: ' . implode( ' ', $rolled ) : '' ),
			)
		);
		return array( 'ok' => $ok, 'steps' => $results, 'rolled_back' => $rolled );
	}

	/** Sparar rapporten från webbläsaren (jämförelserna) och stegen. */
	public static function save_report( array $report ) {
		$report['saved'] = current_time( 'mysql' );
		update_option( self::OPT_REPORT, $report, false );
	}

	public static function last_report() {
		$r = get_option( self::OPT_REPORT, null );
		return is_array( $r ) ? $r : null;
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	public static function ajax() {
		if ( ! current_user_can( CBX_Admin::CAP ) || ! check_ajax_referer( 'cbx_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Behörighet saknas eller sidan är för gammal – ladda om.' ), 403 );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'start' === $op ) {
			$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'test';
			$opts = array(
				'csshero'    => ! empty( $_POST['csshero'] ),
				'switch'     => ! empty( $_POST['switch'] ),
				'allow_warn' => ! empty( $_POST['allow_warn'] ),
			);
			$run = self::start( $mode, $opts );
			wp_send_json_success( array( 'steps' => $run['steps'], 'labels' => self::labels(), 'opts' => $run['opts'], 'mode' => $run['mode'] ) );
		}
		if ( 'step' === $op ) {
			$step   = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			wp_send_json_success( self::step( $step, $offset ) );
		}
		if ( 'stabilize_save' === $op ) {
			$raw = isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : ''; // phpcs:ignore
			$map = json_decode( (string) $raw, true );
			$res = CBX_Stabilize::build( is_array( $map ) ? $map : array() );
			wp_send_json_success( array( 'status' => 'ok', 'message' => sprintf( '%d regler fick stabila kopior (%d element).', $res['rules'], $res['elements'] ), 'done' => true ) );
		}
		if ( 'report' === $op ) {
			$raw    = isset( $_POST['report'] ) ? wp_unslash( $_POST['report'] ) : ''; // phpcs:ignore
			$report = json_decode( (string) $raw, true );
			if ( is_array( $report ) ) {
				self::save_report( $report );
			}
			wp_send_json_success( array( 'saved' => is_array( $report ) ) );
		}
		wp_send_json_error( array( 'message' => 'Okänd åtgärd.' ) );
	}
}
