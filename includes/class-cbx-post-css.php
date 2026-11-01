<?php
/**
 * Tunn subklass som låter oss be Elementor generera en posts CSS i minnet,
 * utan att skriva filen. Laddas bara när Elementor finns.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) && ! class_exists( 'CBX_Post_CSS' ) ) {
	class CBX_Post_CSS extends \Elementor\Core\Files\CSS\Post {
		public function cbx_generate() {
			return $this->parse_content();
		}
	}
}
