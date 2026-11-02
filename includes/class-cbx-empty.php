<?php
/**
 * Döljer tomma dynamiska fält – som Jet gjorde.
 *
 * Jets widgets (t.ex. jet-listing-dynamic-field och -link) dolde sig själva
 * när det dynamiska fält de visade var tomt. Elementors egna widgets gör inte
 * det: en ikonlista vars text och länk hämtas från ett tomt ACF-fält ritar
 * ändå ut ikonen, och ramen runt omkring står kvar som en tom ruta.
 *
 * Därför döljs här, vid visning på sajten (aldrig i Elementors redigerare):
 *
 * 1. Listpunkter i en ikonlista vars dynamiska text OCH länk blev tomma.
 * 2. Hela widgets (rubrik, textredigerare, ikonlista) vars dynamiska innehåll
 *    blev tomt – bara widgets med dynamisk koppling berörs, aldrig något som
 *    är tomt för att det skrevs så.
 * 3. Sektioner och kolumner som därmed blev helt tomma på widgets – så att
 *    ramar och luft runt de dolda fälten försvinner med dem.
 *
 * @package CrocoblockExit
 */

defined( 'ABSPATH' ) || exit;

class CBX_Empty {

	/** Widget-id:n som doldes under renderingen, per sidvisning. */
	protected static $hidden = array();

	public static function init() {
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'widget_content' ), 50, 2 );
		add_filter( 'elementor/frontend/the_content', array( __CLASS__, 'prune' ), 50 );
	}

	/** Aldrig i redigeraren eller dess förhandsvisning – där ska allt synas. */
	protected static function in_editor() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return false;
		}
		$el = \Elementor\Plugin::$instance;
		return ( $el->editor && $el->editor->is_edit_mode() ) || ( $el->preview && $el->preview->is_preview_mode() );
	}

	/**
	 * Filter: elementor/widget/render_content.
	 */
	public static function widget_content( $content, $widget ) {
		if ( self::in_editor() ) {
			return $content;
		}
		$type     = $widget->get_name();
		$settings = (array) $widget->get_data( 'settings' );
		if ( 'heading' === $type || 'text-editor' === $type ) {
			$key = 'heading' === $type ? 'title' : 'editor';
			if ( empty( $settings['__dynamic__'][ $key ] ) ) {
				return $content; // Inte dynamiskt – rör inte.
			}
			if ( '' === trim( wp_strip_all_tags( (string) $content ) ) && ! preg_match( '/<(img|svg|iframe|video|audio)\b/i', (string) $content ) ) {
				self::$hidden[ $widget->get_id() ] = true;
				return '';
			}
			return $content;
		}
		if ( 'icon-list' === $type ) {
			$dynamic = array();
			$any     = false;
			foreach ( isset( $settings['icon_list'] ) ? (array) $settings['icon_list'] : array() as $i => $item ) {
				$dynamic[ $i ] = ! empty( $item['__dynamic__'] );
				$any           = $any || $dynamic[ $i ];
			}
			if ( ! $any ) {
				return $content;
			}
			$out = self::strip_items( (string) $content, $dynamic );
			if ( 0 === $out['left'] ) {
				self::$hidden[ $widget->get_id() ] = true;
				return '';
			}
			return $out['removed'] > 0 ? $out['html'] : $content;
		}
		return $content;
	}

	/**
	 * Tar bort listpunkter utan text och utan länk ur en renderad ikonlista.
	 * Bara punkter med dynamisk koppling ($dynamic per index) tas bort.
	 *
	 * @return array{html: string, left: int, removed: int}
	 */
	public static function strip_items( $html, array $dynamic ) {
		$doc = self::dom_load( $html );
		if ( ! $doc ) {
			// Utan DOM-stödet: dölj bara när hela listan saknar text.
			$left = '' === trim( wp_strip_all_tags( $html ) ) ? 0 : 1;
			return array( 'html' => $html, 'left' => $left, 'removed' => 0 );
		}
		$xp      = new DOMXPath( $doc );
		$items   = $xp->query( "//li[contains(concat(' ', normalize-space(@class), ' '), ' elementor-icon-list-item ')]" );
		$removed = 0;
		$left    = 0;
		$i       = -1;
		foreach ( iterator_to_array( $items ) as $li ) {
			$i++;
			$text = '';
			foreach ( $xp->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-icon-list-text ')]", $li ) as $span ) {
				$text .= $span->textContent;
			}
			$href = '';
			foreach ( $xp->query( './/a[@href]', $li ) as $a ) {
				$href = trim( (string) $a->getAttribute( 'href' ), " \t\n#" );
				if ( '' !== $href ) {
					break;
				}
			}
			if ( '' === trim( $text ) && '' === $href && ! empty( $dynamic[ $i ] ) ) {
				$li->parentNode->removeChild( $li );
				$removed++;
			} else {
				$left++;
			}
		}
		return array( 'html' => self::dom_save( $doc ), 'left' => $left, 'removed' => $removed );
	}

	/**
	 * Filter: elementor/frontend/the_content.
	 * Tar bort de dolda widgetarnas omslag, och sektioner/kolumner som inte
	 * har några widgets kvar.
	 */
	public static function prune( $html ) {
		if ( empty( self::$hidden ) || self::in_editor() ) {
			return $html;
		}
		return self::prune_html( (string) $html, array_keys( self::$hidden ) );
	}

	/**
	 * @param string[] $ids Widget-id:n vars omslag ska bort.
	 */
	public static function prune_html( $html, array $ids ) {
		if ( '' === trim( $html ) || empty( $ids ) ) {
			return $html;
		}
		$doc = self::dom_load( $html );
		if ( ! $doc ) {
			return $html;
		}
		$xp = new DOMXPath( $doc );
		foreach ( $ids as $id ) {
			if ( ! preg_match( '/^[0-9a-z]+$/i', (string) $id ) ) {
				continue;
			}
			$nodes = $xp->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-element-" . $id . " ')]" );
			foreach ( iterator_to_array( $nodes ) as $node ) {
				$parent = $node->parentNode;
				$node->parentNode->removeChild( $node );
				// Uppåt: ta bort sektioner/kolumner som blev utan widgets.
				// Mellanliggande omslag (t.ex. .elementor-widget-wrap) tas
				// inte bort själva men klättras förbi, så att även kolumnen
				// och sektionen runt dem prövas.
				while ( $parent && XML_ELEMENT_NODE === $parent->nodeType && 'cbx-dom-root' !== $parent->getAttribute( 'id' ) ) {
					$widgets = $xp->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-widget ')]", $parent );
					if ( $widgets->length > 0 ) {
						break;
					}
					$cls  = ' ' . preg_replace( '/\s+/', ' ', trim( (string) $parent->getAttribute( 'class' ) ) ) . ' ';
					$next = $parent->parentNode;
					if ( false !== strpos( $cls, ' elementor-element ' ) ) {
						$next->removeChild( $parent );
					}
					$parent = $next;
				}
			}
		}
		return self::dom_save( $doc );
	}

	/* ---------- DOM-hjälpare ---------- */

	/** @return DOMDocument|null */
	protected static function dom_load( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="utf-8"?><div id="cbx-dom-root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $doc : null;
	}

	protected static function dom_save( DOMDocument $doc ) {
		$root = $doc->getElementById( 'cbx-dom-root' );
		if ( ! $root ) {
			return '';
		}
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		return $out;
	}
}
