<?php
/**
 * De dashboardpagina in het WordPress-beheer.
 *
 * PHP haalt de cijfers op en geeft ze als JSON door aan dashboard.js, dat de
 * grafieken tekent. Zo hoeft er geen enkele externe bibliotheek geladen te
 * worden en blijft alles binnen de eigen site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Admin {

	const CAPABILITY = 'cf_view_popup_stats';
	const PAGE_SLUG  = 'cf-exit-popup';

	/** Perioden die je bovenaan kunt kiezen. */
	private static function periods() {
		return array(
			7   => 'Laatste 7 dagen',
			30  => 'Laatste 30 dagen',
			90  => 'Laatste 90 dagen',
			365 => 'Laatste 12 maanden',
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_cf_exit_popup_export', array( __CLASS__, 'export_csv' ) );
	}

	public static function add_menu() {
		add_menu_page(
			'Exit-pop-up',
			'Exit-pop-up',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-status',
			58
		);
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$dir = plugin_dir_path( CF_EXIT_POPUP_FILE ) . 'assets/';
		$url = plugin_dir_url( CF_EXIT_POPUP_FILE ) . 'assets/';

		wp_enqueue_style(
			'cf-exit-popup-dashboard',
			$url . 'dashboard.css',
			array(),
			file_exists( $dir . 'dashboard.css' ) ? (string) filemtime( $dir . 'dashboard.css' ) : CF_EXIT_POPUP_VERSION
		);

		wp_enqueue_script(
			'cf-exit-popup-dashboard',
			$url . 'dashboard.js',
			array(),
			file_exists( $dir . 'dashboard.js' ) ? (string) filemtime( $dir . 'dashboard.js' ) : CF_EXIT_POPUP_VERSION,
			true
		);

		wp_enqueue_style(
			'cf-exit-popup-app',
			$url . 'app.css',
			array( 'cf-exit-popup-dashboard' ),
			file_exists( $dir . 'app.css' ) ? (string) filemtime( $dir . 'app.css' ) : CF_EXIT_POPUP_VERSION
		);

		wp_add_inline_script(
			'cf-exit-popup-dashboard',
			'window.CF_DASH = ' . wp_json_encode( self::collect_data() ) . ';',
			'before'
		);
	}

	/**
	 * De gekozen periode, begrensd tot de vaste keuzes.
	 */
	private static function current_days() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- alleen een filterkeuze, geen wijziging
		$days = isset( $_GET['periode'] ) ? absint( $_GET['periode'] ) : 30;

		return array_key_exists( $days, self::periods() ) ? $days : 30;
	}

	/**
	 * Alles wat het dashboard nodig heeft, in één blok.
	 */
	private static function collect_data() {
		$days = self::current_days();

		return array(
			'days'     => $days,
			'totals'   => CF_Exit_Popup_Storage::totals( $days ),
			'perDay'   => CF_Exit_Popup_Storage::per_day( min( $days, 90 ) ),
			'topPages' => CF_Exit_Popup_Storage::top_pages( $days ),
			'reasons'  => CF_Exit_Popup_Storage::reasons( $days ),
			'devices'  => CF_Exit_Popup_Storage::breakdown( $days, 'device' ),
			'triggers' => CF_Exit_Popup_Storage::breakdown( $days, 'trigger_type' ),
			'visitors' => CF_Exit_Popup_Storage::breakdown( $days, 'visitor' ),
			'perHour'  => CF_Exit_Popup_Storage::per_hour( $days ),
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		$days    = self::current_days();
		$totals  = CF_Exit_Popup_Storage::totals( $days );
		$recent  = CF_Exit_Popup_Storage::recent( $days );
		$periods = self::periods();
		$health  = CF_Exit_Popup_Health::report();

		$export = wp_nonce_url(
			admin_url( 'admin-post.php?action=cf_exit_popup_export&periode=' . $days ),
			'cf_exit_popup_export'
		);
		?>
		<div class="wrap cf-dash">

			<div class="cf-dash__head">
				<div>
					<h1 class="cf-dash__title">Exit-pop-up</h1>
					<p class="cf-dash__sub">Wie stond er op het punt te vertrekken, en hadden we die kunnen helpen?</p>
				</div>
				<div class="cf-dash__periods">
					<?php foreach ( $periods as $value => $label ) : ?>
						<a
							class="cf-dash__period<?php echo ( $value === $days ) ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'periode' => $value ), admin_url( 'admin.php' ) ) ); ?>"
							<?php echo ( $value === $days ) ? 'aria-current="page"' : ''; ?>
						><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php CF_Exit_Popup_View::health_card( $health ); ?>

			<p class="cf-appbanner">
				<span class="cf-appbanner__text">
					Liever als app op je bureaublad, met een eigen icoon en een eigen venster?
				</span>
				<a class="button" href="<?php echo esc_url( CF_Exit_Popup_App::url() ); ?>" target="_blank" rel="noopener">
					App openen
				</a>
				<a class="cf-appbanner__link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . CF_Exit_Popup_Editor::SLUG ) ); ?>">
					pop-up aanpassen
				</a>
				<a class="cf-appbanner__link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . CF_Exit_Popup_Settings::SLUG ) ); ?>">
					instellingen
				</a>
			</p>

			<?php
			if ( 0 === (int) $totals['shown'] ) {
				CF_Exit_Popup_View::empty_state();
			} else {
				CF_Exit_Popup_View::tiles( $totals );
				CF_Exit_Popup_View::charts( $totals );
				CF_Exit_Popup_View::recent_table( $recent, $export );
			}

			CF_Exit_Popup_View::privacy_note();
			?>
		</div>
		<?php
	}

	/**
	 * Zet de metingen van de gekozen periode om in een CSV-bestand.
	 */
	public static function export_csv() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		check_admin_referer( 'cf_exit_popup_export' );

		$days = isset( $_GET['periode'] ) ? absint( $_GET['periode'] ) : 30;
		$days = array_key_exists( $days, self::periods() ) ? $days : 30;

		$rows = CF_Exit_Popup_Storage::recent( $days, 5000 );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=exit-popup-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// UTF-8 markering, anders maakt Excel er rommel van bij accenten.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( 'Wanneer', 'Pagina', 'Apparaat', 'Signaal', 'Bezoeker', 'Antwoord', 'Actie' ), ';' );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['created_at'],
					$row['page'],
					$row['device'],
					$row['trigger_type'],
					'' === $row['visitor'] ? '-' : $row['visitor'],
					'' === $row['answer'] ? 'geen antwoord' : $row['answer'],
					'' === $row['action_taken'] ? '-' : $row['action_taken'],
				),
				';'
			);
		}

		fclose( $out );
		exit;
	}
}
