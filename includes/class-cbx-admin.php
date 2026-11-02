<?php
/**
 * Admin-sidan: Verktyg → Crocoblock-avveckling.
 *
 * @package CrocoblockExit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CBX_Admin {

	const SLUG = 'crocoblock-exit';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'scan', 'batch', 'batch_start' ) as $a ) {
			add_action( 'wp_ajax_cbx_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		foreach ( array( 'settings', 'snapshot', 'export_php', 'csv', 'templates', 'templates_undo', 'switch', 'reactivate', 'single', 'site_css_build', 'site_css_save', 'preview', 'preview_cleanup', 'csshero_migrate', 'csshero_undo' ) as $a ) {
			add_action( 'admin_post_cbx_' . $a, array( __CLASS__, 'post_' . $a ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( CBX_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Öppna</a>' );
		return $links;
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	public static function menu() {
		add_management_page( 'Crocoblock-avveckling', 'Crocoblock-avveckling', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cbx-admin', CBX_URL . 'assets/admin.css', array(), CBX_VERSION );
		wp_enqueue_script( 'cbx-admin', CBX_URL . 'assets/admin.js', array(), CBX_VERSION, true );
		wp_enqueue_script( 'cbx-autopilot', CBX_URL . 'assets/autopilot.js', array( 'cbx-admin' ), CBX_VERSION, true );
		wp_localize_script(
			'cbx-admin',
			'CBX',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'cbx_ajax' ),
				'autoReport' => CBX_Autopilot::last_report(),
				'i18n'  => array(
					'confirmConvert' => "Sidorna skrivs om nu. Varje sida får en backup och kan återställas.\n\nHar du tagit en full backup av databasen? Fortsätt?",
					'confirmRestore' => 'Alla konverterade sidor återställs till originalet med Crocoblock. Fortsätt?',
					'working'        => 'Arbetar …',
					'done'           => 'Klart',
					'error'          => 'Något gick fel',
				),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Sidan
	 * ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$report   = CBX_Scanner::last_report();
		$settings = CBX_Plugin::settings();
		$plugins  = CBX_Plugin::jet_plugins();
		$active   = array_filter( $plugins, function ( $p ) { return $p['active']; } );
		$notice   = isset( $_GET['cbx_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['cbx_notice'] ) ) : ''; // phpcs:ignore
		?>
		<div class="wrap cbx">
			<h1>Crocoblock-avveckling</h1>
			<p class="cbx-lead">Byter Crocoblock mot Elementor Pro på den här sajten – med samma utseende. Tre steg, allt kontrolleras automatiskt och kan ångras.</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div class="cbx-status">
				<?php self::pill( 'Elementor Pro', CBX_Plugin::has_pro() ? ELEMENTOR_PRO_VERSION : 'saknas', CBX_Plugin::has_pro() ? 'ok' : 'bad' ); ?>
				<?php self::pill( 'Crocoblock-tillägg på', (string) count( $active ), $active ? 'warn' : 'ok' ); ?>
				<?php self::pill( 'Konverterade sidor', (string) count( CBX_Plugin::backup_post_ids() ), 'neutral' ); ?>
				<?php if ( CBX_CSS_Hero::plugin() || CBX_CSS_Hero::backup() ) : ?>
					<?php self::pill( 'CSS Hero', CBX_CSS_Hero::backup() ? 'avvecklat' : 'på', CBX_CSS_Hero::backup() ? 'ok' : 'warn' ); ?>
				<?php endif; ?>
			</div>

			<?php if ( ! CBX_Plugin::has_pro() ) : ?>
				<div class="notice notice-warning inline"><p><strong>Elementor Pro är inte aktivt.</strong> De flesta ersättningar kräver Pro. Utan Pro konverteras bara det som går med gratisversionen.</p></div>
			<?php endif; ?>

			<?php self::flow( $active ); ?>

			<details class="cbx-advanced"<?php echo isset( $_GET['cbx_adv'] ) || $notice ? ' open' : ''; // phpcs:ignore ?>>
				<summary>Avancerat – alla steg för hand</summary>
				<p class="description">Samma arbete som ovan, uppdelat i de enskilda stegen: inventering med CSV, förhandsgranskning per sida, konvertering av en enskild sida, sajtens CSS, CSS Hero och återställning.</p>
				<?php self::step_scan( $report ); ?>
				<?php self::step_content( $report ); ?>
				<?php self::step_templates( $report ); ?>
				<?php self::step_site_css(); ?>
				<?php self::step_csshero(); ?>
				<?php self::step_pages( $settings ); ?>
				<?php self::step_switch( $plugins, $report ); ?>
				<?php self::step_restore(); ?>
			</details>
		</div>
		<?php
	}

	protected static function pill( $label, $value, $state ) {
		printf( '<span class="cbx-pill cbx-pill--%s"><span>%s</span><strong>%s</strong></span>', esc_attr( $state ), esc_html( $label ), esc_html( $value ) );
	}

	protected static function card_open( $n, $title, $intro = '' ) {
		echo '<section class="cbx-card"><header><span class="cbx-step">' . (int) $n . '</span><h2>' . esc_html( $title ) . '</h2></header>';
		if ( $intro ) {
			echo '<p class="cbx-intro">' . wp_kses_post( $intro ) . '</p>';
		}
	}

	protected static function card_close() {
		echo '</section>';
	}

	protected static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cbx_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'cbx_' . $action );
	}

	/* ---------- Förenklad arbetsgång: Testa → Genomför → Stäng av ---------- */

	protected static function flow( array $active ) {
		$converted = count( CBX_Plugin::backup_post_ids() );
		$last      = CBX_Autopilot::last_report();
		$tested    = $last && in_array( isset( $last['mode'] ) ? $last['mode'] : '', array( 'test', 'apply' ), true ) ? $last : null;
		$level     = $tested && isset( $tested['verdictLevel'] ) ? $tested['verdictLevel'] : '';
		$remaining = $active ? CBX_Scanner::remaining() : array( 'count' => 0, 'posts' => array(), 'templates' => array() );
		$blocked   = $remaining['count'] || $remaining['templates'];
		$hero      = CBX_CSS_Hero::plugin() && ! CBX_CSS_Hero::backup();
		$badges    = array( 'ok' => array( 'Godkänt', 'ok' ), 'warn' => array( 'Avvikelser', 'warn' ), 'bad' => array( 'Stoppat', 'bad' ), 'neutral' => array( 'Klart', 'neutral' ) );

		echo '<section class="cbx-flow" data-cbx-auto>';
		echo '<ol class="cbx-flow-steps">';

		// 1. Testa.
		echo '<li class="cbx-flow-step' . ( 'ok' === $level ? ' is-done' : '' ) . '"><span class="cbx-flow-num">1</span><div class="cbx-flow-body"><h2>Testa</h2>';
		echo '<p>Går igenom sajten och jämför varje sida som ändras – före och efter, i dator-, surfplatte- och mobilbredd. <strong>Ingenting ändras på sajten.</strong> Tar några minuter.</p>';
		if ( $tested ) {
			$b = isset( $badges[ $level ] ) ? $badges[ $level ] : $badges['neutral'];
			echo '<p class="cbx-flow-last"><span class="cbx-badge cbx-badge--' . esc_attr( $b[1] ) . '">' . esc_html( $b[0] ) . '</span> ' . esc_html( isset( $tested['verdict'] ) ? $tested['verdict'] : '' ) . ' <span class="cbx-meta">' . esc_html( isset( $tested['finished'] ) ? $tested['finished'] : '' ) . '</span></p>';
		}
		echo '</div><div class="cbx-flow-action"><button type="button" class="button button-primary button-hero" data-cbx-auto-run="test">Testa</button></div></li>';

		// 2. Genomför.
		echo '<li class="cbx-flow-step' . ( $converted ? ' is-done' : '' ) . '"><span class="cbx-flow-num">2</span><div class="cbx-flow-body"><h2>Genomför</h2>';
		echo '<p>Testar igen, konverterar sidorna och kontrollerar att den publicerade sajten ser likadan ut. <strong>Vid minsta fel återställs allt automatiskt.</strong> Crocoblock är kvar och påslaget.</p>';
		if ( $converted ) {
			echo '<p class="cbx-flow-last"><span class="cbx-badge cbx-badge--ok">Klart</span> ' . (int) $converted . ' sidor och mallar är konverterade.</p>';
		}
		echo '<p class="cbx-flow-opts">';
		if ( $hero ) {
			echo '<label><input type="checkbox" name="cbx_auto_csshero" value="1" checked> Avveckla även CSS Hero (dess CSS flyttas till WordPress)</label>';
		}
		echo '<label><input type="checkbox" name="cbx_auto_allow_warn" value="1"> Godta små avvikelser</label></p>';
		echo '</div><div class="cbx-flow-action"><button type="button" class="button ' . ( 'ok' === $level && ! $converted ? 'button-primary ' : '' ) . 'button-hero" data-cbx-auto-run="apply">Genomför</button></div></li>';

		// 3. Stäng av Crocoblock.
		echo '<li class="cbx-flow-step' . ( ! $active ? ' is-done' : '' ) . '"><span class="cbx-flow-num">3</span><div class="cbx-flow-body"><h2>Stäng av Crocoblock</h2>';
		if ( ! $active ) {
			echo '<p class="cbx-flow-last"><span class="cbx-badge cbx-badge--ok">Klart</span> Crocoblock är avstängt. Ta bort tilläggen när sajten fungerat ett tag – men låt det här tillägget vara kvar.</p>';
		} elseif ( $blocked ) {
			$names = array();
			foreach ( array_slice( array_keys( $remaining['posts'] ), 0, 5 ) as $id ) {
				$names[] = get_the_title( $id );
			}
			$names = array_merge( $names, array_slice( $remaining['templates'], 0, 3 ) );
			echo '<p>Går inte ännu: ' . esc_html( sprintf( '%d Crocoblock-delar används fortfarande', $remaining['count'] + count( $remaining['templates'] ) ) ) . ' (' . esc_html( implode( ', ', $names ) ) . ( count( $remaining['posts'] ) > 5 ? ' …' : '' ) . '). ' . ( $converted ? 'De kan inte konverteras automatiskt – bygg om dem för hand eller ta bort dem.' : 'Kör steg 2 först.' ) . '</p>';
		} else {
			echo '<p>Stänger av ' . count( $active ) . ' Crocoblock-tillägg och publicerar de nya sidhuvudena, sidfötterna och popuperna. Sajten jämförs med hur den såg ut innan – <strong>skiljer något slås Crocoblock på igen automatiskt.</strong></p>';
		}
		echo '</div><div class="cbx-flow-action"><button type="button" class="button button-hero" data-cbx-auto-run="switch"' . ( ! $active || $blocked ? ' disabled' : '' ) . '>Stäng av</button></div></li>';
		echo '</ol>';

		echo '<div class="cbx-flow-run" data-cbx-auto-box hidden><div class="cbx-progress"><div class="cbx-progress__bar"></div><span class="cbx-progress__text" data-cbx-auto-now></span></div>';
		echo '<details class="cbx-flow-details"><summary>Visa alla delsteg</summary><ol class="cbx-auto-steps" data-cbx-auto-steps></ol></details></div>';
		echo '<div class="cbx-auto-report" data-cbx-auto-report></div>';
		echo '<p class="cbx-flow-undo">Blev något fel? <button type="button" class="button-link cbx-danger" data-cbx-auto-run="undo">Ångra allt</button> – lägger tillbaka alla sidor, mallar och CSS Hero och slår på Crocoblock igen.</p>';
		echo '</section>';
	}

	/* ---------- Steg 1: Inventering ---------- */

	protected static function step_scan( $report ) {
		self::card_open( 1, 'Inventering', 'Går igenom alla sidor, mallar och inlägg med Elementor-innehåll och visar exakt vad som använder Crocoblock och vad som kan ersättas automatiskt.' );
		echo '<p class="cbx-actions"><button type="button" class="button button-primary" data-cbx-scan>Kör inventering</button>';
		if ( $report ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cbx_csv' ), 'cbx_csv' ) ) . '">Ladda ner rapport (CSV)</a>';
		}
		echo '</p><div class="cbx-progress" data-cbx-progress="scan" hidden><div class="cbx-progress__bar"></div><span class="cbx-progress__text"></span></div>';
		echo '<div data-cbx-report>';
		if ( $report ) {
			echo self::render_report( $report ); // phpcs:ignore
		} else {
			echo '<p class="cbx-empty">Ingen inventering gjord ännu.</p>';
		}
		echo '</div>';
		self::card_close();
	}

	public static function render_report( array $report ) {
		$t = CBX_Scanner::totals( $report );
		ob_start();
		?>
		<p class="cbx-meta">Senaste inventering <?php echo esc_html( $report['time'] ); ?> · <?php echo (int) $report['scanned']; ?> dokument genomsökta.</p>
		<div class="cbx-stats">
			<div><strong><?php echo (int) $t['widgets']; ?></strong><span>Crocoblock-widgets</span></div>
			<div class="ok"><strong><?php echo (int) $t['auto']; ?></strong><span>ersätts automatiskt</span></div>
			<div class="<?php echo $t['manual'] ? 'warn' : 'ok'; ?>"><strong><?php echo (int) $t['manual']; ?></strong><span>kräver handpåläggning</span></div>
			<div><strong><?php echo (int) $t['pages']; ?></strong><span>sidor berörs</span></div>
			<div><strong><?php echo (int) $t['ext']; ?></strong><span>tillägg på element</span></div>
			<div><strong><?php echo (int) ( $t['tags'] + $t['shortcodes'] ); ?></strong><span>taggar &amp; kortkoder</span></div>
		</div>

		<?php if ( ! $t['widgets'] && ! $t['ext'] && ! $t['tags'] && ! $t['shortcodes'] && ! $report['blocks'] ) : ?>
			<p class="cbx-good">Inget innehåll använder Crocoblock längre. Kontrollera steg 2–5 och gå sedan till steg 7.</p>
		<?php endif; ?>

		<?php if ( $report['widgets'] ) : ?>
			<h3>Widgets</h3>
			<table class="widefat striped cbx-table">
				<thead><tr><th>Widget</th><th class="num">Antal</th><th class="num">Auto</th><th class="num">Manuellt</th><th>Används på</th><th>Råd</th></tr></thead>
				<tbody>
				<?php foreach ( $report['widgets'] as $type => $w ) : ?>
					<tr>
						<td><code><?php echo esc_html( $type ); ?></code></td>
						<td class="num"><?php echo (int) $w['count']; ?></td>
						<td class="num ok"><?php echo (int) $w['auto']; ?></td>
						<td class="num <?php echo $w['manual'] ? 'warn' : ''; ?>"><?php echo (int) $w['manual']; ?></td>
						<td><?php echo self::post_links( $w['posts'] ); // phpcs:ignore ?></td>
						<td class="cbx-hint"><?php echo esc_html( $w['hint'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		$groups = array(
			'extensions' => array( 'Tillägg på sektioner, kolumner och widgets', 'Tas bort vid konvertering; sticky blir Elementor Pros sticky och popup-kopplingar på knappar/bilder kopplas om.' ),
			'tags'       => array( 'Dynamiska taggar från JetEngine', 'Metafält blir Elementor Pros "Post Custom Field"; övriga tas bort och visar sitt fasta värde.' ),
			'shortcodes' => array( 'Kortkoder', 'Konverteras inte automatiskt – byt ut manuellt innan Crocoblock avaktiveras.' ),
		);
		foreach ( $groups as $key => $info ) :
			if ( empty( $report[ $key ] ) ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( $info[0] ); ?></h3>
			<p class="description"><?php echo esc_html( $info[1] ); ?></p>
			<table class="widefat striped cbx-table">
				<thead><tr><th>Namn</th><th class="num">Antal</th><th>Används på</th></tr></thead>
				<tbody>
				<?php foreach ( $report[ $key ] as $name => $row ) : ?>
					<tr><td><?php echo 'extensions' === $key ? esc_html( $name ) : '<code>' . esc_html( $name ) . '</code>'; ?></td><td class="num"><?php echo (int) $row['count']; ?></td><td><?php echo self::post_links( $row['posts'] ); // phpcs:ignore ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<?php if ( ! empty( $report['site_css'] ) ) : ?>
			<h3>Sajtens egen CSS som pekar på Crocoblock</h3>
			<p class="description">Översätts i steg 4.</p>
			<ul class="cbx-list">
				<?php foreach ( $report['site_css'] as $src ) : ?>
					<li><?php echo esc_html( $src['label'] ); ?>: <?php echo (int) $src['translated']; ?> regler<?php echo $src['untranslated'] ? ', ' . (int) $src['untranslated'] . ' utan motsvarighet' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $report['csshero'] ) ) : $h = $report['csshero']; ?>
			<h3>CSS Hero</h3>
			<p><?php echo $h['moved'] ? 'Flyttad till Ytterligare CSS.' : sprintf( '%s · %d regler, varav %d pekar på Crocoblock och %d är sköra. Avvecklas i steg 5.', $h['active'] ? 'Aktivt' : 'Inaktivt', (int) $h['rules'], (int) $h['jet'], (int) $h['fragile'] ); ?></p>
		<?php endif; ?>

		<?php if ( $report['blocks'] || $report['wp_widgets'] ) : ?>
			<h3>Utanför Elementor</h3>
			<ul class="cbx-list">
				<?php if ( $report['blocks'] ) : ?>
					<li><?php echo (int) $report['blocks']; ?> inlägg/sidor innehåller Gutenberg-block från Crocoblock (<code>wp:jet-…</code>). Byts ut manuellt.</li>
				<?php endif; ?>
				<?php foreach ( $report['wp_widgets'] as $w ) : ?>
					<li>WordPress-widget: <code><?php echo esc_html( $w ); ?></code> (Utseende → Widgets)</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $report['posts'] ) : ?>
			<details class="cbx-details">
				<summary>Alla berörda sidor (<?php echo count( $report['posts'] ); ?>)</summary>
				<table class="widefat striped cbx-table">
					<thead><tr><th>Sida</th><th class="num">Widgets</th><th class="num">Auto</th><th class="num">Manuellt</th><th class="num">Tillägg</th><th>Status</th></tr></thead>
					<tbody>
					<?php foreach ( $report['posts'] as $id => $p ) : ?>
						<tr>
							<td><?php echo self::post_link( $id ); // phpcs:ignore ?></td>
							<td class="num"><?php echo (int) array_sum( $p['widgets'] ); ?></td>
							<td class="num ok"><?php echo (int) $p['auto']; ?></td>
							<td class="num <?php echo $p['manual'] ? 'warn' : ''; ?>"><?php echo (int) $p['manual']; ?></td>
							<td class="num"><?php echo (int) $p['ext']; ?></td>
							<td><?php echo $p['retired'] ? 'Crocoblock-mall (steg 3)' : ( $p['migrated'] ? 'Konverterad' : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	protected static function post_link( $id ) {
		$view = CBX_Plugin::view_link( $id );
		$edit = CBX_Plugin::edit_link( $id );
		$html = esc_html( CBX_Plugin::post_label( $id ) );
		if ( $view ) {
			$html = '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">' . $html . '</a>';
		}
		if ( $edit ) {
			$html .= ' <a class="cbx-edit" href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">redigera</a>';
		}
		return $html;
	}

	protected static function post_links( array $ids ) {
		$out = array();
		foreach ( array_slice( $ids, 0, 6 ) as $id ) {
			$post  = get_post( $id );
			$title = $post && '' !== $post->post_title ? $post->post_title : '#' . $id;
			$edit  = CBX_Plugin::edit_link( $id );
			$out[] = $edit ? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>' : esc_html( $title );
		}
		if ( count( $ids ) > 6 ) {
			$out[] = '+' . ( count( $ids ) - 6 ) . ' till';
		}
		return implode( ', ', $out );
	}

	/* ---------- Steg 2: Innehållstyper ---------- */

	protected static function step_content( $report ) {
		$inv  = $report ? $report['content'] : CBX_Content_Types::inventory();
		$snap = CBX_Content_Types::snapshot_data();
		self::card_open( 2, 'Innehållstyper (JetEngine)', 'Egna inläggstyper och taxonomier från JetEngine försvinner ur WordPress när JetEngine avaktiveras – innehållet ligger kvar men syns inte. Spara en ögonblicksbild, så registrerar det här tillägget dem i stället. Metafälten får en enkel redigeringsruta.' );

		if ( ! $inv['post_types'] && ! $inv['taxonomies'] && ! $inv['meta_boxes'] && ! $snap ) {
			echo '<p class="cbx-good">Inga JetEngine-innehållstyper hittades. Inget att göra här.</p>';
			self::card_close();
			return;
		}

		echo '<table class="widefat striped cbx-table"><thead><tr><th>Typ</th><th>Namn</th><th>Slug</th><th class="num">Poster</th></tr></thead><tbody>';
		foreach ( $inv['post_types'] as $slug => $def ) {
			$name = isset( $def['args']['labels']['name'] ) ? $def['args']['labels']['name'] : $slug;
			echo '<tr><td>Inläggstyp</td><td>' . esc_html( $name ) . '</td><td><code>' . esc_html( $slug ) . '</code></td><td class="num">' . (int) ( isset( $inv['post_counts'][ $slug ] ) ? $inv['post_counts'][ $slug ] : 0 ) . '</td></tr>';
		}
		foreach ( $inv['taxonomies'] as $slug => $def ) {
			$name = isset( $def['args']['labels']['name'] ) ? $def['args']['labels']['name'] : $slug;
			echo '<tr><td>Taxonomi</td><td>' . esc_html( $name ) . ' <span class="description">(' . esc_html( implode( ', ', $def['object_type'] ) ) . ')</span></td><td><code>' . esc_html( $slug ) . '</code></td><td></td></tr>';
		}
		foreach ( $inv['meta_boxes'] as $box ) {
			echo '<tr><td>Metafält</td><td>' . esc_html( $box['title'] ) . ' <span class="description">(' . count( $box['fields'] ) . ' fält)</span></td><td>' . esc_html( implode( ', ', $box['post_types'] ) ) . '</td><td></td></tr>';
		}
		echo '</tbody></table>';

		$warn = array();
		if ( $inv['cct_tables'] ) {
			$warn[] = sprintf( 'Custom Content Types (%d tabeller) ligger i egna databastabeller och kan inte visas utan JetEngine. Exportera eller flytta dem till inlägg manuellt.', count( $inv['cct_tables'] ) );
		}
		if ( $inv['relations'] ) {
			$warn[] = 'JetEngine-relationer finns. Kopplingarna ligger kvar i databasen men används inte längre automatiskt.';
		}
		if ( $inv['options_pages'] ) {
			$warn[] = 'Options-sidor finns. Deras värden ligger kvar i wp_options men saknar redigeringsgränssnitt.';
		}
		foreach ( $warn as $w ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
		}

		echo '<div class="cbx-actions">';
		self::form_open( 'snapshot' );
		submit_button( $snap ? 'Uppdatera ögonblicksbild' : 'Spara ögonblicksbild', 'primary', 'submit', false );
		echo '</form>';
		if ( $snap ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cbx_export_php' ), 'cbx_export_php' ) ) . '">Ladda ner som PHP-kod</a>';
			echo ' <span class="cbx-meta">Sparad ' . esc_html( $snap['time'] ) . ' · ' . count( $snap['post_types'] ) . ' inläggstyper, ' . count( $snap['taxonomies'] ) . ' taxonomier</span>';
		}
		echo '</div>';
		self::card_close();
	}

	/* ---------- Steg 3: Mallar ---------- */

	protected static function step_templates( $report ) {
		$inv = CBX_Templates::inventory();
		self::card_open( 3, 'Mallar', 'Kopierar JetEngine-listningar till Loop Item-mallar, JetPopup till Elementor-popuper och JetThemeCore till Theme Builder-mallar. Originalen lämnas orörda. Sidhuvud/sidfot och popuper skapas som utkast och aktiveras först i steg 7. Utseendet följer med på samma sätt som för sidor.' );

		$sets = array(
			'listings' => array( 'JetEngine-listningar', 'Loop Item' ),
			'popups'   => array( 'JetPopup', 'Popup' ),
			'theme'    => array( 'JetThemeCore', 'Theme Builder' ),
		);
		$any = false;
		echo '<table class="widefat striped cbx-table"><thead><tr><th>Källa</th><th>Mall</th><th>Typ</th><th>Ny Elementor-mall</th></tr></thead><tbody>';
		foreach ( $sets as $key => $labels ) {
			foreach ( $inv[ $key ] as $id => $row ) {
				$any  = true;
				$type = isset( $row['type'] ) ? $row['type'] : ( isset( $row['trigger'] ) ? 'utlösare: ' . $row['trigger'] : '' );
				$new  = $row['migrated'] ? '<a href="' . esc_url( CBX_Plugin::edit_link( $row['migrated'] ) ) . '" target="_blank" rel="noopener">#' . (int) $row['migrated'] . ' ' . esc_html( get_post_status( $row['migrated'] ) ) . '</a>' : '—';
				echo '<tr><td>' . esc_html( $labels[0] ) . '</td><td>' . esc_html( $row['title'] ) . ' <span class="description">#' . (int) $id . ', ' . esc_html( $row['status'] ) . '</span></td><td>' . esc_html( $type ) . '</td><td>' . $new . '</td></tr>'; // phpcs:ignore
			}
		}
		if ( ! $any ) {
			echo '<tr><td colspan="4">Inga Crocoblock-mallar hittades.</td></tr>';
		}
		echo '</tbody></table>';

		if ( $inv['menu_items'] ) {
			echo '<div class="notice notice-warning inline"><p>' . (int) $inv['menu_items'] . ' menypunkter har JetMenu-inställningar (mega-meny). De försvinner – bygg om som vanliga undermenyer eller med Elementor Pros Mega Menu.</p></div>';
		}
		if ( $inv['filters'] ) {
			echo '<div class="notice notice-warning inline"><p>' . (int) $inv['filters'] . ' JetSmartFilters-filter finns. De ersätts inte automatiskt.</p></div>';
		}

		$last = get_option( 'cbx_last_template_run' );
		if ( $last ) {
			echo '<details class="cbx-details"' . ( isset( $_GET['cbx_tpl'] ) ? ' open' : '' ) . '><summary>Resultat från senaste migreringen (' . esc_html( $last['time'] ) . ')</summary><ul class="cbx-list">'; // phpcs:ignore
			foreach ( $last['results'] as $key => $rows ) {
				foreach ( $rows as $r ) {
					echo '<li><strong>' . esc_html( $sets[ $key ][0] . ': ' . ( $r['title'] ? $r['title'] : '#' . $r['from'] ) ) . '</strong> – ' . esc_html( self::state_label( $r['state'] ) );
					if ( $r['notes'] ) {
						echo '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $r['notes'] ) ) . '</li></ul>';
					}
					echo '</li>';
				}
			}
			echo '</ul></details>';
		}

		if ( $any ) {
			echo '<div class="cbx-actions">';
			self::form_open( 'templates' );
			submit_button( 'Migrera mallar', 'primary', 'submit', false, CBX_Plugin::has_pro() ? array() : array( 'disabled' => 'disabled' ) );
			echo '</form></div>';
		}
		self::card_close();
	}

	protected static function state_label( $state ) {
		$labels = array( 'migrated' => 'migrerad', 'skipped' => 'redan migrerad', 'refreshed' => 'innehåll uppdaterat', 'manual' => 'manuellt', 'error' => 'fel' );
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/* ---------- Steg 4: Sajtens egen CSS ---------- */

	protected static function step_site_css() {
		$d = CBX_Site_CSS::data();
		self::card_open( 4, 'Sajtens egen CSS', 'Stil som skrivits direkt mot Crocoblocks klasser – i temat, egna tillägg, CSS Hero, Utseende → Anpassa eller Elementors Custom CSS – träffar inget efter bytet. Här skapas översatta kopior som gäller de nya widgetarna. Originalen rörs inte, och kopiorna påverkar bara konverterade widgetar, så de kan vara på redan före konverteringen.' );
		echo '<div class="cbx-actions">';
		self::form_open( 'site_css_build' );
		submit_button( $d['time'] ? 'Sök och översätt igen' : 'Sök och översätt', 'primary', 'submit', false );
		echo '</form>';
		if ( $d['time'] ) {
			echo ' <span class="cbx-meta">Senast ' . esc_html( $d['time'] ) . ( $d['enabled'] ? ' · aktiv' : ' · avstängd' ) . '</span>';
		}
		echo '</div>';

		if ( $d['time'] && ! $d['sources'] ) {
			echo '<p class="cbx-good">Ingen egen CSS pekar på Crocoblocks klasser.</p>';
		}
		if ( $d['sources'] ) {
			echo '<table class="widefat striped cbx-table"><thead><tr><th>Källa</th><th class="num">Översatta regler</th><th>Utan motsvarighet</th></tr></thead><tbody>';
			foreach ( $d['sources'] as $src ) {
				echo '<tr><td>' . esc_html( $src['label'] ) . '</td><td class="num ok">' . (int) $src['translated'] . '</td><td class="cbx-hint">';
				if ( $src['untranslated'] ) {
					echo '<code>' . implode( '</code>, <code>', array_map( 'esc_html', array_slice( $src['untranslated'], 0, 8 ) ) ) . '</code>' . ( count( $src['untranslated'] ) > 8 ? ' …' : '' );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $d['time'] ) {
			self::form_open( 'site_css_save' );
			echo '<details class="cbx-details"><summary>Visa och komplettera CSS:en</summary>';
			echo '<p class="description">Genererad CSS (skrivs om vid nästa sökning):</p><textarea class="large-text code" rows="10" readonly>' . esc_textarea( $d['css'] ) . '</textarea>';
			echo '<p class="description">Egna tillägg (sparas och behålls):</p><textarea name="extra" class="large-text code" rows="5">' . esc_textarea( $d['extra'] ) . '</textarea>';
			echo '</details>';
			echo '<fieldset class="cbx-options"><label><input type="checkbox" name="enabled" value="1"' . checked( $d['enabled'], 1, false ) . '> Skriv ut den översatta CSS:en på sajten</label></fieldset>';
			submit_button( 'Spara', 'secondary', 'submit', false );
			echo '</form>';
		}
		self::card_close();
	}

	/* ---------- Steg 5: CSS Hero ---------- */

	protected static function step_csshero() {
		$st = CBX_CSS_Hero::status();
		if ( ! $st ) {
			return;
		}
		self::card_open( 5, 'CSS Hero', 'Flyttar all CSS som CSS Hero levererar till WordPress egen Ytterligare CSS (Utseende → Anpassa) och avaktiverar CSS Hero. Utseendet blir detsamma, men CSS:en ligger nu i WordPress och kan redigeras där. Regler som pekar på Crocoblock översätts sedan i steg 4.' );
		$p = $st['plugin'];
		$a = $st['analysis'];
		if ( $st['backup'] ) {
			echo '<p class="cbx-good">CSS Hero flyttades ' . esc_html( $st['backup']['time'] ) . ( $p && ! $p['active'] ? ' och är avaktiverat' : '' ) . '. CSS:en ligger i <a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=custom_css' ) ) . '">Utseende → Anpassa → Ytterligare CSS</a> mellan markeringarna <code>cbx:csshero</code>.</p>';
			echo '<p class="cbx-meta">Kör steg 4 igen så att eventuella Crocoblock-regler i den flyttade CSS:en översätts.</p>';
			self::card_close();
			return;
		}
		if ( ! $p['active'] ) {
			echo '<p>CSS Hero ' . esc_html( $p['version'] ) . ' är installerat men inte aktivt, så det levererar ingen CSS. Det kan tas bort under Tillägg.</p>';
			self::card_close();
			return;
		}
		if ( '' === $st['css'] ) {
			echo '<div class="notice notice-warning inline"><p>CSS Hero ' . esc_html( $p['version'] ) . ' är aktivt men ingen CSS hittades. Öppna CSS Hero och spara en gång, eller kontrollera att sajten svarar.</p></div>';
			self::card_close();
			return;
		}
		echo '<div class="cbx-stats">';
		echo '<div><strong>' . (int) $a['rules'] . '</strong><span>regler</span></div>';
		echo '<div><strong>' . (int) $a['jet'] . '</strong><span>pekar på Crocoblock</span></div>';
		echo '<div class="' . ( $a['fragile'] ? 'warn' : 'ok' ) . '"><strong>' . count( $a['fragile'] ) . '</strong><span>sköra selektorer</span></div>';
		echo '<div><strong>' . (int) ( $a['imports'] + $a['fonts'] ) . '</strong><span>typsnitt/import</span></div>';
		echo '</div>';
		echo '<p class="cbx-meta">Källa: ' . esc_html( implode( ', ', $st['sources'] ) ) . '</p>';
		if ( $a['fragile'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>Sköra selektorer.</strong> De här reglerna pekar på en position i sidans struktur (t.ex. <code>div:nth-child(2)</code>) och slutar gälla så fort layouten ändras – även efter konverteringen. De flyttas med, men byt gärna till en CSS-klass på elementet i Elementor:</p><ul class="cbx-list">';
			foreach ( array_slice( $a['fragile'], 0, 10 ) as $sel ) {
				echo '<li><code>' . esc_html( $sel ) . '</code></li>';
			}
			echo '</ul></div>';
		}
		echo '<details class="cbx-details"><summary>Visa CSS:en som flyttas</summary><textarea class="large-text code" rows="12" readonly>' . esc_textarea( $st['css'] ) . '</textarea></details>';
		echo '<div class="cbx-actions">';
		self::form_open( 'csshero_migrate' );
		submit_button( 'Flytta till Ytterligare CSS och avaktivera CSS Hero', 'primary', 'submit', false, array( 'onclick' => "return confirm('CSS Heros CSS flyttas till Utseende → Anpassa → Ytterligare CSS och CSS Hero avaktiveras. Det går att ångra. Fortsätt?');" ) );
		echo '</form></div>';
		self::card_close();
	}

	/* ---------- Steg 6: Sidor ---------- */

	protected static function step_pages( array $settings ) {
		self::card_open( 6, 'Konvertera sidor', 'Ersätter widgets och tillägg på alla sidor, inlägg och Elementor-mallar – och för över Crocoblocks stil (färger, typsnitt, avstånd, hover, mobil) till de nya widgetarna. Börja med en provkörning: den ändrar ingenting. Klicka på "Förhandsgranska" vid en sida för att se en konverterad kopia bredvid originalet.' );
		self::form_open( 'settings' );
		echo '<fieldset class="cbx-options">';
		echo '<label><input type="checkbox" name="clean_ext" value="1"' . checked( $settings['clean_ext'], 1, false ) . '> Ta bort Crocoblock-tillägg på sektioner, kolumner och widgets (sticky, parallax, tooltips, synlighetsvillkor)</label>';
		echo '<label><input type="checkbox" name="include_drafts" value="1"' . checked( $settings['include_drafts'], 1, false ) . '> Ta med utkast och privata sidor</label>';
		echo '</fieldset>';
		submit_button( 'Spara val', 'secondary', 'submit', false );
		echo '</form>';

		echo '<p class="cbx-actions"><button type="button" class="button button-primary" data-cbx-batch="dry">Provkör</button> ';
		echo '<button type="button" class="button" data-cbx-batch="convert" data-cbx-confirm="confirmConvert">Konvertera</button></p>';
		echo '<div class="cbx-progress" data-cbx-progress="pages" hidden><div class="cbx-progress__bar"></div><span class="cbx-progress__text"></span></div>';
		echo '<div class="cbx-summary" data-cbx-summary="pages"></div>';
		echo '<div class="cbx-results" data-cbx-results="pages"></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cbx-single">';
		echo '<input type="hidden" name="action" value="cbx_single">';
		wp_nonce_field( 'cbx_single' );
		echo '<label>Konvertera en enskild sida: <input type="number" name="post_id" min="1" placeholder="ID" class="small-text"></label> ';
		submit_button( 'Konvertera sidan', 'secondary small', 'submit', false );
		echo '</form>';
		$previews = count( CBX_Preview::all() );
		if ( $previews ) {
			echo '<div class="cbx-actions">';
			self::form_open( 'preview_cleanup' );
			submit_button( sprintf( 'Ta bort förhandsvisningar (%d)', $previews ), 'secondary small', 'submit', false );
			echo '</form></div>';
		}
		self::card_close();
	}

	/* ---------- Steg 5: Byt ---------- */

	protected static function step_switch( array $plugins, $report ) {
		self::card_open( 7, 'Slutför bytet', 'Publicerar migrerade sidhuvuden, sidfötter och popuper och avaktiverar Crocoblock-tilläggen i samma steg. Kontrollera sajten direkt efteråt. Ångrar du dig finns steg 8.' );

		$left = array();
		if ( $report ) {
			$t = CBX_Scanner::totals( $report );
			if ( $t['manual'] ) {
				$left[] = $t['manual'] . ' widgets behöver byggas om manuellt';
			}
			if ( $t['shortcodes'] ) {
				$left[] = $t['shortcodes'] . ' kortkoder behöver bytas ut';
			}
			if ( $report['blocks'] ) {
				$left[] = $report['blocks'] . ' sidor har Crocoblock-block i Gutenberg';
			}
			$inv = $report['content'];
			if ( ( $inv['post_types'] || $inv['taxonomies'] ) && ! CBX_Content_Types::snapshot_data() ) {
				$left[] = 'ögonblicksbild av innehållstyperna saknas (steg 2)';
			}
		} else {
			$left[] = 'ingen inventering gjord (steg 1)';
		}
		if ( $left ) {
			echo '<div class="notice notice-warning inline"><p><strong>Kvar att göra enligt senaste inventeringen:</strong> ' . esc_html( implode( '; ', $left ) ) . '. Kör inventeringen igen efter konverteringen för en aktuell bild.</p></div>';
		}

		$active    = array_filter( $plugins, function ( $p ) { return $p['active']; } );
		$remaining = $active ? CBX_Scanner::remaining() : array( 'count' => 0, 'posts' => array(), 'templates' => array() );
		if ( $remaining['count'] || $remaining['templates'] ) {
			echo '<div class="notice notice-error inline"><p><strong>Stopp: Crocoblock används fortfarande.</strong> Om tilläggen avaktiveras nu försvinner de här delarna från sajten helt (Elementor visar inget för widgets det inte känner igen). Konvertera först i steg 6, och bygg om det som inte kan konverteras.</p><ul class="cbx-list">';
			foreach ( array_slice( $remaining['posts'], 0, 15, true ) as $id => $types ) {
				$list = array();
				foreach ( $types as $type => $n ) {
					$list[] = $type . ( $n > 1 ? ' ×' . $n : '' );
				}
				echo '<li>' . self::post_link( $id ) . ': <code>' . esc_html( implode( ', ', $list ) ) . '</code></li>'; // phpcs:ignore
			}
			if ( count( $remaining['posts'] ) > 15 ) {
				echo '<li>… och ' . ( count( $remaining['posts'] ) - 15 ) . ' sidor till</li>';
			}
			foreach ( $remaining['templates'] as $t ) {
				echo '<li>' . esc_html( $t ) . ' – inte migrerad (steg 3)</li>';
			}
			echo '</ul></div>';
		}
		if ( ! $active ) {
			echo '<p class="cbx-good">Inga Crocoblock-tillägg är aktiva.</p>';
			if ( get_option( 'cbx_templates_activated' ) ) {
				echo '<p class="cbx-meta">Mallarna aktiverades ' . esc_html( get_option( 'cbx_templates_activated' ) ) . '.</p>';
			}
			self::card_close();
			return;
		}
		self::form_open( 'switch' );
		echo '<fieldset class="cbx-options"><legend>Avaktivera:</legend>';
		foreach ( $active as $file => $p ) {
			echo '<label><input type="checkbox" name="plugins[]" value="' . esc_attr( $file ) . '" checked> ' . esc_html( $p['name'] ) . ' <span class="description">' . esc_html( $p['version'] ) . '</span></label>';
		}
		echo '<label><input type="checkbox" name="activate_templates" value="1" checked> Publicera migrerade sidhuvuden, sidfötter och popuper</label>';
		if ( $remaining['count'] || $remaining['templates'] ) {
			echo '<label class="cbx-force"><input type="checkbox" name="force" value="1"> Jag vet att delarna ovan slutar visas och vill avaktivera ändå</label>';
		}
		echo '</fieldset>';
		submit_button( 'Slutför bytet', 'primary', 'submit', false, array( 'onclick' => "return confirm('Crocoblock avaktiveras nu och de nya mallarna publiceras. Fortsätt?');" ) );
		echo '</form>';
		self::card_close();
	}

	/* ---------- Steg 6: Återställ ---------- */

	protected static function step_restore() {
		$backups     = count( CBX_Plugin::backup_post_ids() );
		$deactivated = (array) get_option( 'cbx_deactivated_plugins', array() );
		self::card_open( 8, 'Återställ', 'Ångra allt eller delar. Sidorna återställs från backupen som togs vid första konverteringen, och Crocoblock aktiveras igen automatiskt om tillägget stängdes av i steg 7.' );
		echo '<p class="cbx-actions">';
		echo '<button type="button" class="button" data-cbx-batch="restore" data-cbx-confirm="confirmRestore"' . ( $backups ? '' : ' disabled' ) . '>Återställ ' . (int) $backups . ' sidor</button> ';
		echo '</p>';
		echo '<div class="cbx-progress" data-cbx-progress="restore" hidden><div class="cbx-progress__bar"></div><span class="cbx-progress__text"></span></div>';
		echo '<div class="cbx-summary" data-cbx-summary="restore"></div>';
		echo '<div class="cbx-results" data-cbx-results="restore"></div>';

		echo '<div class="cbx-actions">';
		self::form_open( 'templates_undo' );
		submit_button( 'Ta bort migrerade mallar', 'secondary', 'submit', false, array( 'onclick' => "return confirm('De migrerade Elementor-mallarna flyttas till papperskorgen. Fortsätt?');" ) );
		echo '</form> ';
		if ( CBX_CSS_Hero::backup() ) {
			self::form_open( 'csshero_undo' );
			submit_button( 'Ångra CSS Hero-flytten', 'secondary', 'submit', false, array( 'onclick' => "return confirm('CSS Hero-blocket tas bort ur Ytterligare CSS och CSS Hero aktiveras igen. Fortsätt?');" ) );
			echo '</form> ';
		}
		if ( $deactivated ) {
			self::form_open( 'reactivate' );
			submit_button( 'Aktivera Crocoblock igen (' . count( $deactivated ) . ' tillägg)', 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
		self::card_close();
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	protected static function check_ajax() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'cbx_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Behörighet saknas eller sidan är för gammal – ladda om.' ), 403 );
		}
	}

	public static function ajax_scan() {
		self::check_ajax();
		if ( ! empty( $_POST['start'] ) ) {
			CBX_Scanner::start();
		}
		$res = CBX_Scanner::step( 25 );
		if ( $res['done'] ) {
			$res['html'] = self::render_report( $res['report'] );
			unset( $res['report'] );
		}
		wp_send_json_success( $res );
	}

	public static function ajax_batch_start() {
		self::check_ajax();
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'dry', 'convert', 'restore' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Okänt läge.' ) );
		}
		wp_send_json_success( CBX_Converter::start( $mode ) );
	}

	public static function ajax_batch() {
		self::check_ajax();
		$res = CBX_Converter::step( 'restore' === ( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '' ) ? 20 : 10 );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	/* ------------------------------------------------------------------ *
	 * admin-post
	 * ------------------------------------------------------------------ */

	protected static function check_post( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Behörighet saknas.' );
		}
		check_admin_referer( 'cbx_' . $action );
	}

	protected static function back( $notice, array $extra = array() ) {
		wp_safe_redirect( self::url( array_merge( array( 'cbx_notice' => rawurlencode( $notice ), 'cbx_adv' => 1 ), $extra ) ) );
		exit;
	}

	public static function post_settings() {
		self::check_post( 'settings' );
		update_option(
			CBX_Plugin::OPT_SETTINGS,
			array(
				'clean_ext'      => empty( $_POST['clean_ext'] ) ? 0 : 1,
				'include_drafts' => empty( $_POST['include_drafts'] ) ? 0 : 1,
			)
		);
		self::back( 'Valen är sparade.' );
	}

	public static function post_snapshot() {
		self::check_post( 'snapshot' );
		$res = CBX_Content_Types::snapshot();
		self::back( is_wp_error( $res ) ? $res->get_error_message() : 'Ögonblicksbilden är sparad. Inläggstyperna registreras av det här tillägget när JetEngine avaktiveras.' );
	}

	public static function post_export_php() {
		self::check_post( 'export_php' );
		$code = CBX_Content_Types::php_export();
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="innehallstyper-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.php"' );
		echo $code; // phpcs:ignore
		exit;
	}

	public static function post_csv() {
		self::check_post( 'csv' );
		$report = CBX_Scanner::last_report();
		if ( ! $report ) {
			self::back( 'Kör inventeringen först.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="crocoblock-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM så att Excel läser å, ä, ö rätt.
		foreach ( CBX_Scanner::csv_rows( $report ) as $row ) {
			fputcsv( $out, $row, ';' );
		}
		fclose( $out );
		exit;
	}

	public static function post_templates() {
		self::check_post( 'templates' );
		$results = CBX_Templates::migrate_all();
		update_option( 'cbx_last_template_run', array( 'time' => current_time( 'mysql' ), 'results' => $results ), false );
		$n = 0;
		foreach ( $results as $rows ) {
			foreach ( $rows as $r ) {
				$n += 'migrated' === $r['state'] ? 1 : 0;
			}
		}
		self::back( sprintf( '%d mallar migrerade. Se detaljerna under steg 3 och kör sedan provkörningen i steg 4.', $n ), array( 'cbx_tpl' => 1 ) );
	}

	public static function post_templates_undo() {
		self::check_post( 'templates_undo' );
		$n = CBX_Templates::undo();
		delete_option( 'cbx_last_template_run' );
		self::back( sprintf( '%d migrerade mallar flyttades till papperskorgen.', $n ) );
	}

	public static function post_single() {
		self::check_post( 'single' );
		$id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $id || ! get_post( $id ) ) {
			self::back( 'Hittade ingen sida med det ID:t.' );
		}
		$res = CBX_Converter::process_post( $id, false );
		CBX_Plugin::clear_all_cache();
		if ( ! $res ) {
			self::back( 'Sidan har inget Elementor-innehåll.' );
		}
		$s = CBX_Mapper::summarize( $res['log'] );
		self::back( sprintf( '%s: %d widgets konverterade, %d manuella, %d tillägg rensade.', CBX_Plugin::post_label( $id ), $s['converted'], $s['manual'], $s['cleaned'] ) );
	}

	public static function post_switch() {
		self::check_post( 'switch' );
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// Spärr: kontrollera på nytt, precis innan något stängs av.
		$remaining = CBX_Scanner::remaining();
		if ( ( $remaining['count'] || $remaining['templates'] ) && empty( $_POST['force'] ) ) {
			self::back( sprintf( 'Inget ändrades: %d Crocoblock-widgets på %d sidor/mallar%s är inte konverterade och skulle försvinna. Konvertera först (steg 6).', $remaining['count'], count( $remaining['posts'] ), $remaining['templates'] ? ' och ' . count( $remaining['templates'] ) . ' Crocoblock-mallar' : '' ) );
		}
		$msgs = array();
		if ( ! empty( $_POST['activate_templates'] ) ) {
			$msgs[] = sprintf( '%d mallar publicerade', CBX_Templates::activate() );
		}
		$valid   = array_keys( CBX_Plugin::jet_plugins() );
		$chosen  = isset( $_POST['plugins'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['plugins'] ) ) : array();
		$chosen  = array_values( array_intersect( $chosen, $valid ) );
		if ( $chosen ) {
			deactivate_plugins( $chosen );
			$prev = (array) get_option( 'cbx_deactivated_plugins', array() );
			update_option( 'cbx_deactivated_plugins', array_values( array_unique( array_merge( $prev, $chosen ) ) ), false );
			$msgs[] = sprintf( '%d Crocoblock-tillägg avaktiverade', count( $chosen ) );
		}
		CBX_Plugin::clear_all_cache();
		self::back( implode( ', ', $msgs ) . '. Kontrollera sajten nu och kör inventeringen en sista gång.' );
	}

	public static function post_site_css_build() {
		self::check_post( 'site_css_build' );
		$d = CBX_Site_CSS::build();
		$n = 0;
		foreach ( $d['sources'] as $src ) {
			$n += $src['translated'];
		}
		self::back( $d['sources'] ? sprintf( '%d regler från %d källor översatta och aktiverade.', $n, count( $d['sources'] ) ) : 'Ingen egen CSS pekar på Crocoblocks klasser.' );
	}

	public static function post_site_css_save() {
		self::check_post( 'site_css_save' );
		$extra = isset( $_POST['extra'] ) ? wp_strip_all_tags( wp_unslash( $_POST['extra'] ) ) : ''; // phpcs:ignore
		CBX_Site_CSS::save_settings( ! empty( $_POST['enabled'] ), $extra );
		self::back( 'CSS-inställningarna är sparade.' );
	}

	public static function post_preview() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'cbx_preview_' . $id ) ) {
			wp_die( 'Behörighet saknas eller länken är för gammal.' );
		}
		$copy = CBX_Preview::create( $id );
		if ( is_wp_error( $copy ) ) {
			wp_die( esc_html( $copy->get_error_message() ) );
		}
		wp_safe_redirect( CBX_Preview::url( $copy ) );
		exit;
	}

	public static function post_preview_cleanup() {
		self::check_post( 'preview_cleanup' );
		self::back( sprintf( '%d förhandsvisningar borttagna.', CBX_Preview::cleanup() ) );
	}

	public static function post_csshero_migrate() {
		self::check_post( 'csshero_migrate' );
		$res = CBX_CSS_Hero::migrate( true );
		if ( is_wp_error( $res ) ) {
			self::back( $res->get_error_message() );
		}
		// Översätt Crocoblock-regler i den flyttade CSS:en direkt.
		CBX_Site_CSS::build();
		self::back( sprintf( '%d regler från CSS Hero ligger nu i Utseende → Anpassa → Ytterligare CSS, och CSS Hero är avaktiverat. Kontrollera sajten.', (int) $res['moved'] ) );
	}

	public static function post_csshero_undo() {
		self::check_post( 'csshero_undo' );
		$res = CBX_CSS_Hero::undo();
		CBX_Site_CSS::build();
		self::back( is_wp_error( $res ) ? $res->get_error_message() : 'CSS Hero är aktivt igen och blocket är borttaget ur Ytterligare CSS.' );
	}

	public static function post_reactivate() {
		self::check_post( 'reactivate' );
		$list = (array) get_option( 'cbx_deactivated_plugins', array() );
		$res  = CBX_Plugin::reactivate_jet();
		self::back( is_wp_error( $res ) ? $res->get_error_message() : 'Crocoblock-tilläggen är aktiva igen. Ta bort migrerade mallar om de krockar med JetThemeCore.' );
	}
}
