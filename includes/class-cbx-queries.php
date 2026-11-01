<?php
/**
 * Crocoblocks "egen fråga" (WP_Query som JSON i widgeten) förs över till
 * Elementor Pros Query ID. Tillägget registrerar en hook per fråga:
 * elementor/query/{id} sätter samma argument som Crocoblock använde.
 *
 * @package CrocoblockExit
 */

defined( 'ABSPATH' ) || exit;

class CBX_Queries {

	const OPT = 'cbx_custom_queries';

	public static function init() {
		foreach ( self::all() as $id => $args ) {
			add_action(
				'elementor/query/' . $id,
				function ( $query ) use ( $args ) {
					self::apply( $query, $args );
				}
			);
		}
	}

	/** @return array<string, array> */
	public static function all() {
		$all = get_option( self::OPT, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Avkodar Crocoblocks JSON. Tål det WordPress brukar göra med texten
	 * (snedstreck, HTML-entiteter, typografiska citattecken).
	 *
	 * @return array|null
	 */
	public static function decode( $json ) {
		if ( is_array( $json ) ) {
			return $json;
		}
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return null;
		}
		$tries = array( $json, wp_unslash( $json ), html_entity_decode( $json, ENT_QUOTES, 'UTF-8' ) );
		$tries[] = str_replace( array( "\u{201C}", "\u{201D}", "\u{2033}" ), '"', end( $tries ) );
		foreach ( $tries as $t ) {
			$args = json_decode( $t, true );
			if ( is_array( $args ) && $args ) {
				return $args;
			}
		}
		return null;
	}

	/**
	 * Sparar en fråga och returnerar dess Query ID. Samma argument ger samma ID.
	 */
	public static function register( array $args ) {
		$id  = self::id_for( $args );
		$all = self::all();
		if ( ! isset( $all[ $id ] ) || $all[ $id ] !== $args ) {
			$all[ $id ] = $args;
			update_option( self::OPT, $all, true );
		}
		return $id;
	}

	public static function id_for( array $args ) {
		return 'cbx_' . substr( md5( wp_json_encode( $args ) ), 0, 8 );
	}

	/**
	 * @param WP_Query $query
	 */
	public static function apply( $query, array $args ) {
		// Samma utgångsläge som Crocoblocks egen WP_Query: antal enligt sajtens
		// inställning om JSON:en inte anger något (Elementor sätter annars widgetens antal).
		if ( ! isset( $args['posts_per_page'] ) && ! isset( $args['numberposts'] ) && empty( $args['nopaging'] ) ) {
			$query->set( 'posts_per_page', (int) get_option( 'posts_per_page', 10 ) );
		}
		foreach ( $args as $key => $value ) {
			$query->set( $key, $value );
		}
		// Elementor Pro räknar själv om offset vid sidbläddring; utan offset i
		// frågan ska Elementors eget värde inte ligga kvar.
		if ( ! isset( $args['offset'] ) && isset( $args['paged'] ) ) {
			$query->set( 'offset', 0 );
		}
	}
}
