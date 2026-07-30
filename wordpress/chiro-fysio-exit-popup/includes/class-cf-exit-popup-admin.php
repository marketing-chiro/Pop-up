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

	const CAPABILITY = 'manage_options';
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
			'devices'  => CF_Exit_Popup_Storage::breakdown( $days, 'device' ),
			'triggers' => CF_Exit_Popup_Storage::breakdown( $days, 'trigger_type' ),
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

		// Percentage van de mensen die daadwerkelijk antwoord gaven. Dat is een
		// eerlijker noemer dan alle vertoningen: wie niets invult, weten we niet.
		$answered = (int) $totals['found'] + (int) $totals['not_found'];
		$pct_not_found = $answered > 0 ? round( $totals['not_found'] / $answered * 100 ) : 0;
		$pct_rescued   = $totals['not_found'] > 0 ? round( $totals['rescued'] / $totals['not_found'] * 100 ) : 0;

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

			<?php if ( 0 === (int) $totals['shown'] ) : ?>
				<div class="cf-dash__empty">
					<h2>Nog geen metingen in deze periode</h2>
					<p>
						Zodra de eerste bezoeker de pop-up te zien krijgt, verschijnen de cijfers hier.
						Wil je zelf controleren of het werkt? Open de site met
						<code><?php echo esc_html( home_url( '/?cf-popup=test' ) ); ?></code>
						en klik de pop-up door.
					</p>
				</div>
			<?php else : ?>

			<!-- De vier cijfers waar het om draait -->
			<div class="cf-dash__tiles">
				<div class="cf-tile">
					<span class="cf-tile__label">Pop-up getoond</span>
					<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['shown'] ) ); ?></span>
					<span class="cf-tile__note">bezoekers die weg wilden gaan</span>
				</div>

				<div class="cf-tile">
					<span class="cf-tile__label">Vond niet wat die zocht</span>
					<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['not_found'] ) ); ?></span>
					<span class="cf-tile__note">
						<?php echo esc_html( $pct_not_found ); ?>% van wie antwoord gaf
					</span>
				</div>

				<div class="cf-tile cf-tile--good">
					<span class="cf-tile__label">
						<span class="cf-dot cf-dot--good" aria-hidden="true"></span>
						Nam alsnog contact op
					</span>
					<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['rescued'] ) ); ?></span>
					<span class="cf-tile__note">
						<?php echo esc_html( $pct_rescued ); ?>% van wie het niet vond -
						<?php echo esc_html( number_format_i18n( $totals['calls'] ) ); ?>x gebeld<?php
						if ( $totals['whatsapps'] > 0 ) {
							echo ', ' . esc_html( number_format_i18n( $totals['whatsapps'] ) ) . 'x WhatsApp';
						}
						?>
					</span>
				</div>

				<div class="cf-tile cf-tile--bad">
					<span class="cf-tile__label">
						<span class="cf-dot cf-dot--bad" aria-hidden="true"></span>
						Toch weg, zonder contact
					</span>
					<span class="cf-tile__value"><?php echo esc_html( number_format_i18n( $totals['lost'] ) ); ?></span>
					<span class="cf-tile__note">hier lag werk dat we misliepen</span>
				</div>
			</div>

			<!-- Uitkomst + verloop -->
			<div class="cf-dash__row cf-dash__row--split">
				<section class="cf-card">
					<h2 class="cf-card__title">Hoe liep het af?</h2>
					<p class="cf-card__sub">Alle <?php echo esc_html( number_format_i18n( $totals['shown'] ) ); ?> vertoningen in deze periode.</p>
					<div id="cf-chart-outcome" class="cf-chart"></div>
				</section>

				<section class="cf-card">
					<h2 class="cf-card__title">Verloop per dag</h2>
					<p class="cf-card__sub">Getoond tegenover "niet gevonden".</p>
					<div id="cf-chart-trend" class="cf-chart"></div>
				</section>
			</div>

			<!-- Het meest bruikbare lijstje -->
			<section class="cf-card">
				<h2 class="cf-card__title">Op welke pagina's liepen bezoekers vast?</h2>
				<p class="cf-card__sub">
					Dit is het lijstje om mee aan de slag te gaan: hier stelden bezoekers een vraag
					die de pagina niet beantwoordde.
				</p>
				<div id="cf-chart-pages" class="cf-chart"></div>
			</section>

			<div class="cf-dash__row cf-dash__row--split">
				<section class="cf-card">
					<h2 class="cf-card__title">Wanneer op de dag?</h2>
					<p class="cf-card__sub">Vertoningen per uur. Buiten openingstijden is bellen geen optie.</p>
					<div id="cf-chart-hours" class="cf-chart"></div>
				</section>

				<section class="cf-card">
					<h2 class="cf-card__title">Waar kwamen ze vandaan?</h2>
					<p class="cf-card__sub">Apparaat en het signaal waarop de pop-up verscheen.</p>
					<div id="cf-chart-devices" class="cf-chart"></div>
				</section>
			</div>

			<!-- Ruwe regels -->
			<section class="cf-card">
				<div class="cf-card__head">
					<div>
						<h2 class="cf-card__title">Laatste vertoningen</h2>
						<p class="cf-card__sub">De 50 meest recente, om een gevoel te krijgen bij de cijfers.</p>
					</div>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cf_exit_popup_export&periode=' . $days ), 'cf_exit_popup_export' ) ); ?>">
						Alles downloaden (CSV)
					</a>
				</div>

				<div class="cf-tablewrap">
					<table class="cf-table">
						<thead>
							<tr>
								<th scope="col">Wanneer</th>
								<th scope="col">Pagina</th>
								<th scope="col">Apparaat</th>
								<th scope="col">Antwoord</th>
								<th scope="col">Actie</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<tr>
								<td class="cf-table__when"><?php echo esc_html( mysql2date( 'j M H:i', $row['created_at'] ) ); ?></td>
								<td class="cf-table__page"><?php echo esc_html( $row['page'] ); ?></td>
								<td><?php echo esc_html( $row['device'] ); ?></td>
								<td>
									<?php
									if ( 'nee' === $row['answer'] ) {
										echo '<span class="cf-pill cf-pill--bad">niet gevonden</span>';
									} elseif ( 'ja' === $row['answer'] ) {
										echo '<span class="cf-pill cf-pill--good">gevonden</span>';
									} else {
										echo '<span class="cf-pill">geen antwoord</span>';
									}
									?>
								</td>
								<td>
									<?php
									$labels = array(
										'call'        => 'gebeld',
										'whatsapp'    => 'WhatsApp',
										'appointment' => 'afspraak',
									);
									echo isset( $labels[ $row['action_taken'] ] )
										? '<span class="cf-pill cf-pill--good">' . esc_html( $labels[ $row['action_taken'] ] ) . '</span>'
										: '<span class="cf-muted">-</span>';
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

			<p class="cf-dash__privacy">
				Er worden geen persoonsgegevens vastgelegd: geen IP-adres, geen naam, en geen cookie
				waarmee iemand over meerdere bezoeken te volgen is. Metingen ouder dan een jaar
				worden automatisch verwijderd.
			</p>

			<?php endif; ?>
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

		fputcsv( $out, array( 'Wanneer', 'Pagina', 'Apparaat', 'Signaal', 'Antwoord', 'Actie' ), ';' );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['created_at'],
					$row['page'],
					$row['device'],
					$row['trigger_type'],
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
