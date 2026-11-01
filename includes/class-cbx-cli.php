<?php
/**
 * WP-CLI: wp crocoblock-exit <kommando>
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avveckla Crocoblock från kommandoraden.
 *
 * ## EXEMPEL
 *
 *     wp crocoblock-exit scan
 *     wp crocoblock-exit snapshot
 *     wp crocoblock-exit templates
 *     wp crocoblock-exit site-css
 *     wp crocoblock-exit csshero --dry-run
 *     wp crocoblock-exit convert --dry-run
 *     wp crocoblock-exit convert
 *     wp crocoblock-exit switch
 *     wp crocoblock-exit restore
 */
class CBX_CLI {

	/**
	 * Inventerar sajten.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv eller json.
	 * ---
	 * default: table
	 * ---
	 */
	public function scan( $args, $assoc ) {
		WP_CLI::log( 'Inventerar …' );
		$report = CBX_Scanner::run_all();
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( 'csv' === $format ) {
			$out = fopen( 'php://stdout', 'w' );
			foreach ( CBX_Scanner::csv_rows( $report ) as $row ) {
				fputcsv( $out, $row, ';' );
			}
			return;
		}
		$t = CBX_Scanner::totals( $report );
		WP_CLI::log( sprintf( '%d dokument genomsökta. %d Crocoblock-widgets: %d automatiskt, %d manuellt. %d sidor berörs.', $report['scanned'], $t['widgets'], $t['auto'], $t['manual'], $t['pages'] ) );
		$rows = array();
		foreach ( $report['widgets'] as $type => $w ) {
			$rows[] = array( 'widget' => $type, 'antal' => $w['count'], 'auto' => $w['auto'], 'manuellt' => $w['manual'], 'sidor' => count( $w['posts'] ) );
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'widget', 'antal', 'auto', 'manuellt', 'sidor' ) );
		}
		foreach ( array( 'extensions' => 'Tillägg', 'tags' => 'Dynamiska taggar', 'shortcodes' => 'Kortkoder' ) as $key => $label ) {
			foreach ( $report[ $key ] as $name => $row ) {
				WP_CLI::log( sprintf( '%s: %s × %d', $label, $name, $row['count'] ) );
			}
		}
		$tpl = $report['templates'];
		WP_CLI::log( sprintf( 'Mallar: %d listningar, %d popuper, %d ThemeCore-mallar.', count( $tpl['listings'] ), count( $tpl['popups'] ), count( $tpl['theme'] ) ) );
		$c = $report['content'];
		WP_CLI::log( sprintf( 'Innehållstyper: %d inläggstyper, %d taxonomier, %d fältgrupper.', count( $c['post_types'] ), count( $c['taxonomies'] ), count( $c['meta_boxes'] ) ) );
	}

	/**
	 * Sparar ögonblicksbild av JetEngines inläggstyper och taxonomier.
	 *
	 * ## OPTIONS
	 *
	 * [--export=<fil>]
	 * : Skriv även PHP-koden till en fil.
	 */
	public function snapshot( $args, $assoc ) {
		$res = CBX_Content_Types::snapshot();
		if ( is_wp_error( $res ) ) {
			WP_CLI::warning( $res->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Sparad: %d inläggstyper, %d taxonomier, %d fältgrupper.', count( $res['post_types'] ), count( $res['taxonomies'] ), count( $res['meta_boxes'] ) ) );
		if ( ! empty( $assoc['export'] ) ) {
			file_put_contents( $assoc['export'], CBX_Content_Types::php_export() ); // phpcs:ignore
			WP_CLI::success( 'PHP-kod skriven till ' . $assoc['export'] );
		}
	}

	/**
	 * Migrerar JetEngine-listningar, JetPopup och JetThemeCore till Elementor Pro.
	 */
	public function templates( $args, $assoc ) {
		$results = CBX_Templates::migrate_all();
		update_option( 'cbx_last_template_run', array( 'time' => current_time( 'mysql' ), 'results' => $results ), false );
		foreach ( $results as $group => $rows ) {
			foreach ( $rows as $r ) {
				WP_CLI::log( sprintf( '[%s] %s #%d → #%d: %s', $group, $r['title'], $r['from'], $r['to'], $r['state'] ) );
				foreach ( $r['notes'] as $n ) {
					WP_CLI::log( '    - ' . $n );
				}
			}
		}
		WP_CLI::success( 'Klart.' );
	}

	/**
	 * Letar upp sajtens egen CSS som pekar på Crocoblock och skapar översatta kopior.
	 *
	 * @subcommand site-css
	 */
	public function site_css( $args, $assoc ) {
		$d = CBX_Site_CSS::build();
		foreach ( $d['sources'] as $src ) {
			WP_CLI::log( sprintf( '%s: %d regler översatta%s', $src['label'], $src['translated'], $src['untranslated'] ? ', utan motsvarighet: ' . implode( ', ', $src['untranslated'] ) : '' ) );
		}
		WP_CLI::success( $d['sources'] ? 'Översatt CSS är aktiv.' : 'Ingen egen CSS pekar på Crocoblock.' );
	}

	/**
	 * Avvecklar CSS Hero: flyttar dess CSS till Ytterligare CSS och avaktiverar det.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Visa bara vad som skulle flyttas.
	 *
	 * [--undo]
	 * : Ångra en tidigare flytt.
	 *
	 * [--keep-active]
	 * : Låt CSS Hero vara aktivt efter flytten.
	 */
	public function csshero( $args, $assoc ) {
		if ( ! empty( $assoc['undo'] ) ) {
			$res = CBX_CSS_Hero::undo();
			is_wp_error( $res ) ? WP_CLI::error( $res->get_error_message() ) : WP_CLI::success( 'CSS Hero är aktivt igen.' );
			CBX_Site_CSS::build();
			return;
		}
		$st = CBX_CSS_Hero::status();
		if ( ! $st ) {
			WP_CLI::success( 'CSS Hero finns inte på sajten.' );
			return;
		}
		$a = $st['analysis'];
		WP_CLI::log( sprintf( 'Källa: %s. %d regler, %d mot Crocoblock, %d sköra.', implode( ', ', $st['sources'] ), $a['rules'], $a['jet'], count( $a['fragile'] ) ) );
		foreach ( $a['fragile'] as $sel ) {
			WP_CLI::log( '  skör: ' . $sel );
		}
		if ( ! empty( $assoc['dry-run'] ) ) {
			return;
		}
		$res = CBX_CSS_Hero::migrate( empty( $assoc['keep-active'] ) );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		CBX_Site_CSS::build();
		WP_CLI::success( sprintf( '%d regler flyttade till Ytterligare CSS.%s', $res['moved'], $res['deactivated'] ? ' CSS Hero avaktiverat.' : '' ) );
	}

	/**
	 * Konverterar sidor (eller provkör).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Visa vad som skulle hända utan att ändra något.
	 *
	 * [--post=<id>]
	 * : Bara en post.
	 *
	 * [--verbose]
	 * : Visa varje ändring.
	 */
	public function convert( $args, $assoc ) {
		$dry     = ! empty( $assoc['dry-run'] );
		$verbose = ! empty( $assoc['verbose'] );
		if ( ! empty( $assoc['post'] ) ) {
			$ids = array( (int) $assoc['post'] );
		} else {
			$settings = CBX_Plugin::settings();
			$ids      = CBX_Plugin::elementor_post_ids( array( 'include_drafts' => (bool) $settings['include_drafts'] ) );
		}
		$mapper = CBX_Plugin::mapper();
		$total  = array( 'changed' => 0, 'converted' => 0, 'manual' => 0, 'cleaned' => 0, 'dynamic' => 0 );
		$bar    = WP_CLI\Utils\make_progress_bar( $dry ? 'Provkör' : 'Konverterar', count( $ids ) );
		$lines  = array();
		foreach ( $ids as $id ) {
			$res = CBX_Converter::process_post( $id, $dry, $mapper );
			$bar->tick();
			if ( ! $res || ! $res['log'] ) {
				continue;
			}
			$s = CBX_Mapper::summarize( $res['log'] );
			foreach ( $s as $k => $n ) {
				$total[ $k ] += $n;
			}
			$total['changed'] += $res['changed'] ? 1 : 0;
			$lines[]           = sprintf( '%s: %d ersatta, %d manuella, %d rensade, %d taggar', CBX_Plugin::post_label( $id ), $s['converted'], $s['manual'], $s['cleaned'], $s['dynamic'] );
			if ( $verbose ) {
				foreach ( $res['log'] as $l ) {
					$lines[] = sprintf( '    [%s] %s%s %s', $l['status'], $l['from'], $l['to'] ? ' → ' . $l['to'] : '', implode( ' ', $l['notes'] ) );
				}
			}
		}
		$bar->finish();
		foreach ( $lines as $l ) {
			WP_CLI::log( $l );
		}
		if ( ! $dry ) {
			CBX_Plugin::clear_all_cache();
		}
		WP_CLI::success( sprintf( '%s %d sidor. %d widgets ersatta, %d kvar manuellt, %d element rensade, %d taggar.', $dry ? 'Skulle ändra' : 'Ändrade', $total['changed'], $total['converted'], $total['manual'], $total['cleaned'], $total['dynamic'] ) );
	}

	/**
	 * Publicerar migrerade mallar och avaktiverar alla Crocoblock-tillägg.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Avaktivera även om Crocoblock-widgets finns kvar i innehållet.
	 *
	 * [--yes]
	 * : Fråga inte.
	 *
	 * @subcommand switch
	 */
	public function switch_( $args, $assoc ) {
		$remaining = CBX_Scanner::remaining();
		if ( $remaining['count'] || $remaining['templates'] ) {
			foreach ( $remaining['posts'] as $id => $types ) {
				WP_CLI::log( sprintf( '  #%d %s: %s', $id, get_the_title( $id ), implode( ', ', array_keys( $types ) ) ) );
			}
			foreach ( $remaining['templates'] as $t ) {
				WP_CLI::log( '  ' . $t . ' – inte migrerad' );
			}
			if ( empty( $assoc['force'] ) ) {
				WP_CLI::error( sprintf( '%d Crocoblock-widgets finns kvar och skulle försvinna. Konvertera först, eller kör med --force.', $remaining['count'] ) );
			}
			WP_CLI::warning( 'Fortsätter trots kvarvarande Crocoblock-innehåll (--force).' );
		}
		WP_CLI::confirm( 'Publicera migrerade mallar och avaktivera Crocoblock?', $assoc );
		$n      = CBX_Templates::activate();
		$active = array_keys( array_filter( CBX_Plugin::jet_plugins(), function ( $p ) { return $p['active']; } ) );
		if ( $active ) {
			deactivate_plugins( $active );
			$prev = (array) get_option( 'cbx_deactivated_plugins', array() );
			update_option( 'cbx_deactivated_plugins', array_values( array_unique( array_merge( $prev, $active ) ) ), false );
		}
		CBX_Plugin::clear_all_cache();
		WP_CLI::success( sprintf( '%d mallar publicerade, %d tillägg avaktiverade.', $n, count( $active ) ) );
	}

	/**
	 * Autopilot: kör alla steg i följd och kontrollerar resultatet.
	 *
	 * Utan --apply ändras ingenting som besökare ser (mallar och
	 * förhandsvisningar skapas som utkast). Med --apply konverteras sajten,
	 * de publicerade sidorna kontrolleras och allt återställs automatiskt vid
	 * fel. Den visuella jämförelsen (tre skärmbredder) kräver webbläsaren –
	 * kör Autopilot under Verktyg → Crocoblock-avveckling för den.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Genomför konverteringen.
	 *
	 * [--switch]
	 * : Stäng av Crocoblock efteråt om inget använder det längre.
	 *
	 * [--csshero]
	 * : Flytta CSS Hero till Ytterligare CSS.
	 *
	 * [--yes]
	 * : Fråga inte.
	 */
	public function autopilot( $args, $assoc ) {
		$mode = ! empty( $assoc['apply'] ) ? 'apply' : 'test';
		if ( 'apply' === $mode ) {
			WP_CLI::confirm( 'Konvertera sajten' . ( ! empty( $assoc['switch'] ) ? ' och stänga av Crocoblock' : '' ) . '? Vid fel återställs allt.', $assoc );
		}
		$labels = CBX_Autopilot::labels();
		$res    = CBX_Autopilot::run_headless(
			$mode,
			array( 'switch' => ! empty( $assoc['switch'] ), 'csshero' => ! empty( $assoc['csshero'] ) ),
			function ( $step, $r ) use ( $labels ) {
				$line = sprintf( '%-48s %s', isset( $labels[ $step ] ) ? $labels[ $step ] : $step, $r['message'] );
				if ( 'fail' === $r['status'] ) {
					WP_CLI::warning( $line );
				} else {
					WP_CLI::log( ( 'ok' === $r['status'] ? '✔ ' : ( 'warn' === $r['status'] ? '! ' : '- ' ) ) . $line );
				}
			}
		);
		if ( $res['rolled_back'] ) {
			WP_CLI::warning( 'Återställt: ' . implode( ' ', $res['rolled_back'] ) );
		}
		if ( ! $res['ok'] ) {
			WP_CLI::error( 'Autopiloten stoppade.' );
		}
		WP_CLI::success( 'apply' === $mode ? 'Klart. Kör även Autopilot i webbläsaren för den visuella kontrollen.' : 'Test klart – ingenting är ändrat på sajten.' );
	}

	/**
	 * Återställer sidor från backup.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Bara en post.
	 *
	 * [--templates]
	 * : Flytta även migrerade mallar till papperskorgen.
	 *
	 * [--[no-]reactivate]
	 * : Aktivera Crocoblock-tilläggen igen. Sker automatiskt när sidor återställs; --no-reactivate stänger av det.
	 *
	 * [--yes]
	 * : Fråga inte.
	 */
	public function restore( $args, $assoc ) {
		$ids = ! empty( $assoc['post'] ) ? array( (int) $assoc['post'] ) : CBX_Plugin::backup_post_ids();
		WP_CLI::confirm( sprintf( 'Återställa %d sidor?', count( $ids ) ), $assoc );
		$n = 0;
		foreach ( $ids as $id ) {
			$n += CBX_Plugin::restore( $id ) ? 1 : 0;
		}
		CBX_Plugin::clear_all_cache();
		WP_CLI::success( sprintf( '%d sidor återställda.', $n ) );
		if ( ! empty( $assoc['templates'] ) ) {
			WP_CLI::success( sprintf( '%d mallar till papperskorgen.', CBX_Templates::undo() ) );
		}
		// Originalen innehåller Jet-widgets – Crocoblock aktiveras alltid igen
		// (om inte --no-reactivate anges).
		if ( $n && ! isset( $assoc['reactivate'] ) || ! empty( $assoc['reactivate'] ) ) {
			$res = CBX_Plugin::reactivate_jet();
			if ( is_wp_error( $res ) ) {
				WP_CLI::warning( $res->get_error_message() );
			} elseif ( $res ) {
				WP_CLI::success( sprintf( '%d Crocoblock-tillägg aktiverade igen.', $res ) );
			}
		}
	}
}
