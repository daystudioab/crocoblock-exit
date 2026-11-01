<?php
/**
 * JetEngine-innehållstyper: inlägstyper, taxonomier och metafält.
 *
 * När JetEngine avaktiveras försvinner registreringen av de egna
 * inläggstyperna – innehållet finns kvar i databasen men blir osynligt.
 * Den här klassen tar en ögonblicksbild av definitionerna och registrerar
 * dem själv så fort JetEngine inte längre gör det. Den ger också en enkel
 * redigeringsruta för metafälten och kan exportera allt som PHP-kod.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Content_Types {

	const OPT_SNAPSHOT = 'cbx_content_types';

	protected static $pt_keys = array( 'label', 'labels', 'description', 'public', 'hierarchical', 'exclude_from_search', 'publicly_queryable', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_admin_bar', 'show_in_rest', 'rest_base', 'menu_position', 'menu_icon', 'capability_type', 'map_meta_cap', 'supports', 'taxonomies', 'has_archive', 'rewrite', 'query_var', 'can_export', 'delete_with_user' );

	protected static $tax_keys = array( 'label', 'labels', 'description', 'public', 'publicly_queryable', 'hierarchical', 'show_ui', 'show_in_menu', 'show_in_nav_menus', 'show_in_rest', 'rest_base', 'show_tagcloud', 'show_in_quick_edit', 'show_admin_column', 'query_var', 'rewrite' );

	protected static $bool_keys = array( 'public', 'hierarchical', 'exclude_from_search', 'publicly_queryable', 'show_ui', 'show_in_nav_menus', 'show_in_admin_bar', 'show_in_rest', 'map_meta_cap', 'can_export', 'delete_with_user', 'show_tagcloud', 'show_in_quick_edit', 'show_admin_column', 'query_var' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 20, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function jet_engine_active() {
		return class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' );
	}

	public static function snapshot_data() {
		return get_option( self::OPT_SNAPSHOT, null );
	}

	/* ---------- Läsa JetEngine ---------- */

	protected static function table( $name ) {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_' . $name;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table ? $table : null;
	}

	protected static function read_post_types() {
		global $wpdb;
		$table = self::table( 'post_types' );
		if ( ! $table ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) as $row ) { // phpcs:ignore
			if ( empty( $row['slug'] ) || ( isset( $row['status'] ) && 'publish' !== $row['status'] ) ) {
				continue;
			}
			$out[ $row['slug'] ] = array(
				'args'        => self::build_args( $row, self::$pt_keys ),
				'meta_fields' => self::simplify_fields( maybe_unserialize( isset( $row['meta_fields'] ) ? $row['meta_fields'] : '' ) ),
			);
		}
		return $out;
	}

	protected static function read_taxonomies() {
		global $wpdb;
		$table = self::table( 'taxonomies' );
		if ( ! $table ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) as $row ) { // phpcs:ignore
			if ( empty( $row['slug'] ) || ( isset( $row['status'] ) && 'publish' !== $row['status'] ) ) {
				continue;
			}
			$object_type = maybe_unserialize( isset( $row['object_type'] ) ? $row['object_type'] : array() );
			$out[ $row['slug'] ] = array(
				'object_type' => array_values( array_filter( (array) $object_type ) ),
				'args'        => self::build_args( $row, self::$tax_keys ),
			);
		}
		return $out;
	}

	/**
	 * Samma logik som JetEngines filter_item_for_register(), men med vitlista.
	 */
	protected static function build_args( array $row, array $allowed ) {
		$args   = maybe_unserialize( isset( $row['args'] ) ? $row['args'] : array() );
		$labels = maybe_unserialize( isset( $row['labels'] ) ? $row['labels'] : array() );
		$args   = is_array( $args ) ? $args : array();
		$labels = is_array( $labels ) ? array_filter( $labels, 'is_string' ) : array();

		$rewrite_slug = ! empty( $args['rewrite_slug'] ) ? $args['rewrite_slug'] : $row['slug'];
		$with_front   = isset( $args['with_front'] ) ? filter_var( $args['with_front'], FILTER_VALIDATE_BOOLEAN ) : true;
		$rewrite      = isset( $args['rewrite'] ) ? filter_var( $args['rewrite'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : true;

		$out = array();
		foreach ( $args as $key => $val ) {
			if ( in_array( $key, $allowed, true ) ) {
				$out[ $key ] = $val;
			}
		}
		foreach ( self::$bool_keys as $key ) {
			if ( isset( $out[ $key ] ) && ! is_bool( $out[ $key ] ) ) {
				$out[ $key ] = filter_var( $out[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		if ( isset( $out['show_in_menu'] ) && in_array( $out['show_in_menu'], array( 'true', 'false', '1', '0', 1, 0 ), true ) ) {
			$out['show_in_menu'] = filter_var( $out['show_in_menu'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( isset( $out['has_archive'] ) && in_array( $out['has_archive'], array( 'true', 'false', '1', '0', '' ), true ) ) {
			$out['has_archive'] = filter_var( $out['has_archive'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( isset( $out['menu_position'] ) ) {
			$out['menu_position'] = '' === $out['menu_position'] ? null : (int) $out['menu_position'];
		}
		if ( isset( $out['supports'] ) && ! is_array( $out['supports'] ) ) {
			$out['supports'] = array_filter( explode( ',', (string) $out['supports'] ) );
		}
		$out['rewrite'] = ( false === $rewrite ) ? false : array( 'slug' => $rewrite_slug, 'with_front' => $with_front );
		if ( $labels ) {
			$out['labels'] = $labels;
		}
		return $out;
	}

	/**
	 * Förenklad fältlista: name, title, type, options, is_timestamp.
	 */
	protected static function simplify_fields( $fields ) {
		$out = array();
		foreach ( (array) $fields as $f ) {
			if ( ! is_array( $f ) || empty( $f['name'] ) ) {
				continue;
			}
			if ( ! empty( $f['object_type'] ) && 'field' !== $f['object_type'] ) {
				continue; // flikar, dragspel och rubriker
			}
			$options = array();
			if ( ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				foreach ( $f['options'] as $opt ) {
					if ( is_array( $opt ) && isset( $opt['key'] ) ) {
						$options[ (string) $opt['key'] ] = isset( $opt['value'] ) ? (string) $opt['value'] : (string) $opt['key'];
					}
				}
			}
			$out[] = array(
				'name'         => (string) $f['name'],
				'title'        => ! empty( $f['title'] ) ? (string) $f['title'] : (string) $f['name'],
				'type'         => ! empty( $f['type'] ) ? (string) $f['type'] : 'text',
				'options'      => $options,
				'is_timestamp' => ! empty( $f['is_timestamp'] ) && filter_var( $f['is_timestamp'], FILTER_VALIDATE_BOOLEAN ),
				'multiple'     => ! empty( $f['is_multiple'] ) && filter_var( $f['is_multiple'], FILTER_VALIDATE_BOOLEAN ),
			);
		}
		return $out;
	}

	protected static function read_meta_boxes() {
		$boxes = get_option( 'jet_engine_meta_boxes', array() );
		$out   = array();
		foreach ( (array) $boxes as $box ) {
			if ( ! is_array( $box ) || empty( $box['args'] ) ) {
				continue;
			}
			$args = $box['args'];
			if ( isset( $args['object_type'] ) && 'post' !== $args['object_type'] ) {
				continue; // term- och användarmeta lämnas utanför
			}
			$out[] = array(
				'title'      => ! empty( $args['name'] ) ? $args['name'] : 'Fält',
				'post_types' => ! empty( $args['allowed_post_type'] ) ? (array) $args['allowed_post_type'] : array(),
				'fields'     => self::simplify_fields( isset( $box['meta_fields'] ) ? $box['meta_fields'] : array() ),
			);
		}
		return $out;
	}

	/* ---------- Inventering och ögonblicksbild ---------- */

	public static function inventory() {
		global $wpdb;
		$cct = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'jet_cct_' ) . '%' ) );
		$rel = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'jet_rel_' ) . '%' ) );
		$post_types = self::read_post_types();
		$counts     = array();
		foreach ( array_keys( $post_types ) as $slug ) {
			$counts[ $slug ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", $slug ) );
		}
		$options_pages = 0;
		$table         = self::table( 'post_types' );
		if ( $table ) {
			$options_pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'page'" ); // phpcs:ignore
		}
		return array(
			'post_types'    => $post_types,
			'post_counts'   => $counts,
			'taxonomies'    => self::read_taxonomies(),
			'meta_boxes'    => self::read_meta_boxes(),
			'options_pages' => $options_pages,
			'cct_tables'    => $cct,
			'relations'     => $rel,
			'snapshot'      => self::snapshot_data() ? self::snapshot_data()['time'] : '',
		);
	}

	public static function snapshot() {
		$data = array(
			'time'       => current_time( 'mysql' ),
			'post_types' => self::read_post_types(),
			'taxonomies' => self::read_taxonomies(),
			'meta_boxes' => self::read_meta_boxes(),
		);
		if ( ! $data['post_types'] && ! $data['taxonomies'] && ! $data['meta_boxes'] ) {
			return new WP_Error( 'cbx_nothing', 'Hittade inga JetEngine-innehållstyper att spara.' );
		}
		update_option( self::OPT_SNAPSHOT, $data, true );
		return $data;
	}

	public static function delete_snapshot() {
		delete_option( self::OPT_SNAPSHOT );
	}

	/* ---------- Registrering när JetEngine är borta ---------- */

	public static function register() {
		$snap = self::snapshot_data();
		if ( ! $snap ) {
			return;
		}
		foreach ( (array) $snap['post_types'] as $slug => $def ) {
			if ( ! post_type_exists( $slug ) ) {
				register_post_type( $slug, $def['args'] );
			}
		}
		foreach ( (array) $snap['taxonomies'] as $slug => $def ) {
			if ( ! taxonomy_exists( $slug ) ) {
				register_taxonomy( $slug, $def['object_type'], $def['args'] );
			}
		}
		// Se till att permalänkar fungerar första gången efter bytet.
		$sig = md5( wp_json_encode( array( array_keys( (array) $snap['post_types'] ), array_keys( (array) $snap['taxonomies'] ), self::jet_engine_active() ) ) );
		if ( get_option( 'cbx_rewrite_sig' ) !== $sig ) {
			update_option( 'cbx_rewrite_sig', $sig, true );
			add_action( 'wp_loaded', 'flush_rewrite_rules' );
		}
	}

	/* ---------- Enkel fältredigering ---------- */

	protected static function fields_for( $post_type ) {
		$snap = self::snapshot_data();
		if ( ! $snap ) {
			return array();
		}
		$groups = array();
		if ( ! empty( $snap['post_types'][ $post_type ]['meta_fields'] ) ) {
			$groups[] = array( 'title' => 'Fält', 'fields' => $snap['post_types'][ $post_type ]['meta_fields'] );
		}
		foreach ( (array) $snap['meta_boxes'] as $box ) {
			if ( in_array( $post_type, $box['post_types'], true ) && $box['fields'] ) {
				$groups[] = array( 'title' => $box['title'], 'fields' => $box['fields'] );
			}
		}
		return $groups;
	}

	public static function add_meta_boxes( $post_type, $post = null ) {
		if ( self::jet_engine_active() ) {
			return;
		}
		foreach ( self::fields_for( $post_type ) as $i => $group ) {
			add_meta_box(
				'cbx_fields_' . $i,
				esc_html( $group['title'] ),
				array( __CLASS__, 'render_box' ),
				$post_type,
				'normal',
				'default',
				array( 'fields' => $group['fields'] )
			);
		}
	}

	protected static function editable( array $f ) {
		return in_array( $f['type'], array( 'text', 'textarea', 'wysiwyg', 'number', 'date', 'time', 'datetime-local', 'select', 'radio', 'switcher', 'media', 'iconpicker', 'colorpicker' ), true ) && ! ( 'select' === $f['type'] && $f['multiple'] );
	}

	public static function render_box( $post, $box ) {
		wp_nonce_field( 'cbx_fields', 'cbx_fields_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $box['args']['fields'] as $f ) {
			$name  = $f['name'];
			$value = get_post_meta( $post->ID, $name, true );
			$id    = 'cbx_f_' . sanitize_key( $name );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $f['title'] ) . '</label><br><code style="font-size:11px;color:#777">' . esc_html( $name ) . '</code></th><td>';

			if ( ! self::editable( $f ) ) {
				echo '<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;background:#f6f7f7;padding:8px;margin:0">' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
				echo '<p class="description">Fälttypen "' . esc_html( $f['type'] ) . '" kan bara visas här. Värdet finns kvar i databasen.</p>';
				echo '</td></tr>';
				continue;
			}
			$input = 'cbx_fields[' . esc_attr( $name ) . ']';
			switch ( $f['type'] ) {
				case 'textarea':
				case 'wysiwyg':
					echo '<textarea class="large-text" rows="5" id="' . esc_attr( $id ) . '" name="' . $input . '">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'select':
				case 'radio':
					echo '<select id="' . esc_attr( $id ) . '" name="' . $input . '"><option value="">—</option>';
					foreach ( $f['options'] as $k => $label ) {
						echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'switcher':
					echo '<input type="hidden" name="' . $input . '" value="false"><label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . $input . '" value="true"' . checked( filter_var( $value, FILTER_VALIDATE_BOOLEAN ), true, false ) . '> Ja</label>';
					break;
				case 'media':
					echo '<input type="number" min="0" id="' . esc_attr( $id ) . '" name="' . $input . '" value="' . esc_attr( is_scalar( $value ) ? $value : '' ) . '"> ';
					if ( is_numeric( $value ) && $value ) {
						echo wp_get_attachment_image( (int) $value, array( 60, 60 ) );
					}
					echo '<p class="description">Bilagans ID i mediebiblioteket.</p>';
					break;
				case 'date':
				case 'time':
				case 'datetime-local':
					$shown = $value;
					if ( $f['is_timestamp'] && is_numeric( $value ) && $value ) {
						$fmt   = array( 'date' => 'Y-m-d', 'time' => 'H:i', 'datetime-local' => 'Y-m-d\TH:i' );
						$shown = wp_date( $fmt[ $f['type'] ], (int) $value );
					}
					echo '<input type="' . esc_attr( $f['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . $input . '" value="' . esc_attr( (string) $shown ) . '">';
					break;
				case 'number':
					echo '<input type="number" step="any" id="' . esc_attr( $id ) . '" name="' . $input . '" value="' . esc_attr( (string) $value ) . '">';
					break;
				case 'colorpicker':
					echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . $input . '" value="' . esc_attr( (string) $value ) . '" placeholder="#000000">';
					break;
				default:
					echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . $input . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Enkel ersättning för JetEngines fältredigering (Crocoblock-avveckling). Byt gärna till ACF på sikt.</p>';
	}

	public static function save_meta( $post_id, $post ) {
		if ( self::jet_engine_active() || empty( $_POST['cbx_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cbx_fields_nonce'] ) ), 'cbx_fields' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = isset( $_POST['cbx_fields'] ) && is_array( $_POST['cbx_fields'] ) ? wp_unslash( $_POST['cbx_fields'] ) : array(); // phpcs:ignore
		foreach ( self::fields_for( $post->post_type ) as $group ) {
			foreach ( $group['fields'] as $f ) {
				if ( ! self::editable( $f ) || ! array_key_exists( $f['name'], $input ) ) {
					continue;
				}
				$raw = $input[ $f['name'] ];
				switch ( $f['type'] ) {
					case 'wysiwyg':
						$val = wp_kses_post( $raw );
						break;
					case 'textarea':
						$val = sanitize_textarea_field( $raw );
						break;
					case 'number':
					case 'media':
						$val = is_numeric( $raw ) ? $raw + 0 : '';
						break;
					case 'date':
					case 'time':
					case 'datetime-local':
						$val = sanitize_text_field( $raw );
						if ( $f['is_timestamp'] && '' !== $val ) {
							$ts  = strtotime( $val );
							$val = $ts ? $ts : '';
						}
						break;
					default:
						$val = sanitize_text_field( $raw );
				}
				update_post_meta( $post_id, $f['name'], $val );
			}
		}
	}

	/* ---------- PHP-export ---------- */

	public static function php_export() {
		$snap = self::snapshot_data();
		if ( ! $snap ) {
			return '';
		}
		$code  = "<?php\n/**\n * Innehållstyper från JetEngine, exporterade av Crocoblock-avveckling " . $snap['time'] . ".\n";
		$code .= " * Lägg filen i wp-content/mu-plugins/ (eller i temats functions.php) om du vill\n * ta bort avvecklingstillägget helt.\n */\n\n";
		$code .= "add_action( 'init', function () {\n";
		foreach ( (array) $snap['post_types'] as $slug => $def ) {
			$code .= "\tif ( ! post_type_exists( " . var_export( $slug, true ) . " ) ) {\n\t\tregister_post_type( " . var_export( $slug, true ) . ', ' . self::export_array( $def['args'], 2 ) . " );\n\t}\n";
		}
		foreach ( (array) $snap['taxonomies'] as $slug => $def ) {
			$code .= "\tif ( ! taxonomy_exists( " . var_export( $slug, true ) . " ) ) {\n\t\tregister_taxonomy( " . var_export( $slug, true ) . ', ' . self::export_array( $def['object_type'], 2 ) . ', ' . self::export_array( $def['args'], 2 ) . " );\n\t}\n";
		}
		$code .= "}, 20 );\n";
		return $code;
	}

	protected static function export_array( $value, $indent ) {
		$export = var_export( $value, true );
		$export = preg_replace( '/=>\s*\n\s*array \(/', '=> array (', $export );
		$pad    = str_repeat( "\t", $indent );
		return str_replace( "\n", "\n" . $pad, $export );
	}
}
