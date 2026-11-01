<?php
/**
 * Plugin Name:       Crocoblock-avveckling
 * Description:       Hittar Crocoblock-widgets, tillägg, mallar och innehållstyper och ersätter dem med Elementor / Elementor Pro – med stil, provkörning, förhandsgranskning, backup och återställning. Avvecklar även CSS Hero.
 * Version:           1.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Studio Day
 * Text Domain:       crocoblock-exit
 * License:           GPL-2.0-or-later
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBX_VERSION', '1.5.1' );
define( 'CBX_FILE', __FILE__ );
define( 'CBX_DIR', plugin_dir_path( __FILE__ ) );
define( 'CBX_URL', plugin_dir_url( __FILE__ ) );

require_once CBX_DIR . 'includes/class-cbx-mapper.php';
require_once CBX_DIR . 'includes/class-cbx-css.php';
require_once CBX_DIR . 'includes/class-cbx-plugin.php';
require_once CBX_DIR . 'includes/class-cbx-scanner.php';
require_once CBX_DIR . 'includes/class-cbx-converter.php';
require_once CBX_DIR . 'includes/class-cbx-content-types.php';
require_once CBX_DIR . 'includes/class-cbx-templates.php';
require_once CBX_DIR . 'includes/class-cbx-styles.php';
require_once CBX_DIR . 'includes/class-cbx-site-css.php';
require_once CBX_DIR . 'includes/class-cbx-preview.php';
require_once CBX_DIR . 'includes/class-cbx-queries.php';
require_once CBX_DIR . 'includes/class-cbx-autopilot.php';
require_once CBX_DIR . 'includes/class-cbx-stabilize.php';
require_once CBX_DIR . 'includes/class-cbx-csshero.php';
require_once CBX_DIR . 'includes/class-cbx-admin.php';

add_action( 'plugins_loaded', array( 'CBX_Plugin', 'boot' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CBX_DIR . 'includes/class-cbx-cli.php';
	WP_CLI::add_command( 'crocoblock-exit', 'CBX_CLI' );
}
