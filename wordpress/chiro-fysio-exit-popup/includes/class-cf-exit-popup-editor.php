<?php
/**
 * De pagina waar de hele pop-up in te stellen is.
 *
 * Alles wat de bezoeker ziet of merkt staat hier: de teksten, de contactknoppen,
 * de huisstijl en het gedrag. Rechts staat een voorbeeld dat meteen meebeweegt,
 * zodat je niet hoeft te gokken hoe een wijziging uitpakt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Editor {

	const SLUG = 'cf-exit-popup-popup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 15 );
		add_action( 'admin_post_cf_exit_popup_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			CF_Exit_Popup_Admin::PAGE_SLUG,
			'Pop-up instellen',
			'Pop-up instellen',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		$dir = plugin_dir_path( CF_EXIT_POPUP_FILE ) . 'assets/';
		$url = plugin_dir_url( CF_EXIT_POPUP_FILE ) . 'assets/';

		$v = function ( $bestand ) use ( $dir ) {
			return file_exists( $dir . $bestand ) ? (string) filemtime( $dir . $bestand ) : CF_EXIT_POPUP_VERSION;
		};

		// De echte opmaak van de pop-up, zodat het voorbeeld klopt.
		wp_enqueue_style( 'cf-exit-popup', $url . 'exit-intent-popup.css', array(), $v( 'exit-intent-popup.css' ) );
		wp_enqueue_style( 'cf-exit-popup-dashboard', $url . 'dashboard.css', array(), $v( 'dashboard.css' ) );
		wp_enqueue_style( 'cf-exit-popup-editor', $url . 'editor.css', array( 'cf-exit-popup-dashboard' ), $v( 'editor.css' ) );

		wp_enqueue_script( 'cf-exit-popup-editor', $url . 'editor.js', array(), $v( 'editor.js' ), true );

		// Voor het kiezen van een logo uit de mediabibliotheek.
		wp_enqueue_media();
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		check_admin_referer( 'cf_exit_popup_save' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- hierboven gecontroleerd
		if ( isset( $_POST['herstel'] ) ) {
			CF_Exit_Popup_Options::reset();
			$melding = 'hersteld';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- hierboven gecontroleerd
			CF_Exit_Popup_Options::save( wp_unslash( $_POST ) );
			$melding = 'opgeslagen';
		}

		// De instellingen staan in de HTML van elke pagina. Zolang een
		// cacheplugin de oude versie uitserveert, ziet een bezoeker niets van
		// de wijziging - ook al staat het hier allang goed.
		$geleegd = CF_Exit_Popup_Cache::flush();

		wp_safe_redirect( add_query_arg(
			array(
				'page'       => self::SLUG,
				'cf_melding' => $melding,
				'cf_cache'   => $geleegd ? rawurlencode( implode( ', ', $geleegd ) ) : '',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ======================================================================
	   Kleine hulpjes voor de formuliervelden
	   ====================================================================== */

	/**
	 * Milliseconden als leesbare tijd, zodat de toelichting meebeweegt met wat
	 * er werkelijk staat in plaats van een vast getal te noemen.
	 */
	private static function seconden( $ms ) {
		$ms = (int) $ms;

		if ( 0 === $ms ) {
			return 'geen wachttijd';
		}

		if ( $ms < 1000 ) {
			return $ms . ' milliseconden';
		}

		$sec = $ms / 1000;

		// Hele seconden zonder komma, anders met één cijfer erachter.
		$net = ( $sec === floor( $sec ) )
			? (string) (int) $sec
			: number_format_i18n( $sec, 1 );

		return $net . ( '1' === $net ? ' seconde' : ' seconden' );
	}

	private static function tekst( $naam, $label, $o, $hulp = '', $breed = false ) {
		?>
		<p class="cf-field">
			<label for="cf-<?php echo esc_attr( $naam ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text" id="cf-<?php echo esc_attr( $naam ); ?>" name="<?php echo esc_attr( $naam ); ?>"
				value="<?php echo esc_attr( $o[ $naam ] ); ?>"
				class="<?php echo $breed ? 'cf-wide' : ''; ?>"
				data-preview="<?php echo esc_attr( $naam ); ?>">
			<?php if ( $hulp ) : ?>
				<span class="cf-hulp"><?php echo esc_html( $hulp ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function memo( $naam, $label, $o, $hulp = '' ) {
		?>
		<p class="cf-field">
			<label for="cf-<?php echo esc_attr( $naam ); ?>"><?php echo esc_html( $label ); ?></label>
			<textarea id="cf-<?php echo esc_attr( $naam ); ?>" name="<?php echo esc_attr( $naam ); ?>" rows="3"
				data-preview="<?php echo esc_attr( $naam ); ?>"><?php echo esc_textarea( $o[ $naam ] ); ?></textarea>
			<?php if ( $hulp ) : ?>
				<span class="cf-hulp"><?php echo esc_html( $hulp ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function getal( $naam, $label, $o, $suffix = '', $hulp = '', $min = 0, $max = 100000, $stap = 1 ) {
		?>
		<p class="cf-field cf-field--getal">
			<label for="cf-<?php echo esc_attr( $naam ); ?>"><?php echo esc_html( $label ); ?></label>
			<span class="cf-getal">
				<input type="number" id="cf-<?php echo esc_attr( $naam ); ?>" name="<?php echo esc_attr( $naam ); ?>"
					value="<?php echo esc_attr( $o[ $naam ] ); ?>"
					min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $stap ); ?>"
					data-preview="<?php echo esc_attr( $naam ); ?>">
				<?php if ( $suffix ) : ?><span class="cf-suffix"><?php echo esc_html( $suffix ); ?></span><?php endif; ?>
			</span>
			<?php if ( $hulp ) : ?>
				<span class="cf-hulp"><?php echo esc_html( $hulp ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function schakel( $naam, $label, $o, $hulp = '' ) {
		?>
		<p class="cf-field">
			<label class="cf-switch">
				<input type="checkbox" name="<?php echo esc_attr( $naam ); ?>" value="1" <?php checked( $o[ $naam ], 1 ); ?>
					data-preview="<?php echo esc_attr( $naam ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( $hulp ) : ?>
				<span class="cf-hulp cf-hulp--onder"><?php echo esc_html( $hulp ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function kleur( $naam, $label, $o, $hulp = '' ) {
		?>
		<p class="cf-field cf-field--kleur">
			<label for="cf-<?php echo esc_attr( $naam ); ?>"><?php echo esc_html( $label ); ?></label>
			<span class="cf-kleur">
				<input type="color" id="cf-<?php echo esc_attr( $naam ); ?>" name="<?php echo esc_attr( $naam ); ?>"
					value="<?php echo esc_attr( $o[ $naam ] ); ?>" data-preview="<?php echo esc_attr( $naam ); ?>">
				<input type="text" class="cf-kleur__code" value="<?php echo esc_attr( $o[ $naam ] ); ?>"
					data-voor="cf-<?php echo esc_attr( $naam ); ?>" spellcheck="false">
			</span>
			<?php if ( $hulp ) : ?>
				<span class="cf-hulp"><?php echo esc_html( $hulp ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/* ======================================================================
	   De pagina
	   ====================================================================== */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		$o = CF_Exit_Popup_Options::all();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$melding = isset( $_GET['cf_melding'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_melding'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cache = isset( $_GET['cf_cache'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_cache'] ) ) : '';

		$cacheplugins = CF_Exit_Popup_Cache::detected();
		?>
		<div class="wrap cf-dash cf-editor">

			<div class="cf-dash__head">
				<div>
					<h1 class="cf-dash__title">Pop-up instellen</h1>
					<p class="cf-dash__sub">
						Alles wat de bezoeker ziet en merkt. Rechts beweegt een voorbeeld mee,
						zodat je meteen ziet wat een wijziging doet.
					</p>
				</div>
			</div>

			<?php if ( $melding ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						echo 'hersteld' === $melding
							? 'Alles staat weer op de standaardwaarden.'
							: 'Opgeslagen.';
						?>
						<?php if ( $cache ) : ?>
							De cache van <?php echo esc_html( $cache ); ?> is meteen geleegd,
							dus bezoekers zien de nieuwe versie direct.
						<?php else : ?>
							De wijzigingen staan meteen live.
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $cacheplugins ) : ?>
				<p class="cf-note">
					Er draait een cachelaag op deze site
					(<?php echo esc_html( implode( ', ', $cacheplugins ) ); ?>). Die wordt na
					elke keer opslaan automatisch geleegd. Zie je een wijziging toch niet terug
					op de site, ververs dan je browser met <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd>
					(of <kbd>Cmd</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> op een Mac).
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cf-editor-form">
				<input type="hidden" name="action" value="cf_exit_popup_save">
				<?php wp_nonce_field( 'cf_exit_popup_save' ); ?>

				<div class="cf-editor__layout">

					<!-- ============ Linkerkant: de instellingen ============ -->
					<div class="cf-editor__velden">

						<nav class="cf-tabs" role="tablist">
							<button type="button" class="cf-tab is-active" data-tab="inhoud" role="tab" aria-selected="true">Teksten</button>
							<button type="button" class="cf-tab" data-tab="contact" role="tab" aria-selected="false">Contact</button>
							<button type="button" class="cf-tab" data-tab="huisstijl" role="tab" aria-selected="false">Huisstijl</button>
							<button type="button" class="cf-tab" data-tab="gedrag" role="tab" aria-selected="false">Gedrag</button>
						</nav>

						<!-- ---------- Teksten ---------- -->
						<section class="cf-card cf-paneel is-active" data-paneel="inhoud">
							<h2 class="cf-card__title">De vraag</h2>
							<p class="cf-card__sub">Wat de bezoeker als eerste te zien krijgt.</p>
							<?php
							self::tekst( 'txt_question', 'Vraag', $o, '', true );
							self::tekst( 'txt_question_sub', 'Regel eronder', $o, '', true );
							self::tekst( 'txt_yes', 'Knop: wel gevonden', $o );
							self::tekst( 'txt_no', 'Knop: niet gevonden', $o );
							?>

							<h2 class="cf-card__title cf-mt">Voor wie er al eerder was</h2>
							<p class="cf-card__sub">
								Iemand die terugkomt is verder in zijn overweging, dus die spreken we directer aan.
								Let op: volgens de cijfers gaat dat om weinig bezoekers.
							</p>
							<?php
							self::tekst( 'txt_question_ret', 'Vraag', $o, '', true );
							self::tekst( 'txt_question_ret_sub', 'Regel eronder', $o, '', true );
							?>

							<h2 class="cf-card__title cf-mt">Als het gelukt is</h2>
							<?php
							self::tekst( 'txt_yes_title', 'Kop', $o, '', true );
							self::memo( 'txt_yes_body', 'Tekst', $o );
							self::tekst( 'txt_appointment', 'Knop naar afspraak maken', $o, '', true );
							?>

							<h2 class="cf-card__title cf-mt">Als het niet gelukt is</h2>
							<p class="cf-card__sub">Dit scherm bepaalt of iemand alsnog contact opneemt.</p>
							<?php
							self::tekst( 'txt_no_title', 'Kop', $o, '', true );
							self::memo( 'txt_no_body', 'Tekst', $o );
							self::tekst( 'txt_call', 'Woord voor de belknop', $o, 'Hier komt het telefoonnummer achter.' );
							self::tekst( 'txt_whatsapp', 'Tekst WhatsApp-knop', $o );
							self::tekst( 'txt_hours', 'Regel onderaan', $o, '', true );
							self::tekst( 'help_label', 'Tekst van de hulplink', $o, 'Leeg laten om de link te verbergen.', true );
							self::tekst( 'txt_close', 'Omschrijving sluitknop', $o, 'Wordt voorgelezen door schermlezers.' );
							?>
						</section>

						<!-- ---------- Contact ---------- -->
						<section class="cf-card cf-paneel" data-paneel="contact">
							<h2 class="cf-card__title">Telefoon</h2>
							<?php
							self::tekst( 'phone_display', 'Zoals de bezoeker het ziet', $o, 'Bijvoorbeeld: 024 - 355 88 30' );
							self::tekst( 'phone_href', 'Waar de knop naartoe belt', $o, 'Met landcode in plaats van de nul, zodat bellen ook vanaf een mobiel werkt. Bijvoorbeeld: +31243558830' );
							?>

							<h2 class="cf-card__title cf-mt">WhatsApp</h2>
							<?php
							self::tekst( 'whatsapp', 'Nummer', $o, 'Alleen cijfers, met landcode. 06-14798722 wordt 31614798722. Leeg laten om de knop te verbergen.' );
							self::memo( 'whatsapp_text', 'Bericht dat alvast klaarstaat', $o, 'Zo hoeft de bezoeker niet vanaf niets te typen.' );
							?>

							<h2 class="cf-card__title cf-mt">Links</h2>
							<?php
							self::tekst( 'appointment_url', 'Afspraak maken', $o, 'Knop in het scherm "wel gevonden". Leeg laten om te verbergen.', true );
							self::tekst( 'help_url', 'Hulplink', $o, 'Verschijnt onder de contactknoppen. Leeg laten om te verbergen.', true );
							?>
						</section>

						<!-- ---------- Huisstijl ---------- -->
						<section class="cf-card cf-paneel" data-paneel="huisstijl">
							<h2 class="cf-card__title">Kleuren</h2>
							<p class="cf-card__sub">
								De hele pop-up is op deze kleuren gebouwd, dus één wijziging werkt meteen
								door in knoppen, randen en accenten.
							</p>
							<?php
							self::kleur( 'brand_primary', 'Hoofdkleur', $o, 'Knoppen en accenten.' );
							self::kleur( 'brand_primary_dark', 'Hoofdkleur donkerder', $o, 'Voor als de muis op een knop staat.' );
							self::kleur( 'brand_accent', 'Zachte achtergrondkleur', $o, 'Achter het vinkje en de sluitknop.' );
							self::kleur( 'brand_text', 'Tekstkleur', $o );
							self::kleur( 'brand_muted', 'Rustige tekstkleur', $o, 'Voor bijzinnen en toelichtingen.' );
							?>

							<h2 class="cf-card__title cf-mt">Vorm</h2>
							<?php
							self::getal( 'brand_radius', 'Ronding van het venster', $o, 'px', '', 0, 40 );
							self::getal( 'brand_btn_radius', 'Ronding van de knoppen', $o, 'px', '', 0, 40 );
							self::tekst( 'brand_font', 'Lettertype', $o, 'Leeg laten om de letter van de site over te nemen. Anders bijvoorbeeld: Poppins, sans-serif', true );
							?>

							<h2 class="cf-card__title cf-mt">Logo</h2>
							<p class="cf-card__sub">Optioneel, verschijnt bovenaan in de pop-up.</p>
							<div class="cf-logo">
								<input type="hidden" name="brand_logo" id="cf-brand_logo" value="<?php echo esc_attr( $o['brand_logo'] ); ?>" data-preview="brand_logo">
								<div class="cf-logo__voorbeeld" id="cf-logo-voorbeeld">
									<?php
									$logo = CF_Exit_Popup_Options::logo_url();
									if ( $logo ) {
										echo '<img src="' . esc_url( $logo ) . '" alt="">';
									} else {
										echo '<span class="cf-hulp">Nog geen logo gekozen</span>';
									}
									?>
								</div>
								<span class="cf-logo__knoppen">
									<button type="button" class="button" id="cf-logo-kies">Logo kiezen</button>
									<button type="button" class="button" id="cf-logo-weg" <?php echo $logo ? '' : 'hidden'; ?>>Weghalen</button>
								</span>
							</div>
							<?php self::getal( 'brand_logo_height', 'Hoogte van het logo', $o, 'px', '', 16, 90 ); ?>
						</section>

						<!-- ---------- Gedrag ---------- -->
						<section class="cf-card cf-paneel" data-paneel="gedrag">
							<h2 class="cf-card__title">Aan of uit</h2>
							<?php
							self::schakel( 'enabled', 'De pop-up is actief', $o, 'Uitzetten stopt hem meteen, zonder de plugin uit te schakelen. De cijfers blijven staan.' );
							?>

							<h2 class="cf-card__title cf-mt">Wanneer hij verschijnt</h2>
							<?php
							self::getal( 'arm_after', 'Wachten voordat hij scherp staat', $o, 'ms', 'Dat is nu ' . self::seconden( $o['arm_after'] ) . '. Onderbouwd met het Analytics-rapport: bezoekers zijn gemiddeld 38 seconden actief over 1,98 pagina\'s, dus grofweg 19 seconden per pagina.', 0, 60000, 500 );
							self::getal( 'exit_grace', 'Wachten na het vertreksignaal', $o, 'ms', 'Dat is nu ' . self::seconden( $o['exit_grace'] ) . '. Komt de aanwijzer binnen die tijd terug, dan gebeurt er niets. Dit vangt af dat iemand naar het menu bovenaan reikt en er net voorbij schiet.', 0, 3000, 50 );
							self::schakel( 'require_interaction', 'Pas na een echte beweging of scroll', $o, 'Houdt geautomatiseerd verkeer uit de cijfers. In juli kwam 31% van het verkeer uit landen zonder plausibele patiëntrelatie.' );
							?>

							<h2 class="cf-card__title cf-mt">Hoe vaak</h2>
							<?php
							self::getal( 'cooldown_days', 'Rustperiode per bezoeker', $o, 'dagen', 'Zo lang krijgt iemand hem niet nog eens te zien.', 0, 365 );
							self::getal( 'returning_from', 'Terugkerend vanaf bezoek', $o, 'e bezoek', 'Vanaf dit bezoek krijgt iemand de andere vraag. Op 0 zetten om geen onderscheid te maken.', 0, 20 );
							?>

							<h2 class="cf-card__title cf-mt">Op telefoon en tablet</h2>
							<?php
							self::schakel( 'enable_mobile', 'Ook tonen op mobiel', $o, 'Daar bestaat geen muis, dus wordt er gekeken naar stilte en snel omhoog vegen.' );
							self::getal( 'mobile_idle', 'Tonen na deze stilte', $o, 'ms', 'Dat is nu ' . self::seconden( $o['mobile_idle'] ) . ' zonder aanraking. Op 0 zetten om deze trigger uit te schakelen.', 0, 300000, 5000 );
							?>

							<h2 class="cf-card__title cf-mt">Waar hij nooit verschijnt</h2>
							<?php
							self::memo( 'exclude_paths', 'Uitgesloten pagina\'s', $o, 'Eén term per regel, zonder schuine strepen. Een term hoeft maar ergens in het adres voor te komen: "afspraak" dekt in één keer /uw-afspraak/, /afspraak-chiro/ en /je-1e-afspraak/.' );
							?>
						</section>

						<div class="cf-editor__acties">
							<button type="submit" class="button button-primary button-hero">Opslaan</button>
							<button type="submit" name="herstel" value="1" class="button"
								onclick="return confirm('Alle instellingen terugzetten naar de standaardwaarden. Doorgaan?');">
								Terug naar standaard
							</button>
						</div>
					</div>

					<!-- ============ Rechterkant: het voorbeeld ============ -->
					<div class="cf-editor__voorbeeld">
						<div class="cf-preview" id="cf-preview">
							<div class="cf-preview__balk">
								<span class="cf-preview__titel">Voorbeeld</span>
								<span class="cf-preview__stappen">
									<button type="button" class="cf-preview__stap is-active" data-stap="ask">Vraag</button>
									<button type="button" class="cf-preview__stap" data-stap="no">Niet gevonden</button>
									<button type="button" class="cf-preview__stap" data-stap="yes">Wel gevonden</button>
								</span>
							</div>
							<div class="cf-preview__vlak">
								<div class="cf-exit cf-preview__popup is-open" id="cf-preview-popup"></div>
							</div>
							<p class="cf-preview__hulp">
								Dit is de echte pop-up met de echte opmaak; alleen de achtergrond is nagebootst.
								Wijzigingen zie je hier meteen, maar ze gaan pas live als je opslaat.
							</p>
						</div>
					</div>

				</div>
			</form>
		</div>
		<?php
	}
}
