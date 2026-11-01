<?php
/**
 * Uppdateringar från GitHub.
 *
 * Tillägget har "Update URI: https://github.com/daystudioab/crocoblock-exit" i
 * sitt huvud. Då frågar WordPress inte wordpress.org utan filtret
 * update_plugins_github.com, och här svarar vi med den senaste versionen på
 * GitHub (Releases). Nya versioner syns som vanliga uppdateringar under
 * Tillägg, med "Uppdatera nu" och automatiska uppdateringar.
 *
 * GitHub skapar en version (med färdig zip) först när alla tester har gått
 * igenom – se .github/workflows/tests.yml.
 *
 * @package CrocoblockExit
 */

defined( 'ABSPATH' ) || exit;

class CBX_Updater {

	const REPO      = 'daystudioab/crocoblock-exit';
	const SLUG      = 'crocoblock-exit';
	const CACHE     = 'cbx_github_release';
	const ASSET     = 'crocoblock-exit.zip';
	const CACHE_TTL = 21600; // 6 h

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	public static function basename() {
		return plugin_basename( CBX_FILE );
	}

	/**
	 * Senaste versionen på GitHub (cachad).
	 *
	 * @return array{version: string, package: string, url: string, notes: string, date: string}|null
	 */
	public static function latest( $force = false ) {
		if ( ! $force && ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore
			$force = true; // "Kontrollera igen" under Uppdateringar.
		}
		$cached = $force ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'crocoblock-exit/' . CBX_VERSION,
				),
			)
		);
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) && ! empty( $json['tag_name'] ) && empty( $json['draft'] ) && empty( $json['prerelease'] ) ) {
				$package = '';
				foreach ( isset( $json['assets'] ) ? (array) $json['assets'] : array() as $asset ) {
					if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
						$package = $asset['browser_download_url'];
					}
				}
				if ( $package ) {
					$data = array(
						'version' => ltrim( (string) $json['tag_name'], 'vV' ),
						'package' => $package,
						'url'     => isset( $json['html_url'] ) ? $json['html_url'] : 'https://github.com/' . self::REPO,
						'notes'   => isset( $json['body'] ) ? (string) $json['body'] : '',
						'date'    => isset( $json['published_at'] ) ? (string) $json['published_at'] : '',
					);
				}
			}
		}
		// Misslyckade frågor cachas kortare, så att GitHub inte frågas vid varje sidvisning.
		set_transient( self::CACHE, $data ? $data : array( 'version' => '' ), $data ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Svar till WordPress uppdateringskontroll (update_plugins_github.com).
	 */
	public static function check( $update, $plugin_data, $plugin_file, $locales = array() ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$latest = self::latest();
		if ( ! $latest ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $latest['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => version_compare( $latest['version'], CBX_VERSION, '>' ) ? $latest['package'] : '',
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
	}

	/**
	 * "Visa detaljer" i uppdateringsrutan.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$latest = self::latest();
		$notes  = $latest ? $latest['notes'] : '';
		return (object) array(
			'name'          => 'Crocoblock-avveckling',
			'slug'          => self::SLUG,
			'version'       => $latest ? $latest['version'] : CBX_VERSION,
			'author'        => 'Day Studio',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $latest ? $latest['date'] : '',
			'download_link' => $latest ? $latest['package'] : '',
			'sections'      => array(
				'description' => '<p>Avvecklar Crocoblock (Jet-tilläggen) och CSS Hero på Elementor-sajter: Testa → Genomför → Stäng av, med automatisk visuell kontroll och återställning.</p>',
				'changelog'   => '' !== trim( $notes ) ? wpautop( esc_html( $notes ) ) : '<p>Se <a href="https://github.com/' . self::REPO . '/releases">GitHub</a>.</p>',
			),
		);
	}

	/**
	 * Säkerställer att tillägget packas upp i mappen crocoblock-exit, även om
	 * zip-filen skulle ha ett annat mappnamn.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $want || ! is_dir( $source ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return $source;
	}

	public static function forget() {
		delete_transient( self::CACHE );
	}
}
