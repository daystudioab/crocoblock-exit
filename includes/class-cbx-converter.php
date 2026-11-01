<?php
/**
 * Provkörning, konvertering och återställning av sidor.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Converter {

	const OPT_PROGRESS = 'cbx_convert_progress';
	const OPT_LAST     = 'cbx_last_convert';

	/**
	 * @param string $mode dry|convert|restore
	 */
	public static function start( $mode ) {
		$settings = CBX_Plugin::settings();
		if ( 'restore' === $mode ) {
			$total = count( CBX_Plugin::backup_post_ids() );
		} else {
			$total = CBX_Plugin::count_elementor_posts( array( 'include_drafts' => (bool) $settings['include_drafts'] ) );
		}
		if ( 'convert' === $mode && class_exists( 'CBX_Stabilize' ) ) {
			// Stabila kopior av positionsberoende CSS (byggs av autopiloten) behövs när strukturen ändras.
			CBX_Stabilize::activate( true );
		}
		$progress = array(
			'mode'    => $mode,
			'total'   => $total,
			'offset'  => 0,
			'started' => current_time( 'mysql' ),
			'summary' => array( 'posts' => 0, 'changed' => 0, 'converted' => 0, 'manual' => 0, 'cleaned' => 0, 'dynamic' => 0, 'restored' => 0 ),
		);
		update_option( self::OPT_PROGRESS, $progress, false );
		return $progress;
	}

	public static function step( $limit = 10 ) {
		$progress = get_option( self::OPT_PROGRESS );
		if ( ! $progress ) {
			return new WP_Error( 'cbx_no_run', 'Ingen körning pågår.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$mode     = $progress['mode'];
		$settings = CBX_Plugin::settings();
		$rows     = array();

		if ( 'restore' === $mode ) {
			// Återställning tar bort backupen, så listan krymper – börja alltid från början.
			$ids = array_slice( CBX_Plugin::backup_post_ids(), 0, $limit );
			foreach ( $ids as $id ) {
				if ( CBX_Plugin::restore( $id ) ) {
					$progress['summary']['restored']++;
					$rows[] = self::row( $id, array(), 'restored' );
				}
			}
			$progress['offset'] += count( $ids );
			$done                = count( $ids ) < $limit;
		} else {
			$ids    = CBX_Plugin::elementor_post_ids(
				array(
					'include_drafts' => (bool) $settings['include_drafts'],
					'limit'          => $limit,
					'offset'         => $progress['offset'],
				)
			);
			$mapper = CBX_Plugin::mapper();
			foreach ( $ids as $id ) {
				$res = self::process_post( $id, 'dry' === $mode, $mapper );
				$progress['summary']['posts']++;
				if ( $res && $res['log'] ) {
					foreach ( CBX_Mapper::summarize( $res['log'] ) as $k => $n ) {
						$progress['summary'][ $k ] += $n;
					}
					if ( $res['changed'] ) {
						$progress['summary']['changed']++;
					}
					$rows[] = self::row( $id, $res['log'], $res['changed'] ? ( 'dry' === $mode ? 'would_change' : 'changed' ) : 'manual_only' );
				}
			}
			$progress['offset'] += count( $ids );
			$done                = count( $ids ) < $limit;
		}

		if ( $done ) {
			if ( 'restore' === $mode ) {
				// Originalsidorna innehåller Jet-widgets igen – då måste Crocoblock vara på.
				$progress['summary']['reactivated'] = CBX_Plugin::reactivate_jet();
				if ( ! CBX_Plugin::backup_post_ids() && class_exists( 'CBX_Stabilize' ) ) {
					CBX_Stabilize::activate( false );
				}
			}
			if ( 'dry' !== $mode ) {
				CBX_Plugin::clear_all_cache();
			}
			$progress['finished'] = current_time( 'mysql' );
			update_option( self::OPT_LAST, $progress, false );
			delete_option( self::OPT_PROGRESS );
		} else {
			update_option( self::OPT_PROGRESS, $progress, false );
		}

		return array(
			'done'      => $done,
			'mode'      => $mode,
			'processed' => $progress['offset'],
			'total'     => $progress['total'],
			'summary'   => $progress['summary'],
			'rows'      => $rows,
		);
	}

	/**
	 * Konverterar (eller provkör) en enskild post.
	 *
	 * @return array{log: array, changed: bool}|null
	 */
	public static function process_post( $post_id, $dry = true, ?CBX_Mapper $mapper = null ) {
		$data = CBX_Plugin::get_data( $post_id );
		if ( ! $data ) {
			return null;
		}
		$mapper                   = $mapper ? $mapper : CBX_Plugin::mapper();
		list( $new, $log )        = $mapper->convert_document( $data, 'page' );
		$changed                  = CBX_Mapper::log_has_changes( $log );
		$free                     = array();
		if ( $changed ) {
			// Stilen läses från Jet-widgetarnas genererade CSS innan något sparas.
			list( $new, $log, $free ) = CBX_Styles::apply( $post_id, $new, $log );
		}

		if ( ! $dry && $changed ) {
			CBX_Plugin::backup( $post_id );
			CBX_Plugin::save_data( $post_id, $new );
			CBX_Styles::store_free( $post_id, $free );
			CBX_Styles::forget( $post_id );
			$previous = get_post_meta( $post_id, CBX_Plugin::LOG_META, true );
			$entry    = array( 'time' => current_time( 'mysql' ), 'log' => $log );
			$history  = is_array( $previous ) ? $previous : array();
			$history[] = $entry;
			update_post_meta( $post_id, CBX_Plugin::LOG_META, array_slice( $history, -5 ) );
		}
		return array( 'log' => $log, 'changed' => $changed );
	}

	protected static function row( $post_id, array $log, $state ) {
		return array(
			'id'      => $post_id,
			'label'   => CBX_Plugin::post_label( $post_id ),
			'edit'    => CBX_Plugin::edit_link( $post_id ),
			'view'    => CBX_Plugin::view_link( $post_id ),
			'state'   => $state,
			'preview' => 'restored' === $state ? '' : CBX_Preview::link( $post_id ),
			'summary' => CBX_Mapper::summarize( $log ),
			'log'     => $log,
		);
	}

	public static function last_run() {
		return get_option( self::OPT_LAST, null );
	}

	public static function running() {
		return get_option( self::OPT_PROGRESS, null );
	}
}
