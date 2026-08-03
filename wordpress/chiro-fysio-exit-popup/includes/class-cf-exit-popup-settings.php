<?php
/**
 * Instellingen: de app, de deelbare link en waar de meldingen heen gaan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF_Exit_Popup_Settings {

	const SLUG = 'cf-exit-popup-instellingen';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_post_cf_exit_popup_settings', array( __CLASS__, 'save' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			CF_Exit_Popup_Admin::PAGE_SLUG,
			'Instellingen',
			'Instellingen',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		check_admin_referer( 'cf_exit_popup_settings' );

		$melding = 'opgeslagen';

		// Sleutel vernieuwen laat elke rondgestuurde link meteen vervallen.
		if ( isset( $_POST['vernieuw_sleutel'] ) ) {
			CF_Exit_Popup_App::new_key();
			$melding = 'vernieuwd';
		} elseif ( isset( $_POST['laposta_los'] ) ) {
			CF_Exit_Popup_Laposta::sleutel_opslaan( '' );
			CF_Exit_Popup_Laposta::lijst_opslaan( '' );
			delete_transient( 'cf_laposta_status' );
			$melding = 'losgekoppeld';
		} else {
			$aan = isset( $_POST['delen_aan'] ) ? 1 : 0;
			update_option( 'cf_exit_popup_share_enabled', $aan, false );

			$mail = isset( $_POST['mail_naar'] ) ? sanitize_email( wp_unslash( $_POST['mail_naar'] ) ) : '';
			if ( $mail && is_email( $mail ) ) {
				update_option( 'cf_exit_popup_mail_to', $mail, false );
			} else {
				delete_option( 'cf_exit_popup_mail_to' );
			}

			// Een lege sleutel betekent hier "niet wijzigen": het veld staat leeg
			// omdat we een bestaande sleutel nooit terugtonen in de HTML.
			$nieuw = isset( $_POST['laposta_sleutel'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['laposta_sleutel'] ) ) )
				: '';

			if ( '' !== $nieuw ) {
				CF_Exit_Popup_Laposta::sleutel_opslaan( $nieuw );
				delete_transient( 'cf_laposta_status' );
			}

			if ( isset( $_POST['laposta_lijst'] ) ) {
				CF_Exit_Popup_Laposta::lijst_opslaan( wp_unslash( $_POST['laposta_lijst'] ) );
			}
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'cf_melding' => $melding ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'chiro-fysio-exit-popup' ) );
		}

		$delen_aan = CF_Exit_Popup_App::sharing_on();
		$app_url   = CF_Exit_Popup_App::url();
		$deel_url  = CF_Exit_Popup_App::share_url();
		$mail      = get_option( 'cf_exit_popup_mail_to' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$melding = isset( $_GET['cf_melding'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_melding'] ) ) : '';
		?>
		<div class="wrap cf-dash cf-settings">
			<h1 class="cf-dash__title">Instellingen</h1>
			<p class="cf-dash__sub">De app, het delen van de cijfers, en waar de meldingen heen gaan.</p>

			<?php if ( 'vernieuwd' === $melding ) : ?>
				<div class="notice notice-success"><p>Nieuwe sleutel aangemaakt. De vorige link werkt nu niet meer.</p></div>
			<?php elseif ( 'losgekoppeld' === $melding ) : ?>
				<div class="notice notice-success"><p>De koppeling met Laposta is verbroken.</p></div>
			<?php elseif ( 'opgeslagen' === $melding ) : ?>
				<div class="notice notice-success"><p>Opgeslagen.</p></div>
			<?php endif; ?>

			<section class="cf-card">
				<h2 class="cf-card__title">De app op je bureaublad</h2>
				<p class="cf-card__sub">
					Open dit adres in Chrome of Edge en klik rechtsboven op <strong>Installeren</strong>.
					Je krijgt dan een eigen icoon met een eigen venster, zonder adresbalk. Op een telefoon
					kies je in het browsermenu voor <em>Toevoegen aan beginscherm</em>.
				</p>

				<p class="cf-copyrow">
					<input type="text" class="cf-copyfield" readonly
						value="<?php echo esc_attr( $app_url ); ?>"
						onclick="this.select()">
					<a class="button button-primary" href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener">
						Openen
					</a>
				</p>

				<p class="cf-note">
					Iedereen die ingelogd is en de cijfers mag inzien, kan dit adres gebruiken.
					Wil je een collega alleen laten meekijken zonder toegang tot de site?
					Geef die dan de rol <strong>Exit-pop-up kijker</strong> onder Gebruikers.
				</p>
			</section>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cf_exit_popup_settings">
				<?php wp_nonce_field( 'cf_exit_popup_settings' ); ?>

				<section class="cf-card">
					<h2 class="cf-card__title">Delen zonder inloggen</h2>
					<p class="cf-card__sub">
						Met een geheime link kan iemand de cijfers bekijken zonder account. Handig,
						maar houd er rekening mee: <strong>wie de link heeft, ziet alles</strong>, en een
						link die eenmaal rondgaat krijg je niet meer terug. Daarom kun je hem hieronder
						met één klik vernieuwen - de oude link vervalt dan meteen.
					</p>

					<p>
						<label class="cf-switch">
							<input type="checkbox" name="delen_aan" value="1" <?php checked( $delen_aan ); ?>>
							<span>Geheime link toestaan</span>
						</label>
					</p>

					<?php if ( $delen_aan ) : ?>
						<p class="cf-copyrow">
							<input type="text" class="cf-copyfield" readonly
								value="<?php echo esc_attr( $deel_url ); ?>"
								onclick="this.select()">
							<button type="submit" name="vernieuw_sleutel" value="1" class="button"
								onclick="return confirm('De huidige link vervalt dan meteen. Doorgaan?');">
								Nieuwe link maken
							</button>
						</p>
						<p class="cf-note cf-note--warn">
							Deze link staat nu open. De pagina is afgeschermd voor zoekmachines, maar
							dat helpt niet als iemand hem doorstuurt.
						</p>
					<?php else : ?>
						<p class="cf-note">
							Staat uit. Alleen mensen met een account en de juiste rechten kunnen de cijfers zien.
						</p>
					<?php endif; ?>
				</section>

				<?php self::render_laposta(); ?>

				<section class="cf-card">
					<h2 class="cf-card__title">Meldingen</h2>
					<p class="cf-card__sub">
						Hier komen de weeksamenvatting en een bericht als de metingen stilvallen.
						Laat leeg om het beheerdersadres van de site te gebruiken
						(<?php echo esc_html( get_option( 'admin_email' ) ); ?>).
					</p>
					<p>
						<input type="email" name="mail_naar" class="regular-text"
							value="<?php echo esc_attr( $mail ? $mail : '' ); ?>"
							placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
					</p>
				</section>

				<p><button type="submit" class="button button-primary">Opslaan</button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * De koppeling met Laposta.
	 *
	 * De sleutel wordt bewust nooit teruggetoond. Wie hem heeft, kan mail
	 * versturen namens de praktijk - dat hoort niet in de HTML van een pagina
	 * te staan, ook niet achter een inlog. Je ziet alleen dát er een sleutel
	 * staat; vervangen kan door een nieuwe in te vullen.
	 */
	private static function render_laposta() {
		$ingesteld = CF_Exit_Popup_Laposta::ingesteld();
		$status    = false;

		if ( $ingesteld ) {
			// Even bewaren: dit is een echte oproep naar Laposta en de pagina
			// wordt bij elke opslag opnieuw getekend.
			$status = get_transient( 'cf_laposta_status' );

			if ( false === $status ) {
				$status = CF_Exit_Popup_Laposta::controleer();
				set_transient( 'cf_laposta_status', $status, 5 * MINUTE_IN_SECONDS );
			}
		}
		?>
		<section class="cf-card">
			<h2 class="cf-card__title">Koppeling met Laposta</h2>
			<p class="cf-card__sub">
				Hiermee haalt het dashboard de resultaten van je nieuwsbrieven op en kun je
				campagnes starten. De sleutel maak je aan in Laposta onder
				<strong>Profiel &rarr; API</strong>.
			</p>

			<?php if ( ! $ingesteld ) : ?>

				<p>
					<label for="cf-laposta-sleutel"><strong>API-sleutel</strong></label><br>
					<input type="password" id="cf-laposta-sleutel" name="laposta_sleutel"
						class="regular-text" autocomplete="off" spellcheck="false"
						placeholder="Plak hier de sleutel uit Laposta">
				</p>
				<p class="cf-note">
					Nog geen koppeling. Zolang deze leeg is, werkt de rest van de plug-in gewoon
					door - je ziet alleen geen e-mailcijfers in het dashboard.
				</p>

			<?php else : ?>

				<?php if ( $status && $status['goed'] ) : ?>
					<p class="cf-note cf-note--ok">
						<strong>&check; <?php echo esc_html( $status['melding'] ); ?></strong>
					</p>
				<?php else : ?>
					<p class="cf-note cf-note--warn">
						<strong>Er is een sleutel ingesteld, maar de verbinding lukt niet.</strong><br>
						<?php echo esc_html( $status ? $status['melding'] : CF_Exit_Popup_Laposta::laatste_fout() ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $status && ! empty( $status['lijsten'] ) ) : ?>
					<p>
						<label for="cf-laposta-lijst"><strong>Welke lijst gebruiken we?</strong></label><br>
						<select id="cf-laposta-lijst" name="laposta_lijst">
							<?php foreach ( $status['lijsten'] as $lijst ) : ?>
								<option value="<?php echo esc_attr( $lijst['id'] ); ?>"
									<?php selected( CF_Exit_Popup_Laposta::lijst_id(), $lijst['id'] ); ?>>
									<?php
									printf(
										'%s (%s aanmeldingen)',
										esc_html( $lijst['naam'] ),
										esc_html( number_format_i18n( $lijst['leden'] ) )
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>

				<p>
					<label for="cf-laposta-sleutel">Sleutel vervangen</label><br>
					<input type="password" id="cf-laposta-sleutel" name="laposta_sleutel"
						class="regular-text" autocomplete="off" spellcheck="false"
						placeholder="Laat leeg om de huidige te houden">
					<button type="submit" name="laposta_los" value="1" class="button"
						onclick="return confirm('De koppeling wordt verbroken. Doorgaan?');">
						Loskoppelen
					</button>
				</p>

			<?php endif; ?>
		</section>
		<?php
	}
}
