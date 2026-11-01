<?php
/**
 * Konverteringskärnan: översätter Crocoblock-widgets, tillägg och dynamiska
 * taggar i Elementor-data till Elementor / Elementor Pro.
 *
 * Klassen är medvetet fri från WordPress-anrop. Allt som kräver databasen
 * (bilagor, menyer, termer, migrerade mallar) kommer in via $ctx-callbacks,
 * så att kärnan kan testas fristående.
 *
 * Inställningsnamn och standardvärden är hämtade ur Crocoblocks egen källkod.
 * Viktigt: Elementor sparar bara värden som ändrats från standard, så en
 * saknad nyckel betyder "standardvärdet" och inte "tomt".
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'CBX_TESTING' ) ) {
	exit;
}

class CBX_Mapper {

	/** @var array */
	protected $ctx;

	/** @var array Logg för aktuellt dokument. */
	protected $log = array();

	/** @var string Dokumentkontext: page|listing|popup|theme */
	protected $doc_context = 'page';

	/**
	 * @param array $ctx {
	 *   @type bool     $has_pro        Elementor Pro aktivt.
	 *   @type bool     $clean_ext      Ta bort Crocoblock-tillägg på sektioner/kolumner/widgets.
	 *   @type callable $attachment_url fn( int $id ): string
	 *   @type callable $term_tt_id     fn( int $term_id ): int  (Pro-frågor använder term_taxonomy_id)
	 *   @type callable $menu_slug      fn( int|string $menu ): string
	 *   @type callable $listing_map    fn( int $jet_listing_id ): ?array{id:int,post_type:string}
	 *   @type callable $popup_map      fn( int $jet_popup_id ): ?int
	 *   @type callable $new_id         fn(): string
	 *   @type callable $register_query fn( array $wp_query_args ): string  Query ID för Elementor Pro
	 *   @type int      $per_page       Sajtens "Blogginlägg per sida" (WP_Querys standard)
	 * }
	 */
	public function __construct( array $ctx = array() ) {
		$defaults  = array(
			'has_pro'        => false,
			'clean_ext'      => true,
			'attachment_url' => function ( $id ) { return ''; },
			'term_tt_id'     => function ( $id ) { return (int) $id; },
			'menu_slug'      => function ( $m ) { return (string) $m; },
			'listing_map'    => function ( $id ) { return null; },
			'popup_map'      => function ( $id ) { return null; },
			'new_id'         => function () { return substr( md5( uniqid( '', true ) . mt_rand() ), 0, 7 ); },
			'register_query' => function ( array $args ) { return 'cbx_' . substr( md5( json_encode( $args ) ), 0, 8 ); },
			'per_page'       => 10,
		);
		$this->ctx = array_merge( $defaults, $ctx );
	}

	/* ------------------------------------------------------------------ *
	 * Publikt API
	 * ------------------------------------------------------------------ */

	/**
	 * Är detta en Crocoblock-widget? Alla Crocoblocks widgets heter jet-* eller jet_*.
	 */
	public static function is_jet_type( $type ) {
		return is_string( $type ) && ( 0 === strpos( $type, 'jet-' ) || 0 === strpos( $type, 'jet_' ) );
	}

	/**
	 * Konverterar ett helt Elementor-dokument (toppnivåns element-array).
	 *
	 * @return array{0: array, 1: array} [nya element, logg]
	 */
	public function convert_document( array $elements, $context = 'page' ) {
		$this->log         = array();
		$this->doc_context = $context;
		$out               = $this->convert_elements( $elements );
		return array( $out, $this->log );
	}

	/**
	 * Sammanfattning av en logg: antal per status.
	 */
	public static function summarize( array $log ) {
		$sum = array( 'converted' => 0, 'manual' => 0, 'cleaned' => 0, 'dynamic' => 0 );
		foreach ( $log as $row ) {
			if ( isset( $sum[ $row['status'] ] ) ) {
				$sum[ $row['status'] ]++;
			}
		}
		return $sum;
	}

	/**
	 * Ändrar loggen något i dokumentet?
	 */
	public static function log_has_changes( array $log ) {
		foreach ( $log as $row ) {
			if ( 'manual' !== $row['status'] ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Trädvandring
	 * ------------------------------------------------------------------ */

	protected function convert_elements( array $elements ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}
			$out[] = $this->convert_element( $el );
		}
		return $out;
	}

	protected function convert_element( array $el ) {
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$el['elements'] = $this->convert_elements( $el['elements'] );
		}

		$settings = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : array();
		$id       = isset( $el['id'] ) ? (string) $el['id'] : '';
		$el_label = isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : 'element' );

		// 1. Widgets från Crocoblock.
		if ( isset( $el['elType'] ) && 'widget' === $el['elType'] && self::is_jet_type( isset( $el['widgetType'] ) ? $el['widgetType'] : '' ) ) {
			$type   = $el['widgetType'];
			$result = $this->convert_widget( $type, $settings );

			if ( isset( $result['manual'] ) ) {
				$this->add_log( $id, $type, '', 'manual', array( $result['manual'] ) );
			} else {
				$common       = $this->common_settings( $settings );
				$new_settings = array_merge( $common, $result['settings'] );
				foreach ( array( '__dynamic__', '__globals__' ) as $meta ) {
					if ( ! empty( $common[ $meta ] ) || ! empty( $result['settings'][ $meta ] ) ) {
						$new_settings[ $meta ] = array_merge(
							isset( $common[ $meta ] ) ? (array) $common[ $meta ] : array(),
							isset( $result['settings'][ $meta ] ) ? (array) $result['settings'][ $meta ] : array()
						);
					}
				}
				$classes                      = isset( $new_settings['_css_classes'] ) ? trim( (string) $new_settings['_css_classes'] ) : '';
				$new_settings['_css_classes'] = trim( $classes . ' cbx-jet cbx-from-' . $type );
				$el['widgetType'] = $result['widgetType'];
				$el['settings']   = $new_settings;
				$el['elements']   = array();
				$settings         = $new_settings;
				$el_label         = $result['widgetType'];
				$this->add_log( $id, $type, $result['widgetType'], 'converted', isset( $result['notes'] ) ? $result['notes'] : array() );
			}
		}

		// 2. Crocoblock-tillägg på valfritt element (sticky, parallax, popup-koppling m.m.).
		if ( $this->ctx['clean_ext'] ) {
			list( $settings, $notes, $changed ) = $this->clean_extensions( $settings, $el );
			if ( $changed ) {
				$el['settings'] = $settings;
				$this->add_log( $id, $el_label, '', 'cleaned', $notes );
			}
		}

		// 3. Dynamiska taggar från JetEngine.
		list( $settings, $notes, $changed ) = $this->convert_dynamic_tags( $settings );
		if ( $changed ) {
			$el['settings'] = $settings;
			$this->add_log( $id, $el_label, '', 'dynamic', $notes );
		}

		return $el;
	}

	protected function add_log( $id, $from, $to, $status, array $notes = array() ) {
		$this->log[] = array(
			'id'     => $id,
			'from'   => $from,
			'to'     => $to,
			'status' => $status,
			'notes'  => array_values( array_filter( $notes ) ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Hjälpare
	 * ------------------------------------------------------------------ */

	/** Värde eller standardvärde (tom sträng räknas som "inte satt"). */
	protected function v( array $s, $key, $default = '' ) {
		if ( ! array_key_exists( $key, $s ) || null === $s[ $key ] || '' === $s[ $key ] ) {
			return $default;
		}
		return $s[ $key ];
	}

	/** Switch-värde. Crocoblock använder både 'yes' och 'true'. */
	protected function on( array $s, $key, $default = false ) {
		if ( ! array_key_exists( $key, $s ) ) {
			return (bool) $default;
		}
		$val = $s[ $key ];
		return in_array( $val, array( 'yes', 'true', '1', 1, true, 'on' ), true );
	}

	protected function yn( $bool ) {
		return $bool ? 'yes' : '';
	}

	/** Slider/number → tal. */
	protected function num( $val, $default = 0 ) {
		if ( is_array( $val ) ) {
			$val = isset( $val['size'] ) ? $val['size'] : $default;
		}
		return ( '' === $val || null === $val ) ? $default : ( is_numeric( $val ) ? $val + 0 : $default );
	}

	/** Lista av ID:n ur array eller kommaseparerad text. */
	protected function ids( $val ) {
		if ( is_array( $val ) ) {
			$list = $val;
		} else {
			$list = preg_split( '/[\s,]+/', (string) $val );
		}
		$out = array();
		foreach ( $list as $item ) {
			if ( is_numeric( $item ) && (int) $item > 0 ) {
				$out[] = (int) $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** URL-kontroll. Accepterar Elementors url-array eller en sträng. */
	protected function url( $val ) {
		if ( is_array( $val ) ) {
			return array(
				'url'         => isset( $val['url'] ) ? (string) $val['url'] : '',
				'is_external' => ! empty( $val['is_external'] ) ? 'on' : '',
				'nofollow'    => ! empty( $val['nofollow'] ) ? 'on' : '',
			);
		}
		return array( 'url' => (string) $val, 'is_external' => '', 'nofollow' => '' );
	}

	protected function has_url( $val ) {
		$u = $this->url( $val );
		return '' !== trim( $u['url'] ) && '#' !== trim( $u['url'] );
	}

	/** Media-kontroll. */
	protected function media( $val ) {
		if ( is_array( $val ) && ( ! empty( $val['url'] ) || ! empty( $val['id'] ) ) ) {
			return array(
				'id'  => isset( $val['id'] ) ? (int) $val['id'] : '',
				'url' => isset( $val['url'] ) ? (string) $val['url'] : '',
			);
		}
		return null;
	}

	/** Ikon (nytt array-format eller äldre sträng). */
	protected function icon( array $s, $name ) {
		if ( ! empty( $s[ 'selected_' . $name ] ) && is_array( $s[ 'selected_' . $name ] ) ) {
			return $s[ 'selected_' . $name ];
		}
		if ( ! empty( $s[ $name ] ) && is_array( $s[ $name ] ) && isset( $s[ $name ]['value'] ) ) {
			return $s[ $name ];
		}
		if ( ! empty( $s[ $name ] ) && is_string( $s[ $name ] ) ) {
			$lib = ( false !== strpos( $s[ $name ], 'fab ' ) ) ? 'fa-brands' : 'fa-solid';
			return array( 'value' => $s[ $name ], 'library' => $lib );
		}
		return null;
	}

	protected function rid() {
		return call_user_func( $this->ctx['new_id'] );
	}

	protected function e( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	protected function tag_text( $name, array $settings = array() ) {
		return sprintf(
			'[elementor-tag id="%1$s" name="%2$s" settings="%3$s"]',
			$this->rid(),
			$name,
			urlencode( json_encode( (object) $settings ) )
		);
	}

	/**
	 * Gemensamma avancerade inställningar som alla widgets delar
	 * (marginal, padding, CSS-ID/klasser, responsiv döljning, rörelseeffekter).
	 */
	protected function common_settings( array $s ) {
		$out = array();
		foreach ( $s as $key => $val ) {
			if ( '__dynamic__' === $key || '__globals__' === $key ) {
				continue;
			}
			if ( preg_match( '/^(_(?!_)|hide_(desktop|tablet|mobile|laptop|widescreen|mobile_extra|tablet_extra)$|motion_fx_|custom_css$|custom_attributes$)/', $key ) ) {
				$out[ $key ] = $val;
			}
		}
		foreach ( array( '__globals__', '__dynamic__' ) as $meta ) {
			if ( ! empty( $s[ $meta ] ) && is_array( $s[ $meta ] ) ) {
				foreach ( $s[ $meta ] as $key => $val ) {
					if ( isset( $out[ $key ] ) || preg_match( '/^_(?!_)/', $key ) ) {
						$out[ $meta ][ $key ] = $val;
					}
				}
			}
		}
		return $out;
	}

	/** Innehåll för flikar/dragspel: Elementor-mall eller fri text. */
	protected function tab_content( $type, $template_id, $editor, array &$notes ) {
		if ( 'template' === $type ) {
			if ( ! empty( $template_id ) ) {
				if ( $this->ctx['has_pro'] ) {
					return '[elementor-template id="' . (int) $template_id . '"]';
				}
				$notes[] = sprintf( 'Fliken visade mall #%d. Utan Elementor Pro finns ingen mallkortkod – klistra in innehållet manuellt.', (int) $template_id );
				return '';
			}
			// Mall vald men ingen satt: Crocoblock visade då ingenting.
			return '';
		}
		return (string) $editor;
	}

	protected function need_pro( $what ) {
		return array( 'manual' => sprintf( 'Ersättningen (%s) kräver Elementor Pro, som inte är aktivt på sajten.', $what ) );
	}

	/* ------------------------------------------------------------------ *
	 * Widget-konvertering
	 * ------------------------------------------------------------------ */

	protected function convert_widget( $type, array $s ) {
		$method = 'w_' . str_replace( '-', '_', $type );
		if ( method_exists( $this, $method ) ) {
			return $this->$method( $s );
		}
		return array( 'manual' => self::manual_hint( $type ) );
	}

	/**
	 * Råd för widgets som inte kan konverteras automatiskt.
	 */
	public static function manual_hint( $type ) {
		$hints = array(
			'jet-smart-filters'    => 'JetSmartFilters-filter. Ersätt med Elementor Pros "Taxonomy Filter" (för Loop Grid) eller ett filtertillägg.',
			'jet-woo'              => 'JetWooBuilder. Ersätt med Elementor Pros WooCommerce-widgets eller WooCommerce-mallar i Theme Builder.',
			'jet-single-'          => 'JetWooBuilder (produktsida). Bygg om med Elementor Pros "Single Product"-mall.',
			'jet-cart-'            => 'JetWooBuilder (varukorg). Använd Elementor Pros "Cart"-widget.',
			'jet-checkout-'        => 'JetWooBuilder (kassa). Använd Elementor Pros "Checkout"-widget.',
			'jet-myaccount-'       => 'JetWooBuilder (mitt konto). Använd Elementor Pros "My Account"-widget.',
			'jet-thankyou-'        => 'JetWooBuilder (tack-sida). Använd WooCommerces standardsida.',
			'jet-compare'          => 'JetCompare & Wishlist. Ersätt med ett separat jämförelse-/önskelistetillägg.',
			'jet-wishlist'         => 'JetCompare & Wishlist. Ersätt med ett separat önskelistetillägg.',
			'jet-reviews'          => 'JetReviews. Ersätt med WooCommerce-recensioner eller ett recensionstillägg.',
			'jet-booking'          => 'JetBooking. Kräver ett bokningstillägg som ersättning.',
			'jet-booked'           => 'Booked-integration. Kräver ett bokningstillägg som ersättning.',
			'jet-appointments'     => 'JetAppointments. Kräver ett bokningstillägg som ersättning.',
			'jet-engine-booking-form' => 'JetEngine-formulär. Bygg om med Elementor Pros "Form".',
			'jet-engine-maps-listing' => 'JetEngine-karta. Använd Elementor "Google Maps" eller ett karttillägg.',
			'jet-engine-'          => 'JetEngine-funktion utan direkt motsvarighet. Bygg om manuellt.',
			'jet-listing-dynamic-repeater' => 'JetEngine-repeaterfält. Bygg om med Loop Grid eller en HTML-widget.',
			'jet-listing-calendar' => 'JetEngine-kalender. Ersätt med ett kalendertillägg.',
			'jet-bar-chart'        => 'Diagram. Ersätt med en bild eller ett diagramtillägg.',
			'jet-line-chart'       => 'Diagram. Ersätt med en bild eller ett diagramtillägg.',
			'jet-pie-chart'        => 'Diagram. Ersätt med en bild eller ett diagramtillägg.',
			'jet-weather'          => 'Väder-widget. Ta bort eller ersätt med ett vädertillägg.',
			'jet-instagram-gallery'=> 'Instagram-flöde. Ersätt med ett Instagram-tillägg (t.ex. Smash Balloon).',
			'jet-scroll-navigation'=> 'Scrollnavigering. Använd ankarlänkar (Menu Anchor) + meny.',
			'jet-dropbar'          => 'Dropbar. Bygg om med Elementor Pros popup eller dragspel.',
			'jet-subscribe-form'   => 'Prenumerationsformulär. Bygg om med Elementor Pros "Form" + Mailchimp-åtgärd.',
			'jet-popup-mailchimp'  => 'Mailchimp-formulär i popup. Bygg om med Elementor Pros "Form".',
			'jet-image-comparison' => 'Bildjämförelse. Saknar motsvarighet i Elementor – använd ett litet separat tillägg eller ta bort.',
			'jet-lottie'           => 'Lottie. Lägg in med Elementor Pros "Lottie"-widget (ladda upp JSON-filen igen).',
			'jet-hotspots'         => 'Hotspots. Bygg om med Elementor Pros "Hotspot"-widget.',
			'jet-horizontal-timeline' => 'Horisontell tidslinje. Bygg om med kolumner eller containrar.',
			'jet-hamburger-panel'  => 'Hamburgerpanel. Bygg om med Elementor Pros popup + knapp, eller "Nav Menu" i mobilläge.',
			'jet-breadcrumbs'      => 'Brödsmulor. Elementor Pros "Breadcrumbs" kräver Yoast/Rank Math – annars kortkod från SEO-tillägget.',
			'jet-register'         => 'Registreringsformulär. Bygg om med Elementor Pros "Form" eller WordPress standardregistrering.',
			'jet-reset'            => 'Lösenordsåterställning. Använd WordPress standardflöde (wp-login.php?action=lostpassword).',
			'jet-auth-links'       => 'Inloggningslänkar. Ersätt med knappar eller Elementor Pros "Login".',
			'jet-blog-text-ticker' => 'Nyhetsticker. Ersätt med en enkel "Posts"-lista eller ta bort.',
			'jet-blog-video-playlist' => 'Videospellista. Använd Elementor Pros "Video Playlist".',
			'jet-blog-posts-pagination' => 'Sidnumrering. Använd paginering i Posts/Loop Grid.',
			'jet-view-more'        => '"Visa mer"-knapp för sektioner. Bygg om med dragspel eller "Toggle".',
			'jet-image-accordion'  => 'Bilddragspel. Konverteras inte – bygg om med Image Box i kolumner.',
			'jet-video-playlist'   => 'Videospellista. Använd Elementor Pros "Video Playlist".',
		);
		foreach ( $hints as $prefix => $hint ) {
			if ( 0 === strpos( $type, $prefix ) ) {
				return $hint;
			}
		}
		return 'Ingen automatisk motsvarighet. Bygg om manuellt med en Elementor-widget.';
	}

	/* ---------- JetElements ---------- */

	protected function w_jet_posts( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Posts' );
		}
		$notes = array();
		$out   = array(
			'_skin'                        => 'classic',
			'classic_columns'              => (string) $this->v( $s, 'columns', 3 ),
			'classic_posts_per_page'       => (int) $this->v( $s, 'number', 3 ),
			'classic_thumbnail'            => $this->on( $s, 'show_image', true ) ? 'top' : 'none',
			'classic_show_title'           => $this->yn( $this->on( $s, 'show_title', true ) ),
			'classic_show_excerpt'         => $this->yn( $this->on( $s, 'show_excerpt', true ) ),
			'classic_excerpt_length'       => (int) $this->v( $s, 'excerpt_length', 20 ),
			'classic_show_read_more'       => $this->yn( $this->on( $s, 'show_more', true ) ),
			'classic_read_more_text'       => (string) $this->v( $s, 'more_text', 'Läs mer' ),
			'classic_title_tag'            => (string) $this->v( $s, 'title_html_tag', 'h4' ),
		);
		$out   = array_merge( $out, $this->responsive_columns( $s, 'columns', 3 ) );
		$thumb = $this->v( $s, 'thumb_size', 'post-thumbnail' );
		$out['classic_thumbnail_size_size'] = ( 'post-thumbnail' === $thumb ) ? 'medium_large' : $thumb;
		$out = array_merge( $out, $this->jet_column_gaps( $s ) );

		$meta = array();
		if ( $this->on( $s, 'show_meta', true ) ) {
			if ( $this->on( $s, 'show_author', true ) ) {
				$meta[] = 'author';
			}
			if ( $this->on( $s, 'show_date', true ) ) {
				$meta[] = 'date';
			}
			if ( $this->on( $s, 'show_comments', true ) ) {
				$meta[] = 'comments';
			}
		}
		$out['classic_meta_data'] = $meta;

		$out = array_merge( $out, $this->posts_query(
			$this->v( $s, 'post_type', 'post' ),
			$this->v( $s, 'posts_query', 'latest' ),
			$this->v( $s, 'post_ids' ),
			$this->v( $s, 'post_cat' ),
			array(),
			(int) $this->v( $s, 'post_offset', 0 ),
			$notes
		) );

		if ( $this->on( $s, 'is_archive_template' ) ) {
			$out['posts_post_type'] = 'current_query';
		}
		$out = $this->custom_query( $s, $out, $notes );
		if ( $this->on( $s, 'carousel_enabled' ) ) {
			$notes[] = 'Var en karusell – visas nu som rutnät. Använd Loop Carousel i Elementor Pro om karusell behövs.';
		}
		return array( 'widgetType' => 'posts', 'settings' => $out, 'notes' => $notes );
	}

	/**
	 * Kolumner per skärmstorlek. Crocoblock låter surfplatta och mobil ärva
	 * datorns antal när de inte är satta, men Elementor Pros Posts har egna
	 * standardvärden (2 på surfplatta, 1 på mobil) – därför sätts alla tre.
	 */
	protected function responsive_columns( array $s, $key, $default ) {
		$desk   = (string) max( 1, (int) $this->v( $s, $key, $default ) );
		$tablet = '' !== $this->v( $s, $key . '_tablet' ) ? (string) (int) $s[ $key . '_tablet' ] : $desk;
		$mobile = '' !== $this->v( $s, $key . '_mobile' ) ? (string) (int) $s[ $key . '_mobile' ] : $tablet;
		return array(
			'classic_columns'        => $desk,
			'classic_columns_tablet' => $tablet,
			'classic_columns_mobile' => $mobile,
		);
	}

	/**
	 * Avstånd i JetElements Posts: varje inlägg har "Column Padding" (standard
	 * 10px runt om, från jet-posts.css) och listan har negativ marginal i sidled.
	 * Avståndet mellan inläggen blir alltså topp+botten resp. vänster+höger,
	 * och listan får topp-/bottenpaddingen som luft ovanför och under sig.
	 * Elementor Pro har i stället rad-/kolumnavstånd (standard 35px/30px).
	 */
	protected function jet_column_gaps( array $s ) {
		$out = array();
		$dev = array( '' => '', '_tablet' => '_tablet', '_mobile' => '_mobile' );
		$pad = array( 'top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10 );
		$desk = $pad;
		foreach ( $dev as $suffix ) {
			$val = $this->v( $s, 'column_padding' . $suffix );
			if ( is_array( $val ) && ( ! isset( $val['unit'] ) || 'px' === $val['unit'] ) ) {
				foreach ( $pad as $side => $n ) {
					if ( isset( $val[ $side ] ) && '' !== $val[ $side ] ) {
						$pad[ $side ] = (float) $val[ $side ];
					}
				}
			} elseif ( '' !== $suffix ) {
				continue; // Ärver från större skärm – Elementor gör likadant.
			}
			$out[ 'classic_row_gap' . $suffix ]    = array( 'unit' => 'px', 'size' => $pad['top'] + $pad['bottom'], 'sizes' => array() );
			$out[ 'classic_column_gap' . $suffix ] = array( 'unit' => 'px', 'size' => $pad['left'] + $pad['right'], 'sizes' => array() );
			if ( '' === $suffix ) {
				$desk = $pad;
			}
		}
		// Luften ovanför och under listan läggs på widgetens egen padding.
		if ( $desk['top'] || $desk['bottom'] ) {
			$own = $this->v( $s, '_padding' );
			$own = is_array( $own ) ? $own : array();
			if ( ! isset( $own['unit'] ) || 'px' === $own['unit'] || ( '' === (string) ( $own['top'] ?? '' ) && '' === (string) ( $own['bottom'] ?? '' ) ) ) {
				$out['_padding'] = array(
					'unit'     => 'px',
					'top'      => (string) ( (float) ( $own['top'] ?? 0 ) + $desk['top'] ),
					// Elementor skriver ingen CSS alls om någon sida är tom – fyll med 0.
					'right'    => (string) ( (float) ( $own['right'] ?? 0 ) ),
					'bottom'   => (string) ( (float) ( $own['bottom'] ?? 0 ) + $desk['bottom'] ),
					'left'     => (string) ( (float) ( $own['left'] ?? 0 ) ),
					'isLinked' => false,
				);
			}
		}
		return $out;
	}

	/**
	 * Gemensam frågelogik för Elementor Pros Posts-widget (gruppkontroll "posts").
	 */
	protected function posts_query( $post_type, $mode, $post_ids, $cat_ids, $tag_ids, $offset, array &$notes ) {
		$q = array( 'posts_post_type' => $post_type ? $post_type : 'post' );

		if ( 'ids' === $mode ) {
			$q['posts_post_type'] = 'by_id';
			$q['posts_posts_ids'] = $this->ids( $post_ids );
		} elseif ( in_array( $mode, array( 'category', 'post_tag' ), true ) ) {
			$terms = $this->ids( 'post_tag' === $mode ? $tag_ids : $cat_ids );
			if ( $terms ) {
				$q['posts_include']          = array( 'terms' );
				$q['posts_include_term_ids'] = array_map(
					function ( $t ) {
						return (int) call_user_func( $this->ctx['term_tt_id'], $t );
					},
					$terms
				);
			}
		} elseif ( 'related' === $mode ) {
			$q['posts_post_type'] = 'related';
			$notes[] = '"Relaterade inlägg" – kontrollera att relationen (taxonomi) blev rätt.';
		}
		if ( $offset > 0 ) {
			$q['posts_offset'] = $offset;
		}
		return $q;
	}

	protected function w_jet_blog_smart_listing( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Posts' );
		}
		$notes    = array( 'Smart Listing har en särskild layout (utvald artikel + lista). Den blir ett enkelt rutnät – kontrollera utseendet.' );
		$cols     = (int) $this->v( $s, 'posts_columns', 1 );
		$rows     = (int) $this->v( $s, 'posts_rows', 3 );
		$featured = $this->on( $s, 'featured_post', true ) ? 1 : 0;
		$query_by = $this->v( $s, 'query_by', 'category' );
		$mode     = array( 'category' => 'category', 'post_tag' => 'post_tag', 'ids' => 'ids' );
		$out      = array(
			'_skin'                  => 'classic',
			'classic_columns'        => (string) max( 1, $cols ),
			'classic_posts_per_page' => max( 1, $cols * $rows ) + $featured,
			'classic_thumbnail'      => $this->on( $s, 'show_image', true ) ? 'left' : 'none',
			'classic_show_title'     => 'yes',
			'classic_show_excerpt'   => $this->yn( (int) $this->v( $s, 'excerpt_length', 5 ) > 0 ),
			'classic_excerpt_length' => (int) $this->v( $s, 'excerpt_length', 5 ),
			'classic_show_read_more' => $this->yn( $this->on( $s, 'read_more', false ) ),
			'classic_read_more_text' => (string) $this->v( $s, 'read_more_text', 'Läs mer' ),
		);
		$out  = array_merge( $out, $this->responsive_columns( $s, 'posts_columns', 1 ) );
		$meta = array();
		if ( $this->on( $s, 'show_meta', true ) ) {
			if ( $this->on( $s, 'show_author', false ) ) {
				$meta[] = 'author';
			}
			if ( $this->on( $s, 'show_date', true ) ) {
				$meta[] = 'date';
			}
			if ( $this->on( $s, 'show_comments', false ) ) {
				$meta[] = 'comments';
			}
		}
		$out['classic_meta_data'] = $meta;
		$out = array_merge( $out, $this->posts_query(
			$this->v( $s, 'post_type', 'post' ),
			isset( $mode[ $query_by ] ) ? $mode[ $query_by ] : 'latest',
			$this->v( $s, 'post_ids' ),
			$this->v( $s, 'category_ids' ),
			$this->v( $s, 'post_tag_ids' ),
			(int) $this->v( $s, 'posts_offset', 0 ),
			$notes
		) );
		if ( $this->on( $s, 'is_archive_template' ) ) {
			$out['posts_post_type'] = 'current_query';
		}
		if ( $this->on( $s, 'show_filter' ) ) {
			$notes[] = 'Kategorifiltret ovanför listan följer inte med.';
		}
		$out = $this->custom_query( $s, $out, $notes );
		return array( 'widgetType' => 'posts', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_blog_smart_tiles( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Posts' );
		}
		$notes    = array( 'Smart Tiles (bildplattor) blir ett rutnät med bild överst. Kontrollera antal och utseende.' );
		$query_by = $this->v( $s, 'query_by', 'category' );
		$mode     = array( 'category' => 'category', 'post_tag' => 'post_tag', 'ids' => 'ids' );
		$out      = array(
			'_skin'                  => 'classic',
			'classic_columns'        => '3',
			'classic_posts_per_page' => 3 * max( 1, (int) $this->v( $s, 'rows_num', 1 ) ),
			'classic_thumbnail'      => 'top',
			'classic_show_title'     => 'yes',
			'classic_show_excerpt'   => $this->yn( (int) $this->v( $s, 'excerpt_length', 0 ) > 0 ),
			'classic_excerpt_length' => (int) $this->v( $s, 'excerpt_length', 0 ),
			'classic_show_read_more' => '',
			'classic_meta_data'      => $this->on( $s, 'show_meta', true ) ? array( 'date' ) : array(),
		);
		$out = array_merge( $out, $this->posts_query(
			$this->v( $s, 'post_type', 'post' ),
			isset( $mode[ $query_by ] ) ? $mode[ $query_by ] : 'latest',
			$this->v( $s, 'post_ids' ),
			$this->v( $s, 'category_ids' ),
			$this->v( $s, 'post_tag_ids' ),
			(int) $this->v( $s, 'posts_offset', 0 ),
			$notes
		) );
		$out = $this->custom_query( $s, $out, $notes );
		return array( 'widgetType' => 'posts', 'settings' => $out, 'notes' => $notes );
	}

	/**
	 * "Egen fråga": Crocoblock-widgeten körde en WP_Query från JSON. Den förs
	 * över som Elementor Pros Query ID, som det här tillägget kopplar till
	 * exakt samma argument (se CBX_Queries).
	 */
	protected function custom_query( array $s, array $out, array &$notes ) {
		if ( ! $this->on( $s, 'use_custom_query' ) ) {
			return $out;
		}
		$args = CBX_Mapper::decode_query( $this->v( $s, 'custom_query', '' ) );
		if ( ! $args ) {
			$notes[] = 'Widgeten använde en egen WP_Query (JSON) som inte gick att läsa. Ställ in frågan manuellt.';
			return $out;
		}
		// Frågan styr urvalet helt – rensa det som räknats fram ur widgetens vanliga fält.
		foreach ( array_keys( $out ) as $k ) {
			if ( 0 === strpos( $k, 'posts_' ) ) {
				unset( $out[ $k ] );
			}
		}
		$type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
		$out['posts_post_type'] = is_string( $type ) && 'any' !== $type ? $type : 'post';
		// Utan posts_per_page i JSON:en använde Crocoblock WP_Querys standard,
		// dvs. sajtens "Blogginlägg per sida" – inte widgetens antal.
		if ( isset( $args['posts_per_page'] ) && (int) $args['posts_per_page'] > 0 ) {
			$out['classic_posts_per_page'] = (int) $args['posts_per_page'];
		} elseif ( isset( $args['numberposts'] ) && (int) $args['numberposts'] > 0 ) {
			$out['classic_posts_per_page'] = (int) $args['numberposts'];
		} else {
			$out['classic_posts_per_page'] = max( 1, (int) $this->ctx['per_page'] );
		}
		$id                     = (string) call_user_func( $this->ctx['register_query'], $args );
		$out['posts_query_id']  = $id;
		$notes[]                = sprintf( 'Den egna frågan (WP_Query-JSON) följer med som Query ID "%s". Låt det här tillägget vara aktivt – det är det som kör frågan.', $id );
		if ( preg_match( '/%[a-z_]+%/i', json_encode( $args ) ) ) {
			$notes[] = 'Frågan innehåller JetEngine-makron (%…%) som inte tolkas längre – kontrollera urvalet.';
		}
		return $out;
	}

	/**
	 * @return array|null
	 */
	public static function decode_query( $json ) {
		if ( class_exists( 'CBX_Queries' ) && function_exists( 'wp_unslash' ) ) {
			return CBX_Queries::decode( $json );
		}
		if ( is_array( $json ) ) {
			return $json;
		}
		$args = json_decode( trim( (string) $json ), true );
		return is_array( $args ) && $args ? $args : null;
	}

	protected function w_jet_button( array $s ) {
		$out = array(
			'text' => (string) $this->v( $s, 'button_label_normal', 'Click Me' ),
			'link' => $this->url( $this->v( $s, 'button_url', array( 'url' => '#' ) ) ),
		);
		if ( $this->on( $s, 'use_button_icon', true ) && ( $icon = $this->icon( $s, 'button_icon_normal' ) ) ) {
			$out['selected_icon'] = $icon;
		}
		return array( 'widgetType' => 'button', 'settings' => $out, 'notes' => array( 'Knappens hover-effekter och stil följer inte med.' ) );
	}

	protected function w_jet_headline( array $s ) {
		$title = trim( $this->v( $s, 'first_part', 'Heading' ) . ' ' . $this->v( $s, 'second_part', 'Element' ) );
		$out   = array(
			'title'       => $title,
			'header_size' => (string) $this->v( $s, 'header_size', 'h2' ),
		);
		if ( $this->has_url( $this->v( $s, 'link', '' ) ) ) {
			$out['link'] = $this->url( $s['link'] );
		}
		return array( 'widgetType' => 'heading', 'settings' => $out, 'notes' => array( 'Rubrikens två delar har slagits ihop till en; separat styling av delarna försvinner.' ) );
	}

	protected function w_jet_animated_text( array $s ) {
		$items = array();
		foreach ( (array) $this->v( $s, 'animated_text_list', array() ) as $it ) {
			if ( is_array( $it ) && '' !== $this->v( $it, 'item_text' ) ) {
				$items[] = $it['item_text'];
			}
		}
		if ( ! $items ) {
			$items = array( 'Create' );
		}
		$before = (string) $this->v( $s, 'before_text_content', 'Let us' );
		$after  = (string) $this->v( $s, 'after_text_content', 'your text' );

		if ( $this->ctx['has_pro'] ) {
			$out = array(
				'headline_style' => 'rotate',
				'animation_type' => 'typing',
				'before_text'    => $before,
				'rotating_text'  => implode( "\n", $items ),
				'after_text'     => $after,
				'tag'            => in_array( $this->v( $s, 'html_tag', 'div' ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), true ) ? $this->v( $s, 'html_tag', 'div' ) : 'div',
			);
			if ( $this->has_url( $this->v( $s, 'animated_text_link', '' ) ) ) {
				$out['link'] = $this->url( $s['animated_text_link'] );
			}
			return array( 'widgetType' => 'animated-headline', 'settings' => $out );
		}
		return array(
			'widgetType' => 'heading',
			'settings'   => array( 'title' => trim( $before . ' ' . $items[0] . ' ' . $after ), 'header_size' => 'h2' ),
			'notes'      => array( 'Animationen försvann (kräver Elementor Pro). Bara första ordet visas.' ),
		);
	}

	protected function w_jet_banner( array $s ) {
		$out = array(
			'title_text'       => (string) $this->v( $s, 'banner_title' ),
			'description_text' => (string) $this->v( $s, 'banner_text' ),
			'title_size'       => (string) $this->v( $s, 'banner_title_html_tag', 'h5' ),
		);
		if ( $img = $this->media( $this->v( $s, 'banner_image', null ) ) ) {
			$out['image'] = $img;
		}
		if ( '' !== $this->v( $s, 'banner_link' ) ) {
			$out['link'] = $this->url( $s['banner_link'] );
		}
		return array( 'widgetType' => 'image-box', 'settings' => $out, 'notes' => array( 'Bannerns överlägg och hover-animation följer inte med (texten ligger nu under bilden).' ) );
	}

	protected function w_jet_services( array $s ) {
		$notes = array();
		$out   = array(
			'title_text'       => (string) $this->v( $s, 'services_title', 'Title' ),
			'description_text' => (string) $this->v( $s, 'services_description', '' ),
			'title_size'       => (string) $this->v( $s, 'services_title_size', 'h3' ),
		);
		if ( $this->has_url( $this->v( $s, 'button_url', '' ) ) ) {
			$out['link'] = $this->url( $s['button_url'] );
			$notes[]     = sprintf( 'Knappen "%s" har blivit en länk på hela rutan.', $this->v( $s, 'button_text', 'More' ) );
		}
		if ( $img = $this->media( $this->v( $s, 'services_image', null ) ) ) {
			$out['image'] = $img;
			return array( 'widgetType' => 'image-box', 'settings' => $out, 'notes' => $notes );
		}
		$icon = $this->icon( $s, 'services_icon' );
		$out['selected_icon'] = $icon ? $icon : array( 'value' => 'fas fa-star', 'library' => 'fa-solid' );
		return array( 'widgetType' => 'icon-box', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_team_member( array $s ) {
		$name  = trim( $this->v( $s, 'member_first_name', 'John' ) . ' ' . $this->v( $s, 'member_last_name', 'Borthwick' ) );
		$desc  = array_filter( array( $this->v( $s, 'member_position', 'Position' ), $this->v( $s, 'member_description', '' ) ) );
		$out   = array(
			'title_text'       => $name,
			'description_text' => implode( "\n", $desc ),
			'title_size'       => (string) $this->v( $s, 'member_name_html_tag', 'h3' ),
		);
		if ( $img = $this->media( $this->v( $s, 'member_image', null ) ) ) {
			$out['image'] = $img;
		}
		if ( $this->has_url( $this->v( $s, 'button_url', '' ) ) ) {
			$out['link'] = $this->url( $s['button_url'] );
		}
		$notes = array();
		if ( ! empty( $s['social_list'] ) ) {
			$notes[] = 'Sociala ikoner följer inte med – lägg till en "Social Icons"-widget under.';
		}
		return array( 'widgetType' => 'image-box', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_testimonials( array $s ) {
		$items = array_filter( (array) $this->v( $s, 'item_list', array() ), 'is_array' );
		if ( $this->ctx['has_pro'] ) {
			$slides = array();
			foreach ( $items as $it ) {
				$slide = array(
					'_id'     => $this->rid(),
					'content' => (string) $this->v( $it, 'item_comment' ),
					'name'    => (string) $this->v( $it, 'item_name' ),
					'title'   => (string) $this->v( $it, 'item_position' ),
				);
				if ( $img = $this->media( $this->v( $it, 'item_image', null ) ) ) {
					$slide['image'] = $img;
				}
				$slides[] = $slide;
			}
			$c   = $this->carousel_settings( $s, array( 'arrows' => false, 'dots' => true, 'autoplay' => true ) );
			$out = array(
				'slides'         => $slides,
				'skin'           => 'default',
				'layout'         => 'image_above',
				'autoplay'       => $c['autoplay'],
				'autoplay_speed' => $c['autoplay_speed'],
				'loop'           => $c['infinite'],
				'speed'          => $c['speed'],
				'show_arrows'    => in_array( $c['navigation'], array( 'both', 'arrows' ), true ) ? 'yes' : '',
				'pagination'     => in_array( $c['navigation'], array( 'both', 'dots' ), true ) ? 'bullets' : '',
			);
			if ( '' !== $this->v( $s, 'slides_to_show' ) ) {
				$out['slides_per_view'] = (string) $s['slides_to_show'];
			}
			return array( 'widgetType' => 'testimonial-carousel', 'settings' => $out, 'notes' => array( 'Betyg (stjärnor) och datum följer inte med.' ) );
		}
		$first = $items ? reset( $items ) : array();
		$out   = array(
			'testimonial_content' => (string) $this->v( $first, 'item_comment' ),
			'testimonial_name'    => (string) $this->v( $first, 'item_name' ),
			'testimonial_job'     => (string) $this->v( $first, 'item_position' ),
		);
		if ( $img = $this->media( $this->v( $first, 'item_image', null ) ) ) {
			$out['testimonial_image'] = $img;
		}
		return array( 'widgetType' => 'testimonial', 'settings' => $out, 'notes' => array( sprintf( 'Utan Elementor Pro visas bara första omdömet av %d.', count( $items ) ) ) );
	}

	protected function gallery_from( array $items, $field = 'item_image' ) {
		$gallery = array();
		foreach ( $items as $it ) {
			if ( is_array( $it ) && ( $img = $this->media( $this->v( $it, $field, null ) ) ) ) {
				$gallery[] = $img;
			}
		}
		return $gallery;
	}

	/**
	 * Karusellbeteende: autoplay, hastighet, loop, pilar och prickar.
	 *
	 * @param array $d Crocoblocks standardvärden för widgeten.
	 */
	protected function carousel_settings( array $s, array $d ) {
		$arrows = $this->on( $s, 'arrows', $d['arrows'] );
		$dots   = $this->on( $s, 'dots', $d['dots'] );
		return array(
			'autoplay'       => $this->yn( $this->on( $s, 'autoplay', $d['autoplay'] ) ),
			'autoplay_speed' => (int) $this->v( $s, 'autoplay_speed', 5000 ),
			'infinite'       => $this->yn( $this->on( $s, 'infinite', true ) ),
			'pause_on_hover' => $this->yn( $this->on( $s, 'pause_on_hover', false ) ),
			'speed'          => (int) $this->v( $s, 'speed', 500 ),
			'effect'         => 'fade' === $this->v( $s, 'effect', 'slide' ) ? 'fade' : 'slide',
			'navigation'     => $arrows && $dots ? 'both' : ( $arrows ? 'arrows' : ( $dots ? 'dots' : 'none' ) ),
		);
	}

	protected function w_jet_carousel( array $s ) {
		$items = array_filter( (array) $this->v( $s, 'items_list', array() ), 'is_array' );
		$notes = array();
		foreach ( $items as $it ) {
			if ( '' !== $this->v( $it, 'item_title' ) || '' !== $this->v( $it, 'item_text' ) ) {
				$notes[] = 'Rubriker/texter på bilderna följer inte med – bara bilderna.';
				break;
			}
		}
		foreach ( $items as $it ) {
			if ( 'template' === $this->v( $it, 'item_content_type', 'default' ) ) {
				$notes[] = 'Någon bild använde en mall som innehåll – den delen måste byggas om.';
				break;
			}
		}
		$out = array_merge(
			array(
				'carousel'         => $this->gallery_from( $items ),
				'slides_to_show'   => (string) $this->v( $s, 'slides_to_show', '2' ),
				'slides_to_scroll' => (string) $this->v( $s, 'slides_to_scroll', '1' ),
			),
			$this->carousel_settings( $s, array( 'arrows' => true, 'dots' => false, 'autoplay' => true ) )
		);
		return array( 'widgetType' => 'image-carousel', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_slider( array $s ) {
		$items = array_filter( (array) $this->v( $s, 'item_list', array() ), 'is_array' );
		if ( ! $this->ctx['has_pro'] ) {
			return array(
				'widgetType' => 'image-carousel',
				'settings'   => array( 'carousel' => $this->gallery_from( $items ), 'slides_to_show' => '1', 'navigation' => 'both' ),
				'notes'      => array( 'Utan Elementor Pro blir bildspelet en bildkarusell utan texter och knappar.' ),
			);
		}
		$slides = array();
		$notes  = array();
		foreach ( $items as $it ) {
			$desc  = trim( $this->v( $it, 'item_subtitle' ) . "\n" . $this->v( $it, 'item_desc' ) );
			$slide = array(
				'_id'             => $this->rid(),
				'heading'         => (string) $this->v( $it, 'item_title' ),
				'description'     => $desc,
				'button_text'     => '',
				'background_size' => 'cover',
			);
			if ( '' !== $this->v( $it, 'item_button_primary_url' ) ) {
				$slide['button_text'] = (string) $this->v( $it, 'item_button_primary_text', 'More' );
				$slide['link']        = $this->url( $it['item_button_primary_url'] );
			} elseif ( $this->on( $it, 'item_link' ) && '' !== $this->v( $it, 'item_link_url' ) ) {
				$slide['link']       = $this->url( $it['item_link_url'] );
				$slide['link_click'] = 'slide';
			}
			if ( '' !== $this->v( $it, 'item_button_secondary_url' ) ) {
				$notes['sec'] = 'Andra knappen på bilderna följer inte med (Slides har en knapp per bild).';
			}
			if ( $img = $this->media( $this->v( $it, 'item_image', null ) ) ) {
				$slide['background_image'] = $img;
			}
			if ( 'template' === $this->v( $it, 'item_content_type', 'default' ) ) {
				$notes['tpl'] = 'Någon bild använde en mall som innehåll – den delen måste byggas om.';
			}
			$slides[] = $slide;
		}
		$arrows = $this->on( $s, 'slider_navigation', true );
		$dots   = $this->on( $s, 'slider_pagination', false );
		$out    = array(
			'slides'         => $slides,
			'autoplay'       => $this->yn( $this->on( $s, 'slider_autoplay', true ) ),
			'autoplay_speed' => (int) $this->v( $s, 'slider_autoplay_delay', 5000 ),
			'infinite'       => $this->yn( $this->on( $s, 'slider_loop', true ) ),
			'transition'     => $this->on( $s, 'slider_fade_mode', false ) ? 'fade' : 'slide',
			'navigation'     => $arrows && $dots ? 'both' : ( $arrows ? 'arrows' : ( $dots ? 'dots' : 'none' ) ),
		);
		return array( 'widgetType' => 'slides', 'settings' => $out, 'notes' => array_values( $notes ) );
	}

	protected function w_jet_images_layout( array $s ) {
		$items = array_filter( (array) $this->v( $s, 'image_list', array() ), 'is_array' );
		return array(
			'widgetType' => 'image-gallery',
			'settings'   => array(
				'wp_gallery'      => $this->gallery_from( $items ),
				'gallery_columns' => (string) $this->v( $s, 'columns', 3 ),
				'gallery_link'    => 'file',
				'open_lightbox'   => 'yes',
			),
			'notes'      => array( 'Masonry-/justerad layout och bildtexter blir ett vanligt rutnät.' ),
		);
	}

	protected function w_jet_portfolio( array $s ) {
		$res          = $this->w_jet_images_layout( $s );
		$res['notes'] = array( 'Portfolions kategorifilter och "Visa mer" följer inte med – bara bilderna i rutnät.' );
		return $res;
	}

	protected function w_jet_brands( array $s ) {
		$items = array_filter( (array) $this->v( $s, 'brands_list', array() ), 'is_array' );
		return array(
			'widgetType' => 'image-gallery',
			'settings'   => array(
				'wp_gallery'      => $this->gallery_from( $items ),
				'gallery_columns' => (string) $this->v( $s, 'columns', 4 ),
				'gallery_link'    => 'none',
			),
			'notes'      => array( 'Logotypernas länkar följer inte med.' ),
		);
	}

	protected function w_jet_progress_bar( array $s ) {
		return array(
			'widgetType' => 'progress',
			'settings'   => array(
				'title'   => (string) $this->v( $s, 'title' ),
				'percent' => array( 'unit' => '%', 'size' => $this->num( $this->v( $s, 'percent', 50 ), 50 ) ),
			),
		);
	}

	protected function w_jet_circle_progress( array $s ) {
		$percent = 'percent' === $this->v( $s, 'values_type', 'percent' );
		$value   = $percent ? $this->num( $this->v( $s, 'percent_value', 50 ), 50 ) : $this->num( $this->v( $s, 'absolute_value_curr', 50 ), 50 );
		return array(
			'widgetType' => 'counter',
			'settings'   => array(
				'starting_number' => 0,
				'ending_number'   => $value,
				'prefix'          => (string) $this->v( $s, 'prefix', '' ),
				'suffix'          => (string) $this->v( $s, 'suffix', '%' ),
				'title'           => trim( $this->v( $s, 'title', '' ) . ' ' . $this->v( $s, 'subtitle', '' ) ),
			),
			'notes'      => array( 'Cirkeldiagrammet blev en räknare (siffra).' ),
		);
	}

	protected function w_jet_countdown_timer( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Countdown' );
		}
		$type = $this->v( $s, 'type', 'due_date' );
		$out  = array(
			'show_days'    => $this->yn( $this->on( $s, 'show_days', true ) ),
			'show_hours'   => $this->yn( $this->on( $s, 'show_hours', true ) ),
			'show_minutes' => $this->yn( $this->on( $s, 'show_min', true ) ),
			'show_seconds' => $this->yn( $this->on( $s, 'show_sec', true ) ),
			'custom_labels'=> 'yes',
			'label_days'   => (string) $this->v( $s, 'label_days', 'Dagar' ),
			'label_hours'  => (string) $this->v( $s, 'label_hours', 'Timmar' ),
			'label_minutes'=> (string) $this->v( $s, 'label_min', 'Minuter' ),
			'label_seconds'=> (string) $this->v( $s, 'label_sec', 'Sekunder' ),
		);
		$notes = array();
		if ( 'evergreen' === $type ) {
			$out['countdown_type']             = 'evergreen';
			$out['evergreen_counter_hours']    = (int) $this->v( $s, 'evergreen_hours', 23 );
			$out['evergreen_counter_minutes']  = (int) $this->v( $s, 'evergreen_minutes', 30 );
		} else {
			$out['countdown_type'] = 'due_date';
			if ( '' !== $this->v( $s, 'due_date' ) ) {
				$out['due_date'] = (string) $s['due_date'];
			} else {
				$notes[] = 'Slutdatum var inte sparat (standardvärde) – ange datum i widgeten.';
			}
			if ( 'endless' === $type ) {
				$notes[] = 'Var en återkommande nedräkning – kontrollera inställningen.';
			}
		}
		if ( '' !== $this->v( $s, 'message_after_expire' ) ) {
			$out['expire_actions']       = array( 'message' );
			$out['message_after_expire'] = (string) $s['message_after_expire'];
		}
		return array( 'widgetType' => 'countdown', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_pricing_table( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Price Table' );
		}
		$features = array();
		$excluded = false;
		foreach ( (array) $this->v( $s, 'features_list', array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			if ( 'item-excluded' === $this->v( $it, 'item_included', 'item-included' ) ) {
				$excluded = true;
			}
			$features[] = array( '_id' => $this->rid(), 'item_text' => (string) $this->v( $it, 'item_text', 'Feature' ) );
		}
		$link = array(
			'url'         => (string) $this->v( $s, 'button_url', '#' ),
			'is_external' => $this->on( $s, 'button_is_external' ) ? 'on' : '',
			'nofollow'    => $this->on( $s, 'button_nofollow' ) ? 'on' : '',
		);
		$out = array(
			'heading'                => (string) $this->v( $s, 'title', 'Title' ),
			'sub_heading'            => (string) $this->v( $s, 'subtitle', 'Subtitle' ),
			'currency_symbol'        => 'custom',
			'currency_symbol_custom' => (string) $this->v( $s, 'price_prefix', '$' ),
			'price'                  => (string) $this->v( $s, 'price', '100' ),
			'period'                 => (string) $this->v( $s, 'price_suffix', '/per month' ),
			'features_list'          => $features,
			'button_text'            => (string) $this->v( $s, 'button_text', 'Buy' ),
			'link'                   => $link,
			'footer_additional_info' => (string) $this->v( $s, 'button_after', '' ),
		);
		if ( $this->on( $s, 'featured' ) ) {
			$out['show_ribbon']  = 'yes';
			$out['ribbon_title'] = 'Populär';
		}
		$notes = array();
		if ( $excluded ) {
			$notes[] = 'Funktioner markerade som "ingår ej" visas nu som vanliga rader – markera dem manuellt (t.ex. med en annan ikon).';
		}
		return array( 'widgetType' => 'price-table', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_price_list( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Price List' );
		}
		$list = array();
		foreach ( (array) $this->v( $s, 'price_list', array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$row = array(
				'_id'              => $this->rid(),
				'title'            => (string) $this->v( $it, 'item_title' ),
				'price'            => (string) $this->v( $it, 'item_price' ),
				'item_description' => (string) $this->v( $it, 'item_text' ),
			);
			if ( $img = $this->media( $this->v( $it, 'item_image', null ) ) ) {
				$row['image'] = $img;
			}
			if ( $this->has_url( $this->v( $it, 'item_url', '' ) ) ) {
				$row['link'] = $this->url( $it['item_url'] );
			}
			$list[] = $row;
		}
		return array( 'widgetType' => 'price-list', 'settings' => array( 'price_list' => $list ) );
	}

	protected function w_jet_video( array $s ) {
		$type  = $this->v( $s, 'video_type', 'youtube' );
		$out   = array();
		$notes = array();
		if ( 'vimeo' === $type ) {
			$out['video_type'] = 'vimeo';
			$out['vimeo_url']  = (string) $this->v( $s, 'vimeo_url', 'https://vimeo.com/235215203' );
		} elseif ( 'self_hosted' === $type ) {
			$out['video_type'] = 'hosted';
			if ( $m = $this->media( $this->v( $s, 'self_hosted_url', null ) ) ) {
				$out['hosted_url'] = $m;
			} elseif ( '' !== $this->v( $s, 'external_url' ) ) {
				$out['insert_url']   = 'yes';
				$out['external_url'] = $this->url( $s['external_url'] );
			} else {
				$notes[] = 'Ingen videofil hittades – välj filen i widgeten.';
			}
		} else {
			$out['video_type']  = 'youtube';
			$out['youtube_url'] = (string) $this->v( $s, 'youtube_url', 'https://www.youtube.com/watch?v=CJO0u_HrWE8' );
		}
		foreach ( array( 'autoplay', 'mute', 'loop' ) as $k ) {
			if ( $this->on( $s, $k ) ) {
				$out[ $k ] = 'yes';
			}
		}
		if ( $this->on( $s, 'yt_privacy_mode' ) ) {
			$out['yt_privacy'] = 'yes';
		}
		if ( $this->on( $s, 'show_thumbnail' ) && ( $thumb = $this->media( $this->v( $s, 'thumbnail', null ) ) ) ) {
			$out['show_image_overlay'] = 'yes';
			$out['image_overlay']      = $thumb;
		}
		return array( 'widgetType' => 'video', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_audio( array $s ) {
		$src = '';
		if ( 'external' === $this->v( $s, 'audio_source', 'self' ) ) {
			$src = (string) $this->v( $s, 'external_url' );
		} elseif ( $m = $this->media( $this->v( $s, 'self_url', null ) ) ) {
			$src = $m['url'];
		}
		if ( '' === $src ) {
			return array( 'manual' => 'Ljudspelaren saknar ljudfil – lägg in en HTML-widget med <audio>.' );
		}
		return array(
			'widgetType' => 'html',
			'settings'   => array( 'html' => '<audio controls preload="metadata" src="' . $this->e( $src ) . '"' . ( $this->on( $s, 'loop' ) ? ' loop' : '' ) . ' style="width:100%"></audio>' ),
			'notes'      => array( 'Ljudspelaren använder nu webbläsarens inbyggda spelare.' ),
		);
	}

	protected function w_jet_map( array $s ) {
		$pins    = array_filter( (array) $this->v( $s, 'pins', array() ), 'is_array' );
		$address = '';
		if ( $pins ) {
			$first   = reset( $pins );
			$address = ( '1' === (string) $this->v( $first, 'pin_address_type', '1' ) ) ? (string) $this->v( $first, 'pin_address' ) : (string) $this->v( $first, 'pin_address_lat_lng' );
		}
		if ( '' === $address ) {
			$address = (string) $this->v( $s, 'map_center', $this->v( $s, 'map_center_lat_lng', '' ) );
		}
		$notes = array();
		if ( count( $pins ) > 1 ) {
			$notes[] = sprintf( 'Kartan hade %d nålar – Elementors karta visar bara en adress.', count( $pins ) );
		}
		if ( '' === $address ) {
			$notes[] = 'Ingen adress sparad (standardvärde) – ange adress i widgeten.';
		}
		$height = $this->num( $this->v( $s, 'map_height', 300 ), 300 );
		return array(
			'widgetType' => 'google_maps',
			'settings'   => array(
				'address' => $address,
				'zoom'    => array( 'unit' => 'px', 'size' => $this->num( $this->v( $s, 'zoom', 11 ), 11 ) ),
				'height'  => array( 'unit' => 'px', 'size' => $height ),
			),
			'notes'      => $notes,
		);
	}

	protected function w_jet_table( array $s ) {
		$html = '<table class="cbx-table">';
		$cell = function ( array $c, $tag ) {
			$text = $this->v( $c, 'cell_text', '' );
			if ( $this->has_url( $this->v( $c, 'cell_link', '' ) ) ) {
				$u    = $this->url( $c['cell_link'] );
				$text = '<a href="' . $this->e( $u['url'] ) . '"' . ( $u['is_external'] ? ' target="_blank" rel="noopener"' : '' ) . '>' . $text . '</a>';
			}
			$attr = '';
			if ( (int) $this->v( $c, 'col_span', 0 ) > 1 ) {
				$attr .= ' colspan="' . (int) $c['col_span'] . '"';
			}
			if ( (int) $this->v( $c, 'row_span', 0 ) > 1 ) {
				$attr .= ' rowspan="' . (int) $c['row_span'] . '"';
			}
			return '<' . $tag . $attr . '>' . $text . '</' . $tag . '>';
		};

		$head = array_filter( (array) $this->v( $s, 'table_header', array() ), 'is_array' );
		if ( $head ) {
			$html .= '<thead><tr>';
			foreach ( $head as $c ) {
				$html .= $cell( $c, 'th' );
			}
			$html .= '</tr></thead>';
		}

		$html .= '<tbody>';
		$open  = false;
		foreach ( array_filter( (array) $this->v( $s, 'table_body', array() ), 'is_array' ) as $c ) {
			if ( 'row' === $this->v( $c, 'action', 'cell' ) ) {
				$html .= ( $open ? '</tr>' : '' ) . '<tr>';
				$open  = true;
				continue;
			}
			if ( ! $open ) {
				$html .= '<tr>';
				$open  = true;
			}
			$html .= $cell( $c, $this->on( $c, 'cell_is_th' ) ? 'th' : 'td' );
		}
		$html .= ( $open ? '</tr>' : '' ) . '</tbody>';

		$foot = array_filter( (array) $this->v( $s, 'table_footer', array() ), 'is_array' );
		if ( $foot ) {
			$html .= '<tfoot><tr>';
			foreach ( $foot as $c ) {
				$html .= $cell( $c, 'td' );
			}
			$html .= '</tr></tfoot>';
		}
		$html .= '</table>';

		return array(
			'widgetType' => 'text-editor',
			'settings'   => array( 'editor' => $html ),
			'notes'      => array( 'Tabellen ligger nu som HTML i en textwidget. Stil, sortering och bilder i celler följer inte med.' ),
		);
	}

	protected function w_jet_timeline( array $s ) {
		$tag  = in_array( $this->v( $s, 'title_tag', 'h5' ), array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' ), true ) ? $this->v( $s, 'title_tag', 'h5' ) : 'h5';
		$html = '<ol class="cbx-timeline">';
		foreach ( array_filter( (array) $this->v( $s, 'cards_list', array() ), 'is_array' ) as $it ) {
			$html .= '<li>';
			if ( '' !== $this->v( $it, 'item_meta' ) ) {
				$html .= '<p class="cbx-timeline__meta"><strong>' . $this->e( $it['item_meta'] ) . '</strong></p>';
			}
			$html .= '<' . $tag . '>' . $this->e( $this->v( $it, 'item_title' ) ) . '</' . $tag . '>';
			$html .= wpautop_lite( (string) $this->v( $it, 'item_desc' ) );
			$html .= '</li>';
		}
		$html .= '</ol>';
		return array(
			'widgetType' => 'text-editor',
			'settings'   => array( 'editor' => $html ),
			'notes'      => array( 'Tidslinjen är nu en numrerad lista. Bilder, knappar och den grafiska linjen följer inte med.' ),
		);
	}

	protected function w_jet_inline_svg( array $s ) {
		$img = $this->media( $this->v( $s, 'svg_url', null ) );
		if ( ! $img ) {
			return array( 'manual' => 'SVG-widgeten saknar fil – lägg in en bild-widget.' );
		}
		$out = array( 'image' => $img );
		if ( $this->has_url( $this->v( $s, 'svg_link', '' ) ) ) {
			$out['link_to'] = 'custom';
			$out['link']    = $this->url( $s['svg_link'] );
		}
		return array( 'widgetType' => 'image', 'settings' => $out, 'notes' => array( 'SVG:n visas nu som bild – färgstyrning via CSS fungerar inte längre.' ) );
	}

	protected function w_jet_download_button( array $s ) {
		$file = $this->v( $s, 'download_file', '' );
		$url  = is_numeric( $file ) ? call_user_func( $this->ctx['attachment_url'], (int) $file ) : '';
		$out  = array(
			'text' => (string) $this->v( $s, 'download_label', 'Download' ),
			'link' => array( 'url' => $url ? $url : '#', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => 'download|' ),
		);
		if ( $icon = $this->icon( $s, 'button_icon' ) ) {
			$out['selected_icon'] = $icon;
		}
		$notes = array( 'Filstorleken under knappen följer inte med.' );
		if ( ! $url ) {
			$notes[] = 'Kunde inte hitta filen – sätt länken manuellt.';
		}
		return array( 'widgetType' => 'button', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_animated_box( array $s ) {
		$notes = array();
		if ( 'template' === $this->v( $s, 'front_side_content_type', 'default' ) || 'template' === $this->v( $s, 'back_side_content_type', 'default' ) ) {
			$notes[] = 'Någon sida av rutan använde en mall – den delen måste byggas om.';
		}
		if ( ! $this->ctx['has_pro'] ) {
			return array(
				'widgetType' => 'image-box',
				'settings'   => array(
					'title_text'       => (string) $this->v( $s, 'front_side_title', 'Title' ),
					'description_text' => (string) $this->v( $s, 'front_side_description', '' ),
				),
				'notes'      => array_merge( $notes, array( 'Utan Elementor Pro försvinner baksidan (vändeffekten).' ) ),
			);
		}
		$out = array(
			'graphic_element'    => 'none',
			'title_text_a'       => trim( $this->v( $s, 'front_side_title', 'Title' ) . ' ' . $this->v( $s, 'front_side_subtitle', '' ) ),
			'description_text_a' => (string) $this->v( $s, 'front_side_description', '' ),
			'title_text_b'       => trim( $this->v( $s, 'back_side_title', 'Back Side' ) . ' ' . $this->v( $s, 'back_side_subtitle', '' ) ),
			'description_text_b' => (string) $this->v( $s, 'back_side_description', '' ),
			'button_text'        => (string) $this->v( $s, 'back_side_button_text', 'Read More' ),
		);
		if ( $this->has_url( $this->v( $s, 'back_side_button_link', '' ) ) ) {
			$out['link'] = $this->url( $s['back_side_button_link'] );
		} else {
			$out['button_text'] = '';
		}
		return array( 'widgetType' => 'flip-box', 'settings' => $out, 'notes' => $notes );
	}

	/* ---------- JetTabs ---------- */

	protected function tabs_items( array $items ) {
		$tabs  = array();
		$notes = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$tabs[] = array(
				'_id'         => $this->rid(),
				'tab_title'   => (string) $this->v( $it, 'item_label', 'New Tab' ),
				'tab_content' => $this->tab_content(
					$this->v( $it, 'content_type', 'template' ),
					$this->v( $it, 'item_template_id' ),
					$this->v( $it, 'item_editor_content', '' ),
					$notes
				),
			);
		}
		return array( $tabs, array_values( array_unique( $notes ) ) );
	}

	protected function w_jet_tabs( array $s ) {
		list( $tabs, $notes ) = $this->tabs_items( (array) $this->v( $s, 'tabs', array() ) );
		$out = array( 'tabs' => $tabs );
		if ( in_array( $this->v( $s, 'tabs_position', 'top' ), array( 'left', 'right' ), true ) ) {
			$out['type'] = 'vertical';
		}
		return array( 'widgetType' => 'tabs', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_accordion( array $s ) {
		list( $tabs, $notes ) = $this->tabs_items( (array) $this->v( $s, 'toggles', array() ) );
		$type = $this->on( $s, 'collapsible' ) ? 'toggle' : 'accordion';
		$out  = array( 'tabs' => $tabs );
		if ( $this->on( $s, 'faq_schema' ) ) {
			$out['faq_schema'] = 'yes';
		}
		return array( 'widgetType' => $type, 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_switcher( array $s ) {
		$notes = array( 'Växlaren (av/på) blev två flikar.' );
		$tabs  = array();
		foreach ( array( 'disable' => 'Disable', 'enable' => 'Enable' ) as $side => $label ) {
			$tabs[] = array(
				'_id'         => $this->rid(),
				'tab_title'   => (string) $this->v( $s, $side . '_label', $label ),
				'tab_content' => $this->tab_content(
					$this->v( $s, $side . '_content_type', 'template' ),
					$this->v( $s, $side . '_template_id' ),
					$this->v( $s, $side . '_item_editor_content', '' ),
					$notes
				),
			);
		}
		return array( 'widgetType' => 'tabs', 'settings' => array( 'tabs' => $tabs ), 'notes' => array_values( array_unique( $notes ) ) );
	}

	/* ---------- JetTricks ---------- */

	protected function w_jet_unfold( array $s ) {
		$notes   = array( '"Visa mer"-funktionen försvann – hela texten visas direkt. Använd "Toggle" om den ska vara dold.' );
		$content = $this->tab_content( $this->v( $s, 'content_type', 'editor' ), $this->v( $s, 'template_id' ), $this->v( $s, 'editor', '' ), $notes );
		return array( 'widgetType' => 'text-editor', 'settings' => array( 'editor' => $content ), 'notes' => $notes );
	}

	/* ---------- JetBlocks / JetMenu / JetSearch ---------- */

	protected function w_jet_logo( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Site Logo' );
		}
		if ( 'text' === $this->v( $s, 'logo_type', 'text' ) ) {
			if ( 'custom' === $this->v( $s, 'logo_text_from', 'site_name' ) && '' !== $this->v( $s, 'logo_text' ) ) {
				$out = array( 'title' => (string) $s['logo_text'], 'header_size' => 'div', 'link' => array( 'url' => '/' ) );
				return array( 'widgetType' => 'heading', 'settings' => $out );
			}
			return array( 'widgetType' => 'theme-site-title', 'settings' => array( 'header_size' => 'div' ) );
		}
		$notes = array( 'Logotypen hämtas nu från Utseende → Anpassa → Webbplatsidentitet. Kontrollera att rätt logotyp är vald där.' );
		$out   = array();
		if ( $img = $this->media( $this->v( $s, 'logo_image', null ) ) ) {
			// Behåll bilden som statisk bild om den inte är satt som webbplatslogo.
			$out['image'] = $img;
			$out['link_to'] = 'custom';
			$out['link']    = array( 'url' => '/' );
			return array( 'widgetType' => 'image', 'settings' => $out, 'notes' => array( 'Logotypen är nu en vanlig bild som länkar till startsidan.' ) );
		}
		return array( 'widgetType' => 'theme-site-logo', 'settings' => $out, 'notes' => $notes );
	}

	protected function menu_widget( array $s, $key, $extra_note = '' ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Nav Menu' );
		}
		$menu  = $this->v( $s, $key, '' );
		$slug  = '' !== $menu ? call_user_func( $this->ctx['menu_slug'], $menu ) : '';
		$out   = array(
			'layout'       => ( 'vertical' === $this->v( $s, 'layout', 'horizontal' ) ) ? 'vertical' : 'horizontal',
			'pointer'      => 'none', // Crocoblock har ingen understrykning under menypunkterna.
			'toggle'       => 'burger',
			'align_items'  => '',
		);
		// Brytpunkt för mobilmeny (nya JetMenu: none/lg/md).
		$bp = $this->v( $s, 'dropdown-breakpoint', 'md' );
		$out['dropdown'] = 'none' === $bp ? 'none' : ( 'lg' === $bp ? 'tablet' : 'mobile' );
		if ( 'dropdown' === $this->v( $s, 'layout', '' ) ) {
			$out['layout']   = 'dropdown';
			$out['dropdown'] = 'tablet';
		}
		$notes = array();
		if ( $slug ) {
			$out['menu'] = $slug;
		} else {
			$notes[] = 'Ingen meny var vald – välj meny i widgeten.';
		}
		if ( $extra_note ) {
			$notes[] = $extra_note;
		}
		return array( 'widgetType' => 'nav-menu', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_nav_menu( array $s ) {
		return $this->menu_widget( $s, 'nav_menu' );
	}

	protected function w_jet_custom_menu( array $s ) {
		return $this->menu_widget( $s, 'menu', 'Vertikala menyns mega-innehåll följer inte med.' );
	}

	protected function w_jet_mega_menu( array $s ) {
		return $this->menu_widget( $s, 'menu', 'Mega-meny-innehåll (mallar i menypunkter) följer inte med – bygg om som vanlig undermeny eller med Elementor Pros Mega Menu.' );
	}

	protected function w_jet_mobile_menu( array $s ) {
		$res = $this->menu_widget( $s, 'menu', 'Mobilmenyn är nu Elementors standardmeny med hamburger-läge.' );
		if ( isset( $res['settings'] ) ) {
			$res['settings']['dropdown'] = 'desktop';
		}
		return $res;
	}

	protected function w_jet_search( array $s ) {
		return $this->search_widget( (string) $this->v( $s, 'search_placeholder', 'Sök …' ) );
	}

	protected function w_jet_ajax_search( array $s ) {
		$res          = $this->search_widget( (string) $this->v( $s, 'search_placeholder_text', 'Sök …' ) );
		$res['notes'] = array_merge( isset( $res['notes'] ) ? $res['notes'] : array(), array( 'Live-förslagen (AJAX) försvann – sökningen går nu till sökresultatsidan.' ) );
		return $res;
	}

	protected function search_widget( $placeholder ) {
		$placeholder = html_entity_decode( $placeholder, ENT_QUOTES, 'UTF-8' );
		if ( $this->ctx['has_pro'] ) {
			return array( 'widgetType' => 'search-form', 'settings' => array( 'skin' => 'classic', 'placeholder' => $placeholder ) );
		}
		return array( 'widgetType' => 'wp-widget-search', 'settings' => array( 'wp' => array( 'title' => '' ) ), 'notes' => array( 'WordPress standardsökruta används (Elementor Pro saknas).' ) );
	}

	protected function w_jet_login( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Login' );
		}
		$out = array(
			'custom_labels'        => 'yes',
			'user_label'           => (string) $this->v( $s, 'label_username', 'Användarnamn' ),
			'user_placeholder'     => (string) $this->v( $s, 'placeholder_username', '' ),
			'password_label'       => (string) $this->v( $s, 'label_password', 'Lösenord' ),
			'password_placeholder' => (string) $this->v( $s, 'placeholder_password', '' ),
			'button_text'          => (string) $this->v( $s, 'label_log_in', 'Logga in' ),
			'show_lost_password'   => $this->yn( $this->on( $s, 'lost_password_link' ) ),
		);
		if ( 'custom' === $this->v( $s, 'login_redirect', 'home' ) && '' !== $this->v( $s, 'login_redirect_url' ) ) {
			$out['redirect_after_login'] = 'yes';
			$out['redirect_url']         = array( 'url' => (string) $s['login_redirect_url'] );
		}
		return array( 'widgetType' => 'login', 'settings' => $out );
	}

	protected function w_jet_blocks_cart( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Menu Cart' );
		}
		return array( 'widgetType' => 'woocommerce-menu-cart', 'settings' => array(), 'notes' => array( 'Varukorgens utseende måste stylas om.' ) );
	}

	protected function w_jet_blog_posts_navigation( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Post Navigation' );
		}
		return array( 'widgetType' => 'post-navigation', 'settings' => array() );
	}

	/* ---------- JetPopup ---------- */

	protected function w_jet_popup_action_button( array $s ) {
		$action = $this->v( $s, 'button_action_type', 'link' );
		$out    = array( 'text' => (string) $this->v( $s, 'button_text', 'Read More' ) );
		$notes  = array();
		if ( 'link' === $action ) {
			$out['link'] = $this->url( $this->v( $s, 'button_link', array( 'url' => '#' ) ) );
		} elseif ( $this->ctx['has_pro'] && 'close-popup' === $action ) {
			$out['link']        = array( 'url' => '#' );
			$out['__dynamic__'] = array( 'link' => $this->tag_text( 'popup', array( 'action' => 'close' ) ) );
		} else {
			$out['link'] = array( 'url' => '#' );
			$notes[]     = sprintf( 'Knappens popup-åtgärd ("%s") måste sättas manuellt (Länk → Dynamisk → Åtgärder → Popup).', $action );
		}
		return array( 'widgetType' => 'button', 'settings' => $out, 'notes' => $notes );
	}

	/* ---------- JetEngine ---------- */

	protected function w_jet_listing_grid( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Loop Grid' );
		}
		$listing_id = $this->v( $s, 'lisitng_id', $this->v( $s, 'listing_id', '' ) ); // JetEngine stavar nyckeln fel på riktigt.
		$map        = $listing_id ? call_user_func( $this->ctx['listing_map'], (int) $listing_id ) : null;
		if ( ! $map ) {
			return array( 'manual' => sprintf( 'Listning #%s är inte migrerad ännu. Kör "Migrera JetEngine-listningar" under Mallar och provkör igen.', $listing_id ) );
		}
		$notes = array();
		$out   = array(
			'template_id'          => (int) $map['id'],
			'posts_per_page'       => (int) $this->v( $s, 'posts_num', 6 ),
			'columns'              => (string) $this->v( $s, 'columns', 3 ),
			'post_query_post_type' => ! empty( $map['post_type'] ) ? $map['post_type'] : 'post',
		);
		foreach ( array( 'tablet', 'mobile' ) as $dev ) {
			if ( '' !== $this->v( $s, 'columns_' . $dev ) ) {
				$out[ 'columns_' . $dev ] = (string) $s[ 'columns_' . $dev ];
			}
		}
		if ( $this->on( $s, 'is_archive_template' ) ) {
			$out['post_query_post_type'] = 'current_query';
		}
		if ( $this->on( $s, 'custom_query' ) || ! empty( $s['posts_query'] ) ) {
			$notes[] = 'Listningen hade egna frågevillkor (meta/taxonomi/Query Builder). Ställ in motsvarande under Query i Loop Grid.';
		}
		if ( $this->on( $s, 'carousel_enabled' ) ) {
			$notes[] = 'Var en karusell – använd widgeten Loop Carousel om det behövs.';
		}
		if ( $this->on( $s, 'use_load_more' ) ) {
			$out['pagination_type'] = 'load_more_on_click';
		}
		if ( '' !== $this->v( $s, 'not_found_message' ) ) {
			$out['nothing_found_message'] = (string) $s['not_found_message'];
		}
		return array( 'widgetType' => 'loop-grid', 'settings' => $out, 'notes' => $notes );
	}

	protected function w_jet_listing_dynamic_field( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'dynamiska taggar' );
		}
		$source = $this->v( $s, 'dynamic_field_source', 'object' );
		$tag    = $this->v( $s, 'field_tag', 'div' );
		$tag    = in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ), true ) ? $tag : 'div';
		$notes  = array();
		if ( $this->on( $s, 'dynamic_field_filter' ) || $this->on( $s, 'dynamic_field_custom' ) ) {
			$notes[] = 'Fältet hade formatering/filter (t.ex. datumformat eller "%s") som inte följer med.';
		}

		if ( 'object' === $source ) {
			$prop = $this->v( $s, 'dynamic_field_post_object', 'post_title' );
			switch ( $prop ) {
				case 'post_title':
					return array( 'widgetType' => 'heading', 'settings' => array( 'title' => '', 'header_size' => $tag, '__dynamic__' => array( 'title' => $this->tag_text( 'post-title' ) ) ), 'notes' => $notes );
				case 'post_date':
					return array( 'widgetType' => 'heading', 'settings' => array( 'title' => '', 'header_size' => $tag, '__dynamic__' => array( 'title' => $this->tag_text( 'post-date' ) ) ), 'notes' => $notes );
				case 'post_excerpt':
					return array( 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '', '__dynamic__' => array( 'editor' => $this->tag_text( 'post-excerpt' ) ) ), 'notes' => $notes );
				case 'post_content':
					return array( 'widgetType' => 'theme-post-content', 'settings' => array(), 'notes' => $notes );
				case 'post_id':
				case 'ID':
					return array( 'widgetType' => 'heading', 'settings' => array( 'title' => '', 'header_size' => $tag, '__dynamic__' => array( 'title' => $this->tag_text( 'post-id' ) ) ), 'notes' => $notes );
			}
			return array( 'manual' => sprintf( 'Dynamiskt fält "%s" har ingen automatisk motsvarighet.', $prop ) );
		}

		if ( 'meta' === $source ) {
			$key = $this->v( $s, 'dynamic_field_post_meta_custom', $this->v( $s, 'dynamic_field_post_meta', '' ) );
			if ( '' === $key ) {
				return array( 'manual' => 'Dynamiskt metafält utan angiven nyckel.' );
			}
			return array(
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => '', '__dynamic__' => array( 'editor' => $this->tag_text( 'post-custom-field', array( 'key' => $key ) ) ) ),
				'notes'      => $notes,
			);
		}
		return array( 'manual' => sprintf( 'Dynamiskt fält med källa "%s" (t.ex. options-sida eller relation) konverteras inte automatiskt.', $source ) );
	}

	protected function w_jet_listing_dynamic_image( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'dynamiska taggar' );
		}
		$source = $this->v( $s, 'dynamic_image_source', 'post_thumbnail' );
		if ( 'post_thumbnail' !== $source ) {
			return array( 'manual' => sprintf( 'Bild från metafält "%s". Elementor Pro har ingen generell taggar för bild-metafält (bara ACF/Toolset/Pods) – bygg om manuellt.', $source ) );
		}
		$out = array(
			'image'            => array( 'id' => '', 'url' => '' ),
			'image_size'       => (string) $this->v( $s, 'dynamic_image_size', 'full' ),
			'__dynamic__'      => array( 'image' => $this->tag_text( 'post-featured-image' ) ),
		);
		if ( $this->on( $s, 'linked_image' ) && '_permalink' === $this->v( $s, 'image_link_source', '_permalink' ) ) {
			$out['link_to']              = 'custom';
			$out['link']                 = array( 'url' => '' );
			$out['__dynamic__']['link']  = $this->tag_text( 'post-url' );
		}
		return array( 'widgetType' => 'image', 'settings' => $out );
	}

	protected function w_jet_listing_dynamic_link( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'dynamiska taggar' );
		}
		$source = $this->v( $s, 'dynamic_link_source', '_permalink' );
		$out    = array( 'text' => (string) $this->v( $s, 'link_label', 'Read More' ), 'link' => array( 'url' => '' ) );
		if ( '_permalink' === $source ) {
			$out['__dynamic__'] = array( 'link' => $this->tag_text( 'post-url' ) );
		} elseif ( in_array( $source, array( 'delete_post_link', 'add_query_args', 'options_page' ), true ) ) {
			return array( 'manual' => sprintf( 'Dynamisk länk av typen "%s" konverteras inte automatiskt.', $source ) );
		} else {
			$key                = ( '' !== $this->v( $s, 'dynamic_link_source_custom' ) ) ? $s['dynamic_link_source_custom'] : $source;
			$out['__dynamic__'] = array( 'link' => $this->tag_text( 'post-custom-field', array( 'key' => $key ) ) );
		}
		if ( $this->on( $s, 'open_in_new' ) ) {
			$out['link']['is_external'] = 'on';
		}
		return array( 'widgetType' => 'button', 'settings' => $out, 'notes' => array( 'Länken är nu en knapp – styla den eller byt till textlänk.' ) );
	}

	protected function w_jet_listing_dynamic_terms( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'dynamiska taggar' );
		}
		$tax = $this->v( $s, 'from_tax', '' );
		if ( '' === $tax ) {
			return array( 'manual' => 'Termfält utan vald taxonomi.' );
		}
		$tag = $this->tag_text(
			'post-terms',
			array(
				'taxonomy'  => $tax,
				'separator' => (string) $this->v( $s, 'terms_delimiter', ', ' ),
				'link'      => $this->on( $s, 'terms_linked' ) ? 'yes' : '',
			)
		);
		return array( 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '', '__dynamic__' => array( 'editor' => $tag ) ) );
	}

	protected function w_jet_listing_dynamic_meta( array $s ) {
		if ( ! $this->ctx['has_pro'] ) {
			return $this->need_pro( 'Post Info' );
		}
		return array( 'widgetType' => 'post-info', 'settings' => array(), 'notes' => array( 'Visar författare, datum, tid och kommentarer – ta bort det som inte behövs.' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Tillägg (sticky, parallax, popup-kopplingar, dynamisk synlighet …)
	 * ------------------------------------------------------------------ */

	protected function clean_extensions( array $s, array $el ) {
		$notes   = array();
		$changed = false;
		$is_widget = isset( $el['elType'] ) && 'widget' === $el['elType'];
		$widget    = $is_widget && isset( $el['widgetType'] ) ? $el['widgetType'] : '';

		// Sticky-sektion (JetBlocks) → Elementor Pros sticky.
		if ( $this->on( $s, 'jet_sticky_section' ) ) {
			if ( $this->ctx['has_pro'] ) {
				$devices = array( 'desktop', 'tablet', 'mobile' );
				if ( ! empty( $s['jet_sticky_section_visibility'] ) && is_array( $s['jet_sticky_section_visibility'] ) ) {
					$devices = array_values( array_intersect( $s['jet_sticky_section_visibility'], $devices ) );
				}
				$s['sticky']    = 'top';
				$s['sticky_on'] = $devices ? $devices : array( 'desktop', 'tablet', 'mobile' );
				$notes[]        = 'Sticky (JetBlocks) ersatt med Elementor Pros sticky. Stilen i fastläst läge följer inte med.';
			} else {
				$notes[] = 'Sticky (JetBlocks) togs bort – Elementor Pro saknas.';
			}
		}
		// Sticky kolumn (JetTricks).
		if ( $this->on( $s, 'jet_tricks_column_sticky' ) ) {
			$notes[] = 'Sticky kolumn (JetTricks) togs bort. Elementor Pro stöder sticky på containrar och widgets – sätt det där om det behövs.';
		}
		if ( ! empty( $s['jet_parallax_layout_list'] ) ) {
			$notes[] = 'Parallaxlager (JetElements) togs bort. Använd "Motion Effects" i Elementor Pro om effekten behövs.';
		}
		if ( $this->on( $s, 'jet_tricks_widget_parallax' ) ) {
			$notes[] = 'Widget-parallax (JetTricks) togs bort. Använd "Motion Effects" i Elementor Pro.';
		}
		if ( $this->on( $s, 'jet_tricks_widget_tooltip' ) ) {
			$notes[] = sprintf( 'Tooltip (JetTricks) togs bort: "%s".', $this->v( $s, 'jet_tricks_widget_tooltip_description', '' ) );
		}
		if ( $this->on( $s, 'jet_tricks_widget_satellite' ) ) {
			$notes[] = 'Satellit-etikett (JetTricks) togs bort.';
		}
		if ( $this->on( $s, 'jedv_enabled' ) ) {
			$notes[] = 'Dynamisk synlighet (JetEngine) togs bort – elementet visas nu alltid. Använd Elementor Pros "Display Conditions" om villkoret behövs.';
		}

		// Popup-koppling (JetPopup).
		if ( ! empty( $s['jet_attached_popup'] ) ) {
			$old     = (int) $s['jet_attached_popup'];
			$new     = call_user_func( $this->ctx['popup_map'], $old );
			$trigger = $this->v( $s, 'jet_trigger_type', 'click-self' );
			$linkable = array( 'button' => 'link', 'image' => 'link', 'heading' => 'link', 'icon' => 'link', 'image-box' => 'link', 'icon-box' => 'link', 'text-editor' => '' );
			if ( $new && $this->ctx['has_pro'] && 'click-self' === $trigger && ! empty( $linkable[ $widget ] ) ) {
				$control = $linkable[ $widget ];
				if ( 'image' === $widget ) {
					$s['link_to'] = 'custom';
				}
				$s[ $control ] = array( 'url' => '#' );
				if ( ! isset( $s['__dynamic__'] ) || ! is_array( $s['__dynamic__'] ) ) {
					$s['__dynamic__'] = array();
				}
				$s['__dynamic__'][ $control ] = $this->tag_text( 'popup', array( 'action' => 'open', 'popup' => (string) $new ) );
				$notes[] = sprintf( 'Klick öppnar nu Elementor-popup #%d (tidigare JetPopup #%d).', $new, $old );
			} elseif ( $new ) {
				$notes[] = sprintf( 'Elementet öppnade JetPopup #%d (%s). Lägg en knapp/länk med Dynamisk → Åtgärder → Popup → #%d.', $old, $trigger, $new );
			} else {
				$notes[] = sprintf( 'Elementet öppnade JetPopup #%d – migrera popuperna först, eller koppla manuellt.', $old );
			}
		}

		foreach ( array_keys( $s ) as $key ) {
			if ( 0 === strpos( $key, 'jet_' ) || 0 === strpos( $key, 'jedv_' ) ) {
				unset( $s[ $key ] );
				$changed = true;
			}
		}
		foreach ( array( '__dynamic__', '__globals__' ) as $meta ) {
			if ( ! empty( $s[ $meta ] ) && is_array( $s[ $meta ] ) ) {
				foreach ( array_keys( $s[ $meta ] ) as $key ) {
					if ( 0 === strpos( $key, 'jet_' ) || 0 === strpos( $key, 'jedv_' ) ) {
						unset( $s[ $meta ][ $key ] );
						$changed = true;
					}
				}
			}
		}
		if ( $changed && ! $notes ) {
			$notes[] = 'Crocoblock-inställningar (utan synlig effekt) rensades.';
		}
		return array( $s, $notes, $changed || ! empty( $notes ) );
	}

	/* ------------------------------------------------------------------ *
	 * Dynamiska taggar
	 * ------------------------------------------------------------------ */

	protected function convert_dynamic_tags( array $s ) {
		$notes   = array();
		$changed = false;
		$s       = $this->walk_dynamic( $s, $notes, $changed );
		return array( $s, array_values( array_unique( $notes ) ), $changed );
	}

	protected function walk_dynamic( array $arr, array &$notes, &$changed ) {
		foreach ( $arr as $key => $val ) {
			if ( '__dynamic__' === $key && is_array( $val ) ) {
				foreach ( $val as $control => $text ) {
					if ( ! is_string( $text ) || false === strpos( $text, 'name="jet-' ) ) {
						continue;
					}
					$new = $this->map_tag_text( $text, $notes );
					if ( null === $new ) {
						unset( $arr[ $key ][ $control ] );
					} else {
						$arr[ $key ][ $control ] = $new;
					}
					$changed = true;
				}
				if ( empty( $arr[ $key ] ) ) {
					unset( $arr[ $key ] );
				}
			} elseif ( is_array( $val ) ) {
				$arr[ $key ] = $this->walk_dynamic( $val, $notes, $changed );
			}
		}
		return $arr;
	}

	protected function map_tag_text( $text, array &$notes ) {
		if ( ! preg_match( '/\[elementor-tag id="([^"]*)" name="([^"]*)" settings="([^"]*)"\]/', $text, $m ) ) {
			$notes[] = 'Oläslig dynamisk tagg togs bort.';
			return null;
		}
		$name     = $m[2];
		$settings = json_decode( urldecode( $m[3] ), true );
		$settings = is_array( $settings ) ? $settings : array();

		if ( $this->ctx['has_pro'] ) {
			if ( 'jet-post-custom-field' === $name && ! empty( $settings['meta_field'] ) ) {
				$notes[] = sprintf( 'JetEngine-taggen för fältet "%s" ersatt med Elementor Pros "Post Custom Field".', $settings['meta_field'] );
				$new     = array( 'key' => $settings['meta_field'] );
				if ( ! empty( $settings['fallback'] ) ) {
					$new['fallback'] = $settings['fallback'];
				}
				return $this->tag_text( 'post-custom-field', $new );
			}
			if ( 'jet-post-custom-image' === $name && isset( $settings['img_field'] ) && in_array( $settings['img_field'], array( '_thumbnail_id', 'post_thumbnail' ), true ) ) {
				$notes[] = 'JetEngine-bildtagg ersatt med "Featured Image".';
				return $this->tag_text( 'post-featured-image' );
			}
		}
		$notes[] = sprintf( 'Dynamiska taggen "%s" togs bort – kontrollen visar nu sitt fasta värde. Koppla om med en Elementor Pro-tagg vid behov.', $name );
		return null;
	}
}

/**
 * Enkel radbrytning till stycken (utan WordPress-beroende).
 */
if ( ! function_exists( 'wpautop_lite' ) ) {
	function wpautop_lite( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/<(p|div|ul|ol|table|h[1-6])[\s>]/i', $text ) ) {
			return $text;
		}
		$parts = preg_split( "/\n\s*\n/", $text );
		return '<p>' . implode( '</p><p>', array_map( 'nl2br', $parts ) ) . '</p>';
	}
}
