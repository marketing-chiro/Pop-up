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
		} elseif ( isset( $_POST['proefbericht'] ) ) {
			// Eerst opslaan wat er in het veld staat, anders test je het oude
			// adres terwijl je naar het nieuwe kijkt.
			$adres = isset( $_POST['terugbel_naar'] ) ? sanitize_email( wp_unslash( $_POST['terugbel_naar'] ) ) : '';
			if ( $adres && is_email( $adres ) ) {
				update_option( 'cf_exit_popup_callback_mail', $adres, false );
			}

			$uitslag = CF_Exit_Popup_Callbacks::proefbericht( $adres );
			$melding = is_wp_error( $uitslag ) ? 'proef-mislukt' : 'proef-verstuurd';
		} else {
			$aan = isset( $_POST['delen_aan'] ) ? 1 : 0;
			update_option( 'cf_exit_popup_share_enabled', $aan, false );

			$mail = isset( $_POST['mail_naar'] ) ? sanitize_email( wp_unslash( $_POST['mail_naar'] ) ) : '';
			if ( $mail && is_email( $mail ) ) {
				update_option( 'cf_exit_popup_mail_to', $mail, false );
			} else {
				delete_option( 'cf_exit_popup_mail_to' );
			}

			$terugbel = isset( $_POST['terugbel_naar'] ) ? sanitize_email( wp_unslash( $_POST['terugbel_naar'] ) ) : '';
			if ( $terugbel && is_email( $terugbel ) ) {
				update_option( 'cf_exit_popup_callback_mail', $terugbel, false );
			} else {
				delete_option( 'cf_exit_popup_callback_mail' );
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
		$terugbel  = CF_Exit_Popup_Callbacks::bestemming();
		$mailfout  = get_option( 'cf_exit_popup_mail_error' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$melding = isset( $_GET['cf_melding'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_melding'] ) ) : '';
		?>
		<div class="wrap cf-dash cf-settings">
			<h1 class="cf-dash__title">Instellingen</h1>
			<p class="cf-dash__sub">De app, het delen van de cijfers, en waar de meldingen heen gaan.</p>

			<?php if ( 'vernieuwd' === $melding ) : ?>
				<div class="notice notice-success"><p>Nieuwe sleutel aangemaakt. De vorige link werkt nu niet meer.</p></div>
			<?php elseif ( 'proef-verstuurd' === $melding ) : ?>
				<div class="notice notice-success">
					<p>
						Proefbericht verstuurd naar <strong><?php echo esc_html( $terugbel ); ?></strong>.
						Ligt het er over een paar minuten niet, kijk dan in Ongewenste e-mail
						en in de quarantaine van Microsoft 365.
					</p>
				</div>
			<?php elseif ( 'proef-mislukt' === $melding ) : ?>
				<div class="notice notice-error">
					<p>
						Het versturen lukte niet.
						<?php if ( is_array( $mailfout ) && ! empty( $mailfout['reden'] ) ) : ?>
							De mailserver meldde: <em><?php echo esc_html( $mailfout['reden'] ); ?></em>
						<?php endif; ?>
					</p>
				</div>
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

				<section class="cf-card">
					<h2 class="cf-card__title">Terugbelverzoeken</h2>
					<p class="cf-card__sub">
						Hier komt elk terugbelverzoek binnen, met naam en nummer, zodat er
						vanuit die postbus gebeld kan worden. Het verzoek staat daarnaast
						altijd in het dashboard - raakt een mail zoek, dan is het verzoek
						dus niet weg, en blijft er iemand aan herinneren zolang het openstaat.
					</p>
					<p>
						<input type="email" name="terugbel_naar" class="regular-text"
							value="<?php echo esc_attr( $terugbel ); ?>"
							placeholder="<?php echo esc_attr( CF_Exit_Popup_Callbacks::STANDAARD_ADRES ); ?>">
						<button type="submit" name="proefbericht" value="1" class="button">
							Proefbericht sturen
						</button>
					</p>

					<?php if ( is_array( $mailfout ) && ! empty( $mailfout['reden'] ) ) : ?>
						<p class="cf-note cf-note--warn">
							De laatste verzending mislukte
							(<?php echo esc_html( mysql2date( 'd-m-Y H:i', $mailfout['tijd'] ) ); ?>):
							<em><?php echo esc_html( $mailfout['reden'] ); ?></em>
						</p>
					<?php endif; ?>
				</section>

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
}
